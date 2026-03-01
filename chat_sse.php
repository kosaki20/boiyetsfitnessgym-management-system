<?php
session_start();
require_once 'chat_functions.php';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');

require_once 'includes/db_connection.php';
$user_id = $_GET['user_id'] ?? 0;
$last_id = $_GET['last_id'] ?? 0;

// Set longer execution time for SSE
set_time_limit(0);

// Clean up old typing indicators
cleanupTypingIndicators($conn);

// Send initial connection message
echo "data: " . json_encode([
    'type' => 'connected',
    'message' => 'SSE connection established'
]) . "\n\n";
ob_flush();
flush();

while (true) {
    // Check for new messages
    $new_messages = getNewMessages($user_id, $last_id, $conn);
    
    foreach ($new_messages as $message) {
        echo "data: " . json_encode([
            'type' => 'new_message',
            'message' => $message
        ]) . "\n\n";
        
        ob_flush();
        flush();
        
        $last_id = max($last_id, $message['id']);
    }
    
    // Check for typing indicators
    $typing_users = getTypingStatus($user_id, $conn);
    foreach ($typing_users as $typing_user) {
        echo "data: " . json_encode([
            'type' => 'typing',
            'sender_id' => $typing_user['user_id'],
            'sender_name' => $typing_user['full_name'] ?? $typing_user['username']
        ]) . "\n\n";
        
        ob_flush();
        flush();
    }
    
    // If no typing users found, send stop typing for all
    if (empty($typing_users)) {
        echo "data: " . json_encode([
            'type' => 'stop_typing'
        ]) . "\n\n";
        
        ob_flush();
        flush();
    }
    
    // Sleep for 1 second before checking again
    sleep(1);
    
    // Break the loop if client disconnected
    if (connection_aborted()) {
        break;
    }
}

$conn->close();
?>



