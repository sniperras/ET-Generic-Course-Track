<?php
// admin/export_pdf.php - TWO-PAGE FIT VERSION

require_once '../include/db_connect.php';
require_once '../include/auth.php';
requireAdmin();

// Clear any output
if (ob_get_level()) { ob_end_clean(); }

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
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('GenericCourseTrack');
$pdf->SetAuthor('Ethiopian Airlines Aviation Academy');
$pdf->SetTitle('Training Compliance Report - ' . $employee['full_name']);
$pdf->SetMargins(12, 15, 12); // left, top, right
$pdf->SetAutoPageBreak(TRUE, 20);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();

// ---------------- LETTERHEAD ----------------
$pdf->SetFillColor(0, 74, 173);
$pdf->Rect(0, 0, 297, 40, 'F');
if (file_exists('../img/logo.png')) {
    $pdf->Image('../img/logo.png', 15, 8, 50, 0, 'PNG');
}
$pdf->SetTextColor(255,255,255);
$pdf->SetFont('helvetica', 'B', 22);
$pdf->SetXY(70, 12);
$pdf->Cell(0, 10, 'ETHIOPIAN AIRLINES', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 15);
$pdf->SetXY(70, 22);
$pdf->Cell(0, 10, 'Aviation Academy – Technical Training Department', 0, 1, 'L');
$pdf->SetFont('helvetica', 'I', 11);
$pdf->SetXY(70, 30);
$pdf->Cell(0, 10, 'Addis Ababa, Ethiopia | training@ethiopianairlines.com', 0, 1, 'L');
$pdf->SetDrawColor(0,0,0);
$pdf->SetLineWidth(1);
$pdf->Line(12, 42, 285, 42);

// ---------------- MAIN TITLE + META ----------------
$pdf->Ln(18);
$pdf->SetTextColor(0);
$pdf->SetFont('helvetica', 'B', 24);
$pdf->Cell(0, 15, 'RECURRENT TRAINING COMPLIANCE REPORT', 0, 1, 'C');
$pdf->Ln(8);
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 8, 'Generated on: ' . date('d F Y \a\t H:i'), 0, 1, 'R');
$pdf->Ln(12);

// ---------------- EMPLOYEE INFO ----------------
$pdf->SetFillColor(0, 74, 173);
$pdf->SetTextColor(255);
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 12, ' TECHNICIAN INFORMATION ', 0, 1, 'C', true);

$pdf->SetTextColor(0);
$pdf->SetFillColor(240,245,255);
$pdf->SetFont('helvetica', '', 12);

$info = [
    'Employee ID' => $employee['employee_id'],
    'Full Name' => $employee['full_name'],
    'Position' => $employee['position'] ?: 'Not Assigned',
    'Department' => $employee['department'] ?: 'Not Assigned',
    'Fleet Assignment' => $employee['fleet'] ?: 'Not Assigned',
    'Cost Center' => $employee['cost_center'] ?: 'Not Assigned',
];

foreach ($info as $label => $value) {
    $pdf->Cell(60, 11, $label . ':', 1, 0, 'L', true);
    $pdf->Cell(0, 11, $value, 1, 1, 'L', true);
}
$pdf->Ln(8); // smaller gap to save space

// ---------------- PREPARE TABLE (we will compute how many rows per page) ----------------
$header = ['No', 'Course Code', 'Course Name', 'Completed Date', 'Status', 'Remark'];
// Column widths chosen to exactly fit inside usable width (297 - left - right)
$w = [12, 28, 120, 34, 36, 40]; // total = 270 (approx). Adjusted for margins.

$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetFillColor(220,230,250);
$pdf->SetTextColor(0);

// Print table header now (on first page) and determine where the table starts
foreach ($header as $i => $h) { $pdf->Cell($w[$i], 12, $h, 1, 0, 'C', true); }
$pdf->Ln();

// get measurements
$pageHeight = $pdf->getPageHeight();              // page height in mm (210 for A4 portrait; we are landscape)
$breakMargin = $pdf->getBreakMargin();            // bottom margin for auto-page-break
$margins = $pdf->getMargins();                    // array with left, top, right probably keys ('left','top','right')
$topMargin = is_array($margins) && isset($margins['top']) ? $margins['top'] : ($margins[1] ?? 15);

