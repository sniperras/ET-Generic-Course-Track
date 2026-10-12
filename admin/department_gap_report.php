<?php
// =================================================================
// admin/department_gap_report.php
// Department × Course Training GAP Report
// - GAP = Expired + To-be-expired (initial NOT counted in GAP)
// - Uses cc_course_requirements + position rules (same as dashboard)
// - Dynamic department columns (from cost_center_departments)
// - Clickable cells/rows/columns/totals → CSV export
// - 5-minute file cache with Force Refresh button
// =================================================================

if (session_status() === PHP_SESSION_NONE) session_start();
ob_start();

require_once __DIR__ . '/../include/db_connect.php';
require_once __DIR__ . '/../include/auth.php';
requireAdmin();

// -----------------------------------------------------------------
// CONFIG
// -----------------------------------------------------------------
$CACHE_TTL = 300;
$NEVER_EXPIRE_COURSES = ['ESD', 'ETOPS', 'FUEL', 'ETTPM'];
const FOREVER_THRESHOLD = 999999;

// -----------------------------------------------------------------
// Helpers (same rules as dashboard)
// -----------------------------------------------------------------
function safe_table($t)
{
    return preg_match('/^course_records_[a-zA-Z0-9_]+$/', $t);
}

function is_course_required($cc_course_map, $cc, $course_code)
{
    if (empty($cc_course_map)) return true;
    if ($cc === null || $cc === '') return false;
    return isset($cc_course_map[$cc][$course_code]);
}

function is_position_eligible($course_code, $position)
{
    $code = strtoupper(trim((string)$course_code));
    $pos  = strtoupper(trim((string)$position));

    // Positive
    if ($code === 'ETTPM') {
        if (!(strpos($pos, 'TEAM LEADER') !== false || strpos($pos, 'MGR') !== false)) return false;
    }
    if ($code === 'SMS-MGR') {
        if (strpos($pos, 'MGR') === false) return false;
    }
    if ($code === 'IP') {
        if (!(strpos($pos, 'TECHNICIAN III') !== false
            || strpos($pos, 'TECHNICIAN IV')  !== false
            || strpos($pos, 'TEAM LEADER')    !== false)) return false;
    }
    // Negative
    if ($code === 'MEL') {
        if (strpos($pos, 'MECHANIC') !== false) return false;
    }
    if ($code === 'DGR' || $code === 'RCAT') {
        if (strpos($pos, 'TEAM LEADER') !== false || strpos($pos, 'MGR') !== false) return false;
    }
    return true;
}

function is_employee_eligible($cc_course_map, $cc, $course_code, $position)
{
    return is_course_required($cc_course_map, $cc, $course_code)
        && is_position_eligible($course_code, $position);
}

// -----------------------------------------------------------------
// Force refresh (POST only — no URL param)
// -----------------------------------------------------------------
$force_refresh = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['force_refresh']));

// -----------------------------------------------------------------
// to_be_days window (default 90 like the public dashboard)
// -----------------------------------------------------------------
if (isset($_GET['to_be_days']))       $to_be_days = max(0, (int)$_GET['to_be_days']);
elseif (isset($_POST['to_be_days']))  $to_be_days = max(0, (int)$_POST['to_be_days']);
else                                  $to_be_days = (int)($_SESSION['dept_gap_to_be_days'] ?? 90);
$_SESSION['dept_gap_to_be_days'] = $to_be_days;

// -----------------------------------------------------------------
// Load CC → Department map
// -----------------------------------------------------------------
$cc_dept_map = [];   // [cc] = dept
$departments = [];   // list of unique departments (sorted)
try {
    $rows = $pdo->query("SELECT cost_center, department FROM cost_center_departments")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $cc_dept_map[trim($r['cost_center'])] = trim($r['department']);
    }
    $departments = array_values(array_unique(array_values($cc_dept_map)));
    sort($departments);
} catch (Exception $e) {
    $cc_dept_map = [];
    $departments = [];
}

// -----------------------------------------------------------------
// Load CC → Course map
// -----------------------------------------------------------------
$cc_course_map = [];
try {
    $map_rows = $pdo->query("SELECT cost_center, course_code FROM cc_course_requirements")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($map_rows as $r) {
        $cc_course_map[trim($r['cost_center'])][trim($r['course_code'])] = true;
    }
} catch (Exception $e) {
    $cc_course_map = [];
}

