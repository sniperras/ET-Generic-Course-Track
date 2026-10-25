<?php
// print_pdf.php - Public PDF generation for employee training report (NO LOGIN REQUIRED)

require_once __DIR__ . '/include/db_connect.php';
require_once __DIR__ . '/include/cc_overrides.php';

// Clear any output buffering
if (ob_get_level()) {
    ob_end_clean();
}

// Constants
const FOREVER_THRESHOLD = 999999;

// Function to format validity display
function formatValidity($months): string
{
    if ($months >= FOREVER_THRESHOLD) {
        return 'One-Time';
    }
    return $months . 'm';
}

// Check if emp_id is provided
if (!isset($_GET['emp_id']) || empty($_GET['emp_id'])) {
    die('No employee selected. Please go back and select an employee.');
}

$emp_id = trim($_GET['emp_id']);

// Get employee info
$stmt = $pdo->prepare("SELECT employee_id, full_name, position, department, fleet, cost_center FROM employees WHERE employee_id = ? AND is_active = 1");
$stmt->execute([$emp_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    die('Employee not found. ID: ' . htmlspecialchars($emp_id));
}

$cc = trim($employee['cost_center'] ?? '');
$pos = trim($employee['position'] ?? '');
$emp_dept = trim($employee['department'] ?? '');

// Load CC + Position → Course overrides
$cc_position_overrides = load_cc_position_overrides($pdo);

/**
 * Check if a course is required for a given CC based on cc_course_requirements.
 * (This is the raw CC mapping lookup — no override logic here.)
 */
function cc_has_course_requirement(?string $cc, string $course_code): bool
{
    static $cache = [];
    $cc = trim((string)$cc);
    if ($cc === '') return false;

    if (!isset($cache[$cc])) {
        global $pdo;
        $stmt = $pdo->prepare("SELECT course_code FROM cc_course_requirements WHERE cost_center = ?");
        $stmt->execute([$cc]);
        $cache[$cc] = array_flip(array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'course_code'));
    }
    return isset($cache[$cc][$course_code]);
}

/**
 * Position eligibility — mirrors index.php / dashboard.php / department_gap_report.php
 */
function is_position_eligible(string $course_code, ?string $position): bool
{
    $code = strtoupper(trim($course_code));
    $pos  = strtoupper(trim((string)$position));

    // Normalize whitespace (NBSP, thin spaces, multiple spaces) to single spaces
    $pos  = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\s]+/u', ' ', $pos);

    $is_mgmt = (
        strpos($pos, 'MANAGER') !== false
        || strpos($pos, 'MGR')     !== false
        || strpos($pos, 'MD')      !== false
        || strpos($pos, 'DIR')     !== false
        || strpos($pos, 'TEAM LEADER') !== false
    );

    // SMS-MGR / SMSMRO — pure position rules
    if ($code === 'SMS-MGR')  return $is_mgmt;
    if ($code === 'SMSMRO')   return !$is_mgmt;

    // ETTPM — Team Leader or Manager
    if ($code === 'ETTPM') {
        return (strpos($pos, 'TEAM LEADER') !== false)
            || (strpos($pos, 'DIR') !== false)
            || (strpos($pos, 'MANAGER') !== false)
            || (strpos($pos, 'MGR') !== false);
    }

    // IP — only certain technical roles
    if ($code === 'IP') {
        return (strpos($pos, 'TECHNICIAN III') !== false)
            || (strpos($pos, 'TECHNICIAN IV')  !== false)
            || (strpos($pos, 'TEAM LEADER')    !== false)
            || (strpos($pos, 'FLEET')          !== false)
            || (strpos($pos, 'INSPECTOR')      !== false)
            || (strpos($pos, 'MANAGER')        !== false);
    }

    // MEL — not for mechanics
    if ($code === 'MEL') {
        if (strpos($pos, 'MECHANIC') !== false) return false;
    }

    // DGR / RCAT — not for Team Leaders or Managers
    if ($code === 'DGR' || $code === 'RCAT') {
        if (
            strpos($pos, 'TEAM LEADER') !== false
            || strpos($pos, 'MGR') !== false
            || strpos($pos, 'MANAGER') !== false
        ) return false;
    }

    return true;
}

