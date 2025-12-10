<?php
require_once '../include/db_connect.php';
require_once '../include/auth.php';
requireAdmin();

// --- EARLY: handle Export to CSV BEFORE any HTML/output ---
$search = trim($_GET['search'] ?? '');
$where = '';
$params = [];
if ($search !== '') {
    $where = "WHERE employee_id LIKE ? OR full_name LIKE ? OR position LIKE ? OR department LIKE ? OR fleet LIKE ? OR cost_center LIKE ?";
    $like = "%$search%";
    $params = [$like, $like, $like, $like, $like, $like];
}

if (isset($_GET['export'])) {
    // Clean any previous output so headers can be sent reliably
    if (ob_get_level()) {
        ob_end_clean();
    }

    // CSV headers for download
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="employees_' . date('Y-m-d') . '.csv"');
    // UTF-8 BOM for Excel
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');

    // Header row (explicit columns, in the order we output them)
    fputcsv($output, ['Employee ID', 'Full Name', 'Position', 'Department', 'Fleet', 'Cost Center', 'Status', 'Created At', 'Updated At']);

    // Build query (no LIMIT) so export contains all matching rows
    if ($where) {
        $query = "SELECT employee_id, full_name, position, department, fleet, cost_center, is_active, created_at, updated_at FROM employees $where ORDER BY COALESCE(updated_at, created_at) DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
    } else {
        $query = "SELECT employee_id, full_name, position, department, fleet, cost_center, is_active, created_at, updated_at FROM employees ORDER BY COALESCE(updated_at, created_at) DESC";
        $stmt = $pdo->query($query);
    }

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['employee_id'] ?? '',
            $row['full_name'] ?? '',
            $row['position'] ?? '',
            $row['department'] ?? '',
            $row['fleet'] ?? '',
            $row['cost_center'] ?? '',
            (isset($row['is_active']) && $row['is_active']) ? 'Active' : 'Inactive',
            $row['created_at'] ?? '',
            $row['updated_at'] ?? ''
        ]);
    }

    fclose($output);
    exit; // Important: stop further output (no HTML)
}
// --- END export block ---

// ---------- rest of normal page logic starts here ----------

// Flash message
$message = $_SESSION['emp_message'] ?? null;
unset($_SESSION['emp_message']);

// If not set already, set $search / $where / $params for the page (we already computed above)
$search = trim($_GET['search'] ?? $search);
if ($search !== '' && empty($where)) {
    $where = "WHERE employee_id LIKE ? OR full_name LIKE ? OR position LIKE ? OR department LIKE ? OR fleet LIKE ? OR cost_center LIKE ?";
    $like = "%$search%";
    $params = [$like, $like, $like, $like, $like, $like];
}

// Edit mode (fetch a single row if edit requested)
$edit = null;
if (isset($_GET['edit']) && $_GET['edit'] !== '') {
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Handle Add / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_employee'])) {
    $id          = trim($_POST['employee_id'] ?? '');
    $name        = trim($_POST['full_name'] ?? '');
    $fleet       = trim($_POST['fleet'] ?? '');
    $cost_center = trim($_POST['cost_center'] ?? '');
    $position    = trim($_POST['position'] ?? '');
    $department  = trim($_POST['department'] ?? '');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    // Basic validation
    if ($id === '' || $name === '') {
        $_SESSION['emp_message'] = ['type' => 'error', 'text' => 'Employee ID and Full Name are required.'];
        header("Location: admin_panel.php?page=employees" . ($search ? "&search=" . urlencode($search) : ""));
        exit;
    }

    try {
        if ($edit) {
            // Update: set updated_at so recency is tracked
            $stmt = $pdo->prepare("UPDATE employees
                                   SET full_name = ?, fleet = ?, cost_center = ?, position = ?, department = ?, is_active = ?, updated_at = NOW()
                                   WHERE employee_id = ?");
            $stmt->execute([$name, $fleet, $cost_center, $position, $department, $is_active, $id]);
            $_SESSION['emp_message'] = ['type' => 'success', 'text' => 'Employee updated!'];
        } else {
            // Insert: set created_at and updated_at to NOW()
            $stmt = $pdo->prepare("INSERT IGNORE INTO employees
                                   (employee_id, full_name, fleet, cost_center, position, department, is_active, created_at, updated_at)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            $stmt->execute([$id, $name, $fleet, $cost_center, $position, $department, $is_active]);
            $_SESSION['emp_message'] = $stmt->rowCount()
                ? ['type' => 'success', 'text' => 'Employee added!']
                : ['type' => 'error', 'text' => 'Employee ID already exists!'];
        }
    } catch (Exception $e) {
        $_SESSION['emp_message'] = ['type' => 'error', 'text' => 'Error: ' . $e->getMessage()];
    }
    header("Location: admin_panel.php?page=employees" . ($search ? "&search=" . urlencode($search) : ""));
    exit;
}

// Handle Delete
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $del_id = $_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM employees WHERE employee_id = ?");
        $stmt->execute([$del_id]);
        $_SESSION['emp_message'] = ['type' => 'success', 'text' => 'Employee deleted!'];
    } catch (Exception $e) {
        $_SESSION['emp_message'] = ['type' => 'error', 'text' => 'Cannot delete: ' . $e->getMessage()];
    }
    header("Location: admin_panel.php?page=employees" . ($search ? "&search=" . urlencode($search) : ""));
    exit;
}

// The rest of your HTML page follows...
?>

<h2 class="text-3xl font-bold mb-8">Manage Employees</h2>

