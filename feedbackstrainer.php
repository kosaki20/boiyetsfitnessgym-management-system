<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'trainer') {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';
require_once 'chat_functions.php';
require_once 'notification_functions.php';

$unread_count = getUnreadCount($_SESSION['user_id'], $conn);
$trainer_user_id = $_SESSION['user_id'];

// Get notifications for the current trainer
$notifications = getTrainerNotifications($conn, $trainer_user_id);
$notification_count = count($notifications);

// Function to get feedbacks
function getFeedbacks($conn) {
    $feedbacks = [];
    $sql = "SELECT f.*, u.full_name as user_name 
            FROM feedback f 
            LEFT JOIN users u ON f.user_id = u.id 
            ORDER BY f.created_at DESC";
    $result = $conn->query($sql);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $feedbacks[] = $row;
        }
    }
    
    return $feedbacks;
}

// Function to get feedback statistics
function getFeedbackStats($conn) {
    $stats = [];
    
    // Total feedbacks
    $result = $conn->query("SELECT COUNT(*) as total FROM feedback");
    $stats['total'] = $result->fetch_assoc()['total'];
    
    // Average rating
    $result = $conn->query("SELECT AVG(rating) as average FROM feedback WHERE rating > 0");
    $avg = $result->fetch_assoc()['average'];
    $stats['average_rating'] = $avg ? round($avg, 1) : 0;
    
    // Feedback by category
    $result = $conn->query("SELECT category, COUNT(*) as count FROM feedback GROUP BY category");
    $stats['by_category'] = [];
    while ($row = $result->fetch_assoc()) {
        $stats['by_category'][$row['category']] = $row['count'];
    }
    
    // Feedback by status
    $result = $conn->query("SELECT status, COUNT(*) as count FROM feedback GROUP BY status");
    $stats['by_status'] = [];
    while ($row = $result->fetch_assoc()) {
        $stats['by_status'][$row['status']] = $row['count'];
    }
    
    return $stats;
}

// Process feedback response
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['respond_feedback'])) {
    $feedback_id = $_POST['feedback_id'];
    $admin_notes = $_POST['admin_notes'];
    $status = $_POST['status'];
    
    $sql = "UPDATE feedback SET admin_notes = ?, status = ?, updated_at = NOW() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $admin_notes, $status, $feedback_id);
    
    if ($stmt->execute()) {
        $success_message = "Response saved successfully!";
    } else {
        $error_message = "Error saving response: " . $conn->error;
    }
}

// Process trainer feedback submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    $subject = trim($_POST['subject']);
    $category = trim($_POST['category']);
    $message = trim($_POST['message']);
    $rating = isset($_POST['rating']) ? intval($_POST['rating']) : NULL;
    $urgent = isset($_POST['urgent']) ? 1 : 0;
    $trainer_user_id = $_SESSION['user_id'];
    
    $insert_sql = "INSERT INTO feedback (user_id, user_role, subject, category, message, rating, urgent, status, created_at) 
                   VALUES (?, 'trainer', ?, ?, ?, ?, ?, 'pending', NOW())";
    $stmt = $conn->prepare($insert_sql);
    $stmt->bind_param("isssii", $trainer_user_id, $subject, $category, $message, $rating, $urgent);
    
    if ($stmt->execute()) {
        $success_message = "Your feedback has been submitted successfully!";
        // Clear form
        $_POST = array();
    } else {
        $error_message = "Error submitting feedback: " . $conn->error;
    }
}

$feedbacks = getFeedbacks($conn);
$stats = getFeedbackStats($conn);
?>

<?php require_once 'includes/trainer_header.php'; ?>
<?php require_once 'includes/trainer_sidebar.php'; ?>
    // Global function to open QR scanner
    function openQRScanner() {
        const qrScanner = document.getElementById('qrScanner');
        const qrInput = document.getElementById('qrInput');
        
        qrScanner.classList.remove('hidden');
        if (qrScannerActive) {
            setTimeout(() => qrInput.focus(), 100);
        }
    }
  </script>
<?php require_once 'includes/trainer_footer.php'; ?>
<?php if(isset($conn)) { $conn->close(); } ?>
