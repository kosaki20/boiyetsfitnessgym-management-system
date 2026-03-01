<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'trainer') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}


require_once 'includes/db_connection.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qrCode = trim($_POST['qr_code'] ?? '');
    
    if (empty($qrCode)) {
        echo json_encode(['success' => false, 'message' => 'QR code is empty']);
        exit();
    }
    
    error_log("QR Code Received: " . $qrCode);
    
    // ENHANCED PARSING - Handle both member ID and user ID formats
    $memberId = null;
    $userId = null;
    
    // Format 1: CLIENT_123 (Could be member ID OR user ID)
    if (preg_match('/^CLIENT_(\d+)$/i', $qrCode, $matches)) {
        $identifier = $matches[1];
        error_log("CLIENT format - Identifier: " . $identifier);
        
        // Try to find member by member ID first
        $stmt = $conn->prepare("SELECT m.id, m.full_name, m.status, m.expiry_date, m.member_type, m.user_id 
                               FROM members m 
                               WHERE m.id = ?");
        $stmt->bind_param("i", $identifier);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            // Found by member ID
            $memberId = $identifier;
            error_log("Found by member ID: " . $memberId);
        } else {
            // Try to find by user ID
            $stmt = $conn->prepare("SELECT m.id, m.full_name, m.status, m.expiry_date, m.member_type, m.user_id 
                                   FROM members m 
                                   WHERE m.user_id = ?");
            $stmt->bind_param("i", $identifier);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Found by user ID
                $userId = $identifier;
                error_log("Found by user ID: " . $userId);
            } else {
                error_log("No member found for identifier: " . $identifier);
                echo json_encode(['success' => false, 'message' => 'Member not found. Please regenerate QR code.']);
                exit();
            }
        }
    }
    // Format 2: WALKIN_123 (Could be member ID OR user ID)
    else if (preg_match('/^WALKIN_(\d+)$/i', $qrCode, $matches)) {
        $identifier = $matches[1];
        error_log("WALKIN format - Identifier: " . $identifier);
        
        // Try to find member by member ID first
        $stmt = $conn->prepare("SELECT m.id, m.full_name, m.status, m.expiry_date, m.member_type, m.user_id 
                               FROM members m 
                               WHERE m.id = ?");
        $stmt->bind_param("i", $identifier);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            // Found by member ID
            $memberId = $identifier;
            error_log("Found by member ID: " . $memberId);
        } else {
            // Try to find by user ID
            $stmt = $conn->prepare("SELECT m.id, m.full_name, m.status, m.expiry_date, m.member_type, m.user_id 
                                   FROM members m 
                                   WHERE m.user_id = ?");
            $stmt->bind_param("i", $identifier);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Found by user ID
                $userId = $identifier;
                error_log("Found by user ID: " . $userId);
            } else {
                error_log("No member found for identifier: " . $identifier);
                echo json_encode(['success' => false, 'message' => 'Member not found. Please regenerate QR code.']);
                exit();
            }
        }
    }
    // Format 3: Just numbers (fallback for old QR codes)
    else if (preg_match('/^(\d+)$/', $qrCode, $matches)) {
        $identifier = $matches[1];
        error_log("Numeric format - Identifier: " . $identifier);
        
        // Try to find by member ID
        $stmt = $conn->prepare("SELECT m.id, m.full_name, m.status, m.expiry_date, m.member_type, m.user_id 
                               FROM members m 
                               WHERE m.id = ?");
        $stmt->bind_param("i", $identifier);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            // Try by user ID
            $stmt = $conn->prepare("SELECT m.id, m.full_name, m.status, m.expiry_date, m.member_type, m.user_id 
                                   FROM members m 
                                   WHERE m.user_id = ?");
            $stmt->bind_param("i", $identifier);
            $stmt->execute();
            $result = $stmt->get_result();
        }
        
        if ($result->num_rows > 0) {
            $member = $result->fetch_assoc();
            $memberId = $member['id'];
            error_log("Found member ID: " . $memberId);
        } else {
            error_log("No member found for numeric identifier: " . $identifier);
            echo json_encode(['success' => false, 'message' => 'Member not found. Please regenerate QR code.']);
            exit();
        }
    }
    else {
        error_log("Unsupported QR format: " . $qrCode);
        echo json_encode(['success' => false, 'message' => 'Invalid QR code format. Please regenerate QR code.']);
        exit();
    }
    
    // If we found a member, proceed with attendance logic
    if ($result->num_rows > 0) {
        $member = $result->fetch_assoc();
        error_log("Member found: " . $member['full_name'] . " (Member ID: " . $member['id'] . ", User ID: " . $member['user_id'] . ")");
        
        // Check if membership is active
        if ($member['status'] !== 'active') {
            echo json_encode(['success' => false, 'message' => 'Membership is not active']);
            exit();
        }
        
        // Check if membership has expired
        $currentDate = date('Y-m-d');
        if ($member['expiry_date'] < $currentDate) {
            echo json_encode(['success' => false, 'message' => 'Membership expired on ' . $member['expiry_date']]);
            exit();
        }
        
        // Check attendance logic
        $today = date('Y-m-d');
        $checkStmt = $conn->prepare("SELECT id, check_in FROM attendance WHERE member_id = ? AND DATE(check_in) = ? AND check_out IS NULL");
        $checkStmt->bind_param("is", $member['id'], $today);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows > 0) {
            // Check out
            $attendance = $checkResult->fetch_assoc();
            $updateStmt = $conn->prepare("UPDATE attendance SET check_out = NOW() WHERE id = ?");
            $updateStmt->bind_param("i", $attendance['id']);
            
            if ($updateStmt->execute()) {
                echo json_encode([
                    'success' => true, 
                    'message' => "Checked out {$member['full_name']} successfully",
                    'action' => 'check_out',
                    'member_name' => $member['full_name']
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error checking out']);
            }
        } else {
            // Check in
            $insertStmt = $conn->prepare("INSERT INTO attendance (member_id, check_in) VALUES (?, NOW())");
            $insertStmt->bind_param("i", $member['id']);
            
            if ($insertStmt->execute()) {
                echo json_encode([
                    'success' => true, 
                    'message' => "Checked in {$member['full_name']} successfully",
                    'action' => 'check_in',
                    'member_name' => $member['full_name']
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error checking in']);
            }
        }
    } else {
        error_log("Member not found for identifier in QR: " . $qrCode);
        echo json_encode(['success' => false, 'message' => 'Member not found. Please regenerate QR code.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}

$conn->close();
?>



