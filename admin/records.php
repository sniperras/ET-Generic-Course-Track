<?php
require_once '../include/db_connect.php';
require_once '../include/auth.php';
requireAdmin();

// Increase PHP limits to handle large forms
ini_set('max_input_vars', 5000);
ini_set('max_execution_time', 300);
ini_set('memory_limit', '256M');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();

// Define valid table names
$valid_tables = [
    'course_records_afhs',
    'course_records_ecaras',
    'course_records_esd',
    'course_records_etops',
    'course_records_ewis',
    'course_records_fts',
    'course_records_hf',
    'course_records_ip',
    'course_records_legislation',
    'course_records_mcm',
    'course_records_mel',
    'course_records_moe',
    'course_records_mpm',
    'course_records_regulation',
    'course_records_rsmqsm',
    'course_records_ettpm',
    'course_records_smsmgr',
    'course_records_smsop'
];

// ============================ SAVE RECORDS ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_records'])) {
    $emp_id = trim($_POST['emp_id'] ?? '');
    if (empty($emp_id)) {
        $_SESSION['record_message'] = ['type' => 'error', 'text' => 'Employee ID missing.'];
        header("Location: admin_panel.php?page=records");
        exit;
    }

    $stmt = $pdo->prepare("SELECT full_name, position, department, cost_center FROM employees WHERE employee_id = ? AND is_active = 1");
    $stmt->execute([$emp_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$employee) {
        $_SESSION['record_message'] = ['type' => 'error', 'text' => 'Employee not found.'];
        header("Location: admin_panel.php?page=records");
        exit;
    }

    $updated = 0;
    $errors = [];
    $processed_courses = [];

    // Debug: Check what we received
    error_log("=== START SAVING RECORDS ===");
    error_log("Employee ID: $emp_id");

    // Get all active courses from database to verify IDs
    $all_courses = $pdo->query("SELECT course_id, table_name FROM courses WHERE is_active = 1 AND table_name IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    $valid_course_ids = array_column($all_courses, 'course_id');

    // Process each course from the form
    if (!empty($_POST['taken_date']) && is_array($_POST['taken_date'])) {
        foreach ($_POST['taken_date'] as $course_id => $completed_date) {
            $course_id = (int)$course_id;

            // Skip if course ID is invalid
            if (!in_array($course_id, $valid_course_ids)) {
                error_log("Skipping invalid course ID: $course_id");
                continue;
            }

            $processed_courses[] = $course_id;

            $completed_date = trim($completed_date ?? '');
            $status = $_POST['status'][$course_id] ?? 'NA';

            // Convert empty date to null
            if ($completed_date === '' || $completed_date === '0000-00-00' || $completed_date === 'mm/dd/yyyy') {
                $completed_date = null;
            }

            // Skip if no data to save
            if ($completed_date === null && $status === 'NA') {
                error_log("Skipping course $course_id - no data");
                continue;
            }

            // Get the table name from courses table
            $stmt = $pdo->prepare("SELECT table_name FROM courses WHERE course_id = ? AND is_active = 1");
            $stmt->execute([$course_id]);
            $table_name = $stmt->fetchColumn();

            if (!$table_name) {
                $errors[] = "Course ID $course_id has no table name defined.";
                error_log("No table name for course ID: $course_id");
                continue;
            }

            // Fix typo if present
            if ($table_name === 'course_records_legistlation') {
                $table_name = 'course_records_legislation';
            }

            // Validate table name
            if (!in_array($table_name, $valid_tables)) {
                $errors[] = "Invalid table name: $table_name for course ID $course_id";
                error_log("Invalid table name: $table_name");
                continue;
            }

            try {
                // Check if table exists, create if not
                $table_exists = $pdo->query("SHOW TABLES LIKE '$table_name'")->fetch();
                if (!$table_exists) {
                    // SIMPLIFIED TABLE STRUCTURE - Use ID as primary key
                    $create_sql = "
                        CREATE TABLE `$table_name` (
                            `ID` VARCHAR(50) NOT NULL PRIMARY KEY,
                            `NameOfEmployee` VARCHAR(255) NOT NULL,
                            `CC` VARCHAR(50),
                            `Position` VARCHAR(255),
                            `Dept` VARCHAR(255),
                            `CompletedDate` DATE NULL,
                            `Status` VARCHAR(50) DEFAULT 'NA',
                            `LastUpdated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ";
                    $pdo->exec($create_sql);
                    error_log("Created table: $table_name");
                } else {
                    // Check if table has the right structure
                    // If it has record_id as primary key, we need to handle it differently
                    $check_columns = $pdo->query("SHOW COLUMNS FROM `$table_name`")->fetchAll(PDO::FETCH_COLUMN);

                    if (in_array('record_id', $check_columns)) {
                        // Table has old structure - we need to use a different approach
                        // Check if there's already a record for this employee
                        $check_sql = "SELECT COUNT(*) FROM `$table_name` WHERE ID = ?";
                        $check_stmt = $pdo->prepare($check_sql);
                        $check_stmt->execute([$emp_id]);
                        $exists = $check_stmt->fetchColumn();

                        if ($exists > 0) {
                            // Update existing record
                            $sql = "UPDATE `$table_name` SET 
                                    NameOfEmployee = ?,
                                    CC = ?,
                                    Position = ?,
                                    Dept = ?,
                                    CompletedDate = ?,
                                    Status = ?,
                                    LastUpdated = CURRENT_TIMESTAMP
                                    WHERE ID = ?";
                            $stmt2 = $pdo->prepare($sql);
                            $result = $stmt2->execute([
                                $employee['full_name'],
                                $employee['cost_center'] ?? '',
                                $employee['position'] ?? '',
                                $employee['department'] ?? '',
                                $completed_date,
                                $status,
                                $emp_id
                            ]);
                        } else {
                            // Insert new record
                            $sql = "INSERT INTO `$table_name` 
                                    (ID, NameOfEmployee, CC, Position, Dept, CompletedDate, Status)
                                    VALUES (?, ?, ?, ?, ?, ?, ?)";
                            $stmt2 = $pdo->prepare($sql);
                            $result = $stmt2->execute([
                                $emp_id,
                                $employee['full_name'],
                                $employee['cost_center'] ?? '',
                                $employee['position'] ?? '',
                                $employee['department'] ?? '',
                                $completed_date,
                                $status
                            ]);
                        }

                        if ($stmt2->rowCount() > 0) {
                            $updated++;
                            error_log("Successfully saved course $course_id to $table_name (using update/insert)");
                        }
                        continue; // Skip to next course
                    }
                }

                // Standard INSERT/UPDATE for tables with ID as primary key
                $sql = "INSERT INTO `$table_name` 
                            (ID, NameOfEmployee, CC, Position, Dept, CompletedDate, Status)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            NameOfEmployee = VALUES(NameOfEmployee),
                            CC = VALUES(CC), 
                            Position = VALUES(Position), 
                            Dept = VALUES(Dept),
                            CompletedDate = VALUES(CompletedDate), 
                            Status = VALUES(Status),
                            LastUpdated = CURRENT_TIMESTAMP";

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

                if ($stmt2->rowCount() > 0) {
                    $updated++;
                    error_log("Successfully saved course $course_id to $table_name - Date: $completed_date, Status: $status");
                } else {
                    error_log("No changes for course $course_id (data unchanged)");
                }
            } catch (Exception $e) {
                $error_msg = "Error saving to $table_name for course $course_id: " . $e->getMessage();
                error_log($error_msg);
                $errors[] = $error_msg;

                // Try alternative approach if first fails
                try {
                    error_log("Trying alternative save method...");
                    // Simple INSERT IGNORE approach
                    $sql = "INSERT IGNORE INTO `$table_name` 
                                (ID, NameOfEmployee, CC, Position, Dept, CompletedDate, Status)
                            VALUES (?, ?, ?, ?, ?, ?, ?)";

                    $stmt3 = $pdo->prepare($sql);
                    $stmt3->execute([
                        $emp_id,
                        $employee['full_name'],
                        $employee['cost_center'] ?? '',
                        $employee['position'] ?? '',
                        $employee['department'] ?? '',
                        $completed_date,
                        $status
                    ]);

                    if ($stmt3->rowCount() > 0) {
                        $updated++;
                        error_log("Alternative save successful for course $course_id");
                    } else {
                        // Try UPDATE if INSERT didn't work
                        $sql = "UPDATE `$table_name` SET 
                                NameOfEmployee = ?,
                                CC = ?,
                                Position = ?,
                                Dept = ?,
                                CompletedDate = ?,
                                Status = ?,
                                LastUpdated = CURRENT_TIMESTAMP
                                WHERE ID = ?";

                        $stmt4 = $pdo->prepare($sql);
                        $stmt4->execute([
                            $employee['full_name'],
                            $employee['cost_center'] ?? '',
                            $employee['position'] ?? '',
                            $employee['department'] ?? '',
                            $completed_date,
                            $status,
                            $emp_id
                        ]);

                        if ($stmt4->rowCount() > 0) {
                            $updated++;
                            error_log("Update successful for course $course_id");
                        }
                    }
                } catch (Exception $e2) {
                    error_log("Alternative save also failed: " . $e2->getMessage());
                }
            }
        }
    }

    error_log("=== SAVE COMPLETE ===");
    error_log("Processed courses: " . implode(', ', $processed_courses));
    error_log("Total updated: $updated, Total errors: " . count($errors));

    $message_text = "";
    $message_type = "info";

    if ($updated > 0) {
        $message_text = "$updated record(s) successfully updated!";
        $message_type = "success";

        if (!empty($errors)) {
            $message_text .= " (with " . count($errors) . " error" . (count($errors) > 1 ? 's' : '') . ")";
        }
    } else if (!empty($errors)) {
        $message_text = "Save failed: " . implode("; ", array_slice($errors, 0, 3));
        if (count($errors) > 3) {
            $message_text .= " and " . (count($errors) - 3) . " more";
        }
        $message_type = "error";
    } else {
        $message_text = "No changes were made.";
        $message_type = "info";
    }

    $_SESSION['record_message'] = [
        'type' => $message_type,
        'text' => $message_text
    ];

    // Redirect with employee ID
    $redirect_url = "admin_panel.php?page=records";
    if ($emp_id) {
        $redirect_url .= "&emp_id=" . urlencode($emp_id);
    }

    header("Location: $redirect_url");
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

// Get all active courses
$courses = $pdo->query("
    SELECT course_id, course_code, course_name, validity_months, table_name
    FROM courses 
    WHERE is_active = 1 AND table_name IS NOT NULL
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
    <style>
        /* Custom styles for better UX */
        input[type="date"] {
            font-family: monospace;
            font-size: 16px;
        }

        select {
            cursor: pointer;
        }

        .status-current {
            color: #10B981;
            font-weight: 600;
        }

        .status-expired {
            color: #EF4444;
            font-weight: bold;
        }

        .status-pending {
            color: #F59E0B;
            font-weight: 600;
        }

        .status-tobe {
            color: #8B5CF6;
            font-weight: 600;
        }

        .status-na {
            color: #6B7280;
            font-style: italic;
        }
    </style>
</head>

<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-10 max-w-7xl">

        <h2 class="text-4xl font-bold mb-8 text-center text-gray-800">Update Training Records</h2>

        <?php if ($message): ?>
            <div class="mb-8 p-6 rounded-xl text-white font-bold text-lg text-center shadow-lg
            <?= $message['type'] === 'success' ? 'bg-green-600' : ($message['type'] === 'error' ? 'bg-red-600' : 'bg-blue-600') ?>">
                <?= htmlspecialchars($message['text']) ?>
            </div>
        <?php endif; ?>

        <!-- Export button -->
        <div class="mb-6 flex justify-center">
            <?php if ($employee): ?>
                <a href="export_pdf.php?emp_id=<?= urlencode($emp_id) ?>"
                    target="_blank"
                    class="bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-8 rounded-xl shadow-lg transition hover:scale-105">
                    Export to PDF
                </a>
            <?php else: ?>
                <button disabled
                    class="bg-red-600 text-white font-bold py-3 px-8 rounded-xl shadow-lg opacity-50 cursor-not-allowed">
                    Export to PDF
                </button>
            <?php endif; ?>
        </div>

        <!-- Employee Search Form -->
        <form method="GET" class="mb-12 bg-white rounded-2xl shadow-xl p-8">
            <input type="hidden" name="page" value="records">
            <div class="flex flex-col md:flex-row gap-6 items-end">
                <div class="flex-1">
                    <label class="block text-lg font-semibold text-gray-700 mb-3">Enter Employee ID</label>
                    <input type="text" name="emp_id" placeholder="e.g. 14799" value="<?= htmlspecialchars($emp_id) ?>" required
                        class="w-full px-6 py-4 text-xl font-mono border-2 border-gray-300 rounded-xl focus:border-blue-600 focus:outline-none focus:ring-4 focus:ring-blue-100">
                </div>
                <button type="submit" class="bg-blue-700 hover:bg-blue-800 text-white font-bold text-xl px-12 py-4 rounded-xl shadow-lg transition transform hover:scale-105">
                    Load Employee
                </button>
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
                    <p class="text-lg mt-2 opacity-90">
                        Position: <?= htmlspecialchars($employee['position'] ?? 'N/A') ?> |
                        Department: <?= htmlspecialchars($employee['department'] ?? 'N/A') ?> |
                        Cost Center: <?= htmlspecialchars($employee['cost_center'] ?? 'N/A') ?>
                    </p>
                </div>

                <div class="p-8">
                    <form method="POST" id="recordsForm">
                        <input type="hidden" name="emp_id" value="<?= htmlspecialchars($emp_id) ?>">
                        <input type="hidden" name="update_records" value="1">

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
                                        $table_name  = ($raw_table === 'course_records_legistlation')
                                            ? 'course_records_legislation'
                                            : $raw_table;

                                        $input_date     = '';
                                        $current_status = 'NA';
                                        $status_class   = 'status-na';

                                        // Try to read existing record
                                        try {
                                            if (preg_match('/^course_records_[a-zA-Z0-9_]+$/', $table_name)) {
                                                $sql = "SELECT CompletedDate, Status FROM `$table_name` WHERE ID = ? LIMIT 1";
                                                $stmt = $pdo->prepare($sql);
                                                $stmt->execute([$emp_id]);
                                                $rec = $stmt->fetch(PDO::FETCH_ASSOC);

                                                if ($rec) {
                                                    if (!empty($rec['CompletedDate']) && $rec['CompletedDate'] !== '0000-00-00') {
                                                        $ts = strtotime($rec['CompletedDate']);
                                                        $input_date = $ts ? date('Y-m-d', $ts) : '';
                                                    }
                                                    $current_status = $rec['Status'] ?? 'NA';

                                                    // Set status class
                                                    if ($current_status === 'Current') $status_class = 'status-current';
                                                    elseif ($current_status === 'Expired') $status_class = 'status-expired';
                                                    elseif ($current_status === 'Pending') $status_class = 'status-pending';
                                                    elseif ($current_status === 'To be expired') $status_class = 'status-tobe';
                                                    else $status_class = 'status-na';
                                                }
                                            }
                                        } catch (Throwable $e) {
                                            // Table might not exist yet - that's OK
                                        }
                                    ?>
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="px-6 py-5 font-medium <?= $status_class ?>">
                                                <div class="font-bold"><?= htmlspecialchars($course['course_code']) ?></div>
                                                <div class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($course['course_name']) ?></div>
                                                <?php if ($course['validity_months'] > 0): ?>
                                                    <div class="text-xs text-gray-500 mt-1">Valid for <?= $course['validity_months'] ?> months</div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-5 text-center">
                                                <input type="date"
                                                    name="taken_date[<?= $course_id ?>]"
                                                    value="<?= $input_date ?>"
                                                    class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-48"
                                                    placeholder="YYYY-MM-DD">
                                            </td>
                                            <td class="px-6 py-5 text-center">
                                                <select name="status[<?= $course_id ?>]"
                                                    class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-medium w-48
                                                        <?= $current_status === 'Current' ? 'bg-green-50 text-green-700' : ($current_status === 'Expired' ? 'bg-red-50 text-red-700' : ($current_status === 'Pending' ? 'bg-yellow-50 text-yellow-700' : ($current_status === 'To be expired' ? 'bg-purple-50 text-purple-700' : ''))) ?>">
                                                    <?php foreach ($status_options as $opt): ?>
                                                        <option value="<?= $opt ?>"
                                                            <?= $current_status === $opt ? 'selected' : '' ?>
                                                            <?= $opt === 'NA' ? 'class="text-gray-500"' : '' ?>>
                                                            <?= $opt ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-12 text-center">
                            <button type="submit"
                                name="update_records"
                                class="bg-green-700 hover:bg-green-800 text-white font-bold text-xl px-20 py-5 rounded-xl shadow-xl transition transform hover:scale-105">
                                Save All Training Records
                            </button>
                            <div class="mt-4 text-gray-600">
                                <p>Total courses: <?= count($courses) ?></p>
                                <p class="text-sm">Note: Only courses with dates or non-NA status will be saved</p>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // JavaScript to enhance user experience
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('recordsForm');

            // Form submission loading indicator
            form.addEventListener('submit', function(e) {
                // Show loading indicator
                const submitBtn = form.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<span class="flex items-center justify-center"><svg class="animate-spin h-5 w-5 mr-3 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Saving... Please wait...</span>';
                submitBtn.disabled = true;
                submitBtn.className = 'bg-gray-500 text-white font-bold text-xl px-20 py-5 rounded-xl shadow-xl opacity-75 cursor-not-allowed';

                // Re-enable after 10 seconds just in case
                setTimeout(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                    submitBtn.className = 'bg-green-700 hover:bg-green-800 text-white font-bold text-xl px-20 py-5 rounded-xl shadow-xl transition transform hover:scale-105';
                }, 10000);

                return true;
            });
        });
    </script>
</body>

</html>

<?php ob_end_flush(); ?>