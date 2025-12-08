<?php
// db_connect.php - CHANGE ONLY THESE 4 LINES WITH YOUR HOSTING DETAILS
$host     = 'sql211.infinityfree.com';                   // ← YOUR DB HOST
$dbname   = 'if0_40621574_genericcoursetrack';           // ← YOUR DATABASE NAME
$username = 'if0_40621574';                              // ← YOUR DB USERNAME
$password = 'QllUAVmSRmK8';                              // ← YOUR DB PASSWORD (from hosting panel)

try {
    // Create PDO connection
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);

    // Set recommended attributes
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // In production, log instead of showing errors
    die("Connection failed: " . $e->getMessage());
}
?>
