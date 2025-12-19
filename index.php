<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/include/db_connect.php';

// Config
const DEFAULT_TO_BE_DAYS = 90;
const DISPUTE_COOLDOWN_DAYS = 7; // cooldown period

/* --------- Helpers --------- */
function safe_table(string $t): bool {
    return (bool) preg_match('/^course_records_[a-zA-Z0-9_]+$/', $t);
}
function parse_human_date(?string $raw): ?string {
    if ($raw === null) return null;
    $s = trim($raw); if ($s === '') return null;
    $lower = mb_strtolower($s);
    if (in_array($lower, ['n/a','na','—','-','none'], true)) return null;
    $s = preg_replace('/(\d+)(st|nd|rd|th)/i','$1',$s);
    $s = str_replace(',', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    $formats = ['j-M-y','d-M-y','j-M-Y','d-M-Y','j F Y','d F Y','Y F j','Y M j','M j Y','Y-m-d','d/m/Y','m/d/Y','d.m.Y','Y'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat('!'.$fmt,$s);
        if ($dt !== false) return $dt->format('Y-m-d');
    }
    $ts = strtotime($s);
    if ($ts !== false && $ts !== -1) return date('Y-m-d',$ts);
    return null;
}
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function badge_class_by_gap(string $gap): string {
    switch ($gap) {
        case 'expired': return 'bg-red-600 text-white';
        case 'tobe':    return 'bg-yellow-500 text-white';
        case 'initial': return 'bg-amber-500 text-white';
        case 'ok':      return 'bg-green-600 text-white';
        default:        return 'bg-gray-500 text-white';
    }
}
function badge_class_by_status(string $status): string {
    switch (strtolower($status)) {
        case 'open': return 'bg-yellow-500 text-white';
        case 'closed': return 'bg-green-600 text-white';
        case 'pending': return 'bg-blue-500 text-white';
        case 'resolved': return 'bg-green-500 text-white';
        case 'rejected': return 'bg-red-600 text-white';
        default: return 'bg-gray-500 text-white';
    }
}

/* --------- Handle Dispute Status Check --------- */
$dispute_status_result = null;
$dispute_error = '';
$dispute_id_input = '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['check_dispute_status'])) {
    $dispute_id_input = trim((string)($_GET['dispute_id'] ?? ''));
    
    if ($dispute_id_input === '') {
        $dispute_error = "Please enter a Dispute ID.";
    } else {
        // Check if it's numeric
        if (!is_numeric($dispute_id_input)) {
            $dispute_error = "Dispute ID must be a number.";
        } else {
            $dispute_id = (int)$dispute_id_input;
            
            // Fetch dispute details
            $stmt = $pdo->prepare("
                SELECT * FROM training_disputes 
                WHERE dispute_id = ? 
                LIMIT 1
            ");
            $stmt->execute([$dispute_id]);
            $dispute_status_result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$dispute_status_result) {
                $dispute_error = "Dispute ID not found.";
            }
        }
    }
}

