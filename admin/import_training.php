<?php
// =========================================================
// import_training.php
// Auto-routing CSV Import for course_records_* tables
// Supports TWO CSV formats:
//
//   FORMAT A (old / "Course"):
//     ID Number, Name, Cost Center, Course, Certificate, Date Issued
//     → Routed via $course_table_map_old
//     → No grade check (all rows imported as today)
//
//   FORMAT B (new / "Quiz Name"):
//     ID Number, Name, Date finish, Grade, Cost center, Quiz Name
//     → Routed via $course_table_map_new
//     → Rows failing the quiz's pass-mark are skipped
// =========================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();
ob_start();

require_once '../include/db_connect.php';
require_once '../include/auth.php';
requireAdmin();

// ----------------------
// FORMAT A — Old mapping (unchanged behavior)
// ----------------------
$course_table_map_old = [
    'course_records_fuel' => ['AFHSRC'],
    'course_records_ecaras' => ['ECARASR', 'EASA', 'ECARAS'],
    'course_records_esd' => ['ESD'],
    'course_records_etops' => ['ETOPS'],
    'course_records_ewis' => ['EWIS', 'EWISR'],
    'course_records_fts' => ['FTSR', 'FTS'],
    'course_records_ip' => ['ACI'],
    'course_records_legislation' => ['AVL', 'Avlrc'],
    'course_records_mcm' => ['MCM', 'MCMR'],
    'course_records_mel' => ['MEL', 'MELIN'],
    'course_records_moe' => ['Moei', 'MOER'],
    'course_records_mpm' => ['MPMR', 'MPMI'],
    'course_records_regulation' => ['FAARR', 'FAAI'],
    'course_records_rsmqsm' => ['RSM QCM', 'RSM/QCM LMT'],
    'course_records_ettpm' => ['ETTPM', 'RSTPM'],
    'course_records_smsmgr' => ['SMSSM', 'STFMAS', 'SMSEM'],
    //'course_records_smsop' => ['SMSOP'],
    'course_records_smsmro' => ['STFMOP', 'SMSOP'],
    'course_records_rcat' => ['COM'],
    'course_records_dgr' => ['DG', 'DGR Handling in a Warehouse', 'DGRFAM'],
    'course_records_mnx' => ['MNTX'],
    'course_records_hf' => ['HFTRCECS', 'HFTICNCSO', 'HFAMP', 'HFR'],
    //'course_records_hfmp' => ['HFAMP', 'HFR'],
    'course_records_a350src' => ['a350r'],
    'course_records_b737asrc' => ['B737NG Refresher'],
    'course_records_b787asrc' => ['B787R'],
    'course_records_b737ngtomax' => ['B737NG to MAX'],
    'course_records_b767ascr' => ['B767R'],
    'course_records_b777rot' => ['B777R'],
    'course_records_q400asc' => ['Q400']
];

