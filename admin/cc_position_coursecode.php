<?php
// =================================================================
// admin/cc_position_coursecode.php
// Manage CC + Position → Course overrides.
// Access via: admin_panel.php?page=cc_position_coursecode
// =================================================================

if (session_status() === PHP_SESSION_NONE) session_start();
ob_start();

require_once __DIR__ . '/../include/db_connect.php';
require_once __DIR__ . '/../include/auth.php';
require_once __DIR__ . '/../include/cc_overrides.php';

if (!isGuest()) {
    requireAdmin();
}

// ── Handle POST actions ──
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add override rows
    if ($action === 'add') {
        $cc  = trim((string)($_POST['cost_center']    ?? ''));
        $pos = trim((string)($_POST['position_match'] ?? ''));
        $raw = trim((string)($_POST['course_codes']   ?? ''));
        $note = trim((string)($_POST['note']          ?? ''));

        if ($cc === '' || $pos === '' || $raw === '') {
            $flash = ['type' => 'error', 'text' => 'Cost center, position keyword, and at least one course are required.'];
        } else {
            // Split courses by comma/newline, uppercase them, dedupe
            $parts = preg_split('/[\s,]+/', strtoupper($raw), -1, PREG_SPLIT_NO_EMPTY);
            $codes = array_values(array_unique($parts));

            $ins = $pdo->prepare("
                INSERT IGNORE INTO cc_position_course_overrides
                    (cost_center, position_match, course_code, note)
                VALUES (?, ?, ?, ?)
            ");
            $added = 0;
            foreach ($codes as $code) {
                $ins->execute([$cc, $pos, $code, $note ?: null]);
                if ($ins->rowCount() > 0) $added++;
            }
            $flash = ['type' => 'success', 'text' => "Added {$added} override row(s) for {$cc} + '{$pos}'."];
        }
    }

    // Toggle active
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE cc_position_course_overrides SET is_active = 1 - is_active WHERE id = ?")
                ->execute([$id]);
            $flash = ['type' => 'success', 'text' => 'Override state toggled.'];
        }
    }

    // Delete row
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM cc_position_course_overrides WHERE id = ?")->execute([$id]);
            $flash = ['type' => 'success', 'text' => 'Override row deleted.'];
        }
    }
}

// ── Load current rows ──
$rows = $pdo->query("
    SELECT id, cost_center, position_match, course_code, note, is_active, created_at
    FROM cc_position_course_overrides
    ORDER BY cost_center, position_match, course_code
")->fetchAll(PDO::FETCH_ASSOC);

// Group by CC + position for display
$grouped = [];
foreach ($rows as $r) {
    $key = $r['cost_center'] . '||' . $r['position_match'];
    $grouped[$key]['cost_center']    = $r['cost_center'];
    $grouped[$key]['position_match'] = $r['position_match'];
    $grouped[$key]['note']           = $r['note'];
    $grouped[$key]['rows'][]         = $r;
}

// Load current courses for the datalist hint
$all_courses = $pdo->query("SELECT course_code FROM courses WHERE is_active = 1 ORDER BY course_code")
    ->fetchAll(PDO::FETCH_COLUMN);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>CC + Position → Course Overrides</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial;
        }

        .ov-table th,
        .ov-table td {
            border: 1px solid #e5e7eb;
            padding: 8px 10px;
            vertical-align: middle;
        }

        .ov-table thead th {
            background: #1e3a8a;
            color: #fff;
            font-weight: 600;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #dbeafe;
            color: #1e40af;
            padding: 2px 8px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 600;
            margin: 2px;
        }
    </style>
</head>

