<?php require_once '../include/db_connect.php'; require_once '../include/auth.php'; requireAdmin(); ?>

<h2 class="text-3xl font-bold mb-8">Import Excel (CSV)</h2>

<div class="bg-white rounded-xl shadow-lg p-8">
    <p class="mb-6 text-lg">Upload CSV with columns: <code class="bg-gray-200 px-2">employee_id, full_name, cost_center,position,department,taken_date,status</code></p>

    <form method="POST" enctype="multipart/form-data">
        <input type="file" name="csv" accept=".csv" required class="mb-6">
        <button name="import_csv" class="bg-orange-600 text-white px-8 py-4 rounded-lg font-bold">Import CSV</button>
    </form>

    <?php
    if (isset($_POST['import_csv'])) {
        if ($_FILES['csv']['error'] == 0) {
            $file = $_FILES['csv']['tmp_name'];
            $handle = fopen($file, "r");
            $count = 0;
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (count($data) < 4 || $data[0] === 'employee_id') continue; // skip header
                [$emp_id, $name, $code, $date] = $data;
                // Insert employee if not exists
                $pdo->prepare("INSERT IGNORE INTO employees (employee_id, full_name) VALUES (?,?)")->execute([$emp_id, $name]);
                // Get course_id
                $course_id = $pdo->query("SELECT course_id FROM courses WHERE course_code = '$code'")->fetchColumn();
                if ($course_id) {
                    $pdo->prepare("INSERT INTO course_records (employee_id, course_id, taken_date)
                                   VALUES (?,?,?) ON DUPLICATE KEY UPDATE taken_date=VALUES(taken_date)")
                        ->execute([$emp_id, $course_id, $date ?: null]);
                    $count++;
                }
            }
            echo "<div class='bg-green-100 text-green-800 p-4 rounded-lg mt-6'>Imported $count records successfully!</div>";
        }
    }
    ?>
</div>