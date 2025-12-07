<?php
// =========================================================
// import_training.php
// Robust CSV Import for course_records_* tables
// Handles preview, confirm import, and download of failed/skipped rows
// =========================================================

// Start session and output buffering
if (session_status() === PHP_SESSION_NONE) session_start();
ob_start();

require_once '../include/db_connect.php';
require_once '../include/auth.php';
requireAdmin();

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
    unset($_SESSION['preview_data'], $_SESSION['preview_table'], $_SESSION['skipped_rows']);
    header("Location: admin_panel.php?page=import_training");
    exit;
}

// ----------------------
// 3. Flash message
// ----------------------
$message = $_SESSION['import_message'] ?? null;
unset($_SESSION['import_message']);

// ----------------------
// 4. Get all course_records_* tables
// ----------------------
$stmt = $pdo->prepare("
    SELECT table_name 
    FROM information_schema.tables 
    WHERE table_schema = DATABASE() 
      AND table_name LIKE 'course_records\_%'
    ORDER BY table_name
");
$stmt->execute();
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

// ----------------------
// 5. Helper functions
// ----------------------
function normalize_header($h) { return strtolower(trim($h)); }

$expected = ['employee_id', 'full_name', 'cost_center', 'position', 'department', 'taken_date', 'status'];
$target_columns = [
    'employee_id'  => 'ID',
    'full_name'    => 'NameOfEmployee',
    'cost_center'  => 'CC',
    'position'     => 'Position',
    'department'   => 'Dept',
    'taken_date'   => 'CompletedDate',
    'status'       => 'Status'
];

function parse_date(string $input): ?string {
    $s = trim($input);
    if ($s === '') return null;
    $lower = strtolower($s);
    if (in_array($lower, ['n/a','na','—','-','none'], true)) return null;

    $s = preg_replace('/(\d+)(st|nd|rd|th)/i', '$1', $s);
    $s = preg_replace('/\s+/', ' ', str_replace(',', '', $s));

    $formats = ['j-M-y','d-M-y','j-M-Y','d-M-Y','j F Y','d F Y','M j Y','Y F j','Y-m-d','Y M j'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat('!'.$fmt, $s);
        if ($dt !== false) return $dt->format('Y-m-d');
    }
    $ts = strtotime($s);
    return ($ts !== false && $ts !== -1) ? date('Y-m-d', $ts) : null;
}

// ----------------------
// 6. STEP 1: Upload → Build Preview
// ----------------------
$preview_data = [];
$skipped_rows = [];
$preview_table = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' 
    && isset($_FILES['csv_file'], $_POST['table_name']) 
    && !isset($_POST['confirm_import'])) {

    $table = $_POST['table_name'];
    if (!in_array($table, $tables, true)) {
        $_SESSION['import_message'] = ['type' => 'error', 'text' => 'Invalid table selected.'];
        header("Location: admin_panel.php?page=import_training");
        exit;
    }

    $file = $_FILES['csv_file']['tmp_name'];
    if (($handle = fopen($file, "r")) !== false) {
        $row_num = 0;
        $header = fgetcsv($handle, 2000, ",");
        if ($header !== false) {
            $row_num++;
            $header = array_map('trim', $header);
            $norm_header = array_map('normalize_header', $header);

            if (array_slice($norm_header, 0, 7) !== $expected) {
                $_SESSION['import_message'] = ['type' => 'error', 'text' => 'Wrong CSV header format.'];
                fclose($handle);
                header("Location: admin_panel.php?page=import_training");
                exit;
            }

            while (($data = fgetcsv($handle, 2000, ",")) !== false) {
                $row_num++;
                $data = array_map('trim', $data);
                if (count($data) < 7) {
                    $skipped_rows[] = "Row $row_num: Not enough columns";
                    continue;
                }

                [$emp_id, $full_name, $cost_center, $position, $department, $taken_str, $status] = $data;

                if (empty($emp_id)) {
                    $skipped_rows[] = "Row $row_num: Missing Employee ID";
                    continue;
                }

                $parsed_date = parse_date($taken_str);
                $st = strtolower(trim($status));

                $status_normalized = in_array($st, ['na','n/a','', '—','-'], true) ? 'N/A' : ucwords($st);
                $inactive = in_array($st, ['expired','overdue'], true) ? 1 : 0;

                $preview_data[] = [
                    'employee_id' => $emp_id,
                    'full_name'   => $full_name,
                    'cost_center' => $cost_center,
                    'position'    => $position,
                    'department'  => $department,
                    'taken_date'  => $parsed_date,
                    'taken_raw'   => $taken_str,
                    'status'      => $status_normalized,
                    'inactive'    => $inactive,
                    'row'         => $row_num
                ];
            }
        }
        fclose($handle);
    }

    $_SESSION['preview_data'] = $preview_data;
    $_SESSION['preview_table'] = $table;
    $_SESSION['skipped_rows'] = $skipped_rows;
}

