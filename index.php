<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

// Keep personalized lookups and other state-changing responses out of search indexes.
$is_indexable_homepage = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && empty($_GET);
if (!$is_indexable_homepage) {
    header('X-Robots-Tag: noindex, nofollow, noarchive', true);
}

require_once __DIR__ . '/include/db_connect.php';

// Error handling for AJAX - disable error output for JSON responses
function handleAjaxError($message)
{
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
}

// Check database connection
if (!isset($pdo) || !$pdo instanceof PDO) {
    handleAjaxError("Database connection failed. Please try again later.");
}

// Config
const DEFAULT_TO_BE_DAYS = 90;
const DISPUTE_COOLDOWN_DAYS = 2;
const FOREVER_THRESHOLD = 999999; // Any validity months >= this value = forever

// Courses that never expire (validity forever) - but still need a completion date to be considered "Taken"
$NEVER_EXPIRE_COURSES = ['ESD', 'ETOPS', 'FUEL', 'ETTPM'];

/* --------- Helper Functions --------- */
function is_always_not_taken_course(string $employee_id, string $course_code): bool
{
    // Only apply special handling for this specific employee
    if ($employee_id !== '36689') {
        return false;
    }

    // List of courses that should always show "Not Taken" status for this employee
    $always_not_taken_courses = [
        'B737ASRC',
        'B737NGTOMAX',
        'B767ASCR',
        'B777ROT',
        'B787ASRC',
        'A350SRC'
    ];

    return in_array(strtoupper(trim($course_code)), $always_not_taken_courses);
}

function safe_table(string $t): bool
{
    return (bool) preg_match('/^course_records_[a-zA-Z0-9_]+$/', trim($t));
}

function parse_human_date(?string $raw): ?string
{
    if ($raw === null) return null;
    $s = trim($raw);
    if ($s === '') return null;

    $lower = mb_strtolower($s);
    if (in_array($lower, ['n/a', 'na', '—', '-', 'none', 'mm/dd/yyyy', '0000-00-00'], true)) return null;

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return $s;
    }

    $s = preg_replace('/(\d+)(st|nd|rd|th)/i', '$1', $s);
    $s = str_replace(',', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);

    $formats = [
        'Y-m-d',
        'd/m/Y',
        'm/d/Y',
        'd.m.Y',
        'Y.m.d',
        'j-M-y',
        'd-M-y',
        'j-M-Y',
        'd-M-Y',
        'j F Y',
        'd F Y',
        'Y F j',
        'Y M j',
        'M j Y'
    ];

    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat('!' . $fmt, $s);
        if ($dt !== false) return $dt->format('Y-m-d');
    }

    $ts = strtotime($s);
    if ($ts !== false && $ts !== -1) return date('Y-m-d', $ts);

    return null;
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatValidity($months): string
{
    if ($months >= FOREVER_THRESHOLD) {
        return 'Forever';
    }
    return $months . ' months';
}

function badge_class_by_gap(string $gap): string
{
    switch ($gap) {
        case 'expired':
            return 'bg-red-600 text-white';
        case 'tobe':
            return 'bg-yellow-500 text-white';
        case 'initial':
            return 'bg-amber-500 text-white';
        case 'ok':
            return 'bg-green-600 text-white';
        default:
            return 'bg-gray-500 text-white';
    }
}

function badge_class_by_status(string $status): string
{
    switch (strtolower($status)) {
        case 'open':
            return 'bg-yellow-500 text-white';
        case 'closed':
            return 'bg-green-600 text-white';
        case 'pending':
            return 'bg-blue-500 text-white';
        case 'resolved':
            return 'bg-green-500 text-white';
        case 'rejected':
            return 'bg-red-600 text-white';
        default:
            return 'bg-gray-500 text-white';
    }
}

function getLastImportDate($pdo): ?string
{
    $tables = $pdo->query("SHOW TABLES LIKE 'course_records_%'")->fetchAll(PDO::FETCH_COLUMN);
    $latest_date = null;

    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SELECT MAX(LastUpdated) as last_updated FROM `$table`");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && $result['last_updated']) {
                if (!$latest_date || $result['last_updated'] > $latest_date) {
                    $latest_date = $result['last_updated'];
                }
            }
        } catch (Exception $e) {
            // Skip tables that don't have LastUpdated column
        }
    }

    return $latest_date;
}

/* --------- Handle Dispute Status Check --------- */
$dispute_status_result = null;
$dispute_error = '';
$dispute_id_input = '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['check_dispute_status'])) {
    $dispute_id_input = trim((string)($_GET['dispute_id'] ?? ''));

    if ($dispute_id_input === '') {
        $dispute_error = "Please enter a Complaint ID.";
    } else {
        if (!is_numeric($dispute_id_input)) {
            $dispute_error = "Complaint ID must be a number.";
        } else {
            $dispute_id = (int)$dispute_id_input;
            $stmt = $pdo->prepare("SELECT * FROM training_disputes WHERE dispute_id = ? LIMIT 1");
            $stmt->execute([$dispute_id]);
            $dispute_status_result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$dispute_status_result) {
                $dispute_error = "Complaint ID not found.";
            }
        }
    }
}