// ----------------------
// FORMAT B — New mapping (Quiz Name → Table)
// ----------------------
$course_table_map_new = [
    'course_records_fuel'         => ['Aircraft Fuel Handling and Servicing Final Exam'],
    'course_records_ecaras'       => ['ECARAS REFRESHER EXAM', 'ECARAS INITIAL EXAM'],
    'course_records_esd'          => ['ESD Final Exam'],
    'course_records_etops'        => ['ETOPS'],
    'course_records_ewis'         => [
        'EWIS EXAM 1',
        'EWIS Final Exam',
        'EWIS REFRESHER - EASA - 28 APRIL 2026',
        'EWIS INITIAL EXAM',
        'EWIS Final Exam (OUTSTATION)',
        'EWIS INITIAL EXAM - EASA - 27 APRIL 2026'
    ],
    'course_records_fts'          => [
        'Fuel Tank Safety-Refresher Exam',
        'Fuel Tank safety Initial Exam',
        'FUEL TANK SAFETY INITIAL',
        'FUEL TANK SAFETY INITIAL MAY 06',
        'Fuel Tank Safety-Refresher Exam (OUTSTATION)',
        'FUEL TASK TRAINING LEVEL I Exam (March 2025)',
        'FUEL TANK SAFETY INITIAL'
    ],
    'course_records_ip'           => [
        'ACI Final Exam',
        'A/C Inspection Initial Exam July 10',
        'AIRCRAFT INSPECTION',
        'Aircraft Inspection Training - Initial Exam-1'
    ],
    'course_records_legislation'  => [
        'Aviation Legislation Refresher Course - Final Exam',
        'Aviation Legislation Refresher Course -final exam(outstation)',
        'Aviation Legislation Refresher Final Exam',
        'Exam for Aviation Legislation',
        'Quiz for Aviation Legislation'
    ],
    'course_records_mcm'          => ['MCM', 'MCMR'],
    'course_records_mel'          => [
        'MEL Final Exam',
        'MEL Final Exam (OUTSTATION)',
        'MEL Initial Exam'
    ],
    'course_records_moe'          => [
        'MOE Final Exam',
        'MOE Refresher Course Final Exam',
        'MOE Refresher Course Final Exam (OUTSTATION)'
    ],
    'course_records_mpm'          => [
        'MPM Initial Final Exam',
        'MPM Refresher Final Exam',
        'MPM Refresher Final Exam (OUTSTATION)'
    ],
    'course_records_regulation'   => [
        'Aviation Regulation Initial Final Exam',
        'Aviation Regulation Refresher Final Exam',
         'FAAR Final Exam',
        'Aviation Regulation Refresher Final Exam (outstation)'
    ],
    'course_records_rsmqsm'       => [
        'Exam for RSM/QCM for LMT Initial',
        'RSM/QCM Final Exam',
        'RSM/QCM Final Exam (OUTSTATION)'
    ],
    'course_records_ettpm'        => ['ETTPM', 'RSTPM'],
    'course_records_smsmgr'       => [
        'SMS course Final Exam for Managers and Supervisors',
        'Examination'
    ],
    // 'course_records_smsop'        => ['Exam for SMS for Operational Personnel'],
    'course_records_smsmro'       => [
        'MROSMS Final Exam',
        'Exam for SMS for Operational Personnel',
        'Examination'
    ],
    'course_records_rcat'         => ['RADIOTELEPHONY EXAM'],
    'course_records_dgr'          => [
        'Exam - DGR for Aircraft Maintenance Personnel-Initial',
        'Exam for DGR training for Flight Operations Personnel and Flight Dispatchers-Initial',
        'Exam for DGR Training for General Cargo Accepting/ Processing Personnel - Recurrent',
        'Exam for DGR Training for Handling and Loading Personnel-Initial',
        'Exam for DGR Training for Handling and Loading Personnel-Recurrent',
        'Exam for DGR Training for Personnel Responsible for Passenger Handling -Initial'
    ],
    'course_records_mnx'          => ['Maintenix Exam'],
    'course_records_hf'           => [
        'Basic Trainees Examination',
        'EXAM HF-Initial (NEW)',
        'Exam for Human Factors',
        'EXAM For Human Factors for Maintenance Personnel-Refresher',
        'HUMAN FACTORS INITIAL EXAM (outstation)'
    ],
    // 'course_records_hfmp'         => [
    //     'Basic Trainees Examination',
    //     'EXAM HF-Initial (NEW)',
    //     'Exam for Human Factors',
    //     'EXAM For Human Factors for Maintenance Personnel-Refresher',
    //     'HUMAN FACTORS INITIAL EXAM (outstation)'
    // ],
    'course_records_a350src'      => [
        'A350 Systems Refresher Course Final Exam',
        'A350 Systems Refresher Course Final Exam (outstation)'
    ],
    'course_records_b737asrc'     => [
        'B737 MAX REFRESHER - GENERAL EXAM - 04 JUNE 2026',
        'B737NG Systems Refresher Exam',
        'B737NG Systems Refresher Exam (OUTSTATION)'
    ],
    'course_records_b787asrc'     => [
        'Boeing 787 System Refresher Course Final Exam',
        'Boeing 787 System Refresher Course Final Exam (OUTSTATION)'
    ],
    'course_records_b737ngtomax'  => ['B737NG to MAX'],
    'course_records_b767ascr'     => [
        'B767 Aircraft Systems Refresher Course FINAL EXAM',
        'B767 Aircraft Systems Refresher Course FINAL EXAM (OUTSTATION)'
    ],
    'course_records_b777rot'      => [
        'Final Exam for B-777 Refresher Training',
        'Final Exam for B-777 Refresher Training (outstation)'
    ],
    'course_records_q400asc'      => [
        'Q-400 Refresher Final Exam',
        'Q-400 Refresher Final Exam (OUTSTATION)'
    ]
];

// Reverse lookup maps (case-insensitive, trimmed)
function build_reverse_map(array $map): array
{
    $rev = [];
    foreach ($map as $table => $names) {
        foreach ($names as $n) {
            $rev[strtoupper(trim($n))] = $table;
        }
    }
    return $rev;
}
$course_to_table_old = build_reverse_map($course_table_map_old);
$course_to_table_new = build_reverse_map($course_table_map_new);

// ----------------------
// Pass-mark map for FORMAT B
// Anything not present here defaults to 75.
// ----------------------
$quiz_pass_marks = [
    'AIRCRAFT FUEL HANDLING AND SERVICING FINAL EXAM' => 70,
    'ECARAS REFRESHER EXAM'                           => 75,
    'ECARAS INITIAL EXAM'                             => 75,
    'ESD FINAL EXAM'                                  => 75,
    'EWIS INITIAL EXAM'                               => 75,
    'FUEL TANK SAFETY INITIAL EXAM'                   => 70,
    'ACI FINAL EXAM'                                  => 75,
    'A/C INSPECTION INITIAL EXAM JULY 10'             => 70,
    'AIRCRAFT INSPECTION'                             => 70,
    'AIRCRAFT INSPECTION TRAINING - INITIAL EXAM-1'   => 70,
    'QUIZ FOR AVIATION LEGISLATION'                   => 70,
    'MEL INITIAL EXAM'                                => 75,
    'MOE FINAL EXAM'                                  => 70,
    'MPM INITIAL FINAL EXAM'                          => 75,
    'AVIATION REGULATION INITIAL FINAL EXAM'          => 75,
    'EXAM FOR RSM/QCM FOR LMT INITIAL'                => 70,
    'SMS COURSE FINAL EXAM FOR MANAGERS AND SUPERVISORS' => 75,
    'EXAM FOR SMS FOR OPERATIONAL PERSONNEL'          => 75,
    'EXAMINATION'                                     => 75,
    'RADIOTELEPHONY EXAM'                                            => 75,
    'EXAM - DGR FOR AIRCRAFT MAINTENANCE PERSONNEL-INITIAL' => 80,
    'MAINTENIX EXAM'                                  => 45,
    'BASIC TRAINEES EXAMINATION'                      => 75,
    'EXAM HF-INITIAL (NEW)'                           => 75,
    'EXAM HF'                                         => 70,
];
$DEFAULT_PASS_MARK = 75;

