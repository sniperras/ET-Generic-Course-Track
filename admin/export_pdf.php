<?php
// admin/export_pdf.php - SINGLE PAGE PORTRAIT VERSION (FIXED LAYOUT)

require_once '../include/db_connect.php';
require_once '../include/auth.php';
requireAdmin();

// Clear any output
if (ob_get_level()) {
    ob_end_clean();
}

if (!isset($_GET['emp_id']) || empty($_GET['emp_id'])) {
    die('No employee selected.');
}
$emp_id = trim($_GET['emp_id']);

// Get employee info
$stmt = $pdo->prepare("SELECT employee_id, full_name, position, department, fleet, cost_center FROM employees WHERE employee_id = ? AND is_active = 1");
$stmt->execute([$emp_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$employee) die('Employee not found.');

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
    $table_name = $course['table_name'];
    if ($table_name === 'course_records_legistlation') {
        $table_name = 'course_records_legislation';
    }

    $taken_date = '';
    $status = 'NA';

    if ($table_name && preg_match('/^course_records_[a-zA-Z0-9_]+$/', $table_name)) {
        try {
            $sql = "SELECT CompletedDate, Status FROM `$table_name` WHERE ID = ? LIMIT 1";
            $stmt2 = $pdo->prepare($sql);
            $stmt2->execute([$emp_id]);
            $rec = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($rec) {
                $taken_date = ($rec['CompletedDate'] && $rec['CompletedDate'] !== '0000-00-00') ? $rec['CompletedDate'] : '';
                $status = $rec['Status'] ?? 'NA';
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $records[] = [
        'course_code' => $course['course_code'],
        'course_name' => $course['course_name'],
        'validity_months' => (int)($course['validity_months'] ?? 24),
        'taken_date' => $taken_date,
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
$pdf->SetAutoPageBreak(TRUE, 15);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();

// ---------------- LETTERHEAD ----------------
$pdf->SetFillColor(0, 74, 173);
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
$pdf->Cell(0, 6, 'Aviation Academy – Technical Training Department', 0, 1, 'L');

// Address
$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetXY(50, 21);
$pdf->Cell(0, 5, 'Addis Ababa, Ethiopia | training@ethiopianairlines.com', 0, 1, 'L');

// Separator Line
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.5);
$pdf->Line(10, 36, 200, 36);

// ---------------- MAIN TITLE ----------------
$pdf->Ln(10);
$pdf->SetTextColor(0);
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'RECURRENT TRAINING COMPLIANCE REPORT', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(0, 6, 'Generated on: ' . date('d F Y \a\t H:i'), 0, 1, 'R');
$pdf->Ln(6);

// ---------------- EMPLOYEE INFO SECTION ----------------
$pdf->SetFillColor(0, 74, 173);
$pdf->SetTextColor(255);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 8, ' TECHNICIAN INFORMATION ', 0, 1, 'C', true);

$pdf->SetTextColor(0);
$pdf->SetFillColor(245, 248, 250);
$pdf->SetFont('helvetica', '', 9);

// Employee info in two columns
$info_left = [
    'Employee ID' => $employee['employee_id'],
    'Full Name' => $employee['full_name'],
    'Position' => $employee['position'] ?: 'Not Assigned',
];

$info_right = [
    'Department' => $employee['department'] ?: 'Not Assigned',
    'Fleet Assignment' => $employee['fleet'] ?: 'Not Assigned',
    'Cost Center' => $employee['cost_center'] ?: 'Not Assigned',
];

$max_rows = max(count($info_left), count($info_right));
$left_keys = array_keys($info_left);
$right_keys = array_keys($info_right);

for ($i = 0; $i < $max_rows; $i++) {
    // Left column
    if (isset($left_keys[$i])) {
        $label = $left_keys[$i];
        $value = $info_left[$label];
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(40, 7, $label . ':', 1, 0, 'L', true);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(65, 7, $value, 1, 0, 'L', true);
    } else {
        $pdf->Cell(105, 7, '', 1, 0, 'L', true);
    }

    // Right column
    if (isset($right_keys[$i])) {
        $label = $right_keys[$i];
        $value = $info_right[$label];
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(40, 7, $label . ':', 1, 0, 'L', true);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 7, $value, 1, 1, 'L', true);
    } else {
        $pdf->Cell(0, 7, '', 1, 1, 'L', true);
    }
}
$pdf->Ln(4);

// ---------------- COURSES TABLE ----------------
// Calculate available space for table
$pageHeight = $pdf->getPageHeight();
$currentY = $pdf->GetY();
$availableSpace = $pageHeight - $currentY - 55; // Reserve 55mm for signature

$total_rows = count($records);
$row_height = 6.5;

// Adjust row height if needed
if ($total_rows * $row_height > $availableSpace) {
    $row_height = 5.5;
    $font_size = 7.5;
} else {
    $font_size = 8.5;
}

// Column widths that fit within A4 (190mm usable width)
$w = [7, 20, 105, 28, 30]; // Total: 190mm

$header = ['#', 'Code', 'Course Name', 'Date', 'Status'];

$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(220, 230, 250);
$pdf->SetTextColor(0);

// Print table header
foreach ($header as $i => $h) {
    $pdf->Cell($w[$i], 8, $h, 1, 0, 'C', true);
}
$pdf->Ln();

// Print all records
$no = 1;
$pdf->SetFont('helvetica', '', $font_size);

foreach ($records as $record) {
    // Get color and display text for status
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
    } elseif ($status_lower === 'pending') {
        $r = 255;
        $g = 140;
        $b = 0;
        $status_display = 'Pending';
    } else {
        $r = 108;
        $g = 117;
        $b = 125;
        $status_display = $record['status'] ?: 'N/A';
    }

    // Check for page break
    if ($pdf->GetY() + $row_height > $pageHeight - 50) {
        $pdf->AddPage();
        // Reprint header on new page
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(220, 230, 250);
        foreach ($header as $i => $h) {
            $pdf->Cell($w[$i], 8, $h, 1, 0, 'C', true);
        }
        $pdf->Ln();
        $pdf->SetFont('helvetica', '', $font_size);
    }

    // Print row
    $pdf->Cell($w[0], $row_height, $no++, 1, 0, 'C');
    $pdf->Cell($w[1], $row_height, $record['course_code'], 1, 0, 'L');

    // Course name with truncation
    $course_name = $record['course_name'];
    $max_len = 55;
    if (mb_strlen($course_name) > $max_len) {
        $course_name = mb_substr($course_name, 0, $max_len - 3) . '...';
    }
    $pdf->Cell($w[2], $row_height, $course_name, 1, 0, 'L');
    $pdf->Cell($w[3], $row_height, $record['taken_date'] ?: '—', 1, 0, 'C');

    $pdf->SetTextColor($r, $g, $b);
    $pdf->SetFont('helvetica', 'B', $font_size);
    $pdf->Cell($w[4], $row_height, $status_display, 1, 0, 'C');
    $pdf->SetTextColor(0);
    $pdf->SetFont('helvetica', '', $font_size);
    $pdf->Ln();
}

$pdf->Ln(4);

// ---------------- SIGNATURE BLOCK (COMPACT) ----------------
$pdf->SetFont('helvetica', '', 8.5);
$pdf->MultiCell(0, 4.5, "This is to certify that the above technician has completed the required recurrent training as per Ethiopian Civil Aviation Authority (ECAA) and EASA Part-145 requirements.", 0, 'C');
$pdf->Ln(3);

// Signature lines - 3 columns with equal width
$col_width = 62; // 3 columns = 186mm total

$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell($col_width, 6, 'Prepared by:', 0, 0, 'C');
$pdf->Cell($col_width, 6, 'Approved by:', 0, 0, 'C');
$pdf->Cell($col_width, 6, 'Verified by:', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 9);
$pdf->Cell($col_width, 12, '_________________________', 0, 0, 'C');
$pdf->Cell($col_width, 12, '_________________________', 0, 0, 'C');
$pdf->Cell($col_width, 12, '_________________________', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 8);
$pdf->Cell($col_width, 5, 'Training Coordinator', 0, 0, 'C');
$pdf->Cell($col_width, 5, 'Quality Manager', 0, 0, 'C');
$pdf->Cell($col_width, 5, 'Head of Training', 0, 1, 'C');

$pdf->SetFont('helvetica', 'I', 8);
$pdf->Cell($col_width, 5, 'Date: _____________', 0, 0, 'C');
$pdf->Cell($col_width, 5, 'Date: _____________', 0, 0, 'C');
$pdf->Cell($col_width, 5, 'Date: _____________', 0, 1, 'C');

$pdf->Ln(4);
$pdf->SetFont('helvetica', 'I', 7);
$pdf->Cell(0, 5, 'Generated by GenericCourseTrack © ' . date('Y') . ' Ethiopian Airlines Aviation Academy', 0, 1, 'C');

// OUTPUT
$safeName = preg_replace('/[^A-Za-z0-9_\-\. ]/', '_', ($employee['full_name'] ?? $emp_id));
$filename = "Training_Compliance_{$employee['employee_id']}_{$safeName}.pdf";
$pdf->Output($filename, 'D');
exit;