// ----------------------
// 7. STEP 2: Confirm Import
// ----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
    $preview_data = $_SESSION['preview_data'] ?? [];
    $table = $_SESSION['preview_table'] ?? '';
    $skipped_rows = $_SESSION['skipped_rows'] ?? [];

    unset($_SESSION['preview_data'], $_SESSION['preview_table'], $_SESSION['skipped_rows']);

    if (empty($table) || !in_array($table, $tables, true)) {
        $_SESSION['import_message'] = ['type' => 'error', 'text' => 'Invalid session data.'];
        header("Location: admin_panel.php?page=import_training");
        exit;
    }

    $cols = array_values($target_columns);
    $colList = "`" . implode("`,`", $cols) . "`";
    $placeholders = rtrim(str_repeat('?,', count($cols)), ',');
    $updates = [];
    foreach ($cols as $c) if ($c !== 'ID') $updates[] = "`$c` = VALUES(`$c`)";
    $updateSql = implode(", ", $updates);

    $sql = "INSERT INTO `{$table}` ({$colList}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE {$updateSql}";
    $stmt = $pdo->prepare($sql);

    $success = $failed = 0;
    $failed_rows = [];

    foreach ($preview_data as $row) {
        try {
            $stmt->execute([
                $row['employee_id'],
                $row['full_name'],
                $row['cost_center'],
                $row['position'],
                $row['department'],
                $row['taken_date'],
                $row['status']
            ]);
            $success++;
        } catch (Exception $e) {
            error_log("Import failed row {$row['row']}: " . $e->getMessage());
            $failed++;
            $failed_rows[] = "Row {$row['row']} | ID: {$row['employee_id']} | " . $e->getMessage();
        }
    }

    if ($failed) $_SESSION['failed_rows_content'] = implode("\n", $failed_rows);
    if ($skipped_rows) $_SESSION['skipped_rows_content'] = implode("\n", $skipped_rows);

    $msg = "Import completed: <strong>{$success}</strong> saved into <strong>{$table}</strong>.";
    $links = [];
    if ($failed) $links[] = "<a href=\"import_training.php?download=failed\" class=\"text-red-600 font-bold underline\">{$failed} failed → download</a>";
    if ($skipped_rows) $links[] = "<a href=\"import_training.php?download=skipped\" class=\"text-orange-600 font-bold underline\">" . count($skipped_rows) . " skipped → download</a>";
    if ($links) $msg .= "<br><br><strong>Details:</strong> " . implode(" · ", $links);

    $_SESSION['import_message'] = ['type' => 'success', 'text' => $msg];
    header("Location: admin_panel.php?page=import_training");
    exit;
}

