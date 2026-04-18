<?php
require_once '../include/db_connect.php';
require_once '../include/auth.php';
requireAdmin();

// Flash message system
$message = $_SESSION['course_message'] ?? null;
unset($_SESSION['course_message']);

$editCourse = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM courses WHERE course_id = ?");
    $stmt->execute([$_GET['edit']]);
    $editCourse = $stmt->fetch();
}

// Function to create course records table dynamically with the required columns
function createCourseRecordsTable($pdo, $course_code)
{
    $table_name = 'course_records_' . strtolower($course_code);

    $sql = "CREATE TABLE IF NOT EXISTS `$table_name` (
        ID INT AUTO_INCREMENT PRIMARY KEY,
        NameOfEmployee VARCHAR(100) NOT NULL,
        CC VARCHAR(50) NOT NULL,
        Position VARCHAR(100) NOT NULL,
        Dept VARCHAR(50) NOT NULL,
        CompletedDate DATE NOT NULL,
        Status ENUM('Current', 'Expired', 'Pending', 'Revoked') DEFAULT 'Current',
        LastUpdated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_cc (CC),
        INDEX idx_status (Status),
        INDEX idx_completed_date (CompletedDate)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    try {
        $pdo->exec($sql);
        return true;
    } catch (PDOException $e) {
        error_log("Table creation error: " . $e->getMessage());
        return false;
    }
}

