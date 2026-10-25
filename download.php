<?php
// Define the path to your uploads folder
// __DIR__ points to the directory where download.php is located
$uploadDir = __DIR__ . '/uploads/';

// The actual filename stored in your uploads folder
// (Make sure this matches the exact file name on your server)
$fileName = "ETTPM  FOR TL ET MOR DOC.pdf";
$filePath = $uploadDir . $fileName;

// Check if the file exists on the server
if (file_exists($filePath)) {
    // Set headers to force download and name the downloaded file cleanly
    header('Content-Description: File Transfer');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Ethiopian_Technical_Training_Program_Manual.pdf"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($filePath));

    // Clear system output buffer to prevent memory exhaustion on large files
    flush();

    // Read the file from the local server directory and stream it to the user
    readfile($filePath);
    exit;
} else {
    http_response_code(404);
    echo "Error: The PDF file could not be found in the uploads directory.";
}
