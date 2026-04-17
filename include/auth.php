<?php
// include/auth.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isAdminLoggedIn(): bool {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function getCurrentUserRole(): string {
    return $_SESSION['admin_role'] ?? '';
}

function isUserAdmin(): bool {
    return getCurrentUserRole() === 'admin';
}

function isUserHR(): bool {
    return getCurrentUserRole() === 'hr';
}

function isUserViewer(): bool {
    return getCurrentUserRole() === 'viewer';
}

function requireAdmin() {
    if (!isAdminLoggedIn()) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Not authenticated']);
            exit;
        }
        header("Location: admin_login.php");
        exit();
    }
}

/**
 * Check if user has specific role
 * @param string|array $requiredRole Role or array of roles to check
 * @return bool True if user has required role
 */
function hasRole($requiredRole): bool {
    $userRole = getCurrentUserRole();
    
    if (is_array($requiredRole)) {
        return in_array($userRole, $requiredRole, true);
    }
    
    return $userRole === $requiredRole;
}

/**
 * Check if user can access a specific page based on role
 * @param string $page The page parameter value (e.g., 'dashboard', 'users')
 * @return bool True if user has access to the page
 */
function canAccessPage(string $page): bool {
    $userRole = getCurrentUserRole();
    
    // Admin can access all pages
    if ($userRole === 'admin') {
        return true;
    }
    
    // HR can access all pages except 'users'
    if ($userRole === 'hr') {
        return $page !== 'users';
    }
    
    // Viewer can only access 'dashboard' page
    if ($userRole === 'viewer') {
        return $page === 'dashboard';
    }
    
    return false;
}

/**
 * Require specific role(s) to access a page
 * @param string|array $requiredRole Role or array of roles required
 * @param string $redirectPage Page to redirect to if access denied
 */
function requireRole($requiredRole, string $redirectPage = 'admin_panel.php?page=dashboard') {
    requireAdmin(); // First ensure user is logged in
    
    if (!hasRole($requiredRole)) {
        $_SESSION['error_message'] = 'Access denied. You do not have sufficient permissions.';
        header("Location: $redirectPage");
        exit();
    }
}

/**
 * Require specific page access based on current role
 * @param string $page The page parameter being accessed
 */
function requirePageAccess(string $page) {
    requireAdmin(); // First ensure user is logged in
    
    if (!canAccessPage($page)) {
        $_SESSION['error_message'] = 'Access denied. You do not have permission to access this page.';
        
        // Redirect based on role
        $userRole = getCurrentUserRole();
        if ($userRole === 'viewer') {
            header("Location: admin_panel.php?page=dashboard");
        } elseif ($userRole === 'hr') {
            header("Location: admin_panel.php?page=dashboard");
        } else {
            header("Location: admin_login.php");
        }
        exit();
    }
}

/**
 * Require admin role specifically
 */
function requireAdminRole() {
    requireRole('admin');
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
        $_SESSION['admin_user_id']   = $user['user_id'];
        $_SESSION['admin_name']      = $user['full_name'];
        $_SESSION['admin_role']      = $user['role'];
        $_SESSION['user_role']       = $user['role']; // Keep both for compatibility
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

/**
 * Check if user can manage users (admin only)
 */
function canManageUsers(): bool {
    return isAdminLoggedIn() && isUserAdmin();
}

/**
 * Check if user can manage disputes (admin and hr)
 */
function canManageDisputes(): bool {
    return isAdminLoggedIn() && (isUserAdmin() || isUserHR());
}

/**
 * Check if user can import data (admin and hr)
 */
function canImportData(): bool {
    return isAdminLoggedIn() && (isUserAdmin() || isUserHR());
}

/**
 * Check if user can view a specific page (for menu rendering)
 * @param string $page The page to check
 * @return bool True if user can view this page in menu
 */
function canViewMenuPage(string $page): bool {
    return canAccessPage($page);
}
?>