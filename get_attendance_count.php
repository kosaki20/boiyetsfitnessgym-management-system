<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'trainer') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Database connection
$servername = "localhost";
$username = "root";  // full Hostinger DB username
$password = "";           // your Hostinger DB password
$dbname = "boiyetsdb";         // full Hostinger DB name

$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

header('Content-Type: application/json');

// Get today's attendance count
$today = date('Y-m-d');
$result = $conn->query("SELECT COUNT(DISTINCT member_id) as total FROM attendance WHERE DATE(check_in) = '$today'");
$count = $result->fetch_assoc()['total'];

echo json_encode(['success' => true, 'count' => $count]);

$conn->close();
?>



