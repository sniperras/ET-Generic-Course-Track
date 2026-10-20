<?php
// admin/export_pdf.php - Admin PDF Export (matches print_pdf.php rules)

require_once '../include/db_connect.php';
require_once '../include/auth.php';
require_once '../include/cc_overrides.php';
requireAdmin();

// Clear any output
if (ob_get_level()) {
    ob_end_clean();
}

if (!isset($_GET['emp_id']) || empty($_GET['emp_id'])) {
    die('No employee selected.');
}
$emp_id = trim($_GET['emp_id']);

// Constants
const FOREVER_THRESHOLD = 999999;
$NEVER_EXPIRE_COURSES = ['ESD', 'ETOPS', 'FUEL', 'ETTPM', 'MCM'];

// Function to format validity display
function formatValidity($months): string
{
    if ($months >= FOREVER_THRESHOLD) {
        return 'One-Time';
    }
    return $months . 'm';
}

// Get employee info
$stmt = $pdo->prepare("SELECT employee_id, full_name, position, department, fleet, cost_center FROM employees WHERE employee_id = ? AND is_active = 1");
$stmt->execute([$emp_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$employee) die('Employee not found.');

$emp_cc = trim($employee['cost_center'] ?? '');
$emp_pos = trim($employee['position'] ?? '');
$emp_dept = trim($employee['department'] ?? '');

// Load CC + Position → Course overrides
$cc_position_overrides = load_cc_position_overrides($pdo);

/**
 * Check if a course is required for a given CC based on cc_course_requirements.
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
 * Position eligibility — matches print_pdf.php / index.php exactly.
 */
function is_position_eligible(string $course_code, ?string $position): bool
{
    $code = strtoupper(trim($course_code));
    $pos  = strtoupper(trim((string)$position));

    // Normalize whitespace
    $pos  = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\s]+/u', ' ', $pos);

    $is_mgmt = (
        strpos($pos, 'MANAGER') !== false
        || strpos($pos, 'MGR')     !== false
        || strpos($pos, 'MD')      !== false
        || strpos($pos, 'DIR')     !== false
        || strpos($pos, 'TEAM LEADER') !== false
    );

    // SMS-MGR / SMSMRO
    if ($code === 'SMS-MGR')  return $is_mgmt;
    if ($code === 'SMSMRO')   return !$is_mgmt;

    // ETTPM
    if ($code === 'ETTPM') {
        return (strpos($pos, 'TEAM LEADER') !== false)
            || (strpos($pos, 'DIR') !== false)
            || (strpos($pos, 'MANAGER') !== false)
            || (strpos($pos, 'MGR') !== false);
    }

    // IP
    if ($code === 'IP') {
        return (strpos($pos, 'TECHNICIAN III') !== false)
            || (strpos($pos, 'TECHNICIAN IV')  !== false)
            || (strpos($pos, 'TEAM LEADER')    !== false)
            || (strpos($pos, 'FLEET')          !== false)
            || (strpos($pos, 'INSPECTOR')      !== false)
            || (strpos($pos, 'MANAGER')        !== false);
    }

    // MEL
    if ($code === 'MEL') {
        if (strpos($pos, 'MECHANIC') !== false) return false;
    }

    // DGR / RCAT
    if ($code === 'DGR' || $code === 'RCAT') {
        if (
            strpos($pos, 'TEAM LEADER') !== false
            || strpos($pos, 'MGR') !== false
            || strpos($pos, 'MANAGER') !== false
        ) return false;
    }

    return true;
}

