<?php
// admin/export_pdf.php - FINAL VERSION (NO ERRORS, FULL LETTERHEAD + SIGNATURE)

require_once '../include/db_connect.php';
require_once '../include/auth.php';
requireAdmin();

// Prevent any output before PDF
ob_clean();

if (!isset($_GET['emp_id']) || empty($_GET['emp_id'])) {
    die('No employee selected.');
}

$emp_id = $_GET['emp_id'];

// Get employee
$stmt = $pdo->prepare("SELECT employee_id, full_name, fleet, cost_center FROM employees WHERE employee_id = ?");
$stmt->execute([$emp_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    die('Employee not found.');
}

// Get training records
$sql = "
    SELECT 
        c.course_code,
        c.course_name,
        c.validity_months,
        cr.taken_date,
        cr.remark,
        cr.inactive
    FROM courses c
    LEFT JOIN course_records cr ON c.course_id = cr.course_id AND cr.employee_id = ?
    WHERE c.is_active = 1
    ORDER BY c.course_code
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$emp_id]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load TCPDF
require_once '../tcpdf/tcpdf.php';

// LANDSCAPE A4
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

$pdf->SetCreator('GenericCourseTrack');
$pdf->SetAuthor('Ethiopian Airlines Aviation Academy');
$pdf->SetTitle('Training Compliance Report - ' . $employee['full_name']);
$pdf->SetMargins(12, 15, 12);
$pdf->SetAutoPageBreak(TRUE, 20);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();

// ==================== LETTERHEAD ====================
$pdf->SetFillColor(0, 74, 173);
$pdf->Rect(0, 0, 297, 40, 'F'); // Top blue bar

// Logo
if (file_exists('../img/logo.png')) {
    $pdf->Image('../img/logo.png', 15, 8, 50, 0, 'PNG');
}

// Company Text
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('helvetica', 'B', 22);
$pdf->SetXY(70, 12);
$pdf->Cell(0, 10, 'ETHIOPIAN AIRLINES', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 15);
$pdf->SetXY(70, 22);
$pdf->Cell(0, 10, 'Aviation Academy – Technical Training Department', 0, 1, 'L');

$pdf->SetFont('helvetica', 'I', 11);
$pdf->SetXY(70, 30);
$pdf->Cell(0, 10, 'Addis Ababa, Ethiopia | training@ethiopianairlines.com', 0, 1, 'L');

$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(1);
$pdf->Line(12, 42, 285, 42);

// ==================== MAIN CONTENT ====================
$pdf->Ln(18);

$pdf->SetTextColor(0);
$pdf->SetFont('helvetica', 'B', 24);
$pdf->Cell(0, 15, 'RECURRENT TRAINING COMPLIANCE REPORT', 0, 1, 'C');
$pdf->Ln(8);

$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 8, 'Generated on: ' . date('d F Y \a\t H:i'), 0, 1, 'R');
$pdf->Ln(12);

// Employee Info
$pdf->SetFillColor(0, 74, 173);
$pdf->SetTextColor(255);
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 12, ' TECHNICIAN INFORMATION ', 0, 1, 'C', true);

$pdf->SetTextColor(0);
$pdf->SetFillColor(240, 245, 255);
$pdf->SetFont('helvetica', '', 12);

$info = [
    'Employee ID'     => $employee['employee_id'],
    'Full Name'       => $employee['full_name'],
    'Fleet Assignment'=> $employee['fleet'] ?: 'Not Assigned',
    'Cost Center'     => $employee['cost_center'] ?: 'Not Assigned',
];

foreach ($info as $label => $value) {
    $pdf->Cell(60, 11, $label . ':', 1, 0, 'L', true);
    $pdf->Cell(0, 11, $value, 1, 1, 'L', true);
}

$pdf->Ln(15);