function get_pass_mark(string $quiz_name): float
{
    global $quiz_pass_marks, $DEFAULT_PASS_MARK;
    $key = strtoupper(trim($quiz_name));
    if (isset($quiz_pass_marks[$key])) {
        return (float)$quiz_pass_marks[$key];
    }
    return (float)$DEFAULT_PASS_MARK;
}

// ----------------------
// 1. DOWNLOAD HANDLER
// ----------------------
if (isset($_GET['download'])) {
    $type = $_GET['download'];

    if ($type === 'failed' && !empty($_SESSION['failed_rows_content'])) {
        $content = $_SESSION['failed_rows_content'];
        $filename = "failed_import_rows_" . date('Y-m-d_H-i-s') . ".txt";
        header("Content-Type: text/plain");
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        echo $content;
        unset($_SESSION['failed_rows_content']);
        exit;
    }

    if ($type === 'skipped' && !empty($_SESSION['skipped_rows_content'])) {
        $content = $_SESSION['skipped_rows_content'];
        $filename = "skipped_rows_" . date('Y-m-d_H-i-s') . ".txt";
        header("Content-Type: text/plain");
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        echo $content;
        unset($_SESSION['skipped_rows_content']);
        exit;
    }

    echo "No data available for download.";
    exit;
}

// ----------------------
// 2. Cancel preview
// ----------------------
if (isset($_GET['cancel_preview'])) {
    unset(
        $_SESSION['preview_data'],
        $_SESSION['skipped_rows'],
        $_SESSION['failed_rows'],
        $_SESSION['preview_format']
    );
    header("Location: admin_panel.php?page=import_training");
    exit;
}

// ----------------------
// 3. Flash message
// ----------------------
$message = $_SESSION['import_message'] ?? null;
unset($_SESSION['import_message']);

// ----------------------
// 4. Helper functions
// ----------------------
function parse_date(string $input): ?string
{
    $s = trim($input);
    if ($s === '') return null;
    $lower = strtolower($s);
    if (in_array($lower, ['n/a', 'na', '—', '-', 'none'], true)) return null;

    $s = preg_replace('/(\d+)(?:st|nd|rd|th)/i', '$1', $s);
    $s = preg_replace('/\s+/', ' ', str_replace(',', '', $s));

    $formats = [
        'Y F j',
        'Y F d',
        'F j, Y',
        'j F Y',
        'j-M-Y',
        'd-M-Y',
        'Y-m-d',
        'm/d/Y',
        'd/m/Y',
        'Y/m/d',
        'j M Y',
        'M j, Y'
    ];

    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat('!' . $fmt, $s);
        if ($dt !== false && $dt->format('Y-m-d') > '2000-01-01') {
            return $dt->format('Y-m-d');
        }
    }

    $ts = strtotime($s);
    if ($ts !== false && $ts !== -1) return date('Y-m-d', $ts);

    return null;
}

function calculate_status($completion_date): string
{
    if (!$completion_date) return 'N/A';

    $completed = new DateTime($completion_date);
    $now = new DateTime();
    $two_years_later = clone $completed;
    $two_years_later->modify('+2 years');

    return ($now > $two_years_later) ? 'Expired' : 'Current';
}

function get_table_for_course(string $course, array $course_to_table): ?string
{
    $course_upper = strtoupper(trim($course));
    return $course_to_table[$course_upper] ?? null;
}

function needs_special_date_handling($course): bool
{
    $course_upper = strtoupper(trim($course));
    return in_array($course_upper, ['EASA', 'HFTICNCSO', 'HFTRCECS', 'STFMOP', 'STFMAS']);
}

function update_or_insert_record($pdo, $table, $employee_id, $full_name, $cost_center, $completion_date, $course_name)
{
    try {
        $check_stmt = $pdo->prepare("SELECT ID, CompletedDate, Status FROM `{$table}` WHERE ID = ?");
        $check_stmt->execute([$employee_id]);
        $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

        $current_date = date('Y-m-d H:i:s');
        $calculated_status = calculate_status($completion_date);

        if ($existing) {
            $should_update = false;
            if ($completion_date && (!$existing['CompletedDate'] || $completion_date > $existing['CompletedDate'])) {
                $should_update = true;
            }

            if ($should_update) {
                $sql = "UPDATE `{$table}` SET 
                            NameOfEmployee = ?,
                            CC = ?,
                            CompletedDate = ?,
                            Status = ?,
                            LastUpdated = ?
                        WHERE ID = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $full_name,
                    $cost_center,
                    $completion_date,
                    $calculated_status,
                    $current_date,
                    $employee_id
                ]);
                return ['action' => 'updated', 'old_date' => $existing['CompletedDate'] ?? 'NULL', 'new_date' => $completion_date];
            } else {
                return ['action' => 'skipped', 'reason' => 'Existing record has same or newer date'];
            }
        } else {
            $sql = "INSERT INTO `{$table}` (ID, NameOfEmployee, CC, CompletedDate, Status, LastUpdated) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $employee_id,
                $full_name,
                $cost_center,
                $completion_date,
                $calculated_status,
                $current_date
            ]);
            return ['action' => 'inserted'];
        }
    } catch (Exception $e) {
        throw new Exception("Database error: " . $e->getMessage());
    }
}

