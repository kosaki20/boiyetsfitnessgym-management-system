<?php
session_start();
require_once 'chat_functions.php';

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
$user_id = $_GET['user_id'] ?? 0;
$last_id = $_GET['last_id'] ?? 0;

header('Content-Type: application/json');

if ($user_id) {
    $new_messages = getNewMessages($user_id, $last_id, $conn);
    echo json_encode(['messages' => $new_messages]);
} else {
    echo json_encode(['messages' => []]);
}

$conn->close();
?>



