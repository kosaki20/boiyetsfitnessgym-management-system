<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once 'includes/db_connection.php';

require_once 'notification_functions.php';

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'mark_all_read') {
        $success = markAllNotificationsAsRead($conn, $user_id);
        
        if ($success) {
            echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to mark notifications as read']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}

$conn->close();
?>