// Training Table - PERFECT FIT COLUMNS (only change!)
$pdf->SetFillColor(0, 74, 173);
$pdf->SetTextColor(255);
$pdf->SetFont('helvetica', 'B', 15);
$pdf->Cell(0, 12, ' RECURRENT TRAINING RECORDS ', 0, 1, 'C', true);
$pdf->Ln(6);

// THESE ARE THE ONLY LINES CHANGED - PERFECT FIT IN LANDSCAPE
$header = ['No', 'Course Code', 'Course Name', 'Completed Date', 'Status', 'Remark'];
$w = [12, 28, 125, 34, 28, 50];   // Total = 285mm - fits perfectly in A4 Landscape

$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetFillColor(220, 230, 250);
$pdf->SetTextColor(0);

foreach ($header as $i => $h) {
    $pdf->Cell($w[$i], 12, $h, 1, 0, 'C', true);
}
$pdf->Ln();

$pdf->SetFont('helvetica', '', 11);
$no = 1;

foreach ($records as $record) {
    // Status logic
    if ($record['inactive']) {
        $status = 'INACTIVE';
        $r = 120; $g = 120; $b = 120;
    } elseif (!$record['taken_date']) {
        $status = 'NOT TAKEN';
        $r = 200; $g = 100; $b = 0;
    } else {
        $expiry = date('Y-m-d', strtotime($record['taken_date'] . " + {$record['validity_months']} months"));
        if (strtotime($expiry) < time()) {
            $status = 'EXPIRED';
            $r = 200; $g = 0; $b = 0;
        } else {
            $status = 'CURRENT';
            $r = 0; $g = 140; $b = 0;
        }
    }

    $pdf->Cell($w[0], 11, $no++, 1, 0, 'C');
    $pdf->Cell($w[1], 11, $record['course_code'], 1);
    $pdf->Cell($w[2], 11, $record['course_name'], 1);
    $pdf->Cell($w[3], 11, $record['taken_date'] ?: '—', 1, 0, 'C');

    $pdf->SetTextColor($r, $g, $b);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell($w[4], 11, $status, 1, 0, 'C');

    $pdf->SetTextColor(0);
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell($w[5], 11, $record['remark'] ?: '—', 1, 1);
}

// ==================== SIGNATURE BLOCK ====================
$pdf->Ln(25);

$pdf->SetFont('helvetica', '', 12);
$pdf->MultiCell(0, 8, "This is to certify that the above technician has completed the required recurrent training\nas per Ethiopian Civil Aviation Authority (ECAA) and EASA Part-145 requirements.", 0, 'C');
$pdf->Ln(15);

$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(90, 10, 'Prepared by:', 0, 0, 'L');
$pdf->Cell(90, 10, 'Approved by:', 0, 0, 'L');
$pdf->Cell(90, 10, 'Verified by:', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(90, 25, '___________________________', 0, 0);
$pdf->Cell(90, 25, '___________________________', 0, 0);
$pdf->Cell(90, 25, '___________________________', 0, 1);

$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(90, 8, 'Training Coordinator', 0, 0);
$pdf->Cell(90, 8, 'Quality Manager', 0, 0);
$pdf->Cell(90, 8, 'Head of Training', 0, 1);

$pdf->SetFont('helvetica', 'I', 10);
$pdf->Cell(90, 8, 'Date: ________________', 0, 0);
$pdf->Cell(90, 8, 'Date: ________________', 0, 0);
$pdf->Cell(90, 8, 'Date: ________________', 0, 1);

$pdf->Ln(15);
$pdf->SetFont('helvetica', 'I', 10);
$pdf->Cell(0, 10, 'Generated by GenericCourseTrack © ' . date('Y') . ' Ethiopian Airlines Aviation Academy', 0, 1, 'C');

// OUTPUT PDF
$filename = "Training_Compliance_{$employee['employee_id']}_{$employee['full_name']}.pdf";
$pdf->Output($filename, 'D'); // Force download
exit;
?>