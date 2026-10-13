<?php

declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../include/db_connect.php';
require_once __DIR__ . '/../include/auth.php';

// Handle password change (logged-in users only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_own_password'])) {
  if (!isAdminLoggedIn()) {
    header('Location: admin_login.php');
    exit;
  }

  $current_password = $_POST['current_password'];
  $new_password     = $_POST['new_password'];
  $confirm_password = $_POST['confirm_password'];

  $user_id = $_SESSION['admin_user_id'];
  $stmt = $pdo->prepare("SELECT password FROM users WHERE user_id = ?");
  $stmt->execute([$user_id]);
  $user = $stmt->fetch();

  if (!$user || !password_verify($current_password, $user['password'])) {
    $_SESSION['password_error'] = 'Current password is incorrect!';
  } elseif ($new_password !== $confirm_password) {
    $_SESSION['password_error'] = 'New passwords do not match!';
  } elseif (strlen($new_password) < 6) {
    $_SESSION['password_error'] = 'Password must be at least 6 characters long!';
  } else {
    $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
    $update_stmt = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
    if ($update_stmt->execute([$hashed_password, $user_id])) {
      $_SESSION['password_success'] = 'Password changed successfully!';
    } else {
      $_SESSION['password_error'] = 'Failed to update password. Please try again.';
    }
  }

  header('Location: admin_panel.php?page=' . ($_GET['page'] ?? 'dashboard'));
  exit;
}

// Whitelist of allowed pages
$allowed_pages = [
  'dashboard',
  'department_gap_report',
  'employees',
  'courses',
  'records',
  'import',
  'import_training',
  'training_disputes_manage',
  '1&status=open',
  'users'
];

// Resolve page
$page_raw = $_GET['page'] ?? 'dashboard';
$page = in_array($page_raw, $allowed_pages, true) ? $page_raw : 'dashboard';

// Auth gate: public pages skip login, everything else requires it
// (requirePageAccess internally calls requireAdmin() for protected pages)
requirePageAccess($page);

$current_user_role = $_SESSION['user_role'] ?? '';

// Users page is admin-only
if (!canManageUsers() && $page === 'users') {
  $_SESSION['error_message'] = 'Access denied. Only administrators can manage users.';
  header('Location: admin_panel.php?page=dashboard');
  exit;
}

// AJAX special handling
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
  if ($page === 'training_disputes_manage') {
    include __DIR__ . '/training_disputes_manage.php';
    exit;
  }
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['success' => false, 'message' => 'AJAX not supported for this route']);
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Ethiopian Airlines Generic Course Track</title>
  <link rel="icon" type="image/png" href="../img/ethiopian_logo.ico">
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
    }
  </style>
</head>

