<?php
session_start();
require_once 'chat_functions.php';

require_once 'includes/db_connection.php';
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



