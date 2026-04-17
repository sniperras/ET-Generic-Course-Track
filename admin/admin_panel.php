<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../include/db_connect.php';
require_once __DIR__ . '/../include/auth.php';
// Get the requested page
$page = $_GET['page'] ?? 'dashboard';

// Check if user has access to this page
requirePageAccess($page);
// Whitelist of allowed pages to include
$allowed_pages = [
    'dashboard', 'employees', 'courses', 'records',
    'import', 'import_training', 'training_disputes_manage','1&status=open', 'users'
];

// Change default page to 'dashboard'
$page_raw = $_GET['page'] ?? 'dashboard';
$page = in_array($page_raw, $allowed_pages, true) ? $page_raw : 'dashboard';

// require admin (redirect or JSON response for AJAX)
requireAdmin();

// Check user role from session
$current_user_role = $_SESSION['user_role'] ?? '';

// Check if current user can access the requested page
// Instead of manually checking $current_user_role
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
  <title>Admin Panel - GenericCourseTrack</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>body{font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial;}</style>
</head>
<body class="bg-gray-100 min-h-screen font-inter">
  <header class="bg-blue-900 text-white shadow-lg">
    <div class="max-w-7xl mx-auto px-6 py-4 flex justify-between items-center">
      <h1 class="text-2xl font-bold">Admin Panel</h1>
      <div class="flex items-center gap-6">
        <span>Welcome, <strong><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></strong> 
        <span class="text-sm bg-blue-700 px-3 py-1 rounded-full"><?= strtoupper($current_user_role) ?></span>
        </span>
        <a href="/index.php" class="bg-red-600 hover:bg-red-700 px-5 py-2 rounded-lg font-medium">Logout</a>
      </div>
    </div>
  </header>

  <div class="flex">
    <aside class="w-64 bg-white shadow-xl min-h-screen">
      <nav class="mt-8">
        <?php
        $nav = [
            'dashboard' => 'Dashboard',
            'employees' => 'Employees',
            'courses'   => 'Courses',
            'records'   => 'Training Records',
            'import'    => 'Import Employee Excel',
            'import_training' => 'Import Records Excel',
            'training_disputes_manage' => 'Manage Training Disputes',
            'users'     => 'Users'
        ];
        foreach ($nav as $key => $label):
            $active = $page === $key;
            // Hide "Users" menu item for non-admin users
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
      // Display error message if set
      if (isset($_SESSION['error_message'])) {
          echo '<div class="bg-red-100 text-red-800 p-4 rounded-lg mb-6">' . htmlspecialchars($_SESSION['error_message']) . '</div>';
          unset($_SESSION['error_message']);
      }
      
      // include the chosen page (safe via whitelist)
      switch ($page) {
          case 'employees':       include __DIR__ . '/employees.php';      break;
          case 'courses':         include __DIR__ . '/courses.php';        break;
          case 'dashboard':       include __DIR__ . '/dashboard.php';      break;
          case 'import':          include __DIR__ . '/import.php';         break;
          case 'import_training': include __DIR__ . '/import_training.php';break;
          case 'training_disputes_manage': include __DIR__ . '/training_disputes_manage.php'; break;
          case '1&status=open':   include __DIR__ . '/training_disputes_manage.php?1&status=open'; break;
          case 'users':           include __DIR__ . '/users.php';          break;
          case 'records':         include __DIR__ . '/records.php';        break;
          default:                include __DIR__ . '/dashboard.php';        break;
      }
      ?>
    </main>
  </div>
</body>
</html>