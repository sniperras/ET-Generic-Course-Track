<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../include/db_connect.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}
if (!isset($pdo) || !$pdo instanceof PDO) {
    echo json_encode(['success' => false, 'message' => 'DB connection missing']);
    exit;
}

$input = $_POST ?? [];
$action = (string)($input['action'] ?? '');
$id = (int)($input['id'] ?? 0);
$hr_comment = trim($input['hr_comment'] ?? '');
$csrf = $_SESSION['csrf_token'] ?? '';
$provided = (string)($input['csrf_token'] ?? '');

if ($action !== 'toggle_status' || $id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}
if (!hash_equals((string)$csrf, $provided)) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// fetch current status
$stmt = $pdo->prepare("SELECT status, hr_comment FROM training_disputes WHERE dispute_id = ? LIMIT 1");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Dispute not found']);
    exit;
}
$current = mb_strtolower((string)$row['status']);
$new = ($current === 'closed') ? 'open' : 'closed';
$admin_user = $_SESSION['admin_user'] ?? $_SESSION['admin_name'] ?? 'admin';

// If closing, validate HR comment
if ($new === 'closed') {
    if (empty($hr_comment)) {
        echo json_encode(['success' => false, 'message' => 'HR comment is required to close the dispute']);
        exit;
    }
    
    // Update with HR comment
    $upd = $pdo->prepare("UPDATE training_disputes SET status = ?, updated_at = NOW(), closed_by = ?, hr_comment = ? WHERE dispute_id = ?");
    $ok = $upd->execute([$new, $admin_user, $hr_comment, $id]);
} else {
    // When re-opening, keep existing HR comment
    $upd = $pdo->prepare("UPDATE training_disputes SET status = ?, updated_at = NOW(), closed_by = NULL WHERE dispute_id = ?");
    $ok = $upd->execute([$new, $id]);
}

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Failed to update status']);
    exit;
}

// fetch updated row to return timestamps and closed_by
$fetch = $pdo->prepare("SELECT status, updated_at, closed_by, hr_comment FROM training_disputes WHERE dispute_id = ? LIMIT 1");
$fetch->execute([$id]);
$after = $fetch->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'dispute_id' => $id,
    'new_status' => $after['status'] ?? $new,
    'updated_at' => $after['updated_at'] ?? null,
    'closed_by' => $after['closed_by'] ?? null,
    'hr_comment' => $after['hr_comment'] ?? $hr_comment,
]);
exit;