<?php
// notifications.php - Unified Notification System
session_start();
header('Content-Type: application/json');


/**
 * NOTIFICATION FUNCTIONS
 */

/**
 * Get unread notification count for a user
 */
function getUnreadNotificationCount($conn, $user_id, $user_role) {
    $sql = "SELECT COUNT(*) as count FROM notifications 
            WHERE (user_id = ? OR (user_id IS NULL AND role = ?) OR (user_id IS NULL AND role IS NULL))
            AND read_status = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $user_id, $user_role);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['count'];
}

/**
 * Get user notifications with role consideration
 */
function getUserNotifications($conn, $user_id, $user_role, $limit = 10) {
    $sql = "SELECT * FROM notifications 
            WHERE (user_id = ? OR (user_id IS NULL AND role = ?) OR (user_id IS NULL AND role IS NULL))
            ORDER BY created_at DESC LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isi", $user_id, $user_role, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    return $notifications;
}

/**
 * Mark all notifications as read for a user
 */
function markAllNotificationsAsRead($conn, $user_id, $user_role) {
    $sql = "UPDATE notifications SET read_status = 1 
            WHERE (user_id = ? OR (user_id IS NULL AND role = ?)) 
            AND read_status = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $user_id, $user_role);
    return $stmt->execute();
}

/**
 * Create a new notification
 */
function createNotification($conn, $user_id, $role, $title, $message, $type = 'system', $priority = 'medium') {
    $sql = "INSERT INTO notifications (user_id, role, title, message, type, priority, read_status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, 0, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isssss", $user_id, $role, $title, $message, $type, $priority);
    return $stmt->execute();
}

/**
 * Get unread chat messages count
 */
function getUnreadChatCount($conn, $user_id) {
    $sql = "SELECT COUNT(*) as count FROM chat_messages 
            WHERE receiver_id = ? AND is_read = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['count'];
}

/**
 * Get dashboard notifications (announcements, memberships, etc.)
 */
