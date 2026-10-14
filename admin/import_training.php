<?php
// =========================================================
// import_training.php
// Auto-routing CSV Import for course_records_* tables
// =========================================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session and output buffering
if (session_status() === PHP_SESSION_NONE) session_start();
ob_start();

require_once '../include/db_connect.php';
require_once '../include/auth.php';
requireAdmin();

// ----------------------
// Course to Table Mapping
// ----------------------
$course_table_map = [
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
    'course_records_smsop' => ['SMSOP'],
    'course_records_smsmro' => ['STFMOP', 'SMSOP'],
    'course_records_rcat' => ['COM'],
    'course_records_dgr' => ['DG', 'DGR Handling in a Warehouse', 'DGRFAM'],
    'course_records_mnx' => ['MNTX'],
    'course_records_hf' => ['HFTRCECS', 'HFTICNCSO'],
    'course_records_hfmp' => ['HFAMP', 'HFR'],
    'course_records_a350src' => ['a350r'],
    'course_records_b737asrc' => ['B737NG Refresher'],
    'course_records_b787asrc' => ['B787R'],
    'course_records_b737ngtomax' => ['B737NG to MAX'],
    'course_records_b767ascr' => ['B767R'],
    'course_records_b777rot' => ['B777R'],
    'course_records_q400asc' => ['Q400']
];

// Reverse mapping for quick lookup
$course_to_table = [];
foreach ($course_table_map as $table => $courses) {
    foreach ($courses as $course) {
        $course_to_table[strtoupper(trim($course))] = $table;
    }
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
    unset($_SESSION['preview_data'], $_SESSION['skipped_rows'], $_SESSION['failed_rows']);
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

    // Remove ordinal indicators (st, nd, rd, th) - improved regex
    $s = preg_replace('/(\d+)(?:st|nd|rd|th)/i', '$1', $s);
    $s = preg_replace('/\s+/', ' ', str_replace(',', '', $s));

    // Debug logging
    error_log("Attempting to parse date: '$s'");

    // Add more date formats to handle "2026 April 20" type formats
    $formats = [
        'Y F j',        // 2026 April 20
        'Y F d',        // 2026 April 20
        'F j, Y',       // April 20, 2026
        'j F Y',        // 20 April 2026
        'j-M-Y',        // 20-Apr-2026
        'd-M-Y',        // 20-Apr-2026
        'Y-m-d',        // 2026-04-20
        'm/d/Y',        // 04/20/2026
        'd/m/Y',        // 20/04/2026
        'Y/m/d',        // 2026/04/20
        'j M Y',        // 20 Apr 2026
        'M j, Y'        // Apr 20, 2026
    ];

    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat('!' . $fmt, $s);
        if ($dt !== false && $dt->format('Y-m-d') > '2000-01-01') {
            error_log("Successfully parsed as '{$fmt}': " . $dt->format('Y-m-d'));
            return $dt->format('Y-m-d');
        }
    }

    // Try strtotime as fallback
    $ts = strtotime($s);
    if ($ts !== false && $ts !== -1) {
        $result = date('Y-m-d', $ts);
        error_log("Parsed via strtotime: '$s' -> '$result'");
        return $result;
    }

    error_log("Failed to parse date: '$input'");
    return null;
}

function calculate_status($completion_date): string
{
    if (!$completion_date) return 'N/A';

    $completed = new DateTime($completion_date);
    $now = new DateTime();
    $two_years_later = clone $completed;
    $two_years_later->modify('+2 years');

    if ($now > $two_years_later) {
        return 'Expired';
    } else {
        return 'Current';
    }
}

