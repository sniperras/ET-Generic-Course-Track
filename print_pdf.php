<?php
// print_pdf.php - Public PDF generation for employee training report (NO LOGIN REQUIRED)

require_once __DIR__ . '/include/db_connect.php';

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
        return 'Forever';
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

// Get all courses
$courses = $pdo->query("
    SELECT course_id, course_code, course_name, COALESCE(validity_months, 24) AS validity_months, TRIM(table_name) AS table_name
    FROM courses
    WHERE is_active = 1 AND table_name IS NOT NULL
    ORDER BY course_code ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Never expire courses (also include courses with validity >= FOREVER_THRESHOLD)
$NEVER_EXPIRE_COURSES = ['ESD', 'ETOPS', 'FUEL', 'ETTPM'];

// Build records
$records = [];
foreach ($courses as $course) {
    $table = trim($course['table_name']);
    $course_code = $course['course_code'];
    $course_name = $course['course_name'];
    $validity_months = (int)$course['validity_months'];
    $never_expire = in_array($course_code, $NEVER_EXPIRE_COURSES) || $validity_months >= FOREVER_THRESHOLD;

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
                    } elseif ($never_expire && $has_valid_date) {
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

// Letterhead
$pdf->SetFillColor(0, 74, 173);
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
$pdf->Cell(0, 5, 'MRO Svcs – Technical Training Department', 0, 1, 'L');

// Address
$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetXY(50, 14);
$pdf->Cell(0, 5, 'Addis Ababa, Bole international Airport, Ethiopia | Call us : (+251-11) 517-8864/8608', 0, 1, 'L');

// // Separator Line
// $pdf->SetDrawColor(0, 0, 0);
// $pdf->SetLineWidth(0.5);
// $pdf->Line(10, 34, 200, 34);

// Main Title
// $pdf->Ln(8);
$pdf->SetTextColor(0);
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetXY(10, 25);
$pdf->Cell(0, 15, 'RECURRENT TRAINING COMPLIANCE REPORT', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 8);
$pdf->SetXY(50, 23);
$pdf->SetTextColor(0);
$pdf->Cell(157, 0, 'Generated on: ' . date('d F Y \a\t H:i'), 0, 1, 'R');
$pdf->Ln(4);

// Employee Info
$pdf->SetXY(10, 38);
$pdf->SetFillColor(0, 74, 173);
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
$pdf->SetFillColor(220, 230, 250);
$pdf->SetTextColor(0);

foreach ($header as $i => $h) {
    $pdf->Cell($w[$i], 7, $h, 1, 0, 'C', true);
}
$pdf->Ln();

$no = 1;
$row_height = 5.5;
$pdf->SetFont('helvetica', '', 8);

foreach ($records as $record) {
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

    // Use the formatted validity display
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
//$pdf->Cell($col_width, 6, 'Prepared by:', 0, 0, 'C');
//$pdf->Cell($col_width, 6, 'Approved by:', 0, 0, 'C');
$pdf->Cell($col_width, 6, 'Verified by:', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 9);
//$pdf->Cell($col_width, 10, '_________________________', 0, 0, 'C');
//$pdf->Cell($col_width, 10, '_________________________', 0, 0, 'C');
$pdf->Cell($col_width, 10, '_________________________', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 7.5);
//$pdf->Cell($col_width, 5, 'Training Coordinator', 0, 0, 'C');
//$pdf->Cell($col_width, 5, 'Quality Manager', 0, 0, 'C');
$pdf->Cell($col_width, 5, 'MRO HR', 0, 1, 'C');

$pdf->SetFont('helvetica', 'I', 7);
//$pdf->Cell($col_width, 5, 'Date: _____________', 0, 0, 'C');
//$pdf->Cell($col_width, 5, 'Date: _____________', 0, 0, 'C');
$pdf->Cell($col_width, 5, 'Date: ' . date('d F Y'), 0, 1, 'C');

$pdf->Ln(3);
$pdf->SetFont('helvetica', 'I', 6.5);
$pdf->Cell(0, 4, 'Generated by GenericCourseTrack © ' . date('Y') . ' MRO HR', 0, 1, 'C');

// OUTPUT - Show in browser (opens print dialog)
$safeName = preg_replace('/[^A-Za-z0-9_\-\. ]/', '_', ($employee['full_name'] ?? $emp_id));
$filename = "Training_Compliance_{$employee['employee_id']}_{$safeName}.pdf";
$pdf->Output($filename, 'I');
exit;