// ----------------------
// 5. Process Upload
// ----------------------
$preview_data = [];
$skipped_rows = [];
$failed_rows = [];
$processed = false;
$preview_format = $_SESSION['preview_format'] ?? 'old';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ───────────────────────────────────────────────────────
    // CONFIRM IMPORT
    // ───────────────────────────────────────────────────────
    if (isset($_POST['confirm_import']) && $_POST['confirm_import'] == '1') {

        $preview_data = $_SESSION['preview_data'] ?? [];
        unset(
            $_SESSION['preview_data'],
            $_SESSION['skipped_rows'],
            $_SESSION['preview_format']
        );

        if (empty($preview_data)) {
            $_SESSION['import_message'] = ['type' => 'error', 'text' => 'No preview data found. Please upload the CSV again.'];
            header("Location: admin_panel.php?page=import_training");
            exit;
        }

        $total_success = 0;
        $total_failed = 0;
        $total_skipped = 0;
        $table_stats = [];
        $failed_details = [];
        $skipped_details = [];

        foreach ($preview_data as $table => $records) {
            $table_stats[$table] = ['success' => 0, 'failed' => 0, 'skipped' => 0];

            foreach ($records as $record) {
                try {
                    $result = update_or_insert_record(
                        $pdo,
                        $table,
                        $record['employee_id'],
                        $record['full_name'],
                        $record['cost_center'],
                        $record['taken_date'],
                        $record['course']
                    );

                    if ($result['action'] === 'skipped') {
                        $total_skipped++;
                        $table_stats[$table]['skipped']++;
                        $skipped_details[] = "Row {$record['row']} | Table: {$table} | ID: {$record['employee_id']} | Course: {$record['course']} | {$result['reason']}";
                    } else {
                        $total_success++;
                        $table_stats[$table]['success']++;
                    }
                } catch (Exception $e) {
                    $total_failed++;
                    $table_stats[$table]['failed']++;
                    $failed_details[] = "Row {$record['row']} | Table: {$table} | ID: {$record['employee_id']} | Course: {$record['course']} | " . $e->getMessage();
                }
            }
        }

        if ($failed_details)  $_SESSION['failed_rows_content']  = implode("\n", $failed_details);
        if ($skipped_details) $_SESSION['skipped_rows_content'] = implode("\n", $skipped_details);

        $msg = "<strong>✅ Import Completed!</strong><br><br>";
        $msg .= "📊 <strong>Summary:</strong><br>";
        $msg .= "• Inserted/Updated: <strong class='text-green-700'>{$total_success}</strong> records<br>";
        $msg .= "• Skipped: <strong class='text-orange-600'>{$total_skipped}</strong> records<br>";
        $msg .= "• Failed: <strong class='text-red-600'>{$total_failed}</strong> records<br><br>";
        $msg .= "<strong>📋 Per Table Statistics:</strong><br>";
        foreach ($table_stats as $table => $stats) {
            $msg .= "• <strong>" . htmlspecialchars($table) . "</strong>: +{$stats['success']} updated, ⏭{$stats['skipped']} skipped, ❌{$stats['failed']} failed<br>";
        }

        $links = [];
        if ($failed_details)  $links[] = "<a href=\"import_training.php?download=failed\" class=\"text-red-600 font-bold underline\">📥 Download failed rows ({$total_failed})</a>";
        if ($skipped_details) $links[] = "<a href=\"import_training.php?download=skipped\" class=\"text-orange-600 font-bold underline\">📥 Download skipped rows ({$total_skipped})</a>";
        if ($links) $msg .= "<br><br>" . implode(" &nbsp;|&nbsp; ", $links);

        $_SESSION['import_message'] = ['type' => 'success', 'text' => $msg];
        header("Location: admin_panel.php?page=import_training");
        exit;
    }

    // ───────────────────────────────────────────────────────
    // PREVIEW (upload)
    // ───────────────────────────────────────────────────────
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {

        $file = $_FILES['csv_file']['tmp_name'];

        if (($handle = fopen($file, "r")) !== false) {

            $header = fgetcsv($handle, 2000, ",");
            if ($header === false) {
                $_SESSION['import_message'] = ['type' => 'error', 'text' => 'Could not read the CSV header.'];
                fclose($handle);
                header("Location: admin_panel.php?page=import_training");
                exit;
            }

            // Trim BOM and whitespace
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            $header = array_map('trim', $header);

            // ─────────────────────────────────────────────
            // FORMAT DETECTION
            // ─────────────────────────────────────────────
            $header_lower = array_map('strtolower', $header);
            $is_new_format = in_array('quiz name', $header_lower, true)
                || in_array('date finish', $header_lower, true)
                || in_array('grade', $header_lower, true);

            if ($is_new_format) {
                // ═════════════════════════════════════════════
                // FORMAT B — Quiz-based with grade check
                // Required columns (by name, not position):
                //   ID Number, Name, Date finish, Grade, Cost center, Quiz Name
                // ═════════════════════════════════════════════
                $col = function (string $name) use ($header_lower) {
                    $i = array_search(strtolower($name), $header_lower, true);
                    return ($i === false) ? null : $i;
                };

                $i_id     = $col('ID Number');
                $i_name   = $col('Name');
                $i_date   = $col('Date finish');
                $i_grade  = $col('Grade');
                $i_cc     = $col('Cost center');
                $i_quiz   = $col('Quiz Name');

                if (
                    $i_id === null || $i_name === null || $i_date === null
                    || $i_grade === null || $i_cc === null || $i_quiz === null
                ) {
                    $_SESSION['import_message'] = ['type' => 'error', 'text' => 'New format CSV is missing required columns: ID Number, Name, Date finish, Grade, Cost center, Quiz Name'];
                    fclose($handle);
                    header("Location: admin_panel.php?page=import_training");
                    exit;
                }

                $row_num = 1;
                $organized_data = [];
                $all_skipped = [];

                while (($data = fgetcsv($handle, 2000, ",")) !== false) {
                    $row_num++;

                    if (count($data) < count($header)) {
                        $all_skipped[] = "Row $row_num: Not enough columns";
                        continue;
                    }

                    $data = array_map('trim', $data);

                    $id_number    = $data[$i_id];
                    $name         = $data[$i_name];
                    $date_finish  = $data[$i_date];
                    $grade_raw    = $data[$i_grade];
                    $cost_center  = $data[$i_cc];
                    $quiz_name    = $data[$i_quiz];

                    if ($id_number === '') {
                        $all_skipped[] = "Row $row_num: Missing ID Number";
                        continue;
                    }
                    if ($quiz_name === '') {
                        $all_skipped[] = "Row $row_num: Missing Quiz Name";
                        continue;
                    }

                    // ── Grade check ──
                    if ($grade_raw === '' || !is_numeric($grade_raw)) {
                        $all_skipped[] = "Row $row_num: Missing or invalid Grade ('{$grade_raw}') — skipped";
                        continue;
                    }
                    $grade = (float)$grade_raw;

                    $pass_mark = get_pass_mark($quiz_name);
                    if ($grade < $pass_mark) {
                        $all_skipped[] = "Row $row_num: Grade {$grade} < required {$pass_mark} for '{$quiz_name}' — skipped";
                        continue;
                    }

                    // ── Route by Quiz Name ──
                    $target_table = get_table_for_course($quiz_name, $course_to_table_new);
                    if (!$target_table) {
                        $all_skipped[] = "Row $row_num: Unknown quiz '{$quiz_name}' — no matching table";
                        continue;
                    }

                    $parsed_date = parse_date($date_finish);

                    $record = [
                        'employee_id' => $id_number,
                        'full_name'   => $name,
                        'cost_center' => $cost_center,
                        'course'      => $quiz_name,
                        'taken_date'  => $parsed_date,
                        'taken_raw'   => $date_finish,
                        'grade'       => $grade,
                        'row'         => $row_num
                    ];

                    if (!isset($organized_data[$target_table])) $organized_data[$target_table] = [];
                    $organized_data[$target_table][] = $record;
                }

                $_SESSION['preview_data']    = $organized_data;
                $_SESSION['skipped_rows']    = $all_skipped;
                $_SESSION['preview_format']  = 'new';

                $preview_data    = $organized_data;
                $skipped_rows    = $all_skipped;
                $preview_format  = 'new';
                $processed       = true;
            } else {
                // ═════════════════════════════════════════════
                // FORMAT A — Old format (Course + Certificate + Date)
                // ═════════════════════════════════════════════
                if (count($header) < 6) {
                    $_SESSION['import_message'] = ['type' => 'error', 'text' => 'Invalid old-format CSV. Need at least 6 columns: ID Number, Name, Cost Center, Course, Certificate, Date Issued'];
                    fclose($handle);
                    header("Location: admin_panel.php?page=import_training");
                    exit;
                }

                $row_num = 1;
                $organized_data = [];
                $all_skipped = [];

                while (($data = fgetcsv($handle, 2000, ",")) !== false) {
                    $row_num++;
                    $data = array_map('trim', $data);

                    if (count($data) < 5) {
                        $all_skipped[] = "Row $row_num: Not enough columns (need at least 5)";
                        continue;
                    }

                    $id_number    = $data[0];
                    $name         = $data[1];
                    $cost_center  = $data[2];
                    $course       = $data[3];
                    $certificate  = isset($data[4]) ? $data[4] : '';
                    $date_issued  = isset($data[5]) ? $data[5] : '';

                    if ($id_number === '') {
                        $all_skipped[] = "Row $row_num: Missing ID Number";
                        continue;
                    }
                    if ($course === '') {
                        $all_skipped[] = "Row $row_num: Missing Course name";
                        continue;
                    }

                    $target_table = get_table_for_course($course, $course_to_table_old);
                    if (!$target_table) {
                        $all_skipped[] = "Row $row_num: Unknown course '{$course}' — no matching table found";
                        continue;
                    }

                    $parsed_date = parse_date($date_issued);
                    if (needs_special_date_handling($course) && isset($data[6])) {
                        $parsed_date = parse_date($data[6]);
                    }

                    $record = [
                        'employee_id' => $id_number,
                        'full_name'   => $name,
                        'cost_center' => $cost_center,
                        'course'      => $course,
                        'taken_date'  => $parsed_date,
                        'taken_raw'   => $date_issued,
                        'certificate_ignored' => $certificate,
                        'row'         => $row_num
                    ];

                    if (!isset($organized_data[$target_table])) $organized_data[$target_table] = [];
                    $organized_data[$target_table][] = $record;
                }

                $_SESSION['preview_data']    = $organized_data;
                $_SESSION['skipped_rows']    = $all_skipped;
                $_SESSION['preview_format']  = 'old';

                $preview_data    = $organized_data;
                $skipped_rows    = $all_skipped;
                $preview_format  = 'old';
                $processed       = true;
            }
        }

        fclose($handle);
    }
}

