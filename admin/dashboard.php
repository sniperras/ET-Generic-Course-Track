<?php

if (session_status() === PHP_SESSION_NONE) session_start();
ob_start();

require_once __DIR__ . '/../include/db_connect.php';
require_once __DIR__ . '/../include/auth.php';
requireAdmin();

// ---------------- CONFIG ----------------
$CACHE_TTL = 300;          // seconds
$TOP_PIE_COUNT = 8;
$TOP_DEPT_COUNT = 12;
$DEFAULT_TO_BE_DAYS = 90;
$DRILL_PAGE_SIZE = 50;

// ---------------- Helpers ----------------
function cache_get_path($key) {
    $safe = preg_replace('/[^a-z0-9_\-]/i', '_', $key);
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . "gct_cache_{$safe}.bin";
}
function cache_get($key, $ttl_seconds) {
    $p = cache_get_path($key);
    if (!file_exists($p)) return null;
    if (filemtime($p) + $ttl_seconds < time()) { @unlink($p); return null; }
    $data = @file_get_contents($p);
    return $data === false ? null : unserialize($data);
}
function cache_set($key, $value) {
    $p = cache_get_path($key);
    @file_put_contents($p, serialize($value), LOCK_EX);
}
function safe_table($t) { return preg_match('/^course_records_[a-zA-Z0-9_]+$/', $t); }

// ---------------- Read to_be_days ----------------
if (isset($_GET['to_be_days'])) {
    $to_be_days = max(0, (int)$_GET['to_be_days']);
    $_SESSION['to_be_days'] = $to_be_days;
} elseif (isset($_POST['to_be_days'])) {
    $to_be_days = max(0, (int)$_POST['to_be_days']);
    $_SESSION['to_be_days'] = $to_be_days;
} else {
    $to_be_days = $_SESSION['to_be_days'] ?? $DEFAULT_TO_BE_DAYS;
}

