<?php
require_once 'includes/db_connection.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $memberType = $_POST['member_type'];
    $fullName = $_POST['full_name'];
    $age = $_POST['age'];
    $contactNumber = $_POST['contact_number'];
    $address = $_POST['address'];
    $membershipPlan = $_POST['membership_plan'];
    $expiryDate = $_POST['expiry_date'];
    
    // Additional fields for clients
    $gender = isset($_POST['gender']) ? $_POST['gender'] : null;
    $height = isset($_POST['height']) ? $_POST['height'] : null;
    $weight = isset($_POST['weight']) ? $_POST['weight'] : null;
    $fitnessGoals = isset($_POST['fitness_goals']) ? $_POST['fitness_goals'] : null;
    
    // Insert into database
    $sql = "INSERT INTO members (member_type, full_name, age, contact_number, address, gender, height, weight, fitness_goals, membership_plan, start_date, expiry_date) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssisssddsss", $memberType, $fullName, $age, $contactNumber, $address, $gender, $height, $weight, $fitnessGoals, $membershipPlan, $expiryDate);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Member registered successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error registering member: ' . $conn->error]);
    }
    
    $stmt->close();
}

$conn->close();
?>