// Get courses
$courses = $pdo->query("
    SELECT course_id, course_code, course_name, validity_months, table_name
    FROM courses
    WHERE is_active = 1 AND table_name IS NOT NULL
    ORDER BY course_code ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Build records
$records = [];
foreach ($courses as $course) {
    $table_name = trim($course['table_name']);
    $course_code = trim($course['course_code']);
    $course_name = $course['course_name'];
    $validity_months = (int)($course['validity_months'] ?? 24);
    $is_global_forever = in_array($course_code, $NEVER_EXPIRE_COURSES) || $validity_months >= FOREVER_THRESHOLD;

    if ($table_name === 'course_records_legistlation') {
        $table_name = 'course_records_legislation';
    }

    // ── STEP -1: SKIP override ──
    if (
        !empty($cc_position_overrides)
        && is_forced_skipped_by_override($cc_position_overrides, $emp_cc, $emp_pos, $course_code, $emp_dept)
    ) {
        $records[] = [
            'course_code' => $course_code,
            'course_name' => $course_name,
            'validity_months' => $validity_months,
            'validity_display' => formatValidity($validity_months),
            'taken_date' => '',
            'expiry_date' => '',
            'status' => 'Not Applicable'
        ];
        continue;
    }

    // ── STEP 0: TAKE override ──
    $forced_override = is_forced_applicable_by_override($cc_position_overrides, $emp_cc, $emp_pos, $course_code, $emp_dept);

    // ── STEP 1: CC map gate ──
    $required_by_cc = cc_has_course_requirement($emp_cc, $course_code);

    // ── STEP 2: Position rules ──
    $pos_eligible = is_position_eligible($course_code, $emp_pos);

    // Final applicability decision
    $is_applicable = $forced_override || ($required_by_cc && $pos_eligible);

    if (!$is_applicable) {
        $records[] = [
            'course_code' => $course_code,
            'course_name' => $course_name,
            'validity_months' => $validity_months,
            'validity_display' => formatValidity($validity_months),
            'taken_date' => '',
            'expiry_date' => '',
            'status' => 'Not Applicable'
        ];
        continue;
    }

    // Check if specific FOREVER override applies
    $is_forever = $is_global_forever || is_forced_forever_by_override($cc_position_overrides, $emp_cc, $emp_pos, $course_code, $emp_dept);

    $taken_date = '';
    $expiry_date = '';
    $status = 'Not Taken';

    if ($table_name && preg_match('/^course_records_[a-zA-Z0-9_]+$/', $table_name)) {
        try {
            $sql = "SELECT CompletedDate, Status FROM `$table_name` WHERE ID = ? LIMIT 1";
            $stmt2 = $pdo->prepare($sql);
            $stmt2->execute([$emp_id]);
            $rec = $stmt2->fetch(PDO::FETCH_ASSOC);

            if ($rec) {
                $completed = $rec['CompletedDate'] ?? null;
                $status_raw = trim($rec['Status'] ?? '');
                $has_valid_date = ($completed && $completed !== '0000-00-00' && $completed !== '');
                $st_lower = strtolower($status_raw);

                if ($has_valid_date) {
                    $taken_date = $completed;
                }

                if (!$has_valid_date && ($st_lower === 'taken' || $st_lower === 'current')) {
                    $status = 'Not Taken';
                } elseif ($is_forever && $has_valid_date) {
                    $status = 'Current';
                    $expiry_date = '—';
                } elseif ($st_lower === 'current' || $st_lower === 'taken') {
                    $status = 'Current';
                } elseif ($st_lower === 'expired') {
                    $status = 'Expired';
                } elseif (!$has_valid_date) {
                    $status = 'Not Taken';
                } else {
                    $status = ucfirst($status_raw);
                }

                // Calculate expiry date if not forever
                if (!$is_forever && $has_valid_date) {
                    $cdt = DateTime::createFromFormat('Y-m-d', $completed);
                    if ($cdt) {
                        $expiry_dt = clone $cdt;
                        $expiry_dt->modify("+{$validity_months} months");
                        $expiry_date = $expiry_dt->format('Y-m-d');
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $records[] = [
        'course_code' => $course_code,
        'course_name' => $course_name,
        'validity_months' => $validity_months,
        'validity_display' => formatValidity($validity_months),
        'taken_date' => $taken_date,
        'expiry_date' => $expiry_date,
        'status' => $status
    ];
}

// Load TCPDF
require_once '../TCPDF/tcpdf.php';

// Create PDF in PORTRAIT orientation
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('GenericCourseTrack');
$pdf->SetAuthor('Ethiopian Airlines Aviation Academy');
$pdf->SetTitle('Training Compliance Report - ' . $employee['full_name']);
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(FALSE, 0); // Disable auto page break to force one page
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();

// ---------------- LETTERHEAD (#588D01) ----------------
$pdf->SetFillColor(88, 141, 1);
$pdf->Rect(0, 0, 210, 32, 'F');

// Logo
if (file_exists('../img/logo.png')) {
    $pdf->Image('../img/logo.png', 10, 6, 35, 0, 'PNG');
}

// Company Name
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetXY(50, 8);
$pdf->Cell(0, 7, 'ETHIOPIAN AIRLINES', 0, 1, 'L');

// Department
$pdf->SetFont('helvetica', '', 10);
$pdf->SetXY(50, 15);
$pdf->Cell(0, 6, 'MRO Svcs – Technical Training Department', 0, 1, 'L');

// Address
$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetXY(50, 21);
$pdf->Cell(0, 5, 'Addis Ababa, Bole international Airport, Ethiopia | Call us : (+251-11) 517-8864/8608', 0, 1, 'L');

// ---------------- MAIN TITLE ----------------
$pdf->Ln(6);
$pdf->SetTextColor(0);
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 8, 'RECURRENT TRAINING COMPLIANCE REPORT', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 8);
$pdf->Cell(0, 5, 'Generated on: ' . date('d F Y \a\t H:i'), 0, 1, 'R');
$pdf->Ln(2);

// ---------------- EMPLOYEE INFO SECTION ----------------
$pdf->SetFillColor(88, 141, 1);
$pdf->SetTextColor(255);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 7, ' TRAINING VERIFICATION ', 0, 1, 'C', true);

$pdf->SetTextColor(0);
$pdf->SetFillColor(245, 248, 250);
$pdf->SetFont('helvetica', '', 8);

$info_left = [
    'Employee ID' => $employee['employee_id'],
    'Full Name' => $employee['full_name'],
    'Position' => $employee['position'] ?: 'Not Assigned',
];

$info_right = [
    'Department' => $employee['department'] ?: 'Not Assigned',
    'Fleet Assignment' => $employee['fleet'],
    'Cost Center' => $employee['cost_center'] ?: 'Not Assigned',
];

$max_rows = max(count($info_left), count($info_right));
$left_keys = array_keys($info_left);
$right_keys = array_keys($info_right);

for ($i = 0; $i < $max_rows; $i++) {
    if (isset($left_keys[$i])) {
        $label = $left_keys[$i];
        $value = $info_left[$label];
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(38, 6, $label . ':', 1, 0, 'L', true);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(60, 6, $value, 1, 0, 'L', true);
    } else {
        $pdf->Cell(98, 6, '', 1, 0, 'L', true);
    }

    if (isset($right_keys[$i])) {
        $label = $right_keys[$i];
        $value = $info_right[$label];
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(38, 6, $label . ':', 1, 0, 'L', true);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(0, 6, $value, 1, 1, 'L', true);
    } else {
        $pdf->Cell(0, 6, '', 1, 1, 'L', true);
    }
}
$pdf->Ln(2);

// ---------------- COURSES TABLE ----------------
$pageHeight = $pdf->getPageHeight();
$currentY = $pdf->GetY();

// Calculate available space for the table (leave room for signature block at bottom)
$availableSpace = $pageHeight - $currentY - 45;

$total_rows = count($records);
$header_height = 7;

// Dynamically calculate row height to fit all rows on one page
if ($total_rows > 0) {
    $row_height = floor(($availableSpace - $header_height) / $total_rows);
    // Clamp row height between 4mm and 6mm
    $row_height = max(4, min(6, $row_height));
} else {
    $row_height = 6;
}

// Adjust font size based on row height
if ($row_height < 5) {
    $font_size = 6.5;
} elseif ($row_height < 5.5) {
    $font_size = 7;
} else {
    $font_size = 8;
}

// Column widths (Total 190mm)
$w = [7, 20, 85, 25, 25, 28];
$header = ['#', 'Code', 'Course Name', 'Date', 'Expiry', 'Status'];

$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(219, 233, 178);
$pdf->SetTextColor(0);

foreach ($header as $i => $h) {
    $pdf->Cell($w[$i], $header_height, $h, 1, 0, 'C', true);
}
$pdf->Ln();

$no = 1;
$pdf->SetFont('helvetica', '', $font_size);

foreach ($records as $record) {
    $status_lower = strtolower($record['status']);

    if ($status_lower === 'current' || $status_lower === 'taken') {
        $r = 0;
        $g = 128;
        $b = 0;
        $status_display = 'Current';
    } elseif ($status_lower === 'expired') {
        $r = 220;
        $g = 53;
        $b = 69;
        $status_display = 'Expired';
    } elseif ($status_lower === 'not taken') {
        $r = 245;
        $g = 158;
        $b = 11;
        $status_display = 'Not Taken';
    } else {
        $r = 108;
        $g = 117;
        $b = 125;
        $status_display = 'Not Applicable';
    }

    $pdf->Cell($w[0], $row_height, $no++, 1, 0, 'C');
    $pdf->Cell($w[1], $row_height, $record['course_code'], 1, 0, 'L');

    $course_name = $record['course_name'];
    $max_chars = ($font_size <= 7) ? 55 : 48;
    if (mb_strlen($course_name) > $max_chars) {
        $course_name = mb_substr($course_name, 0, $max_chars - 3) . '...';
    }
    $pdf->Cell($w[2], $row_height, $course_name, 1, 0, 'L');
    $pdf->Cell($w[3], $row_height, $record['taken_date'] ?: '—', 1, 0, 'C');
    $pdf->Cell($w[4], $row_height, $record['expiry_date'] ?: '—', 1, 0, 'C');

    $pdf->SetTextColor($r, $g, $b);
    $pdf->SetFont('helvetica', 'B', $font_size);
    $pdf->Cell($w[5], $row_height, $status_display, 1, 0, 'C');

    $pdf->SetTextColor(0);
    $pdf->SetFont('helvetica', '', $font_size);
    $pdf->Ln();
}

// ---------------- SIGNATURE BLOCK ----------------
$pdf->Ln(3);
$pdf->SetFont('helvetica', '', 7.5);
$pdf->MultiCell(0, 3.5, "This is to certify that the above employee has completed the required recurrent training as per Ethiopian Civil Aviation Authority (ECAA) and EASA Part-145 requirements.", 0, 'C');
$pdf->Ln(2);

$col_width = 62;

$pdf->SetFont('helvetica', 'B', 8);
$pdf->Cell($col_width, 5, 'Verified by:', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 8);
$pdf->Cell($col_width, 8, '_________________________', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 7);
$pdf->Cell($col_width, 4, 'MRO HR', 0, 1, 'C');

$pdf->SetFont('helvetica', 'I', 6.5);
$pdf->Cell($col_width, 4, 'Date: ' . date('d F Y'), 0, 1, 'C');

$pdf->Ln(2);
$pdf->SetFont('helvetica', 'I', 6.5);
$pdf->Cell(0, 4, 'Generated by GenericCourseTrack © ' . date('Y') . ' MRO HR', 0, 1, 'C');

// OUTPUT
$safeName = preg_replace('/[^A-Za-z0-9_\-\. ]/', '_', ($employee['full_name'] ?? $emp_id));
$filename = "Training_Compliance_{$employee['employee_id']}_{$safeName}.pdf";
$pdf->Output($filename, 'D');
exit;