// -----------------------------------------------------------------
// Load active courses
// -----------------------------------------------------------------
$courses = $pdo->query("
    SELECT course_id, course_code, course_name,
           COALESCE(validity_months,24) AS validity_months,
           TRIM(table_name) AS table_name
    FROM courses
    WHERE is_active = 1 AND table_name IS NOT NULL
    ORDER BY course_code
")->fetchAll(PDO::FETCH_ASSOC);

// -----------------------------------------------------------------
// Load active employees (only those with a mapped cost center)
// -----------------------------------------------------------------
$employees = [];
$unmapped_ccs = [];
foreach ($pdo->query("SELECT employee_id, full_name, position, cost_center FROM employees WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC) as $e) {
    $cc = trim((string)$e['cost_center']);
    if ($cc === '') continue;
    if (!isset($cc_dept_map[$cc])) {
        $unmapped_ccs[$cc] = ($unmapped_ccs[$cc] ?? 0) + 1;
        continue; // skip unmapped employees for the report
    }
    $e['cost_center'] = $cc;
    $e['department']  = $cc_dept_map[$cc];
    $employees[] = $e;
}

// =================================================================
// EXPORT HANDLERS (must run before any HTML output)
// =================================================================
if (isset($_GET['export'])) {

    // Build matrix data (no cache read for exports — always fresh)
    $matrix = build_matrix($pdo, $courses, $employees, $cc_course_map, $to_be_days);

    $scope = $_GET['export'];      // cell | course | dept | total
    $code  = $_GET['course'] ?? ''; // course_code
    $dept  = $_GET['dept']   ?? ''; // department

    $filename_parts = ['dept_gap'];
    $rows_to_export = [];

    // Common headers
    $headers = [
        'Course Code',
        'Course Name',
        'Employee ID',
        'Employee Name',
        'Cost Center',
        'Department',
        'Position',
        'Completed Date',
        'Expiry Date',
        'Gap Type'
    ];

    if ($scope === 'cell' && $code !== '' && $dept !== '') {
        // One course × one department
        $filename_parts[] = preg_replace('/[^A-Za-z0-9_]/', '_', $code);
        $filename_parts[] = preg_replace('/[^A-Za-z0-9_]/', '_', $dept);
        $rows_to_export = $matrix['drilldown'][$code][$dept] ?? [];
    } elseif ($scope === 'course' && $code !== '') {
        // Whole course across all departments
        $filename_parts[] = 'course';
        $filename_parts[] = preg_replace('/[^A-Za-z0-9_]/', '_', $code);
        foreach ($departments as $d) {
            foreach (($matrix['drilldown'][$code][$d] ?? []) as $r) {
                $rows_to_export[] = $r;
            }
        }
    } elseif ($scope === 'dept' && $dept !== '') {
        // Whole department across all courses
        $filename_parts[] = 'dept';
        $filename_parts[] = preg_replace('/[^A-Za-z0-9_]/', '_', $dept);
        foreach ($courses as $c) {
            $cc = trim($c['course_code']);
            foreach (($matrix['drilldown'][$cc][$dept] ?? []) as $r) {
                $rows_to_export[] = $r;
            }
        }
    } elseif ($scope === 'total') {
        // Everything
        $filename_parts[] = 'all';
        foreach ($courses as $c) {
            $cc = trim($c['course_code']);
            foreach ($departments as $d) {
                foreach (($matrix['drilldown'][$cc][$d] ?? []) as $r) {
                    $rows_to_export[] = $r;
                }
            }
        }
    } else {
        header('HTTP/1.1 400 Bad Request');
        echo "Invalid export scope";
        exit;
    }

    // Sort for stable output
    usort($rows_to_export, function ($a, $b) {
        $cmp = strcmp($a[0] . '|' . $a[5], $b[0] . '|' . $b[5]);
        if ($cmp === 0) $cmp = strcmp($a[2], $b[2]);  // course, dept, emp
        return $cmp;
    });

    $filename = implode('_', $filename_parts) . '_' . date('Ymd_His') . '.csv';

    // Clean any buffered output
    while (ob_get_level()) ob_end_clean();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    // BOM for Excel UTF-8 friendliness
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows_to_export as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// =================================================================
// BUILD MATRIX FUNCTION (used by both page and exports)
// =================================================================
function build_matrix($pdo, $courses, $employees, $cc_course_map, $to_be_days)
{
    $matrix = [
        'gap'        => [],   // [course][dept] = count (Expired + To-be)
        'expired'    => [],   // [course][dept] = count
        'tobe'       => [],   // [course][dept] = count
        'initial'    => [],   // [course][dept] = count
        'na'         => [],   // [course][dept] = true if "N/A" (no CC requires it)
        'drilldown'  => [],   // [course][dept][] = row data for CSV
        'dept_totals_gap'     => [],
        'dept_totals_expired' => [],
        'dept_totals_initial' => [],
        'dept_totals_tobe'    => [],
        'grand_gap'           => 0,
        'grand_expired'       => 0,
        'grand_initial'       => 0,
        'grand_tobe'          => 0,
    ];

    // Pre-group employees by department for faster access
    $emp_by_dept = [];
    foreach ($employees as $e) {
        $emp_by_dept[$e['department']][] = $e;
    }

    $today = new DateTime('today');

    foreach ($courses as $c) {
        $course_code = trim($c['course_code']);
        $course_name = $c['course_name'];
        $tbl         = $c['table_name'];
        $valid       = (int)$c['validity_months'];
        $never_expire = in_array($course_code, $GLOBALS['NEVER_EXPIRE_COURSES']) || $valid >= FOREVER_THRESHOLD;

        if (!safe_table($tbl)) continue;

        // Fetch completed data for this course
        $completed = [];
        try {
            $stmt = $pdo->query("SELECT ID, CompletedDate, Status FROM `{$tbl}` WHERE CompletedDate IS NOT NULL AND CompletedDate != '' AND CompletedDate != '0000-00-00'");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $completed[$row['ID']] = $row['CompletedDate'];
            }
        } catch (Exception $e) {
            $completed = [];
        }

        // Determine which departments (well, which CCs) require this course
        $required_ccs_by_dept = [];   // [dept] = true if any CC in dept requires this course
        foreach ($cc_course_map as $cc => $codes) {
            if (!isset($codes[$course_code])) continue;
            $dept = $GLOBALS['cc_dept_map'][$cc] ?? null;
            if ($dept !== null) $required_ccs_by_dept[$dept] = true;
        }

        foreach ($GLOBALS['departments'] as $dept) {
            if (!isset($required_ccs_by_dept[$dept])) {
                $matrix['na'][$course_code][$dept] = true;
                $matrix['gap'][$course_code][$dept]     = 0;
                $matrix['expired'][$course_code][$dept] = 0;
                $matrix['tobe'][$course_code][$dept]    = 0;
                $matrix['initial'][$course_code][$dept] = 0;
                continue;
            }

            $matrix['na'][$course_code][$dept]      = false;
            $matrix['gap'][$course_code][$dept]     = 0;
            $matrix['expired'][$course_code][$dept] = 0;
            $matrix['tobe'][$course_code][$dept]    = 0;
            $matrix['initial'][$course_code][$dept] = 0;

            foreach (($emp_by_dept[$dept] ?? []) as $e) {
                $cc = $e['cost_center'];
                $pos = $e['position'] ?? '';

                if (!is_employee_eligible($cc_course_map, $cc, $course_code, $pos)) continue;

                $emp_id = $e['employee_id'];
                $gap_type = 'ok';
                $expiry_date = null;
                $completed_date = null;

                if (!isset($completed[$emp_id])) {
                    $gap_type = 'initial';
                } else {
                    $completed_date = $completed[$emp_id];
                    $cdt = DateTime::createFromFormat('Y-m-d', $completed_date);
                    if (!$cdt) $cdt = date_create($completed_date);

                    if (!$cdt) {
                        $gap_type = 'initial';
                    } elseif ($never_expire) {
                        $gap_type = 'ok';
                    } else {
                        $expiry = (clone $cdt)->modify("+{$valid} months");
                        $expiry_date = $expiry->format('Y-m-d');
                        if ($expiry < $today) {
                            $gap_type = 'expired';
                        } else {
                            $days = (int)$today->diff($expiry)->days;
                            if ($days <= $to_be_days) $gap_type = 'tobe';
                            else $gap_type = 'ok';
                        }
                    }
                }

                if ($gap_type === 'ok') continue;

                // Bucket the counts
                if ($gap_type === 'expired') {
                    $matrix['expired'][$course_code][$dept]++;
                    $matrix['gap'][$course_code][$dept]++;
                } elseif ($gap_type === 'tobe') {
                    $matrix['tobe'][$course_code][$dept]++;
                    $matrix['gap'][$course_code][$dept]++;
                } elseif ($gap_type === 'initial') {
                    $matrix['initial'][$course_code][$dept]++;
                    // Initial is NOT counted in GAP (per spec)
                }

                // Drilldown row (for CSV)
                $matrix['drilldown'][$course_code][$dept][] = [
                    $course_code,
                    $course_name,
                    $emp_id,
                    $e['full_name'],
                    $cc,
                    $dept,
                    $pos,
                    $completed_date ?? 'N/A',
                    $expiry_date ?? 'N/A',
                    ucfirst($gap_type)
                ];
            }

            // Accumulate department totals
            $matrix['dept_totals_gap'][$dept]     = ($matrix['dept_totals_gap'][$dept] ?? 0)     + $matrix['gap'][$course_code][$dept];
            $matrix['dept_totals_expired'][$dept] = ($matrix['dept_totals_expired'][$dept] ?? 0) + $matrix['expired'][$course_code][$dept];
            $matrix['dept_totals_initial'][$dept] = ($matrix['dept_totals_initial'][$dept] ?? 0) + $matrix['initial'][$course_code][$dept];
            $matrix['dept_totals_tobe'][$dept]    = ($matrix['dept_totals_tobe'][$dept] ?? 0)    + $matrix['tobe'][$course_code][$dept];

            $matrix['grand_gap']     += $matrix['gap'][$course_code][$dept];
            $matrix['grand_expired'] += $matrix['expired'][$course_code][$dept];
            $matrix['grand_initial'] += $matrix['initial'][$course_code][$dept];
            $matrix['grand_tobe']    += $matrix['tobe'][$course_code][$dept];
        }
    }

    return $matrix;
}

// =================================================================
// CACHE
// =================================================================
$cache_key  = "dept_gap_v1_tobed_{$to_be_days}";
$cache_file = sys_get_temp_dir() . "/gct_{$cache_key}.cache";

if ($force_refresh && file_exists($cache_file)) {
    unlink($cache_file);
}

$use_cache = file_exists($cache_file) && (time() - filemtime($cache_file)) < $CACHE_TTL;

if ($use_cache) {
    $cached = unserialize(file_get_contents($cache_file));
    $matrix = $cached['matrix'];
    $last_cache_update = date('H:i:s', filemtime($cache_file));
    $next_cache_update = date('H:i:s', time() + ($CACHE_TTL - (time() - filemtime($cache_file))));
} else {
    $matrix = build_matrix($pdo, $courses, $employees, $cc_course_map, $to_be_days);
    file_put_contents($cache_file, serialize(['matrix' => $matrix]));
    $last_cache_update = 'Just now';
    $next_cache_update = date('H:i:s', time() + $CACHE_TTL);
}

// =================================================================
// PIE CHART DATA — GAP by department
// =================================================================
$pie_labels = [];
$pie_values = [];
foreach ($departments as $d) {
    $val = $matrix['dept_totals_gap'][$d] ?? 0;
    if ($val > 0) {
        $pie_labels[] = $d;
        $pie_values[] = $val;
    }
}
$js_pie_labels = json_encode($pie_labels);
$js_pie_values = json_encode($pie_values);
$js_departments = json_encode($departments);
$js_to_be_days = (int)$to_be_days;

// Build a lookup: [course_code] = course_name
$course_names = [];
foreach ($courses as $c) {
    $course_names[trim($c['course_code'])] = $c['course_name'];
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Department Training GAP Report</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .matrix-table th,
        .matrix-table td {
            border: 1px solid #e5e7eb;
            padding: 6px 10px;
            text-align: center;
            white-space: nowrap;
        }

        .matrix-table thead th {
            background: #1e3a8a;
            color: #fff;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .matrix-table thead th.course-col {
            background: #1e40af;
            text-align: left;
            position: sticky;
            left: 0;
            z-index: 11;
        }

        .matrix-table tbody td.course-col {
            text-align: left;
            font-weight: 500;
            background: #f8fafc;
            position: sticky;
            left: 0;
            z-index: 5;
        }

        .matrix-table tbody tr:hover td {
            background: #eff6ff;
        }

        .matrix-table tbody tr:hover td.course-col {
            background: #dbeafe;
        }

        .cell-clickable {
            cursor: pointer;
            transition: background-color 0.15s;
        }

        .cell-clickable:hover {
            background: #fde68a !important;
            outline: 2px solid #f59e0b;
            outline-offset: -2px;
        }

        .na-cell {
            color: #9ca3af;
            font-style: italic;
            background: #f3f4f6 !important;
        }

        .zero-cell {
            color: #9ca3af;
        }

        .gap-cell {
            font-weight: 700;
            color: #b91c1c;
            background: #fef2f2 !important;
        }

        .tobe-cell {
            font-weight: 700;
            color: #b45309;
            background: #fffbeb !important;
        }

        .ini-cell {
            font-weight: 600;
            color: #a16207;
        }

        .total-col {
            background: #fef9c3 !important;
            font-weight: 700;
            color: #854d0e;
        }

        .total-row td {
            background: #f1f5f9 !important;
            font-weight: 700;
            color: #0f172a;
        }

        .total-row td.course-col {
            background: #e2e8f0 !important;
        }

        .dept-header {
            background: #1d4ed8 !important;
        }

        .clickable-header:hover {
            background: #1e40af !important;
            cursor: pointer;
        }

        .cache-info {
            font-size: 11px;
            color: #6b7280;
        }

        .warn-banner {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            color: #92400e;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 13px;
        }

        /* Table container: allow horizontal scroll for many depts */
        .matrix-wrap {
            overflow: auto;
            max-height: 70vh;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }
    </style>
</head>

<body class="bg-gray-100 min-h-screen">

    <div class="max-w-7xl mx-auto p-6">

        <!-- Header -->
        <div class="flex items-start justify-between mb-4 flex-wrap gap-3">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">MRO Training GAP — by Department</h1>
                <p class="text-sm text-gray-500">
                    GAP = Expired + To-be-expired (within <strong><?= $to_be_days ?></strong> days).
                    Initial (not taken) is shown separately.
                </p>
            </div>
            <div class="flex items-center gap-3">
                <form method="GET" class="flex items-center gap-2" id="toBeForm">
                    <label class="text-sm text-gray-600">To-be window (days):</label>
                    <input type="number" name="to_be_days" min="0"
                        value="<?= htmlspecialchars($to_be_days) ?>"
                        class="w-20 px-2 py-1 border rounded text-sm">
                    <button class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm">Apply</button>
                </form>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="force_refresh" value="1">
                    <input type="hidden" name="to_be_days" value="<?= htmlspecialchars($to_be_days) ?>">
                    <button type="submit"
                        class="bg-orange-500 hover:bg-orange-600 text-white px-3 py-1 rounded text-sm font-medium"
                        onclick="return confirm('Force refresh from the database?');">
                        🔄 Force Refresh
                    </button>
                </form>
            </div>
        </div>

        <div class="cache-info text-right mb-4">
            <?php if ($use_cache): ?>
                Cached (<?= $last_cache_update ?>) — next auto-refresh: <?= $next_cache_update ?>
            <?php else: ?>
                Live data (<?= $last_cache_update ?>)
            <?php endif; ?>
        </div>

        <?php if (!empty($unmapped_ccs)): ?>
            <div class="warn-banner mb-4">
                <strong>⚠️ Unmapped Cost Centers:</strong>
                <?= count($unmapped_ccs) ?> cost center(s) with active employees are missing from
                <code>cost_center_departments</code> — employees in those CCs are excluded from this report.
                <div class="mt-1 font-mono text-xs">
                    <?php foreach ($unmapped_ccs as $cc => $n): ?>
                        <?= htmlspecialchars($cc) ?> (<?= $n ?>),
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Pie chart -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <div class="bg-white p-5 rounded-xl shadow lg:col-span-1">
                <h3 class="font-bold mb-3 text-gray-700">GAP by Department</h3>
                <?php if (empty($pie_values)): ?>
                    <div class="text-center text-gray-400 py-8 text-sm">No GAP data</div>
                <?php else: ?>
                    <canvas id="deptPieChart" style="max-height: 320px;"></canvas>
                <?php endif; ?>
            </div>

            <div class="bg-white p-5 rounded-xl shadow lg:col-span-2">
                <h3 class="font-bold mb-3 text-gray-700">Summary</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="bg-red-50 border border-red-200 rounded p-3">
                        <div class="text-xs uppercase text-red-700">Expired</div>
                        <div class="text-2xl font-bold text-red-700"><?= number_format($matrix['grand_expired']) ?></div>
                    </div>
                    <div class="bg-amber-50 border border-amber-200 rounded p-3">
                        <div class="text-xs uppercase text-amber-700">To-be expired</div>
                        <div class="text-2xl font-bold text-amber-700"><?= number_format($matrix['grand_tobe']) ?></div>
                    </div>
                    <div class="bg-yellow-50 border border-yellow-200 rounded p-3">
                        <div class="text-xs uppercase text-yellow-700">Initial (not taken)</div>
                        <div class="text-2xl font-bold text-yellow-700"><?= number_format($matrix['grand_initial']) ?></div>
                    </div>
                    <div class="bg-blue-50 border border-blue-200 rounded p-3">
                        <div class="text-xs uppercase text-blue-700">Total GAP</div>
                        <div class="text-2xl font-bold text-blue-700"><?= number_format($matrix['grand_gap']) ?></div>
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-3">
                    Total GAP = Expired + To-be. Initial is tracked separately.
                </p>
            </div>
        </div>

        <!-- Matrix table -->
        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                <h3 class="font-bold text-gray-700">Course × Department — Training GAP</h3>
                <a href="?page=department_gap_report&export=total&to_be_days=<?= $to_be_days ?>"
                    class="bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded text-sm font-medium">
                    📥 Export All (GAP)
                </a>
            </div>

            <div class="matrix-wrap">
                <table class="matrix-table w-full text-sm">
                    <thead>
                        <tr>
                            <th class="course-col">Course</th>
                            <?php foreach ($departments as $d): ?>
                                <th class="dept-header clickable-header"
                                    onclick="location.href='?page=department_gap_report&export=dept&dept=<?= urlencode($d) ?>&to_be_days=<?= $to_be_days ?>'"
                                    title="Click to export all <?= htmlspecialchars($d) ?> rows">
                                    Dir. <?= htmlspecialchars($d) ?>
                                </th>
                            <?php endforeach; ?>
                            <th class="total-col clickable-header"
                                onclick="location.href='?page=department_gap_report&export=total&to_be_days=<?= $to_be_days ?>'"
                                title="Click to export all rows">Total GAP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courses as $c):
                            $code = trim($c['course_code']);
                            $row_gap = 0;
                            foreach ($departments as $d) $row_gap += $matrix['gap'][$code][$d] ?? 0;
                        ?>
                            <tr>
                                <td class="course-col"><?= htmlspecialchars($code) ?></td>
                                <?php foreach ($departments as $d):
                                    $is_na = !empty($matrix['na'][$code][$d]);
                                    $gap   = $matrix['gap'][$code][$d] ?? 0;
                                    $exp   = $matrix['expired'][$code][$d] ?? 0;
                                    $tobe  = $matrix['tobe'][$code][$d] ?? 0;
                                    $ini   = $matrix['initial'][$code][$d] ?? 0;
                                ?>
                                    <?php if ($is_na): ?>
                                        <td class="na-cell">N/A</td>
                                    <?php else: ?>
                                        <td class="cell-clickable <?= $gap > 0 ? 'gap-cell' : 'zero-cell' ?>"
                                            onclick="location.href='?page=department_gap_report&export=cell&course=<?= urlencode($code) ?>&dept=<?= urlencode($d) ?>&to_be_days=<?= $to_be_days ?>'"
                                            title="Expired: <?= $exp ?> | To-be: <?= $tobe ?> | Initial: <?= $ini ?> (click to export)">
                                            <?= $gap ?>
                                        </td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <td class="total-col cell-clickable"
                                    onclick="location.href='?page=department_gap_report&export=course&course=<?= urlencode($code) ?>&to_be_days=<?= $to_be_days ?>'"
                                    title="Click to export all <?= htmlspecialchars($code) ?> rows">
                                    <?= $row_gap ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <!-- Section A: Expired / Initial / Total GAP[active] -->
                        <tr class="total-row">
                            <td class="course-col">Expired</td>
                            <?php foreach ($departments as $d): ?>
                                <td><?= number_format($matrix['dept_totals_expired'][$d] ?? 0) ?></td>
                            <?php endforeach; ?>
                            <td class="total-col"><?= number_format($matrix['grand_expired']) ?></td>
                        </tr>
                        <tr class="total-row">
                            <td class="course-col">Initial training not taken</td>
                            <?php foreach ($departments as $d): ?>
                                <td><?= number_format($matrix['dept_totals_initial'][$d] ?? 0) ?></td>
                            <?php endforeach; ?>
                            <td class="total-col"><?= number_format($matrix['grand_initial']) ?></td>
                        </tr>
                        <tr class="total-row">
                            <td class="course-col">Total GAP [active employees]</td>
                            <?php foreach ($departments as $d):
                                $v = ($matrix['dept_totals_expired'][$d] ?? 0) + ($matrix['dept_totals_initial'][$d] ?? 0);
                            ?>
                                <td><?= number_format($v) ?></td>
                            <?php endforeach; ?>
                            <td class="total-col"><?= number_format($matrix['grand_expired'] + $matrix['grand_initial']) ?></td>
                        </tr>

                        <tr>
                            <td colspan="<?= count($departments) + 2 ?>" style="height:12px; background:transparent; border:none;"></td>
                        </tr>

                        <!-- Section B: To-be / Total GAP -->
                        <tr class="total-row">
                            <td class="course-col">To be expired</td>
                            <?php foreach ($departments as $d): ?>
                                <td><?= number_format($matrix['dept_totals_tobe'][$d] ?? 0) ?></td>
                            <?php endforeach; ?>
                            <td class="total-col"><?= number_format($matrix['grand_tobe']) ?></td>
                        </tr>
                        <tr class="total-row">
                            <td class="course-col">Total GAP</td>
                            <?php foreach ($departments as $d):
                                $v = ($matrix['dept_totals_expired'][$d] ?? 0)
                                    + ($matrix['dept_totals_initial'][$d] ?? 0)
                                    + ($matrix['dept_totals_tobe'][$d] ?? 0);
                            ?>
                                <td><?= number_format($v) ?></td>
                            <?php endforeach; ?>
                            <td class="total-col"><?= number_format($matrix['grand_expired'] + $matrix['grand_initial'] + $matrix['grand_tobe']) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-xs text-gray-500 mt-3">
                <strong>Tip:</strong> Click any <em>cell</em>, <em>row total</em>, <em>column header</em>, or the <em>grand total</em> button to export a CSV of exactly that scope.
            </p>
        </div>

    </div>

    <script>
        const pieLabels = <?= $js_pie_labels ?>;
        const pieValues = <?= $js_pie_values ?>;

        if (pieLabels.length > 0) {
            new Chart(document.getElementById('deptPieChart').getContext('2d'), {
                type: 'pie',
                data: {
                    labels: pieLabels,
                    datasets: [{
                        data: pieValues,
                        backgroundColor: [
                            '#1f77b4', '#ff7f0e', '#2ca02c', '#d62728', '#9467bd',
                            '#8c564b', '#e377c2', '#7f7f7f', '#bcbd22', '#17becf',
                            '#f39c12', '#9b59b6', '#1abc9c', '#e74c3c', '#3498db'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                font: {
                                    size: 11
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                    const pct = total ? ((ctx.raw / total) * 100).toFixed(1) : 0;
                                    return `${ctx.label}: ${ctx.raw.toLocaleString()} (${pct}%)`;
                                }
                            }
                        }
                    },
                    onClick(e, items) {
                        if (!items.length) return;
                        const dept = pieLabels[items[0].index];
                        window.location.href = `?page=department_gap_report&export=dept&dept=${encodeURIComponent(dept)}&to_be_days=<?= $to_be_days ?>`;
                    }
                }
            });
        }

        // Preserve to_be_days on the GET form when submitted
        document.getElementById('toBeForm')?.addEventListener('submit', function() {
            // Nothing to add — form already posts to GET with to_be_days
        });
    </script>
</body>

</html>