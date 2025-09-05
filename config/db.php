<?php
// --- DATABASE CONFIGURATION ---
$servername = "localhost"; 
$username = "root";        // Your database username
$password = "";            // Your database password (XAMPP default is empty)
$dbname = "cash";          // Your database name

// --- DATABASE CONNECTION (MySQLi) ---
// This connection is for older pages that use the '$conn' variable.
$conn = new mysqli($servername, $username, $password, $dbname);

// Check MySQLi connection
if ($conn->connect_error) {
    die("MySQLi Connection failed: " . $conn->connect_error);
}

// --- DATABASE CONNECTION (PDO) ---
// This connection is for newer pages that use the '$pdo' variable for more security.
try {
    $pdo = new PDO("mysql:host=" . $servername . ";dbname=" . $dbname, $username, $password);
    // Set the PDO error mode to exception to catch errors
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // If the connection fails, stop the script and show an error message.
    die("PDO Connection failed: " . $e->getMessage());
}
?>
