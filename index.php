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
    $courses_raw = trim((string)($_POST['dispute_courses'] ?? ''));
    $comment = trim((string)($_POST['dispute_comment'] ?? ''));
    $course_dates_json = $_POST['dispute_course_dates_json'] ?? '[]';

    if ($emp_id === '' || $emp_name === '') $errors_dispute[] = "Employee ID and name are required for a dispute.";

    $lines = preg_split("/\r\n|\n|\r/", $courses_raw);
    $courses_list = [];
    foreach ($lines as $L) { $s = trim($L); if ($s !== '') $courses_list[] = $s; }
    if (count($courses_list) === 0) $errors_dispute[] = "Please list at least one course (one per line).";

    $course_dates = json_decode($course_dates_json, true);
    if (!is_array($course_dates)) $course_dates = [];

    $normalized_dates = [];
    for ($i = 0; $i < count($courses_list); $i++) {
        $d = $course_dates[$i] ?? '';
        $d = trim((string)$d);
        if ($d === '') $normalized_dates[] = null;
        else { $ts = strtotime($d); if ($ts === false) $normalized_dates[] = null; else $normalized_dates[] = date('Y-m-d', $ts); }
    }

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
                json_encode($normalized_dates, JSON_UNESCAPED_UNICODE),
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
    <div class="bg-white rounded-2xl shadow-lg p-8 mb-8">
        <h2 class="text-2xl font-bold mb-4">Check Your Training Status</h2>
        <form method="POST" class="max-w-2xl">
            <label class="block text-sm font-medium text-gray-600 mb-1">Employee ID</label>
            <div class="flex gap-3">
                <input name="employee_id" required value="<?= h($emp_id_submitted) ?>"
                       class="flex-1 px-4 py-3 border rounded-lg text-lg font-mono focus:outline-none focus:ring-2 focus:ring-blue-200"
                       placeholder="e.g. 19962">
                <button type="submit" class="bg-blue-700 text-white px-6 py-3 rounded-lg font-semibold shadow hover:bg-blue-800">Check Status</button>
            </div>
        </form>
    </div>

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
            <div class="bg-white rounded-xl max-w-3xl w-full p-6 shadow-lg">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xl font-bold">Raise Training Dispute</h3>
                    <button id="closeDisputeBtn" class="text-gray-500 hover:text-gray-700 text-2xl leading-none">&times;</button>
                </div>

                <form id="disputeForm" method="POST">
                    <input type="hidden" name="raise_dispute" value="1">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Employee Name</label>
                            <input type="text" name="dispute_employee_name" value="<?= h($employee['full_name']) ?>" readonly class="w-full px-4 py-2 border rounded bg-gray-100">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Employee ID</label>
                            <input type="text" name="dispute_employee_id" value="<?= h($employee['employee_id']) ?>" readonly class="w-full px-4 py-2 border rounded bg-gray-100">
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700">Courses (one per line)</label>
                            <div class="flex gap-4">
                                <textarea id="coursesTextarea" name="dispute_courses" rows="5" placeholder="e.g. Human Factors&#10;Fire Safety" class="flex-1 px-4 py-2 border rounded"></textarea>

                                <div class="w-64">
                                    <div class="text-sm text-gray-700 mb-2">Course Dates</div>
                                    <div id="datesContainer" class="space-y-2 overflow-auto max-h-40 p-1 border rounded bg-gray-50">
                                        <div class="text-xs text-gray-400">Enter dates for each course on the left (optional).</div>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="dispute_course_dates_json" id="dispute_course_dates_json" value="[]">
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700">General Comment</label>
                            <textarea name="dispute_comment" rows="4" placeholder="Explain the issue or any details for the MRO officer..." class="w-full px-4 py-2 border rounded"></textarea>
                        </div>
                    </div>

                    <!-- modal inline errors -->
                    <div id="modalErrors" class="mt-4"></div>

                    <div class="mt-6 flex items-center justify-between">
                        <div class="text-sm text-gray-600">We will send your dispute to the MRO record officer for review.</div>
                        <div class="flex gap-3">
                            <button type="button" id="addCourseRowBtn" class="bg-gray-200 hover:bg-gray-300 px-3 py-2 rounded">+ Add Empty Course Row</button>
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
        <p class="text-xs mt-2 opacity-75">HR Management System • Powered by Ethiopian Developers</p>
    </div>
</footer>