<!-- Flash Message -->
<?php if ($message): ?>
    <div class="mb-6 p-4 rounded-lg text-white font-bold <?= $message['type'] === 'success' ? 'bg-green-600' : 'bg-red-600' ?>">
        <?= htmlspecialchars($message['text']) ?>
    </div>
<?php endif; ?>

<!-- Actions Bar: Search + Export -->
<div class="bg-white rounded-xl shadow p-6 mb-8 flex flex-col md:flex-row gap-4 justify-between items-center">
    <form method="GET" class="flex-1 max-w-md">
        <input type="hidden" name="page" value="employees">
        <div class="relative">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search employees..."
                   class="w-full pl-12 pr-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
            <svg class="absolute left-4 top-3.5 w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </div>
    </form>

    <div class="flex gap-3">
        <a href="?page=employees&export=1<?= $search ? '&search=' . urlencode($search) : '' ?>"
           class="bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-6 rounded-lg flex items-center gap-2 transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            Export CSV
        </a>
    </div>
</div>

<!-- Add / Edit Form -->
<div class="bg-white rounded-xl shadow-lg p-8 mb-10">
    <h3 class="text-2xl font-semibold mb-6"><?= $edit ? 'Edit Employee' : 'Add New Employee' ?></h3>
    <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Employee ID <span class="text-red-500">*</span></label>
            <input type="text" name="employee_id" value="<?= htmlspecialchars($edit['employee_id'] ?? '') ?>" required placeholder="20428" class="w-full px-5 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Full Name <span class="text-red-500">*</span></label>
            <input type="text" name="full_name" value="<?= htmlspecialchars($edit['full_name'] ?? '') ?>" required class="w-full px-5 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Position</label>
            <input type="text" name="position" value="<?= htmlspecialchars($edit['position'] ?? '') ?>" placeholder="Pilot, Engineer..." class="w-full px-5 py-3 border rounded-lg">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Department</label>
            <input type="text" name="department" value="<?= htmlspecialchars($edit['department'] ?? '') ?>" class="w-full px-5 py-3 border rounded-lg">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Fleet</label>
            <input type="text" name="fleet" value="<?= htmlspecialchars($edit['fleet'] ?? '') ?>" placeholder="B737 / A350" class="w-full px-5 py-3 border rounded-lg">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Cost Center</label>
            <input type="text" name="cost_center" value="<?= htmlspecialchars($edit['cost_center'] ?? '') ?>" placeholder="MXT01" class="w-full px-5 py-3 border rounded-lg">
        </div>

        <div class="md:col-span-2">
            <label class="flex items-center gap-3">
                <input type="checkbox" name="is_active" <?= (!isset($edit) || (isset($edit['is_active']) && $edit['is_active'])) ? 'checked' : '' ?> class="w-5 h-5 text-blue-600 rounded">
                <span class="font-medium">Active Employee</span>
            </label>
        </div>

        <div class="md:col-span-2 flex gap-4">
            <button name="save_employee" class="bg-blue-700 hover:bg-blue-800 text-white font-bold py-3 px-8 rounded-lg">
                <?= $edit ? 'Update' : 'Add' ?> Employee
            </button>
            <?php if ($edit): ?>
                <a href="?page=employees<?= $search ? '&search=' . urlencode($search) : '' ?>" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-3 px-8 rounded-lg">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Employees Table -->
<div class="bg-white rounded-xl shadow-lg overflow-hidden">
    <div class="px-8 py-5 bg-gray-50 border-b">
        <h3 class="text-xl font-bold">All Employees</h3>
        <div class="px-0 py-2 text-sm text-gray-600">Showing the 50 most recently inserted or updated employees. Use search to find others.</div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-100 text-gray-700 font-medium">
                <tr>
                    <th class="px-6 py-4 text-left">ID</th>
                    <th class="px-6 py-4 text-left">Name</th>
                    <th class="px-6 py-4 text-left">Fleet</th>
                    <th class="px-6 py-4 text-left">Cost Center</th>
                    <th class="px-6 py-4 text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php
                // Prepare listing query: if searching, show all results (ordered by recency). Otherwise show recent 25.
                if ($where) {
                    $query = "SELECT * FROM employees $where ORDER BY COALESCE(updated_at, created_at) DESC";
                    $stmt = $pdo->prepare($query);
                    $stmt->execute($params);
                } else {
                    $query = "SELECT * FROM employees ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 25";
                    $stmt = $pdo->query($query);
                }

                if ($stmt->rowCount() === 0): ?>
                    <tr><td colspan="5" class="text-center py-12 text-gray-500">No employees yet. Add one above!</td></tr>
                <?php else: while ($row = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-4 font-mono text-blue-700"><?= htmlspecialchars($row['employee_id']) ?></td>
                        <td class="px-6 py-4 font-medium"><?= htmlspecialchars($row['full_name']) ?></td>
                        <td class="px-6 py-4"><?= htmlspecialchars($row['fleet'] ?: '-') ?></td>
                        <td class="px-6 py-4"><?= htmlspecialchars($row['cost_center'] ?: '-') ?></td>
                        <td class="px-6 py-4 text-center">
                            <a href="?page=employees&edit=<?= urlencode($row['employee_id']) ?>" class="text-blue-600 hover:underline font-medium">Edit</a>
                            &nbsp;|&nbsp;
                            <a href="?page=employees&delete=<?= urlencode($row['employee_id']) . ($search ? '&search=' . urlencode($search) : '') ?>" onclick="return confirm('Delete employee <?= htmlspecialchars(addslashes($row['full_name'])) ?>?');" class="text-red-600 hover:underline font-medium">Delete</a>
                        </td>
                    </tr>
                <?php endwhile; endif; ?>
            </tbody>
        </table>
    </div>
</div>
