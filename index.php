<?php
// Serve crawler files through the PHP front controller as well as the root static files.
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($requestPath === '/robots.txt') {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
    echo implode("\n", [
        'User-agent: *',
        'Allow: /',
        'Disallow: /admin/',
        'Disallow: /include/',
        'Disallow: /Script/',
        'Disallow: /TCPDF/',
        'Disallow: /vendor/',
        'Disallow: /logs/',
        'Disallow: /print_pdf.php',
        '',
        'Sitemap: https://coursetrack.etairtv.com/sitemap.xml',
        '',
    ]);
    exit;
}
if ($requestPath === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    echo '  <url><loc>https://coursetrack.etairtv.com/about.php</loc></url>' . "\n";
    echo '</urlset>';
    exit;
}
$lookupResponse = (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET')
    || isset($_GET['dispute_id'])
    || isset($_GET['check_dispute_status']);
$portalRobotsDirective = $lookupResponse
    ? 'noindex, nofollow, nosnippet'
    : 'noindex, follow';
header('X-Robots-Tag: ' . $portalRobotsDirective, true);

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/include/db_connect.php';
require_once __DIR__ . '/include/cc_overrides.php';

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
$NEVER_EXPIRE_COURSES = ['ESD', 'ETOPS', 'FUEL', 'ETTPM', 'MCM', 'MNX'];

/* --------- Helper Functions --------- */
function is_always_not_taken_course(string $employee_id, string $course_code): bool
{
    if ($employee_id !== '36689') {
        return false;
    }
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

/**
 * Loads the CC → required-course map from cc_course_requirements.
 */
function load_cc_course_map(PDO $pdo): array
{
    $map = [];
    try {
        $rows = $pdo->query("SELECT cost_center, course_code FROM cc_course_requirements")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $map[trim($r['cost_center'])][trim($r['course_code'])] = true;
        }
    } catch (Exception $e) {
        return [];
    }
    return $map;
}

/**
 * Detects management-level positions.
 */
function is_sms_management_role(?string $position): bool
{
    $pos = strtoupper(trim((string)$position));
    if ($pos === '') return false;

    return (strpos($pos, 'MANAGER') !== false)
        || (strpos($pos, 'MGR')     !== false)
        || (strpos($pos, 'MD')      !== false)
        || (strpos($pos, 'TEAM LEADER') !== false)
        || (strpos($pos, 'DIR')     !== false);
}

/**
 * Position eligibility — position rules ONLY.
 */
function is_position_eligible(string $course_code, ?string $position): bool
{
    $code = strtoupper(trim($course_code));
    $pos  = strtoupper(trim((string)$position));

    // Normalize whitespace (fixes double spaces in positions like "TECHNICIAN  IV")
    $pos  = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\s]+/u', ' ', $pos);

    $is_mgmt = is_sms_management_role($position);

    if ($code === 'SMS-MGR')  return $is_mgmt;
    if ($code === 'SMSMRO')   return !$is_mgmt;

    if ($code === 'ETTPM') {
        return (strpos($pos, 'TEAM LEADER') !== false)
            || (strpos($pos, 'DIR') !== false)
            || (strpos($pos, 'MANAGER') !== false)
            || (strpos($pos, 'MGR') !== false);
    }

    if ($code === 'IP') {
        return (strpos($pos, 'TECHNICIAN III') !== false)
            || (strpos($pos, 'TECHNICIAN IV')  !== false)
            || (strpos($pos, 'TEAM LEADER')    !== false)
            || (strpos($pos, 'FLEET')          !== false)
            || (strpos($pos, 'INSPECTOR')      !== false)
            || (strpos($pos, 'MANAGER')        !== false);
    }

    if ($code === 'MEL') {
        if (strpos($pos, 'MECHANIC') !== false) return false;
    }

    if ($code === 'DGR' || $code === 'RCAT') {
        if (strpos($pos, 'TEAM LEADER') !== false || strpos($pos, 'MGR') !== false || strpos($pos, 'MANAGER') !== false) return false;
    }

    return true;
}

