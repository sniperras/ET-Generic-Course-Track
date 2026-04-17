<?php 
require_once '../include/db_connect.php'; 
require_once '../include/auth.php'; 
requireAdminRole(); // Instead of just requireAdmin()

// Get current user's role from session
$current_user_role = $_SESSION['user_role'] ?? '';

// Only allow admin users to access this page
if ($current_user_role !== 'admin') {
    // Redirect to dashboard with error message
    $_SESSION['error_message'] = 'Access denied. Only administrators can manage users.';
    header('Location: admin_panel.php?page=dashboard');
    exit;
}

// Handle Add User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $username = $_POST['username'];
    $pass = password_hash($_POST['password'], PASSWORD_BCRYPT);
    $name = $_POST['full_name'];
    $role = $_POST['role'];

    try {
        $stmt = $pdo->prepare("INSERT INTO users (username, full_name, password, role, is_active) VALUES (?,?,?,?, 1)");
        $stmt->execute([$username, $name, $pass, $role]);
        $_SESSION['success_message'] = "User '{$username}' added successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error adding user: " . $e->getMessage();
    }
    
    // Redirect to avoid form resubmission
    header('Location: admin_panel.php?page=users');
    exit;
}

// Handle Change Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $user_id = $_POST['user_id'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate passwords match
    if ($new_password !== $confirm_password) {
        $_SESSION['error_message'] = "Passwords do not match!";
    } elseif (strlen($new_password) < 6) {
        $_SESSION['error_message'] = "Password must be at least 6 characters long!";
    } else {
        try {
            $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $stmt->execute([$hashed_password, $user_id]);
            $_SESSION['success_message'] = "Password changed successfully!";
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error changing password: " . $e->getMessage();
        }
    }
    
    header('Location: admin_panel.php?page=users');
    exit;
}

// Handle Delete User
if (isset($_GET['delete_user'])) {
    $user_id = $_GET['delete_user'];
    
    // Prevent admin from deleting themselves
    if ($user_id == $_SESSION['admin_user_id']) {
        $_SESSION['error_message'] = "You cannot delete your own account!";
    } else {
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $_SESSION['success_message'] = "User deleted successfully!";
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error deleting user: " . $e->getMessage();
        }
    }
    
    header('Location: admin_panel.php?page=users');
    exit;
}

// Handle Toggle User Status (Activate/Deactivate)
if (isset($_GET['toggle_status'])) {
    $user_id = $_GET['toggle_status'];
    
    // Prevent admin from deactivating themselves
    if ($user_id == $_SESSION['admin_user_id']) {
        $_SESSION['error_message'] = "You cannot deactivate your own account!";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $_SESSION['success_message'] = "User status updated successfully!";
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error updating user status: " . $e->getMessage();
        }
    }
    
    header('Location: admin_panel.php?page=users');
    exit;
}

// Fetch all users
$users = $pdo->query("SELECT user_id, username, full_name, role, is_active FROM users ORDER BY user_id")->fetchAll();
?>

<h2 class="text-3xl font-bold mb-8">Manage Users (Admins, HR, & Viewers) - Admin Only</h2>

<?php if (isset($_SESSION['success_message'])): ?>
    <div class="bg-green-100 border border-green-400 text-green-800 px-4 py-3 rounded-lg mb-6">
        <?= htmlspecialchars($_SESSION['success_message']) ?>
        <?php unset($_SESSION['success_message']); ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="bg-red-100 border border-red-400 text-red-800 px-4 py-3 rounded-lg mb-6">
        <?= htmlspecialchars($_SESSION['error_message']) ?>
        <?php unset($_SESSION['error_message']); ?>
    </div>
<?php endif; ?>

<!-- Add User Form -->
<div class="bg-white rounded-xl shadow-lg p-8 mb-8">
    <h3 class="text-xl font-bold mb-4">Add New User</h3>
    <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <input type="text" name="username" placeholder="Username" required class="px-5 py-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-600">
        <input type="text" name="full_name" placeholder="Full Name" required class="px-5 py-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-600">
        <input type="password" name="password" placeholder="Password (min 6 chars)" required class="px-5 py-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-600">
        <select name="role" class="px-5 py-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-600">
            <option value="admin">Admin</option>
            <option value="hr">HR</option>
            <option value="viewer">Viewer Only</option>
        </select>
        <button type="submit" name="add_user" class="bg-purple-700 hover:bg-purple-800 text-white px-8 py-4 rounded-lg font-bold transition duration-200">Add User</button>
    </form>
</div>

