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
$DEFAULT_TO_BE_DAYS = 1;
$DRILL_PAGE_SIZE = 50;

// Courses that never expire (validity forever)
$NEVER_EXPIRE_COURSES = ['ESD', 'ETOPS', 'FUEL', 'ETTPM'];

// ---------------- Helpers ----------------
function cache_get_path($key)
{
    $safe = preg_replace('/[^a-z0-9_\-]/i', '_', $key);
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . "gct_cache_{$safe}.bin";
}
function cache_get($key, $ttl_seconds)
{
    $p = cache_get_path($key);
    if (!file_exists($p)) return null;
    if (filemtime($p) + $ttl_seconds < time()) {
        @unlink($p);
        return null;
    }
    $data = @file_get_contents($p);
    return $data === false ? null : unserialize($data);
}
function cache_set($key, $value)
{
    $p = cache_get_path($key);
    @file_put_contents($p, serialize($value), LOCK_EX);
}
function safe_table($t)
{
    return preg_match('/^course_records_[a-zA-Z0-9_]+$/', $t);
}

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

// ---------------- CSV Export for Cost Center (Employee List) ----------------
if (isset($_GET['export_dept_csv'])) {
    $cost_center = $_GET['export_dept_csv'] ?? '';
    $type = $_GET['etype'] ?? 'all'; // all, expired, tobe, initial
    $to_be = max(0, (int)($_GET['to_be_days'] ?? $to_be_days));

    // Get all active employees in this cost center
    $employees = $pdo->prepare("SELECT employee_id, full_name, position, department FROM employees WHERE is_active = 1 AND cost_center = ?");
    $employees->execute([$cost_center]);
    $employees_list = $employees->fetchAll(PDO::FETCH_ASSOC);
    $employee_ids = array_column($employees_list, 'employee_id');
    $employee_details = [];
    foreach ($employees_list as $emp) {
        $employee_details[$emp['employee_id']] = $emp;
    }

    if (empty($employee_ids)) {
        header('HTTP/1.1 400 Bad Request');
        echo "No employees found for this cost center";
        exit;
    }

    // Get all courses
    $courses = $pdo->query("SELECT course_id, course_code, course_name, COALESCE(validity_months,24) AS validity_months, table_name
                            FROM courses WHERE is_active=1 AND table_name IS NOT NULL ORDER BY course_code")->fetchAll(PDO::FETCH_ASSOC);

    $export_data = [];

    foreach ($courses as $c) {
        $tbl = $c['table_name'];
        $valid = (int)$c['validity_months'];
        $course_code = $c['course_code'];
        $course_name = $c['course_name'];
        $never_expire = in_array($course_code, $NEVER_EXPIRE_COURSES);

        if (!safe_table($tbl)) continue;

        // Get completion data for these employees
        $placeholders = implode(',', array_fill(0, count($employee_ids), '?'));
        $stmt = $pdo->prepare("SELECT ID, CompletedDate, Status FROM `{$tbl}` WHERE ID IN ($placeholders)");
        $stmt->execute($employee_ids);
        $completed_data = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $completed_data[$row['ID']] = [
                'completed_date' => $row['CompletedDate'],
                'status' => $row['Status']
            ];
        }

        foreach ($employee_ids as $emp_id) {
            $emp = $employee_details[$emp_id];
            $status_type = '';
            $completion_date = null;

            if (!isset($completed_data[$emp_id])) {
                $status_type = 'initial';
            } else {
                $completion_date = $completed_data[$emp_id]['completed_date'];
                if (!$completion_date || $completion_date == '0000-00-00') {
                    $status_type = 'initial';
                } elseif ($never_expire) {
                    continue; // Never expire courses don't show in expired/tobe
                } else {
                    $completion_dt = DateTime::createFromFormat('Y-m-d', $completion_date);
                    if (!$completion_dt) {
                        $completion_dt = date_create($completion_date);
                    }

                    if ($completion_dt) {
                        $expiry_dt = clone $completion_dt;
                        $expiry_dt->modify("+{$valid} months");
                        $today = new DateTime();
                        $days_to_expiry = (int)$today->diff($expiry_dt)->days;

                        if ($expiry_dt < $today) {
                            $status_type = 'expired';
                        } elseif ($days_to_expiry <= $to_be) {
                            $status_type = 'tobe';
                        } else {
                            continue; // Current/valid - don't export
                        }
                    } else {
                        $status_type = 'initial';
                    }
                }
            }

            // Filter by type if specified
            if ($type !== 'all' && $status_type !== $type) {
                continue;
            }

            $export_data[] = [
                'course_code' => $course_code,
                'course_name' => $course_name,
                'employee_id' => $emp_id,
                'employee_name' => $emp['full_name'],
                'position' => $emp['position'],
                'department' => $emp['department'],
                'completed_date' => $completion_date ?? 'N/A',
                'status_type' => ucfirst($status_type),
                'cost_center' => $cost_center
            ];
        }
    }

    // Sort by course code then employee name
    usort($export_data, function ($a, $b) {
        $cmp = strcmp($a['course_code'], $b['course_code']);
        if ($cmp == 0) {
            return strcmp($a['employee_name'], $b['employee_name']);
        }
        return $cmp;
    });

    $type_label = ($type === 'all') ? 'all_employees' : $type;
    $filename = "cost_center_{$cost_center}_{$type_label}_report_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    if (ob_get_level()) ob_end_clean();
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Course Code', 'Course Name', 'Employee ID', 'Employee Name', 'Position', 'Department', 'Completed Date', 'Status', 'Cost Center']);
    foreach ($export_data as $row) {
        fputcsv($out, [$row['course_code'], $row['course_name'], $row['employee_id'], $row['employee_name'], $row['position'], $row['department'], $row['completed_date'], $row['status_type'], $row['cost_center']]);
    }
    fclose($out);
    exit;
}

