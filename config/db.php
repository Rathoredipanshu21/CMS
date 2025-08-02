<?php
// config/db.php

$servername = "localhost"; // Or your DB host
$username = "root";        // Your database username
$password = "";            // Your database password (XAMPP default is empty)
$dbname = "cash"; // <<-- CHANGE THIS to your database name

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>