// ----------------------
// Load preview if available
// ----------------------
if (empty($preview_data) && !empty($_SESSION['preview_data'])) {
    $preview_data = $_SESSION['preview_data'];
    $preview_table = $_SESSION['preview_table'] ?? '';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Import Training Records</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">

<div class="container mx-auto px-4 py-12 max-w-6xl">
<h2 class="text-3xl font-bold mb-8 text-center text-gray-800">Import Course Records (CSV)</h2>

<?php if ($message): ?>
<div class="mb-8 p-6 rounded-xl text-white font-bold text-lg shadow-lg <?= $message['type']==='success' ? 'bg-green-600' : 'bg-red-600' ?>">
    <?= $message['text'] ?>
</div>
<?php endif; ?>

<div class="bg-white rounded-2xl shadow-2xl p-10">
<?php if (!empty($preview_data)): ?>
    <h3 class="text-2xl font-bold mb-6 text-blue-900">
        Preview: <?= count($preview_data) ?> rows → target table: 
        <code class="bg-gray-200 px-2 py-1 rounded"><?= htmlspecialchars($preview_table) ?></code>
    </h3>

    <div class="overflow-x-auto mb-8 max-h-96 overflow-y-auto border rounded-lg">
        <table class="w-full text-sm">
            <thead class="bg-blue-800 text-white sticky top-0">
                <tr>
                    <th class="px-4 py-3 text-left">Row</th>
                    <th class="px-4 py-3 text-left">Emp ID</th>
                    <th class="px-4 py-3 text-left">Name</th>
                    <th class="px-4 py-3 text-left">Position</th>
                    <th class="px-4 py-3 text-left">Dept</th>
                    <th class="px-4 py-3 text-center">Date</th>
                    <th class="px-4 py-3 text-center">Status</th>
                </tr>
            </thead>
            <tbody class="bg-gray-50">
                <?php foreach ($preview_data as $r): ?>
                <tr class="border-b hover:bg-blue-50">
                    <td class="px-4 py-3"><?= $r['row'] ?></td>
                    <td class="px-4 py-3 font-mono"><?= htmlspecialchars($r['employee_id']) ?></td>
                    <td class="px-4 py-3"><?= htmlspecialchars($r['full_name']) ?></td>
                    <td class="px-4 py-3"><?= htmlspecialchars($r['position']) ?></td>
                    <td class="px-4 py-3"><?= htmlspecialchars($r['department']) ?></td>
                    <td class="px-4 py-3 text-center" title="<?= htmlspecialchars($r['taken_raw'] ?? '') ?>">
                        <?= $r['taken_date'] ?? '—' ?>
                    </td>
                    <td class="px-4 py-3 text-center font-bold <?= $r['inactive'] ? 'text-red-600' : 'text-green-600' ?>">
                        <?= htmlspecialchars($r['status']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <form method="POST" class="text-center">
        <button name="confirm_import" class="bg-green-700 hover:bg-green-800 text-white font-bold text-xl py-4 px-16 rounded-xl shadow-2xl">
            Confirm & Import All (<?= count($preview_data) ?> records)
        </button>
        <a href="admin_panel.php?page=import_training&cancel_preview=1" class="ml-8 text-red-600 hover:underline text-lg">Cancel</a>
    </form>
<?php else: ?>
    <form method="POST" enctype="multipart/form-data" class="space-y-10">
        <div>
            <label class="block text-2xl font-bold mb-4 text-gray-800">1. Select target table</label>
            <select name="table_name" required class="w-full px-6 py-4 text-lg border-2 rounded-xl focus:border-blue-600">
                <option value="">-- Choose table --</option>
                <?php foreach ($tables as $t): ?>
                    <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-2xl font-bold mb-4 text-gray-800">2. Upload CSV File</label>
            <input type="file" name="csv_file" accept=".csv" required 
                   class="block w-full text-lg border-2 border-dashed rounded-xl p-12 bg-gray-50 hover:border-blue-600">
            <p class="text-sm text-gray-500 mt-2">Header must be: <code>employee_id, full_name, cost_center, position, department, taken_date, status</code></p>
        </div>

        <div class="text-center">
            <button type="submit" class="bg-gradient-to-r from-blue-700 to-blue-900 text-white font-bold text-2xl py-6 px-20 rounded-2xl shadow-2xl hover:scale-105 transition">
                Preview Import
            </button>
        </div>
    </form>
<?php endif; ?>
</div>
</div>

</body>
</html>

<?php ob_end_flush(); ?>
