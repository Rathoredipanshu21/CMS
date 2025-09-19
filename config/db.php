<?php
// --- DATABASE CONFIGURATION ---
$servername = "localhost"; 
$username = "root";        // Your database username
$password = "";            // Your database password (XAMPP default is empty)
$dbname = "cash";          // Your database name
$charset = "utf8mb4";      // Character set for modern applications

/**
 * -------------------------------------------------------------------------
 * DATABASE CONNECTION (MySQLi)
 * -------------------------------------------------------------------------
 * This connection uses the MySQLi extension and the '$conn' variable.
 * It is included to ensure your older pages continue to work without any changes.
 */
$conn = new mysqli($servername, $username, $password, $dbname);

// Check MySQLi connection
if ($conn->connect_error) {
    die("MySQLi Connection failed: " . $conn->connect_error);
}


/**
 * -------------------------------------------------------------------------
 * DATABASE CONNECTION (PDO) - RECOMMENDED
 * -------------------------------------------------------------------------
 * This is the modern, more secure PDO connection using the '$pdo' variable.
 * The new pages (cash_demo.php, cash_denomination.php) use this method.
 * It includes better error handling and more secure default settings.
 */
$dsn = "mysql:host=$servername;dbname=$dbname;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions on errors
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Fetch results as associative arrays
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Use true native prepared statements
];

try {
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (\PDOException $e) {
    // If the PDO connection fails, stop the script and show the error.
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
?>