<?php require_once '../include/db_connect.php'; require_once '../include/auth.php'; requireAdmin(); ?>

<h2 class="text-3xl font-bold mb-8">Manage Users (Admins & HR)</h2>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $username = $_POST['username'];
    $pass = password_hash($_POST['password'], PASSWORD_BCRYPT);
    $name = $_POST['full_name'];
    $role = $_POST['role'];

    $pdo->prepare("INSERT INTO users (username, full_name, password, role) VALUES (?,?,?,?)")
        ->execute([$username, $name, $pass, $role]);
    echo '<div class="bg-green-100 text-green-800 p-4 rounded-lg mb-6">User added!</div>';
}
?>

<div class="bg-white rounded-xl shadow-lg p-8">
    <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <input type="text" name="username" placeholder="Username" required class="px-5 py-3 border rounded-lg">
        <input type="text" name="full_name" placeholder="Full Name" required class="px-5 py-3 border rounded-lg">
        <input type="password" name="password" placeholder="Password" required class="px-5 py-3 border rounded-lg">
        <select name="role" class="px-5 py-3 border rounded-lg">
            <option value="admin">Admin</option>
            <option value="hr">HR</option>
            <option value="viewer">Viewer Only</option>
        </select>
        <button name="add_user" class="bg-purple-700 text-white px-8 py-4 rounded-lg font-bold">Add User</button>
    </form>

    <table class="w-full">
        <thead class="bg-gray-100">
            <tr><th class="px-6 py-4">Username</th><th>Name</th><th>Role</th></tr>
        </thead>
        <tbody>
            <?php foreach ($pdo->query("SELECT username, full_name, role FROM users") as $u): ?>
            <tr class="border-t">
                <td class="px-6 py-4"><?= $u['username'] ?></td>
                <td class="px-6 py-4"><?= htmlspecialchars($u['full_name']) ?></td>
                <td class="px-6 py-4"><span class="px-3 py-1 rounded-full text-white text-sm <?= $u['role']=='admin'?'bg-red-600':($u['role']=='hr'?'bg-blue-600':'bg-gray-600') ?>">
                    <?= ucfirst($u['role']) ?>
                </span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>