function get_table_for_course($course): ?string
{
    global $course_to_table;
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
        // First check if record exists for this employee
        $check_sql = "SELECT ID, CompletedDate, Status FROM `{$table}` WHERE ID = ?";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$employee_id]);
        $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

        $current_date = date('Y-m-d H:i:s');
        $calculated_status = calculate_status($completion_date);

        // For MEL/MELIN, always take the latest completion date
        $course_upper = strtoupper(trim($course_name));
        $is_mel_course = in_array($course_upper, ['MEL', 'MELIN']);

        if ($existing) {
            // Record exists, check if we need to update
            $should_update = false;

            if ($is_mel_course) {
                // For MEL/MELIN, update if new date is newer
                if ($completion_date && (!$existing['CompletedDate'] || $completion_date > $existing['CompletedDate'])) {
                    $should_update = true;
                }
            } else {
                // For other courses, update if new date is newer or if no existing date
                if ($completion_date && (!$existing['CompletedDate'] || $completion_date > $existing['CompletedDate'])) {
                    $should_update = true;
                }
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
            // Insert new record
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

// Debug: Log POST data
error_log("POST data: " . print_r($_POST, true));
error_log("FILES data: " . print_r($_FILES, true));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Check if this is a confirm import request
    if (isset($_POST['confirm_import']) && $_POST['confirm_import'] == '1') {
        error_log("Confirm import triggered");

        // CONFIRM IMPORT - Process the actual import
        $preview_data = $_SESSION['preview_data'] ?? [];
        $skipped_rows = $_SESSION['skipped_rows'] ?? [];

        error_log("Preview data count: " . count($preview_data));

        unset($_SESSION['preview_data'], $_SESSION['skipped_rows']);

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
            error_log("Processing table: $table with " . count($records) . " records");
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
                    error_log("Error processing record: " . $e->getMessage());
                }
            }
        }

        if ($failed_details) $_SESSION['failed_rows_content'] = implode("\n", $failed_details);
        if ($skipped_details) $_SESSION['skipped_rows_content'] = implode("\n", $skipped_details);

        // Build success message with table stats
        $msg = "<strong>✅ Import Completed!</strong><br><br>";
        $msg .= "📊 <strong>Summary:</strong><br>";
        $msg .= "• Inserted/Updated: <strong class='text-green-700'>{$total_success}</strong> records<br>";
        $msg .= "• Skipped: <strong class='text-orange-600'>{$total_skipped}</strong> records (existing with newer dates)<br>";
        $msg .= "• Failed: <strong class='text-red-600'>{$total_failed}</strong> records<br><br>";
        $msg .= "<strong>📋 Per Table Statistics:</strong><br>";
        foreach ($table_stats as $table => $stats) {
            $msg .= "• <strong>" . htmlspecialchars($table) . "</strong>: +{$stats['success']} updated, ⏭{$stats['skipped']} skipped, ❌{$stats['failed']} failed<br>";
        }

        $links = [];
        if ($failed_details) $links[] = "<a href=\"import_training.php?download=failed\" class=\"text-red-600 font-bold underline\">📥 Download failed rows ({$total_failed})</a>";
        if ($skipped_details) $links[] = "<a href=\"import_training.php?download=skipped\" class=\"text-orange-600 font-bold underline\">📥 Download skipped rows ({$total_skipped})</a>";
        if ($links) $msg .= "<br><br>" . implode(" &nbsp;|&nbsp; ", $links);

        $_SESSION['import_message'] = ['type' => 'success', 'text' => $msg];
        error_log("Import completed. Success: $total_success, Skipped: $total_skipped, Failed: $total_failed");
        header("Location: admin_panel.php?page=import_training");
        exit;
    }

    // Check if this is a file upload (preview)
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
        error_log("File upload detected");

        // PREVIEW - Parse CSV and organize by table
        $file = $_FILES['csv_file']['tmp_name'];

        if (($handle = fopen($file, "r")) !== false) {
            $header = fgetcsv($handle, 2000, ",");
            if ($header !== false) {
                $header = array_map('trim', $header);

                error_log("CSV Header: " . implode(', ', $header));

                // Check for minimum columns (ID, Name, Cost Center, Course, Certificate, Date Issued)
                // Certificate column will be ignored
                if (count($header) < 6) {
                    $_SESSION['import_message'] = ['type' => 'error', 'text' => 'Invalid CSV format. Need at least 6 columns: ID Number, Name, Cost Center, Course, Certificate, Date Issued'];
                    fclose($handle);
                    header("Location: admin_panel.php?page=import_training");
                    exit;
                }

                $row_num = 1; // Start after header
                $organized_data = [];
                $all_skipped = [];

                while (($data = fgetcsv($handle, 2000, ",")) !== false) {
                    $row_num++;
                    $data = array_map('trim', $data);

                    if (count($data) < 5) {
                        $all_skipped[] = "Row $row_num: Not enough columns (need at least 5)";
                        continue;
                    }

                    // Map columns according to new CSV structure:
                    // Column 0: ID Number
                    // Column 1: Name
                    // Column 2: Cost Center
                    // Column 3: Course
                    // Column 4: Certificate (IGNORED - will be skipped)
                    // Column 5: Date Issued

                    $id_number = $data[0];
                    $name = $data[1];
                    $cost_center = $data[2];
                    $course = $data[3];

                    // Ignore the Certificate column (index 4) - we don't use it
                    $certificate = isset($data[4]) ? $data[4] : '';

                    // Date Issued is now at index 5 (was previously index 4)
                    $date_issued = isset($data[5]) ? $data[5] : '';

                    // Log that certificate column was ignored (for debugging)
                    if (!empty($certificate) && $certificate !== 'Certificate') {
                        error_log("Row $row_num: Certificate column '{$certificate}' ignored as requested");
                    }

                    if (empty($id_number)) {
                        $all_skipped[] = "Row $row_num: Missing ID Number";
                        continue;
                    }

                    if (empty($course)) {
                        $all_skipped[] = "Row $row_num: Missing Course name";
                        continue;
                    }

                    // Determine target table
                    $target_table = get_table_for_course($course);

                    if (!$target_table) {
                        $all_skipped[] = "Row $row_num: Unknown course '{$course}' - no matching table found";
                        continue;
                    }

                    // Parse date (now from column 5/6)
                    $parsed_date = parse_date($date_issued);

                    // For special courses that need date from column 6 (which is now column 7 in new structure)
                    if (needs_special_date_handling($course) && isset($data[6])) {
                        $parsed_date = parse_date($data[6]);
                    }

                    // Prepare record
                    $record = [
                        'employee_id' => $id_number,
                        'full_name' => $name,
                        'cost_center' => $cost_center,
                        'course' => $course,
                        'taken_date' => $parsed_date,
                        'taken_raw' => $date_issued,
                        'certificate_ignored' => $certificate, // Store for logging but not used
                        'row' => $row_num
                    ];

                    // Organize by table
                    if (!isset($organized_data[$target_table])) {
                        $organized_data[$target_table] = [];
                    }
                    $organized_data[$target_table][] = $record;
                }
            }
            fclose($handle);
        }

        $_SESSION['preview_data'] = $organized_data;
        $_SESSION['skipped_rows'] = $all_skipped;
        $preview_data = $organized_data;
        $skipped_rows = $all_skipped;
        $processed = true;

        error_log("Preview data organized. Tables: " . count($organized_data) . ", Total records: " . array_sum(array_map('count', $organized_data)));
    }
}

