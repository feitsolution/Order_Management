<?php
// Database connection
$servername = "localhost";
$username = "root";
$password = ""; // Use your actual database password
$dbname = "order_management"; // Replace with your database name

$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