<!-- Users Table -->
<div class="bg-white rounded-xl shadow-lg overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-100">
                <tr>
                    <th class="px-6 py-4 text-left">Username</th>
                    <th class="px-6 py-4 text-left">Full Name</th>
                    <th class="px-6 py-4 text-left">Role</th>
                    <th class="px-6 py-4 text-left">Status</th>
                    <th class="px-6 py-4 text-left">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr class="border-t hover:bg-gray-50">
                    <td class="px-6 py-4"><?= htmlspecialchars($user['username']) ?></td>
                    <td class="px-6 py-4"><?= htmlspecialchars($user['full_name']) ?></td>
                    <td class="px-6 py-4">
                        <span class="px-3 py-1 rounded-full text-white text-sm <?= 
                            $user['role']=='admin' ? 'bg-red-600' : 
                            ($user['role']=='hr' ? 'bg-blue-600' : 'bg-gray-600') 
                        ?>">
                            <?= ucfirst($user['role']) ?>
                        </span>
                    </td>
                    <td class="px-6 py-4">
                        <span class="px-3 py-1 rounded-full text-white text-sm <?= $user['is_active'] ? 'bg-green-600' : 'bg-gray-500' ?>">
                            <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex gap-2">
                            <!-- Change Password Button -->
                            <button onclick="openPasswordModal(<?= $user['user_id'] ?>, '<?= htmlspecialchars($user['username']) ?>')" 
                                    class="bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-1 rounded text-sm transition duration-200">
                                Change Password
                            </button>
                            
                            <!-- Toggle Status Button -->
                            <a href="?page=users&toggle_status=<?= $user['user_id'] ?>" 
                               onclick="return confirm('Are you sure you want to <?= $user['is_active'] ? 'deactivate' : 'activate' ?> this user?')"
                               class="<?= $user['is_active'] ? 'bg-orange-500 hover:bg-orange-600' : 'bg-green-500 hover:bg-green-600' ?> text-white px-3 py-1 rounded text-sm transition duration-200">
                                <?= $user['is_active'] ? 'Deactivate' : 'Activate' ?>
                            </a>
                            
                            <!-- Delete Button (don't show for current user) -->
                            <?php if ($user['user_id'] != $_SESSION['admin_user_id']): ?>
                            <a href="?page=users&delete_user=<?= $user['user_id'] ?>" 
                               onclick="return confirm('Are you sure you want to delete user <?= htmlspecialchars($user['username']) ?>? This action cannot be undone.')"
                               class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-sm transition duration-200">
                                Delete
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Change Password Modal -->
<div id="passwordModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold">Change Password</h3>
            <button onclick="closePasswordModal()" class="text-gray-600 hover:text-gray-800 text-xl">&times;</button>
        </div>
        
        <form method="POST" onsubmit="return validatePasswordForm()">
            <input type="hidden" name="user_id" id="modal_user_id">
            
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">User: <span id="modal_username" class="text-purple-600"></span></label>
            </div>
            
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">New Password (min 6 chars)</label>
                <input type="password" name="new_password" id="new_password" required 
                       class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-600">
            </div>
            
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">Confirm Password</label>
                <input type="password" name="confirm_password" id="confirm_password" required 
                       class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-600">
                <p id="password_error" class="text-red-500 text-xs mt-1 hidden">Passwords do not match!</p>
            </div>
            
            <div class="flex gap-2">
                <button type="submit" name="change_password" 
                        class="bg-purple-700 hover:bg-purple-800 text-white font-bold py-2 px-4 rounded transition duration-200">
                    Change Password
                </button>
                <button type="button" onclick="closePasswordModal()" 
                        class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded transition duration-200">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openPasswordModal(userId, username) {
    document.getElementById('modal_user_id').value = userId;
    document.getElementById('modal_username').textContent = username;
    document.getElementById('passwordModal').classList.remove('hidden');
    document.getElementById('new_password').value = '';
    document.getElementById('confirm_password').value = '';
    document.getElementById('password_error').classList.add('hidden');
}

function closePasswordModal() {
    document.getElementById('passwordModal').classList.add('hidden');
}

function validatePasswordForm() {
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    const errorDiv = document.getElementById('password_error');
    
    if (newPassword !== confirmPassword) {
        errorDiv.classList.remove('hidden');
        return false;
    }
    
    if (newPassword.length < 6) {
        alert('Password must be at least 6 characters long!');
        return false;
    }
    
    errorDiv.classList.add('hidden');
    return true;
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('passwordModal');
    if (event.target === modal) {
        closePasswordModal();
    }
}
</script>