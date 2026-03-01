<?php
// chat_functions.php

function getChatUsers($current_user_id, $current_user_role, $conn) {
    $users = [];
    
    if ($current_user_role == 'admin') {
        // Admin can chat with trainers and clients
        $sql = "SELECT id, username, full_name, role, 
                       (SELECT COUNT(*) FROM chat_messages 
                        WHERE sender_id = users.id 
                        AND receiver_id = ? 
                        AND is_read = FALSE) as unread_count
                FROM users 
                WHERE role IN ('trainer', 'client') 
                AND id != ?
                ORDER BY role, full_name";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $current_user_id, $current_user_id);
    } elseif ($current_user_role == 'trainer') {
        // Trainers can chat with admin and their assigned clients
        $sql = "SELECT DISTINCT u.id, u.username, u.full_name, u.role,
                       (SELECT COUNT(*) FROM chat_messages 
                        WHERE sender_id = u.id 
                        AND receiver_id = ? 
                        AND is_read = FALSE) as unread_count
                FROM users u
                LEFT JOIN trainer_client_assignments tca ON u.id = tca.client_user_id
                WHERE u.role = 'admin' 
                   OR (u.role = 'client' AND tca.trainer_user_id = ?)
                   OR u.id = ?
                ORDER BY u.role, u.full_name";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $current_user_id, $current_user_id, $current_user_id);
    } else {
        // Clients can chat with admin and their assigned trainer
        $sql = "SELECT u.id, u.username, u.full_name, u.role,
                       (SELECT COUNT(*) FROM chat_messages 
                        WHERE sender_id = u.id 
                        AND receiver_id = ? 
                        AND is_read = FALSE) as unread_count
                FROM users u
                LEFT JOIN trainer_client_assignments tca ON u.id = tca.trainer_user_id
                WHERE (u.role = 'admin' OR (u.role = 'trainer' AND tca.client_user_id = ?))
                AND u.id != ?
                ORDER BY u.role, u.full_name";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $current_user_id, $current_user_id, $current_user_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    $stmt->close();
    return $users;
}

function getChatMessages($user1_id, $user2_id, $conn) {
    $sql = "SELECT cm.*, u.full_name as sender_name, u.role as sender_role
            FROM chat_messages cm
            JOIN users u ON cm.sender_id = u.id
            WHERE (cm.sender_id = ? AND cm.receiver_id = ?) 
               OR (cm.sender_id = ? AND cm.receiver_id = ?)
            ORDER BY cm.created_at ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiii", $user1_id, $user2_id, $user2_id, $user1_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
    
    $stmt->close();
    
    // Mark messages as read
    markMessagesAsRead($user1_id, $user2_id, $conn);
    
    return $messages;
}

function sendMessage($sender_id, $sender_role, $receiver_id, $receiver_role, $message, $conn) {
    // Validate message length
    if (strlen($message) > 1000) {
        error_log("Message too long: " . strlen($message) . " characters");
        return false;
    }
    
    $sql = "INSERT INTO chat_messages (sender_id, sender_role, receiver_id, receiver_role, message) 
            VALUES (?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isiss", $sender_id, $sender_role, $receiver_id, $receiver_role, $message);
    $result = $stmt->execute();
    
    if (!$result) {
        error_log("Chat message send failed: " . $conn->error);
        $stmt->close();
        return false;
    }
    
    $message_id = $conn->insert_id;
    $stmt->close();
    
    return $message_id;
}

function sendMessageWithAttachment($sender_id, $sender_role, $receiver_id, $receiver_role, $message, $attachment_path, $attachment_type, $conn) {
    // Validate message length
    if (strlen($message) > 1000) {
        error_log("Message too long: " . strlen($message) . " characters");
        return false;
    }
    
    $sql = "INSERT INTO chat_messages (sender_id, sender_role, receiver_id, receiver_role, message, attachment_path, attachment_type) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    // Corrected: 7 parameters need 7 type specifiers: i=integer, s=string
    $stmt->bind_param("isissss", $sender_id, $sender_role, $receiver_id, $receiver_role, $message, $attachment_path, $attachment_type);
    $result = $stmt->execute();
    
    if (!$result) {
        error_log("Chat message with attachment send failed: " . $conn->error);
        $stmt->close();
        return false;
    }
    
    $message_id = $conn->insert_id;
    $stmt->close();
    
    return $message_id;
}

function markMessagesAsRead($receiver_id, $sender_id, $conn) {
    $sql = "UPDATE chat_messages 
            SET is_read = TRUE 
            WHERE sender_id = ? AND receiver_id = ? AND is_read = FALSE";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $sender_id, $receiver_id);
    $result = $stmt->execute();
    
    if (!$result) {
        error_log("Mark messages as read failed: " . $conn->error);
    }
    
    $stmt->close();
    return $result;
}

function getUnreadCount($user_id, $conn) {
    $sql = "SELECT COUNT(*) as unread_count 
            FROM chat_messages 
            WHERE receiver_id = ? AND is_read = FALSE";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['unread_count'] ?? 0;
}

function getNewMessages($user_id, $last_message_id, $conn) {
    $sql = "SELECT cm.*, u.full_name as sender_name, u.role as sender_role
            FROM chat_messages cm
            JOIN users u ON cm.sender_id = u.id
            WHERE cm.receiver_id = ? AND cm.id > ?
            ORDER BY cm.created_at ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $user_id, $last_message_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
    
    $stmt->close();
    return $messages;
}

// Typing indicator functions
function setTypingStatus($user_id, $receiver_id, $is_typing, $conn) {
    $sql = "REPLACE INTO typing_indicators (user_id, receiver_id, is_typing, last_updated) 
            VALUES (?, ?, ?, NOW())";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $user_id, $receiver_id, $is_typing);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

function getTypingStatus($user_id, $conn) {
    $sql = "SELECT ti.user_id, u.full_name, u.role 
            FROM typing_indicators ti 
            JOIN users u ON ti.user_id = u.id 
            WHERE ti.receiver_id = ? AND ti.is_typing = 1 
            AND ti.last_updated > DATE_SUB(NOW(), INTERVAL 3 SECOND)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $typing_users = [];
    while ($row = $result->fetch_assoc()) {
        $typing_users[] = $row;
    }
    
    $stmt->close();
    return $typing_users;
}

// Clean up old typing indicators
function cleanupTypingIndicators($conn) {
    $sql = "DELETE FROM typing_indicators WHERE last_updated < DATE_SUB(NOW(), INTERVAL 5 SECOND)";
    $conn->query($sql);
}

function getUserDisplayName($user) {
    return !empty($user['full_name']) ? $user['full_name'] : $user['username'];
}
?>



