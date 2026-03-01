<?php
// DEBUG MODE - Remove this in production
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "boiyetsdb";

$conn = new mysqli($servername, $username, $password, $dbname);

$errors = [];

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $client_type = $_POST['client_type'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Common fields for both types
    $age = intval($_POST['age'] ?? 0);
    $address = trim($_POST['address'] ?? '');
    $membership_plan = $_POST['membership_plan'] ?? '';
    
    // Client-specific fields
    $gender = $_POST['gender'] ?? '';
    $height = floatval($_POST['height'] ?? 0);
    $weight = floatval($_POST['weight'] ?? 0);
    $fitness_goals = trim($_POST['fitness_goals'] ?? '');

    // Validation
    if (empty($client_type)) {
        $errors[] = "Please select client type";
    }
    
    if (empty($full_name) || strlen($full_name) < 2) {
        $errors[] = "Full name must be at least 2 characters long";
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address";
    }
    
    if (!preg_match('/^[0-9]{11}$/', $contact_number)) {
        $errors[] = "Contact number must be exactly 11 digits";
    }
    
    if (empty($username) || strlen($username) < 3) {
        $errors[] = "Username must be at least 3 characters long";
    }
    
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }
    
    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long";
    }
    
    if ($age < 16 || $age > 100) {
        $errors[] = "Age must be between 16 and 100";
    }
    
    if (empty($address) || strlen($address) < 5) {
        $errors[] = "Address must be at least 5 characters long";
    }
    
    if (empty($membership_plan)) {
        $errors[] = "Please select a membership plan";
    }
    
    // Client-specific validations
    if ($client_type === 'full-time') {
        if (empty($gender)) {
            $errors[] = "Please select gender";
        }
        
        if ($height < 100 || $height > 250) {
            $errors[] = "Height must be between 100cm and 250cm";
        }
        
        if ($weight < 30 || $weight > 300) {
            $errors[] = "Weight must be between 30kg and 300kg";
        }
    }

    // Check for duplicate username and email
    if (empty($errors)) {
        $check_sql = "SELECT COUNT(*) as count FROM users WHERE (username = ? OR email = ?) AND deleted_at IS NULL";
        $check_stmt = $conn->prepare($check_sql);
        
        if ($check_stmt) {
            $check_stmt->bind_param("ss", $username, $email);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            $row = $result->fetch_assoc();
            
            if ($row['count'] > 0) {
                $errors[] = "Username or email already exists";
            }
        }
    }

    // If no errors, proceed with registration
    if (empty($errors)) {
        // Map client_type to member_type for database
        $member_type = ($client_type === 'full-time') ? 'client' : 'walk-in';
        
        // Calculate expiry date and price
        function calculateExpiryDate($plan) {
            $today = new DateTime();
            switch($plan) {
                case 'daily': return $today->modify('+1 day')->format('Y-m-d');
                case 'weekly': return $today->modify('+7 days')->format('Y-m-d');
                case 'halfmonth': return $today->modify('+15 days')->format('Y-m-d');
                case 'monthly': return $today->modify('+1 month')->format('Y-m-d');
                default: return $today->format('Y-m-d');
            }
        }
        
        function getMembershipPrice($plan) {
            switch($plan) {
                case 'daily': return 40.00;
                case 'weekly': return 160.00;
                case 'halfmonth': return 250.00;
                case 'monthly': return 400.00;
                default: return 0.00;
            }
        }
        
        $start_date = date('Y-m-d');
        $expiry_date = calculateExpiryDate($membership_plan);
        $plan_price = getMembershipPrice($membership_plan);
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Get default trainer ID for created_by field
            $trainer_id = 1; // Default to admin user ID 1
            
            // Create user account
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $user_sql = "INSERT INTO users (username, password, role, full_name, email, client_type) VALUES (?, ?, 'client', ?, ?, ?)";
            $user_stmt = $conn->prepare($user_sql);
            
            if (!$user_stmt) {
                throw new Exception("Error preparing user statement: " . $conn->error);
            }
            
            $user_stmt->bind_param("sssss", $username, $hashed_password, $full_name, $email, $client_type);
            
            if (!$user_stmt->execute()) {
                throw new Exception("Error creating user account: " . $user_stmt->error);
            }
            
            $user_id = $conn->insert_id;
            
            // Generate QR code
            $qr_content = strtoupper($member_type) . "_" . $user_id;
            $qr_filename = 'qrcodes/' . $member_type . '_' . $user_id . '_' . time() . '.png';
            
            // Create qrcodes directory if it doesn't exist
            if (!is_dir('qrcodes')) {
                mkdir('qrcodes', 0755, true);
            }
            
            // Generate QR code if library exists
            if (file_exists('phpqrcode/qrlib.php')) {
                require_once 'phpqrcode/qrlib.php';
                if (class_exists('QRcode')) {
                    try {
                        QRcode::png($qr_content, $qr_filename, QR_ECLEVEL_L, 8);
                        if (!file_exists($qr_filename)) {
                            error_log("QR code file was not created: " . $qr_filename);
                            $qr_filename = null;
                        }
                    } catch (Exception $e) {
                        error_log("QR code generation failed: " . $e->getMessage());
                        $qr_filename = null;
                    }
                } else {
                    error_log("QRcode class not found");
                    $qr_filename = null;
                }
            } else {
                error_log("QR code library not found");
                $qr_filename = null;
            }
            
            // Insert member record - CORRECTED VERSION
            if ($member_type === 'client') {
                $member_sql = "INSERT INTO members (member_type, full_name, age, contact_number, address, gender, height, weight, fitness_goals, membership_plan, start_date, expiry_date, status, created_by, user_id, qr_code_path) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)";
                $member_stmt = $conn->prepare($member_sql);
                if (!$member_stmt) {
                    throw new Exception("Error preparing member statement: " . $conn->error);
                }
                // CORRECTED: 16 parameters total - "ssisssddsssssiis"
                $member_stmt->bind_param("ssisssddsssssiis", 
                    $member_type, 
                    $full_name, 
                    $age, 
                    $contact_number, 
                    $address, 
                    $gender, 
                    $height, 
                    $weight, 
                    $fitness_goals, 
                    $membership_plan, 
                    $start_date, 
                    $expiry_date, 
                    $trainer_id,        // created_by
                    $user_id, 
                    $qr_filename
                );
            } else {
                $member_sql = "INSERT INTO members (member_type, full_name, age, contact_number, address, membership_plan, start_date, expiry_date, status, created_by, user_id, qr_code_path) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)";
                $member_stmt = $conn->prepare($member_sql);
                if (!$member_stmt) {
                    throw new Exception("Error preparing member statement: " . $conn->error);
                }
                // CORRECTED: 12 parameters total - "ssissssssiis"
                $member_stmt->bind_param("ssissssssiis", 
                    $member_type, 
                    $full_name, 
                    $age, 
                    $contact_number, 
                    $address, 
                    $membership_plan, 
                    $start_date, 
                    $expiry_date, 
                    $trainer_id,        // created_by
                    $user_id, 
                    $qr_filename
                );
            }
            
            if (!$member_stmt->execute()) {
                throw new Exception("Error registering member: " . $member_stmt->error);
            }
            
            $member_id = $conn->insert_id;
            
            // Record membership payment
            $payment_sql = "INSERT INTO membership_payments (member_id, member_name, plan_type, plan_price, amount, payment_date, payment_method, transaction_id, status) 
                           VALUES (?, ?, ?, ?, ?, CURDATE(), 'cash', ?, 'completed')";
            $payment_stmt = $conn->prepare($payment_sql);
            if (!$payment_stmt) {
                throw new Exception("Error preparing payment statement: " . $conn->error);
            }
            
            $transaction_id = strtoupper($member_type) . '_' . $member_id . '_' . time();
            $payment_stmt->bind_param("issdss", $member_id, $full_name, $membership_plan, $plan_price, $plan_price, $transaction_id);
            
            if (!$payment_stmt->execute()) {
                throw new Exception("Error recording membership payment: " . $payment_stmt->error);
            }
            
            $payment_id = $conn->insert_id;
            
            // Record revenue entry
            $revenue_sql = "INSERT INTO revenue_entries (
                category_id, amount, description, payment_method, reference_id, reference_name, 
                revenue_date, recorded_by, notes, reconciled
            ) VALUES (2, ?, ?, 'cash', ?, ?, CURDATE(), 1, 'Auto-recorded from client registration', 0)";
            
            $revenue_stmt = $conn->prepare($revenue_sql);
            if (!$revenue_stmt) {
                throw new Exception("Revenue statement preparation failed: " . $conn->error);
            }
            
            $description = $member_type . ' registration: ' . $full_name . ' - ' . $membership_plan;
            $revenue_stmt->bind_param("dsss", $plan_price, $description, $member_id, $full_name);
            
            if (!$revenue_stmt->execute()) {
                throw new Exception("Revenue entry creation failed: " . $revenue_stmt->error);
            }
            
            $revenue_id = $conn->insert_id;
            
            // Update the membership_payments table with revenue_entry_id
            $update_payment_sql = "UPDATE membership_payments SET revenue_entry_id = ? WHERE id = ?";
            $update_payment_stmt = $conn->prepare($update_payment_sql);
            $update_payment_stmt->bind_param("ii", $revenue_id, $payment_id);
            $update_payment_stmt->execute();
            
            // Auto-assign client to a trainer (only for full-time clients)
            if ($member_type === 'client') {
                // Get first available trainer
                $trainer_query = "SELECT id FROM users WHERE role = 'trainer' AND deleted_at IS NULL LIMIT 1";
                $trainer_result = $conn->query($trainer_query);
                
                if ($trainer_result && $trainer_result->num_rows > 0) {
                    $trainer = $trainer_result->fetch_assoc();
                    $trainer_user_id = $trainer['id'];
                    
                    $assignment_sql = "INSERT INTO trainer_client_assignments (trainer_user_id, client_user_id) VALUES (?, ?)";
                    $assignment_stmt = $conn->prepare($assignment_sql);
                    if ($assignment_stmt) {
                        $assignment_stmt->bind_param("ii", $trainer_user_id, $user_id);
                        if (!$assignment_stmt->execute()) {
                            error_log("Failed to assign client to trainer: " . $assignment_stmt->error);
                            // Don't throw exception for this, just log it
                        }
                    }
                }
            }
            
            if ($conn->commit()) {
                // Registration successful - redirect to login
                $_SESSION['registration_success'] = "Account created successfully! You can now login.";
                header("Location: index.php");
                exit();
            } else {
                throw new Exception("Transaction commit failed: " . $conn->error);
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Registration failed: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - Boiyets Fitness Gym</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary: #64748b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --light: #f8fafc;
            --dark: #1e293b;
            --border: #e2e8f0;
            --gold: #fbbf24;
            --gold-dark: #f59e0b;
        }
        
        body {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            color: var(--light);
        }
        
        .container {
            width: 100%;
            max-width: 500px;
        }
        
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo h1 {
            color: var(--gold);
            font-size: 3rem;
            font-weight: 800;
            letter-spacing: 3px;
            text-transform: uppercase;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
            margin-bottom: 5px;
        }
        
        .logo h2 {
            color: #ffffff;
            font-size: 1.3rem;
            font-weight: 300;
            letter-spacing: 4px;
            opacity: 0.9;
        }
        
        .signup-box {
            background-color: rgba(255, 255, 255, 0.05);
            border-radius: 15px;
            padding: 40px 35px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            position: relative;
            overflow: hidden;
        }
        
        .signup-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--gold), var(--gold-dark));
        }
        
        .signup-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .signup-header h3 {
            color: #ffffff;
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .signup-header p {
            color: #94a3b8;
            font-size: 1rem;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            text-align: center;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.3s ease;
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            color: #cbd5e1;
            margin-bottom: 8px;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            background-color: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 8px;
            color: #ffffff;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }
        
        .form-control::placeholder {
            color: #94a3b8;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.15);
            background-color: rgba(255, 255, 255, 0.12);
        }
        
        /* Fixed Dropdown Styling */
        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2394a3b8' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 12px;
            padding-right: 35px;
            background-color: rgba(255, 255, 255, 0.08) !important;
        }
        
        select.form-control option {
            background: #2d2d2d;
            color: #ffffff;
            padding: 10px;
        }
        
        select.form-control:focus {
            background-color: rgba(255, 255, 255, 0.12) !important;
        }
        
        .client-type-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 25px;
        }
        
        .client-type-option {
            position: relative;
            cursor: pointer;
        }
        
        .client-type-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .client-type-card {
            padding: 18px 12px;
            border: 2px solid rgba(255, 255, 255, 0.15);
            border-radius: 10px;
            text-align: center;
            transition: all 0.3s ease;
            background-color: rgba(255, 255, 255, 0.05);
            cursor: pointer;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .client-type-option input[type="radio"]:checked + .client-type-card {
            border-color: var(--gold);
            background-color: rgba(251, 191, 36, 0.1);
            box-shadow: 0 0 20px rgba(251, 191, 36, 0.2);
        }
        
        .client-type-icon {
            font-size: 1.4rem;
            color: var(--gold);
            margin-bottom: 8px;
        }
        
        .client-type-title {
            font-weight: 600;
            margin-bottom: 4px;
            font-size: 0.95rem;
            color: #ffffff;
        }
        
        .client-type-desc {
            font-size: 0.8rem;
            color: #94a3b8;
            line-height: 1.3;
        }
        
        .password-input {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 4px;
            transition: color 0.3s ease;
            font-size: 0.9rem;
        }
        
        .password-toggle:hover {
            color: var(--gold);
        }
        
        .btn-signup {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--gold), var(--gold-dark));
            color: #1a1a1a;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin: 25px 0 15px 0;
        }
        
        .btn-signup:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(251, 191, 36, 0.4);
        }
        
        .btn-signup:active {
            transform: translateY(0);
        }
        
        .form-links {
            text-align: center;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .login-link a {
            color: #94a3b8;
            text-decoration: none;
            font-size: 0.85rem;
            transition: color 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        
        .login-link a:hover {
            color: var(--gold);
        }
        
        /* Section Headers */
        .section-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 25px 0 15px 0;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .section-header i {
            color: var(--gold);
            font-size: 1.1rem;
        }
        
        .section-header h4 {
            color: #ffffff;
            font-size: 1.1rem;
            font-weight: 600;
            margin: 0;
        }
        
        /* Form Grid Layout */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .form-full-width {
            grid-column: 1 / -1;
        }
        
        /* Compact Section Styling */
        .compact-section {
            background: rgba(255, 255, 255, 0.03);
            border-radius: 10px;
            padding: 20px;
            margin-top: 10px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .compact-section .form-grid {
            gap: 12px;
        }
        
        /* Fitness Profile Section (Only for Full-time Clients) */
        .fitness-profile-section {
            transition: all 0.3s ease;
            max-height: 1000px;
            overflow: hidden;
            margin-top: 20px;
        }
        
        .fitness-profile-section.hidden {
            max-height: 0;
            opacity: 0;
            margin-top: 0;
            overflow: hidden;
        }
        
        /* Animations */
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }
        
        .signup-box {
            animation: fadeIn 0.6s ease;
        }
        
        /* Loading animation */
        .loading-spinner {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid transparent;
            border-top: 2px solid currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                max-width: 500px;
            }
            
            .signup-box {
                padding: 30px 25px;
            }
            
            .logo h1 {
                font-size: 2.5rem;
            }
            
            .logo h2 {
                font-size: 1.1rem;
            }
            
            .client-type-selector {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            body {
                padding: 15px;
            }
            
            .container {
                max-width: 100%;
            }
            
            .signup-box {
                padding: 25px 20px;
            }
            
            .logo h1 {
                font-size: 2.2rem;
                letter-spacing: 2px;
            }
            
            .logo h2 {
                font-size: 1rem;
                letter-spacing: 3px;
            }
            
            .compact-section {
                padding: 15px;
            }
            
            .section-header {
                margin: 20px 0 12px 0;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <h1>BOIYETS</h1>
            <h2>FITNESS GYM</h2>
        </div>
        
        <div class="signup-box">
            <div class="signup-header">
                <h3>Create Account</h3>
                <p>Join our fitness community today</p>
            </div>
            
            <?php if (isset($errors) && !empty($errors)): ?>
                <div class="alert alert-error" id="errorAlert">
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <?php foreach ($errors as $error): ?>
                            <div><?php echo htmlspecialchars($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <form id="signupForm" action="signup.php" method="POST">
                <!-- Client Type Selection -->
                <div class="form-group">
                    <label>Select Client Type</label>
                    <div class="client-type-selector">
                        <label class="client-type-option">
                            <input type="radio" id="walk-in" name="client_type" value="walk-in" required 
                                   <?php echo (isset($_POST['client_type']) && $_POST['client_type'] == 'walk-in') ? 'checked' : ''; ?>>
                            <div class="client-type-card">
                                <div class="client-type-icon">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div class="client-type-title">Walk-in</div>
                                <div class="client-type-desc">Pay-per-visit access</div>
                            </div>
                        </label>
                        
                        <label class="client-type-option">
                            <input type="radio" id="full-time" name="client_type" value="full-time" required
                                   <?php echo (isset($_POST['client_type']) && $_POST['client_type'] == 'full-time') ? 'checked' : ''; ?>>
                            <div class="client-type-card">
                                <div class="client-type-icon">
                                    <i class="fas fa-user-check"></i>
                                </div>
                                <div class="client-type-title">Full-time</div>
                                <div class="client-type-desc">Full membership access</div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Account Information Section -->
                <div class="section-header">
                    <i class="fas fa-user-circle"></i>
                    <h4>Account Information</h4>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" class="form-control" 
                               placeholder="Enter your full name" required 
                               value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control" 
                               placeholder="Enter your email" required 
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="contact_number">Contact Number</label>
                        <input type="tel" id="contact_number" name="contact_number" class="form-control" 
                               placeholder="09XXXXXXXXX" pattern="[0-9]{11}" required maxlength="11"
                               value="<?php echo isset($_POST['contact_number']) ? htmlspecialchars($_POST['contact_number']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" class="form-control" 
                               placeholder="Choose a username" required 
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-input">
                            <input type="password" id="password" name="password" class="form-control" 
                                   placeholder="Enter password" required>
                            <button type="button" class="password-toggle" id="passwordToggle">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="password-input">
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" 
                                   placeholder="Confirm password" required>
                            <button type="button" class="password-toggle" id="confirmPasswordToggle">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Basic Information Section -->
                <div class="section-header">
                    <i class="fas fa-info-circle"></i>
                    <h4>Basic Information</h4>
                </div>
                
                <div class="compact-section">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="age">Age</label>
                            <input type="number" id="age" name="age" class="form-control" 
                                   placeholder="Age" min="16" max="100" required
                                   value="<?php echo isset($_POST['age']) ? htmlspecialchars($_POST['age']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="membership_plan">Membership Plan</label>
                            <select id="membership_plan" name="membership_plan" class="form-control" required>
                                <option value="">Select a plan</option>
                                <option value="daily" <?php echo (isset($_POST['membership_plan']) && $_POST['membership_plan'] == 'daily') ? 'selected' : ''; ?>>Per Visit - ₱40</option>
                                <option value="weekly" <?php echo (isset($_POST['membership_plan']) && $_POST['membership_plan'] == 'weekly') ? 'selected' : ''; ?>>Weekly - ₱160</option>
                                <option value="halfmonth" <?php echo (isset($_POST['membership_plan']) && $_POST['membership_plan'] == 'halfmonth') ? 'selected' : ''; ?>>Half Month - ₱250</option>
                                <option value="monthly" <?php echo (isset($_POST['membership_plan']) && $_POST['membership_plan'] == 'monthly') ? 'selected' : ''; ?>>Monthly - ₱400</option>
                            </select>
                        </div>
                        
                        <div class="form-group form-full-width">
                            <label for="address">Address</label>
                            <textarea id="address" name="address" class="form-control" 
                                      placeholder="Your complete address" rows="2" required><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- Additional Information Section (Only for Full-time Clients) -->
                <div class="fitness-profile-section <?php echo (isset($_POST['client_type']) && $_POST['client_type'] == 'full-time') ? '' : 'hidden'; ?>" id="fitnessProfileSection">
                    <div class="section-header">
                        <i class="fas fa-dumbbell"></i>
                        <h4>Additional Information</h4>
                    </div>
                    
                    <div class="compact-section">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="gender">Gender</label>
                                <select id="gender" name="gender" class="form-control">
                                    <option value="">Select Gender</option>
                                    <option value="male" <?php echo (isset($_POST['gender']) && $_POST['gender'] == 'male') ? 'selected' : ''; ?>>Male</option>
                                    <option value="female" <?php echo (isset($_POST['gender']) && $_POST['gender'] == 'female') ? 'selected' : ''; ?>>Female</option>
                                    <option value="other" <?php echo (isset($_POST['gender']) && $_POST['gender'] == 'other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="height">Height (cm)</label>
                                <input type="number" id="height" name="height" class="form-control" 
                                       placeholder="Height in cm" step="0.1" min="100" max="250"
                                       value="<?php echo isset($_POST['height']) ? htmlspecialchars($_POST['height']) : ''; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="weight">Weight (kg)</label>
                                <input type="number" id="weight" name="weight" class="form-control" 
                                       placeholder="Weight in kg" step="0.1" min="30" max="300"
                                       value="<?php echo isset($_POST['weight']) ? htmlspecialchars($_POST['weight']) : ''; ?>">
                            </div>
                            
                            <div class="form-group form-full-width">
                                <label for="fitness_goals">Fitness Goals</label>
                                <textarea id="fitness_goals" name="fitness_goals" class="form-control" 
                                          placeholder="Describe your fitness goals (weight loss, muscle gain, endurance, etc.)" rows="2"><?php echo isset($_POST['fitness_goals']) ? htmlspecialchars($_POST['fitness_goals']) : ''; ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="btn-signup">
                    <i class="fas fa-user-plus"></i>
                    <span>Create Account</span>
                </button>
                
                <div class="form-links">
                    <div class="login-link">
                        <a href="index.php">
                            <i class="fas fa-sign-in-alt"></i>
                            Already have an account? Login here
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Password toggle functionality
            const passwordToggle = document.getElementById('passwordToggle');
            const passwordInput = document.getElementById('password');
            const confirmPasswordToggle = document.getElementById('confirmPasswordToggle');
            const confirmPasswordInput = document.getElementById('confirm_password');
            
            function setupPasswordToggle(toggle, input) {
                toggle.addEventListener('click', function() {
                    const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                    input.setAttribute('type', type);
                    const icon = this.querySelector('i');
                    icon.className = type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
                });
            }
            
            setupPasswordToggle(passwordToggle, passwordInput);
            setupPasswordToggle(confirmPasswordToggle, confirmPasswordInput);

            // Client type selection functionality
            const clientTypeRadios = document.querySelectorAll('input[name="client_type"]');
            const fitnessProfileSection = document.getElementById('fitnessProfileSection');
            
            function toggleClientSections() {
                const selectedType = document.querySelector('input[name="client_type"]:checked');
                
                if (selectedType && selectedType.value === 'full-time') {
                    fitnessProfileSection.classList.remove('hidden');
                    
                    // Set required fields for fitness profile
                    document.getElementById('gender').setAttribute('required', 'required');
                    document.getElementById('height').setAttribute('required', 'required');
                    document.getElementById('weight').setAttribute('required', 'required');
                } else {
                    fitnessProfileSection.classList.add('hidden');
                    
                    // Remove required from fitness profile fields
                    document.getElementById('gender').removeAttribute('required');
                    document.getElementById('height').removeAttribute('required');
                    document.getElementById('weight').removeAttribute('required');
                }
            }
            
            clientTypeRadios.forEach(radio => {
                radio.addEventListener('change', toggleClientSections);
            });
            
            // Initialize on page load
            toggleClientSections();

            // Form submission handling
            document.getElementById('signupForm').addEventListener('submit', function(e) {
                const password = document.getElementById('password').value.trim();
                const confirmPassword = document.getElementById('confirm_password').value.trim();
                const clientType = document.querySelector('input[name="client_type"]:checked');
                
                if (!clientType) {
                    e.preventDefault();
                    showError('Please select a client type');
                    return false;
                }
                
                if (password !== confirmPassword) {
                    e.preventDefault();
                    showError('Passwords do not match');
                    return false;
                }
                
                if (password.length < 6) {
                    e.preventDefault();
                    showError('Password must be at least 6 characters long');
                    return false;
                }
                
                const btn = this.querySelector('.btn-signup');
                const btnText = btn.querySelector('span');
                btnText.innerHTML = '<span class="loading-spinner" style="color: #1a1a1a;"></span> Creating Account...';
                btn.disabled = true;
            });

            function showError(message) {
                const existingAlert = document.getElementById('errorAlert');
                if (existingAlert) {
                    existingAlert.remove();
                }
                
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-error';
                alertDiv.id = 'errorAlert';
                alertDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i><span>${message}</span>`;
                
                document.querySelector('.signup-header').parentNode.insertBefore(alertDiv, document.querySelector('.signup-header').nextSibling);
            }

            // Auto-hide error after 5 seconds
            setTimeout(() => {
                const errorAlert = document.getElementById('errorAlert');
                if (errorAlert) {
                    errorAlert.style.opacity = '0';
                    setTimeout(() => {
                        if (errorAlert.parentNode) {
                            errorAlert.parentNode.removeChild(errorAlert);
                        }
                    }, 300);
                }
            }, 5000);

            // Add hover effects to form controls
            document.querySelectorAll('.form-control').forEach(input => {
                input.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = 'rgba(255, 255, 255, 0.12)';
                });
                
                input.addEventListener('mouseleave', function() {
                    if (!this.matches(':focus')) {
                        this.style.backgroundColor = 'rgba(255, 255, 255, 0.08)';
                    }
                });
            });

            // Add hover effects to client type cards
            document.querySelectorAll('.client-type-card').forEach(card => {
                card.addEventListener('mouseenter', function() {
                    if (!this.previousElementSibling.checked) {
                        this.style.backgroundColor = 'rgba(255, 255, 255, 0.08)';
                        this.style.borderColor = 'rgba(255, 255, 255, 0.2)';
                    }
                });
                
                card.addEventListener('mouseleave', function() {
                    if (!this.previousElementSibling.checked) {
                        this.style.backgroundColor = 'rgba(255, 255, 255, 0.05)';
                        this.style.borderColor = 'rgba(255, 255, 255, 0.15)';
                    }
                });
            });
        });
    </script>
</body>
</html>