<body class="bg-gray-100 min-h-screen font-inter">
  <header class="bg-blue-900 text-white shadow-lg">
    <div class="max-w-7xl mx-auto px-6 py-4 flex justify-between items-center">
      <img src="../img/logo.png" alt="Logo" class="h-[3.5rem] w-auto" onerror="this.style.display='none'">
      <h1 class="text-xl font-bold">Ethiopian Airlines Generic Course Track</h1>
      <div class="flex items-center gap-6">
        <span>Welcome, <strong><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Guest') ?></strong></span>
        <span class="text-sm bg-blue-700 px-3 py-1 rounded-full"><?= strtoupper($current_user_role) ?></span>
        <?php if ($current_user_role !== 'guest'): ?>
          <button onclick="openPasswordModal()"
            class="bg-yellow-600 hover:bg-yellow-700 px-5 py-2 rounded-lg font-medium transition duration-200">
            Change Password
          </button>
          <a href="/index.php" class="bg-red-600 hover:bg-red-700 px-5 py-2 rounded-lg font-medium transition duration-200">Logout</a>
        <?php else: ?>
          <a href="admin_login.php" class="bg-green-600 hover:bg-green-700 px-5 py-2 rounded-lg font-medium transition duration-200">Login</a>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <div class="flex">
    <aside class="w-64 bg-white shadow-xl min-h-screen">
      <nav class="mt-8">
        <?php
        $nav = [
          'dashboard' => 'Dashboard',
          'department_gap_report' => 'Department Gap Report',
          'employees' => 'Employees',
          'courses'   => 'Courses',
          'records'   => 'Training Records',
          'import'    => 'Import Employee Excel',
          'import_training' => 'Import Records Excel',
          'training_disputes_manage' => 'Manage Training Disputes',
          'users'     => 'Users'
        ];

        // Guests see only public pages
        if ($current_user_role === 'guest') {
          $nav = [
            'dashboard' => 'Dashboard',
            'department_gap_report' => 'Department Gap Report',
          ];
        }

        foreach ($nav as $key => $label):
          $active = $page === $key;
          if ($key === 'users' && $current_user_role !== 'admin') {
            continue;
          }
        ?>
          <a href="?page=<?= urlencode($key) ?>"
            class="block py-4 px-8 <?= $active ? 'bg-blue-700 text-white' : 'hover:bg-gray-100 text-gray-700' ?>">
            <?= htmlspecialchars($label) ?>
          </a>
        <?php endforeach; ?>
      </nav>
    </aside>

    <main class="flex-1 p-10">
      <?php
      if (isset($_SESSION['password_success'])) {
        echo '<div class="bg-green-100 border border-green-400 text-green-800 px-4 py-3 rounded-lg mb-6">' . htmlspecialchars($_SESSION['password_success']) . '</div>';
        unset($_SESSION['password_success']);
      }
      if (isset($_SESSION['password_error'])) {
        echo '<div class="bg-red-100 border border-red-400 text-red-800 px-4 py-3 rounded-lg mb-6">' . htmlspecialchars($_SESSION['password_error']) . '</div>';
        unset($_SESSION['password_error']);
      }
      if (isset($_SESSION['error_message'])) {
        echo '<div class="bg-red-100 text-red-800 p-4 rounded-lg mb-6">' . htmlspecialchars($_SESSION['error_message']) . '</div>';
        unset($_SESSION['error_message']);
      }

      switch ($page) {
        case 'employees':
          include __DIR__ . '/employees.php';
          break;
        case 'courses':
          include __DIR__ . '/courses.php';
          break;
        case 'dashboard':
          include __DIR__ . '/dashboard.php';
          break;
        case 'import':
          include __DIR__ . '/import.php';
          break;
        case 'department_gap_report':
          include __DIR__ . '/department_gap_report.php';
          break;
        case 'import_training':
          include __DIR__ . '/import_training.php';
          break;
        case 'training_disputes_manage':
          include __DIR__ . '/training_disputes_manage.php';
          break;
        case '1&status=open':
          include __DIR__ . '/training_disputes_manage.php';
          break;
        case 'users':
          include __DIR__ . '/users.php';
          break;
        case 'records':
          include __DIR__ . '/records.php';
          break;
        default:
          include __DIR__ . '/dashboard.php';
          break;
      }
      ?>
    </main>
  </div>

  <!-- Change Password Modal (only for logged-in users) -->
  <?php if ($current_user_role !== 'guest'): ?>
    <div id="passwordModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
      <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-lg font-bold text-gray-900">Change Your Password</h3>
          <button onclick="closePasswordModal()" class="text-gray-600 hover:text-gray-800 text-2xl">&times;</button>
        </div>

        <form method="POST" onsubmit="return validatePasswordForm()">
          <div class="mb-4">
            <label class="block text-gray-700 text-sm font-bold mb-2">Current Password</label>
            <input type="password" name="current_password" id="current_password" required
              class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600">
          </div>

          <div class="mb-4">
            <label class="block text-gray-700 text-sm font-bold mb-2">New Password (min 6 chars)</label>
            <input type="password" name="new_password" id="new_password" required
              class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600">
          </div>

          <div class="mb-4">
            <label class="block text-gray-700 text-sm font-bold mb-2">Confirm New Password</label>
            <input type="password" name="confirm_password" id="confirm_password" required
              class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-600">
            <p id="password_error" class="text-red-500 text-xs mt-1 hidden">Passwords do not match!</p>
          </div>

          <div class="flex gap-2">
            <button type="submit" name="change_own_password"
              class="bg-blue-700 hover:bg-blue-800 text-white font-bold py-2 px-4 rounded transition duration-200">
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
  <?php endif; ?>

  <script>
    function openPasswordModal() {
      document.getElementById('passwordModal').classList.remove('hidden');
      document.getElementById('current_password').value = '';
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

    window.onclick = function(event) {
      const modal = document.getElementById('passwordModal');
      if (modal && event.target === modal) {
        closePasswordModal();
      }
    }
  </script>
</body>

</html>