<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'trainer') {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_id = $_POST['member_id'];
    
    $stmt = $conn->prepare("SELECT user_id FROM members WHERE id = ?");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $member = $result->fetch_assoc();
        echo json_encode([
            'success' => true,
            'user_id' => $member['user_id']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Member not found'
        ]);
    }
    
    $stmt->close();
}

$conn->close();
?>



