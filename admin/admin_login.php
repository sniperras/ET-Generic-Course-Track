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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, height=device-height, viewport-fit=cover">
    <title>Admin Login - Generic Course Track</title>
    <link rel="icon" type="image/png" href="../img/ethiopian_logo.ico">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            height: 100vh;
            overflow: hidden;
        }

        /* No scroll - everything fits in one view */
        .no-scroll {
            overflow: hidden;
        }
    </style>
</head>

<body class="bg-gradient-to-br from-cream-900 to-blue-700 no-scroll flex flex-col">

    <!-- Navbar - compact version -->
    <nav class="bg-blue-900 text-white shadow flex-shrink-0">
        <div class="max-w-7xl mx-auto px-6 py-3 flex justify-between items-center">
            <a href="../index.php" class="flex items-center space-x-3 text-white hover:text-blue-200 transition">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                </svg>
                <span class="font-bold text-lg">Generic Course Track</span>
            </a>
            <a href="../index.php" class="text-white hover:text-blue-200 font-medium px-3 py-1.5 rounded-lg transition text-sm">
                ← Back to Home
            </a>
        </div>
    </nav>

    <!-- Main Content - perfectly centered with padding top/bottom -->
    <div class="flex-1 flex items-center justify-center px-4 py-4 overflow-hidden">
        <div class="bg-white rounded-2xl shadow-2xl p-8 w-full max-w-md">
            <div class="text-center mb-5">
                <h1 class="text-2xl font-bold text-gray-800">Admin Login</h1>
                <p class="text-gray-600 mt-1 text-sm">Generic Course Track HR Panel</p>
            </div>

            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-2 rounded-lg mb-5 text-center font-medium text-sm">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-1 text-sm">Username</label>
                    <input type="text" name="username" required autocomplete="off"
                        class="w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:border-blue-600 focus:outline-none transition font-mono text-base"
                        placeholder="username">
                </div>

                <div class="mb-5">
                    <label class="block text-gray-700 font-semibold mb-1 text-sm">Password</label>
                    <input type="password" name="password" required
                        class="w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:border-blue-600 focus:outline-none transition text-base"
                        placeholder="••••••••">
                </div>

                <button type="submit"
                    class="w-full bg-blue-800 hover:bg-blue-900 text-white font-bold text-lg py-3 rounded-xl transition shadow-lg hover:scale-105">
                    Login
                </button>
            </form>


        </div>
    </div>

    <!-- FOOTER: stays at bottom, matches original blue-950 with subtle text, compact -->
    <footer class="bg-blue-950 text-gray-300 py-3 flex-shrink-0">
        <div class="w-full max-w-7xl mx-auto px-5 text-center">
            <p class="text-xs">
                © 2026 <span class="font-semibold text-white">Generic Course Track</span>. All rights reserved.
            </p>
            <p class="text-[11px] mt-1 opacity-75">
                MRO Course Track System • Powered by NATNAEL BIZUNEH
            </p>
        </div>
    </footer>

</body>

</html>