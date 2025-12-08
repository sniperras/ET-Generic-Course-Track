<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
ob_start();
require_once __DIR__ . '/../include/db_connect.php';

@ini_set('display_errors','0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

const ITEMS_PER_PAGE = 50;

// Admin auth
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit;
    }
    header('Location: admin_login.php');
    exit;
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    ob_end_clean();
    http_response_code(500);
    echo "Database connection not found.";
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrf = $_SESSION['csrf_token'];
$admin_user = $_SESSION['admin_user'] ?? $_SESSION['admin_name'] ?? 'admin';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

ob_end_flush();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin — Training Disputes</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', sans-serif; }
    .table-row:hover { background-color: #f8fafc !important; }
    .status-open { @apply bg-red-100 text-red-800; }
    .status-closed { @apply bg-green-100 text-green-800; }
  </style>
</head>
<body class="bg-gray-50 min-h-screen">

<header class="bg-gradient-to-r from-indigo-600 to-purple-600 text-white shadow-lg">
  <div class="max-w-7xl mx-auto px-6 py-5 flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold">Training Disputes</h1>
      <p class="text-sm opacity-90">Manage and resolve employee disputes</p>
    </div>
    <div class="flex items-center gap-4">
      <form id="searchForm" class="flex">
        <input type="text" name="q" placeholder="Search ID, name, status..." class="px-4 py-2 text-gray-900 rounded-l-lg border-0 focus:outline-none focus:ring-2 focus:ring-purple-500 w-64">
        <button type="submit" class="bg-purple-700 hover:bg-purple-800 px-5 py-2 rounded-r-lg font-medium transition">Search</button>
      </form>
    </div>
  </div>
</header>

<main class="max-w-7xl mx-auto p-6">

  <!-- Status Filter Buttons -->
  <div class="mb-6 flex gap-3 flex-wrap">
    <button type="button" class="status-btn px-6 py-3 rounded-lg font-semibold transition shadow-sm bg-blue-600 text-white" data-status="open">
  Open Disputes
</button>
    <button type="button" class="status-btn px-6 py-3 rounded-lg font-semibold transition shadow-sm" data-status="closed">
      Closed Disputes
    </button>
    <button type="button" class="status-btn px-6 py-3 rounded-lg font-semibold transition shadow-sm bg-blue-600 text-white" data-status="all">
      All Disputes
    </button>
  </div>

  <!-- Table Container -->
  <div id="tableContainer" class="bg-white rounded-xl shadow-lg overflow-hidden border border-gray-200">
    <!-- Will be loaded via AJAX -->
    <div class="p-12 text-center text-gray-500">Loading disputes...</div>
  </div>
</main>

<!-- Modal -->
<div id="modal" class="fixed inset-0 hidden items-center justify-center bg-black/50 z-50 px-4">
  <div class="bg-white rounded-2xl max-w-4xl w-full p-8 shadow-2xl max-h-screen overflow-y-auto">
    <div class="flex justify-between items-start mb-6">
      <div>
        <h2 id="modalTitle" class="text-2xl font-bold text-gray-800"></h2>
        <p id="modalSubtitle" class="text-gray-600 mt-1"></p>
      </div>
      <button id="modalClose" class="text-gray-500 hover:text-gray-700 text-3xl">×</button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
      <div class="space-y-5">
        <div>
          <label class="text-sm font-medium text-gray-600">Employee</label>
          <div id="modalEmployee" class="mt-1 text-lg font-semibold"></div>
        </div>
        <div>
          <label class="text-sm font-medium text-gray-600">Courses</label>
          <div id="modalCourses" class="mt-2 space-y-1 text-gray-700"></div>
        </div>
        <div>
          <label class="text-sm font-medium text-gray-600">Course Dates</label>
          <div id="modalDates" class="mt-2 space-y-1 text-gray-700"></div>
        </div>
      </div>
      <div class="space-y-5">
        <div>
          <label class="text-sm font-medium text-gray-600">Comment</label>
          <div id="modalComment" class="mt-2 p-4 bg-gray-50 rounded-lg text-gray-700 whitespace-pre-wrap"></div>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="text-sm font-medium text-gray-600">Status</label>
            <div id="modalStatus" class="mt-2"></div>
          </div>
          <div>
            <label class="text-sm font-medium text-gray-600">Created</label>
            <div id="modalCreated" class="mt-2 font-mono text-sm"></div>
          </div>
          <div>
            <label class="text-sm font-medium text-gray-600">Updated</label>
            <div id="modalUpdated" class="mt-2 font-mono text-sm"></div>
          </div>
          <div>
            <label class="text-sm font-medium text-gray-600">Closed By</label>
            <div id="modalClosedBy" class="mt-2"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="mt-8 flex justify-end gap-4">
      <button id="toggleStatusBtn" class="px-8 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold rounded-lg shadow hover:shadow-lg transition transform hover:scale-105">
        Mark as Closed
      </button>
    </div>
    <div id="modalMsg" class="mt-4"></div>
  </div>
</div>

<script>
const CSRF = "<?= h($csrf) ?>";
let currentId = null;
let currentRow = null;

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

function bindViewButtons() {
  document.querySelectorAll('.viewBtn').forEach(btn => {
    btn.onclick = function() {
      const d = this.dataset;
      currentId = d.id;
      currentRow = this.closest('tr');

      document.getElementById('modalTitle').textContent = `Dispute #${d.id}`;
      document.getElementById('modalSubtitle').textContent = `Employee ID: ${d.employeeId || '—'}`;
      document.getElementById('modalEmployee').innerHTML = `<strong>${escapeHtml(d.employeeName || '—')}</strong><br><small class="text-gray-500">ID: ${escapeHtml(d.employeeId || '—')}</small>`;

      let courses = [];
      try { courses = JSON.parse(d.courses || '[]'); } catch(e) { courses = [d.courses]; }
      if (!Array.isArray(courses)) courses = [courses];
      document.getElementById('modalCourses').innerHTML = courses.length ? courses.map(c => `• ${escapeHtml(String(c))}`).join('<br>') : '—';

      let dates = [];
      try { dates = JSON.parse(d.courseDates || '[]'); } catch(e) { dates = [d.courseDates]; }
      if (!Array.isArray(dates)) dates = [dates];
      document.getElementById('modalDates').innerHTML = dates.length ? dates.map(d => `• ${escapeHtml(String(d || '—'))}`).join('<br>') : '—';

      document.getElementById('modalComment').textContent = d.comment || 'No comment';
      const isOpen = (d.status || '').toLowerCase() === 'open';
      document.getElementById('modalStatus').innerHTML = `
        <span class="inline-block px-4 py-2 rounded-full text-sm font-bold ${isOpen ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'}">
          ${isOpen ? 'Open' : 'Closed'}
        </span>`;
      document.getElementById('modalCreated').textContent = d.createdAt || '—';
      document.getElementById('modalUpdated').textContent = d.updatedAt || '—';
      document.getElementById('modalClosedBy').textContent = d.closedBy || '—';
      document.getElementById('toggleStatusBtn').textContent = isOpen ? 'Mark as Closed' : 'Re-open';

      document.getElementById('modal').classList.remove('hidden');
      document.getElementById('modal').classList.add('flex');
      document.getElementById('modalMsg').innerHTML = '';
    };
  });
}

function loadTable(page = 1, status = 'all', query = '') {
  const container = document.getElementById('tableContainer');
  const q = query || document.querySelector('input[name="q"]')?.value.trim() || '';

  const params = new URLSearchParams();
  params.append('page', page);
  if (q) params.append('q', q);
  if (status !== 'all') params.append('status', status);

  container.innerHTML = `<div class="p-16 text-center"><div class="inline-block animate-spin rounded-full h-10 w-10 border-4 border-purple-500 border-t-transparent"></div><p class="mt-4 text-gray-600">Loading...</p></div>`;

  fetch(`ajax_load_disputes.php?${params.toString()}`, {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.text())
  .then(html => {
    container.innerHTML = html;
    bindViewButtons();

    // Update active button
    document.querySelectorAll('.status-btn').forEach(b => {
      const isActive = b.dataset.status === status;
      b.classList.toggle('bg-blue-600', isActive);
      b.classList.toggle('text-white', isActive);
      b.classList.toggle('bg-gray-200', !isActive);
      b.classList.toggle('hover:bg-gray-300', !isActive);
      b.classList.toggle('shadow-md', isActive);
    });
  })
  .catch(() => {
    container.innerHTML = '<div class="p-12 text-center text-red-600">Failed to load disputes.</div>';
  });
}

// Events
document.querySelectorAll('.status-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    loadTable(1, btn.dataset.status);
  });
});

