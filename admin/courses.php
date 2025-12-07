<?php 
require_once '../include/db_connect.php'; 
require_once '../include/auth.php'; 
requireAdmin(); 

// Flash message system
//session_start();
$message = $_SESSION['course_message'] ?? null;
unset($_SESSION['course_message']);

$editCourse = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM courses WHERE course_id = ?");
    $stmt->execute([$_GET['edit']]);
    $editCourse = $stmt->fetch();
}

// Handle save (add or update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_course'])) {
    $course_id     = $_POST['course_id'] ?? null;
    $course_code   = trim($_POST['course_code']);
    $course_name   = trim($_POST['course_name']);
    $validity      = (int)$_POST['validity_months'];

    try {
        if ($course_id) {
            $stmt = $pdo->prepare("UPDATE courses SET course_code = ?, course_name = ?, validity_months = ? WHERE course_id = ?");
            $stmt->execute([$course_code, $course_name, $validity, $course_id]);
            $_SESSION['course_message'] = ['type' => 'success', 'text' => 'Course updated successfully!'];
        } else {
            $stmt = $pdo->prepare("INSERT INTO courses (course_code, course_name, validity_months) VALUES (?, ?, ?)");
            $stmt->execute([$course_code, $course_name, $validity]);
            $_SESSION['course_message'] = ['type' => 'success', 'text' => 'Course added successfully!'];
        }
    } catch (Exception $e) {
        $_SESSION['course_message'] = ['type' => 'error', 'text' => 'Error: Could not save course. Maybe duplicate code?'];
    }

    // Stay on the same page so user sees the message
    header("Location: admin_panel.php?page=courses" . ($course_id ? "&edit=$course_id" : ""));
    exit;
}
?>

<h2 class="text-3xl font-bold mb-8">Manage Courses</h2>

<!-- Success / Error Message (appears right here!) -->
<?php if ($message): ?>
    <div class="mb-8 p-5 rounded-xl text-white font-bold text-lg shadow-lg <?= $message['type'] === 'success' ? 'bg-green-600' : 'bg-red-600' ?>">
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
                   placeholder="e.g. HF" required class="w-full px-5 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Course Name</label>
            <input type="text" name="course_name" value="<?= htmlspecialchars($editCourse['course_name'] ?? '') ?>" 
                   placeholder="Human Factors" required class="w-full px-5 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Validity (months)</label>
            <input type="number" name="validity_months" value="<?= $editCourse['validity_months'] ?? '24' ?>" 
                   min="1" max="120" required class="w-full px-5 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
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
                    <th class="px-6 py-4 text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php
                $stmt = $pdo->query("SELECT * FROM courses ORDER BY course_code");
                while ($c = $stmt->fetch()):
                ?>
                <tr class="hover:bg-gray-50 transition">
                    <td class="px-6 py-4 font-bold text-blue-700"><?= htmlspecialchars($c['course_code']) ?></td>
                    <td class="px-6 py-4"><?= htmlspecialchars($c['course_name']) ?></td>
                    <td class="px-6 py-4 text-center"><?= $c['validity_months'] ?> months</td>
                    <td class="px-6 py-4 text-center">
                        <a href="?page=courses&edit=<?= $c['course_id'] ?>" class="text-blue-600 hover:underline font-medium">Edit</a>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if ($stmt->rowCount() === 0): ?>
                <tr><td colspan="4" class="text-center py-12 text-gray-500">No courses yet. Add one above!</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>