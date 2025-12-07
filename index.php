<?php
// index.php - public lookup for employee generic course status
// Place in your webroot. Requires include/db_connect.php that provides $pdo (PDO).

declare(strict_types=1);

require_once __DIR__ . '/include/db_connect.php';

// Config
const DEFAULT_TO_BE_DAYS = 90;

// helper: safe table name
function safe_table(string $t): bool {
    return (bool) preg_match('/^course_records_[a-zA-Z0-9_]+$/', $t);
}

/**
 * Try to parse many human date formats into Y-m-d or return null for NA/unparseable.
 * Accepts examples like:
 *   "1-Sep-23", "2021 May 10th", "N/A", "2023 November 29th", "08/08/2025", "2025-08-07"
 */
function parse_human_date(?string $raw): ?string {
    if ($raw === null) return null;
    $s = trim($raw);
    if ($s === '') return null;
    $lower = mb_strtolower($s);
    if (in_array($lower, ['n/a','na','—','-','none'], true)) return null;

    // remove ordinal suffixes: 1st, 2nd, 3rd, 4th...
    $s = preg_replace('/(\d+)(st|nd|rd|th)/i', '$1', $s);
    // remove commas and normalize spaces
    $s = str_replace(',', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);

    $formats = [
        'j-M-y', 'd-M-y', 'j-M-Y', 'd-M-Y',
        'j F Y', 'd F Y', 'Y F j', 'Y M j',
        'M j Y', 'Y-m-d', 'd/m/Y', 'm/d/Y',
        'd.m.Y', 'Y'
    ];

    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat('!'.$fmt, $s);
        if ($dt !== false) {
            return $dt->format('Y-m-d');
        }
    }

    // last resort: strtotime
    $ts = strtotime($s);
    if ($ts !== false && $ts !== -1) {
        return date('Y-m-d', $ts);
    }

    return null;
}

// Safe HTML-escape helper — always casts to string to avoid TypeErrors with integers.
function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Use internal default to-be-days only (no UI)
$to_be_days = DEFAULT_TO_BE_DAYS;