// Get all courses
$courses = $pdo->query("
    SELECT course_id, course_code, course_name, COALESCE(validity_months, 24) AS validity_months, TRIM(table_name) AS table_name
    FROM courses
    WHERE is_active = 1 AND table_name IS NOT NULL
    ORDER BY course_code ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Never expire courses
$NEVER_EXPIRE_COURSES = ['ESD', 'ETOPS', 'FUEL', 'ETTPM', 'MCM', 'MNX'];

// Build records
$records = [];
foreach ($courses as $course) {
    $table = trim($course['table_name']);
    $course_code = $course['course_code'];
    $course_name = $course['course_name'];
    $validity_months = (int)$course['validity_months'];
    $is_global_forever = in_array($course_code, $NEVER_EXPIRE_COURSES) || $validity_months >= FOREVER_THRESHOLD;

    // ── STEP -1: SKIP override (highest priority) ──
    if (
        !empty($cc_position_overrides)
        && is_forced_skipped_by_override($cc_position_overrides, $cc, $pos, $course_code, $emp_dept)
    ) {
        $records[] = [
            'code' => $course_code,
            'name' => $course_name,
            'taken_date' => '—',
            'status' => 'Not Applicable',
            'validity' => $validity_months,
            'validity_display' => formatValidity($validity_months),
        ];
        continue;
    }

    // ── STEP 0: TAKE override ──
    $forced_override = is_forced_applicable_by_override($cc_position_overrides, $cc, $pos, $course_code, $emp_dept);

    // ── STEP 1: CC map gate ──
    $required_by_cc = cc_has_course_requirement($cc, $course_code);

    // ── STEP 2: Position rules ──
    $pos_eligible = is_position_eligible($course_code, $pos);

    // Final applicability decision
    $is_applicable = $forced_override || ($required_by_cc && $pos_eligible);

    if (!$is_applicable) {
        $records[] = [
            'code' => $course_code,
            'name' => $course_name,
            'taken_date' => '—',
            'status' => 'Not Applicable',
            'validity' => $validity_months,
            'validity_display' => formatValidity($validity_months),
        ];
        continue;
    }

    // Check if this specific employee has a FOREVER override
    $is_forever = $is_global_forever || is_forced_forever_by_override($cc_position_overrides, $cc, $pos, $course_code, $emp_dept);

    $completed = null;
    $status = 'Not Taken';

    if ($table && preg_match('/^course_records_[a-zA-Z0-9_]+$/', $table)) {
        try {
            $table_exists = $pdo->query("SHOW TABLES LIKE '$table'")->fetch();
            if ($table_exists) {
                $sql = "SELECT CompletedDate, Status FROM `$table` WHERE ID = ? LIMIT 1";
                $stmt2 = $pdo->prepare($sql);
                $stmt2->execute([$emp_id]);
                $rec = $stmt2->fetch(PDO::FETCH_ASSOC);

                if ($rec) {
                    $completed = $rec['CompletedDate'] ?? null;
                    $status_raw = trim($rec['Status'] ?? '');
                    $has_valid_date = ($completed && $completed !== '0000-00-00' && $completed !== '');
                    $st_lower = strtolower($status_raw);

                    if (!$has_valid_date && ($st_lower === 'taken' || $st_lower === 'current')) {
                        $status = 'Not Taken';
                    } elseif ($is_forever && $has_valid_date) {
                        $status = 'Current';
                    } elseif ($st_lower === 'current' || $st_lower === 'taken') {
                        $status = 'Current';
                    } elseif ($st_lower === 'expired') {
                        $status = 'Expired';
                    } elseif (!$has_valid_date) {
                        $status = 'Not Taken';
                    } else {
                        $status = ucfirst($status_raw);
                    }
                }
            }
        } catch (Exception $e) {
            // Ignore
        }
    }

    $records[] = [
        'code' => $course_code,
        'name' => $course_name,
        'taken_date' => ($completed && $completed !== '0000-00-00') ? $completed : '—',
        'status' => $status,
        'validity' => $validity_months,
        'validity_display' => formatValidity($validity_months)
    ];
}

// Load TCPDF
require_once __DIR__ . '/TCPDF/tcpdf.php';

// Create PDF
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('GenericCourseTrack');
$pdf->SetAuthor('Ethiopian Airlines Aviation Academy');
$pdf->SetTitle('Training Compliance Report - ' . $employee['full_name']);
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(TRUE, 10);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();

// Letterhead — #588D01
$pdf->SetFillColor(88, 141, 1);
$pdf->Rect(0, 0, 210, 22, 'F');

// Logo
if (file_exists(__DIR__ . '/img/logo.png')) {
    $pdf->Image(__DIR__ . '/img/logo.png', 10, 3, 35, 0, 'PNG');
}

// Company Name
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('helvetica', 'B', 13);
$pdf->SetXY(50, 3);
$pdf->Cell(0, 6, 'ETHIOPIAN AIRLINES', 0, 1, 'L');

// Department
$pdf->SetFont('helvetica', '', 9);
$pdf->SetXY(50, 9);
$pdf->Cell(0, 5, 'MD ET MRO Svcs', 0, 1, 'L');

// Vertical Separator Line
$pdf->SetDrawColor(255, 255, 255);
$pdf->SetLineWidth(0.2);
$pdf->Line(145, 4, 145, 13); // x1, y1, x2, y2

// CRITICAL: Reset draw color and line width back to black for subsequent tables
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.2); // Standard table border width
// MRO
$pdf->SetFont('helvetica', '', 9);
$pdf->SetXY(45, 6);
$pdf->Cell(157, 0, 'MRO Coaching and Training Section', 0, 1, 'R');
// Address
$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetXY(50, 14);
$pdf->Cell(0, 5, 'Addis Ababa, Bole international Airport, Ethiopia | Call us : (+251-11) 517-8864/8608', 0, 1, 'L');