/**
 * Combined eligibility.
 * 1. Check SKIP overrides (wins over everything).
 * 2. Check TAKE overrides (wins over CC map + position).
 * 3. Fall back to CC map AND position rules.
 */
function is_employee_eligible_for_course(array $cc_course_map, array $cc_dept_map, ?string $cc, string $course_code, ?string $position): bool
{
    global $cc_position_overrides;

    $emp_dept = $cc_dept_map[trim((string)$cc)] ?? '';

    // STEP -1 — SKIP override (highest priority)
    if (
        !empty($cc_position_overrides)
        && is_forced_skipped_by_override($cc_position_overrides, $cc, $position, $course_code, $emp_dept)
    ) {
        return false;
    }

    // STEP 0 — TAKE override (bypasses CC map and position)
    if (
        !empty($cc_position_overrides)
        && is_forced_applicable_by_override($cc_position_overrides, $cc, $position, $course_code, $emp_dept)
    ) {
        return true;
    }

    // STEP 1 — Standard CC map gate
    if (empty($cc_course_map)) {
        return is_position_eligible($course_code, $position);
    }
    if ($cc === null || trim($cc) === '') return false;
    if (!isset($cc_course_map[$cc][$course_code])) return false;

    // STEP 2 — Standard position rules
    return is_position_eligible($course_code, $position);
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
        return 'One-Time';
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
        case 'na':
            return 'bg-slate-400 text-white';
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

/* --------- Load Maps --------- */
$cc_course_map = load_cc_course_map($pdo);
$cc_position_overrides = load_cc_position_overrides($pdo);

$cc_dept_map = [];
try {
    $rows = $pdo->query("SELECT cost_center, department FROM cost_center_departments")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $cc_dept_map[trim($r['cost_center'])] = trim($r['department']);
    }
} catch (Exception $e) {
}

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
            foreach ($courses as $c) {
                $table = trim($c['table_name']);
                $course_code = $c['course_code'];
                $course_name = $c['course_name'];
                $validity_months = (int)$c['validity_months'];

                // Base global forever flag
                $is_global_forever = in_array($course_code, $NEVER_EXPIRE_COURSES) || $validity_months >= FOREVER_THRESHOLD;

                // Not-Applicable gate — checks CC map + Dept map + position rules + overrides
                $cc  = $employee['cost_center'] ?? '';
                $pos = $employee['position'] ?? '';
                $emp_dept = $cc_dept_map[$cc] ?? '';

                if (!is_employee_eligible_for_course($cc_course_map, $cc_dept_map, $cc, $course_code, $pos)) {
                    $results[] = [
                        'course_code'     => $course_code,
                        'course_name'     => $course_name,
                        'completed_raw'   => null,
                        'completed'       => null,
                        'status_raw'      => 'Not Applicable',
                        'status'          => 'Not Applicable',
                        'expiry'          => null,
                        'gap_type'        => 'na',
                        'validity_months' => $validity_months
                    ];
                    continue;
                }

                // Check if this specific employee has a FOREVER override rule
                $is_forever = $is_global_forever || is_forced_forever_by_override(
                    $cc_position_overrides,
                    $cc,
                    $pos,
                    $course_code,
                    $emp_dept
                );

                // Get employee ID for special handling
                $employee_id = $employee['employee_id'];

                // Check if this is employee 36689 with a special course that should always show "Not Taken"
                $always_not_taken = is_always_not_taken_course($employee_id, $course_code);

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
                    continue;
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

                    // For never-expire courses (or per-employee FOREVER overrides) with a valid date -> show as "Current"
                    if ($is_forever && $parsed !== null && $parsed !== '') {
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
            $table_check = $pdo->query("SHOW TABLES LIKE 'training_disputes'")->fetch();
            if (!$table_check) {
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
        echo json_encode(['can_proceed' => true]);
    }
    exit;
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">


    <title>Ethiopian Airlines CourseTrack | Employee Training Status</title>
    <meta name="description" content="Use Ethiopian Airlines CourseTrack to check recorded employee course completion, training expiry dates, and submitted complaint status.">
    <meta name="robots" content="<?= $portalRobotsDirective ?>">
    <meta property="og:title" content="Ethiopian Airlines CourseTrack | Employee Training Status">
    <meta property="og:description" content="Check course completion, training expiry dates, and submitted course complaint updates with CourseTrack.">
    <meta property="og:url" content="https://coursetrack.etairtv.com/">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="en_ET">
    <meta property="og:site_name" content="Ethiopian Airlines CourseTrack">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Ethiopian Airlines CourseTrack | Employee Training Status">
    <meta name="twitter:description" content="Check course completion, training expiry dates, and submitted course complaint updates with CourseTrack.">
    <meta name="theme-color" content="#588D01">
    <link rel="icon" type="image/png" href="img/ethiopian_logo.ico">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
        }

        input[type="date"] {
            max-width: 160px;
        }

        input[type="date"]:invalid {
            color: #999;
        }
    </style>
</head>

<body class="bg-gray-50 min-h-screen flex flex-col">
    <?php include __DIR__ . '/training_notice_popup.php'; ?>
    <header class="bg-[#588D01] text-white shadow">
        <div class="max-w-6xl mx-auto px-6 py-5 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <img src="./img/logo.png" alt="Logo" class="h-[3.5rem] w-auto" onerror="this.style.display='none'">
                <div>
                    <h1 class="text-2xl font-bold">MRO Employees Training Tracker</h1>
                    <p class="text-sm opacity-80">Check your training & expiry status by entering your Employee ID</p>
                    <p class="text-[11px] text-white/70 mt-0.5">
                        Integrated with Ethiopian Airlines LMS (<a href="https://learning1.ethiopianairlines.com/" target="_blank" class="underline hover:text-white">learning1.ethiopianairlines.com</a>)
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="admin/admin_login.php" class="bg-white text-blue-900 px-4 py-2 rounded font-semibold shadow">HR Login</a>
                <a href="admin/admin_panel.php?page=department_gap_report" class="bg-white text-blue-900 px-4 py-2 rounded font-semibold shadow">MRO Management Login</a>
            </div>
        </div>
    </header>
    <nav class="w-full bg-gradient-to-r from-red-700 via-red-600 to-orange-500 text-white shadow-lg relative">
        <div class="max-w-6xl mx-auto px-6">
            <ul class="flex flex-wrap items-center gap-x-1 gap-y-1 py-1.5 text-sm">
                <li class="flex items-center gap-1.5 px-3 py-1.5 font-bold uppercase tracking-wider text-amber-300">
                    <span>Useful Links</span>
                    <span class="relative flex h-3 w-3 items-center justify-center">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-300"></span>
                    </span>
                    <span class="animate-bounce font-extrabold text-lg leading-none ml-0.5">👉</span>
                </li>
                <li>
                    <a href="https://deftrack.etairtv.com/live_statics.php"
                        target="_blank" rel="noopener"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-blue-50 hover:bg-blue-700 hover:text-white transition-colors font-medium">
                        <span>🛠️</span>
                        <span>All Fleet ADD FOLLOW UP (Open Fault Track)</span>
                        <span class="text-blue-300 text-xs">↗</span>
                    </a>
                </li>
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
    <main class="max-w-6xl mx-auto px-6 py-10 flex-grow">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <div class="bg-white rounded-2xl shadow-lg p-8">
                <h2 class="text-2xl font-bold mb-4">Check Your Training Status</h2>
                <form method="POST" class="max-w-full">
                    <label class="block text-sm font-medium text-gray-600 mb-1">Employee ID</label>
                    <div class="flex gap-3">
                        <input name="employee_id" required value="<?= h($emp_id_submitted) ?>"
                            class="flex-1 px-4 py-3 border rounded-lg text-lg font-mono focus:outline-none focus:ring-2 focus:ring-blue-200"
                            placeholder="e.g. 19962">
                        <button type="submit" class="bg-[#588D01] text-white px-6 py-3 rounded-lg font-semibold shadow hover:bg-[#3B5E01] transition">Check Status</button>
                    </div>
                </form>
            </div>

            <div id="complaint-status" class="bg-white rounded-2xl shadow-lg p-8">
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

        <!-- ═══════════════════════════════════════════════════════════
             ERROR BANNERS — shown ABOVE the notice card
             ═══════════════════════════════════════════════════════════ -->
        <?php if ($dispute_error !== ''): ?>
            <div class="bg-red-100 border border-red-300 text-red-700 p-6 rounded-lg mb-6">
                <div class="font-semibold">Error:</div>
                <p><?= h($dispute_error) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="bg-red-100 border border-red-300 text-red-700 p-6 rounded-lg mb-6"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if (!$employee && !$dispute_status_result): ?>
            <!-- ═══════════════════════════════════════════════════════════
             Static "Training is Mandatory" notice card
             Only visible BEFORE the user looks up an employee or complaint.
             Hides automatically once results are shown.
             ═══════════════════════════════════════════════════════════ -->
            <div id="inlineTrainingNotice" class="mb-8">
                <div class="rounded-2xl shadow-xl overflow-hidden" style="background: linear-gradient(160deg,#06301C,#14773F);">
                    <div class="relative">

                        <!-- Decorative floating circles -->
                        <div class="pointer-events-none absolute -top-24 -right-24 w-96 h-96 rounded-full bg-yellow-400/20 animate-pulse"></div>
                        <div class="pointer-events-none absolute -bottom-24 -left-24 w-72 h-72 rounded-full bg-white/5"></div>

                        <div class="relative z-[1] p-6 md:p-8 text-white">

                            <!-- Badge -->
                            <span class="inline-flex items-center gap-2 bg-[#FFB800] text-[#06301C] font-extrabold tracking-wider px-4 py-1.5 rounded-full text-xs shadow-md">
                                <span class="w-5 h-5 rounded-full bg-[#06301C] text-[#FFB800] inline-flex items-center justify-center text-xs font-black">!</span>
                                IMPORTANT NOTICE
                            </span>

                            <!-- Title -->
                            <h1 class="text-3xl md:text-4xl font-extrabold mt-3 mb-1.5 leading-tight">
                                <span style="background:linear-gradient(90deg,#FFB800,#9be15d,#FFB800);background-size:200%;-webkit-background-clip:text;background-clip:text;color:transparent;animation:tnpShine 3s linear infinite;">
                                    Training is Mandatory.
                                </span>
                            </h1>
                            <p class="text-[#C9EBD6] text-sm md:text-base">
                                Keep your training up to date — every employee, every time.
                            </p>

                            <!-- Cards -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-5">

                                <!-- All Employees -->
                                <div class="bg-white text-[#06301C] rounded-xl overflow-hidden shadow-md">
                                    <h2 class="flex items-center gap-2 px-4 py-2.5 text-white text-sm md:text-base font-bold"
                                        style="background: linear-gradient(90deg,#2EAD5F,#5CCB88);">
                                        👤 ALL EMPLOYEES
                                    </h2>
                                    <ul class="p-2.5">
                                        <li class="tnp-check flex items-start gap-2.5 p-2 rounded-lg cursor-pointer hover:bg-[#EEF8F2] transition select-none"
                                            data-group="emp">
                                            <div class="min-w-0">
                                                <b class="block text-sm md:text-[15px]">Own your training</b>
                                                <small class="text-[#4A6657] text-xs md:text-sm">Follow your personal training plan &amp; schedule.</small>
                                            </div>
                                        </li>
                                        <li class="tnp-check flex items-start gap-2.5 p-2 rounded-lg cursor-pointer hover:bg-[#EEF8F2] transition select-none"
                                            data-group="emp">
                                            <div class="min-w-0">
                                                <b class="block text-sm md:text-[15px]">Attend &amp; pass</b>
                                                <small class="text-[#4A6657] text-xs md:text-sm">Join every scheduled course and pass all exams.</small>
                                            </div>
                                        </li>
                                    </ul>
                                </div>

                                <!-- Team Leaders -->
                                <div class="bg-white text-[#06301C] rounded-xl overflow-hidden shadow-md">
                                    <h2 class="flex items-center gap-2 px-4 py-2.5 text-white text-sm md:text-base font-bold"
                                        style="background: linear-gradient(90deg,#0B6E4F,#1FA37A);">
                                        ⭐ TEAM LEADERS
                                    </h2>
                                    <ul class="p-2.5">
                                        <li class="tnp-check flex items-start gap-2.5 p-2 rounded-lg cursor-pointer hover:bg-[#EEF8F2] transition select-none"
                                            data-group="tl">
                                            <div class="min-w-0">
                                                <b class="block text-sm md:text-[15px]">Follow up</b>
                                                <small class="text-[#4A6657] text-xs md:text-sm">Check each team member's training status.</small>
                                            </div>
                                        </li>
                                        <li class="tnp-check flex items-start gap-2.5 p-2 rounded-lg cursor-pointer hover:bg-[#EEF8F2] transition select-none"
                                            data-group="tl">
                                            <div class="min-w-0">
                                                <b class="block text-sm md:text-[15px]">Enforce</b>
                                                <small class="text-[#4A6657] text-xs md:text-sm">Make sure no one misses required training.</small>
                                            </div>
                                        </li>
                                        <li class="tnp-check flex items-start gap-2.5 p-2 rounded-lg cursor-pointer hover:bg-[#EEF8F2] transition select-none"
                                            data-group="tl">
                                            <div class="min-w-0">
                                                <b class="block text-sm md:text-[15px]">Keep it current</b>
                                                <small class="text-[#4A6657] text-xs md:text-sm">No overdue or expired training in your team.</small>
                                            </div>
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <!-- Reminder chips -->
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-5">
                                <div class="bg-white/10 border border-white/20 rounded-xl px-4 py-3">
                                    <strong class="block text-xl text-[#FFB800]">2 yrs</strong>
                                    <span class="text-xs text-[#E1F5E9] leading-tight block mt-0.5">Refresher training required every 2 years</span>
                                </div>
                                <div class="bg-white/10 border border-white/20 rounded-xl px-4 py-3">
                                    <strong class="block text-xl text-[#FFB800]">Latest</strong>
                                    <span class="text-xs text-[#E1F5E9] leading-tight block mt-0.5">Use the latest ETTPM revision from the Portal</span>
                                </div>
                                <div class="bg-white/10 border border-white/20 rounded-xl px-4 py-3">
                                    <strong class="block text-xl text-[#FFB800]">Safety</strong>
                                    <span class="text-xs text-[#E1F5E9] leading-tight block mt-0.5">Qualified people keep aircraft safe</span>
                                </div>
                            </div>
                            
                            <!-- Footer actions -->
                            <div class="flex flex-wrap gap-3 justify-between items-center mt-4">
                                <div>
                                    <small class="text-[#C9EBD6] block text-sm">
                                        Ethiopian MRO | ETTPM &nbsp;•&nbsp;
                                        <b class="text-[#FFB800]">Train • Qualify • Fly Safe</b>
                                    </small>
                                    <small class="text-xs text-[#C9EBD6] block mt-1" id="tnpHint">
                                        Tap each point to tick it off.
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Gradient title animation for the inline notice -->
            <style>
                @keyframes tnpShine {
                    to {
                        background-position: 200%;
                    }
                }
            </style>

            <!-- Tick-off behavior for the inline notice (scoped to #inlineTrainingNotice) -->
            <script>
                (function() {
                    const card = document.getElementById('inlineTrainingNotice');
                    if (!card) return;

                    const items = card.querySelectorAll('.tnp-check');
                    const progress = card.querySelector('#tnpProgress');
                    const hintEl = card.querySelector('#tnpHint');
                    if (!items.length || !progress) return;

                    function updateProgress() {
                        const done = Array.from(items).filter(li => li.classList.contains('done')).length;
                        const total = items.length;
                        const pct = total ? (done / total) * 100 : 0;
                        progress.style.width = pct + '%';

                        items.forEach(li => {
                            const ck = li.querySelector('.tnp-ck');
                            if (!ck) return;
                            const group = li.dataset.group || 'emp';
                            const color = group === 'tl' ? '#0B6E4F' : '#2EAD5F';
                            if (li.classList.contains('done')) {
                                ck.style.background = color;
                                ck.style.color = '#fff';
                                ck.style.transform = 'scale(1.15) rotate(360deg)';
                            } else {
                                ck.style.background = '';
                                ck.style.color = 'transparent';
                                ck.style.transform = '';
                            }
                        });

                        
                    }

                    items.forEach(li => {
                        li.addEventListener('click', function() {
                            li.classList.toggle('done');
                            updateProgress();
                        });
                    });

                    updateProgress();
                })();
            </script>
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

        <?php if ($employee): ?>
            <div id="printableArea">
                <div class="bg-white rounded-2xl shadow-lg overflow-hidden mb-8">
                    <div class="bg-[#588D01] text-white p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-sm">Employee</div>
                                <div class="text-2xl font-bold"><?= h($employee['full_name']) ?></div>
                                <div class="text-sm opacity-90 mt-1">
                                    ID: <span class="font-mono"><?= h($employee['employee_id']) ?></span> —
                                    CC: <?= h($employee['cost_center'] ?? 'N/A') ?> •
                                    Department: <?= h($employee['department'] ?? 'N/A') ?> •
                                    <?= h($employee['position'] ?? 'N/A') ?>
                                </div>
                            </div>
                            <div class="text-right text-sm opacity-80">
                                <?= count($results) ?> courses loaded
                            </div>
                        </div>
                    </div>

                    <div class="p-6">
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

                        <div class="overflow-x-auto">
                            <table class="w-full table-auto">
                                <thead class="bg-gray-100 text-gray-700">
                                    <tr>
                                        <th class="p-3 text-left">Course</th>
                                        <th class="p-3 text-center">Course Completed Date</th>
                                        <th class="p-3 text-center">Course Expiry Date</th>
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
                                                        echo 'Not applicable for your section';
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

    <footer class="bg-[#588D01] text-white py-3 flex-shrink-0">
        <div class="w-full max-w-7xl mx-auto px-5 text-center">
            <p class="text-xs">
                © 2025 - 2026 <span class="font-semibold text-white">MRO Employees Training Tracker</span>. All rights reserved.
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

            const today = new Date().toISOString().split('T')[0];

            function getSavedFormData() {
                try {
                    const saved = localStorage.getItem(STORAGE_KEY);
                    if (saved) return JSON.parse(saved);
                } catch (e) {}
                return {
                    courses: [],
                    comment: ''
                };
            }

            function saveFormDataToStorage(data) {
                try {
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
                } catch (e) {}
            }

            function clearFormDataFromStorage() {
                try {
                    localStorage.removeItem(STORAGE_KEY);
                } catch (e) {}
            }

            async function checkCooldownAndOpenModal() {
                if (!EMPLOYEE_ID) {
                    showAjaxMessage(false, 'Employee ID not found. Please refresh the page.');
                    return;
                }
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
                    if (data.can_proceed) openModal();
                    else showAjaxMessage(false, data.message);
                } catch (error) {
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
                div.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
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
                        if (!res.ok) throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                        return res.json();
                    })
                    .then(data => {
                        if (data.success) {
                            showAjaxMessage(true, data.message || 'Complaint submitted successfully!');
                            closeModal();
                            setTimeout(() => {
                                clearAllCourseRowsAndComment();
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
                        modalErrors.innerHTML = `<div class="mb-4 p-3 rounded bg-red-50 text-red-800">Network or server error: ${err.message}</div>`;
                    })
                    .finally(() => {
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalText;
                    });
            });

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