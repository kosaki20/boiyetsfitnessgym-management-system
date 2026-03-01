<?php
session_start();
require_once 'chat_functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['notifications' => []]);
    exit();
}

require_once 'includes/db_connection.php';

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

$notifications = [];

// Get unread messages count
$unread_count = getUnreadCount($user_id, $conn);

// Get active announcements (prepared statement)
$ann_stmt = $conn->prepare("SELECT * FROM announcements
    WHERE (expiry_date >= CURDATE() OR expiry_date IS NULL)
    AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY created_at DESC LIMIT 5");
$ann_stmt->execute();
$announcements_result = $ann_stmt->get_result();

while ($row = $announcements_result->fetch_assoc()) {
    $notifications[] = [
        'type'     => 'announcement',
        'title'    => $row['title'],
        'message'  => substr($row['content'], 0, 100) . '...',
        'time'     => $row['created_at'],
        'priority' => $row['priority']
    ];
}

// Get expiring memberships (for trainers)
if ($user_role == 'trainer') {
    $exp_stmt = $conn->prepare(
        "SELECT m.full_name, m.expiry_date
         FROM members m
         INNER JOIN trainer_client_assignments tca ON m.user_id = tca.client_user_id
         WHERE tca.trainer_user_id = ?
         AND m.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
         AND m.status = 'active'"
    );
    $exp_stmt->bind_param("i", $user_id);
    $exp_stmt->execute();
    $expiring_result = $exp_stmt->get_result();

    while ($row = $expiring_result->fetch_assoc()) {
        $days_left = floor((strtotime($row['expiry_date']) - time()) / (60 * 60 * 24));
        $notifications[] = [
            'type'     => 'membership',
            'title'    => 'Membership Expiring',
            'message'  => $row['full_name'] . "'s membership expires in {$days_left} days",
            'time'     => date('Y-m-d H:i:s'),
            'priority' => 'high'
        ];
    }
}

// Get persisted notifications from notifications table
$notif_stmt = $conn->prepare(
    "SELECT title, message, type, priority, created_at
     FROM notifications
     WHERE user_id = ? AND (read_status = 0 OR read_status IS NULL)
     ORDER BY created_at DESC LIMIT 10"
);
$notif_stmt->bind_param("i", $user_id);
$notif_stmt->execute();
$notif_result = $notif_stmt->get_result();
while ($row = $notif_result->fetch_assoc()) {
    $notifications[] = [
        'type'     => $row['type'],
        'title'    => $row['title'],
        'message'  => $row['message'],
        'time'     => $row['created_at'],
        'priority' => $row['priority']
    ];
}

header('Content-Type: application/json');
echo json_encode([
    'notifications' => $notifications,
    'unread_count' => $unread_count,
    'total_count' => count($notifications) + $unread_count
]);

$conn->close();
?>