<!-- JS: modal + synchronize courses <-> date inputs + AJAX submit + cooldown flash -->
<script>
(function(){
    const openBtn = document.getElementById('openDisputeBtn');
    const closeBtn = document.getElementById('closeDisputeBtn');
    const modal = document.getElementById('disputeModal');
    const coursesTextarea = document.getElementById('coursesTextarea');
    const datesContainer = document.getElementById('datesContainer');
    const hiddenDates = document.getElementById('dispute_course_dates_json');
    const addCourseRowBtn = document.getElementById('addCourseRowBtn');
    const disputeForm = document.getElementById('disputeForm');
    const modalErrors = document.getElementById('modalErrors');
    const ajaxMessageContainer = document.getElementById('ajaxMessageContainer');

    function openModal(){ modal.classList.remove('hidden'); modal.classList.add('flex'); syncDates(); modalErrors.innerHTML=''; }
    function closeModal(){ modal.classList.add('hidden'); modal.classList.remove('flex'); }

    openBtn?.addEventListener('click', function(e){
        // client-side cooldown check
        const canRaise = openBtn.dataset.canRaise === '1';
        if (!canRaise) {
            // show the flash message for 10 seconds
            showTemporaryFlash(false, 'Please try again after one week, as you have already used your quota.', 10000);
            return;
        }
        openModal();
    });

    closeBtn?.addEventListener('click', closeModal);
    modal?.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });

    function getCourseLines(){
        const raw = coursesTextarea.value || '';
        return raw.split(/\r\n|\n|\r/).map(l => l.replace(/\u00A0/g,' ').trim());
    }

    function syncDates(){
        const lines = getCourseLines();
        datesContainer.innerHTML = '';
        const existingDates = JSON.parse(hiddenDates.value || '[]');
        for (let i=0;i<lines.length;i++){
            const wrapper = document.createElement('div'); wrapper.className='flex items-center gap-2';
            const label = document.createElement('div'); label.className='text-xs text-gray-600 w-1/2 truncate'; label.textContent = lines[i] || '(empty)';
            const input = document.createElement('input'); input.type='date'; input.className='w-1/2 px-2 py-1 border rounded'; input.dataset.idx = i;
            if (existingDates[i]) input.value = existingDates[i];
            wrapper.appendChild(label); wrapper.appendChild(input); datesContainer.appendChild(wrapper);
        }
        if (lines.length === 0) {
            // keep a hint row
            const hint = document.createElement('div'); hint.className='text-xs text-gray-400'; hint.textContent='Enter courses in the textarea to add date fields.';
            datesContainer.appendChild(hint);
        }
    }

    let debounceTimer = null;
    coursesTextarea?.addEventListener('input', function(){ clearTimeout(debounceTimer); debounceTimer = setTimeout(syncDates, 200); });

    addCourseRowBtn?.addEventListener('click', function(){
        coursesTextarea.value = coursesTextarea.value + (coursesTextarea.value ? "\n" : "") + "";
        syncDates();
    });

    function showAjaxMessage(success, message) {
        ajaxMessageContainer.innerHTML = '';
        const div = document.createElement('div');
        div.className = 'mb-4 p-4 rounded ' + (success ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800');
        div.textContent = message;
        ajaxMessageContainer.appendChild(div);
    }

    // show temporary flash in ajaxMessageContainer for specified duration (ms)
    function showTemporaryFlash(success, message, durationMs) {
        ajaxMessageContainer.innerHTML = '';
        const div = document.createElement('div');
        div.className = 'mb-4 p-4 rounded ' + (success ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800');
        div.textContent = message;
        ajaxMessageContainer.appendChild(div);
        // auto-hide after durationMs
        setTimeout(() => {
            if (ajaxMessageContainer.contains(div)) {
                ajaxMessageContainer.removeChild(div);
            }
        }, durationMs);
    }

    disputeForm?.addEventListener('submit', function(e){
        e.preventDefault();
        modalErrors.innerHTML = '';

        // build hiddenDates
        const lines = getCourseLines();
        const inputs = datesContainer.querySelectorAll('input[type="date"]');
        const dates = [];
        for (let i = 0; i < lines.length; i++){
            let v = null;
            const inp = Array.from(inputs).find(x => x.dataset.idx == i);
            if (inp && inp.value) v = inp.value;
            dates.push(v);
        }
        hiddenDates.value = JSON.stringify(dates);

        const formData = new FormData(disputeForm);

        // Send AJAX POST
        fetch(location.href, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(async res => {
            const ct = res.headers.get('content-type') || '';
            if (ct.indexOf('application/json') !== -1) {
                return res.json();
            } else {
                // fallback: if not JSON, treat as error
                const text = await res.text();
                throw new Error('Unexpected server response: ' + text.slice(0,200));
            }
        })
        .then(data => {
            if (data.success) {
                // show success, close modal, clear form
                showAjaxMessage(true, data.message || 'Dispute submitted');
                closeModal();
                disputeForm.reset();
                hiddenDates.value = '[]';
                syncDates();
                // Since a dispute was added, disable further attempts on client (cooldown starts)
                openBtn.dataset.canRaise = '0';
            } else {
                // If server returned the cooldown message, show it as a temporary flash as well
                const errs = data.errors || [data.message || 'Submission failed'];
                // if the only error is the cooldown message, show as temporary flash for 10s and do not close modal
                if (errs.length === 1 && errs[0].toLowerCase().includes('please fill try after 1 week')) {
                    showTemporaryFlash(false, errs[0], 10000);
                    // Also show inside modal
                    const ul = document.createElement('ul'); ul.className='list-disc pl-5';
                    const li = document.createElement('li'); li.textContent = errs[0]; ul.appendChild(li);
                    modalErrors.innerHTML = '';
                    const box = document.createElement('div'); box.className='mb-4 p-3 rounded bg-red-50 text-red-800'; box.appendChild(ul);
                    modalErrors.appendChild(box);
                } else {
                    // show errors inside modal (do not close)
                    const ul = document.createElement('ul'); ul.className='list-disc pl-5';
                    errs.forEach(err => {
                        const li = document.createElement('li'); li.textContent = err; ul.appendChild(li);
                    });
                    modalErrors.innerHTML = '';
                    const box = document.createElement('div'); box.className='mb-4 p-3 rounded bg-red-50 text-red-800'; box.appendChild(ul);
                    modalErrors.appendChild(box);
                }
            }
        })
        .catch(err => {
            modalErrors.innerHTML = '';
            const box = document.createElement('div'); box.className='mb-4 p-3 rounded bg-red-50 text-red-800';
            box.textContent = 'Network or server error: ' + err.message;
            modalErrors.appendChild(box);
        });
    });

    document.addEventListener('DOMContentLoaded', syncDates);

})();
</script>

</body>
</html>