// Function to update existing table structure if needed (for backward compatibility)
function updateExistingTable($pdo, $table_name)
{
    try {
        // Check if table exists first
        $check = $pdo->prepare("SHOW TABLES LIKE ?");
        $check->execute([$table_name]);
        if ($check->rowCount() == 0) {
            return false;
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM `$table_name`");
        $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $columnsToAdd = [];
        $requiredColumns = ['ID', 'NameOfEmployee', 'CC', 'Position', 'Dept', 'CompletedDate', 'Status', 'LastUpdated'];

        foreach ($requiredColumns as $col) {
            if (!in_array($col, $existingColumns)) {
                $columnsToAdd[] = $col;
            }
        }

        // Add missing columns
        foreach ($columnsToAdd as $col) {
            switch ($col) {
                case 'NameOfEmployee':
                    $pdo->exec("ALTER TABLE `$table_name` ADD COLUMN NameOfEmployee VARCHAR(100) NOT NULL DEFAULT ''");
                    break;
                case 'CC':
                    $pdo->exec("ALTER TABLE `$table_name` ADD COLUMN CC VARCHAR(50) NOT NULL DEFAULT ''");
                    $pdo->exec("ALTER TABLE `$table_name` ADD INDEX idx_cc (CC)");
                    break;
                case 'Position':
                    $pdo->exec("ALTER TABLE `$table_name` ADD COLUMN Position VARCHAR(100) NOT NULL DEFAULT ''");
                    break;
                case 'Dept':
                    $pdo->exec("ALTER TABLE `$table_name` ADD COLUMN Dept VARCHAR(50) NOT NULL DEFAULT ''");
                    break;
                case 'CompletedDate':
                    $pdo->exec("ALTER TABLE `$table_name` ADD COLUMN CompletedDate DATE");
                    $pdo->exec("ALTER TABLE `$table_name` ADD INDEX idx_completed_date (CompletedDate)");
                    break;
                case 'Status':
                    $pdo->exec("ALTER TABLE `$table_name` ADD COLUMN Status ENUM('Current', 'Expired', 'Pending', 'Revoked') DEFAULT 'Current'");
                    $pdo->exec("ALTER TABLE `$table_name` ADD INDEX idx_status (Status)");
                    break;
                case 'LastUpdated':
                    $pdo->exec("ALTER TABLE `$table_name` ADD COLUMN LastUpdated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
                    break;
            }
        }

        return true;
    } catch (PDOException $e) {
        error_log("Table update error: " . $e->getMessage());
        return false;
    }
}

// Handle save (add or update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_course'])) {
    $course_id     = $_POST['course_id'] ?? null;
    $course_code   = trim($_POST['course_code']);
    $course_name   = trim($_POST['course_name']);
    $validity      = (int)$_POST['validity_months'];
    $table_name    = 'course_records_' . strtolower($course_code);

    try {
        if ($course_id) {
            // Get old course code before update
            $stmt = $pdo->prepare("SELECT course_code, table_name FROM courses WHERE course_id = ?");
            $stmt->execute([$course_id]);
            $old_course = $stmt->fetch();
            $old_table = $old_course['table_name'];
            $new_table = 'course_records_' . strtolower($course_code);

            // Update course info
            $stmt = $pdo->prepare("UPDATE courses SET course_code = ?, course_name = ?, validity_months = ?, table_name = ? WHERE course_id = ?");
            $stmt->execute([$course_code, $course_name, $validity, $table_name, $course_id]);

            // Rename table if course code changed
            if ($old_table !== $new_table && $old_table) {
                $rename_sql = "RENAME TABLE `$old_table` TO `$new_table`";
                $pdo->exec($rename_sql);
            }

            // Update existing table structure if needed
            updateExistingTable($pdo, $new_table);

            $_SESSION['course_message'] = ['type' => 'success', 'text' => 'Course updated successfully!'];
        } else {
            // Check if course code already exists
            $check = $pdo->prepare("SELECT course_id FROM courses WHERE course_code = ?");
            $check->execute([$course_code]);
            if ($check->fetch()) {
                throw new Exception("Course code already exists!");
            }

            // Insert course
            $stmt = $pdo->prepare("INSERT INTO courses (course_code, course_name, validity_months, table_name, is_active) VALUES (?, ?, ?, ?, 1)");
            $stmt->execute([$course_code, $course_name, $validity, $table_name]);

            // Create the dynamic table for this course with the required columns
            if (createCourseRecordsTable($pdo, $course_code)) {
                $_SESSION['course_message'] = ['type' => 'success', 'text' => 'Course added successfully with records table!'];
            } else {
                $_SESSION['course_message'] = ['type' => 'warning', 'text' => 'Course added but there was an issue creating the records table.'];
            }
        }
    } catch (Exception $e) {
        $_SESSION['course_message'] = ['type' => 'error', 'text' => 'Error: ' . $e->getMessage()];
    }

    header("Location: admin_panel.php?page=courses" . ($course_id ? "&edit=$course_id" : ""));
    exit;
}

// Handle delete course
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $course_id = $_GET['delete'];

    try {
        $stmt = $pdo->prepare("SELECT course_code, table_name FROM courses WHERE course_id = ?");
        $stmt->execute([$course_id]);
        $course = $stmt->fetch();

        if ($course) {
            $table_name = $course['table_name'];
            $drop_sql = "DROP TABLE IF EXISTS `$table_name`";
            $pdo->exec($drop_sql);

            $delete = $pdo->prepare("DELETE FROM courses WHERE course_id = ?");
            $delete->execute([$course_id]);

            $_SESSION['course_message'] = ['type' => 'success', 'text' => 'Course and its records table deleted successfully!'];
        }
    } catch (Exception $e) {
        $_SESSION['course_message'] = ['type' => 'error', 'text' => 'Error deleting course: ' . $e->getMessage()];
    }

    header("Location: admin_panel.php?page=courses");
    exit;
}

// Function to get record count - DIRECT COUNT that actually works
function getRecordCount($pdo, $table_name)
{
    try {
        // First check if the table exists using SHOW TABLES
        $checkTable = $pdo->prepare("SHOW TABLES LIKE ?");
        $checkTable->execute([$table_name]);

        if ($checkTable->rowCount() === 0) {
            return 0;
        }

        // Table exists, count the records
        $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM `$table_name`");
        $countStmt->execute();
        $result = $countStmt->fetch(PDO::FETCH_ASSOC);

        return (int)$result['total'];
    } catch (Exception $e) {
        // Log error for debugging
        error_log("Error counting records in $table_name: " . $e->getMessage());
        return 0;
    }
}
?>

<h2 class="text-3xl font-bold mb-8">Manage Courses</h2>