// ---------------- AJAX: drill ----------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'course_employees') {
    header('Content-Type: application/json; charset=utf-8');
    $course_id = (int)($_GET['course_id'] ?? 0);
    $type = $_GET['type'] ?? 'initial';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = $DRILL_PAGE_SIZE;
    $offset = ($page-1)*$limit;
    $to_be = max(0, (int)($_GET['to_be_days'] ?? $to_be_days));

    $stmt = $pdo->prepare("SELECT course_code, course_name, COALESCE(validity_months,24) AS validity_months, table_name FROM courses WHERE course_id=? AND is_active=1 LIMIT 1");
    $stmt->execute([$course_id]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$course) { echo json_encode(['ok'=>false,'error'=>'Course not found']); exit; }

    $tbl = $course['table_name']; $valid = (int)$course['validity_months'];
    if (!safe_table($tbl)) { echo json_encode(['ok'=>false,'error'=>'Invalid course table']); exit; }

    try {
        if ($type==='initial') {
            $sql_count = "SELECT COUNT(*) FROM employees e LEFT JOIN `{$tbl}` t ON e.employee_id=t.ID WHERE e.is_active=1 AND (t.ID IS NULL OR t.CompletedDate IS NULL OR t.CompletedDate='')";
            $sql_page = "SELECT e.employee_id, e.full_name, e.cost_center, e.position, e.department, t.CompletedDate, t.Status
                         FROM employees e LEFT JOIN `{$tbl}` t ON e.employee_id=t.ID
                         WHERE e.is_active=1 AND (t.ID IS NULL OR t.CompletedDate IS NULL OR t.CompletedDate='')
                         ORDER BY e.cost_center, e.employee_id LIMIT {$limit} OFFSET {$offset}";
        } elseif ($type==='expired') {
            $sql_count = "SELECT COUNT(*) FROM `{$tbl}` t JOIN employees e ON e.employee_id=t.ID
                          WHERE e.is_active=1 AND t.CompletedDate IS NOT NULL AND DATE_ADD(t.CompletedDate, INTERVAL {$valid} MONTH)<CURDATE()";
            $sql_page = "SELECT t.ID AS employee_id, e.full_name, e.cost_center, e.position, e.department, t.CompletedDate, t.Status
                         FROM `{$tbl}` t JOIN employees e ON e.employee_id=t.ID
                         WHERE e.is_active=1 AND t.CompletedDate IS NOT NULL AND DATE_ADD(t.CompletedDate, INTERVAL {$valid} MONTH)<CURDATE()
                         ORDER BY e.cost_center, t.ID LIMIT {$limit} OFFSET {$offset}";
        } else { // to-be
            $sql_count = "SELECT COUNT(*) FROM `{$tbl}` t JOIN employees e ON e.employee_id=t.ID
                          WHERE e.is_active=1 AND t.CompletedDate IS NOT NULL
                          AND DATE_ADD(t.CompletedDate, INTERVAL {$valid} MONTH) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL {$to_be} DAY)";
            $sql_page = "SELECT t.ID AS employee_id, e.full_name, e.cost_center, e.position, e.department, t.CompletedDate, t.Status
                         FROM `{$tbl}` t JOIN employees e ON e.employee_id=t.ID
                         WHERE e.is_active=1 AND t.CompletedDate IS NOT NULL
                         AND DATE_ADD(t.CompletedDate, INTERVAL {$valid} MONTH) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL {$to_be} DAY)
                         ORDER BY e.cost_center, t.ID LIMIT {$limit} OFFSET {$offset}";
        }

        $total = (int)$pdo->query($sql_count)->fetchColumn();
        $rows = $pdo->query($sql_page)->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok'=>true,
            'course'=>['id'=>$course_id,'code'=>$course['course_code'],'name'=>$course['course_name']],
            'type'=>$type,'to_be_days'=>$to_be,'total'=>$total,'page'=>$page,'page_size'=>$limit,'rows'=>$rows
        ]);
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// ---------------- CSV export handler ----------------
if (isset($_GET['export_course'])) {
    $course_id = (int)($_GET['export_course'] ?? 0);
    $type = $_GET['etype'] ?? 'initial';
    $to_be = max(0,(int)($_GET['to_be_days'] ?? $to_be_days));

    $stmt = $pdo->prepare("SELECT course_code, course_name, COALESCE(validity_months,24) AS validity_months, table_name FROM courses WHERE course_id=? LIMIT 1");
    $stmt->execute([$course_id]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$course || !safe_table($course['table_name'])) { header('HTTP/1.1 400 Bad Request'); echo "Invalid course"; exit; }

    $tbl = $course['table_name']; $valid = (int)$course['validity_months'];

    if ($type==='initial') {
        $sql = "SELECT e.employee_id, e.full_name, e.cost_center, e.position, e.department, t.CompletedDate, t.Status
                FROM employees e LEFT JOIN `{$tbl}` t ON e.employee_id=t.ID
                WHERE e.is_active=1 AND (t.ID IS NULL OR t.CompletedDate IS NULL OR t.CompletedDate='') ORDER BY e.cost_center, e.employee_id";
    } elseif ($type==='expired') {
        $sql = "SELECT t.ID AS employee_id, e.full_name, e.cost_center, e.position, e.department, t.CompletedDate, t.Status
                FROM `{$tbl}` t JOIN employees e ON e.employee_id=t.ID
                WHERE e.is_active=1 AND t.CompletedDate IS NOT NULL AND DATE_ADD(t.CompletedDate, INTERVAL {$valid} MONTH)<CURDATE()
                ORDER BY e.cost_center, t.ID";
    } else {
        $sql = "SELECT t.ID AS employee_id, e.full_name, e.cost_center, e.position, e.department, t.CompletedDate, t.Status
                FROM `{$tbl}` t JOIN employees e ON e.employee_id=t.ID
                WHERE e.is_active=1 AND t.CompletedDate IS NOT NULL
                  AND DATE_ADD(t.CompletedDate, INTERVAL {$valid} MONTH) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL {$to_be} DAY)
                ORDER BY e.cost_center, t.ID";
    }

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $filename = "{$course['course_code']}_{$type}_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    if (ob_get_level()) ob_end_clean();
    $out = fopen('php://output','w');
    fputcsv($out, ['employee_id','full_name','cost_center','position','department','CompletedDate','Status']);
    foreach ($rows as $r) fputcsv($out, [$r['employee_id'],$r['full_name'],$r['cost_center'],$r['position'],$r['department'],$r['CompletedDate'] ?? '',$r['Status'] ?? '']);
    fclose($out); exit;
}

// ---------------- MAIN PAGE: compute aggregated stats (cached) ----------------
$cache_key = "dashboard_stats_tobed_{$to_be_days}";
$cached = cache_get($cache_key, $CACHE_TTL);

