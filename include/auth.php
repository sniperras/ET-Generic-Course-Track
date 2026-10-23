<?php
// include/auth.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ────────────────────────────────────────────────────────────────
// GUEST MODE — pages allowed without login
// ────────────────────────────────────────────────────────────────
function isPublicPage(string $page): bool
{
    // Only these page keys are accessible without login
    return in_array($page, [
        'department_gap_report',
        'dashboard',     // ← remove this line if you don't want the dashboard public
    ], true);
}

function isGuest(): bool
{
    return !isAdminLoggedIn() && !empty($_SESSION['guest_mode']);
}

function enterGuestMode(): void
{
    if (!isAdminLoggedIn()) {
        $_SESSION['guest_mode'] = true;
        $_SESSION['admin_name'] = 'Guest';
        $_SESSION['admin_role'] = 'guest';
        $_SESSION['user_role']  = 'guest';
    }
}

// ────────────────────────────────────────────────────────────────
function isAdminLoggedIn(): bool
{
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function getCurrentUserRole(): string
{
    return $_SESSION['admin_role'] ?? '';
}

function isUserAdmin(): bool
{
    return getCurrentUserRole() === 'admin';
}

function isUserHR(): bool
{
    return getCurrentUserRole() === 'hr';
}

function isUserViewer(): bool
{
    return getCurrentUserRole() === 'viewer';
}

function isUserGuest(): bool
{
    return getCurrentUserRole() === 'guest';
}

function requireAdmin()
{
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

function hasRole($requiredRole): bool
{
    $userRole = getCurrentUserRole();
    if (is_array($requiredRole)) {
        return in_array($userRole, $requiredRole, true);
    }
    return $userRole === $requiredRole;
}

function canAccessPage(string $page): bool
{
    $userRole = getCurrentUserRole();

    // Admin → everything
    if ($userRole === 'admin') return true;

    // HR → everything except 'users'
    if ($userRole === 'hr') return $page !== 'users';

    // Viewer → only dashboard
    if ($userRole === 'viewer') return $page === 'dashboard';

    // Guest → only dashboard + department_gap_report
    if ($userRole === 'guest') {
        return in_array($page, ['dashboard', 'department_gap_report'], true);
    }

    return false;
}

function requireRole($requiredRole, string $redirectPage = 'admin_panel.php?page=dashboard')
{
    requireAdmin();
    if (!hasRole($requiredRole)) {
        $_SESSION['error_message'] = 'Access denied. You do not have sufficient permissions.';
        header("Location: $redirectPage");
        exit();
    }
}

function requirePageAccess(string $page)
{
    // ⚠️ Allow guests to see public pages WITHOUT login
    if (isPublicPage($page) && !isAdminLoggedIn()) {
        enterGuestMode();
        return;   // ← skip the auth requirement entirely
    }

    requireAdmin();

    if (!canAccessPage($page)) {
        $_SESSION['error_message'] = 'Access denied. You do not have permission to access this page.';
        $userRole = getCurrentUserRole();
        if ($userRole === 'viewer' || $userRole === 'hr' || $userRole === 'guest') {
            header("Location: admin_panel.php?page=dashboard");
        } else {
            header("Location: admin_login.php");
        }
        exit();
    }
}

function requireAdminRole()
{
    requireRole('admin');
}

function loginAdmin(string $username, string $password): bool
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT user_id, full_name, password, role FROM users WHERE username = ? AND is_active = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password'])) {
        unset($_SESSION['guest_mode']);  // ← clear guest flag on real login
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_user_id']   = $user['user_id'];
        $_SESSION['admin_name']      = $user['full_name'];
        $_SESSION['admin_role']      = $user['role'];
        $_SESSION['user_role']       = $user['role'];
        return true;
    }
    return false;
}

function logoutAdmin()
{
    session_unset();
    session_destroy();
}

function getCurrentAdmin(): ?array
{
    if (!isAdminLoggedIn()) return null;
    return [
        'id'   => $_SESSION['admin_user_id'],
        'name' => $_SESSION['admin_name'],
        'role' => $_SESSION['admin_role']
    ];
}

function canManageUsers(): bool
{
    return isAdminLoggedIn() && isUserAdmin();
}

function canManageDisputes(): bool
{
    return isAdminLoggedIn() && (isUserAdmin() || isUserHR());
}

function canImportData(): bool
{
    return isAdminLoggedIn() && (isUserAdmin() || isUserHR());
}

function canViewMenuPage(string $page): bool
{
    return canAccessPage($page);
}