// Load preview if available from session
if (empty($preview_data) && !empty($_SESSION['preview_data'])) {
    $preview_data   = $_SESSION['preview_data'];
    $skipped_rows   = $_SESSION['skipped_rows'] ?? [];
    $preview_format = $_SESSION['preview_format'] ?? 'old';
    $processed      = true;
}
?>

<!DOCTYPE html>
<html lang="en">

<head><meta charset="utf-8">
    


    <title>Import Training Records - Auto Route</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            animation: fadeIn 0.3s ease;
        }

        .modal-container {
            background: white;
            border-radius: 16px;
            width: 500px;
            max-width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideIn 0.3s ease;
            overflow: hidden;
        }

        .modal-header {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            padding: 20px 24px;
            font-size: 20px;
            font-weight: bold;
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            padding: 16px 24px;
            background: #f9fafb;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            border-top: 1px solid #e5e7eb;
        }

        .btn {
            padding: 10px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            font-size: 14px;
        }

        .btn-cancel {
            background: #e5e7eb;
            color: #374151;
        }

        .btn-cancel:hover {
            background: #d1d5db;
        }

        .btn-confirm {
            background: #10b981;
            color: white;
        }

        .btn-confirm:hover {
            background: #059669;
            transform: scale(1.02);
        }

        .warning-icon {
            font-size: 48px;
            text-align: center;
            margin-bottom: 16px;
        }

        .warning-text {
            color: #dc2626;
            font-weight: 600;
            margin-bottom: 16px;
            text-align: center;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 10000;
            flex-direction: column;
        }

        .spinner {
            width: 60px;
            height: 60px;
            border: 6px solid #f3f3f3;
            border-top: 6px solid #10b981;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        .loading-text {
            color: white;
            margin-top: 20px;
            font-size: 18px;
            font-weight: bold;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }
    </style>
