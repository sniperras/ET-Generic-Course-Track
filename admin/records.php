<?php
require_once '../include/db_connect.php';
require_once '../include/auth.php';
require_once '../include/tracker.php';
tracker_log_visit($pdo);
requireAdmin();

// Increase PHP limits to handle large forms
ini_set('max_input_vars', 5000);
ini_set('max_execution_time', 300);
ini_set('memory_limit', '256M');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();

// =================================================================
// HELPER: identify the current actor (logged-in admin or 'system')
// =================================================================
function current_actor(): string
{
    // Your auth.php stores the full name in $_SESSION['admin_name'].
    // We also check a few other common keys in case you add them later.
    foreach (['admin_username', 'username', 'user', 'admin_name', 'full_name', 'email'] as $key) {
        if (!empty($_SESSION[$key]) && is_string($_SESSION[$key])) {
            $v = trim($_SESSION[$key]);
            if ($v !== '') return $v;
        }
    }
    return 'system';
}

// =================================================================
// ONE-TIME MIGRATION: add LastUpdatedBy to every course_records_* table
// =================================================================
function ensure_last_updated_by_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $tables = $pdo->query("
            SELECT TABLE_NAME
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME LIKE 'course\\_records\\_%'
        ")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $tbl) {
            if (!preg_match('/^course_records_[a-zA-Z0-9_]+$/', $tbl)) continue;

            $hasCol = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND COLUMN_NAME = 'LastUpdatedBy'
            ");
            $hasCol->execute([$tbl]);
            if ((int)$hasCol->fetchColumn() > 0) continue;

            $pdo->exec("ALTER TABLE `$tbl`
                        ADD COLUMN `LastUpdatedBy` VARCHAR(100) NULL DEFAULT NULL");
        }
    } catch (Throwable $e) {
        error_log('ensure_last_updated_by_columns failed: ' . $e->getMessage());
    }
}

ensure_last_updated_by_columns($pdo);