// Discover active courses and their course_records tables from DB
// Expect 'courses' table with course_id, course_code, course_name, validity_months, table_name
$courses = $pdo->query("
    SELECT course_id, course_code, course_name, COALESCE(validity_months, 24) AS validity_months, table_name
    FROM courses
    WHERE is_active = 1
    ORDER BY course_code
")->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
$employee = null;
$emp_id_submitted = '';
$results = []; // per course results
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['employee_id'])) {
    $emp_id_submitted = trim((string)($_POST['employee_id'] ?? ''));

    // basic input validation
    if ($emp_id_submitted === '') {
        $error = "Please enter an employee ID.";
    } else {
        // lookup employee
        $stmt = $pdo->prepare("SELECT employee_id, full_name, position, department, cost_center, is_active FROM employees WHERE employee_id = ? LIMIT 1");
        $stmt->execute([$emp_id_submitted]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$employee || !(int)($employee['is_active'] ?? 0)) {
            $employee = null;
            $error = "Employee ID not found or inactive.";
        } else {
            // For each active course, query its course_records_* table for the employee
            foreach ($courses as $c) {
                $table = $c['table_name'];
                $course_id = (int)$c['course_id'];
                $course_code = $c['course_code'];
                $course_name = $c['course_name'];
                $validity_months = (int)$c['validity_months'];

                // default result row
                $row = [
                    'course_id' => $course_id,
                    'course_code' => $course_code,
                    'course_name' => $course_name,
                    'completed_raw' => null,
                    'completed' => null, // normalized Y-m-d or null
                    'status_raw' => null,
                    'status' => null, // normalized
                    'expiry' => null,
                    'gap_type' => 'initial' // initial|expired|tobe|ok|na
                ];

                if (!$table || !safe_table($table)) {
                    // Table missing / not safe: show Not Available
                    $row['status_raw'] = null;
                    $row['status'] = 'N/A';
                    $row['gap_type'] = 'na';
                    $results[] = $row;
                    continue;
                }

                // Try fetch record from the table.
                try {
                    // Table may or may not have a record; columns expected: ID, NameOfEmployee, CC, Position, Dept, CompletedDate, Status
                    $sql = "SELECT CompletedDate, Status FROM `{$table}` WHERE ID = ? LIMIT 1";
                    $s = $pdo->prepare($sql);
                    $s->execute([$emp_id_submitted]);
                    $rec = $s->fetch(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    // If table doesn't exist or error, treat as no record
                    $rec = false;
                }

                if (!$rec) {
                    // No record found -> initial (not taken)
                    $row['status_raw'] = null;
                    $row['status'] = 'Not Taken';
                    $row['gap_type'] = 'initial';
                    $results[] = $row;
                    continue;
                }

                // Normalize completed date
                $completed_raw = $rec['CompletedDate'] ?? null;
                $parsed = null;
                if ($completed_raw !== null && trim((string)$completed_raw) !== '') {
                    $parsed = parse_human_date((string)$completed_raw);
                } else {
                    $parsed = null;
                }

                $row['completed_raw'] = $completed_raw;
                $row['completed'] = $parsed; // Y-m-d or null

                // Normalize status text
                $status_raw = trim((string)($rec['Status'] ?? ''));
                $row['status_raw'] = $status_raw;

                $status_norm = null;
                $st_lower = mb_strtolower($status_raw);
                if ($st_lower === '' || in_array($st_lower, ['n/a','na','—','-','none'], true)) {
                    $status_norm = 'N/A';
                } elseif (in_array($st_lower, ['current','valid'], true)) {
                    $status_norm = 'Current';
                } elseif (in_array($st_lower, ['expired','overdue'], true)) {
                    $status_norm = 'Expired';
                } elseif ($st_lower === 'to be expired' || $st_lower === 'tobe' || $st_lower === 'to_be_expired') {
                    $status_norm = 'To be expired';
                } else {
                    // custom label — keep as-is (e.g., "Labour Union")
                    $status_norm = $status_raw === '' ? 'N/A' : $status_raw;
                }

                $row['status'] = $status_norm;

                // If we have a valid completed date, compute expiry and gap type
                if ($parsed !== null) {
                    // compute expiry date = completed + validity_months
                    $dt = DateTime::createFromFormat('Y-m-d', $parsed);
                    if ($dt !== false) {
                        $expiry_dt = (clone $dt)->modify("+{$validity_months} months");
                        $row['expiry'] = $expiry_dt->format('Y-m-d');

                        $today = new DateTime('today');
                        if ($expiry_dt < $today) {
                            $row['gap_type'] = 'expired';
                            $row['status'] = 'Expired';
                        } else {
                            // days until expiry
                            $days_to_expiry = (int)$today->diff($expiry_dt)->days;
                            if ($expiry_dt >= $today && $days_to_expiry <= $to_be_days) {
                                $row['gap_type'] = 'tobe';
                                $row['status'] = 'To be expired';
                            } else {
                                $row['gap_type'] = 'ok';
                                $row['status'] = 'Current';
                            }
                        }
                    } else {
                        // can't make DateTime from parsed (unlikely)
                        $row['expiry'] = null;
                        $row['gap_type'] = ($status_norm === 'Expired') ? 'expired' : 'na';
                    }
                } else {
                    // No valid completed date
                    if ($status_norm === 'Expired') {
                        $row['gap_type'] = 'expired';
                    } elseif ($status_norm === 'To be expired') {
                        $row['gap_type'] = 'tobe';
                    } elseif ($status_norm === 'Current') {
                        // status Current but no date — keep as Current but show N/A date
                        $row['gap_type'] = 'ok';
                    } elseif ($status_norm === 'N/A' || $status_norm === 'Not Taken') {
                        $row['gap_type'] = 'initial';
                    } else {
                        $row['gap_type'] = 'na';
                    }
                }

                $results[] = $row;
            } // foreach course
        } // employee exists
    } // if submitted non-empty
}

// small helper for UI badges
function badge_class_by_gap(string $gap): string {
    switch ($gap) {
        case 'expired': return 'bg-red-600 text-white';
        case 'tobe':    return 'bg-yellow-500 text-white';
        case 'initial': return 'bg-amber-500 text-white';
        case 'ok':      return 'bg-green-600 text-white';
        default:        return 'bg-gray-500 text-white';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>GenericCourseTrack — Check Your Training Status</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>body{font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial;} </style>
</head>
<body class="bg-gray-50 min-h-screen">

<header class="bg-blue-900 text-white shadow">
    <div class="max-w-6xl mx-auto px-6 py-5 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold">GenericCourseTrack</h1>
            <p class="text-sm opacity-80">Check your training & expiry status by entering your Employee ID</p>
        </div>
        <a href="admin/admin_login.php" class="bg-white text-blue-900 px-4 py-2 rounded font-semibold shadow">Admin Login</a>
    </div>
</header>

<main class="max-w-6xl mx-auto px-6 py-10">
    <div class="bg-white rounded-2xl shadow-lg p-8 mb-8">
        <h2 class="text-2xl font-bold mb-4">Check Your Training Status</h2>

        <form method="POST" class="max-w-2xl">
            <label class="block text-sm font-medium text-gray-600 mb-1">Employee ID</label>
            <div class="flex gap-3">
                <input name="employee_id" required value="<?= h($emp_id_submitted) ?>"
                       class="flex-1 px-4 py-3 border rounded-lg text-lg font-mono focus:outline-none focus:ring-2 focus:ring-blue-200"
                       placeholder="e.g. 19962">
                <button type="submit" class="bg-blue-700 text-white px-6 py-3 rounded-lg font-semibold shadow hover:bg-blue-800">
                    Check Status
                </button>
            </div>
        </form>
    </div>

    <?php if ($error !== ''): ?>
        <div class="bg-red-100 border border-red-300 text-red-700 p-6 rounded-lg mb-6">
            <?= h($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($employee): ?>
        <div class="bg-white rounded-2xl shadow-lg overflow-hidden mb-8">
            <div class="bg-gradient-to-r from-blue-800 to-blue-900 text-white p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm">Employee</div>
                        <div class="text-2xl font-bold"><?= h($employee['full_name']) ?></div>
                        <div class="text-sm opacity-90 mt-1">ID: <span class="font-mono"><?= h($employee['employee_id']) ?></span> — CC: <?= h($employee['cost_center'] ?? 'N/A') ?> • <?= h($employee['position'] ?? 'N/A') ?></div>
                    </div>
                    <div class="text-right text-sm opacity-80">
                        Updated view
                    </div>
                </div>
            </div>

            <div class="p-6 overflow-x-auto">
                <table class="w-full table-auto">
                    <thead class="bg-gray-100 text-gray-700">
                        <tr>
                            <th class="p-3 text-left">Course</th>
                            <th class="p-3 text-center">Completed Date</th>
                            <th class="p-3 text-center">Expiry Date</th>
                            <th class="p-3 text-center">Status</th>
                            <th class="p-3 text-center">Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $r):
                            $completed_disp = $r['completed'] ? date('Y-m-d', strtotime($r['completed'])) : 'N/A';
                            $expiry_disp = $r['expiry'] ?? 'N/A';
                            $status_disp = $r['status'] ?? ($r['status_raw'] ?? 'N/A');
                            $badge = badge_class_by_gap($r['gap_type']);
                        ?>
                        <tr class="border-b hover:bg-gray-50">
                            <td class="p-3 font-medium"><?= h($r['course_code'] . ' - ' . $r['course_name']) ?></td>
                            <td class="p-3 text-center font-mono"><?= h($completed_disp) ?></td>
                            <td class="p-3 text-center font-mono"><?= h($expiry_disp) ?></td>
                            <td class="p-3 text-center">
                                <span class="inline-block px-3 py-1 rounded-full <?= h($badge) ?> text-sm font-semibold">
                                    <?= h($status_disp) ?>
                                </span>
                            </td>
                            <td class="p-3 text-center text-sm text-gray-700">
                                <?php
                                    // friendly note
                                    switch ($r['gap_type']) {
                                        case 'expired': echo 'Expired — training must be renewed'; break;
                                        case 'tobe': echo "To be expired within " . DEFAULT_TO_BE_DAYS . " days"; break;
                                        case 'initial': echo 'Initial training not taken'; break;
                                        case 'ok': echo 'Up to date'; break;
                                        case 'na': echo 'Not available'; break;
                                        default: echo h((string)($r['status_raw'] ?? ''));
                                    }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        <div class="bg-white rounded-2xl shadow-lg p-8 text-center">
            <div class="text-xl font-bold text-gray-700">Employee ID not found or inactive</div>
        </div>
    <?php endif; ?>

    <div class="text-center text-gray-500 mt-12">
        © <?= date('Y') ?> • GenericCourseTrack
    </div>
</main>

</body>
</html>
