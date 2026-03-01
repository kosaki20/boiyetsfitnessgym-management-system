<?php
session_start();
require_once 'chat_functions.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
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

$user_id = $_POST['user_id'] ?? 0;
$receiver_id = $_POST['receiver_id'] ?? 0;
$typing = $_POST['typing'] ?? 0;

if ($user_id && $receiver_id) {
    setTypingStatus($user_id, $receiver_id, $typing, $conn);
}

$conn->close();
?>



