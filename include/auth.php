<?php
// include/auth.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isAdminLoggedIn(): bool {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function requireAdmin() {
    if (!isAdminLoggedIn()) {
        header("Location: admin_login.php");
        exit();
    }
}

/**
 * Login a user from the `users` table
 */
function loginAdmin(string $username, string $password): bool {
    global $pdo;

    $stmt = $pdo->prepare("SELECT user_id, full_name, password, role FROM users WHERE username = ? AND is_active = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_user_id']  = $user['user_id'];
        $_SESSION['admin_name']     = $user['full_name'];
        $_SESSION['admin_role']     = $user['role'];
        return true;
    }
    return false;
}

function logoutAdmin() {
    session_unset();
    session_destroy();
}

/**
 * Get current logged‑in user info (optional helper)
 */
function getCurrentAdmin(): ?array {
    if (!isAdminLoggedIn()) return null;
    return [
        'id'   => $_SESSION['admin_user_id'],
        'name' => $_SESSION['admin_name'],
        'role' => $_SESSION['admin_role']
    ];
}
?>