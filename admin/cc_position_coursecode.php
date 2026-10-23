<?php
// =================================================================
// admin/cc_position_coursecode.php
// Manage CC + Dept + Position → Course overrides (TAKE, SKIP, FOREVER).
// =================================================================

if (session_status() === PHP_SESSION_NONE) session_start();
ob_start();

require_once __DIR__ . '/../include/db_connect.php';
require_once __DIR__ . '/../include/auth.php';
require_once __DIR__ . '/../include/cc_overrides.php';

if (!isGuest()) {
    requireAdmin();
}

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $cc      = trim((string)($_POST['cost_center']    ?? ''));
        $any_cc  = !empty($_POST['any_cc']);
        $dept    = trim((string)($_POST['department']     ?? ''));
        $pos     = trim((string)($_POST['position_match'] ?? ''));
        $raw     = trim((string)($_POST['course_codes']   ?? ''));
        $note    = trim((string)($_POST['note']           ?? ''));
        $rule    = $_POST['rule_type'] ?? 'take';
        
        if (!in_array($rule, ['take', 'skip', 'forever'], true)) $rule = 'take';
        if ($any_cc) $cc = '*';

        // ── Validation ──
        if ($raw === '') {
            $flash = ['type' => 'error', 'text' => 'You must provide at least one course code.'];
        } 
        // Prevent global SKIP/FOREVER rules that hit absolutely everyone
        elseif ($rule !== 'take' && $cc === '*' && $dept === '' && $pos === '') {
            $flash = ['type' => 'error', 'text' => 'Global rules (Any CC + Any Dept + Any Pos) are not allowed for SKIP/FOREVER. Please add at least a Department or Position.'];
        } 
        // Must target at least one thing
        elseif ($cc === '' && $dept === '' && $pos === '') {
            $flash = ['type' => 'error', 'text' => 'You must specify a Cost Center, Department, or Position.'];
        } 
        else {
            $parts = preg_split('/[\s,]+/', strtoupper($raw), -1, PREG_SPLIT_NO_EMPTY);
            $codes = array_values(array_unique($parts));

            $ins = $pdo->prepare("
                INSERT IGNORE INTO cc_position_course_overrides
                    (cost_center, department, position_match, course_code, rule_type, note)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $added = 0;
            foreach ($codes as $code) {
                $ins->execute([$cc, $dept ?: null, $pos, $code, $rule, $note ?: null]);
                if ($ins->rowCount() > 0) $added++;
            }
            $label = strtoupper($rule);
            $flash = ['type' => 'success', 'text' => "Added {$added} {$label} override row(s)."];
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE cc_position_course_overrides SET is_active = 1 - is_active WHERE id = ?")
                ->execute([$id]);
            $flash = ['type' => 'success', 'text' => 'Override state toggled.'];
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM cc_position_course_overrides WHERE id = ?")->execute([$id]);
            $flash = ['type' => 'success', 'text' => 'Override row deleted.'];
        }
    }
}

$rows = $pdo->query("
    SELECT id, cost_center, department, position_match, course_code, rule_type, note, is_active, created_at
    FROM cc_position_course_overrides
    ORDER BY rule_type, cost_center, department, position_match, course_code
")->fetchAll(PDO::FETCH_ASSOC);

$grouped = [];
foreach ($rows as $r) {
    $rt  = $r['rule_type'] ?? 'take';
    if (!in_array($rt, ['take', 'skip', 'forever'], true)) $rt = 'take';
    $key = $rt . '||' . $r['cost_center'] . '||' . ($r['department']??'') . '||' . $r['position_match'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'rule_type'      => $rt,
            'cost_center'    => $r['cost_center'],
            'department'     => $r['department'],
            'position_match' => $r['position_match'],
            'note'           => $r['note'],
            'rows'           => [],
        ];
    }
    $grouped[$key]['rows'][] = $r;
}