// available space for rows on page 1 after printing header
$startY = $pdf->GetY();
$available_first = $pageHeight - $breakMargin - $startY;

// available space for rows on page 2 (conservative): assume table header will be printed on top of page 2
$tableHeaderHeight = 12; // we used 12 for header height
$available_second = $pageHeight - $breakMargin - $topMargin - $tableHeaderHeight - 4; // small padding

$total_rows = count($records);

// Choose row height & font size so everything fits into two pages
$chosen_row_h = null;
$chosen_font = null;

// Try row heights from 11 down to 6 (mm) and pick the first that fits
for ($row_h = 11; $row_h >= 6; $row_h--) {
    // choose font size associated (rough mapping)
    if ($row_h >= 11) $fs = 11;
    elseif ($row_h === 10) $fs = 10;
    elseif ($row_h >= 8) $fs = 9;
    else $fs = 8;

    $fit1 = (int) floor($available_first / $row_h);
    $fit2 = (int) floor($available_second / $row_h);
    if ($fit1 + $fit2 >= $total_rows) {
        $chosen_row_h = $row_h;
        $chosen_font = $fs;
        $rows_first = $fit1;
        $rows_second = $fit2;
        break;
    }
}

// If nothing found, use smallest with larger capacity but still force two pages by splitting
if ($chosen_row_h === null) {
    $chosen_row_h = 6;
    $chosen_font = 8;
    $rows_first = (int) floor($available_first / $chosen_row_h);
    $rows_second = (int) floor($available_second / $chosen_row_h);
    // If still not enough, we will still split by ceil(total/2) below
}

// If computed capacities are tiny, ensure at least 1 row per page
$rows_first = max(1, $rows_first ?? 0);
$rows_second = max(1, $rows_second ?? 0);

// determine exact split: try to fill first page as much as possible (but leaving at least 1 row for page 2)
if ($rows_first >= $total_rows) {
    // everything fits on first page (rare), just put all there and keep signature on same page
    $per_page_first = $total_rows;
    $per_page_second = 0;
} else {
    // otherwise we want everything on two pages; cap first page to rows_first, but also ensure not empty second page
    $per_page_first = min($rows_first, $total_rows - 1);
    $per_page_second = $total_rows - $per_page_first;
    // If computed second capacity insufficient for remaining, re-balance: do ceil(total/2)
    if ($per_page_second > $rows_second) {
        $per_page_first = (int) ceil($total_rows / 2);
        $per_page_second = $total_rows - $per_page_first;
    }
}

// Now print rows with chosen sizes, repeating header on second page, signature only on last page
$pdf->SetFont('helvetica', '', $chosen_font);

// pointer to records
$idx = 0;
$no = 1;

// helper to (re)print table header (used for page 2)
$printTableHeader = function() use ($pdf, $header, $w) {
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(220,230,250);
    $pdf->SetTextColor(0);
    foreach ($header as $i => $h) { $pdf->Cell($w[$i], 12, $h, 1, 0, 'C', true); }
    $pdf->Ln();
    $pdf->SetFont('helvetica', '', 11);
};

// Print first page rows (up to $per_page_first)
for ($i = 0; $i < $per_page_first; $i++) {
    $record = $records[$idx++];

    // compute color for status
    switch (strtolower($record['status'])) {
        case 'current': $r=0;$g=140;$b=0; break;
        case 'expired': $r=200;$g=0;$b=0; break;
        case 'pending': $r=200;$g=100;$b=0; break;
        default: $r=0;$g=0;$b=0; break;
    }

    $pdf->Cell($w[0], $chosen_row_h, $no++, 1, 0, 'C');
    $pdf->Cell($w[1], $chosen_row_h, $record['course_code'], 1, 0, 'L');
    // Course name may be long; use MultiCell if it wraps (but keep row aligned)
    $xBefore = $pdf->GetX();
    $yBefore = $pdf->GetY();
    $pdf->Cell($w[2], $chosen_row_h, $record['course_name'], 1, 0, 'L');
    $pdf->Cell($w[3], $chosen_row_h, $record['taken_date'] ?: '—', 1, 0, 'C');
    $pdf->SetTextColor($r,$g,$b);
    $pdf->SetFont('helvetica', 'B', $chosen_font);
    $pdf->Cell($w[4], $chosen_row_h, ucfirst($record['status']), 1, 0, 'C');
    $pdf->SetTextColor(0);
    $pdf->SetFont('helvetica', '', $chosen_font);
    // empty remark
    $pdf->Cell($w[5], $chosen_row_h, '', 1, 1);
}