/* --------- Load active courses --------- */
$courses = $pdo->query("
    SELECT course_id, course_code, course_name, COALESCE(validity_months, 24) AS validity_months, TRIM(table_name) AS table_name
    FROM courses
    WHERE is_active = 1
    ORDER BY course_code
")->fetchAll(PDO::FETCH_ASSOC);

/* --------- Handle employee status check --------- */
$employee = null;
$emp_id_submitted = '';
$results = [];
$error = '';
$last_import_date = getLastImportDate($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['employee_id']) && !isset($_POST['raise_dispute'])) {
    $emp_id_submitted = trim((string)($_POST['employee_id'] ?? ''));

    if ($emp_id_submitted === '') {
        $error = "Please enter an employee ID.";
    } else {
        $stmt = $pdo->prepare("SELECT employee_id, full_name, position, department, cost_center, is_active FROM employees WHERE employee_id = ? LIMIT 1");
        $stmt->execute([$emp_id_submitted]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$employee || !(int)($employee['is_active'] ?? 0)) {
            $employee = null;
            $error = "Employee ID not found or inactive.";
        } else {
            // Process each course
            foreach ($courses as $c) {
                $table = trim($c['table_name']);
                $course_code = $c['course_code'];
                $course_name = $c['course_name'];
                $validity_months = (int)$c['validity_months'];
                $never_expire = in_array($course_code, $NEVER_EXPIRE_COURSES) || $validity_months >= FOREVER_THRESHOLD;

                // Get employee ID for special handling
                $employee_id = $employee['employee_id'];

                // Check if this is employee 36689 with a special course that should always show "Not Taken"
                $always_not_taken = is_always_not_taken_course($employee_id, $course_code);

                // For employee 36689 with special courses - always show "Not Taken"
                if ($always_not_taken) {
                    $row = [
                        'course_code' => $course_code,
                        'course_name' => $course_name,
                        'completed_raw' => null,
                        'completed' => null,
                        'status_raw' => null,
                        'status' => 'Not Taken',
                        'expiry' => null,
                        'gap_type' => 'initial',
                        'validity_months' => $validity_months
                    ];
                    $results[] = $row;
                    continue; // Skip database lookup for these courses
                }

                $row = [
                    'course_code' => $course_code,
                    'course_name' => $course_name,
                    'completed_raw' => null,
                    'completed' => null,
                    'status_raw' => null,
                    'status' => null,
                    'expiry' => null,
                    'gap_type' => 'initial',
                    'validity_months' => $validity_months
                ];

                if (!$table || !safe_table($table)) {
                    $row['status'] = 'N/A';
                    $row['gap_type'] = 'na';
                    $results[] = $row;
                    continue;
                }

                try {
                    $table_exists = $pdo->query("SHOW TABLES LIKE '$table'")->fetch();

                    if (!$table_exists) {
                        $row['status'] = 'Not Taken';
                        $row['gap_type'] = 'initial';
                        $results[] = $row;
                        continue;
                    }

                    $sql = "SELECT CompletedDate, Status FROM `{$table}` WHERE ID = ? LIMIT 1";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$emp_id_submitted]);
                    $rec = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$rec) {
                        $row['status'] = 'Not Taken';
                        $row['gap_type'] = 'initial';
                        $results[] = $row;
                        continue;
                    }

                    $completed_raw = $rec['CompletedDate'] ?? null;
                    $parsed = null;

                    if ($completed_raw !== null && trim((string)$completed_raw) !== '' && trim((string)$completed_raw) !== '0000-00-00') {
                        $parsed = parse_human_date((string)$completed_raw);
                    }

                    $row['completed_raw'] = $completed_raw;
                    $row['completed'] = $parsed;

                    $status_raw = trim((string)($rec['Status'] ?? ''));
                    $row['status_raw'] = $status_raw;
                    $st_lower = mb_strtolower($status_raw);

                    // For courses with "Taken" or "Current" status but NO completion date -> show as "Not Taken"
                    if (($st_lower === 'taken' || $st_lower === 'current') && ($parsed === null || $parsed === '')) {
                        $row['status'] = 'Not Taken';
                        $row['gap_type'] = 'initial';
                        $results[] = $row;
                        continue;
                    }

                    // For never-expire courses with a valid date -> show as "Current"
                    if ($never_expire && $parsed !== null && $parsed !== '') {
                        $row['gap_type'] = 'ok';
                        $row['status'] = 'Current';
                        $results[] = $row;
                        continue;
                    }

                    // Normalize status for regular courses
                    if ($st_lower === '' || in_array($st_lower, ['n/a', 'na', '—', '-', 'none'], true)) {
                        $status_norm = 'N/A';
                    } elseif (in_array($st_lower, ['current', 'valid', 'taken', 'passed', 'completed'])) {
                        $status_norm = 'Current';
                    } elseif (in_array($st_lower, ['expired', 'overdue'])) {
                        $status_norm = 'Expired';
                    } elseif ($st_lower === 'to be expired' || $st_lower === 'tobe' || $st_lower === 'to_be_expired') {
                        $status_norm = 'To be expired';
                    } else {
                        $status_norm = $status_raw === '' ? 'N/A' : $status_raw;
                    }

                    $current_status = 'N/A';

                    if ($parsed !== null && $parsed !== '') {
                        $dt = DateTime::createFromFormat('Y-m-d', $parsed);
                        if ($dt !== false) {
                            $expiry_dt = (clone $dt)->modify("+{$validity_months} months");
                            $row['expiry'] = $expiry_dt->format('Y-m-d');
                            $today = new DateTime('today');

                            if ($expiry_dt < $today) {
                                $row['gap_type'] = 'expired';
                                $current_status = 'Expired';
                            } else {
                                $days_to_expiry = (int)$today->diff($expiry_dt)->days;
                                if ($days_to_expiry <= DEFAULT_TO_BE_DAYS) {
                                    $row['gap_type'] = 'tobe';
                                    $current_status = 'To be expired';
                                } else {
                                    $row['gap_type'] = 'ok';
                                    $current_status = 'Current';
                                }
                            }
                        } else {
                            $row['expiry'] = null;
                            if (in_array(strtolower($status_norm), ['expired', 'overdue'])) {
                                $row['gap_type'] = 'expired';
                                $current_status = 'Expired';
                            } elseif (in_array(strtolower($status_norm), ['current', 'valid', 'taken', 'passed', 'completed'])) {
                                $row['gap_type'] = 'ok';
                                $current_status = 'Current';
                            } else {
                                $row['gap_type'] = 'na';
                                $current_status = $status_norm;
                            }
                        }
                    } else {
                        if (in_array(strtolower($status_norm), ['expired', 'overdue'])) {
                            $row['gap_type'] = 'expired';
                            $current_status = 'Expired';
                        } elseif (in_array(strtolower($status_norm), ['current', 'valid', 'taken', 'passed', 'completed'])) {
                            $row['gap_type'] = 'ok';
                            $current_status = 'Current';
                        } elseif ($status_norm === 'N/A' || $status_norm === 'Not Taken') {
                            $row['gap_type'] = 'initial';
                            $current_status = 'Not Taken';
                        } else {
                            $row['gap_type'] = 'na';
                            $current_status = $status_norm;
                        }
                    }

                    $row['status'] = $current_status;
                    $results[] = $row;
                } catch (Exception $e) {
                    $row['status'] = 'N/A';
                    $row['gap_type'] = 'na';
                    $results[] = $row;
                }
            }
        }
    }
}