/* --------- Load active courses --------- */
$courses = $pdo->query("
    SELECT course_id, course_code, course_name, COALESCE(validity_months, 24) AS validity_months, table_name
    FROM courses
    WHERE is_active = 1
    ORDER BY course_code
")->fetchAll(PDO::FETCH_ASSOC);

/* --------- Handle employee status check (form submit) --------- */
$employee = null;
$emp_id_submitted = '';
$results = [];
$error = '';

$employee_dispute_cooldown = [
    'can_raise' => true,
    'cooldown_until' => null, // DateTime or null
    'seconds_left' => 0
];

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
            // check dispute cooldown for this employee
            try {
                $stmt = $pdo->prepare("SELECT created_at FROM training_disputes WHERE employee_id = ? ORDER BY created_at DESC LIMIT 1");
                $stmt->execute([$employee['employee_id']]);
                $last = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($last && !empty($last['created_at'])) {
                    $lastDt = new DateTimeImmutable($last['created_at']);
                    $coolUntil = $lastDt->add(new DateInterval('P' . DISPUTE_COOLDOWN_DAYS . 'D'));
                    $now = new DateTimeImmutable('now');
                    if ($coolUntil > $now) {
                        $employee_dispute_cooldown['can_raise'] = false;
                        $employee_dispute_cooldown['cooldown_until'] = $coolUntil;
                        $employee_dispute_cooldown['seconds_left'] = (int)$now->diff($coolUntil)->format('%r%s');
                        // note: %r%s isn't standard for seconds; compute properly:
                        $employee_dispute_cooldown['seconds_left'] = (int)($coolUntil->getTimestamp() - $now->getTimestamp());
                    }
                }
            } catch (Exception $e) {
                // ignore, leave can_raise true
            }

            foreach ($courses as $c) {
                $table = $c['table_name'];
                $course_id = (int)$c['course_id'];
                $course_code = $c['course_code'];
                $course_name = $c['course_name'];
                $validity_months = (int)$c['validity_months'];
                $row = [
                    'course_id'=>$course_id,'course_code'=>$course_code,'course_name'=>$course_name,
                    'completed_raw'=>null,'completed'=>null,'status_raw'=>null,'status'=>null,'expiry'=>null,'gap_type'=>'initial'
                ];
                if (!$table || !safe_table($table)) {
                    $row['status']='N/A'; $row['gap_type']='na'; $results[]=$row; continue;
                }
                try {
                    $sql = "SELECT CompletedDate, Status FROM `{$table}` WHERE ID = ? LIMIT 1";
                    $s = $pdo->prepare($sql); $s->execute([$emp_id_submitted]); $rec = $s->fetch(PDO::FETCH_ASSOC);
                } catch (Exception $e) { $rec = false; }
                if (!$rec) { $row['status']='Not Taken'; $row['gap_type']='initial'; $results[]=$row; continue; }
                $completed_raw = $rec['CompletedDate'] ?? null;
                $parsed = ($completed_raw!==null && trim((string)$completed_raw)!=='') ? parse_human_date((string)$completed_raw) : null;
                $row['completed_raw']=$completed_raw; $row['completed']=$parsed;
                $status_raw = trim((string)($rec['Status'] ?? '')); $row['status_raw']=$status_raw;
                $st_lower = mb_strtolower($status_raw);
                if ($st_lower === '' || in_array($st_lower,['n/a','na','—','-','none'], true)) $status_norm='N/A';
                elseif (in_array($st_lower,['current','valid'], true)) $status_norm='Current';
                elseif (in_array($st_lower,['expired','overdue'], true)) $status_norm='Expired';
                elseif ($st_lower==='to be expired' || $st_lower==='tobe' || $st_lower==='to_be_expired') $status_norm='To be expired';
                else $status_norm = $status_raw === '' ? 'N/A' : $status_raw;
                $row['status'] = $status_norm;
                if ($parsed !== null) {
                    $dt = DateTime::createFromFormat('Y-m-d', $parsed);
                    if ($dt !== false) {
                        $expiry_dt = (clone $dt)->modify("+{$validity_months} months");
                        $row['expiry'] = $expiry_dt->format('Y-m-d');
                        $today = new DateTime('today');
                        if ($expiry_dt < $today) { $row['gap_type']='expired'; $row['status']='Expired'; }
                        else { $days_to_expiry = (int)$today->diff($expiry_dt)->days;
                            if ($expiry_dt >= $today && $days_to_expiry <= DEFAULT_TO_BE_DAYS) { $row['gap_type']='tobe'; $row['status']='To be expired'; }
                            else { $row['gap_type']='ok'; $row['status']='Current'; }
                        }
                    } else { $row['expiry']=null; $row['gap_type']=($status_norm==='Expired')?'expired':'na'; }
                } else {
                    if ($status_norm==='Expired') $row['gap_type']='expired';
                    elseif ($status_norm==='To be expired') $row['gap_type']='tobe';
                    elseif ($status_norm==='Current') $row['gap_type']='ok';
                    elseif ($status_norm==='N/A' || $status_norm==='Not Taken') $row['gap_type']='initial';
                    else $row['gap_type']='na';
                }
                $results[] = $row;
            } // foreach
        } // found employee
    } // validation
}