// ============================ SAVE RECORDS ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_records'])) {
    $emp_id = trim($_POST['emp_id'] ?? '');
    if (empty($emp_id)) {
        $_SESSION['record_message'] = ['type' => 'error', 'text' => 'Employee ID missing.'];
        header("Location: admin_panel.php?page=records");
        exit;
    }

    $stmt = $pdo->prepare("SELECT full_name, position, department, cost_center FROM employees WHERE employee_id = ? AND is_active = 1");
    $stmt->execute([$emp_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$employee) {
        $_SESSION['record_message'] = ['type' => 'error', 'text' => 'Employee not found.'];
        header("Location: admin_panel.php?page=records");
        exit;
    }

    $actor = current_actor();

    $updated = 0;
    $skipped = 0;
    $errors = [];

    error_log("=== START SAVING RECORDS ===");
    error_log("Employee ID: $emp_id");
    error_log("Actor: $actor");

    $all_courses = $pdo->query("SELECT course_id, table_name FROM courses WHERE is_active = 1 AND table_name IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    $valid_course_ids = array_column($all_courses, 'course_id');

    if (!empty($_POST['taken_date']) && is_array($_POST['taken_date'])) {
        foreach ($_POST['taken_date'] as $course_id => $completed_date) {
            $course_id = (int)$course_id;
            if (!in_array($course_id, $valid_course_ids)) {
                continue;
            }

            $completed_date = trim($completed_date ?? '');
            $status = $_POST['status'][$course_id] ?? 'NA';

            if ($completed_date === '' || $completed_date === '0000-00-00' || $completed_date === 'mm/dd/yyyy') {
                $completed_date = null;
            }

            // Skip if no data to save
            if ($completed_date === null && $status === 'NA') {
                continue;
            }

            $stmt = $pdo->prepare("SELECT table_name FROM courses WHERE course_id = ? AND is_active = 1");
            $stmt->execute([$course_id]);
            $table_name = trim((string)$stmt->fetchColumn());

            if (!$table_name) {
                $errors[] = "Course ID $course_id has no table name defined.";
                continue;
            }
            if (!preg_match('/^course_records_[a-zA-Z0-9_]+$/', $table_name)) {
                $errors[] = "Invalid table name for course ID $course_id.";
                continue;
            }

            try {
                // Make sure the table exists (create with LastUpdatedBy if not)
                $table_exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table_name))->fetch();
                if (!$table_exists) {
                    $pdo->exec("
                        CREATE TABLE `$table_name` (
                            `ID` VARCHAR(50) NOT NULL PRIMARY KEY,
                            `NameOfEmployee` VARCHAR(255) NOT NULL,
                            `CC` VARCHAR(50),
                            `Position` VARCHAR(255),
                            `Dept` VARCHAR(255),
                            `CompletedDate` DATE NULL,
                            `Status` VARCHAR(50) DEFAULT 'NA',
                            `LastUpdated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            `LastUpdatedBy` VARCHAR(100) NULL DEFAULT NULL
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                } else {
                    $hasCol = $pdo->prepare("
                        SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                          AND COLUMN_NAME = 'LastUpdatedBy'
                    ");
                    $hasCol->execute([$table_name]);
                    if ((int)$hasCol->fetchColumn() === 0) {
                        $pdo->exec("ALTER TABLE `$table_name`
                                    ADD COLUMN `LastUpdatedBy` VARCHAR(100) NULL DEFAULT NULL");
                    }
                }

                // ── Read the existing row first ──
                $existing = null;
                try {
                    $getStmt = $pdo->prepare("SELECT CompletedDate, Status FROM `$table_name` WHERE ID = ? LIMIT 1");
                    $getStmt->execute([$emp_id]);
                    $existing = $getStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch (Throwable $e) {
                    $existing = null; // treat as missing row
                }

                $newDate   = $completed_date ? date('Y-m-d', strtotime($completed_date)) : null;
                $oldDate   = ($existing && !empty($existing['CompletedDate']) && $existing['CompletedDate'] !== '0000-00-00')
                    ? date('Y-m-d', strtotime($existing['CompletedDate'])) : null;
                $oldStatus = $existing['Status'] ?? null;

                // ── If nothing actually changed, skip the write entirely ──
                if ($existing !== null && $oldDate === $newDate && $oldStatus === $status) {
                    $skipped++;
                    continue;
                }

                // ── Otherwise: write and stamp LastUpdatedBy = $actor ──
                $sql = "INSERT INTO `$table_name` 
                            (ID, NameOfEmployee, CC, Position, Dept, CompletedDate, Status, LastUpdatedBy)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            NameOfEmployee = VALUES(NameOfEmployee),
                            CC             = VALUES(CC), 
                            Position       = VALUES(Position), 
                            Dept           = VALUES(Dept),
                            CompletedDate  = VALUES(CompletedDate), 
                            Status         = VALUES(Status),
                            LastUpdated    = CURRENT_TIMESTAMP,
                            LastUpdatedBy  = VALUES(LastUpdatedBy)";

                $stmt2 = $pdo->prepare($sql);
                $stmt2->execute([
                    $emp_id,
                    $employee['full_name'],
                    $employee['cost_center'] ?? '',
                    $employee['position']    ?? '',
                    $employee['department']  ?? '',
                    $completed_date,
                    $status,
                    $actor
                ]);

                $updated++;
                error_log("Updated course $course_id -> $table_name (actor=$actor)");
            } catch (Exception $e) {
                $error_msg = "Error saving to $table_name for course $course_id: " . $e->getMessage();
                error_log($error_msg);
                $errors[] = $error_msg;
            }
        }
    }

    error_log("=== SAVE COMPLETE ===");
    error_log("Updated: $updated, Skipped (unchanged): $skipped, Errors: " . count($errors));

    $message_text = "";
    $message_type = "info";

    if ($updated > 0) {
        $message_text = "$updated record(s) successfully updated!";
        if ($skipped > 0) $message_text .= " ($skipped unchanged, skipped)";
        if (!empty($errors)) {
            $message_text .= " (with " . count($errors) . " error" . (count($errors) > 1 ? 's' : '') . ")";
        }
        $message_type = "success";
    } elseif (!empty($errors)) {
        $message_text = "Save failed: " . implode("; ", array_slice($errors, 0, 3));
        if (count($errors) > 3) {
            $message_text .= " and " . (count($errors) - 3) . " more";
        }
        $message_type = "error";
    } elseif ($skipped > 0) {
        $message_text = "No changes detected — $skipped record(s) already match what was stored.";
        $message_type = "info";
    } else {
        $message_text = "No changes were made.";
        $message_type = "info";
    }

    $_SESSION['record_message'] = [
        'type' => $message_type,
        'text' => $message_text
    ];

    $redirect_url = "admin_panel.php?page=records";
    if ($emp_id) {
        $redirect_url .= "&emp_id=" . urlencode($emp_id);
    }

    header("Location: $redirect_url");
    exit;
}

// ============================ DISPLAY PAGE ============================
$message = $_SESSION['record_message'] ?? null;
unset($_SESSION['record_message']);

$emp_id = trim($_GET['emp_id'] ?? '');
$employee = null;
if ($emp_id) {
    $stmt = $pdo->prepare("SELECT full_name, position, department, cost_center FROM employees WHERE employee_id = ? AND is_active = 1");
    $stmt->execute([$emp_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
}

$courses = $pdo->query("
    SELECT course_id, course_code, course_name, validity_months, table_name
    FROM courses 
    WHERE is_active = 1 AND table_name IS NOT NULL AND table_name != ''
    ORDER BY course_code ASC
")->fetchAll(PDO::FETCH_ASSOC);

$status_options = ['Current', 'Pending', 'Expired', 'To be expired', 'NA'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Training Records</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        input[type="date"] {
            font-family: monospace;
            font-size: 16px;
        }

        select {
            cursor: pointer;
        }

        .status-current {
            color: #10B981;
            font-weight: 600;
        }

        .status-expired {
            color: #EF4444;
            font-weight: bold;
        }

        .status-pending {
            color: #F59E0B;
            font-weight: 600;
        }

        .status-tobe {
            color: #8B5CF6;
            font-weight: 600;
        }

        .status-na {
            color: #6B7280;
            font-style: italic;
        }

        .actor-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
            background: #e0e7ff;
            color: #3730a3;
        }

        .actor-pill-system {
            background: #e5e7eb;
            color: #374151;
        }
    </style>
    <script>
        (function() {
            if (window.__trackerInit) return;
            window.__trackerInit = true;

            const start = Date.now();
            let scrollMax = 0,
                clicks = 0,
                formFocuses = 0,
                downloads = 0,
                exitIntent = 0;

            function onScroll() {
                const doc = document.documentElement;
                const pct = Math.min(100, Math.round(
                    (doc.scrollTop + window.innerHeight) / doc.scrollHeight * 100
                ));
                if (pct > scrollMax) scrollMax = pct;
            }
            window.addEventListener('scroll', onScroll, {
                passive: true
            });

            document.addEventListener('click', () => clicks++);
            document.addEventListener('focusin', (e) => {
                if (e.target.matches('input,textarea,select')) formFocuses++;
            });
            document.addEventListener('click', (e) => {
                const a = e.target.closest('a');
                if (a && (a.href || '').match(/\.(pdf|zip|docx?|xlsx?|csv)$/i)) downloads++;
            });

            document.addEventListener('mouseleave', (e) => {
                if (e.clientY < 10) exitIntent = 1;
            });

            function send(extra) {
                const nav = navigator;
                const conn = nav.connection || nav.mozConnection || nav.webkitConnection || {};

                const data = new FormData();
                data.append('track_visit', '1');
                data.append('screen_w', window.screen.width || 0);
                data.append('screen_h', window.screen.height || 0);
                data.append('viewport_w', window.innerWidth || 0);
                data.append('viewport_h', window.innerHeight || 0);
                data.append('color_depth', window.screen.colorDepth || 0);
                data.append('pixel_ratio', window.devicePixelRatio || 1);
                data.append('dark_mode', window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 1 : 0);
                data.append('lang', nav.language || '');
                data.append('languages', (nav.languages || []).join(','));
                data.append('cpu_cores', nav.hardwareConcurrency || 0);
                data.append('device_memory', nav.deviceMemory || 0);
                data.append('conn_type', conn.effectiveType || '');
                data.append('conn_speed', conn.downlink || '');
                data.append('data_saver', conn.saveData ? 1 : 0);
                data.append('timezone', Intl.DateTimeFormat().resolvedOptions().timeZone || '');
                data.append('platform', nav.platform || '');
                data.append('scroll_depth', scrollMax);
                data.append('dwell_seconds', Math.round((Date.now() - start) / 1000));
                data.append('clicks', clicks);
                data.append('form_focuses', formFocuses);
                data.append('downloads', downloads);
                data.append('exit_intent', exitIntent);
                Object.entries(extra || {}).forEach(([k, v]) => data.append(k, v));

                if (navigator.sendBeacon) {
                    navigator.sendBeacon(window.location.pathname + '?track_visit=1', data);
                } else {
                    fetch(window.location.pathname + '?track_visit=1', {
                        method: 'POST',
                        body: data,
                        keepalive: true
                    });
                }
            }

            // First snapshot shortly after load
            setTimeout(() => send(), 1200);

            // Final snapshot when leaving or hiding
            const flush = () => send();
            window.addEventListener('pagehide', flush);
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') flush();
            });
        })();
    </script>
</head>

<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-10 max-w-7xl">

        <h2 class="text-4xl font-bold mb-8 text-center text-gray-800">Update Training Records</h2>

        <?php if ($message): ?>
            <div class="mb-8 p-6 rounded-xl text-white font-bold text-lg text-center shadow-lg
            <?= $message['type'] === 'success' ? 'bg-green-600' : ($message['type'] === 'error' ? 'bg-red-600' : 'bg-blue-600') ?>">
                <?= htmlspecialchars($message['text']) ?>
            </div>
        <?php endif; ?>

        <!-- Export button -->
        <div class="mb-6 flex justify-center">
            <?php if ($employee): ?>
                <a href="export_pdf.php?emp_id=<?= urlencode($emp_id) ?>"
                    target="_blank"
                    class="bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-8 rounded-xl shadow-lg transition hover:scale-105">
                    Export to PDF
                </a>
            <?php else: ?>
                <button disabled
                    class="bg-red-600 text-white font-bold py-3 px-8 rounded-xl shadow-lg opacity-50 cursor-not-allowed">
                    Export to PDF
                </button>
            <?php endif; ?>
        </div>

        <!-- Employee Search Form -->
        <form method="GET" class="mb-12 bg-white rounded-2xl shadow-xl p-8">
            <input type="hidden" name="page" value="records">
            <div class="flex flex-col md:flex-row gap-6 items-end">
                <div class="flex-1">
                    <label class="block text-lg font-semibold text-gray-700 mb-3">Enter Employee ID</label>
                    <input type="text" name="emp_id" placeholder="e.g. 14799" value="<?= htmlspecialchars($emp_id) ?>" required
                        class="w-full px-6 py-4 text-xl font-mono border-2 border-gray-300 rounded-xl focus:border-blue-600 focus:outline-none focus:ring-4 focus:ring-blue-100">
                </div>
                <button type="submit" class="bg-blue-700 hover:bg-blue-800 text-white font-bold text-xl px-12 py-4 rounded-xl shadow-lg transition transform hover:scale-105">
                    Load Employee
                </button>
            </div>
        </form>

        <?php if ($emp_id && !$employee): ?>
            <div class="bg-red-100 border-2 border-red-400 text-red-800 px-8 py-6 rounded-xl text-xl font-bold text-center">
                Employee ID <span class="font-mono"><?= htmlspecialchars($emp_id) ?></span> not found.
            </div>
        <?php endif; ?>

        <?php if ($employee): ?>
            <div class="bg-white rounded-2xl shadow-2xl overflow-hidden">
                <div class="bg-gradient-to-r from-blue-800 to-blue-900 text-white p-8">
                    <h3 class="text-3xl font-bold">Updating Records for:</h3>
                    <p class="text-2xl mt-3">
                        <span class="font-mono text-yellow-300"><?= htmlspecialchars($emp_id) ?></span> -
                        <?= htmlspecialchars($employee['full_name']) ?>
                    </p>
                    <p class="text-lg mt-2 opacity-90">
                        Position: <?= htmlspecialchars($employee['position'] ?? 'N/A') ?> |
                        Department: <?= htmlspecialchars($employee['department'] ?? 'N/A') ?> |
                        Cost Center: <?= htmlspecialchars($employee['cost_center'] ?? 'N/A') ?>
                    </p>
                </div>

                <div class="p-8">
                    <form method="POST" id="recordsForm">
                        <input type="hidden" name="emp_id" value="<?= htmlspecialchars($emp_id) ?>">
                        <input type="hidden" name="update_records" value="1">

                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead class="bg-gray-100 text-gray-700 font-bold text-lg">
                                    <tr>
                                        <th class="px-6 py-5">Course</th>
                                        <th class="px-6 py-5 text-center">Completed Date</th>
                                        <th class="px-6 py-5 text-center">Status</th>
                                        <th class="px-6 py-5 text-center">Last Updated By</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach ($courses as $course):
                                        $course_id   = (int)$course['course_id'];
                                        $table_name  = trim($course['table_name']);

                                        $input_date      = '';
                                        $current_status  = 'NA';
                                        $status_class    = 'status-na';
                                        $last_updated_by = '';

                                        try {
                                            if ($table_name && preg_match('/^course_records_[a-zA-Z0-9_]+$/', $table_name)) {
                                                $sql = "SELECT CompletedDate, Status, LastUpdatedBy FROM `$table_name` WHERE ID = ? LIMIT 1";
                                                $stmt = $pdo->prepare($sql);
                                                $stmt->execute([$emp_id]);
                                                $rec = $stmt->fetch(PDO::FETCH_ASSOC);

                                                if ($rec) {
                                                    if (!empty($rec['CompletedDate']) && $rec['CompletedDate'] !== '0000-00-00') {
                                                        $ts = strtotime($rec['CompletedDate']);
                                                        $input_date = $ts ? date('Y-m-d', $ts) : '';
                                                    }
                                                    $current_status = $rec['Status'] ?? 'NA';
                                                    $last_updated_by = trim((string)($rec['LastUpdatedBy'] ?? ''));

                                                    if ($current_status === 'Current') $status_class = 'status-current';
                                                    elseif ($current_status === 'Expired') $status_class = 'status-expired';
                                                    elseif ($current_status === 'Pending') $status_class = 'status-pending';
                                                    elseif ($current_status === 'To be expired') $status_class = 'status-tobe';
                                                    else $status_class = 'status-na';
                                                }
                                            }
                                        } catch (Throwable $e) {
                                            // Table might not exist yet - that's OK
                                        }
                                    ?>
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="px-6 py-5 font-medium <?= $status_class ?>">
                                                <div class="font-bold"><?= htmlspecialchars($course['course_code']) ?></div>
                                                <div class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($course['course_name']) ?></div>
                                                <?php if ($course['validity_months'] > 0): ?>
                                                    <div class="text-xs text-gray-500 mt-1">Valid for <?= $course['validity_months'] ?> months</div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-5 text-center">
                                                <input type="date"
                                                    name="taken_date[<?= $course_id ?>]"
                                                    value="<?= $input_date ?>"
                                                    class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-48"
                                                    placeholder="YYYY-MM-DD">
                                            </td>
                                            <td class="px-6 py-5 text-center">
                                                <select name="status[<?= $course_id ?>]"
                                                    class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-medium w-48
                                                        <?= $current_status === 'Current' ? 'bg-green-50 text-green-700' : ($current_status === 'Expired' ? 'bg-red-50 text-red-700' : ($current_status === 'Pending' ? 'bg-yellow-50 text-yellow-700' : ($current_status === 'To be expired' ? 'bg-purple-50 text-purple-700' : ''))) ?>">
                                                    <?php foreach ($status_options as $opt): ?>
                                                        <option value="<?= $opt ?>"
                                                            <?= $current_status === $opt ? 'selected' : '' ?>
                                                            <?= $opt === 'NA' ? 'class="text-gray-500"' : '' ?>>
                                                            <?= $opt ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td class="px-6 py-5 text-center text-sm">
                                                <?php if ($last_updated_by === ''): ?>
                                                    <span class="text-gray-400 italic">—</span>
                                                <?php else: ?>
                                                    <?php $pillClass = strtolower($last_updated_by) === 'system' ? 'actor-pill actor-pill-system' : 'actor-pill'; ?>
                                                    <span class="<?= $pillClass ?>" title="<?= htmlspecialchars($last_updated_by) ?>">
                                                        <?= htmlspecialchars($last_updated_by) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-12 text-center">
                            <button type="submit"
                                name="update_records"
                                class="bg-green-700 hover:bg-green-800 text-white font-bold text-xl px-20 py-5 rounded-xl shadow-xl transition transform hover:scale-105">
                                Save All Training Records
                            </button>
                            <div class="mt-4 text-gray-600">
                                <p>Total courses: <?= count($courses) ?></p>
                                <p class="text-sm">Note: Only courses with dates or non-NA status will be saved; unchanged rows are skipped automatically.</p>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('recordsForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const submitBtn = form.querySelector('button[type="submit"]');
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<span class="flex items-center justify-center"><svg class="animate-spin h-5 w-5 mr-3 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Saving... Please wait.</span>';
                    submitBtn.disabled = true;
                    submitBtn.className = 'bg-gray-500 text-white font-bold text-xl px-20 py-5 rounded-xl shadow-xl opacity-75 cursor-not-allowed';

                    setTimeout(() => {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                        submitBtn.className = 'bg-green-700 hover:bg-green-800 text-white font-bold text-xl px-20 py-5 rounded-xl shadow-xl transition transform hover:scale-105';
                    }, 10000);

                    return true;
                });
            }
        });
    </script>
</body>

</html>

<?php ob_end_flush(); ?>