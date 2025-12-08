<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../include/db_connect.php';

if (!isset($_SESSION['admin_logged_in'])) { http_response_code(403); exit; }

const ITEMS_PER_PAGE = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * ITEMS_PER_PAGE;
$q = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'all'; // ← This was the bug!

$whereParts = [];
$params = [];

if ($q !== '') {
    if (is_numeric($q)) {
        $whereParts[] = "(dispute_id = ? OR employee_id LIKE ? OR employee_name LIKE ?)";
        $params[] = (int)$q; $params[] = "%$q%"; $params[] = "%$q%";
    } else {
        $whereParts[] = "(employee_id LIKE ? OR employee_name LIKE ? OR LOWER(status) LIKE ?)";
        $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%" . strtolower($q) . "%";
    }
}

if ($statusFilter !== 'all' && in_array($statusFilter, ['open','closed'])) {
    $whereParts[] = "LOWER(status) = ?";
    $params[] = $statusFilter;
}

$where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM training_disputes $where");
$totalStmt->execute($params);
$totalCount = (int)$totalStmt->fetchColumn();
$totalPages = (int)ceil($totalCount / ITEMS_PER_PAGE);

$listStmt = $pdo->prepare("
    SELECT dispute_id, employee_id, employee_name, courses, course_dates, comment, status, created_at, updated_at, closed_by
    FROM training_disputes $where
    ORDER BY created_at DESC
    LIMIT " . ITEMS_PER_PAGE . " OFFSET $offset
");
$listStmt->execute($params);
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>

<table class="w-full">
  <thead class="bg-gradient-to-r from-gray-50 to-gray-100 border-b-2 border-gray-200">
    <tr>
      <th class="text-left p-5 font-semibold text-gray-700">ID</th>
      <th class="text-left p-5 font-semibold text-gray-700">Employee</th>
      <th class="text-left p-5 font-semibold text-gray-700">Courses</th>
      <th class="text-left p-5 font-semibold text-gray-700">Status</th>
      <th class="text-left p-5 font-semibold text-gray-700">Created</th>
      <th class="text-left p-5 font-semibold text-gray-700">Updated</th>
      <th class="text-left p-5 font-semibold text-gray-700">Closed By</th>
      <th class="text-left p-5 font-semibold text-gray-700">Action</th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($rows)): ?>
      <tr><td colspan="8" class="p-16 text-center text-gray-500 text-lg">No disputes found.</td></tr>
    <?php else: foreach ($rows as $r):
        $courses = json_decode($r['courses'] ?? '[]', true) ?: [$r['courses']];
        $preview = implode(', ', array_slice($courses, 0, 3));
        if (count($courses) > 3) $preview .= ', ...';
        $isOpen = strtolower($r['status']) === 'open';
    ?>
      <tr class="border-b border-gray-100 hover:bg-gray-50 transition table-row">
        <td class="p-5 font-mono text-sm">#<?= h($r['dispute_id']) ?></td>
        <td class="p-5">
          <div class="font-medium"><?= h($r['employee_name']) ?></div>
          <div class="text-xs text-gray-500">ID: <?= h($r['employee_id']) ?></div>
        </td>
        <td class="p-5 text-sm"><?= h($preview) ?></td>
        <td class="p-5">
          <span class="inline-block px-3 py-1.5 rounded-full text-xs font-bold <?= $isOpen ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' ?>">
            <?= $isOpen ? 'Open' : 'Closed' ?>
          </span>
        </td>
        <td class="p-5 font-mono text-xs"><?= h($r['created_at']) ?></td>
        <td class="p-5 font-mono text-xs"><?= $r['updated_at'] ? h($r['updated_at']) : '—' ?></td>
        <td class="p-5 text-sm"><?= $r['closed_by'] ? h($r['closed_by']) : '—' ?></td>
        <td class="p-5">
         <button 
  class="viewBtn bg-gradient-to-r from-indigo-500 to-purple-500 text-white px-5 py-2 rounded-lg font-medium hover:shadow-lg transition transform hover:scale-105"
  data-id="<?= h($r['dispute_id']) ?>"
  data-employee-id="<?= h($r['employee_id']) ?>"
  data-employee-name="<?= h($r['employee_name']) ?>"
  data-courses='<?= h(json_encode($courses)) ?>'
  data-course-dates='<?= h($r['course_dates']) ?>'
  data-comment="<?= h($r['comment']) ?>"
  data-status="<?= h($r['status']) ?>"
  data-created-at="<?= h($r['created_at']) ?>"
  data-updated-at="<?= h($r['updated_at'] ?? '') ?>"
  data-closed-by="<?= h($r['closed_by'] ?? '') ?>"
>
  View
</button>

        </td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>

<?php if ($totalPages > 1): ?>
<div class="bg-gray-50 border-t border-gray-200 px-6 py-4 flex items-center justify-between text-sm">
  <div class="text-gray-600">Page <?= $page ?> of <?= $totalPages ?> (<?= $totalCount ?> total)</div>
  <div class="space-x-2">
    <?php if ($page > 1): ?>
      <button class="ajax-page px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-100" data-page="<?= $page - 1 ?>">Previous</button>
    <?php endif; ?>
    <?php if ($page < $totalPages): ?>
      <button class="ajax-page px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-100" data-page="<?= $page + 1 ?>">Next</button>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>