// ---------------- AJAX: drill ----------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'course_employees') {
    header('Content-Type: application/json; charset=utf-8');
    $course_id = (int)($_GET['course_id'] ?? 0);
    $type = $_GET['type'] ?? 'initial';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = $DRILL_PAGE_SIZE;
    $offset = ($page - 1) * $limit;
    $to_be = max(0, (int)($_GET['to_be_days'] ?? $to_be_days));

    $stmt = $pdo->prepare("SELECT course_code, course_name, COALESCE(validity_months,24) AS validity_months, table_name FROM courses WHERE course_id=? AND is_active=1 LIMIT 1");
    $stmt->execute([$course_id]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$course) {
        echo json_encode(['ok' => false, 'error' => 'Course not found']);
        exit;
    }

    $tbl = $course['table_name'];
    $valid = (int)$course['validity_months'];
    $course_code = $course['course_code'];
    $never_expire = in_array($course_code, $GLOBALS['NEVER_EXPIRE_COURSES']);

    if (!safe_table($tbl)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid course table']);
        exit;
    }

    try {
        if ($type === 'initial') {
            $sql_count = "SELECT COUNT(*) FROM employees e LEFT JOIN `{$tbl}` t ON e.employee_id=t.ID WHERE e.is_active=1 AND (t.ID IS NULL OR t.CompletedDate IS NULL OR t.CompletedDate='')";
            $sql_page = "SELECT e.employee_id, e.full_name, e.cost_center, e.position, e.department, t.CompletedDate, t.Status
                         FROM employees e LEFT JOIN `{$tbl}` t ON e.employee_id=t.ID
                         WHERE e.is_active=1 AND (t.ID IS NULL OR t.CompletedDate IS NULL OR t.CompletedDate='')
                         ORDER BY e.cost_center, e.employee_id LIMIT {$limit} OFFSET {$offset}";
        } elseif ($type === 'expired') {
            if ($never_expire) {
                $sql_count = "SELECT 0";
                $sql_page = "SELECT e.employee_id, e.full_name, e.cost_center, e.position, e.department, NULL AS CompletedDate, NULL AS Status
                             FROM employees e WHERE 1=0";
            } else {
                $sql_count = "SELECT COUNT(*) FROM `{$tbl}` t JOIN employees e ON e.employee_id=t.ID
                              WHERE e.is_active=1 AND t.CompletedDate IS NOT NULL AND DATE_ADD(t.CompletedDate, INTERVAL {$valid} MONTH) < CURDATE()";
                $sql_page = "SELECT t.ID AS employee_id, e.full_name, e.cost_center, e.position, e.department, t.CompletedDate, t.Status
                             FROM `{$tbl}` t JOIN employees e ON e.employee_id=t.ID
                             WHERE e.is_active=1 AND t.CompletedDate IS NOT NULL AND DATE_ADD(t.CompletedDate, INTERVAL {$valid} MONTH) < CURDATE()
                             ORDER BY e.cost_center, t.ID LIMIT {$limit} OFFSET {$offset}";
            }
        } else { // to-be
            if ($never_expire) {
                $sql_count = "SELECT 0";
                $sql_page = "SELECT e.employee_id, e.full_name, e.cost_center, e.position, e.department, NULL AS CompletedDate, NULL AS Status
                             FROM employees e WHERE 1=0";
            } else {
                $sql_count = "SELECT COUNT(*) FROM `{$tbl}` t JOIN employees e ON e.employee_id=t.ID
                              WHERE e.is_active=1 AND t.CompletedDate IS NOT NULL
                              AND DATE_ADD(t.CompletedDate, INTERVAL {$valid} MONTH) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL {$to_be} DAY)";
                $sql_page = "SELECT t.ID AS employee_id, e.full_name, e.cost_center, e.position, e.department, t.CompletedDate, t.Status
                             FROM `{$tbl}` t JOIN employees e ON e.employee_id=t.ID
                             WHERE e.is_active=1 AND t.CompletedDate IS NOT NULL
                             AND DATE_ADD(t.CompletedDate, INTERVAL {$valid} MONTH) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL {$to_be} DAY)
                             ORDER BY e.cost_center, t.ID LIMIT {$limit} OFFSET {$offset}";
            }
        }

        $total = (int)$pdo->query($sql_count)->fetchColumn();
        $rows = $pdo->query($sql_page)->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok' => true,
            'course' => ['id' => $course_id, 'code' => $course['course_code'], 'name' => $course['course_name']],
            'type' => $type,
            'to_be_days' => $to_be,
            'total' => $total,
            'page' => $page,
            'page_size' => $limit,
            'rows' => $rows,
            'never_expire' => $never_expire
        ]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ---------------- CSV export handler for courses ----------------