// Main Title
$pdf->SetTextColor(0);
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetXY(10, 25);
$pdf->Cell(0, 15, 'RECURRENT TRAINING COMPLIANCE REPORT', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 8);
$pdf->SetXY(50, 23);
$pdf->SetTextColor(0);
$pdf->Cell(157, 0, 'Generated on: ' . date('d F Y \a\t H:i'), 0, 1, 'R');
$pdf->Ln(4);

// Employee Info — section header in #588D01
$pdf->SetXY(10, 38);
$pdf->SetFillColor(88, 141, 1);
$pdf->SetTextColor(255);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 7, ' TRAINING VERIFICATION ', 0, 1, 'C', true);

$pdf->SetTextColor(0);
$pdf->SetFillColor(245, 248, 250);
$pdf->SetFont('helvetica', '', 9);

$info = [
    'Employee ID' => $employee['employee_id'],
    'Full Name' => $employee['full_name'],
    'Position' => $employee['position'] ?: '',
    'Department' => $employee['department'] ?: '',
    'Fleet Assignment' => $employee['fleet'] ?: '',
    'Cost Center' => $employee['cost_center'] ?: '',
];

$half = ceil(count($info) / 2);
$info_left = array_slice($info, 0, $half, true);
$info_right = array_slice($info, $half, null, true);
$max_rows = max(count($info_left), count($info_right));
$left_keys = array_keys($info_left);
$right_keys = array_keys($info_right);