/* --------- Dispute: server-side handling (insert). Supports AJAX (JSON) and normal POST fallback --------- */
$dispute_ok = false;
$dispute_msg = '';
$errors_dispute = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['raise_dispute'])) {
    $is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    $emp_id = trim((string)($_POST['dispute_employee_id'] ?? ''));
    $emp_name = trim((string)($_POST['dispute_employee_name'] ?? ''));
    $comment = trim((string)($_POST['dispute_comment'] ?? ''));
    
    // Get courses from individual inputs
    $courses_list = [];
    $course_dates = [];
    
    if (isset($_POST['dispute_course']) && is_array($_POST['dispute_course'])) {
        foreach ($_POST['dispute_course'] as $index => $course) {
            $course = trim((string)$course);
            if ($course !== '') {
                $courses_list[] = $course;
                
                // Get corresponding date if exists
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

    if ($emp_id === '' || $emp_name === '') $errors_dispute[] = "Employee ID and name are required for a dispute.";

    if (count($courses_list) === 0) $errors_dispute[] = "Please list at least one course.";

    // SERVER-SIDE COOLDOWN CHECK (defense in depth)
    if ($emp_id !== '') {
        try {
            $stmt = $pdo->prepare("SELECT created_at FROM training_disputes WHERE employee_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$emp_id]);
            $last = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($last && !empty($last['created_at'])) {
                $lastDt = new DateTimeImmutable($last['created_at']);
                $coolUntil = $lastDt->add(new DateInterval('P' . DISPUTE_COOLDOWN_DAYS . 'D'));
                $now = new DateTimeImmutable('now');
                if ($coolUntil > $now) {
                    $errors_dispute[] = "please fill try after 1 week you already use your quota";
                }
            }
        } catch (Exception $e) {
            // if DB problem, we won't block on that basis; but log or add error if necessary
            // $errors_dispute[] = "Server error checking dispute cooldown: " . $e->getMessage();
        }
    }

    if (empty($errors_dispute)) {
        try {
            $ins = $pdo->prepare("INSERT INTO training_disputes
                (employee_id, employee_name, courses, course_dates, comment, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'open', NOW())");
            $ins->execute([
                $emp_id,
                $emp_name,
                json_encode(array_values($courses_list), JSON_UNESCAPED_UNICODE),
                json_encode(array_values($course_dates), JSON_UNESCAPED_UNICODE),
                $comment
            ]);
            $dispute_ok = true;
            $dispute_msg = "Your dispute has been submitted. Dispute ID: " . $pdo->lastInsertId();
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
    // non-AJAX flow: continue to render page and show errors/success in-page (already handled later)
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Generic Course Track — Check Your Training Status</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>body{font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial;} </style>
</head>
<body class="bg-gray-50 min-h-screen flex flex-col">

<header class="bg-blue-900 text-white shadow">
    <div class="max-w-6xl mx-auto px-6 py-5 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold">Generic Course Track</h1>
            <p class="text-sm opacity-80">Check your training & expiry status by entering your Employee ID</p>
        </div>
        <a href="admin/admin_login.php" class="bg-white text-blue-900 px-4 py-2 rounded font-semibold shadow">Admin Login</a>
    </div>
</header>

<main class="max-w-6xl mx-auto px-6 py-10 flex-grow">
    <!-- Dual forms side by side -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
        <!-- Check Training Status Form -->
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

        <!-- Check Dispute Status Form -->
        <div class="bg-white rounded-2xl shadow-lg p-8">
            <h2 class="text-2xl font-bold mb-4">Check Dispute Status</h2>
            <form method="GET" class="max-w-full">
                <label class="block text-sm font-medium text-gray-600 mb-1">Dispute ID</label>
                <div class="flex gap-3">
                    <input name="dispute_id" required value="<?= h($dispute_id_input) ?>"
                           class="flex-1 px-4 py-3 border rounded-lg text-lg font-mono focus:outline-none focus:ring-2 focus:ring-blue-200"
                           placeholder="e.g. 1001">
                    <input type="hidden" name="check_dispute_status" value="1">
                    <button type="submit" class="bg-green-700 text-white px-6 py-3 rounded-lg font-semibold shadow hover:bg-green-800">Check Dispute</button>
                </div>
                <p class="text-sm text-gray-500 mt-2">Enter the Dispute ID you received when submitting your dispute</p>
            </form>
        </div>
    </div>

    <!-- Show dispute status result -->
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
                        <div class="text-sm">Dispute Status</div>
                        <div class="text-2xl font-bold">Dispute ID: <?= h($dispute_status_result['dispute_id']) ?></div>
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
                <!-- Dispute Details -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div>
                        <h3 class="font-bold text-gray-700 mb-2">Dispute Details</h3>
                        <div class="space-y-3">
                            <div>
                                <div class="text-sm text-gray-500">Courses Disputed:</div>
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
                                        Your dispute is currently being reviewed by the HR team. Please check back later for updates.
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

                <!-- Timeline -->
                <div class="border-t pt-4">
                    <h3 class="font-bold text-gray-700 mb-3">Dispute Timeline</h3>
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

    <!-- Existing employee status section -->
    <?php if ($error !== ''): ?>
        <div class="bg-red-100 border border-red-300 text-red-700 p-6 rounded-lg mb-6"><?= h($error) ?></div>
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
                    <div class="text-right text-sm opacity-80">Updated view</div>
                </div>
            </div>

            <div class="p-6">
                <!-- Placeholder for AJAX messages (success or error) -->
                <div id="ajaxMessageContainer">
                    <!-- If non-AJAX submission resulted in message, show it here -->
                    <?php if ($dispute_ok): ?>
                        <div class="mb-4 p-4 rounded bg-green-100 text-green-800"><?= h($dispute_msg) ?></div>
                    <?php elseif (!empty($errors_dispute)): ?>
                        <div class="mb-4 p-4 rounded bg-red-100 text-red-800">
                            <ul class="list-disc pl-5"><?php foreach ($errors_dispute as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="mb-6">
                    <?php
                        // Prepare data attributes so JS can decide whether to allow opening the modal.
                        $canRaise = $employee_dispute_cooldown['can_raise'] ? '1' : '0';
                        $cooldownUntilAttr = '';
                        $secondsLeftAttr = '0';
                        if (!$employee_dispute_cooldown['can_raise'] && $employee_dispute_cooldown['cooldown_until'] instanceof DateTimeImmutable) {
                            $cooldownUntilAttr = $employee_dispute_cooldown['cooldown_until']->format('Y-m-d\TH:i:s');
                            $secondsLeftAttr = (string) max(0, (int)$employee_dispute_cooldown['seconds_left']);
                        }
                    ?>
                    <button id="openDisputeBtn"
                        data-can-raise="<?= h($canRaise) ?>"
                        data-cooldown-until="<?= h($cooldownUntilAttr) ?>"
                        data-seconds-left="<?= h($secondsLeftAttr) ?>"
                        class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg font-semibold">Raise Dispute about Training</button>
                </div>

                <div class="overflow-x-auto">
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
                                <td class="p-3 text-center"><span class="inline-block px-3 py-1 rounded-full <?= h($badge) ?> text-sm font-semibold"><?= h($status_disp) ?></span></td>
                                <td class="p-3 text-center text-sm text-gray-700">
                                    <?php
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
        </div>

        <!-- Dispute Modal -->
        <div id="disputeModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 px-4">
            <div class="bg-white rounded-xl max-w-4xl w-full p-6 shadow-lg">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xl font-bold">Raise Training Dispute</h3>
                    <button id="closeDisputeBtn" class="text-gray-500 hover:text-gray-700 text-2xl leading-none">&times;</button>
                </div>

                <form id="disputeForm" method="POST">
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
                            <h4 class="font-semibold text-gray-700">Courses</h4>
                            <button type="button" id="clearAllBtn" class="text-sm text-red-600 hover:text-red-800">Clear All</button>
                        </div>
                        
                        <div id="coursesContainer" class="space-y-3 max-h-60 overflow-y-auto p-2 border rounded">
                            <!-- Course rows will be added here -->
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

                    <!-- modal inline errors -->
                    <div id="modalErrors" class="mt-4"></div>

                    <div class="mt-6 flex items-center justify-between">
                        <div class="text-sm text-gray-600">We will send your dispute to the MRO HR record officer for review.</div>
                        <div class="flex gap-3">
                            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded font-semibold">Submit Dispute</button>
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

<footer class="bg-blue-950 text-gray-300 py-8 mt-auto">
    <div class="max-w-7xl mx-auto px-6 text-center">
        <p class="text-sm">© <?= date('Y') ?> <span class="font-semibold text-white">Generic Course Track</span>. All rights reserved.</p>
        <p class="text-xs mt-2 opacity-75">MRO HR Management System • Powered by Ethiopian Developers</p>
    </div>
</footer>

<!-- JS: modal + separate course inputs with add/remove functionality -->
<script>
(function(){
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

    function getSavedFormData() {
        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved) return JSON.parse(saved);
        } catch (e) {
            console.error('Error loading saved form data:', e);
        }
        return { courses: [], comment: '' };
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

    function openModal(){
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        modalErrors.innerHTML = '';

        clearAllCourseRows();

        const savedFormData = getSavedFormData();

        if (savedFormData.courses && savedFormData.courses.length > 0) {
            savedFormData.courses.forEach(course => {
                addCourseRow(course.name, course.date);
            });
        } else {
            addCourseRow(); // One empty row for new dispute
        }

        if (disputeComment && savedFormData.comment) {
            disputeComment.value = savedFormData.comment;
        }

        updateNoCoursesMessage();
    }

    function closeModal(){
        saveFormData();
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    openBtn?.addEventListener('click', function(e){
        const canRaise = openBtn.dataset.canRaise === '1';
        if (!canRaise) {
            showTemporaryFlash(false, 'Please try again after one week, as you have already used your quota.', 10000);
            return;
        }
        openModal();
    });

    closeBtn?.addEventListener('click', closeModal);
    modal?.addEventListener('click', function(e){
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
            if (courseInput) {
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
        if (noCoursesMessage) {
            noCoursesMessage.style.display = rows === 0 ? 'block' : 'none';
        }
    }

    addCourseRowBtn?.addEventListener('click', () => addCourseRow());

    clearAllBtn?.addEventListener('click', () => {
        if (confirm('Are you sure you want to clear ALL courses and the comment?')) {
            clearAllCourseRowsAndComment();
        }
    });

    if (disputeComment) disputeComment.addEventListener('input', saveFormData);

    // === MISSING FUNCTION FIXED ===
    function showAjaxMessage(success, message) {
        ajaxMessageContainer.innerHTML = '';
        const div = document.createElement('div');
        div.className = `mb-4 p-4 rounded ${success ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`;
        div.textContent = message;
        ajaxMessageContainer.appendChild(div);
    }

    function showTemporaryFlash(success, message, durationMs) {
        ajaxMessageContainer.innerHTML = '';
        const div = document.createElement('div');
        div.className = `mb-4 p-4 rounded ${success ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`;
        div.textContent = message;
        ajaxMessageContainer.appendChild(div);
        setTimeout(() => div.remove(), durationMs);
    }

    // === FORM SUBMISSION WITH FRIENDLY VALIDATION ===
    disputeForm?.addEventListener('submit', function(e){
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

        // Comment can be empty — no validation

        if (validationErrors.length > 0) {
            const ul = document.createElement('ul');
            ul.className = 'list-disc pl-6 text-left';
            validationErrors.forEach(err => {
                const li = document.createElement('li');
                li.textContent = err;
                ul.appendChild(li);
            });
            modalErrors.innerHTML = `<div class="mb-4 p-4 rounded bg-orange-100 text-orange-800 border border-orange-300">
                <strong>Please fix the following:</strong>${ul.outerHTML}
            </div>`;
            return;
        }

        if (!hasValidCourse) {
            modalErrors.innerHTML = '<div class="mb-4 p-4 rounded bg-orange-100 text-orange-800 border border-orange-300">Please add at least one complete course (name and date).</div>';
            return;
        }

        const formData = new FormData(disputeForm);
        fetch(location.href, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.ok ? res.json() : res.text().then(t => { throw new Error(t); }))
        .then(data => {
            if (data.success) {
                showAjaxMessage(true, data.message || 'Dispute submitted successfully!');
                closeModal();
                clearAllCourseRowsAndComment();
                openBtn.dataset.canRaise = '0';
            } else {
                const errs = data.errors || [data.message || 'Submission failed'];
                if (errs.length === 1 && errs[0].toLowerCase().includes('please try after 1 week')) {
                    showTemporaryFlash(false, errs[0], 10000);
                }
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
        });
    });

    // Cleanup old employee data
    document.addEventListener('DOMContentLoaded', () => {
        const lastId = localStorage.getItem('last_employee_id');
        if (lastId && lastId !== EMPLOYEE_ID) {
            localStorage.removeItem(`dispute_form_data_${lastId}`);
        }
        if (EMPLOYEE_ID) localStorage.setItem('last_employee_id', EMPLOYEE_ID);
    });
})();
</script>
</body>
</html>