// Load preview if available from session
if (empty($preview_data) && !empty($_SESSION['preview_data'])) {
    $preview_data = $_SESSION['preview_data'];
    $skipped_rows = $_SESSION['skipped_rows'] ?? [];
    $processed = true;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">


    <title>Import Training Records - Auto Route</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Custom Modal Styles */
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

        /* Loading spinner */
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

    <!-- Custom Confirmation Modal -->
    <div id="confirmModal" class="modal-overlay" style="display: none;">
        <div class="modal-container">
            <div class="modal-header">
                ⚠️ Confirm Import
            </div>
            <div class="modal-body">
                <div class="warning-icon">
                    ⚠️
                </div>
                <p style="text-align: center; margin-bottom: 16px; font-size: 16px;">
                    Are you sure you want to import <strong id="recordCount">0</strong> records?
                </p>
                <p style="text-align: center; color: #6b7280; font-size: 14px;">
                    This may take a while depending on the number of records.
                </p>
                <div class="warning-text">
                    This action cannot be undone!
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-cancel" onclick="closeModal()">Cancel</button>
                <button class="btn btn-confirm" onclick="confirmImport()">Confirm Import</button>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="spinner"></div>
        <div class="loading-text">Importing records, please wait...</div>
    </div>

    <div class="container mx-auto px-4 py-12 max-w-7xl">
        <h2 class="text-3xl font-bold mb-4 text-center text-gray-800">Import Training Records (CSV)</h2>
        <p class="text-center text-gray-600 mb-8">Upload CSV with columns: <strong>ID Number, Name, Cost Center, Course, Certificate, Date Issued</strong><br>
            <span class="text-orange-600 font-semibold">Note: The "Certificate" column will be automatically ignored during import.</span><br>
            Records will be automatically routed to appropriate tables based on the Course column.
        </p>

        <?php if ($message): ?>
            <div class="mb-8 p-6 rounded-xl shadow-lg <?= $message['type'] === 'success' ? 'bg-green-50 border-l-8 border-green-600 text-green-800' : 'bg-red-50 border-l-8 border-red-600 text-red-800' ?>">
                <?= $message['text'] ?>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl shadow-2xl p-10">
            <?php if (!empty($preview_data) && $processed): ?>
                <h3 class="text-2xl font-bold mb-6 text-blue-900">
                    📋 Preview Import - <?= count($preview_data) ?> table(s) affected
                </h3>

                <?php
                $total_records = 0;
                foreach ($preview_data as $records) {
                    $total_records += count($records);
                }
                ?>
                <p class="mb-4 text-gray-700">Total records to process: <strong><?= number_format($total_records) ?></strong></p>
                <p class="mb-4 text-gray-600 text-sm">⚠️ <strong>Certificate column ignored:</strong> The Certificate column has been skipped and will not be imported.</p>

                <?php if (!empty($skipped_rows)): ?>
                    <div class="mb-6 p-4 bg-yellow-50 border border-yellow-300 rounded-lg">
                        <p class="text-yellow-800 font-bold">⚠️ Skipped rows during preview: <?= count($skipped_rows) ?></p>
                        <p class="text-sm text-yellow-700 mt-1">These rows will be skipped during import. Download after import for details.</p>
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
                                        <th class="px-4 py-2 text-left">Course</th>
                                        <th class="px-4 py-2 text-center">Date Issued</th>
                                        <th class="px-4 py-2 text-center">Certificate (Ignored)</th>
                                        <th class="px-4 py-2 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-gray-50">
                                    <?php
                                    $sample_records = array_slice($records, 0, 10);
                                    foreach ($sample_records as $r):
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
                                            <td class="px-4 py-2 text-center text-gray-400 italic">
                                                <?= !empty($r['certificate_ignored']) ? '🔸 Ignored' : '—' ?>
                                            </td>
                                            <td class="px-4 py-2 text-center font-bold <?= $status_class ?>">
                                                <?= $status ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (count($records) > 10): ?>
                                        <tr class="bg-gray-100">
                                            <td colspan="7" class="px-4 py-2 text-center text-gray-500">
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
                            <strong>Required columns (in order):</strong><br>
                            1. ID Number | 2. Name | 3. Cost Center | 4. Course | 5. Certificate | 6. Date Issued<br>
                            <span class="text-orange-600 font-semibold">🔸 Note: The "Certificate" column (column 5) will be automatically ignored/removed during processing.</span><br>
                            <strong>Optional column for special courses (EASA, HFTICNCSO):</strong> Column 7 (additional date field)
                        </p>
                    </div>

                    <div class="bg-blue-50 p-4 rounded-lg">
                        <p class="font-semibold text-blue-900 mb-2">📋 Course Routing Rules:</p>
                        <div class="grid grid-cols-2 gap-2 text-sm text-blue-800 max-h-64 overflow-y-auto">
                            <?php foreach ($course_table_map as $table => $courses): ?>
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
        // Modal functions
        function showConfirmModal(recordCount) {
            document.getElementById('recordCount').innerText = recordCount.toLocaleString();
            document.getElementById('confirmModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('confirmModal').style.display = 'none';
        }

        function confirmImport() {
            // Close the modal
            closeModal();

            // Show loading overlay
            document.getElementById('loadingOverlay').style.display = 'flex';

            // Submit the form
            document.getElementById('importForm').submit();
        }

        // Close modal if clicking outside of it
        document.getElementById('confirmModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Prevent accidental form submission with Enter key
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