</head>

<body class="bg-gray-100">

    <div id="confirmModal" class="modal-overlay" style="display: none;">
        <div class="modal-container">
            <div class="modal-header">⚠️ Confirm Import</div>
            <div class="modal-body">
                <div class="warning-icon">⚠️</div>
                <p style="text-align: center; margin-bottom: 16px; font-size: 16px;">
                    Are you sure you want to import <strong id="recordCount">0</strong> records?
                </p>
                <p style="text-align: center; color: #6b7280; font-size: 14px;">
                    This may take a while depending on the number of records.
                </p>
                <div class="warning-text">This action cannot be undone!</div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-cancel" onclick="closeModal()">Cancel</button>
                <button class="btn btn-confirm" onclick="confirmImport()">Confirm Import</button>
            </div>
        </div>
    </div>

    <div id="loadingOverlay" class="loading-overlay">
        <div class="spinner"></div>
        <div class="loading-text">Importing records, please wait...</div>
    </div>

    <div class="container mx-auto px-4 py-12 max-w-7xl">
        <h2 class="text-3xl font-bold mb-4 text-center text-gray-800">Import Training Records (CSV)</h2>
        <p class="text-center text-gray-600 mb-2">
            <strong>Auto-detects format:</strong> Course-based (old) or Quiz-based (new).
        </p>
        <p class="text-center text-gray-500 text-sm mb-8">
            <strong>Old format:</strong> ID Number, Name, Cost Center, Course, Certificate, Date Issued<br>
            <strong>New format:</strong> ID Number, Name, Date finish, Grade, Cost center, Quiz Name
        </p>

        <?php if ($message): ?>
            <div class="mb-8 p-6 rounded-xl shadow-lg <?= $message['type'] === 'success' ? 'bg-green-50 border-l-8 border-green-600 text-green-800' : 'bg-red-50 border-l-8 border-red-600 text-red-800' ?>">
                <?= $message['text'] ?>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl shadow-2xl p-10">
            <?php if (!empty($preview_data) && $processed): ?>

                <h3 class="text-2xl font-bold mb-2 text-blue-900">
                    📋 Preview Import — <?= count($preview_data) ?> table(s) affected
                </h3>
                <p class="mb-4">
                    <span class="inline-block px-3 py-1 rounded-full text-xs font-bold
                        <?= $preview_format === 'new' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800' ?>">
                        Detected format: <?= $preview_format === 'new' ? 'NEW (Quiz + Grade)' : 'OLD (Course)' ?>
                    </span>
                </p>

                <?php
                $total_records = 0;
                foreach ($preview_data as $records) $total_records += count($records);
                ?>
                <p class="mb-4 text-gray-700">Total records to process: <strong><?= number_format($total_records) ?></strong></p>

                <?php if (!empty($skipped_rows)): ?>
                    <div class="mb-6 p-4 bg-yellow-50 border border-yellow-300 rounded-lg">
                        <p class="text-yellow-800 font-bold">⚠️ Rows skipped during preview: <?= count($skipped_rows) ?></p>
                        <p class="text-sm text-yellow-700 mt-1">See details below. These rows will not be imported.</p>
                        <details class="mt-2">
                            <summary class="cursor-pointer text-sm text-yellow-900 font-semibold">Show first 20 skip reasons</summary>
                            <ul class="mt-2 list-disc pl-6 text-xs text-yellow-900 space-y-0.5">
                                <?php foreach (array_slice($skipped_rows, 0, 20) as $reason): ?>
                                    <li><?= htmlspecialchars($reason) ?></li>
                                <?php endforeach; ?>
                                <?php if (count($skipped_rows) > 20): ?>
                                    <li class="italic">… and <?= count($skipped_rows) - 20 ?> more</li>
                                <?php endif; ?>
                            </ul>
                        </details>
                    </div>
                <?php endif; ?>

                <?php
                $display_count = 0;
                foreach ($preview_data as $table => $records):
                    if ($display_count++ >= 5) {
                        if ($display_count == 6) {
                            echo '<div class="mb-4 p-4 bg-gray-100 rounded-lg text-center">';
                            echo '<p class="text-gray-600">+ ' . (count($preview_data) - 5) . ' more tables not shown in preview</p>';
                            echo '</div>';
                        }
                        continue;
                    }
                ?>
                    <div class="mb-8 border rounded-lg overflow-hidden">
                        <div class="bg-blue-800 text-white px-4 py-3 font-bold text-lg">
                            📁 <?= htmlspecialchars($table) ?> (<?= number_format(count($records)) ?> records)
                        </div>
                        <div class="overflow-x-auto max-h-96 overflow-y-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-200 sticky top-0">
                                    <tr>
                                        <th class="px-4 py-2 text-left">Row</th>
                                        <th class="px-4 py-2 text-left">ID</th>
                                        <th class="px-4 py-2 text-left">Name</th>
                                        <th class="px-4 py-2 text-left">Course / Quiz</th>
                                        <th class="px-4 py-2 text-center">Date</th>
                                        <?php if ($preview_format === 'new'): ?>
                                            <th class="px-4 py-2 text-center">Grade</th>
                                        <?php else: ?>
                                            <th class="px-4 py-2 text-center">Certificate (Ignored)</th>
                                        <?php endif; ?>
                                        <th class="px-4 py-2 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-gray-50">
                                    <?php foreach (array_slice($records, 0, 10) as $r):
                                        $status = calculate_status($r['taken_date']);
                                        $status_class = $status == 'Current' ? 'text-green-600' : 'text-red-600';
                                    ?>
                                        <tr class="border-b hover:bg-blue-50">
                                            <td class="px-4 py-2"><?= $r['row'] ?></td>
                                            <td class="px-4 py-2 font-mono"><?= htmlspecialchars($r['employee_id']) ?></td>
                                            <td class="px-4 py-2"><?= htmlspecialchars(substr($r['full_name'], 0, 30)) ?></td>
                                            <td class="px-4 py-2 font-semibold"><?= htmlspecialchars($r['course']) ?></td>
                                            <td class="px-4 py-2 text-center" title="<?= htmlspecialchars($r['taken_raw'] ?? '') ?>">
                                                <?= $r['taken_date'] ?? '—' ?>
                                            </td>
                                            <?php if ($preview_format === 'new'): ?>
                                                <td class="px-4 py-2 text-center font-mono font-bold text-green-700">
                                                    <?= htmlspecialchars((string)($r['grade'] ?? '')) ?>
                                                </td>
                                            <?php else: ?>
                                                <td class="px-4 py-2 text-center text-gray-400 italic">
                                                    <?= !empty($r['certificate_ignored']) ? '🔸 Ignored' : '—' ?>
                                                </td>
                                            <?php endif; ?>
                                            <td class="px-4 py-2 text-center font-bold <?= $status_class ?>">
                                                <?= $status ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (count($records) > 10): ?>
                                        <tr class="bg-gray-100">
                                            <td colspan="<?= $preview_format === 'new' ? 7 : 7 ?>" class="px-4 py-2 text-center text-gray-500">
                                                ... and <?= number_format(count($records) - 10) ?> more records
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>

                <form id="importForm" method="POST" class="text-center mt-6">
                    <input type="hidden" name="confirm_import" value="1">
                    <button type="button" onclick="showConfirmModal(<?= $total_records ?>)" class="bg-green-700 hover:bg-green-800 text-white font-bold text-xl py-4 px-16 rounded-xl shadow-2xl transition">
                        ✅ Confirm & Import All (<?= number_format($total_records) ?> records)
                    </button>
                    <a href="admin_panel.php?page=import_training&cancel_preview=1" class="ml-8 text-red-600 hover:underline text-lg">❌ Cancel</a>
                </form>

            <?php else: ?>

                <form method="POST" enctype="multipart/form-data" class="space-y-10">
                    <div>
                        <label class="block text-2xl font-bold mb-4 text-gray-800">📂 Upload CSV File</label>
                        <input type="file" name="csv_file" accept=".csv" required
                            class="block w-full text-lg border-2 border-dashed rounded-xl p-12 bg-gray-50 hover:border-blue-600 cursor-pointer">
                        <p class="text-sm text-gray-500 mt-2">
                            The format is auto-detected from the CSV header. Both formats are accepted.
                        </p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-blue-50 p-4 rounded-lg">
                            <p class="font-semibold text-blue-900 mb-2">📘 OLD Format (Course-based)</p>
                            <p class="text-sm text-blue-800">
                                Columns: <strong>ID Number, Name, Cost Center, Course, Certificate, Date Issued</strong>
                            </p>
                            <p class="text-xs text-blue-600 mt-2">No grade check. Uses Course column to route.</p>
                        </div>
                        <div class="bg-purple-50 p-4 rounded-lg">
                            <p class="font-semibold text-purple-900 mb-2">📗 NEW Format (Quiz + Grade)</p>
                            <p class="text-sm text-purple-800">
                                Columns: <strong>ID Number, Name, Date finish, Grade, Cost center, Quiz Name</strong>
                            </p>
                            <p class="text-xs text-purple-600 mt-2">Checks Grade ≥ pass-mark. Uses Quiz Name to route.</p>
                        </div>
                    </div>

                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="font-semibold text-gray-900 mb-2">📋 Quiz Pass Marks (NEW format)</p>
                        <div class="grid grid-cols-2 gap-2 text-xs text-gray-700 max-h-64 overflow-y-auto">
                            <?php foreach ($quiz_pass_marks as $quiz => $mark): ?>
                                <div><strong><?= htmlspecialchars($quiz) ?>:</strong> ≥ <?= $mark ?></div>
                            <?php endforeach; ?>
                            <div class="col-span-2 mt-2 pt-2 border-t text-blue-700">
                                <strong>Default for any other quiz:</strong> ≥ <?= $DEFAULT_PASS_MARK ?>
                            </div>
                        </div>
                    </div>
                    <div class="bg-blue-50 p-4 rounded-lg">
                        <p class="font-semibold text-blue-900 mb-2">📋 Course Routing Rules:OLD</p>
                        <div class="grid grid-cols-2 gap-2 text-sm text-blue-800 max-h-64 overflow-y-auto">
                            <?php foreach ($course_table_map_old as $table => $courses): ?>
                                <div><strong><?= htmlspecialchars(str_replace('course_records_', '', $table)) ?>:</strong> <?= implode(', ', $courses) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="bg-blue-50 p-4 rounded-lg">
                        <p class="font-semibold text-blue-900 mb-2">📋 Course Routing Rules:NEW</p>
                        <div class="grid grid-cols-2 gap-2 text-sm text-blue-800 max-h-64 overflow-y-auto">
                            <?php foreach ($course_table_map_new as $table => $courses): ?>
                                <div><strong><?= htmlspecialchars(str_replace('course_records_', '', $table)) ?>:</strong> <?= implode(', ', $courses) ?></div>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-xs text-blue-600 mt-2">
                            ℹ️ <strong>Note:</strong> For MEL/MELIN courses, the latest completion date is kept.<br>
                            For EASA and HFTICNCSO, date is taken from column 7 if available.<br>
                            Status is automatically calculated (Current = within 2 years, Expired = after 2 years).<br>
                            <span class="text-orange-600">🔸 The "Certificate" column is ignored during import.</span>
                        </p>
                    </div>
                    <div class="text-center">
                        <button type="submit" class="bg-gradient-to-r from-blue-700 to-blue-900 hover:from-blue-800 hover:to-blue-950 text-white font-bold text-2xl py-6 px-20 rounded-2xl shadow-2xl transition transform hover:scale-105">
                            🚀 Preview & Process
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function showConfirmModal(recordCount) {
            document.getElementById('recordCount').innerText = recordCount.toLocaleString();
            document.getElementById('confirmModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('confirmModal').style.display = 'none';
        }

        function confirmImport() {
            closeModal();
            document.getElementById('loadingOverlay').style.display = 'flex';
            document.getElementById('importForm').submit();
        }
        document.getElementById('confirmModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && document.getElementById('confirmModal').style.display === 'flex') {
                e.preventDefault();
                confirmImport();
            }
        });
    </script>

</body>

</html>

<?php ob_end_flush(); ?>