if (isset($_GET['export_course'])) {
    $course_id = (int)($_GET['export_course'] ?? 0);
    $type = $_GET['etype'] ?? 'initial';
    $to_be = max(0, (int)($_GET['to_be_days'] ?? $to_be_days));

    $stmt = $pdo->prepare("SELECT course_code, course_name, COALESCE(validity_months,24) AS validity_months, table_name FROM courses WHERE course_id=? LIMIT 1");
    $stmt->execute([$course_id]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$course || !safe_table($course['table_name'])) {
        header('HTTP/1.1 400 Bad Request');
        echo "Invalid course";
        exit;
    }

    $tbl = $course['table_name'];
    $valid = (int)$course['validity_months'];
    $course_code = $course['course_code'];
    $never_expire = in_array($course_code, $NEVER_EXPIRE_COURSES);

    if ($type === 'initial') {
        $sql = "SELECT e.employee_id, e.full_name, e.cost_center, e.position, e.department, t.CompletedDate, t.Status
                FROM employees e LEFT JOIN `{$tbl}` t ON e.employee_id=t.ID
                WHERE e.is_active=1 AND (t.ID IS NULL OR t.CompletedDate IS NULL OR t.CompletedDate='') ORDER BY e.cost_center, e.employee_id";
    } elseif ($type === 'expired') {
        if ($never_expire) {
            $sql = "SELECT e.employee_id, e.full_name, e.cost_center, e.position, e.department, NULL AS CompletedDate, NULL AS Status
                    FROM employees e WHERE 1=0";
        } else {
            $sql = "SELECT t.ID AS employee_id, e.full_name, e.cost_center, e.position, e.department, t.CompletedDate, t.Status
                    FROM `{$tbl}` t JOIN employees e ON e.employee_id=t.ID
                    WHERE e.is_active=1 AND t.CompletedDate IS NOT NULL AND DATE_ADD(t.CompletedDate, INTERVAL {$valid} MONTH) < CURDATE()
                    ORDER BY e.cost_center, t.ID";
        }
    } else {
        if ($never_expire) {
            $sql = "SELECT e.employee_id, e.full_name, e.cost_center, e.position, e.department, NULL AS CompletedDate, NULL AS Status
                    FROM employees e WHERE 1=0";
        } else {
            $sql = "SELECT t.ID AS employee_id, e.full_name, e.cost_center, e.position, e.department, t.CompletedDate, t.Status
                    FROM `{$tbl}` t JOIN employees e ON e.employee_id=t.ID
                    WHERE e.is_active=1 AND t.CompletedDate IS NOT NULL
                      AND DATE_ADD(t.CompletedDate, INTERVAL {$valid} MONTH) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL {$to_be} DAY)
                    ORDER BY e.cost_center, t.ID";
        }
    }

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $filename = "{$course['course_code']}_{$type}_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    if (ob_get_level()) ob_end_clean();
    $out = fopen('php://output', 'w');
    fputcsv($out, ['employee_id', 'full_name', 'cost_center', 'position', 'department', 'CompletedDate', 'Status']);
    foreach ($rows as $r) fputcsv($out, [$r['employee_id'], $r['full_name'], $r['cost_center'], $r['position'], $r['department'], $r['CompletedDate'] ?? '', $r['Status'] ?? '']);
    fclose($out);
    exit;
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
    $dept_course_stats = $cached['dept_course_stats'] ?? [];
} else {
    // Calculate fresh data with optimized approach
    $course_stats = [];
    $dept_gaps = [];
    $dept_course_stats = [];
    $total_gap = 0;

    // Get all active employees once
    $active_employees_result = $pdo->query("SELECT employee_id, full_name, cost_center, position, department FROM employees WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    $active_employees = $active_employees_result;
    $total_active_employees = count($active_employees_result);

    // Group employees by cost center
    $employees_by_cc = [];
    foreach ($active_employees as $emp) {
        $cc = $emp['cost_center'];
        if (!isset($employees_by_cc[$cc])) {
            $employees_by_cc[$cc] = [];
        }
        $employees_by_cc[$cc][] = $emp;
    }

    // Get all courses
    $courses = $pdo->query("SELECT course_id, course_code, course_name, COALESCE(validity_months,24) AS validity_months, table_name
                            FROM courses WHERE is_active=1 AND table_name IS NOT NULL ORDER BY course_code")->fetchAll(PDO::FETCH_ASSOC);

    // Pre-calculate month range for monthly stats
    $months = [];
    $month_counts = [];
    $now = new DateTime();
    for ($i = 11; $i >= 0; $i--) {
        $m = (clone $now)->modify("-{$i} months");
        $ym = $m->format('Y-m');
        $months[] = $ym;
        $month_counts[$ym] = 0;
    }

    // Initialize department course stats
    foreach ($employees_by_cc as $cc => $emps) {
        $dept_course_stats[$cc] = [];
    }

    // Process each course
    foreach ($courses as $c) {
        $cid = (int)$c['course_id'];
        $tbl = $c['table_name'];
        $valid = (int)$c['validity_months'];
        $course_code = $c['course_code'];
        $never_expire = in_array($course_code, $NEVER_EXPIRE_COURSES);

        if (!safe_table($tbl)) continue;

        // Get all employees who have completed this course
        $completed_data = [];
        $stmt = $pdo->query("SELECT ID, CompletedDate, Status FROM `{$tbl}` WHERE CompletedDate IS NOT NULL AND CompletedDate != ''");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $completed_data[$row['ID']] = [
                'completed_date' => $row['CompletedDate'],
                'status' => $row['Status']
            ];
        }

        // Calculate counts per department
        $dept_initial = [];
        $dept_expired = [];
        $dept_tobe = [];

        // Initialize department arrays
        foreach ($employees_by_cc as $cc => $emps) {
            $dept_initial[$cc] = 0;
            $dept_expired[$cc] = 0;
            $dept_tobe[$cc] = 0;
        }

        // For each active employee
        foreach ($active_employees as $emp) {
            $emp_id = $emp['employee_id'];
            $cc = $emp['cost_center'];

            if (!isset($completed_data[$emp_id])) {
                $dept_initial[$cc]++;
                continue;
            }

            $completion_date = $completed_data[$emp_id]['completed_date'];
            if (!$completion_date) {
                $dept_initial[$cc]++;
                continue;
            }

            // For never-expire courses, count as current (no gap)
            if ($never_expire) {
                continue;
            }

            // Parse completion date and calculate expiry
            $completion_dt = DateTime::createFromFormat('Y-m-d', $completion_date);
            if (!$completion_dt) {
                $completion_dt = date_create($completion_date);
            }

            if (!$completion_dt) {
                $dept_initial[$cc]++;
                continue;
            }

            $expiry_dt = clone $completion_dt;
            $expiry_dt->modify("+{$valid} months");
            $today = new DateTime();
            $days_to_expiry = (int)$today->diff($expiry_dt)->days;

            if ($expiry_dt < $today) {
                $dept_expired[$cc]++;
            } elseif ($days_to_expiry <= $to_be_days) {
                $dept_tobe[$cc]++;
            }
        }

        // Store course stats
        $initial_total = array_sum($dept_initial);
        $expired_total = array_sum($dept_expired);
        $tobe_total = array_sum($dept_tobe);

        $course_stats[$cid] = [
            'course_id' => $cid,
            'course_code' => $course_code,
            'course_name' => $c['course_name'],
            'initial' => $initial_total,
            'expired' => $expired_total,
            'tobe' => $tobe_total,
            'total_gap' => $expired_total + $tobe_total,
            'never_expire' => $never_expire
        ];
        $total_gap += ($expired_total + $tobe_total);

        // Store department-specific stats
        foreach ($employees_by_cc as $cc => $emps) {
            $dept_course_stats[$cc][$cid] = [
                'course_code' => $course_code,
                'course_name' => $c['course_name'],
                'initial' => $dept_initial[$cc],
                'expired' => $dept_expired[$cc],
                'tobe' => $dept_tobe[$cc],
                'gap' => $dept_expired[$cc] + $dept_tobe[$cc]
            ];
        }

        // Update department gaps
        foreach ($employees_by_cc as $cc => $emps) {
            if (!isset($dept_gaps[$cc])) {
                $dept_gaps[$cc] = 0;
            }
            $dept_gaps[$cc] += $dept_expired[$cc] + $dept_tobe[$cc];
        }

        // Get monthly completion stats
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
        'total_active_employees' => $total_active_employees,
        'dept_course_stats' => $dept_course_stats
    ]);
}

