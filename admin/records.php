<?php
require_once '../include/db_connect.php';
require_once '../include/auth.php';
requireAdmin();

ob_start();

// ============================ SAVE RECORDS ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_records'])) {
    $emp_id = trim($_POST['emp_id'] ?? '');
    if (empty($emp_id)) { $_SESSION['record_message'] = ['type'=>'error','text'=>'Employee ID missing.']; header("Location: admin_panel.php?page=records"); exit; }

    $stmt = $pdo->prepare("SELECT full_name, position, department, cost_center FROM employees WHERE employee_id = ? AND is_active = 1");
    $stmt->execute([$emp_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$employee) { $_SESSION['record_message'] = ['type'=>'error','text'=>'Employee not found.']; header("Location: admin_panel.php?page=records"); exit; }

    $updated = 0;
    foreach ($_POST['taken_date'] as $course_id => $completed_date) {
        $course_id = (int)$course_id;
        $completed_date = $completed_date === '' ? null : trim($completed_date);
        $status = $_POST['status'][$course_id] ?? 'NA';

        if ($completed_date === null && $status === 'NA') continue;

        // Get the table name from courses table
        $stmt = $pdo->prepare("SELECT table_name FROM courses WHERE course_id = ? AND is_active = 1");
        $stmt->execute([$course_id]);
        $raw_table = $stmt->fetchColumn();

        // AUTO-FIX the only known typo
        if ($raw_table === 'course_records_legistlation') {
            $table_name = 'course_records_legislation';   // <-- correct table you have now
        } else {
            $table_name = $raw_table;
        }

        if (!$table_name || !preg_match('/^course_records_[a-zA-Z0-9_]+$/', $table_name)) continue;

        try {
            $sql = "INSERT INTO `$table_name` 
                        (ID, NameOfEmployee, CC, Position, Dept, CompletedDate, Status)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        NameOfEmployee = VALUES(NameOfEmployee),
                        CC = VALUES(CC), Position = VALUES(Position), Dept = VALUES(Dept),
                        CompletedDate = VALUES(CompletedDate), Status = VALUES(Status)";

            $stmt2 = $pdo->prepare($sql);
            $stmt2->execute([
                $emp_id,
                $employee['full_name'],
                $employee['cost_center'] ?? '',
                $employee['position'] ?? '',
                $employee['department'] ?? '',
                $completed_date,
                $status
            ]);
            if ($stmt2->rowCount() > 0) $updated++;
        } catch (Exception $e) {
            error_log("Save error [$table_name]: " . $e->getMessage());
        }
    }

    $_SESSION['record_message'] = [
        'type' => $updated > 0 ? 'success' : 'info',
        'text' => $updated > 0 ? "$updated record(s) updated!" : "No changes."
    ];
    header("Location: admin_panel.php?page=records&emp_id=" . urlencode($emp_id));
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
    FROM courses WHERE is_active = 1 AND table_name IS NOT NULL
    ORDER BY course_code ASC
")->fetchAll(PDO::FETCH_ASSOC);

$status_options = ['Current', 'Pending', 'Expired', 'To be expired', 'NA'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Training Records</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">
<div class="container mx-auto px-4 py-10 max-w-7xl">

    <h2 class="text-4xl font-bold mb-8 text-center text-gray-800">Update Training Records</h2>

    <?php if ($message): ?>
        <div class="mb-8 p-6 rounded-xl text-white font-bold text-lg text-center shadow-lg
            <?= $message['type']==='success' ? 'bg-green-600' : 'bg-red-600' ?>">
            <?= htmlspecialchars($message['text']) ?>
        </div>
    <?php endif; ?>

    <!-- Export button (moved ABOVE Enter Employee ID). Disabled until employee is loaded. -->
    <div class="mb-6 flex justify-center">
        <?php if ($employee): ?>
            <a href="export_pdf.php?emp_id=<?= urlencode($emp_id) ?>"
               target="_blank"
               class="bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-8 rounded-xl shadow-lg transition">
               Export to PDF
            </a>
        <?php else: ?>
            <a href="#"
               onclick="return false;"
               aria-disabled="true"
               title="Load an employee first"
               class="bg-red-600 text-white font-bold py-3 px-8 rounded-xl shadow-lg transition opacity-50 pointer-events-none cursor-not-allowed">
               Export to PDF
            </a>
        <?php endif; ?>
    </div>

    <form method="GET" class="mb-12 bg-white rounded-2xl shadow-xl p-8">
        <input type="hidden" name="page" value="records">
        <div class="flex flex-col md:flex-row gap-6 items-end">
            <div class="flex-1">
                <label class="block text-lg font-semibold text-gray-700 mb-3">Enter Employee ID</label>
                <input type="text" name="emp_id" placeholder="e.g. 19962" value="<?= htmlspecialchars($emp_id) ?>" required
                       class="w-full px-6 py-4 text-xl font-mono border-2 border-gray-300 rounded-xl focus:border-blue-600 focus:outline-none focus:ring-4 focus:ring-blue-100">
            </div>
            <button class="bg-blue-700 hover:bg-blue-800 text-white font-bold text-xl px-12 py-4 rounded-xl shadow-lg transition transform hover:scale-105">
                Load Employee
            </button>
        </div>
       <div class="flex justify-between items-center mb-8">
   
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
            </div>

            <div class="p-8">
                <form method="POST">
                    <input type="hidden" name="emp_id" value="<?= htmlspecialchars($emp_id) ?>">

                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-gray-100 text-gray-700 font-bold text-lg">
                                <tr>
                                    <th class="px-6 py-5">Course</th>
                                    <th class="px-6 py-5 text-center">Completed Date</th>
                                    <th class="px-6 py-5 text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($courses as $course):
                                    $course_id   = (int)$course['course_id'];
                                    $raw_table   = $course['table_name'];

                                    // Fix the only known typo
                                    if ($raw_table === 'course_records_legistlation') {
                                        $table_name = 'course_records_legislation';
                                    } else {
                                        $table_name = $raw_table;
                                    }

                                    $input_date     = '';
                                    $current_status = 'NA';

                                    // Try to read the record – if table doesn't exist or no row → keep defaults
                                    try {
                                        if (preg_match('/^course_records_[a-zA-Z0-9_]+$/', $table_name)) {
                                            $sql = "SELECT CompletedDate, Status FROM `$table_name` WHERE ID = ? LIMIT 1";
                                            $stmt = $pdo->prepare($sql);
                                            $stmt->execute([$emp_id]);
                                            $rec = $stmt->fetch(PDO::FETCH_ASSOC);

                                            if ($rec) {
                                                if ($rec['CompletedDate'] && $rec['CompletedDate'] !== '0000-00-00') {
                                                    $ts = strtotime($rec['CompletedDate']);
                                                    $input_date = $ts ? date('Y-m-d', $ts) : '';
                                                }
                                                $current_status = $rec['Status'] ?? 'NA';
                                            }
                                        }
                                    } catch (Throwable $e) {
                                        // Table missing or any error → just show empty (row still appears)
                                    }

                                    $status_class = $current_status === 'Current' ? 'text-green-600 font-medium' :
                                                   ($current_status === 'Expired' ? 'text-red-600 font-bold' : 'text-gray-500 italic');
                                ?>
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="px-6 py-5 font-medium <?= $status_class ?>">
                                            <?= htmlspecialchars($course['course_code']) ?> - <?= htmlspecialchars($course['course_name']) ?>
                                        </td>
                                        <td class="px-6 py-5 text-center">
                                            <input type="date" name="taken_date[<?= $course_id ?>]" value="<?= $input_date ?>"
                                                   class="px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                                        </td>
                                        <td class="px-6 py-5 text-center">
                                            <select name="status[<?= $course_id ?>]" class="px-6 py-2 border rounded-lg font-medium">
                                                <?php foreach ($status_options as $opt): ?>
                                                    <option value="<?= $opt ?>" <?= $current_status === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-12 text-center">
                        <button name="update_records" class="bg-green-700 hover:bg-green-800 text-white font-bold text-xl px-20 py-5 rounded-xl shadow-xl transition transform hover:scale-105">
                            Save All Training Records
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>

<?php ob_end_flush(); ?>
