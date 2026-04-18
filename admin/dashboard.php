<?php

if (session_status() === PHP_SESSION_NONE) session_start();
ob_start();

require_once __DIR__ . '/../include/db_connect.php';
require_once __DIR__ . '/../include/auth.php';
requireAdmin();

// ---------------- CONFIG ----------------
$CACHE_TTL = 300;          // seconds (5 minutes)
$TOP_PIE_COUNT = 8;
$TOP_DEPT_COUNT = 12;
$DEFAULT_TO_BE_DAYS = 1;
$DRILL_PAGE_SIZE = 50;

// Courses that never expire (validity forever)
$NEVER_EXPIRE_COURSES = ['ESD', 'ETOPS', 'FUEL', 'ETTPM'];
const FOREVER_THRESHOLD = 999999;

// ---------------- Helpers ----------------
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

// ---------------- Read selected cost centers ----------------
$selected_cost_centers = [];
$cc_search_input = '';

// Check if clear filter is requested
if (isset($_GET['clear_filter']) && $_GET['clear_filter'] == 1) {
    $_SESSION['selected_cost_centers'] = [];
    $selected_cost_centers = [];
    header("Location: admin_panel.php?page=dashboard&to_be_days=" . $to_be_days);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_filter'])) {
    if (isset($_POST['selected_cc']) && is_array($_POST['selected_cc'])) {
        $selected_cost_centers = $_POST['selected_cc'];
        $_SESSION['selected_cost_centers'] = $selected_cost_centers;
    } else {
        $selected_cost_centers = [];
        $_SESSION['selected_cost_centers'] = [];
    }
    if (isset($_POST['cc_search']) && trim($_POST['cc_search']) !== '') {
        $cc_search_input = trim($_POST['cc_search']);
        $search_ccs = array_map('trim', explode(',', $cc_search_input));
        $search_ccs = array_filter($search_ccs);
        foreach ($search_ccs as $cc) {
            if (!in_array($cc, $selected_cost_centers)) {
                $selected_cost_centers[] = $cc;
            }
        }
        $_SESSION['selected_cost_centers'] = $selected_cost_centers;
    }
    $redirect_url = "admin_panel.php?page=dashboard&to_be_days=" . $to_be_days;
    if (!empty($selected_cost_centers)) {
        foreach ($selected_cost_centers as $cc) {
            $redirect_url .= '&selected_cc[]=' . urlencode($cc);
        }
    }
    header("Location: " . $redirect_url);
    exit;
} elseif (isset($_GET['selected_cc']) && is_array($_GET['selected_cc'])) {
    $selected_cost_centers = $_GET['selected_cc'];
    $_SESSION['selected_cost_centers'] = $selected_cost_centers;
} elseif (isset($_SESSION['selected_cost_centers']) && is_array($_SESSION['selected_cost_centers'])) {
    $selected_cost_centers = $_SESSION['selected_cost_centers'];
}

// ---------------- CSV Export for Selected Cost Centers (Combined) ----------------
if (isset($_GET['export_combined_csv'])) {
    $type = $_GET['etype'] ?? 'all';
    $to_be = max(0, (int)($_GET['to_be_days'] ?? $to_be_days));
    $selected_ccs = isset($_GET['selected_cc']) && is_array($_GET['selected_cc']) ? $_GET['selected_cc'] : $selected_cost_centers;

    if (empty($selected_ccs)) {
        $selected_ccs = $pdo->query("SELECT DISTINCT cost_center FROM employees WHERE is_active = 1 AND cost_center IS NOT NULL AND cost_center != ''")->fetchAll(PDO::FETCH_COLUMN);
    }

    $placeholders = implode(',', array_fill(0, count($selected_ccs), '?'));
    $stmt = $pdo->prepare("SELECT employee_id, full_name, position, department, cost_center FROM employees WHERE is_active = 1 AND cost_center IN ($placeholders)");
    $stmt->execute($selected_ccs);
    $employees_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $employee_ids = array_column($employees_list, 'employee_id');
    $employee_details = [];
    foreach ($employees_list as $emp) {
        $employee_details[$emp['employee_id']] = $emp;
    }

    if (empty($employee_ids)) {
        header('HTTP/1.1 400 Bad Request');
        echo "No employees found for selected cost centers";
        exit;
    }

    $courses = $pdo->query("SELECT course_id, course_code, course_name, COALESCE(validity_months,24) AS validity_months, table_name
                            FROM courses WHERE is_active=1 AND table_name IS NOT NULL ORDER BY course_code")->fetchAll(PDO::FETCH_ASSOC);

    $export_data = [];

    foreach ($courses as $c) {
        $tbl = $c['table_name'];
        $valid = (int)$c['validity_months'];
        $course_code = $c['course_code'];
        $course_name = $c['course_name'];
        $never_expire = in_array($course_code, $NEVER_EXPIRE_COURSES) || $valid >= FOREVER_THRESHOLD;

        if (!safe_table($tbl)) continue;

        $emp_placeholders = implode(',', array_fill(0, count($employee_ids), '?'));
        $stmt2 = $pdo->prepare("SELECT ID, CompletedDate, Status FROM `{$tbl}` WHERE ID IN ($emp_placeholders)");
        $stmt2->execute($employee_ids);
        $completed_data = [];
        while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
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
                    continue;
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
                            continue;
                        }
                    } else {
                        $status_type = 'initial';
                    }
                }
            }

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
                'cost_center' => $emp['cost_center'],
                'completed_date' => $completion_date ?? 'N/A',
                'status_type' => ucfirst($status_type)
            ];
        }
    }

    usort($export_data, function ($a, $b) {
        $cmp = strcmp($a['course_code'], $b['course_code']);
        if ($cmp == 0) {
            return strcmp($a['employee_name'], $b['employee_name']);
        }
        return $cmp;
    });

    $type_label = ($type === 'all') ? 'all_employees' : $type;
    $cc_label = !empty($selected_ccs) ? implode('_', array_slice($selected_ccs, 0, 3)) : 'all';
    $filename = "combined_{$cc_label}_{$type_label}_report_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    if (ob_get_level()) ob_end_clean();
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Course Code', 'Course Name', 'Employee ID', 'Employee Name', 'Position', 'Department', 'Cost Center', 'Completed Date', 'Status']);
    foreach ($export_data as $row) {
        fputcsv($out, [$row['course_code'], $row['course_name'], $row['employee_id'], $row['employee_name'], $row['position'], $row['department'], $row['cost_center'], $row['completed_date'], $row['status_type']]);
    }
    fclose($out);
    exit;
}

