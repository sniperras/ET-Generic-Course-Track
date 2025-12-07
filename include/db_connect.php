<?php
// include/db_connect.php - Database Connection (PDO + Secure)

$host = 'localhost';
$db   = 'GenericCourseTrack';
$user = 'root';        // default XAMPP
$pass = '';            // default XAMPP (empty)
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    // In production hide this, but for dev it's helpful
    die("Connection failed: " . $e->getMessage());
}
?>