<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'trainer') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "boiyetsdb";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_id = $_POST['member_id'] ?? null;
    $plan_type = $_POST['plan_type'] ?? null;
    $payment_method = $_POST['payment_method'] ?? 'cash';
    
    if (!$member_id || !$plan_type) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit();
    }
    
    // Define membership plans and durations
    $membership_plans = [
        'daily' => ['price' => 40, 'duration' => '+1 day'],
        'weekly' => ['price' => 160, 'duration' => '+7 days'],
        'halfmonth' => ['price' => 250, 'duration' => '+15 days'],
        'monthly' => ['price' => 400, 'duration' => '+30 days']
    ];
    
    if (!isset($membership_plans[$plan_type])) {
        echo json_encode(['success' => false, 'message' => 'Invalid membership plan']);
        exit();
    }
    
    $plan = $membership_plans[$plan_type];
    $trainer_id = $_SESSION['user_id'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get current member details
        $member_sql = "SELECT m.*, u.id as user_id FROM members m 
                      LEFT JOIN users u ON m.user_id = u.id 
                      WHERE m.id = ?";
        $stmt = $conn->prepare($member_sql);
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $member_result = $stmt->get_result();
        $member = $member_result->fetch_assoc();
        $stmt->close();
        
        if (!$member) {
            throw new Exception("Member not found");
        }
        
        // Calculate new expiry date
        $current_date = $member['expiry_date'] > date('Y-m-d') ? $member['expiry_date'] : date('Y-m-d');
        $new_expiry = date('Y-m-d', strtotime($current_date . ' ' . $plan['duration']));
        
        // Update member's membership
        $update_sql = "UPDATE members SET 
                      membership_plan = ?, 
                      start_date = CURDATE(), 
                      expiry_date = ?, 
                      status = 'active',
                      updated_at = CURRENT_TIMESTAMP 
                      WHERE id = ?";
        
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("ssi", $plan_type, $new_expiry, $member_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update membership: " . $stmt->error);
        }
        $stmt->close();
        
        // Record membership payment
        $payment_sql = "INSERT INTO membership_payments 
                       (member_id, member_name, plan_type, plan_price, amount, payment_date, payment_method, status, transaction_id) 
                       VALUES (?, ?, ?, ?, ?, CURDATE(), ?, 'completed', ?)";
        
        $transaction_id = 'RENEW_' . $member_id . '_' . time();
        $stmt = $conn->prepare($payment_sql);
        $stmt->bind_param("issddss", 
            $member_id, 
            $member['full_name'],
            $plan_type,
            $plan['price'],
            $plan['price'],
            $payment_method,
            $transaction_id
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to record payment: " . $stmt->error);
        }
        $payment_id = $stmt->insert_id;
        $stmt->close();
        
        // Record revenue entry
        $revenue_sql = "INSERT INTO revenue_entries 
                       (category_id, amount, description, payment_method, reference_type, reference_id, reference_name, revenue_date, recorded_by) 
                       VALUES (2, ?, ?, ?, 'member', ?, ?, CURDATE(), ?)";
        
        $description = "Membership renewal: {$member['full_name']} - " . ucfirst($plan_type);
        $stmt = $conn->prepare($revenue_sql);
        $stmt->bind_param("dssisi", 
            $plan['price'],
            $description,
            $payment_method,
            $member_id,
            $member['full_name'],
            $trainer_id
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to record revenue: " . $stmt->error);
        }
        $revenue_id = $stmt->insert_id;
        $stmt->close();
        
        // Update membership payment with revenue entry ID
        $update_payment_sql = "UPDATE membership_payments SET revenue_entry_id = ? WHERE id = ?";
        $stmt = $conn->prepare($update_payment_sql);
        $stmt->bind_param("ii", $revenue_id, $payment_id);
        $stmt->execute();
        $stmt->close();
        
        // Update any pending renewal requests for this member
        $update_requests_sql = "UPDATE membership_renewal_requests 
                               SET status = 'completed', 
                               verified_by = ?, 
                               verified_at = CURRENT_TIMESTAMP,
                               notes = CONCAT(COALESCE(notes, ''), ' Renewal processed by trainer on ', CURDATE())
                               WHERE member_id = ? AND status IN ('pending', 'paid')";
        
        $stmt = $conn->prepare($update_requests_sql);
        $stmt->bind_param("ii", $trainer_id, $member_id);
        $stmt->execute();
        $stmt->close();
        
        // Create notification for client
        if ($member['user_id']) {
            $notification_sql = "INSERT INTO notifications 
                                (user_id, role, title, message, type, priority) 
                                VALUES (?, 'client', 'Membership Renewed', 
                                'Your {$plan_type} membership has been renewed by your trainer. New expiry: {$new_expiry}', 
                                'membership', 'medium')";
            
            $stmt = $conn->prepare($notification_sql);
            $stmt->bind_param("i", $member['user_id']);
            $stmt->execute();
            $stmt->close();
        }
        
        // Create notification for trainer
        $trainer_notification_sql = "INSERT INTO notifications 
                                    (user_id, role, title, message, type, priority) 
                                    VALUES (?, 'trainer', 'Membership Renewed', 
                                    'Successfully renewed {$plan_type} membership for {$member['full_name']} (₱{$plan['price']})', 
                                    'membership', 'medium')";
        
        $stmt = $conn->prepare($trainer_notification_sql);
        $stmt->bind_param("i", $trainer_id);
        $stmt->execute();
        $stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Membership renewed successfully',
            'plan_type' => ucfirst($plan_type),
            'amount_paid' => $plan['price'],
            'new_expiry' => date('M j, Y', strtotime($new_expiry))
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
}

$conn->close();
?>