$all_courses = $pdo->query("SELECT course_code FROM courses WHERE is_active = 1 ORDER BY course_code")->fetchAll(PDO::FETCH_COLUMN);
$all_depts = $pdo->query("SELECT DISTINCT department FROM cost_center_departments WHERE department IS NOT NULL AND department != '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>CC + Dept + Position → Course Overrides</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial; }
        .ov-table th, .ov-table td { border: 1px solid #e5e7eb; padding: 8px 10px; vertical-align: middle; }
        .ov-table thead th { background: #1e3a8a; color: #fff; font-weight: 600; }
        .chip { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 9999px; font-size: 12px; font-weight: 600; margin: 2px; }
        .chip-take { background: #dcfce7; color: #166534; }
        .chip-skip { background: #fee2e2; color: #991b1b; }
        .chip-forever { background: #e0f2fe; color: #0c4a6e; }
        .rule-badge { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; letter-spacing: .5px; }
        .rule-badge.take { background: #10b981; color: #fff; }
        .rule-badge.skip { background: #dc2626; color: #fff; }
        .rule-badge.forever { background: #0284c7; color: #fff; }
    </style>
</head>

<body class="bg-gray-100 min-h-screen">

    <div class="max-w-7xl mx-auto p-6">

        <div class="flex items-center justify-between flex-wrap gap-3 mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">CC + Dept + Position → Course Overrides</h1>
                <p class="text-sm text-gray-500 mt-1">
                    Force courses to be <strong>required</strong>, <strong>not applicable</strong>, or <strong>never expire</strong> for specific cost centers, departments, or positions.
                </p>
            </div>
            <a href="admin_panel.php?page=dashboard" class="text-sm text-blue-700 hover:underline">← Back to dashboard</a>
        </div>

        <?php if ($flash): ?>
            <div class="mb-4 p-3 rounded <?= $flash['type'] === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                <?= htmlspecialchars($flash['text']) ?>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-xl shadow p-5 mb-6">
            <h2 class="font-bold text-gray-700 mb-3">Add new override</h2>
            <form method="POST" onsubmit="return validateRuleForm(this)" class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
                <input type="hidden" name="action" value="add">

                <div class="md:col-span-2">
                    <label class="block text-xs uppercase text-gray-500 font-semibold mb-1">Cost Center</label>
                    <input name="cost_center" placeholder="e.g. MROMG413"
                        class="w-full px-3 py-2 border rounded-lg text-sm font-mono focus:ring-2 focus:ring-blue-300">
                    <label class="inline-flex items-center gap-1 mt-1 text-xs text-gray-600">
                        <input type="checkbox" name="any_cc" value="1" class="rounded"> Any CC
                    </label>
                </div>

                <div class="md:col-span-1">
                    <label class="block text-xs uppercase text-gray-500 font-semibold mb-1">Department</label>
                    <input name="department" placeholder="e.g. LMT" list="dept-list"
                        class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-300">
                    <datalist id="dept-list">
                        <?php foreach ($all_depts as $d): ?>
                            <option value="<?= htmlspecialchars($d) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="md:col-span-1">
                    <label class="block text-xs uppercase text-gray-500 font-semibold mb-1">Position</label>
                    <input name="position_match" placeholder="e.g. INSPECTOR"
                        class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-300">
                </div>

                <div class="md:col-span-1">
                    <label class="block text-xs uppercase text-gray-500 font-semibold mb-1">Rule type</label>
                    <select name="rule_type" class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-300">
                        <option value="take">FORCE TAKE</option>
                        <option value="skip">FORCE SKIP</option>
                        <option value="forever">FORCE FOREVER</option>
                    </select>
                </div>

                <div class="md:col-span-1">
                    <label class="block text-xs uppercase text-gray-500 font-semibold mb-1">Note</label>
                    <input name="note" placeholder="Optional"
                        class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-300">
                </div>

                <div class="md:col-span-6">
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

                <div class="md:col-span-6 mt-2">
                    <button type="submit" class="bg-blue-700 hover:bg-blue-800 text-white px-5 py-2 rounded-lg text-sm font-semibold">
                        ➕ Add Override
                    </button>
                </div>
            </form>
            
            <div class="text-xs text-gray-600 mt-4 space-y-1 bg-gray-50 p-3 rounded">
                <p><strong>How filtering works:</strong> If you specify multiple filters, an employee must match <strong>all</strong> of them.</p>
                <p>• <strong>Any CC</strong> checkbox + blank Dept + blank Pos → Applies to <strong>everyone</strong> (TAKE only).</p>
                <p>• <strong>Dept = LMT</strong> + blank CC + blank Pos → Applies to <strong>everyone in the LMT department</strong>.</p>
                <p>• <strong>Dept = LMT</strong> + <strong>Pos = INSPECTOR</strong> → Applies to <strong>inspectors in the LMT department</strong>.</p>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-5">
            <h2 class="font-bold text-gray-700 mb-3">Active overrides</h2>

            <?php if (empty($grouped)): ?>
                <p class="text-gray-500 text-sm">No overrides configured yet.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="ov-table w-full text-sm">
                        <thead>
                            <tr>
                                <th class="text-left">Rule</th>
                                <th class="text-left">Cost Center</th>
                                <th class="text-left">Department</th>
                                <th class="text-left">Position</th>
                                <th class="text-left">Courses</th>
                                <th class="text-left">Note</th>
                                <th style="width:120px;">Status</th>
                                <th style="width:120px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grouped as $g):
                                $is_take = $g['rule_type'] === 'take';
                                $is_skip = $g['rule_type'] === 'skip';
                                $is_forever = $g['rule_type'] === 'forever';
                                $chip_class = $is_take ? 'chip-take' : ($is_skip ? 'chip-skip' : 'chip-forever');
                            ?>
                                <tr>
                                    <td>
                                        <span class="rule-badge <?= $g['rule_type'] ?>">
                                            <?= strtoupper($g['rule_type']) ?>
                                        </span>
                                    </td>
                                    <td class="font-mono font-semibold">
                                        <?= $g['cost_center'] === '*' ? '<em class="text-gray-500">(any CC)</em>' : htmlspecialchars($g['cost_center']) ?>
                                    </td>
                                    <td class="font-mono">
                                        <?= empty($g['department']) ? '<em class="text-gray-500">(any dept)</em>' : htmlspecialchars($g['department']) ?>
                                    </td>
                                    <td>
                                        <?= $g['position_match'] === '' ? '<em class="text-gray-500">(any)</em>' : htmlspecialchars($g['position_match']) ?>
                                    </td>
                                    <td>
                                        <?php foreach ($g['rows'] as $row): ?>
                                            <span class="chip <?= $chip_class ?>"><?= htmlspecialchars($row['course_code']) ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                    <td class="text-gray-600 text-xs"><?= htmlspecialchars((string)$g['note']) ?></td>
                                    <td>
                                        <?php
                                        $all_active = true;
                                        foreach ($g['rows'] as $r) if (!(int)$r['is_active']) { $all_active = false; break; }
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
                                                    title="Toggle <?= htmlspecialchars($row['course_code']) ?>">
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

    </div>

    <script>
    function validateRuleForm(form) {
        const anyCc = form.any_cc.checked;
        const cc = form.cost_center.value.trim();
        const dept = form.department.value.trim();
        const pos = form.position_match.value.trim();
        const rule = form.rule_type.value;
        const courses = form.course_codes.value.trim();

        if (courses === '') {
            alert('Please add at least one course.');
            return false;
        }

        // Check if it matches nothing
        if (!anyCc && cc === '' && dept === '' && pos === '') {
            alert('Please specify a Cost Center, Department, or Position.');
            return false;
        }

        // Prevent global SKIP/FOREVER
        if (rule !== 'take') {
            const isGlobal = (anyCc || cc === '') && dept === '' && pos === '';
            if (isGlobal) {
                alert('SKIP and FOREVER rules cannot be applied globally to everyone. Please add a Department or Position.');
                return false;
            }
        }

        return true;
    }
    </script>

</body>

</html>