<body class="bg-gray-100 min-h-screen">

    <div class="max-w-6xl mx-auto p-6">

        <div class="flex items-center justify-between flex-wrap gap-3 mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">CC + Position → Course Overrides</h1>
                <p class="text-sm text-gray-500 mt-1">
                    Force specific courses to be <strong>required</strong> for employees whose cost center matches and whose position contains the keyword below — even if <code>cc_course_requirements</code> has no entry.
                </p>
            </div>
            <a href="admin_panel.php?page=dashboard" class="text-sm text-blue-700 hover:underline">← Back to dashboard</a>
        </div>

        <?php if ($flash): ?>
            <div class="mb-4 p-3 rounded <?= $flash['type'] === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                <?= htmlspecialchars($flash['text']) ?>
            </div>
        <?php endif; ?>

        <!-- Add form -->
        <div class="bg-white rounded-xl shadow p-5 mb-6">
            <h2 class="font-bold text-gray-700 mb-3">Add new override</h2>
            <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                <input type="hidden" name="action" value="add">

                <div>
                    <label class="block text-xs uppercase text-gray-500 font-semibold mb-1">Cost Center</label>
                    <input name="cost_center" required placeholder="e.g. MROMG413"
                        class="w-full px-3 py-2 border rounded-lg text-sm font-mono focus:ring-2 focus:ring-blue-300">
                </div>

                <div>
                    <label class="block text-xs uppercase text-gray-500 font-semibold mb-1">Position contains</label>
                    <input name="position_match" required placeholder="e.g. INSPECTOR"
                        class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-300">
                </div>

                <div>
                    <label class="block text-xs uppercase text-gray-500 font-semibold mb-1">Course codes (comma-separated)</label>
                    <input name="course_codes" required placeholder="e.g. EWIS, FTS, IP, DGR"
                        list="course-code-list"
                        class="w-full px-3 py-2 border rounded-lg text-sm font-mono focus:ring-2 focus:ring-blue-300">
                    <datalist id="course-code-list">
                        <?php foreach ($all_courses as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div>
                    <label class="block text-xs uppercase text-gray-500 font-semibold mb-1">Note (optional)</label>
                    <input name="note" placeholder="Reason / reference"
                        class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-300">
                </div>

                <div class="md:col-span-4">
                    <button type="submit"
                        class="bg-blue-700 hover:bg-blue-800 text-white px-5 py-2 rounded-lg text-sm font-semibold">
                        ➕ Add Override
                    </button>
                </div>
            </form>
            <p class="text-xs text-gray-500 mt-3">
                <strong>Tip:</strong> Course codes are matched exactly against <code>courses.course_code</code>. Position is a case-insensitive substring match.
            </p>
        </div>

        <!-- Existing rules -->
        <div class="bg-white rounded-xl shadow p-5">
            <h2 class="font-bold text-gray-700 mb-3">Active overrides</h2>

            <?php if (empty($grouped)): ?>
                <p class="text-gray-500 text-sm">No overrides configured yet.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="ov-table w-full text-sm">
                        <thead>
                            <tr>
                                <th class="text-left">Cost Center</th>
                                <th class="text-left">Position contains</th>
                                <th class="text-left">Required courses</th>
                                <th class="text-left">Note</th>
                                <th style="width:120px;">Status</th>
                                <th style="width:120px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grouped as $g): ?>
                                <tr>
                                    <td class="font-mono font-semibold"><?= htmlspecialchars($g['cost_center']) ?></td>
                                    <td><?= htmlspecialchars($g['position_match']) ?></td>
                                    <td>
                                        <?php foreach ($g['rows'] as $row): ?>
                                            <span class="chip"><?= htmlspecialchars($row['course_code']) ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                    <td class="text-gray-600 text-xs"><?= htmlspecialchars((string)$g['note']) ?></td>
                                    <td>
                                        <?php
                                        $all_active = true;
                                        foreach ($g['rows'] as $r) if (!(int)$r['is_active']) {
                                            $all_active = false;
                                            break;
                                        }
                                        ?>
                                        <span class="<?= $all_active ? 'text-green-700' : 'text-gray-400' ?> font-semibold">
                                            <?= $all_active ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td class="text-right whitespace-nowrap">
                                        <?php foreach ($g['rows'] as $row): ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit"
                                                    class="text-xs <?= (int)$row['is_active'] ? 'text-amber-600 hover:text-amber-800' : 'text-green-600 hover:text-green-800' ?> font-semibold mr-2"
                                                    title="Toggle course <?= htmlspecialchars($row['course_code']) ?>">
                                                    <?= (int)$row['is_active'] ? 'Disable' : 'Enable' ?> <?= htmlspecialchars($row['course_code']) ?>
                                                </button>
                                            </form>
                                        <?php endforeach; ?>
                                        <?php foreach ($g['rows'] as $row): ?>
                                            <form method="POST" class="inline" onsubmit="return confirm('Delete <?= htmlspecialchars($row['course_code']) ?> from this rule?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="text-xs text-red-600 hover:text-red-800 font-semibold">
                                                    🗑️
                                                </button>
                                            </form>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="mt-6 bg-blue-50 border border-blue-200 rounded-xl p-4 text-sm text-blue-900">
            <strong>How this page interacts with other pages:</strong>
            <ul class="list-disc pl-5 mt-2 space-y-1">
                <li><code>index.php</code>, <code>dashboard.php</code>, <code>department_gap_report.php</code>, and <code>print_pdf.php</code> all call <code>is_employee_eligible_for_course()</code>.</li>
                <li>That function now checks these overrides <strong>before</strong> consulting <code>cc_course_requirements</code>.</li>
                <li>So if you add <code>MROMG413 + INSPECTOR → EWIS, FTS, IP, DGR</code> here, employees with an INSPECTOR position in MROMG413 will see those courses as applicable — everywhere.</li>
            </ul>
        </div>

    </div>

</body>

</html>