if ($cached !== null) {
    // Use cached data
    $course_stats = $cached['course_stats'];
    $dept_gaps = $cached['dept_gaps'];
    $total_gap = $cached['total_gap'];
    $month_counts = $cached['month_counts'];
    $months = $cached['months'];
    $total_active_employees = $cached['total_active_employees'];
} else {
    // Calculate fresh data with optimized approach
    $course_stats = [];
    $dept_gaps = [];
    $total_gap = 0;
    
    // Get all active employees once
    $active_employees_result = $pdo->query("SELECT employee_id, cost_center FROM employees WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    $active_employees = $active_employees_result;
    $total_active_employees = count($active_employees_result);
    
    // Group employees by cost center for quick lookup
    $employees_by_cc = [];
    foreach ($active_employees as $emp) {
        $cc = $emp['cost_center'];
        if (!isset($employees_by_cc[$cc])) {
            $employees_by_cc[$cc] = [];
        }
        $employees_by_cc[$cc][] = $emp['employee_id'];
    }
    
    // Get all courses
    $courses = $pdo->query("SELECT course_id, course_code, course_name, COALESCE(validity_months,24) AS validity_months, table_name
                            FROM courses WHERE is_active=1 AND table_name IS NOT NULL ORDER BY course_code")->fetchAll(PDO::FETCH_ASSOC);
    
    // Pre-calculate month range for monthly stats
    $months = [];
    $month_counts = [];
    $now = new DateTime();
    for($i = 11; $i >= 0; $i--){ 
        $m = (clone $now)->modify("-{$i} months"); 
        $ym = $m->format('Y-m'); 
        $months[] = $ym; 
        $month_counts[$ym] = 0; 
    }
    
    // Process each course
    foreach ($courses as $c) {
        $cid = (int)$c['course_id'];
        $tbl = $c['table_name'];
        $valid = (int)$c['validity_months'];
        
        if (!safe_table($tbl)) continue;
        
        // OPTIMIZATION: Use EXISTS subqueries instead of complex CASE statements
        // Get all employees who have completed this course
        $completed_employees = [];
        $completion_dates = [];
        
        $stmt = $pdo->query("SELECT ID, CompletedDate FROM `{$tbl}` WHERE CompletedDate IS NOT NULL AND CompletedDate != ''");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $completed_employees[$row['ID']] = true;
            if ($row['CompletedDate']) {
                $completion_dates[$row['ID']] = $row['CompletedDate'];
            }
        }
        
        // Calculate counts
        $initial = 0;
        $expired = 0;
        $tobe = 0;
        
        // For each active employee
        foreach ($active_employees as $emp) {
            $emp_id = $emp['employee_id'];
            
            if (!isset($completed_employees[$emp_id])) {
                // Employee hasn't taken the course
                $initial++;
                continue;
            }
            
            // Employee has taken the course, check expiry
            $completion_date = $completion_dates[$emp_id] ?? null;
            if (!$completion_date) {
                $initial++;
                continue;
            }
            
            // Parse completion date
            $completion_dt = DateTime::createFromFormat('Y-m-d', $completion_date);
            if (!$completion_dt) {
                // Try other formats
                $completion_dt = date_create($completion_date);
            }
            
            if (!$completion_dt) {
                $initial++;
                continue;
            }
            
            $expiry_dt = clone $completion_dt;
            $expiry_dt->modify("+{$valid} months");
            $today = new DateTime();
            
            if ($expiry_dt < $today) {
                $expired++;
            } else {
                $days_to_expiry = (int)$today->diff($expiry_dt)->days;
                if ($days_to_expiry <= $to_be_days) {
                    $tobe++;
                }
            }
        }
        
        $total = $initial + $expired + $tobe;
        $course_stats[$cid] = [
            'course_code' => $c['course_code'],
            'course_name' => $c['course_name'],
            'initial' => $initial,
            'expired' => $expired,
            'tobe' => $tobe,
            'total' => $total
        ];
        $total_gap += $total;
        
        // Update department gaps
        foreach ($employees_by_cc as $cc => $emp_ids) {
            if (!isset($dept_gaps[$cc])) {
                $dept_gaps[$cc] = 0;
            }
            
            // For this department, calculate how many employees have gaps
            $dept_gap = 0;
            foreach ($emp_ids as $emp_id) {
                if (!isset($completed_employees[$emp_id])) {
                    $dept_gap++;
                } else {
                    $completion_date = $completion_dates[$emp_id] ?? null;
                    if (!$completion_date) {
                        $dept_gap++;
                        continue;
                    }
                    
                    $completion_dt = DateTime::createFromFormat('Y-m-d', $completion_date);
                    if (!$completion_dt) {
                        $dept_gap++;
                        continue;
                    }
                    
                    $expiry_dt = clone $completion_dt;
                    $expiry_dt->modify("+{$valid} months");
                    $today = new DateTime();
                    
                    if ($expiry_dt < $today || ($expiry_dt >= $today && (int)$today->diff($expiry_dt)->days <= $to_be_days)) {
                        $dept_gap++;
                    }
                }
            }
            
            $dept_gaps[$cc] += $dept_gap;
        }
        
        // Get monthly completion stats for this course
        $month_stmt = $pdo->query("SELECT DATE_FORMAT(CompletedDate,'%Y-%m') AS ym, COUNT(*) AS cnt 
                                   FROM `{$tbl}` 
                                   WHERE CompletedDate IS NOT NULL 
                                   AND CompletedDate >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH),'%Y-%m-01') 
                                   GROUP BY ym");
        while ($month_row = $month_stmt->fetch(PDO::FETCH_ASSOC)) {
            if (isset($month_counts[$month_row['ym']])) {
                $month_counts[$month_row['ym']] += (int)$month_row['cnt'];
            }
        }
    }
    
    // Sort department gaps
    arsort($dept_gaps);
    
    // Cache the results
    cache_set($cache_key, [
        'course_stats' => $course_stats,
        'dept_gaps' => $dept_gaps,
        'total_gap' => $total_gap,
        'month_counts' => $month_counts,
        'months' => $months,
        'total_active_employees' => $total_active_employees
    ]);
}