for ($i = 0; $i < $max_rows; $i++) {
    if (isset($left_keys[$i])) {
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(45, 6, $left_keys[$i] . ':', 1, 0, 'L', true);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(55, 6, $info_left[$left_keys[$i]], 1, 0, 'L', true);
    } else {
        $pdf->Cell(100, 6, '', 1, 0, 'L', true);
    }

    if (isset($right_keys[$i])) {
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(45, 6, $right_keys[$i] . ':', 1, 0, 'L', true);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 6, $info_right[$right_keys[$i]], 1, 1, 'L', true);
    } else {
        $pdf->Cell(0, 6, '', 1, 1, 'L', true);
    }
}
$pdf->Ln(4);

// Courses Table
$header = ['#', 'Code', 'Course Name', 'Date', 'Status', 'Validity'];
$w = [7, 23, 92, 25, 26, 20];

$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(219, 233, 178); // light tint of #588D01
$pdf->SetTextColor(0);

foreach ($header as $i => $h) {
    $pdf->Cell($w[$i], 7, $h, 1, 0, 'C', true);
}
$pdf->Ln();

$no = 1;
$row_height = 5.5;
$pdf->SetFont('helvetica', '', 8);

foreach ($records as $record) {
    // Colour coding per gap type (same as index.php)
    if ($record['status'] == 'Current') {
        $r = 0;
        $g = 128;
        $b = 0;
    } elseif ($record['status'] == 'Expired') {
        $r = 220;
        $g = 53;
        $b = 69;
    } elseif ($record['status'] == 'Not Taken') {
        $r = 245;
        $g = 158;
        $b = 11;
    } else {
        // Not Applicable → gray
        $r = 108;
        $g = 117;
        $b = 125;
    }

    $course_name = $record['name'];
    if (mb_strlen($course_name) > 48) {
        $course_name = mb_substr($course_name, 0, 45) . '...';
    }

    $pdf->Cell($w[0], $row_height, $no++, 1, 0, 'C');
    $pdf->Cell($w[1], $row_height, $record['code'], 1, 0, 'L');
    $pdf->Cell($w[2], $row_height, $course_name, 1, 0, 'L');
    $pdf->Cell($w[3], $row_height, $record['taken_date'], 1, 0, 'C');

    $pdf->SetTextColor($r, $g, $b);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell($w[4], $row_height, $record['status'], 1, 0, 'C');
    $pdf->SetTextColor(0);
    $pdf->SetFont('helvetica', '', 8);

    $pdf->Cell($w[5], $row_height, $record['validity_display'], 1, 0, 'C');
    $pdf->Ln();
}

$pdf->Ln(5);

// Signature Block
$pdf->SetFont('helvetica', '', 8);
$pdf->MultiCell(0, 4.5, "This is to certify that the above employee has completed the required recurrent training as per Ethiopian Civil Aviation Authority (ECAA) and EASA Part-145 requirements.", 0, 'C');
$pdf->Ln(2);

$col_width = 62;
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell($col_width, 6, 'Verified by:', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 9);
$pdf->Cell($col_width, 10, '_________________________', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 7.5);
$pdf->Cell($col_width, 5, 'MRO HR', 0, 1, 'C');

$pdf->SetFont('helvetica', 'I', 7);
$pdf->Cell($col_width, 5, 'Date: ' . date('d F Y'), 0, 1, 'C');

$pdf->Ln(3);
$pdf->SetFont('helvetica', 'I', 6.5);
$pdf->Cell(0, 4, 'Generated by GenericCourseTrack © ' . date('Y') . ' MRO HR', 0, 1, 'C');

// OUTPUT - Show in browser (opens print dialog)
$safeName = preg_replace('/[^A-Za-z0-9_\-\. ]/', '_', ($employee['full_name'] ?? $emp_id));
$filename = "Training_Compliance_{$employee['employee_id']}_{$safeName}.pdf";
$pdf->Output($filename, 'I');
exit;