document.getElementById('searchForm')?.addEventListener('submit', e => {
  e.preventDefault();
  const activeStatus = document.querySelector('.status-btn.bg-blue-600')?.dataset.status || 'all';
  loadTable(1, activeStatus);
});

document.addEventListener('click', e => {
  const btn = e.target.closest('.ajax-page');
  if (btn) {
    e.preventDefault();
    const activeStatus = document.querySelector('.status-btn.bg-blue-600')?.dataset.status || 'all';
    loadTable(btn.dataset.page, activeStatus);
  }
});

document.getElementById('modalClose')?.addEventListener('click', () => {
  document.getElementById('modal').classList.add('hidden');
});
document.getElementById('modal')?.addEventListener('click', e => {
  if (e.target === e.currentTarget) e.currentTarget.classList.add('hidden');
});

document.getElementById('toggleStatusBtn')?.addEventListener('click', function() {
  if (!currentId) return;
  this.disabled = true;
  this.innerHTML = 'Saving...';

  fetch('ajax_toggle_dispute.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'toggle_status', id: currentId, csrf_token: CSRF })
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      const isClosed = data.new_status.toLowerCase() === 'closed';
      document.getElementById('modalStatus').innerHTML = `<span class="inline-block px-4 py-2 rounded-full text-sm font-bold ${isClosed ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}">${isClosed ? 'Closed' : 'Open'}</span>`;
      document.getElementById('modalUpdated').textContent = data.updated_at || '—';
      document.getElementById('modalClosedBy').textContent = data.closed_by || '—';
      this.textContent = isClosed ? 'Re-open' : 'Mark as Closed';

      if (currentRow) {
        currentRow.cells[3].innerHTML = isClosed
          ? '<span class="px-3 py-1.5 rounded-full text-xs font-bold bg-green-100 text-green-800">Closed</span>'
          : '<span class="px-3 py-1.5 rounded-full text-xs font-bold bg-red-100 text-red-800">Open</span>';
        if (data.updated_at) currentRow.cells[5].textContent = data.updated_at;
        currentRow.cells[6].textContent = data.closed_by || '—';

        const currentFilter = document.querySelector('.status-btn.bg-blue-600')?.dataset.status;
        if (currentFilter === 'open' && isClosed) {
          currentRow.remove();
          if (!document.querySelector('tbody tr')) {
            document.querySelector('tbody').innerHTML = '<tr><td colspan="8" class="p-12 text-center text-gray-500 text-lg">No disputes found.</td></tr>';
          }
        }
      }

      document.getElementById('modalMsg').innerHTML = `<div class="p-4 rounded-lg bg-green-100 text-green-800 font-medium">Status updated successfully!</div>`;
    }
  })
  .finally(() => {
    this.disabled = false;
    this.innerHTML = this.textContent.includes('Re-open') ? 'Re-open' : 'Mark as Closed';
  });
});

// Initial load
loadTable(1, 'open');
</script>

</body>
</html>