/* --------- Handle Dispute Submission --------- */
$dispute_ok = false;
$dispute_msg = '';
$errors_dispute = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['raise_dispute'])) {
    $is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    $emp_id = trim((string)($_POST['dispute_employee_id'] ?? ''));
    $emp_name = trim((string)($_POST['dispute_employee_name'] ?? ''));
    $comment = trim((string)($_POST['dispute_comment'] ?? ''));

    $courses_list = [];
    $course_dates = [];

    if (isset($_POST['dispute_course']) && is_array($_POST['dispute_course'])) {
        foreach ($_POST['dispute_course'] as $index => $course) {
            $course = trim((string)$course);
            if ($course !== '') {
                $courses_list[] = $course;
                $date = isset($_POST['dispute_course_date'][$index]) ? trim((string)$_POST['dispute_course_date'][$index]) : '';
                if ($date !== '') {
                    $ts = strtotime($date);
                    $course_dates[] = $ts !== false ? date('Y-m-d', $ts) : null;
                } else {
                    $course_dates[] = null;
                }
            }
        }
    }

    if ($emp_id === '' || $emp_name === '') {
        $errors_dispute[] = "Employee ID and name are required.";
    }

    if (count($courses_list) === 0) {
        $errors_dispute[] = "Please list at least one course.";
    }

    if (empty($errors_dispute)) {
        try {
            // Check if training_disputes table exists
            $table_check = $pdo->query("SHOW TABLES LIKE 'training_disputes'")->fetch();
            if (!$table_check) {
                // Create the table if it doesn't exist
                $create_sql = "
                    CREATE TABLE `training_disputes` (
                        `dispute_id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        `employee_id` VARCHAR(50) NOT NULL,
                        `employee_name` VARCHAR(255) NOT NULL,
                        `courses` TEXT NOT NULL,
                        `course_dates` TEXT,
                        `comment` TEXT,
                        `hr_comment` TEXT,
                        `status` VARCHAR(50) DEFAULT 'open',
                        `closed_by` VARCHAR(100),
                        `created_at` DATETIME NOT NULL,
                        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_employee_id (employee_id),
                        INDEX idx_status (status),
                        INDEX idx_created_at (created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ";
                $pdo->exec($create_sql);
            }

            $ins = $pdo->prepare("INSERT INTO training_disputes (employee_id, employee_name, courses, course_dates, comment, status, created_at) VALUES (?, ?, ?, ?, ?, 'open', NOW())");
            $ins->execute([
                $emp_id,
                $emp_name,
                json_encode(array_values($courses_list), JSON_UNESCAPED_UNICODE),
                json_encode(array_values($course_dates), JSON_UNESCAPED_UNICODE),
                $comment
            ]);
            $dispute_ok = true;
            $dispute_msg = "Your complaint has been submitted. Complaint ID: " . $pdo->lastInsertId() . ", Save or write down the Complaint ID to check the complaint status later.";
        } catch (Exception $e) {
            $errors_dispute[] = "Database error: " . $e->getMessage();
        }
    }

    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        if ($dispute_ok) {
            echo json_encode(['success' => true, 'message' => $dispute_msg]);
        } else {
            echo json_encode(['success' => false, 'message' => 'There were errors with your submission.', 'errors' => array_values($errors_dispute)]);
        }
        exit;
    }
}

// AJAX endpoint to check cooldown before opening modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_cooldown'])) {
    header('Content-Type: application/json; charset=utf-8');
    $emp_id = trim((string)($_POST['employee_id'] ?? ''));

    if (empty($emp_id)) {
        echo json_encode(['can_proceed' => false, 'message' => 'Employee ID is required.']);
        exit;
    }

    try {
        $cooldown_check = $pdo->prepare("
            SELECT created_at, status FROM training_disputes 
            WHERE employee_id = ? 
            AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $cooldown_check->execute([$emp_id, DISPUTE_COOLDOWN_DAYS]);
        $recent_dispute = $cooldown_check->fetch(PDO::FETCH_ASSOC);

        if ($recent_dispute) {
            $cooldown_end = new DateTime($recent_dispute['created_at']);
            $cooldown_end->modify('+' . DISPUTE_COOLDOWN_DAYS . ' days');
            $now = new DateTime();
            $days_remaining = (int)($now->diff($cooldown_end)->format('%a'));
            $hours_remaining = (int)($now->diff($cooldown_end)->format('%h'));

            if ($days_remaining > 0) {
                $message = "You have already submitted a complaint on " . date('F j, Y', strtotime($recent_dispute['created_at'])) . ". 
                Please complete all remaining courses and come back after " . $days_remaining . " day(s) to submit a new complaint.";
            } elseif ($hours_remaining > 0) {
                $message = "You have already submitted a complaint recently. 
                Please complete all remaining courses and come back after " . $hours_remaining . " hour(s) to submit a new complaint.";
            } else {
                $message = "You have already submitted a complaint recently. 
                Please complete all remaining courses and wait " . DISPUTE_COOLDOWN_DAYS . " days before submitting another complaint.";
            }

            echo json_encode(['can_proceed' => false, 'message' => $message]);
            exit;
        }

        echo json_encode(['can_proceed' => true]);
    } catch (Exception $e) {
        // Table might not exist yet (first complaint), that's fine - allow proceeding
        echo json_encode(['can_proceed' => true]);
    }
    exit;
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">


    <title>ET AirTV CourseTrack | Employee Training Status</title>
    <meta name="description" content="ET AirTV CourseTrack lets employees check course completion, training expiry dates, and submitted complaint updates through one central employee portal.">
    <meta name="robots" content="<?= $is_indexable_homepage ? 'index, follow' : 'noindex, nofollow' ?>">
    <?php if ($is_indexable_homepage): ?>
    <link rel="canonical" href="https://coursetrack.etairtv.com/">
    <?php endif; ?>
    <meta property="og:title" content="ET AirTV CourseTrack | Employee Training Status">
    <meta property="og:description" content="Check course completion and training expiry status, then follow up on submitted training complaints.">
    <meta property="og:url" content="https://coursetrack.etairtv.com/">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="ET AirTV CourseTrack">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="ET AirTV CourseTrack | Employee Training Status">
    <meta name="twitter:description" content="Check course completion and training expiry status, then follow up on submitted training complaints.">
    <meta name="theme-color" content="#1e3a8a">
    <?php if ($is_indexable_homepage): ?>
    <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "WebSite",
            "name": "ET AirTV CourseTrack",
            "url": "https://coursetrack.etairtv.com/"
        }
    </script>
    <?php endif; ?>
    <link rel="icon" type="image/png" href="img/ethiopian_logo.ico">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
        }

        /* Limit date picker to max today */
        input[type="date"] {
            max-width: 160px;
        }

        input[type="date"]:invalid {
            color: #999;
        }
    </style>
</head>

<body class="bg-gray-50 min-h-screen flex flex-col">

    <header class="bg-blue-900 text-white shadow">
        <div class="max-w-6xl mx-auto px-6 py-5 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <img src="./img/logo.png" alt="ET AirTV CourseTrack" class="h-[3.5rem] w-auto" onerror="this.style.display='none'">
                <div>
                    <h1 class="text-2xl font-bold">ET AirTV CourseTrack</h1>
                    <p class="text-sm opacity-80">Check your training & expiry status by entering your Employee ID</p>
                </div>
            </div>
            <a href="admin/admin_login.php" class="bg-white text-blue-900 px-4 py-2 rounded font-semibold shadow">HR Login</a>
        </div>
    </header>
    <!-- ════════════════════════════════════════════════════════════════
         SUB-NAVIGATION BAR
         To add a new link: copy one <a> block, change href + label.
         ════════════════════════════════════════════════════════════════ -->
    <nav class="w-full bg-gradient-to-r from-red-700 via-red-600 to-orange-500 text-white shadow-lg relative">
        <div class="max-w-6xl mx-auto px-6">
            <ul class="flex flex-wrap items-center gap-x-1 gap-y-1 py-1.5 text-sm">

                <!-- Useful Links Badge & Blinking/Pointing Arrow -->
                <li class="flex items-center gap-1.5 px-3 py-1.5 font-bold uppercase tracking-wider text-amber-300">
                    <span>Useful Links</span>
                    <span class="relative flex h-3 w-3 items-center justify-center">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-300"></span>
                    </span>
                    <span class="animate-bounce font-extrabold text-lg leading-none ml-0.5">👉</span>
                </li>

                <!-- Deferral (open fault track) -->
                <li>
                    <a href="https://deftrack.etairtv.com/live_statics.php"
                        target="_blank" rel="noopener"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-blue-50 hover:bg-blue-700 hover:text-white transition-colors font-medium">
                        <span>🛠️</span>
                        <span>All Fleet ADD FOLLOW UP (Open Fault Track)</span>
                        <span class="text-blue-300 text-xs">↗</span>
                    </a>
                </li>

                <!-- ET Locker Verifier -->
                <li>
                    <a href="http://etlocker.infinityfree.me"
                        target="_blank" rel="noopener"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-blue-50 hover:bg-blue-700 hover:text-white transition-colors font-medium">
                        <span>🔐</span>
                        <span>ET Locker Verifier</span>
                        <span class="text-blue-300 text-xs">↗</span>
                    </a>
                </li>



                <li>
                    <a href="https://etairtv.com/?mode=lmt"
                        target="_blank" rel="noopener"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-blue-50 hover:bg-blue-700 hover:text-white transition-colors font-medium">
                        <span>📺</span>
                        <span>ET AirTV</span>
                        <span class="text-blue-300 text-xs">↗</span>
                    </a>
                </li>

            </ul>
        </div>
    </nav>
    <!-- ════════════════════ END SUB-NAVIGATION ════════════════════ -->
    <main class="max-w-6xl mx-auto px-6 py-10 flex-grow">
        <!-- Dual forms -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <div class="bg-white rounded-2xl shadow-lg p-8">
                <h2 class="text-2xl font-bold mb-4">Check Your Training Status</h2>
                <form method="POST" class="max-w-full">
                    <label class="block text-sm font-medium text-gray-600 mb-1">Employee ID</label>
                    <div class="flex gap-3">
                        <input name="employee_id" required value="<?= h($emp_id_submitted) ?>"
                            class="flex-1 px-4 py-3 border rounded-lg text-lg font-mono focus:outline-none focus:ring-2 focus:ring-blue-200"
                            placeholder="e.g. 19962">
                        <button type="submit" class="bg-blue-700 text-white px-6 py-3 rounded-lg font-semibold shadow hover:bg-blue-800">Check Status</button>
                    </div>
                </form>
            </div>

            <div class="bg-white rounded-2xl shadow-lg p-8">
                <h2 class="text-2xl font-bold mb-4">Check Complaint Status</h2>
                <form method="GET" class="max-w-full">
                    <label class="block text-sm font-medium text-gray-600 mb-1">Complaint ID</label>
                    <div class="flex gap-3">
                        <input name="dispute_id" required value="<?= h($dispute_id_input) ?>"
                            class="flex-1 px-4 py-3 border rounded-lg text-lg font-mono focus:outline-none focus:ring-2 focus:ring-blue-200"
                            placeholder="e.g. 1001">
                        <input type="hidden" name="check_dispute_status" value="1">
                        <button type="submit" class="bg-green-700 text-white px-6 py-3 rounded-lg font-semibold shadow hover:bg-green-800">Check Complaint</button>
                    </div>
                    <p class="text-sm text-gray-500 mt-2">Enter the Complaint ID you received when submitting your complaint</p>
                </form>
            </div>
        </div>

        <!-- Complaint Status Result -->
        <?php if ($dispute_error !== ''): ?>
            <div class="bg-red-100 border border-red-300 text-red-700 p-6 rounded-lg mb-6">
                <div class="font-semibold">Error:</div>
                <p><?= h($dispute_error) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($dispute_status_result): ?>
            <div class="bg-white rounded-2xl shadow-lg overflow-hidden mb-8">
                <div class="bg-gradient-to-r from-green-800 to-green-900 text-white p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm">Complaint Status</div>
                            <div class="text-2xl font-bold">Complaint ID: <?= h($dispute_status_result['dispute_id']) ?></div>
                            <div class="text-sm opacity-90 mt-1">
                                Submitted by: <?= h($dispute_status_result['employee_name']) ?>
                                (ID: <?= h($dispute_status_result['employee_id']) ?>)
                                on <?= date('F j, Y', strtotime($dispute_status_result['created_at'])) ?>
                            </div>
                        </div>
                        <div class="text-right">
                            <span class="inline-block px-4 py-2 rounded-full <?= badge_class_by_status($dispute_status_result['status']) ?> text-sm font-semibold">
                                <?= h(ucfirst($dispute_status_result['status'])) ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <h3 class="font-bold text-gray-700 mb-2">Complaint Details</h3>
                            <div class="space-y-3">
                                <div>
                                    <div class="text-sm text-gray-500">Courses Complaint:</div>
                                    <?php
                                    $courses_list = json_decode($dispute_status_result['courses'] ?? '[]', true);
                                    $course_dates = json_decode($dispute_status_result['course_dates'] ?? '[]', true);
                                    ?>
                                    <?php if (!empty($courses_list)): ?>
                                        <ul class="mt-1 pl-5 list-disc">
                                            <?php foreach ($courses_list as $index => $course): ?>
                                                <li class="text-gray-800">
                                                    <?= h($course) ?>
                                                    <?php if (isset($course_dates[$index]) && $course_dates[$index]): ?>
                                                        <span class="text-sm text-gray-600">(Date: <?= date('Y-m-d', strtotime($course_dates[$index])) ?>)</span>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <p class="text-gray-500 italic">No courses listed</p>
                                    <?php endif; ?>
                                </div>

                                <div>
                                    <div class="text-sm text-gray-500">Employee Comment:</div>
                                    <div class="mt-1 p-3 bg-gray-50 rounded border"><?= nl2br(h($dispute_status_result['comment'] ?? 'No comment provided')) ?></div>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h3 class="font-bold text-gray-700 mb-2">Resolution Details</h3>
                            <div class="space-y-3">
                                <?php if ($dispute_status_result['status'] === 'closed' || $dispute_status_result['status'] === 'resolved'): ?>
                                    <div>
                                        <div class="text-sm text-gray-500">HR Comment:</div>
                                        <div class="mt-1 p-3 bg-green-50 rounded border"><?= nl2br(h($dispute_status_result['hr_comment'] ?? 'No HR comment provided')) ?></div>
                                    </div>

                                    <?php if ($dispute_status_result['closed_by']): ?>
                                        <div>
                                            <div class="text-sm text-gray-500">Closed By:</div>
                                            <div class="mt-1 text-gray-800"><?= h($dispute_status_result['closed_by']) ?></div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($dispute_status_result['updated_at']): ?>
                                        <div>
                                            <div class="text-sm text-gray-500">Last Updated:</div>
                                            <div class="mt-1 text-gray-800"><?= date('F j, Y H:i:s', strtotime($dispute_status_result['updated_at'])) ?></div>
                                        </div>
                                    <?php endif; ?>
                                <?php elseif ($dispute_status_result['status'] === 'rejected'): ?>
                                    <div>
                                        <div class="text-sm text-gray-500">Rejection Reason:</div>
                                        <div class="mt-1 p-3 bg-red-50 rounded border"><?= nl2br(h($dispute_status_result['hr_comment'] ?? 'No reason provided')) ?></div>
                                    </div>

                                    <?php if ($dispute_status_result['updated_at']): ?>
                                        <div>
                                            <div class="text-sm text-gray-500">Last Updated:</div>
                                            <div class="mt-1 text-gray-800"><?= date('F j, Y H:i:s', strtotime($dispute_status_result['updated_at'])) ?></div>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="p-4 bg-yellow-50 border border-yellow-200 rounded">
                                        <p class="text-yellow-800">
                                            <strong>Status: <?= h(ucfirst($dispute_status_result['status'])) ?></strong><br>
                                            Your complaint is currently being reviewed by the HR team. Please check back later for updates.
                                        </p>
                                    </div>

                                    <?php if ($dispute_status_result['updated_at']): ?>
                                        <div>
                                            <div class="text-sm text-gray-500">Last Updated:</div>
                                            <div class="mt-1 text-gray-800"><?= date('F j, Y H:i:s', strtotime($dispute_status_result['updated_at'])) ?></div>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="border-t pt-4">
                        <h3 class="font-bold text-gray-700 mb-3">Complaint Timeline</h3>
                        <div class="space-y-2">
                            <div class="flex items-center">
                                <div class="w-3 h-3 bg-blue-500 rounded-full mr-3"></div>
                                <div class="text-gray-700">
                                    <strong>Created:</strong> <?= date('F j, Y H:i:s', strtotime($dispute_status_result['created_at'])) ?>
                                </div>
                            </div>
                            <?php if ($dispute_status_result['updated_at'] && $dispute_status_result['updated_at'] !== $dispute_status_result['created_at']): ?>
                                <div class="flex items-center">
                                    <div class="w-3 h-3 bg-green-500 rounded-full mr-3"></div>
                                    <div class="text-gray-700">
                                        <strong>Last Updated:</strong> <?= date('F j, Y H:i:s', strtotime($dispute_status_result['updated_at'])) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Employee Status Result -->
        <?php if ($error !== ''): ?>
            <div class="bg-red-100 border border-red-300 text-red-700 p-6 rounded-lg mb-6"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($employee): ?>
            <div id="printableArea">
                <div class="bg-white rounded-2xl shadow-lg overflow-hidden mb-8">
                    <div class="bg-gradient-to-r from-blue-800 to-blue-900 text-white p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-sm">Employee</div>
                                <div class="text-2xl font-bold"><?= h($employee['full_name']) ?></div>
                                <div class="text-sm opacity-90 mt-1">
                                    ID: <span class="font-mono"><?= h($employee['employee_id']) ?></span> —
                                    CC: <?= h($employee['cost_center'] ?? 'N/A') ?> •
                                    <?= h($employee['position'] ?? 'N/A') ?>
                                </div>
                            </div>
                            <div class="text-right text-sm opacity-80">
                                <?= count($results) ?> courses loaded
                            </div>
                        </div>
                    </div>

                    <div class="p-6">
                        <!-- AJAX Messages -->
                        <div id="ajaxMessageContainer">
                            <?php if ($dispute_ok && !isset($_SERVER['HTTP_X_REQUESTED_WITH'])): ?>
                                <div class="mb-4 p-4 rounded bg-green-100 text-green-800 auto-dismiss-message"><?= h($dispute_msg) ?></div>
                            <?php elseif (!empty($errors_dispute) && !isset($_SERVER['HTTP_X_REQUESTED_WITH'])): ?>
                                <div class="mb-4 p-4 rounded bg-red-100 text-red-800">
                                    <ul class="list-disc pl-5">
                                        <?php foreach ($errors_dispute as $err): ?>
                                            <li><?= h($err) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Complaint Button and Last Import Date -->
                        <div class="mb-6 flex items-center justify-between flex-wrap gap-4">
                            <div class="flex gap-3">
                                <button id="openDisputeBtn" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg font-semibold">
                                    Raise Complaint about Training
                                </button>
                                <button onclick="window.open('print_pdf.php?emp_id=<?= urlencode((string)$employee['employee_id']) ?>&print=true', '_blank');"
                                    class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg font-semibold inline-flex items-center gap-2">
                                    🖨️ Print Report
                                </button>
                            </div>

                            <?php if ($last_import_date): ?>
                                <?php
                                $dt = new DateTime($last_import_date);
                                $dt->setTimezone(new DateTimeZone('Africa/Nairobi')); // East Africa Time (GMT+3)
                                ?>
                                <div class="text-sm text-gray-500 bg-gray-100 px-4 py-2 rounded-lg">
                                    📅 Last data update: <?= $dt->format('F j, Y \a\t g:i A T') ?>
                                </div>
                            <?php endif; ?>

                        </div>

                        <!-- Courses Table -->
                        <div class="overflow-x-auto">
                            <table class="w-full table-auto">
                                <thead class="bg-gray-100 text-gray-700">
                                    <tr>
                                        <th class="p-3 text-left">Course</th>
                                        <th class="p-3 text-center">Completed Date</th>
                                        <th class="p-3 text-center">Expiry Date</th>
                                        <th class="p-3 text-center">Status</th>
                                        <th class="p-3 text-center">Validity</th>
                                        <th class="p-3 text-center">Note</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($results as $r):
                                        $completed_disp = ($r['completed'] && $r['completed'] !== '') ? date('Y-m-d', strtotime($r['completed'])) : 'N/A';
                                        $expiry_disp = $r['expiry'] ?? 'N/A';
                                        $current_status = $r['status'] ?? 'N/A';
                                        $badge = badge_class_by_gap($r['gap_type']);
                                        $validity_months = $r['validity_months'] ?? 24;
                                    ?>
                                        <tr class="border-b hover:bg-gray-50">
                                            <td class="p-3 font-medium">
                                                <div class="font-bold"><?= h($r['course_code']) ?></div>
                                                <div class="text-sm text-gray-600"><?= h($r['course_name']) ?></div>
                                            </td>
                                            <td class="p-3 text-center font-mono"><?= h($completed_disp) ?></td>
                                            <td class="p-3 text-center font-mono"><?= h($expiry_disp) ?></td>
                                            <td class="p-3 text-center">
                                                <span class="inline-block px-3 py-1 rounded-full <?= h($badge) ?> text-sm font-semibold">
                                                    <?= h($current_status) ?>
                                                </span>
                                            </td>
                                            <td class="p-3 text-center text-sm">
                                                <?= formatValidity($validity_months) ?>
                                            </td>
                                            <td class="p-3 text-center text-sm text-gray-700">
                                                <?php
                                                switch ($r['gap_type']) {
                                                    case 'expired':
                                                        echo 'Expired — training must be renewed';
                                                        break;
                                                    case 'tobe':
                                                        echo "To be expired within " . DEFAULT_TO_BE_DAYS . " days";
                                                        break;
                                                    case 'initial':
                                                        echo 'Training not taken';
                                                        break;
                                                    case 'ok':
                                                        echo 'Up to date';
                                                        break;
                                                    case 'na':
                                                        echo 'Not available';
                                                        break;
                                                    default:
                                                        echo h((string)($r['status_raw'] ?? ''));
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Complaint Modal -->
            <div id="disputeModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 px-4">
                <div class="bg-white rounded-xl max-w-4xl w-full p-6 shadow-lg max-h-[90vh] overflow-y-auto">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-xl font-bold">Raise Training Complaint</h3>
                        <button id="closeDisputeBtn" class="text-gray-500 hover:text-gray-700 text-2xl leading-none">&times;</button>
                    </div>

                    <form id="disputeForm" method="POST" action="<?= h($_SERVER['REQUEST_URI']) ?>">
                        <input type="hidden" name="raise_dispute" value="1">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Employee Name</label>
                                <input type="text" name="dispute_employee_name" value="<?= h($employee['full_name']) ?>" readonly class="w-full px-4 py-2 border rounded bg-gray-100">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Employee ID</label>
                                <input type="text" name="dispute_employee_id" value="<?= h($employee['employee_id']) ?>" readonly class="w-full px-4 py-2 border rounded bg-gray-100">
                            </div>
                        </div>

                        <div class="mb-6">
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="font-semibold text-gray-700">Fill in the name of the course you took and the date of each course. <br>only fill in the course that has a problem.</h4>
                                <button type="button" id="clearAllBtn" class="text-sm text-red-600 hover:text-red-800">Clear All</button>
                            </div>

                            <div id="coursesContainer" class="space-y-3 max-h-60 overflow-y-auto p-2 border rounded">
                                <div class="text-sm text-gray-500 italic py-2 text-center" id="noCoursesMessage">
                                    No courses added yet. Click "+ Add Empty Course Row" to add courses.
                                </div>
                            </div>

                            <div class="mt-3">
                                <button type="button" id="addCourseRowBtn" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded font-semibold">
                                    + Add Empty Course Row
                                </button>
                            </div>
                        </div>

                        <div class="mb-6">
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-sm font-medium text-gray-700">General Comment</label>
                            </div>
                            <textarea id="disputeComment" name="dispute_comment" rows="4" placeholder="Explain the issue or any details for the MRO officer..." class="w-full px-4 py-2 border rounded"></textarea>
                        </div>

                        <div id="modalErrors" class="mt-4"></div>

                        <div class="mt-6 flex items-center justify-between">
                            <div class="text-sm text-gray-600">We will send your complaint to the MRO HR record officer for review.</div>
                            <div class="flex gap-3">
                                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded font-semibold">Submit Complaint</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

        <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <div class="bg-white rounded-2xl shadow-lg p-8 text-center">
                <div class="text-xl font-bold text-gray-700">Employee ID not found or inactive</div>
            </div>
        <?php endif; ?>

    </main>

    <!-- FOOTER: stays at bottom, matches original blue-950 with subtle text, compact -->
    <footer class="bg-blue-950 text-gray-300 py-3 flex-shrink-0">
        <div class="w-full max-w-7xl mx-auto px-5 text-center">
            <p class="text-xs">
                © 2026 <span class="font-semibold text-white">Generic Course Track</span>. All rights reserved.
            </p>
            <p class="text-[11px] mt-1 opacity-75">
                MRO Course Track System • Developed by NATNAEL BIZUNEH
            </p>
        </div>
    </footer>

    <script>
        (function() {
            const openBtn = document.getElementById('openDisputeBtn');
            const closeBtn = document.getElementById('closeDisputeBtn');
            const modal = document.getElementById('disputeModal');
            const addCourseRowBtn = document.getElementById('addCourseRowBtn');
            const clearAllBtn = document.getElementById('clearAllBtn');
            const coursesContainer = document.getElementById('coursesContainer');
            const noCoursesMessage = document.getElementById('noCoursesMessage');
            const disputeComment = document.getElementById('disputeComment');
            const disputeForm = document.getElementById('disputeForm');
            const modalErrors = document.getElementById('modalErrors');
            const ajaxMessageContainer = document.getElementById('ajaxMessageContainer');

            let courseRowCounter = 0;
            const EMPLOYEE_ID = '<?= h($employee['employee_id'] ?? '') ?>';
            const STORAGE_KEY = `dispute_form_data_${EMPLOYEE_ID}`;

            // Get today's date in YYYY-MM-DD format for max date restriction
            const today = new Date().toISOString().split('T')[0];

            function getSavedFormData() {
                try {
                    const saved = localStorage.getItem(STORAGE_KEY);
                    if (saved) return JSON.parse(saved);
                } catch (e) {
                    console.error('Error loading saved form data:', e);
                }
                return {
                    courses: [],
                    comment: ''
                };
            }

            function saveFormDataToStorage(data) {
                try {
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
                } catch (e) {
                    console.error('Error saving form data:', e);
                }
            }

            function clearFormDataFromStorage() {
                try {
                    localStorage.removeItem(STORAGE_KEY);
                } catch (e) {
                    console.error('Error clearing form data:', e);
                }
            }

            // Check cooldown before opening modal
            async function checkCooldownAndOpenModal() {
                if (!EMPLOYEE_ID) {
                    showAjaxMessage(false, 'Employee ID not found. Please refresh the page.');
                    return;
                }

                // Show loading state on button
                const originalText = openBtn.textContent;
                openBtn.disabled = true;
                openBtn.textContent = 'Checking...';

                try {
                    const formData = new FormData();
                    formData.append('check_cooldown', '1');
                    formData.append('employee_id', EMPLOYEE_ID);

                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    const data = await response.json();

                    if (data.can_proceed) {
                        // No cooldown - open modal
                        openModal();
                    } else {
                        // Cooldown active - show message in ajaxMessageContainer
                        showAjaxMessage(false, data.message);
                    }
                } catch (error) {
                    console.error('Cooldown check error:', error);
                    // If error, allow proceeding (or show error message)
                    showAjaxMessage(false, 'Unable to check complaint status. Please try again later.');
                } finally {
                    openBtn.disabled = false;
                    openBtn.textContent = originalText;
                }
            }

            function openModal() {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                modalErrors.innerHTML = '';
                clearAllCourseRows();
                const savedFormData = getSavedFormData();
                if (savedFormData.courses && savedFormData.courses.length > 0) {
                    savedFormData.courses.forEach(course => addCourseRow(course.name, course.date));
                } else {
                    addCourseRow();
                }
                if (disputeComment && savedFormData.comment) disputeComment.value = savedFormData.comment;
                updateNoCoursesMessage();
            }

            function closeModal() {
                saveFormData();
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }

            // Use cooldown check when clicking the button
            openBtn?.addEventListener('click', checkCooldownAndOpenModal);
            closeBtn?.addEventListener('click', closeModal);
            modal?.addEventListener('click', function(e) {
                if (e.target === modal) closeModal();
            });

            function saveFormData() {
                const savedFormData = {
                    courses: [],
                    comment: disputeComment ? disputeComment.value.trim() : ''
                };
                const courseRows = coursesContainer.querySelectorAll('.course-row');
                courseRows.forEach(row => {
                    const courseInput = row.querySelector('input[name^="dispute_course"]');
                    const dateInput = row.querySelector('input[name^="dispute_course_date"]');
                    if (courseInput && courseInput.value.trim()) {
                        savedFormData.courses.push({
                            name: courseInput.value.trim(),
                            date: dateInput ? dateInput.value.trim() : ''
                        });
                    }
                });
                saveFormDataToStorage(savedFormData);
            }

            function addCourseRow(courseName = '', courseDate = '') {
                courseRowCounter++;
                const rowId = `courseRow_${courseRowCounter}`;
                const rowDiv = document.createElement('div');
                rowDiv.className = 'course-row flex items-center gap-2 p-2 border rounded hover:bg-gray-50';
                rowDiv.id = rowId;
                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'remove-course-btn text-red-500 hover:text-red-700 text-lg font-bold w-6 h-6 flex items-center justify-center';
                removeBtn.innerHTML = '−';
                removeBtn.title = 'Remove this course';
                removeBtn.addEventListener('click', () => removeCourseRow(rowId));
                const courseInput = document.createElement('input');
                courseInput.type = 'text';
                courseInput.name = `dispute_course[]`;
                courseInput.placeholder = 'Course name (e.g., Human Factors)';
                courseInput.className = 'flex-1 px-3 py-2 border rounded';
                courseInput.value = courseName;
                const dateInput = document.createElement('input');
                dateInput.type = 'date';
                dateInput.name = `dispute_course_date[]`;
                dateInput.className = 'w-40 px-3 py-2 border rounded';
                dateInput.value = courseDate;
                // Set max date to today (cannot select future dates)
                dateInput.max = today;
                rowDiv.appendChild(removeBtn);
                rowDiv.appendChild(courseInput);
                rowDiv.appendChild(dateInput);
                coursesContainer.appendChild(rowDiv);
                updateNoCoursesMessage();
                if (!courseName) setTimeout(() => courseInput.focus(), 10);
                courseInput.addEventListener('input', saveFormData);
                dateInput.addEventListener('input', saveFormData);
            }

            function removeCourseRow(rowId) {
                document.getElementById(rowId)?.remove();
                updateNoCoursesMessage();
                saveFormData();
            }

            function clearAllCourseRows() {
                coursesContainer.innerHTML = '';
                courseRowCounter = 0;
                updateNoCoursesMessage();
            }

            function clearAllCourseRowsAndComment() {
                clearAllCourseRows();
                if (disputeComment) disputeComment.value = '';
                clearFormDataFromStorage();
                addCourseRow();
            }

            function updateNoCoursesMessage() {
                const rows = coursesContainer.querySelectorAll('.course-row').length;
                if (noCoursesMessage) noCoursesMessage.style.display = rows === 0 ? 'block' : 'none';
            }

            addCourseRowBtn?.addEventListener('click', () => addCourseRow());
            clearAllBtn?.addEventListener('click', () => {
                if (confirm('Are you sure you want to clear ALL courses and the comment?')) clearAllCourseRowsAndComment();
            });
            if (disputeComment) disputeComment.addEventListener('input', saveFormData);

            function showAjaxMessage(success, message) {
                ajaxMessageContainer.innerHTML = '';
                const div = document.createElement('div');
                div.className = `mb-4 p-4 rounded ${success ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`;
                div.textContent = message;
                ajaxMessageContainer.appendChild(div);
                // Auto scroll to message
                div.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
                // Auto hide after 20 seconds
                setTimeout(() => {
                    if (div.parentNode) div.remove();
                }, 20000);
            }

            disputeForm?.addEventListener('submit', function(e) {
                e.preventDefault();
                modalErrors.innerHTML = '';
                const courseRows = coursesContainer.querySelectorAll('.course-row');
                let hasValidCourse = false;
                let validationErrors = [];
                courseRows.forEach((row, index) => {
                    const courseInput = row.querySelector('input[name^="dispute_course"]');
                    const dateInput = row.querySelector('input[name^="dispute_course_date"]');
                    const courseName = courseInput ? courseInput.value.trim() : '';
                    const courseDate = dateInput ? dateInput.value.trim() : '';
                    if (courseName === '' && courseDate === '') {
                        validationErrors.push(`Row ${index + 1}: Please fill in the course name and date or remove this row.`);
                    } else if (courseName === '') {
                        validationErrors.push(`Row ${index + 1}: Please enter a course name or remove this row.`);
                    } else if (courseDate === '') {
                        validationErrors.push(`Row ${index + 1}: Please select a date or remove this row.`);
                    } else {
                        hasValidCourse = true;
                    }
                });
                if (validationErrors.length > 0) {
                    const ul = document.createElement('ul');
                    ul.className = 'list-disc pl-6 text-left';
                    validationErrors.forEach(err => {
                        const li = document.createElement('li');
                        li.textContent = err;
                        ul.appendChild(li);
                    });
                    modalErrors.innerHTML = `<div class="mb-4 p-4 rounded bg-orange-100 text-orange-800 border border-orange-300"><strong>Please fix the following:</strong>${ul.outerHTML}</div>`;
                    return;
                }
                if (!hasValidCourse) {
                    modalErrors.innerHTML = '<div class="mb-4 p-4 rounded bg-orange-100 text-orange-800 border border-orange-300">Please add at least one complete course (name and date).</div>';
                    return;
                }

                const formData = new FormData(disputeForm);
                const submitBtn = disputeForm.querySelector('button[type="submit"]');
                const originalText = submitBtn.textContent;
                submitBtn.disabled = true;
                submitBtn.textContent = 'Submitting...';

                fetch(window.location.href, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(res => {
                        if (!res.ok) {
                            throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                        }
                        return res.json();
                    })
                    .then(data => {
                        if (data.success) {
                            showAjaxMessage(true, data.message || 'Complaint submitted successfully!');
                            closeModal();
                            setTimeout(() => {
                                clearAllCourseRowsAndComment();
                                const msgDiv = ajaxMessageContainer.querySelector('.bg-green-100');
                                // if (msgDiv) setTimeout(() => msgDiv.remove(), 5000);
                            }, 500);
                        } else {
                            const errs = data.errors || [data.message || 'Submission failed'];
                            const ul = document.createElement('ul');
                            ul.className = 'list-disc pl-5';
                            errs.forEach(err => {
                                const li = document.createElement('li');
                                li.textContent = err;
                                ul.appendChild(li);
                            });
                            modalErrors.innerHTML = `<div class="mb-4 p-3 rounded bg-red-50 text-red-800">${ul.outerHTML}</div>`;
                        }
                    })
                    .catch(err => {
                        console.error('Fetch error:', err);
                        modalErrors.innerHTML = `<div class="mb-4 p-3 rounded bg-red-50 text-red-800">Network or server error: ${err.message}</div>`;
                    })
                    .finally(() => {
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalText;
                    });
            });

            // Auto-dismiss non-AJAX messages after 20 seconds
            document.querySelectorAll('.auto-dismiss-message').forEach(function(message) {
                setTimeout(function() {
                    if (message && message.parentNode) {
                        message.style.transition = 'opacity 0.5s ease';
                        message.style.opacity = '0';
                        setTimeout(function() {
                            if (message && message.parentNode) message.remove();
                        }, 500);
                    }
                }, 20000);
            });
        })();
    </script>
</body>

</html>