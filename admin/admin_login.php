<?php
require_once '../include/db_connect.php';
require_once '../include/auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (loginAdmin($username, $password)) {
        header("Location: admin_panel.php");
        exit();
    } else {
        $error = "Invalid username or password";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Generic Course Track</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>

<body class="bg-gradient-to-br from-cream-900 to-blue-700 min-h-screen flex flex-col">

    <!-- Navbar -->
    <nav class="bg-blue-900 text-white shadow">
        <div class="max-w-7xl mx-auto px-6 py-4 flex justify-between items-center">
            <a href="../index.php" class="flex items-center space-x-3 text-white hover:text-blue-200 transition">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="true" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                </svg>
                <span class="font-bold text-xl">Generic Course Track</span>
            </a>
            <a href="../index.php" class="text-white hover:text-blue-200 font-medium px-4 py-2 rounded-lg transition">
                ← Back to Home
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="flex-1 flex items-center justify-center px-4 py-8">
        <div class="bg-white rounded-2xl shadow-2xl p-10 w-full max-w-md">
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-gray-800">Admin Login</h1>
                <p class="text-gray-600 mt-2">Generic Course Track HR Panel</p>
            </div>

            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 text-center font-medium">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
    <div class="mb-6">
        <label class="block text-gray-700 font-semibold mb-2">Username</label>
        <input type="text" name="username" required autocomplete="off"
               class="w-full px-5 py-4 border-2 border-gray-300 rounded-xl focus:border-blue-600 focus:outline-none transition font-mono text-lg"
               placeholder="username">
    </div>

    <div class="mb-8">
        <label class="block text-gray-700 font-semibold mb-2">Password</label>
        <input type="password" name="password" required
               class="w-full px-5 py-4 border-2 border-gray-300 rounded-xl focus:border-blue-600 focus:outline-none transition text-lg"
               placeholder="••••••••">
    </div>

    <button type="submit"
            class="w-full bg-blue-800 hover:bg-blue-900 text-white font-bold text-xl py-5 rounded-xl transition shadow-lg transform hover:scale-105">
        Login
    </button>
</form>

<!-- Tips Section -->
<div class="mt-8 bg-gradient-to-r from-blue-50 to-blue-100 border-l-4 border-blue-600 rounded-xl p-6 shadow-md">
    <h2 class="text-lg font-semibold text-blue-900 mb-3 flex items-center">
        <svg class="w-6 h-6 mr-2 text-blue-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M13 16h-1v-4h-1m1-4h.01M12 20h.01M12 4h.01M4 12h.01M20 12h.01M12 12h.01"/>
        </svg>
        Helpful Tips
    </h2>
    <ul class="space-y-2 text-gray-700">
       
        <li class="flex items-start">
            <span class="text-blue-600 font-bold mr-2">•</span>
            First login may take longer as the system prepares reports.
        </li>
      
      
        <li class="flex items-start">
            <span class="text-blue-600 font-bold mr-2">•</span>
            Contact IT support if you encounter repeated login issues.
        </li>
    </ul>
</div>


          
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-blue-950 text-gray-300 py-8 mt-auto">
        <div class="max-w-7xl mx-auto px-6 text-center">
            <p class="text-sm">
                © 2025 <span class="font-semibold text-white">Generic Course Track</span>. All rights reserved.
            </p>
            <p class="text-xs mt-2 opacity-75">
                MRO HR Management System • Powered by Ethiopian Developers
            </p>
        </div>
    </footer>

</body>
</html>