<!-- Success / Error Message -->
<?php if ($message): ?>
    <div class="mb-8 p-5 rounded-xl text-white font-bold text-lg shadow-lg <?= $message['type'] === 'success' ? 'bg-green-600' : ($message['type'] === 'warning' ? 'bg-yellow-600' : 'bg-red-600') ?>">
        <?= htmlspecialchars($message['text']) ?>
    </div>
<?php endif; ?>

<!-- Add / Edit Form -->
<div class="bg-white rounded-xl shadow-lg p-8 mb-10">
    <h3 class="text-2xl font-semibold mb-6"><?= $editCourse ? 'Edit Course' : 'Add New Course' ?></h3>

    <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <input type="hidden" name="course_id" value="<?= $editCourse['course_id'] ?? '' ?>">

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Course Code</label>
            <input type="text" name="course_code" value="<?= htmlspecialchars($editCourse['course_code'] ?? '') ?>"
                placeholder="e.g. AFHS" required class="w-full px-5 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
            <p class="text-xs text-gray-500 mt-1">Table will be: course_records_<?= strtolower($editCourse['course_code'] ?? 'code') ?></p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Course Name</label>
            <input type="text" name="course_name" value="<?= htmlspecialchars($editCourse['course_name'] ?? '') ?>"
                placeholder="e.g. Aircraft Human Factors" required class="w-full px-5 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Validity (months)</label>
            <input type="number" name="validity_months" value="<?= $editCourse['validity_months'] ?? '24' ?>"
                min="1" max="999999" required class="w-full px-5 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
        </div>

        <div class="md:col-span-3 flex gap-4">
            <button name="save_course" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 px-8 rounded-lg transition shadow">
                <?= $editCourse ? 'Update Course' : 'Add Course' ?>
            </button>
            <?php if ($editCourse): ?>
                <a href="admin_panel.php?page=courses" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-3 px-8 rounded-lg transition">
                    Cancel Edit
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Courses List -->
<div class="bg-white rounded-xl shadow-lg overflow-hidden">
    <div class="px-8 py-5 bg-gray-50 border-b">
        <h3 class="text-xl font-bold">All Courses</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-100 text-gray-700 font-medium">
                <tr>
                    <th class="px-6 py-4 text-left">Code</th>
                    <th class="px-6 py-4 text-left">Course Name</th>
                    <th class="px-6 py-4 text-center">Validity</th>
                    <th class="px-6 py-4 text-left">Records Table</th>
                    <th class="px-6 py-4 text-center">Records Count</th>
                    <th class="px-6 py-4 text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php
                $stmt = $pdo->query("SELECT * FROM courses ORDER BY course_code");
                $hasCourses = false;
                while ($c = $stmt->fetch()):
                    $hasCourses = true;
                    $recordCount = getRecordCount($pdo, $c['table_name']);
                ?>
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-4 font-bold text-blue-700"><?= htmlspecialchars($c['course_code']) ?></td>
                        <td class="px-6 py-4"><?= htmlspecialchars($c['course_name']) ?></td>
                        <td class="px-6 py-4 text-center"><?= $c['validity_months'] ?> months</td>
                        <td class="px-6 py-4">
                            <code class="text-xs bg-gray-100 px-2 py-1 rounded"><?= htmlspecialchars($c['table_name']) ?></code>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $recordCount > 0 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' ?>">
                                <?= number_format($recordCount) ?> record<?= $recordCount != 1 ? 's' : '' ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-center space-x-3">
                            <a href="?page=courses&edit=<?= $c['course_id'] ?>" class="text-blue-600 hover:text-blue-800 font-medium">Edit</a>
                            <a href="?page=courses&delete=<?= $c['course_id'] ?>"
                                onclick="return confirm('Delete this course and ALL its records? This cannot be undone!')"
                                class="text-red-600 hover:text-red-800 font-medium">Delete</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                <?php if (!$hasCourses): ?>
                    <tr>
                        <td colspan="6" class="text-center py-12 text-gray-500">No courses yet. Add one above!</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>