// ---------------- Prepare JS arrays ----------------
$course_totals = [];
foreach($course_stats as $k => $v) {
    $course_totals[$k] = $v['total'];
}
arsort($course_totals);

$pie_labels = [];
$pie_values = [];
$other = 0;
$count = 0;

foreach($course_totals as $cid => $val) { 
    $count++; 
    if($count <= $TOP_PIE_COUNT) {
        $pie_labels[] = $course_stats[$cid]['course_code'];
        $pie_values[] = $val;
    } else {
        $other += $val;
    }
}

if($other > 0) {
    $pie_labels[] = 'Other';
    $pie_values[] = $other;
}

$js_pie_labels = json_encode($pie_labels);
$js_pie_values = json_encode($pie_values);

$top_dept_labels = array_slice(array_keys($dept_gaps), 0, $TOP_DEPT_COUNT);
$top_dept_values = array_map(fn($k) => $dept_gaps[$k], $top_dept_labels);

$js_bar_labels = json_encode($top_dept_labels);
$js_bar_values = json_encode($top_dept_values);
$js_line_labels = json_encode($months);
$js_line_values = json_encode(array_map(fn($m) => $month_counts[$m] ?? 0, $months));

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Dashboard — GenericCourseTrack (Stats)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-100">
<div class="max-w-7xl mx-auto p-6">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">Generic Course Follow-up — Dashboard</h1>
        <form method="GET" class="flex items-center gap-2">
            <label class="text-sm text-gray-600">To-be-expire window (days):</label>
            <input type="number" name="to_be_days" min="0" value="<?= htmlspecialchars($to_be_days) ?>" class="w-24 px-2 py-1 border rounded">
            <button class="bg-blue-600 text-white px-3 py-1 rounded">Apply</button>
        </form>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-white p-6 rounded-xl shadow">
            <h3 class="text-sm uppercase text-gray-500">Total active employees</h3>
            <p class="text-4xl font-bold text-blue-700"><?= number_format($total_active_employees ?? 0) ?></p>
        </div>
        <div class="bg-white p-6 rounded-xl shadow">
            <h3 class="text-sm uppercase text-gray-500">Active courses</h3>
            <p class="text-4xl font-bold text-emerald-600"><?= number_format(count($course_stats)) ?></p>
        </div>
        <div class="bg-white p-6 rounded-xl shadow">
            <h3 class="text-sm uppercase text-gray-500">Total GAP</h3>
            <p class="text-4xl font-bold text-red-600"><?= number_format(array_sum(array_column($course_stats,'total'))) ?></p>
            <p class="text-xs text-gray-500">Initial + Expired + To-be (<?= $to_be_days ?> days)</p>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="bg-white p-4 rounded-xl shadow">
            <h3 class="font-bold mb-2">GAP by course</h3>
            <canvas id="pieChart"></canvas>
        </div>
        <div class="bg-white p-4 rounded-xl shadow">
            <h3 class="font-bold mb-2">Top cost centers by GAP</h3>
            <canvas id="barChart"></canvas>
        </div>
        <div class="bg-white p-4 rounded-xl shadow">
            <h3 class="font-bold mb-2">Completed Dates (last 12 months)</h3>
            <canvas id="lineChart"></canvas>
        </div>
    </div>

    <div class="mt-6 bg-white p-4 rounded-xl shadow">
        <h3 class="font-bold mb-3">Courses table</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="p-2">Course</th>
                        <th class="p-2 text-center">Initial</th>
                        <th class="p-2 text-center">Expired</th>
                        <th class="p-2 text-center">To be (<?= $to_be_days ?>d)</th>
                        <th class="p-2 text-center">Total</th>
                        <th class="p-2 text-center">Export</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($course_stats as $cid => $cs): ?>
                        <tr class="border-b">
                            <td class="p-2"><?= htmlspecialchars($cs['course_code'].' - '.$cs['course_name']) ?></td>
                            <td class="p-2 text-center"><?= number_format($cs['initial']) ?></td>
                            <td class="p-2 text-center"><?= number_format($cs['expired']) ?></td>
                            <td class="p-2 text-center"><?= number_format($cs['tobe']) ?></td>
                            <td class="p-2 text-center font-bold text-red-600"><?= number_format($cs['total']) ?></td>
                            <td class="p-2 text-center">
                                <a href="dashboard.php?export_course=<?= $cid ?>&etype=initial&to_be_days=<?= $to_be_days ?>" class="text-sm text-blue-600 underline">Initial CSV</a> ·
                                <a href="dashboard.php?export_course=<?= $cid ?>&etype=expired&to_be_days=<?= $to_be_days ?>" class="text-sm text-blue-600 underline">Expired CSV</a> ·
                                <a href="dashboard.php?export_course=<?= $cid ?>&etype=tobe&to_be_days=<?= $to_be_days ?>" class="text-sm text-blue-600 underline">ToBe CSV</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const pieLabels = <?= $js_pie_labels ?>;
