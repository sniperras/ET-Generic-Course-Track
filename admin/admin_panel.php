<?php
require_once '../include/db_connect.php';
require_once '../include/auth.php';
requireAdmin(); // Redirects to login if not authenticated

// Allowed pages to include (whitelist)
$allowed_pages = [
    'dashboard', 'employees', 'courses', 'records',
    'import', 'import_training', 'users'
];

// sanitize requested page
$page_raw = $_GET['page'] ?? 'dashboard';
$page = in_array($page_raw, $allowed_pages, true) ? $page_raw : 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Admin Panel - GenericCourseTrack</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="bg-gray-100 min-h-screen font-inter">

    <!-- Top Bar -->
    <header class="bg-blue-900 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-6 py-4 flex justify-between items-center">
            <h1 class="text-2xl font-bold">Admin Panel</h1>
            <div class="flex items-center gap-6">
                <span>Welcome, <strong><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></strong></span>
                <!-- Point to logout.php (recommended) — adapt if you use a different logout URL -->
                <a href="../index.php" class="bg-red-600 hover:bg-red-700 px-5 py-2 rounded-lg font-medium">Logout</a>
            </div>
        </div>
    </header>

    <div class="flex">
        <!-- Sidebar -->
        <aside class="w-64 bg-white shadow-xl min-h-screen">
            <nav class="mt-8">
                <?php
                // build nav items array so logic is simple and consistent
                $nav = [
                    'dashboard' => 'Dashboard',
                    'employees' => 'Employees',
                    'courses'   => 'Courses',
                    'records'   => 'Training Records',
                    'import'    => 'Import Employee Excel',
                    'import_training' => 'Import Records Excel',
                    'users'     => 'Users'
                ];
                foreach ($nav as $key => $label):
                    $active = $page === $key;
                ?>
                    <a href="?page=<?= urlencode($key) ?>"
                       class="block py-4 px-8 <?= $active ? 'bg-blue-700 text-white' : 'hover:bg-gray-100 text-gray-700' ?>">
                        <?= htmlspecialchars($label) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 p-10">
            <?php
            // Safe include: include only whitelisted pages
            switch ($page) {
                case 'employees':       include 'employees.php';      break;
                case 'courses':         include 'courses.php';        break;
                case 'records':         include 'records.php';        break;
                case 'import':          include 'import.php';         break; // keep old import if present
                case 'import_training': include 'import_training.php';break; // updated import script (place here)
                case 'users':           include 'users.php';          break;
                default:                include 'dashboard.php';      break;
            }
            ?>
        </main>
    </div>
</body>
</html>
