<?php
require_once '../include/db_connect.php';
require_once '../include/auth.php';
requireAdmin();

function clean_header_cell($s) {
    // remove BOM, lowercase, trim
    $s = preg_replace('/\x{FEFF}/u', '', $s);
    return strtolower(trim($s));
}

function truncate_to($s, $len) {
    if ($s === null) return null;
    $s = trim($s);
    if ($len && mb_strlen($s) > $len) return mb_substr($s, 0, $len);
    return $s === '' ? null : $s;
}

$max_lengths = [
    'employee_id' => 11, // int - we'll cast
    'full_name'   => 100,
    'fleet'       => 50,
    'cost_center' => 50,
    'position'    => 100,
    'department'  => 100,
];

$messages = [];
$imported = 0;
$updated = 0;
$skipped = 0;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_csv'])) {
    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Upload failed or no file provided.";
    } else {
        $tmp = $_FILES['csv']['tmp_name'];

        if (!is_uploaded_file($tmp)) {
            $errors[] = "Invalid uploaded file.";
        } else {
            // open and read header
            if (($handle = fopen($tmp, 'r')) === false) {
                $errors[] = "Unable to open CSV file.";
            } else {
                // read header row
                $header = fgetcsv($handle, 0, ",");
                if ($header === false) {
                    $errors[] = "CSV is empty.";
                    fclose($handle);
                } else {
                    // normalize header cells
                    $normalized = array_map('clean_header_cell', $header);
                    // expected columns (we are lenient about order)
                    $expected = ['employee_id','full_name','cost_center','position','department'];
                    $map = []; // csv index => column name
                    foreach ($normalized as $i => $col) {
                        if (in_array($col, $expected, true)) {
                            $map[$i] = $col;
                        } else {
                            // ignore unknown columns
                        }
                    }

                    // ensure required columns exist
                    if (!in_array('employee_id', $map, true) || !in_array('full_name', $map, true)) {
                        $errors[] = "CSV header must include at least: employee_id and full_name.";
                        fclose($handle);
                    } else {
                        // prepare statements
                        $pdo->beginTransaction();
                        try {
                            $selectStmt = $pdo->prepare("SELECT employee_id FROM employees WHERE employee_id = ?");
                            $insertStmt = $pdo->prepare(
                                "INSERT INTO employees (employee_id, full_name, fleet, cost_center, position, department, is_active)
                                 VALUES (?, ?, ?, ?, ?, ?, ?)"
                            );
                            $updateStmt = $pdo->prepare(
                                "UPDATE employees SET full_name = ?, fleet = ?, cost_center = ?, position = ?, department = ?, is_active = ?
                                 WHERE employee_id = ?"
                            );

                            $rowNumber = 1; // header is row 1
                            while (($row = fgetcsv($handle, 0, ",")) !== false) {
                                $rowNumber++;
                                // skip completely empty rows
                                $allEmpty = true;
                                foreach ($row as $cell) {
                                    if (trim($cell) !== '') { $allEmpty = false; break; }
                                }
                                if ($allEmpty) {
                                    $skipped++;
                                    continue;
                                }

                                // map values to columns
                                $vals = [
                                    'employee_id' => null,
                                    'full_name'   => null,
                                    'fleet'       => null,
                                    'cost_center' => null,
                                    'position'    => null,
                                    'department'  => null,
                                ];
                                foreach ($map as $idx => $col) {
                                    $vals[$col] = isset($row[$idx]) ? $row[$idx] : null;
                                }

                                // validation / cleaning
                                // employee_id (required, int)
                                $empRaw = trim((string)($vals['employee_id'] ?? ''));
                                if ($empRaw === '') {
                                    $errors[] = "Row $rowNumber: missing employee_id, skipping.";
                                    $skipped++;
                                    continue;
                                }
                                // Try casting to int
                                if (!is_numeric($empRaw)) {
                                    $errors[] = "Row $rowNumber: employee_id '$empRaw' not numeric, skipping.";
                                    $skipped++;
                                    continue;
                                }
                                $emp_id = intval($empRaw);

                                // full_name
                                $full_name = truncate_to($vals['full_name'] ?? null, $max_lengths['full_name']);
                                if ($full_name === null) {
                                    $errors[] = "Row $rowNumber: empty full_name, skipping.";
                                    $skipped++;
                                    continue;
                                }

                                // optional fields: fleet, cost_center, position, department
                                $fleet = truncate_to($vals['fleet'] ?? null, $max_lengths['fleet']);
                                $cost_center = truncate_to($vals['cost_center'] ?? null, $max_lengths['cost_center']);
                                $position = truncate_to($vals['position'] ?? null, $max_lengths['position']);
                                $department = truncate_to($vals['department'] ?? null, $max_lengths['department']);

                                // default is_active to 1 if missing; keep stored value on update if you prefer, here we set to 1
                                $is_active = 1;

                                // check if exists
                                $selectStmt->execute([$emp_id]);
                                $exists = (bool)$selectStmt->fetchColumn();

                                if ($exists) {
                                    $updateStmt->execute([
                                        $full_name,
                                        $fleet,
                                        $cost_center,
                                        $position,
                                        $department,
                                        $is_active,
                                        $emp_id
                                    ]);
                                    $updated++;
                                } else {
                                    $insertStmt->execute([
                                        $emp_id,
                                        $full_name,
                                        $fleet,
                                        $cost_center,
                                        $position,
                                        $department,
                                        $is_active
                                    ]);
                                    $imported++;
                                }
                            } // end while rows

                            $pdo->commit();
                            fclose($handle);
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            fclose($handle);
                            $errors[] = "Database error: " . $e->getMessage();
                        }
                    } // header valid
                } // header read
            } // fopen ok
        } // is_uploaded_file
    } // file set
} // POST

// UI output
?>
<h2 class="text-3xl font-bold mb-8">Import CSV (Employees)</h2>

<div class="bg-white rounded-xl shadow-lg p-8">
    <p class="mb-6 text-lg">Upload CSV with columns: <code class="bg-gray-200 px-2">employee_id, full_name, cost_center, position, department</code></p>

    <form method="POST" enctype="multipart/form-data">
        <input type="file" name="csv" accept=".csv,text/csv" required class="mb-6">
        <button name="import_csv" class="bg-orange-600 text-white px-8 py-4 rounded-lg font-bold">Import CSV</button>
    </form>

    <?php if ($imported || $updated || $skipped || count($errors) > 0): ?>
        <div class="mt-6 p-4 rounded-lg <?php echo count($errors) ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'; ?>">
            <p><strong>Summary:</strong></p>
            <ul>
                <li>Imported (new) rows: <?php echo $imported; ?></li>
                <li>Updated rows: <?php echo $updated; ?></li>
                <li>Skipped rows (empty/malformed): <?php echo $skipped; ?></li>
                <li>Errors: <?php echo count($errors); ?></li>
            </ul>
            <?php if (count($errors) > 0): ?>
                <details class="mt-2">
                    <summary class="cursor-pointer">Show errors (<?php echo count($errors); ?>)</summary>
                    <ul>
                        <?php foreach ($errors as $err): ?>
                            <li><?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