// ---------------- CSV Export for Cost Center (Employee List) ----------------
if (isset($_GET['export_dept_csv'])) {
    $cost_center = $_GET['export_dept_csv'] ?? '';
    $type = $_GET['etype'] ?? 'all';
    $to_be = max(0, (int)($_GET['to_be_days'] ?? $to_be_days));

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

    $courses = $pdo->query("SELECT course_id, course_code, course_name, COALESCE(validity_months,24) AS validity_months, table_name
                            FROM courses WHERE is_active=1 AND table_name IS NOT NULL ORDER BY course_code")->fetchAll(PDO::FETCH_ASSOC);

    $export_data = [];

    foreach ($courses as $c) {
        $tbl = $c['table_name'];
        $valid = (int)$c['validity_months'];
        $course_code = $c['course_code'];
        $course_name = $c['course_name'];
        $never_expire = in_array($course_code, $NEVER_EXPIRE_COURSES) || $valid >= FOREVER_THRESHOLD;

        if (!safe_table($tbl)) continue;

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
                    continue;
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
                            continue;
                        }
                    } else {
                        $status_type = 'initial';
                    }
                }
            }

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
    $never_expire = in_array($course_code, $NEVER_EXPIRE_COURSES) || $valid >= FOREVER_THRESHOLD;

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
                $sql_page = "SELECT e.employee_id, e.full_name, e.cost_center, e.position, e.department, NULL AS CompletedDate, NULL AS Status FROM employees e WHERE 1=0";
            } else {
                $sql_count = "SELECT COUNT(*) FROM `{$tbl}` t JOIN employees e ON e.employee_id=t.ID
                              WHERE e.is_active=1 AND t.CompletedDate IS NOT NULL AND DATE_ADD(t.CompletedDate, INTERVAL {$valid} MONTH) < CURDATE()";
                $sql_page = "SELECT t.ID AS employee_id, e.full_name, e.cost_center, e.position, e.department, t.CompletedDate, t.Status
                             FROM `{$tbl}` t JOIN employees e ON e.employee_id=t.ID
                             WHERE e.is_active=1 AND t.CompletedDate IS NOT NULL AND DATE_ADD(t.CompletedDate, INTERVAL {$valid} MONTH) < CURDATE()
                             ORDER BY e.cost_center, t.ID LIMIT {$limit} OFFSET {$offset}";
            }
        } else {
            if ($never_expire) {
                $sql_count = "SELECT 0";
                $sql_page = "SELECT e.employee_id, e.full_name, e.cost_center, e.position, e.department, NULL AS CompletedDate, NULL AS Status FROM employees e WHERE 1=0";
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
    $never_expire = in_array($course_code, $NEVER_EXPIRE_COURSES) || $valid >= FOREVER_THRESHOLD;

    if ($type === 'initial') {
        $sql = "SELECT e.employee_id, e.full_name, e.cost_center, e.position, e.department, t.CompletedDate, t.Status
                FROM employees e LEFT JOIN `{$tbl}` t ON e.employee_id=t.ID
                WHERE e.is_active=1 AND (t.ID IS NULL OR t.CompletedDate IS NULL OR t.CompletedDate='') ORDER BY e.cost_center, e.employee_id";
    } elseif ($type === 'expired') {
        if ($never_expire) {
            $sql = "SELECT e.employee_id, e.full_name, e.cost_center, e.position, e.department, NULL AS CompletedDate, NULL AS Status FROM employees e WHERE 1=0";
        } else {
            $sql = "SELECT t.ID AS employee_id, e.full_name, e.cost_center, e.position, e.department, t.CompletedDate, t.Status
                    FROM `{$tbl}` t JOIN employees e ON e.employee_id=t.ID
                    WHERE e.is_active=1 AND t.CompletedDate IS NOT NULL AND DATE_ADD(t.CompletedDate, INTERVAL {$valid} MONTH) < CURDATE()
                    ORDER BY e.cost_center, t.ID";
        }
    } else {
        if ($never_expire) {
            $sql = "SELECT e.employee_id, e.full_name, e.cost_center, e.position, e.department, NULL AS CompletedDate, NULL AS Status FROM employees e WHERE 1=0";
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

// ---------------- Get all cost centers for filter ----------------
$all_cost_centers = $pdo->query("SELECT DISTINCT cost_center FROM employees WHERE is_active = 1 AND cost_center IS NOT NULL AND cost_center != '' ORDER BY cost_center")->fetchAll(PDO::FETCH_COLUMN);

// ---------------- MAIN PAGE: compute aggregated stats (cached) ----------------
$cache_key = "dashboard_stats_tobed_{$to_be_days}";
$cache_file = sys_get_temp_dir() . "/gct_dashboard_{$cache_key}.cache";

$use_cache = file_exists($cache_file) && (time() - filemtime($cache_file)) < $CACHE_TTL;

if ($use_cache) {
    $cached = unserialize(file_get_contents($cache_file));
    $course_stats = $cached['course_stats'];
    $dept_gaps = $cached['dept_gaps'];
    $total_gap = $cached['total_gap'];
    $month_counts = $cached['month_counts'];
    $months = $cached['months'];
    $total_active_employees = $cached['total_active_employees'];
    $dept_course_stats = $cached['dept_course_stats'] ?? [];
    $employees_by_cc = $cached['employees_by_cc'] ?? [];
} else {
    $active_employees = $pdo->query("SELECT employee_id, full_name, cost_center, position, department FROM employees WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    $total_active_employees = count($active_employees);

    $employees_by_cc = [];
    foreach ($active_employees as $emp) {
        $cc = $emp['cost_center'];
        if (!isset($employees_by_cc[$cc])) {
            $employees_by_cc[$cc] = [];
        }
        $employees_by_cc[$cc][] = $emp;
    }

    $courses = $pdo->query("SELECT course_id, course_code, course_name, COALESCE(validity_months,24) AS validity_months, table_name
                            FROM courses WHERE is_active=1 AND table_name IS NOT NULL ORDER BY course_code")->fetchAll(PDO::FETCH_ASSOC);

    $months = [];
    $month_counts = [];
    $now = new DateTime();
    for ($i = 11; $i >= 0; $i--) {
        $m = (clone $now)->modify("-{$i} months");
        $ym = $m->format('Y-m');
        $months[] = $ym;
        $month_counts[$ym] = 0;
    }

    $course_stats = [];
    $dept_gaps = [];
    $dept_course_stats = [];

    foreach ($employees_by_cc as $cc => $emps) {
        $dept_course_stats[$cc] = [];
    }

    foreach ($courses as $c) {
        $cid = (int)$c['course_id'];
        $tbl = $c['table_name'];
        $valid = (int)$c['validity_months'];
        $course_code = $c['course_code'];
        $never_expire = in_array($course_code, $NEVER_EXPIRE_COURSES) || $valid >= FOREVER_THRESHOLD;

        if (!safe_table($tbl)) continue;

        $completed_data = [];
        $stmt = $pdo->query("SELECT ID, CompletedDate, Status FROM `{$tbl}` WHERE CompletedDate IS NOT NULL AND CompletedDate != '' AND CompletedDate != '0000-00-00'");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $completed_data[$row['ID']] = [
                'completed_date' => $row['CompletedDate'],
                'status' => $row['Status']
            ];
        }

        $dept_initial = [];
        $dept_expired = [];
        $dept_tobe = [];

        foreach ($employees_by_cc as $cc => $emps) {
            $dept_initial[$cc] = 0;
            $dept_expired[$cc] = 0;
            $dept_tobe[$cc] = 0;
        }

        $today = new DateTime();

        foreach ($active_employees as $emp) {
            $emp_id = $emp['employee_id'];
            $cc = $emp['cost_center'];

            if (!isset($completed_data[$emp_id])) {
                $dept_initial[$cc]++;
                continue;
            }

            $completion_date = $completed_data[$emp_id]['completed_date'];
            if (!$completion_date || $completion_date == '0000-00-00') {
                $dept_initial[$cc]++;
                continue;
            }

            if ($never_expire) {
                continue;
            }

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
            $days_to_expiry = (int)$today->diff($expiry_dt)->days;

            if ($expiry_dt < $today) {
                $dept_expired[$cc]++;
            } elseif ($days_to_expiry <= $to_be_days) {
                $dept_tobe[$cc]++;
            }
        }

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

        foreach ($employees_by_cc as $cc => $emps) {
            if (!isset($dept_gaps[$cc])) {
                $dept_gaps[$cc] = 0;
            }
            $dept_gaps[$cc] += $dept_expired[$cc] + $dept_tobe[$cc];
        }

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

    $total_gap = array_sum($dept_gaps);
    arsort($dept_gaps);

    file_put_contents($cache_file, serialize([
        'course_stats' => $course_stats,
        'dept_gaps' => $dept_gaps,
        'total_gap' => $total_gap,
        'month_counts' => $month_counts,
        'months' => $months,
        'total_active_employees' => $total_active_employees,
        'dept_course_stats' => $dept_course_stats,
        'employees_by_cc' => $employees_by_cc
    ]));
}

// ---------------- Prepare combined data for selected cost centers ----------------
$combined_course_stats = [];
$combined_total_gap = 0;

$display_ccs = !empty($selected_cost_centers) ? $selected_cost_centers : array_keys($dept_gaps);

$filtered_employees_count = 0;
foreach ($display_ccs as $cc) {
    if (isset($employees_by_cc[$cc])) {
        $filtered_employees_count += count($employees_by_cc[$cc]);
    }
}

foreach ($course_stats as $cid => $cs) {
    $combined_course_stats[$cid] = [
        'course_id' => $cid,
        'course_code' => $cs['course_code'],
        'course_name' => $cs['course_name'],
        'initial' => 0,
        'expired' => 0,
        'tobe' => 0,
        'total_gap' => 0,
        'never_expire' => $cs['never_expire']
    ];

    foreach ($display_ccs as $cc) {
        if (isset($dept_course_stats[$cc][$cid])) {
            $combined_course_stats[$cid]['initial'] += $dept_course_stats[$cc][$cid]['initial'];
            $combined_course_stats[$cid]['expired'] += $dept_course_stats[$cc][$cid]['expired'];
            $combined_course_stats[$cid]['tobe'] += $dept_course_stats[$cc][$cid]['tobe'];
        }
    }
    $combined_course_stats[$cid]['total_gap'] = $combined_course_stats[$cid]['expired'] + $combined_course_stats[$cid]['tobe'];
    $combined_total_gap += $combined_course_stats[$cid]['total_gap'];
}

// Prepare pie chart data
$course_gaps = [];
foreach ($combined_course_stats as $k => $v) {
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
        $pie_labels[] = $combined_course_stats[$cid]['course_code'];
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
$js_line_labels = json_encode($months);
$js_line_values = json_encode($month_counts);

// Prepare combined department chart data
$combined_dept_data = [];
$combined_dept_labels = [];
$combined_dept_gaps = [];
$combined_dept_expired = [];
$combined_dept_tobe = [];

foreach ($combined_course_stats as $cid => $cs) {
    if ($cs['total_gap'] > 0) {
        $combined_dept_labels[] = $cs['course_code'];
        $combined_dept_gaps[] = $cs['total_gap'];
        $combined_dept_expired[] = $cs['expired'];
        $combined_dept_tobe[] = $cs['tobe'];
    }
}

if (!empty($combined_dept_labels)) {
    $combined_dept_data = [
        'labels' => $combined_dept_labels,
        'gaps' => $combined_dept_gaps,
        'expired' => $combined_dept_expired,
        'tobe' => $combined_dept_tobe,
        'total' => $combined_total_gap
    ];
}
$js_dept_chart_data = json_encode(!empty($combined_dept_data) ? ['combined' => $combined_dept_data] : []);

$last_cache_update = $use_cache ? date('H:i:s', filemtime($cache_file)) : 'Just now';
$next_cache_update = $use_cache ? date('H:i:s', time() + ($CACHE_TTL - (time() - filemtime($cache_file)))) : 'Now';
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Dashboard — GenericCourseTrack (Stats)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="../img/ethiopian_logo.ico">
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

        .cache-info {
            font-size: 11px;
            color: #6b7280;
            text-align: right;
            margin-bottom: 10px;
        }

        .filter-section {
            background: white;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .checkbox-group {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 12px;
            background: #f9fafb;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            padding: 6px 8px;
            border-bottom: 1px solid #e5e7eb;
        }

        .checkbox-item:last-child {
            border-bottom: none;
        }

        .checkbox-item input {
            margin-right: 10px;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .checkbox-item label {
            cursor: pointer;
            font-size: 14px;
            color: #374151;
        }

        .checkbox-item:hover {
            background-color: #f3f4f6;
        }

        .filter-actions {
            display: flex;
            gap: 8px;
            align-items: flex-end;
        }

        .apply-filter-btn {
            background-color: #3b82f6;
            color: white;
            padding: 8px 20px;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            border: none;
        }

        .apply-filter-btn:hover {
            background-color: #2563eb;
        }

        .clear-filter-btn {
            background-color: #ef4444;
            color: white;
            padding: 8px 20px;
            border-radius: 6px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .clear-filter-btn:hover {
            background-color: #dc2626;
        }

        .selected-count {
            font-size: 12px;
            color: #6b7280;
            margin-top: 8px;
        }

        .search-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }

        .cc-search-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .clear-search-btn {
            background-color: #6b7280;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            margin-left: 8px;
            cursor: pointer;
            border: none;
        }

        .clear-search-btn:hover {
            background-color: #4b5563;
        }

        .search-header {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }

        .combined-export-group {
            display: flex;
            gap: 8px;
            margin-left: 20px;
        }
    </style>
</head>

<body class="bg-gray-100">
    <div class="max-w-7xl mx-auto p-6">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold">Generic Course Follow-up — Dashboard</h1>
            <div class="flex items-center gap-2">
                <form method="GET" class="flex items-center gap-2" id="toBeForm">
                    <label class="text-sm text-gray-600">To-be-expire window (days):</label>
                    <input type="number" name="to_be_days" min="0" value="<?= htmlspecialchars($to_be_days) ?>" class="w-24 px-2 py-1 border rounded">
                    <button class="bg-blue-600 text-white px-3 py-1 rounded">Apply</button>
                </form>
            </div>
        </div>

        <div class="cache-info">
            <?php if ($use_cache): ?>
                Data cached - Next auto-refresh at <?= $next_cache_update ?> (last update: <?= $last_cache_update ?>)
            <?php else: ?>
                Data freshly loaded (<?= $last_cache_update ?>)
            <?php endif; ?>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="bg-white p-6 rounded-xl shadow">
                <h3 class="text-sm uppercase text-gray-500">Total active employees</h3>
                <p class="text-4xl font-bold text-blue-700"><?= number_format($filtered_employees_count) ?></p>
                <?php if (!empty($selected_cost_centers)): ?>
                    <p class="text-xs text-gray-500">Filtered by selected cost centers</p>
                <?php endif; ?>
            </div>
            <div class="bg-white p-6 rounded-xl shadow">
                <h3 class="text-sm uppercase text-gray-500">Active courses</h3>
                <p class="text-4xl font-bold text-emerald-600"><?= number_format(count($combined_course_stats)) ?></p>
            </div>
            <div class="bg-white p-6 rounded-xl shadow">
                <h3 class="text-sm uppercase text-gray-500">Total GAP (Expired + To-be)</h3>
                <p class="text-4xl font-bold text-red-600"><?= number_format($combined_total_gap) ?></p>
                <p class="text-xs text-gray-500">To-be defined as within <?= $to_be_days ?> days of expiry</p>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="bg-white p-4 rounded-xl shadow">
                <h3 class="font-bold mb-2">GAP by course (Expired + To-be)</h3>
                <canvas id="pieChart"></canvas>
            </div>
            <div class="bg-white p-4 rounded-xl shadow">
                <h3 class="font-bold mb-2">GAP by Cost Center</h3>
                <canvas id="costCenterPieChart"></canvas>
                <?php if (!empty($selected_cost_centers)): ?>
                    <p class="text-xs text-gray-500 text-center mt-2">Showing filtered cost centers</p>
                <?php endif; ?>
            </div>
            <div class="bg-white p-4 rounded-xl shadow">
                <h3 class="font-bold mb-2">Selected Cost Centers</h3>
                <div class="text-center p-4">
                    <p class="text-2xl font-bold text-blue-600"><?= count($selected_cost_centers) ?: 'All' ?></p>
                    <p class="text-sm text-gray-500">Cost Center<?= count($selected_cost_centers) != 1 ? 's' : '' ?> Selected</p>
                    <?php if (!empty($selected_cost_centers)): ?>
                        <div class="text-xs text-gray-400 mt-2 max-h-20 overflow-y-auto">
                            <?php foreach ($selected_cost_centers as $cc): ?>
                                <div><?= htmlspecialchars($cc) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Courses Table -->
        <div class="mt-6 bg-white p-4 rounded-xl shadow">
            <h3 class="font-bold mb-3">Courses Table <?= !empty($selected_cost_centers) ? '(Filtered by selected cost centers)' : '' ?></h3>
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
                        <?php foreach ($combined_course_stats as $cid => $cs): ?>
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
                                        <a href="admin_panel.php?page=dashboard&export_course=<?= $cid ?>&etype=initial&to_be_days=<?= $to_be_days ?>&<?= !empty($selected_cost_centers) ? 'selected_cc[]=' . implode('&selected_cc[]=', array_map('urlencode', $selected_cost_centers)) : '' ?>" class="export-link">Initial</a>
                                        <a href="admin_panel.php?page=dashboard&export_course=<?= $cid ?>&etype=expired&to_be_days=<?= $to_be_days ?>&<?= !empty($selected_cost_centers) ? 'selected_cc[]=' . implode('&selected_cc[]=', array_map('urlencode', $selected_cost_centers)) : '' ?>" class="export-link">Expired</a>
                                        <a href="admin_panel.php?page=dashboard&export_course=<?= $cid ?>&etype=tobe&to_be_days=<?= $to_be_days ?>&<?= !empty($selected_cost_centers) ? 'selected_cc[]=' . implode('&selected_cc[]=', array_map('urlencode', $selected_cost_centers)) : '' ?>" class="export-link">ToBe</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Cost Center Filter Section -->
        <div class="filter-section">
            <h3 class="font-bold mb-3 text-lg">Filter Cost Centers</h3>
            <form method="POST" action="" id="filterForm">
                <input type="hidden" name="apply_filter" value="1">
                <input type="hidden" name="to_be_days" value="<?= $to_be_days ?>">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Select Cost Centers (Checkboxes)</label>
                        <div class="search-header">
                            <input type="text" id="ccSearchBox" class="cc-search-input" placeholder="Search cost centers...">
                            <button type="button" id="clearSearchBtn" class="clear-search-btn">Clear</button>
                        </div>
                        <div class="checkbox-group" id="checkboxContainer">
                            <?php foreach ($all_cost_centers as $cc): ?>
                                <div class="checkbox-item" data-cc="<?= htmlspecialchars(strtolower($cc)) ?>">
                                    <input type="checkbox" name="selected_cc[]" value="<?= htmlspecialchars($cc) ?>" id="cc_<?= preg_replace('/[^a-zA-Z0-9]/', '_', $cc) ?>" <?= in_array($cc, $selected_cost_centers) ? 'checked' : '' ?>>
                                    <label for="cc_<?= preg_replace('/[^a-zA-Z0-9]/', '_', $cc) ?>"><?= htmlspecialchars($cc) ?></label>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($all_cost_centers)): ?>
                                <div class="text-gray-500 text-center py-4">No cost centers found</div>
                            <?php endif; ?>
                        </div>
                        <div class="selected-count mt-2">
                            <?php $selected_count = count($selected_cost_centers); ?>
                            <?php if ($selected_count > 0): ?>
                                <span class="text-green-600">✓ <?= $selected_count ?> cost center(s) selected</span>
                            <?php else: ?>
                                <span class="text-gray-500">No cost centers selected (showing all)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Or Enter Cost Centers (comma-separated)</label>
                        <input type="text" name="cc_search" class="search-input" placeholder="e.g., MROORD482, MROMIL482, MROMG521" value="">
                        <div class="mt-4 flex gap-3">
                            <button type="submit" class="apply-filter-btn">Apply Filter</button>
                            <a href="admin_panel.php?page=dashboard&to_be_days=<?= $to_be_days ?>&clear_filter=1" class="clear-filter-btn">Clear Filter</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Combined Cost Center Course Details Section -->
        <div class="mt-6">
            <h3 class="font-bold mb-3 text-xl">Cost Center Course Details <?= !empty($selected_cost_centers) ? '(Combined View for Selected Cost Centers)' : '(All Cost Centers Combined)' ?></h3>

            <?php if (!empty($combined_dept_labels)): ?>
                <div class="dept-section">
                    <div class="dept-header flex items-center justify-between flex-wrap gap-4" onclick="toggleDept('dept_combined')">
                        <h4 class="text-lg font-semibold">
                            <?php if (!empty($selected_cost_centers)): ?>
                                Combined: <?= htmlspecialchars(implode(', ', $selected_cost_centers)) ?>
                            <?php else: ?>
                                All Cost Centers Combined
                            <?php endif; ?>
                            (Total GAP: <?= number_format($combined_dept_data['total']) ?>)
                        </h4>
                        <div class="flex items-center gap-4" onclick="event.stopPropagation()">
                            <div class="combined-export-group">
                                <a href="admin_panel.php?page=dashboard&export_combined_csv=1&etype=all&to_be_days=<?= $to_be_days ?>&<?= !empty($selected_cost_centers) ? 'selected_cc[]=' . implode('&selected_cc[]=', array_map('urlencode', $selected_cost_centers)) : '' ?>" class="export-btn">📥 Export All Employees</a>
                                <a href="admin_panel.php?page=dashboard&export_combined_csv=1&etype=expired&to_be_days=<?= $to_be_days ?>&<?= !empty($selected_cost_centers) ? 'selected_cc[]=' . implode('&selected_cc[]=', array_map('urlencode', $selected_cost_centers)) : '' ?>" class="export-btn" style="background-color: #dc2626;">⚠️ Export Expired Only</a>
                                <a href="admin_panel.php?page=dashboard&export_combined_csv=1&etype=tobe&to_be_days=<?= $to_be_days ?>&<?= !empty($selected_cost_centers) ? 'selected_cc[]=' . implode('&selected_cc[]=', array_map('urlencode', $selected_cost_centers)) : '' ?>" class="export-btn" style="background-color: #f59e0b;">⏰ Export To-be Only</a>
                                <a href="admin_panel.php?page=dashboard&export_combined_csv=1&etype=initial&to_be_days=<?= $to_be_days ?>&<?= !empty($selected_cost_centers) ? 'selected_cc[]=' . implode('&selected_cc[]=', array_map('urlencode', $selected_cost_centers)) : '' ?>" class="export-btn" style="background-color: #6b7280;">📋 Export Initial Only</a>
                            </div>
                            <span class="toggle-icon text-xl">▶</span>
                        </div>
                    </div>
                    <div id="dept_combined" class="dept-content">
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                            <div class="bg-gray-50 p-4 rounded-lg">
                                <h5 class="font-bold mb-2 text-center">GAP Distribution by Course</h5>
                                <canvas id="pie_dept_combined"></canvas>
                            </div>
                            <div class="bg-gray-50 p-4 rounded-lg">
                                <h5 class="font-bold mb-2 text-center">Expired vs To-be by Course</h5>
                                <canvas id="bar_dept_combined"></canvas>
                            </div>
                        </div>

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
                                    $combined_courses_agg = [];
                                    foreach ($combined_course_stats as $cid => $cs) {
                                        if ($cs['total_gap'] > 0 || $cs['initial'] > 0) {
                                            $combined_courses_agg[] = [
                                                'course_code' => $cs['course_code'],
                                                'course_name' => $cs['course_name'],
                                                'initial' => $cs['initial'],
                                                'expired' => $cs['expired'],
                                                'tobe' => $cs['tobe'],
                                                'gap' => $cs['total_gap']
                                            ];
                                        }
                                    }
                                    usort($combined_courses_agg, function ($a, $b) {
                                        return $b['gap'] - $a['gap'];
                                    });
                                    foreach ($combined_courses_agg as $c_stat):
                                    ?>
                                        <tr class="border-b hover:bg-gray-50">
                                            <td class="p-2 border"><?= htmlspecialchars($c_stat['course_code'] . ' - ' . mb_substr($c_stat['course_name'], 0, 40)) ?></td>
                                            <td class="p-2 text-center border"><?= number_format($c_stat['initial']) ?></td>
                                            <td class="p-2 text-center border <?= $c_stat['expired'] > 0 ? 'text-red-600 font-bold' : '' ?>"><?= number_format($c_stat['expired']) ?></td>
                                            <td class="p-2 text-center border <?= $c_stat['tobe'] > 0 ? 'text-yellow-600 font-bold' : '' ?>"><?= number_format($c_stat['tobe']) ?></td>
                                            <td class="p-2 text-center border font-bold <?= $c_stat['gap'] > 0 ? 'text-red-600' : 'text-gray-500' ?>"><?= number_format($c_stat['gap']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="bg-white rounded-xl p-8 text-center text-gray-500">
                    <?php if (!empty($selected_cost_centers)): ?>
                        No data available for selected cost centers.
                    <?php else: ?>
                        No cost center data available.
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const pieLabels = <?= $js_pie_labels ?>;
        const pieValues = <?= $js_pie_values ?>;
        const toBeDays = <?= (int)$to_be_days ?>;
        const deptChartData = <?= $js_dept_chart_data ?>;

        window.toggleDept = function(id) {
            const content = document.getElementById(id);
            if (!content) return;
            const icon = content.previousElementSibling?.querySelector('.toggle-icon');
            content.classList.toggle('active');
            if (icon) icon.classList.toggle('rotated');
        };

        // Search functionality for checkboxes
        const searchBox = document.getElementById('ccSearchBox');
        const clearSearchBtn = document.getElementById('clearSearchBtn');
        const checkboxItems = document.querySelectorAll('.checkbox-item');

        function filterCheckboxes() {
            const searchTerm = searchBox.value.toLowerCase();
            checkboxItems.forEach(item => {
                const ccText = item.getAttribute('data-cc') || item.textContent.toLowerCase();
                if (searchTerm === '' || ccText.includes(searchTerm)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        if (searchBox) {
            searchBox.addEventListener('keyup', filterCheckboxes);
        }

        if (clearSearchBtn) {
            clearSearchBtn.addEventListener('click', function() {
                if (searchBox) {
                    searchBox.value = '';
                    filterCheckboxes();
                }
            });
        }

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
                            <?php foreach ($combined_course_stats as $cid => $cs) {
                                echo "'" . addslashes($cs['course_code']) . "':" . $cid . ",";
                            } ?>
                        };
                        if (!COURSE_MAP[code]) {
                            alert('No mapping for ' + code);
                            return;
                        }
                        const cid = COURSE_MAP[code];
                        window.open(`admin_panel.php?page=dashboard&ajax=course_employees&course_id=${cid}&type=initial&to_be_days=${toBeDays}`, '_blank');
                    }
                }
            });
        }

        // Cost Center Pie Chart
        const costCenterLabels = <?= json_encode(array_keys($dept_gaps)) ?>;
        const costCenterValues = <?= json_encode(array_values($dept_gaps)) ?>;

        let filteredCostCenterLabels = costCenterLabels;
        let filteredCostCenterValues = costCenterValues;

        <?php if (!empty($selected_cost_centers)): ?>
            filteredCostCenterLabels = [];
            filteredCostCenterValues = [];
            <?php foreach ($selected_cost_centers as $cc): ?>
                filteredCostCenterLabels.push('<?= addslashes($cc) ?>');
                filteredCostCenterValues.push(<?= $dept_gaps[$cc] ?? 0 ?>);
            <?php endforeach; ?>
        <?php endif; ?>

        if (filteredCostCenterLabels.length > 0 && filteredCostCenterValues.length > 0) {
            const nonZeroLabels = [];
            const nonZeroValues = [];
            for (let i = 0; i < filteredCostCenterLabels.length; i++) {
                if (filteredCostCenterValues[i] > 0) {
                    nonZeroLabels.push(filteredCostCenterLabels[i]);
                    nonZeroValues.push(filteredCostCenterValues[i]);
                }
            }

            if (nonZeroLabels.length > 0) {
                new Chart(document.getElementById('costCenterPieChart').getContext('2d'), {
                    type: 'pie',
                    data: {
                        labels: nonZeroLabels,
                        datasets: [{
                            data: nonZeroValues,
                            backgroundColor: ['#1f77b4', '#ff7f0e', '#2ca02c', '#d62728', '#9467bd', '#8c564b', '#e377c2', '#7f7f7f', '#bcbd22', '#17becf', '#f39c12', '#9b59b6', '#1abc9c', '#e74c3c', '#3498db', '#e67e22', '#2ecc71', '#f1c40f', '#34495e', '#16a085']
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
                                    },
                                    boxWidth: 12
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                        return `${label}: ${value.toLocaleString()} (${percentage}%)`;
                                    }
                                }
                            }
                        },
                        onClick(e, items) {
                            if (!items.length) return;
                            const idx = items[0].index;
                            const cc = nonZeroLabels[idx];
                            if (cc) {
                                window.location.href = `admin_panel.php?page=dashboard&to_be_days=${toBeDays}&selected_cc[]=${encodeURIComponent(cc)}`;
                            }
                        }
                    }
                });
            } else {
                document.getElementById('costCenterPieChart').parentElement.innerHTML = '<div class="text-center text-gray-500 py-8">No GAP data available for selected cost centers</div>';
            }
        } else {
            document.getElementById('costCenterPieChart').parentElement.innerHTML = '<div class="text-center text-gray-500 py-8">No cost center data available</div>';
        }

        // Combined Department Charts
        if (deptChartData.combined && deptChartData.combined.labels.length > 0) {
            const data = deptChartData.combined;

            const pieCanvas = document.getElementById('pie_dept_combined');
            if (pieCanvas) {
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

            const barCanvas = document.getElementById('bar_dept_combined');
            if (barCanvas) {
                new Chart(barCanvas.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Expired',
                            data: data.expired,
                            backgroundColor: '#dc2626'
                        }, {
                            label: 'To-be',
                            data: data.tobe,
                            backgroundColor: '#f59e0b'
                        }]
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

        // Auto-expand combined department section
        const combinedDept = document.getElementById('dept_combined');
        if (combinedDept) {
            combinedDept.classList.add('active');
            const icon = combinedDept.previousElementSibling?.querySelector('.toggle-icon');
            if (icon) icon.classList.add('rotated');
        }

        // Preserve selected cost centers when changing to_be_days
        document.getElementById('toBeForm')?.addEventListener('submit', function(e) {
            const selectedCheckboxes = document.querySelectorAll('input[name="selected_cc[]"]:checked');
            if (selectedCheckboxes.length > 0) {
                selectedCheckboxes.forEach(cb => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'selected_cc[]';
                    input.value = cb.value;
                    this.appendChild(input);
                });
            }
        });
    </script>
</body>

</html>