// If there are remaining rows, add new page and print header then remaining rows
if ($idx < $total_rows) {
    $pdf->AddPage();
    // Print the table header on the second page
    $printTableHeader();

    // How many to print on second page: either $per_page_second or remaining
    $to_print = min($per_page_second, $total_rows - $idx);
    // If $to_print is 0, print remaining
    if ($to_print <= 0) $to_print = $total_rows - $idx;

    for ($j = 0; $j < $to_print; $j++) {
        $record = $records[$idx++];

        switch (strtolower($record['status'])) {
            case 'current': $r=0;$g=140;$b=0; break;
            case 'expired': $r=200;$g=0;$b=0; break;
            case 'pending': $r=200;$g=100;$b=0; break;
            default: $r=0;$g=0;$b=0; break;
        }

        $pdf->Cell($w[0], $chosen_row_h, $no++, 1, 0, 'C');
        $pdf->Cell($w[1], $chosen_row_h, $record['course_code'], 1, 0, 'L');
        $pdf->Cell($w[2], $chosen_row_h, $record['course_name'], 1, 0, 'L');
        $pdf->Cell($w[3], $chosen_row_h, $record['taken_date'] ?: '—', 1, 0, 'C');
        $pdf->SetTextColor($r,$g,$b);
        $pdf->SetFont('helvetica', 'B', $chosen_font);
        $pdf->Cell($w[4], $chosen_row_h, ucfirst($record['status']), 1, 0, 'C');
        $pdf->SetTextColor(0);
        $pdf->SetFont('helvetica', '', $chosen_font);
        $pdf->Cell($w[5], $chosen_row_h, '', 1, 1);
    }

    // If there are still remaining rows (shouldn't happen), print them on the same second page (they will wrap or overflow).
    while ($idx < $total_rows) {
        $record = $records[$idx++];
        switch (strtolower($record['status'])) {
            case 'current': $r=0;$g=140;$b=0; break;
            case 'expired': $r=200;$g=0;$b=0; break;
            case 'pending': $r=200;$g=100;$b=0; break;
            default: $r=0;$g=0;$b=0; break;
        }
        $pdf->Cell($w[0], $chosen_row_h, $no++, 1, 0, 'C');
        $pdf->Cell($w[1], $chosen_row_h, $record['course_code'], 1, 0, 'L');
        $pdf->Cell($w[2], $chosen_row_h, $record['course_name'], 1, 0, 'L');
        $pdf->Cell($w[3], $chosen_row_h, $record['taken_date'] ?: '—', 1, 0, 'C');
        $pdf->SetTextColor($r,$g,$b);
        $pdf->SetFont('helvetica', 'B', $chosen_font);
        $pdf->Cell($w[4], $chosen_row_h, ucfirst($record['status']), 1, 0, 'C');
        $pdf->SetTextColor(0);
        $pdf->SetFont('helvetica', '', $chosen_font);
        $pdf->Cell($w[5], $chosen_row_h, '', 1, 1);
    }
}

// ---------------- SIGNATURE BLOCK (ONLY on the last page) ----------------
$pdf->Ln(6);
$pdf->SetFont('helvetica', '', 12);
$pdf->MultiCell(0, 8, "This is to certify that the above technician has completed the required recurrent training\nas per Ethiopian Civil Aviation Authority (ECAA) and EASA Part-145 requirements.", 0, 'C');
$pdf->Ln(12);
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
$pdf->Ln(6);
$pdf->SetFont('helvetica', 'I', 10);
$pdf->Cell(0, 10, 'Generated by GenericCourseTrack © ' . date('Y') . ' Ethiopian Airlines Aviation Academy', 0, 1, 'C');

// OUTPUT
$safeName = preg_replace('/[^A-Za-z0-9_\-\. ]/', '_', ($employee['full_name'] ?? $emp_id));
$filename = "Training_Compliance_{$employee['employee_id']}_{$safeName}.pdf";
$pdf->Output($filename, 'D');
exit;