// ---------------- Prepare JS arrays ----------------
$course_gaps = [];
foreach ($course_stats as $k => $v) {
    $course_gaps[$k] = $v['total_gap'];
}
arsort($course_gaps);

$pie_labels = [];
$pie_values = [];
$other = 0;
$count = 0;

foreach ($course_gaps as $cid => $val) {
    if ($val == 0) continue;
    $count++;
    if ($count <= $TOP_PIE_COUNT) {
        $pie_labels[] = $course_stats[$cid]['course_code'];
        $pie_values[] = $val;
    } else {
        $other += $val;
    }
}

if ($other > 0) {
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

// Prepare department chart data
$dept_chart_data = [];
foreach ($dept_gaps as $cc => $total) {
    if (isset($dept_course_stats[$cc]) && count($dept_course_stats[$cc]) > 0) {
        $course_labels = [];
        $gap_values = [];
        $expired_values = [];
        $tobe_values = [];

        foreach ($dept_course_stats[$cc] as $c_stat) {
            if ($c_stat['gap'] > 0) {
                $course_labels[] = $c_stat['course_code'];
                $gap_values[] = $c_stat['gap'];
                $expired_values[] = $c_stat['expired'];
                $tobe_values[] = $c_stat['tobe'];
            }
        }

        if (!empty($course_labels)) {
            $dept_chart_data[$cc] = [
                'labels' => $course_labels,
                'gaps' => $gap_values,
                'expired' => $expired_values,
                'tobe' => $tobe_values,
                'total' => $total
            ];
        }
    }
}
$js_dept_chart_data = json_encode($dept_chart_data);

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Dashboard — GenericCourseTrack (Stats)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .dept-section {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
            background: white;
        }

        .dept-header {
            cursor: pointer;
            user-select: none;
        }

        .dept-content {
            display: none;
            margin-top: 16px;
        }

        .dept-content.active {
            display: block;
        }

        .toggle-icon {
            transition: transform 0.3s ease;
        }

        .toggle-icon.rotated {
            transform: rotate(90deg);
        }

        .export-btn {
            background-color: #10b981;
            color: white;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            transition: background-color 0.2s;
            text-decoration: none;
        }

        .export-btn:hover {
            background-color: #059669;
        }

        .export-group {
            display: flex;
            gap: 8px;
            justify-content: center;
        }

        .export-link {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            background-color: #e5e7eb;
            color: #374151;
            text-decoration: none;
        }

        .export-link:hover {
            background-color: #d1d5db;
        }

        .dept-export-group {
            display: flex;
            gap: 8px;
        }
    </style>
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

        <!-- Stats Cards -->
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
                <h3 class="text-sm uppercase text-gray-500">Total GAP (Expired + To-be)</h3>
                <p class="text-4xl font-bold text-red-600"><?= number_format($total_gap) ?></p>
                <p class="text-xs text-gray-500">To-be defined as within <?= $to_be_days ?> days of expiry</p>
            </div>
        </div>

        <!-- Charts -->
        <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="bg-white p-4 rounded-xl shadow">
                <h3 class="font-bold mb-2">GAP by course (Expired + To-be)</h3>
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

        <!-- Courses Table -->
        <div class="mt-6 bg-white p-4 rounded-xl shadow">
            <h3 class="font-bold mb-3">Courses table</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="p-2 border">Course</th>
                            <th class="p-2 text-center border">Never Expire</th>
                            <th class="p-2 text-center border">Initial</th>
                            <th class="p-2 text-center border">Expired</th>
                            <th class="p-2 text-center border">To be (<?= $to_be_days ?>d)</th>
                            <th class="p-2 text-center border">Total GAP</th>
                            <th class="p-2 text-center border">Export</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($course_stats as $cid => $cs): ?>
                            <tr class="border-b hover:bg-gray-50">
                                <td class="p-2 border"><?= htmlspecialchars($cs['course_code'] . ' - ' . mb_substr($cs['course_name'], 0, 50)) ?></td>
                                <td class="p-2 text-center border">
                                    <?php if ($cs['never_expire']): ?>
                                        <span class="text-green-600 font-bold">✓ Forever</span>
                                    <?php else: ?>
                                        <span class="text-gray-400">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-2 text-center border"><?= number_format($cs['initial']) ?></td>
                                <td class="p-2 text-center border <?= $cs['expired'] > 0 ? 'text-red-600 font-bold' : '' ?>"><?= number_format($cs['expired']) ?></td>
                                <td class="p-2 text-center border <?= $cs['tobe'] > 0 ? 'text-yellow-600 font-bold' : '' ?>"><?= number_format($cs['tobe']) ?></td>
                                <td class="p-2 text-center border font-bold text-red-600"><?= number_format($cs['total_gap']) ?></td>
                                <td class="p-2 text-center border">
                                    <div class="export-group">
                                        <a href="dashboard.php?export_course=<?= $cid ?>&etype=initial&to_be_days=<?= $to_be_days ?>" class="export-link">Initial</a>
                                        <a href="dashboard.php?export_course=<?= $cid ?>&etype=expired&to_be_days=<?= $to_be_days ?>" class="export-link">Expired</a>
                                        <a href="dashboard.php?export_course=<?= $cid ?>&etype=tobe&to_be_days=<?= $to_be_days ?>" class="export-link">ToBe</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Department Course Details Section with Employee Export -->
        <div class="mt-6">
            <h3 class="font-bold mb-3 text-xl">Cost Center Course Details</h3>
            <?php foreach ($dept_gaps as $cc => $total_gap_val): ?>
                <?php if (isset($dept_course_stats[$cc]) && count($dept_course_stats[$cc]) > 0): ?>
                    <div class="dept-section">
                        <div class="dept-header flex items-center justify-between" onclick="toggleDept('dept_<?= preg_replace('/[^a-zA-Z0-9]/', '_', $cc) ?>')">
                            <h4 class="text-lg font-semibold">Cost Center: <?= htmlspecialchars($cc) ?> (Total GAP: <?= number_format($total_gap_val) ?>)</h4>
                            <div class="flex items-center gap-4">
                                <div class="dept-export-group" onclick="event.stopPropagation()">
                                    <a href="dashboard.php?export_dept_csv=<?= urlencode($cc) ?>&etype=all&to_be_days=<?= $to_be_days ?>" class="export-btn">📥 Export All Employees</a>
                                    <a href="dashboard.php?export_dept_csv=<?= urlencode($cc) ?>&etype=expired&to_be_days=<?= $to_be_days ?>" class="export-btn" style="background-color: #dc2626;">⚠️ Export Expired Only</a>
                                    <a href="dashboard.php?export_dept_csv=<?= urlencode($cc) ?>&etype=tobe&to_be_days=<?= $to_be_days ?>" class="export-btn" style="background-color: #f59e0b;">⏰ Export To-be Only</a>
                                    <a href="dashboard.php?export_dept_csv=<?= urlencode($cc) ?>&etype=initial&to_be_days=<?= $to_be_days ?>" class="export-btn" style="background-color: #6b7280;">📋 Export Initial Only</a>
                                </div>
                                <span class="toggle-icon text-xl">▶</span>
                            </div>
                        </div>
                        <div id="dept_<?= preg_replace('/[^a-zA-Z0-9]/', '_', $cc) ?>" class="dept-content">
                            <!-- Charts Row -->
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                                <div class="bg-gray-50 p-4 rounded-lg">
                                    <h5 class="font-bold mb-2 text-center">GAP Distribution by Course</h5>
                                    <canvas id="pie_dept_<?= preg_replace('/[^a-zA-Z0-9]/', '_', $cc) ?>"></canvas>
                                </div>
                                <div class="bg-gray-50 p-4 rounded-lg">
                                    <h5 class="font-bold mb-2 text-center">Expired vs To-be by Course</h5>
                                    <canvas id="bar_dept_<?= preg_replace('/[^a-zA-Z0-9]/', '_', $cc) ?>"></canvas>
                                </div>
                            </div>

                            <!-- Data Table -->
                            <div class="overflow-x-auto mt-4">
                                <table class="w-full text-left border-collapse">
                                    <thead class="bg-gray-100">
                                        <tr>
                                            <th class="p-2 border">Course</th>
                                            <th class="p-2 text-center border">Initial</th>
                                            <th class="p-2 text-center border">Expired</th>
                                            <th class="p-2 text-center border">To be</th>
                                            <th class="p-2 text-center border">GAP</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $dept_courses = $dept_course_stats[$cc];
                                        usort($dept_courses, function ($a, $b) {
                                            return $b['gap'] - $a['gap'];
                                        });
                                        foreach ($dept_courses as $c_stat):
                                        ?>
                                            <?php if ($c_stat['gap'] > 0 || $c_stat['initial'] > 0): ?>
                                                <tr class="border-b hover:bg-gray-50">
                                                    <td class="p-2 border"><?= htmlspecialchars($c_stat['course_code'] . ' - ' . mb_substr($c_stat['course_name'], 0, 40)) ?></td>
                                                    <td class="p-2 text-center border"><?= number_format($c_stat['initial']) ?></td>
                                                    <td class="p-2 text-center border <?= $c_stat['expired'] > 0 ? 'text-red-600 font-bold' : '' ?>"><?= number_format($c_stat['expired']) ?></td>
                                                    <td class="p-2 text-center border <?= $c_stat['tobe'] > 0 ? 'text-yellow-600 font-bold' : '' ?>"><?= number_format($c_stat['tobe']) ?></td>
                                                    <td class="p-2 text-center border font-bold <?= $c_stat['gap'] > 0 ? 'text-red-600' : 'text-gray-500' ?>"><?= number_format($c_stat['gap']) ?></td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
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
        const deptChartData = <?= $js_dept_chart_data ?>;

        // Toggle function for department sections
        window.toggleDept = function(id) {
            const content = document.getElementById(id);
            const icon = content.previousElementSibling.querySelector('.toggle-icon');
            content.classList.toggle('active');
            icon.classList.toggle('rotated');
        };

        // Main Pie Chart
        if (pieLabels.length > 0 && pieValues.length > 0) {
            new Chart(document.getElementById('pieChart').getContext('2d'), {
                type: 'pie',
                data: {
                    labels: pieLabels,
                    datasets: [{
                        data: pieValues,
                        backgroundColor: ['#1f77b4', '#ff7f0e', '#2ca02c', '#d62728', '#9467bd', '#8c564b', '#e377c2', '#7f7f7f', '#bcbd22', '#17becf']
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    },
                    onClick(e, items) {
                        if (!items.length) return;
                        const idx = items[0].index;
                        const code = pieLabels[idx];
                        const COURSE_MAP = {
                            <?php foreach ($course_stats as $cid => $cs) {
                                echo "'" . addslashes($cs['course_code']) . "':" . $cid . ",";
                            } ?>
                        };
                        if (!COURSE_MAP[code]) {
                            alert('No mapping for ' + code);
                            return;
                        }
                        const cid = COURSE_MAP[code];
                        window.open(`dashboard.php?ajax=course_employees&course_id=${cid}&type=initial&to_be_days=${toBeDays}`, '_blank');
                    }
                }
            });
        }

        // Main Bar Chart
        if (barLabels.length > 0 && barValues.length > 0) {
            new Chart(document.getElementById('barChart').getContext('2d'), {
                type: 'bar',
                data: {
                    labels: barLabels,
                    datasets: [{
                        label: 'GAP (Expired + To-be)',
                        data: barValues,
                        backgroundColor: '#4f46e5'
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }

        // Main Line Chart
        if (lineLabels.length > 0 && lineValues.length > 0) {
            new Chart(document.getElementById('lineChart').getContext('2d'), {
                type: 'line',
                data: {
                    labels: lineLabels,
                    datasets: [{
                        label: 'Completed',
                        data: lineValues,
                        borderColor: '#059669',
                        tension: 0.3,
                        fill: false,
                        pointRadius: 3
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }

        // Department Charts
        for (const [cc, data] of Object.entries(deptChartData)) {
            const safeId = cc.replace(/[^a-zA-Z0-9]/g, '_');

            // Pie Chart for GAP Distribution
            const pieCanvas = document.getElementById(`pie_dept_${safeId}`);
            if (pieCanvas && data.labels.length > 0) {
                new Chart(pieCanvas.getContext('2d'), {
                    type: 'pie',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            data: data.gaps,
                            backgroundColor: ['#1f77b4', '#ff7f0e', '#2ca02c', '#d62728', '#9467bd', '#8c564b', '#e377c2', '#7f7f7f', '#bcbd22', '#17becf']
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: {
                                    font: {
                                        size: 10
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Bar Chart for Expired vs To-be
            const barCanvas = document.getElementById(`bar_dept_${safeId}`);
            if (barCanvas && data.labels.length > 0) {
                new Chart(barCanvas.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: data.labels,
                        datasets: [{
                                label: 'Expired',
                                data: data.expired,
                                backgroundColor: '#dc2626'
                            },
                            {
                                label: 'To-be',
                                data: data.tobe,
                                backgroundColor: '#f59e0b'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'top'
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Count'
                                }
                            },
                            x: {
                                ticks: {
                                    font: {
                                        size: 10
                                    }
                                }
                            }
                        }
                    }
                });
            }
        }

        // Auto-expand first department
        const firstDept = document.querySelector('.dept-content');
        if (firstDept) {
            firstDept.classList.add('active');
            const firstIcon = firstDept.previousElementSibling.querySelector('.toggle-icon');
            if (firstIcon) firstIcon.classList.add('rotated');
        }
    </script>
</body>

</html>