function getDashboardNotifications($conn, $user_id, $user_role) {
    $notifications = [];

    // 1. Get active announcements
    $announcements_sql = "SELECT * FROM announcements 
                         WHERE (expiry_date >= CURDATE() OR expiry_date IS NULL) 
                         AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                         AND (target_audience = ? OR target_audience = 'all')
                         ORDER BY priority DESC, created_at DESC LIMIT 5";
    $stmt = $conn->prepare($announcements_sql);
    $target_audience = $user_role == 'trainer' ? 'trainers' : ($user_role == 'client' ? 'clients' : 'all');
    $stmt->bind_param("s", $target_audience);
    $stmt->execute();
    $announcements_result = $stmt->get_result();

    while ($row = $announcements_result->fetch_assoc()) {
        $notifications[] = [
            'type' => 'announcement',
            'id' => 'announcement_' . $row['id'],
            'title' => htmlspecialchars($row['title']),
            'message' => substr(htmlspecialchars($row['content']), 0, 100) . '...',
            'time' => $row['created_at'],
            'priority' => $row['priority'],
            'target_audience' => $row['target_audience']
        ];
    }

    // 2. Get expiring memberships (for trainers)
    if ($user_role == 'trainer') {
        $expiring_sql = "SELECT m.id, m.full_name, m.expiry_date 
                        FROM members m 
                        INNER JOIN trainer_client_assignments tca ON m.user_id = tca.client_user_id 
                        WHERE tca.trainer_user_id = ? 
                        AND m.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                        AND m.status = 'active'
                        ORDER BY m.expiry_date ASC";
        $stmt = $conn->prepare($expiring_sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $expiring_result = $stmt->get_result();
        
        while ($row = $expiring_result->fetch_assoc()) {
            $days_left = floor((strtotime($row['expiry_date']) - time()) / (60 * 60 * 24));
            $notifications[] = [
                'type' => 'membership',
                'id' => 'member_' . $row['id'],
                'title' => 'Membership Expiring',
                'message' => htmlspecialchars($row['full_name']) . "'s membership expires in " . $days_left . " day(s)",
                'time' => date('Y-m-d H:i:s'),
                'priority' => $days_left <= 3 ? 'high' : 'medium',
                'member_id' => $row['id']
            ];
        }
    }

    // 3. Get pending renewal requests (for trainers)
    if ($user_role == 'trainer') {
        $renewal_sql = "SELECT COUNT(*) as count FROM membership_renewal_requests 
                       WHERE trainer_id = ? AND status = 'pending'";
        $stmt = $conn->prepare($renewal_sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $renewal_result = $stmt->get_result();
        $renewal_count = $renewal_result->fetch_assoc()['count'];
        
        if ($renewal_count > 0) {
            $notifications[] = [
                'type' => 'renewal',
                'id' => 'renewal_requests',
                'title' => 'Pending Renewals',
                'message' => "You have {$renewal_count} pending membership renewal(s)",
                'time' => date('Y-m-d H:i:s'),
                'priority' => 'medium'
            ];
        }
    }

    // 4. Get equipment maintenance alerts (for admin)
    if ($user_role == 'admin') {
        $equipment_sql = "SELECT COUNT(*) as count FROM equipment 
                         WHERE status IN ('Needs Maintenance', 'Under Repair', 'Broken')";
        $result = $conn->query($equipment_sql);
        $equipment_count = $result->fetch_assoc()['count'];
        
        if ($equipment_count > 0) {
            $notifications[] = [
                'type' => 'equipment',
                'id' => 'equipment_alerts',
                'title' => 'Equipment Maintenance',
                'message' => "{$equipment_count} equipment item(s) need attention",
                'time' => date('Y-m-d H:i:s'),
                'priority' => 'medium'
            ];
        }
    }

    // Sort notifications by priority and time
    usort($notifications, function($a, $b) {
        $priority_order = ['high' => 3, 'medium' => 2, 'low' => 1];
        $a_priority = $priority_order[$a['priority']] ?? 1;
        $b_priority = $priority_order[$b['priority']] ?? 1;
        
        if ($a_priority != $b_priority) {
            return $b_priority - $a_priority;
        }
        return strtotime($b['time']) - strtotime($a['time']);
    });

    return $notifications;
}

/**
 * MAIN REQUEST HANDLER
 */
try {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }

    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['role'] ?? 'client';
    require_once 'includes/db_connection.php';

    // Handle POST requests (actions)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'mark_all_read':
                $success = markAllNotificationsAsRead($conn, $user_id, $user_role);
                echo json_encode([
                    'success' => $success, 
                    'message' => $success ? 'All notifications marked as read' : 'Failed to mark notifications as read'
                ]);
                break;
                
            case 'get_notifications':
                $notifications = getUserNotifications($conn, $user_id, $user_role);
                $unread_count = getUnreadNotificationCount($conn, $user_id, $user_role);
                echo json_encode([
                    'success' => true,
                    'notifications' => $notifications,
                    'unread_count' => $unread_count
                ]);
                break;
                
            case 'create_notification':
                if ($user_role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Permission denied']);
                    break;
                }
                
                $target_user_id = $_POST['user_id'] ?? null;
                $target_role = $_POST['role'] ?? null;
                $title = $_POST['title'] ?? '';
                $message = $_POST['message'] ?? '';
                $type = $_POST['type'] ?? 'system';
                $priority = $_POST['priority'] ?? 'medium';
                
                $success = createNotification($conn, $target_user_id, $target_role, $title, $message, $type, $priority);
                echo json_encode([
                    'success' => $success,
                    'message' => $success ? 'Notification created' : 'Failed to create notification'
                ]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } 
    // Handle GET requests (dashboard notifications)
    else {
        $dashboard_notifications = getDashboardNotifications($conn, $user_id, $user_role);
        $unread_chat_count = getUnreadChatCount($conn, $user_id);
        $unread_notification_count = getUnreadNotificationCount($conn, $user_id, $user_role);
        
        echo json_encode([
            'success' => true,
            'notifications' => $dashboard_notifications,
            'unread_chat_count' => $unread_chat_count,
            'unread_notification_count' => $unread_notification_count,
            'total_count' => count($dashboard_notifications) + $unread_chat_count
        ]);
    }

} catch (Exception $e) {
    error_log("Notifications error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
        'notifications' => [],
        'unread_chat_count' => 0,
        'unread_notification_count' => 0,
        'total_count' => 0
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>