const pieValues = <?= $js_pie_values ?>;
const barLabels = <?= $js_bar_labels ?>;
const barValues = <?= $js_bar_values ?>;
const lineLabels = <?= $js_line_labels ?>;
const lineValues = <?= $js_line_values ?>;
const toBeDays = <?= (int)$to_be_days ?>;

new Chart(document.getElementById('pieChart').getContext('2d'), {
    type:'pie',
    data:{ labels: pieLabels, datasets:[{ data: pieValues, backgroundColor: pieLabels.map((_,i)=>['#1f77b4','#ff7f0e','#2ca02c','#d62728','#9467bd','#8c564b','#e377c2','#7f7f7f','#bcbd22','#17becf'][i%10]) }]},
    options:{ responsive:true, plugins:{legend:{position:'bottom'}}, onClick(e, items){
        if(!items.length) return;
        const idx = items[0].index; const code = pieLabels[idx];
        const COURSE_MAP = { <?php foreach($course_stats as $cid=>$cs){ echo "'".addslashes($cs['course_code'])."':".$cid.","; } ?> };
        if(!COURSE_MAP[code]){ alert('No mapping for '+code); return; }
        const cid = COURSE_MAP[code];
        window.open(`dashboard.php?ajax=course_employees&course_id=${cid}&type=initial&to_be_days=${toBeDays}`,'_blank');
    }}
});

new Chart(document.getElementById('barChart').getContext('2d'), {
    type:'bar', data:{ labels: barLabels, datasets:[{ label:'GAP', data: barValues, backgroundColor:'#4f46e5' }]},
    options:{ responsive:true, plugins:{legend:{display:false}}, scales:{ y:{beginAtZero:true} } }
});

new Chart(document.getElementById('lineChart').getContext('2d'), {
    type:'line', data:{ labels: lineLabels, datasets:[{ label:'Completed', data: lineValues, borderColor:'#059669', tension:0.3, fill:false, pointRadius:3 }]},
    options:{ responsive:true, scales:{ y:{beginAtZero:true} } }
});
</script>
</body>
</html>