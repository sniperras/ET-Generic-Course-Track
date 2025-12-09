<?php
// db_connect.php - CHANGE ONLY THESE 4 LINES WITH YOUR HOSTING DETAILS
$host     = 'sql211.infinityfree.com';                   // ← YOUR DB HOST
$dbname   = 'if0_40621574_genericcoursetrack';           // ← YOUR DATABASE NAME
$username = 'if0_40621574';                              // ← YOUR DB USERNAME
$password = 'QllUAVmSRmK8';                              // ← YOUR DB PASSWORD (from hosting panel)

try {
    // Create PDO connection
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
// allow large SELECTs for this session (may be allowed on your host)
try {
    $pdo->exec("SET SESSION SQL_BIG_SELECTS=1");
} catch (PDOException $e) {
    // If the host forbids this, the query below will still run but may throw the same error.
    error_log("Could not enable SQL_BIG_SELECTS: " . $e->getMessage());
}

    // Set recommended attributes
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // In production, log instead of showing errors
    die("Connection failed: " . $e->getMessage());
}
?>
