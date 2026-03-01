<?php
// FIX 1: Only start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Regenerate session ID for security
if (!isset($_SESSION['session_regenerated'])) {
    session_regenerate_id(true);
    $_SESSION['session_regenerated'] = true;
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'trainer') {
    header("Location: index.php");
    exit();
}

// Add CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Database connection
require_once 'includes/db_connection.php';

// FIX 2: Include files with function_exists checks
$included_files = [
    'chat_functions.php',
    'notification_functions.php', 
    'phpqrcode/qrlib.php'
];

foreach ($included_files as $file) {
    if (!file_exists($file)) {
        error_log("MISSING REQUIRED FILE: $file");
    }
}

// Include files with error suppression and function checks
@require_once 'chat_functions.php';

// FIX: Check if functions already exist before including notification_functions.php
if (!function_exists('getUnreadNotificationCount')) {
    @require_once 'notification_functions.php';
} else {
    error_log("Notification functions already loaded, skipping include");
}

@require_once 'phpqrcode/qrlib.php';

$unread_count = 0;
$notification_count = 0;
$notifications = [];

// Only call functions if they exist
if (function_exists('getUnreadCount')) {
    $unread_count = getUnreadCount($_SESSION['user_id'], $conn);
}

if (function_exists('getUnreadNotificationCount')) {
    $notification_count = getUnreadNotificationCount($conn, $_SESSION['user_id']);
}

if (function_exists('getTrainerNotifications')) {
    $notifications = getTrainerNotifications($conn, $_SESSION['user_id']);
}

$trainer_user_id = $_SESSION['user_id'];

// Initialize variables
$show_success_modal = false;
$success_data = [];
$member_type = $_GET['type'] ?? 'client';
$active_tab = $member_type;
$error_message = '';

// Handle AJAX requests for duplicate checking
if (isset($_GET['action']) && $_GET['action'] == 'check_duplicate') {
    header('Content-Type: application/json');
    
    if (!isset($_GET['field']) || !isset($_GET['value'])) {
        echo json_encode(['available' => false, 'message' => 'Invalid request']);
        exit();
    }
    
    $field = $_GET['field'];
    $value = $_GET['value'];
    
    // Validate field
    $allowed_fields = ['username', 'email'];
    if (!in_array($field, $allowed_fields)) {
        echo json_encode(['available' => false, 'message' => 'Invalid field']);
        exit();
    }
    
    // Check for duplicates
    $check_sql = "SELECT COUNT(*) as count FROM users WHERE $field = ? AND deleted_at IS NULL";
    $check_stmt = $conn->prepare($check_sql);
    if (!$check_stmt) {
        error_log("Duplicate check prepare failed: " . $conn->error);
        echo json_encode(['available' => false, 'message' => 'Database error']);
        exit();
    }
    
    $check_stmt->bind_param("s", $value);
    if (!$check_stmt->execute()) {
        error_log("Duplicate check execute failed: " . $check_stmt->error);
        echo json_encode(['available' => false, 'message' => 'Database error']);
        exit();
    }
    
    $result = $check_stmt->get_result();
    $row = $result->fetch_assoc();
    
    $available = $row['count'] == 0;
    $message = $available ? "$field is available" : "$field already exists";
    
    echo json_encode(['available' => $available, 'message' => $message]);
    exit();
}

// FIX 3: Check if function exists before declaring
if (!function_exists('calculateExpiryDate')) {
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
}

if (!function_exists('getMembershipPrice')) {
    function getMembershipPrice($plan) {
        switch($plan) {
            case 'daily': return 40.00;
            case 'weekly': return 160.00;
            case 'halfmonth': return 250.00;
            case 'monthly': return 400.00;
            default: return 0.00;
        }
    }
}

if (!function_exists('generateWalkInUsername')) {
    function generateWalkInUsername($full_name, $conn) {
        $base_username = 'walkin_' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $full_name));
        $username = substr($base_username, 0, 15);
        $counter = 1;
        
        $check_sql = "SELECT COUNT(*) as count FROM users WHERE username = ? AND deleted_at IS NULL";
        $check_stmt = $conn->prepare($check_sql);
        
        if (!$check_stmt) {
            return $base_username . '_' . time();
        }
        
        while (true) {
            $check_stmt->bind_param("s", $username);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            $row = $result->fetch_assoc();
            
            if ($row['count'] == 0) break;
            
            $username = substr($base_username, 0, 12) . $counter;
            $counter++;
            
            if ($counter > 100) {
                $username = $base_username . '_' . time();
                break;
            }
        }
        
        return $username;
    }
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['full_name'])) {
    error_log("=== FORM SUBMISSION DETECTED ===");
    
    // CSRF protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = "CSRF token validation failed";
        error_log("CSRF validation failed");
    } else {
        error_log("CSRF token validated successfully");
        
        $member_type = $_POST['member_type'] ?? 'client';
        error_log("Processing registration for member type: $member_type");
        
        // Common fields for both types
        $full_name = trim($_POST['full_name'] ?? '');
        $age = intval($_POST['age'] ?? 0);
        $contact_number = trim($_POST['contact_number'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $membership_plan = $_POST['membership_plan'] ?? '';
        
        // Client-specific fields
        $gender = '';
        $height = 0;
        $weight = 0;
        $fitness_goals = '';
        $username = '';
        $email = '';
        $password = '';
        $confirm_password = '';
        
        if ($member_type === 'client') {
            $gender = $_POST['gender'] ?? '';
            $height = floatval($_POST['height'] ?? 0);
            $weight = floatval($_POST['weight'] ?? 0);
            $fitness_goals = trim($_POST['fitness_goals'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
        }
        
        // Validate inputs
        $errors = [];
        
        // Common validations
        if (empty($full_name) || strlen($full_name) < 2) {
            $errors[] = "Full name must be at least 2 characters long!";
        }
        
        if ($age < 16 || $age > 100) {
            $errors[] = "Age must be between 16 and 100!";
        }
        
        if (!preg_match('/^[0-9]{11}$/', $contact_number)) {
            $errors[] = "Contact number must be exactly 11 digits!";
        }
        
        if (empty($address) || strlen($address) < 5) {
            $errors[] = "Address must be at least 5 characters long!";
        }
        
        if (empty($membership_plan)) {
            $errors[] = "Please select a membership plan!";
        }
        
        // Client-specific validations
        if ($member_type === 'client') {
            if (empty($gender)) {
                $errors[] = "Please select gender!";
            }
            
            if ($password !== $confirm_password) {
                $errors[] = "Passwords do not match!";
            }
            
            if (strlen($password) < 6) {
                $errors[] = "Password must be at least 6 characters long!";
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Please enter a valid email address!";
            }
            
          
            // Check for duplicates in final submission
            if (!empty($username)) {
                $check_username_sql = "SELECT COUNT(*) as count FROM users WHERE username = ? AND deleted_at IS NULL";
                $check_username_stmt = $conn->prepare($check_username_sql);
                if ($check_username_stmt) {
                    $check_username_stmt->bind_param("s", $username);
                    $check_username_stmt->execute();
                    $username_result = $check_username_stmt->get_result();
                    $username_row = $username_result->fetch_assoc();
                    
                    if ($username_row['count'] > 0) {
                        $errors[] = "Username already exists. Please choose a different username.";
                    }
                }
            }
            
            if (!empty($email)) {
                $check_email_sql = "SELECT COUNT(*) as count FROM users WHERE email = ? AND deleted_at IS NULL";
                $check_email_stmt = $conn->prepare($check_email_sql);
                if ($check_email_stmt) {
                    $check_email_stmt->bind_param("s", $email);
                    $check_email_stmt->execute();
                    $email_result = $check_email_stmt->get_result();
                    $email_row = $email_result->fetch_assoc();
                    
                    if ($email_row['count'] > 0) {
                        $errors[] = "Email already exists. Please use a different email address.";
                    }
                }
            }
        }
        
        error_log("Validation completed. Errors found: " . count($errors));
        if (!empty($errors)) {
            error_log("Validation errors: " . implode(", ", $errors));
        }
        
        if (empty($errors)) {
            // Calculate expiry date and price
            $start_date = date('Y-m-d');
            $expiry_date = calculateExpiryDate($membership_plan);
            $plan_price = getMembershipPrice($membership_plan);
            
            error_log("Starting registration process:");
            error_log("Name: $full_name, Type: $member_type, Plan: $membership_plan, Price: $plan_price");
            error_log("Start Date: $start_date, Expiry Date: $expiry_date");
            
            // Start transaction
            error_log("Starting database transaction");
            $conn->begin_transaction();
            
            try {
                $user_id = null;
                $qr_filename = null;
                $qr_content = null;
                $generated_username = '';
                
                if ($member_type === 'client') {
                    error_log("Processing CLIENT registration");
                    
                    // Create user account for client
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $user_sql = "INSERT INTO users (username, password, role, full_name, email, client_type) VALUES (?, ?, 'client', ?, ?, 'full-time')";
                    error_log("User SQL: $user_sql");
                    
                    $user_stmt = $conn->prepare($user_sql);
                    if (!$user_stmt) {
                        throw new Exception("Error preparing user statement: " . $conn->error);
                    }
                    
                    $user_stmt->bind_param("ssss", $username, $hashed_password, $full_name, $email);
                    error_log("Creating user account with username: $username, email: $email");
                    
                    if (!$user_stmt->execute()) {
                        throw new Exception("Error creating user account: " . $user_stmt->error);
                    }
                    
                    $user_id = $conn->insert_id;
                    $generated_username = $username;
                    error_log("User account created successfully with ID: $user_id");
                    
                    // Generate QR code
                    $qr_content = "CLIENT_" . $user_id;
                    $qr_filename = 'qrcodes/client_' . $user_id . '_' . time() . '.png';
                    
                } else {
                    error_log("Processing WALK-IN registration");
                    
                    // Walk-in registration - USE MANUAL USERNAME AND PASSWORD
                    $username = trim($_POST['username'] ?? '');
                    $email = trim($_POST['email'] ?? '');
                    $password = $_POST['password'] ?? '';
                    $confirm_password = $_POST['confirm_password'] ?? '';
                    
                    // Validate walk-in account information
                    if (empty($username) || strlen($username) < 3) {
                        throw new Exception("Username must be at least 3 characters long");
                    }
                    
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        throw new Exception("Please enter a valid email address for walk-in");
                    }
                    
                    if ($password !== $confirm_password) {
                        throw new Exception("Passwords do not match");
                    }
                    
                    if (strlen($password) < 6) {
                        throw new Exception("Password must be at least 6 characters long");
                    }
                    
                    // Create user account for walk-in with manual credentials
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $user_sql = "INSERT INTO users (username, password, role, full_name, email, client_type) VALUES (?, ?, 'client', ?, ?, 'walk-in')";
                    error_log("Walk-in User SQL: $user_sql");
                    
                    $user_stmt = $conn->prepare($user_sql);
                    if (!$user_stmt) {
                        throw new Exception("Error preparing walk-in user statement: " . $conn->error);
                    }
                    
                    $user_stmt->bind_param("ssss", $username, $hashed_password, $full_name, $email);
                    error_log("Creating walk-in user account with username: $username, email: $email");
                    
                    if (!$user_stmt->execute()) {
                        throw new Exception("Error creating walk-in user account: " . $user_stmt->error);
                    }
                    
                    $user_id = $conn->insert_id;
                    $generated_username = $username;
                    error_log("Walk-in user account created successfully with ID: $user_id");
                    
                    // Generate QR code
                    $qr_content = "WALKIN_" . $user_id;
                    $qr_filename = 'qrcodes/walkin_' . $user_id . '_' . time() . '.png';
                }
                
                // Create qrcodes directory if it doesn't exist
                if (!is_dir('qrcodes')) {
                    error_log("Creating qrcodes directory");
                    if (!mkdir('qrcodes', 0755, true)) {
                        throw new Exception("Failed to create QR code directory");
                    }
                }
                
                // Check if directory is writable
                if (!is_writable('qrcodes')) {
                    throw new Exception("QR code directory is not writable");
                }
                
                // Generate QR code if library exists
                if (class_exists('QRcode')) {
                    error_log("Generating QR code: $qr_filename");
                    QRcode::png($qr_content, $qr_filename, QR_ECLEVEL_L, 8);
                    error_log("QR code generated successfully");
                } else {
                    error_log("QR code library not available - skipping QR generation");
                    $qr_filename = null;
                }
                
                if ($member_type === 'client') {
                    // FIXED: Correct SQL for client insertion with proper parameter count
                    $member_sql = "INSERT INTO members (member_type, full_name, age, contact_number, address, gender, height, weight, fitness_goals, membership_plan, start_date, expiry_date, status, created_by, user_id, qr_code_path) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    error_log("Member SQL: $member_sql");
                    
                    $member_stmt = $conn->prepare($member_sql);
                    if (!$member_stmt) {
                        throw new Exception("Error preparing member statement: " . $conn->error);
                    }
                    
                    $status = 'active';
                    $member_stmt->bind_param("ssisssddsssssiis", 
                        $member_type,        // member_type (s)
                        $full_name,          // full_name (s)
                        $age,                // age (i)
                        $contact_number,     // contact_number (s)
                        $address,            // address (s)
                        $gender,             // gender (s)
                        $height,             // height (d)
                        $weight,             // weight (d)
                        $fitness_goals,      // fitness_goals (s)
                        $membership_plan,    // membership_plan (s)
                        $start_date,         // start_date (s)
                        $expiry_date,        // expiry_date (s)
                        $status,             // status (s)
                        $trainer_user_id,    // created_by (i)
                        $user_id,            // user_id (i)
                        $qr_filename         // qr_code_path (s)
                    );
                    
                } else {
                    // FIXED: Correct SQL for walk-in insertion with proper parameter count
                    $member_sql = "INSERT INTO members (member_type, full_name, age, contact_number, address, membership_plan, start_date, expiry_date, status, created_by, user_id, qr_code_path) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    error_log("Walk-in Member SQL: $member_sql");
                    
                    $member_stmt = $conn->prepare($member_sql);
                    if (!$member_stmt) {
                        throw new Exception("Error preparing walk-in member statement: " . $conn->error);
                    }
                    
                    $status = 'active';
                    $member_stmt->bind_param("ssissssssiis", 
                        $member_type,        // member_type (s)
                        $full_name,          // full_name (s)
                        $age,                // age (i)
                        $contact_number,     // contact_number (s)
                        $address,            // address (s)
                        $membership_plan,    // membership_plan (s)
                        $start_date,         // start_date (s)
                        $expiry_date,        // expiry_date (s)
                        $status,             // status (s)
                        $trainer_user_id,    // created_by (i)
                        $user_id,            // user_id (i)
                        $qr_filename         // qr_code_path (s)
                    );
                }
                
                error_log("Executing member insertion query");
                if ($member_stmt->execute()) {
                    $member_id = $conn->insert_id;
                    error_log("Member record created successfully with ID: $member_id");
                    
                    // Record membership payment
                    $payment_sql = "INSERT INTO membership_payments (member_id, member_name, plan_type, plan_price, amount, payment_date, payment_method, transaction_id, status) 
                                   VALUES (?, ?, ?, ?, ?, CURDATE(), 'cash', ?, 'completed')";
                    error_log("Payment SQL: $payment_sql");
                    
                    $payment_stmt = $conn->prepare($payment_sql);
                    if (!$payment_stmt) {
                        throw new Exception("Error preparing payment statement: " . $conn->error);
                    }
                    
                    $transaction_id = strtoupper($member_type) . '_' . $member_id . '_' . time();
                    $payment_stmt->bind_param("issdss", $member_id, $full_name, $membership_plan, $plan_price, $plan_price, $transaction_id);
                    
                    error_log("Recording membership payment with transaction ID: $transaction_id");
                    if (!$payment_stmt->execute()) {
                        throw new Exception("Error recording membership payment: " . $payment_stmt->error);
                    }
                    
                    $payment_id = $conn->insert_id;
                    error_log("Membership payment recorded with ID: $payment_id");
                    
                    // FIXED: Remove reference_type column and fix parameter count
                    $revenue_sql = "INSERT INTO revenue_entries (
                        category_id, 
                        amount, 
                        description, 
                        payment_method, 
                        reference_id, 
                        reference_name, 
                        revenue_date, 
                        recorded_by, 
                        notes,
                        reconciled
                    ) VALUES (?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, 0)";

                    error_log("Revenue SQL: $revenue_sql");

                    $revenue_stmt = $conn->prepare($revenue_sql);
                    if (!$revenue_stmt) {
                        throw new Exception("Revenue statement preparation failed: " . $conn->error);
                    }

                    $category_id = 2; // Membership Fees category
                    $description = $member_type . ' registration: ' . $full_name . ' - ' . $membership_plan;
                    $payment_method = 'cash';
                    $reference_id = $member_id;
                    $reference_name = $full_name;
                    $notes = 'Auto-recorded from ' . $member_type . ' registration by trainer';

                    error_log("Creating revenue entry: $description");

                    // FINAL CORRECT VERSION
                    $revenue_stmt->bind_param(
                        "idsssiss",  // i,d,s,s,s,s,i,s
                        $category_id,        // i (integer)
                        $plan_price,         // d (decimal)
                        $description,        // s (string)
                        $payment_method,     // s (string)
                        $reference_id,       // s (string)
                        $reference_name,     // s (string) - full_name is a string
                        $trainer_user_id,    // i (integer) - user_id is integer
                        $notes               // s (string)
                    );

                    if (!$revenue_stmt->execute()) {
                        throw new Exception("Revenue entry creation failed: " . $revenue_stmt->error);
                    }
                    $revenue_id = $conn->insert_id;
                    error_log("Revenue entry created with ID: $revenue_id");
                    
                    // Update the membership_payments table with revenue_entry_id
                    $update_payment_sql = "UPDATE membership_payments SET revenue_entry_id = ? WHERE id = ?";
                    $update_payment_stmt = $conn->prepare($update_payment_sql);
                    $update_payment_stmt->bind_param("ii", $revenue_id, $payment_id);
                    $update_payment_stmt->execute();
                    error_log("Updated membership payment with revenue entry ID");
                    
                    // Auto-assign client to trainer (only for regular clients)
                    if ($member_type === 'client') {
                        $assignment_sql = "INSERT INTO trainer_client_assignments (trainer_user_id, client_user_id) VALUES (?, ?)";
                        $assignment_stmt = $conn->prepare($assignment_sql);
                        if ($assignment_stmt) {
                            $assignment_stmt->bind_param("ii", $trainer_user_id, $user_id);
                            
                            if (!$assignment_stmt->execute()) {
                                error_log("Failed to assign client to trainer: " . $assignment_stmt->error);
                                // Don't throw exception for this, just log it
                            } else {
                                error_log("Client assigned to trainer successfully");
                            }
                        }
                    }
                    
                    // Create notification using function from notification_functions.php
                    if (function_exists('createNotification')) {
                        createNotification(
                            $conn,
                            $trainer_user_id,
                            'trainer',
                            ucfirst($member_type) . ' Registered',
                            'Successfully registered ' . $member_type . ': ' . $full_name . ' with ' . $membership_plan . ' membership (₱' . $plan_price . ')',
                            'membership',
                            'medium'
                        );
                        error_log("Notification created");
                    }
                    
                    if ($conn->commit()) {
                        error_log("=== TRANSACTION COMMITTED SUCCESSFULLY ===");
                        
                        // Store success data
                        $show_success_modal = true;
                        $success_data = [
                            'member_type' => $member_type,
                            'username' => $generated_username,
                            'password' => $password, // Use the actual password entered
                            'full_name' => $full_name,
                            'membership_plan' => $membership_plan,
                            'plan_price' => $plan_price,
                            'expiry_date' => $expiry_date,
                            'qr_code_path' => $qr_filename,
                            'qr_code_data' => $qr_content,
                            'revenue_recorded' => true
                        ];
                        
                        // Clear form
                        $_POST = array();
                        
                        // Regenerate CSRF token after successful form submission
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        
                    } else {
                        throw new Exception("Transaction commit failed: " . $conn->error);
                    }
                    
                } else {
                    throw new Exception("Error registering member: " . $member_stmt->error);
                }
                
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Registration failed: " . $e->getMessage();
                error_log("=== REGISTRATION FAILED: " . $e->getMessage() . " ===");
                error_log("Stack trace: " . $e->getTraceAsString());
            }
        } else {
            $error_message = implode("<br>", $errors);
            error_log("Validation failed: " . $error_message);
        }
    }
} else {
    // This is not a form submission, so clear any previous error
    $error_message = '';
}

// Get stats for display
$total_clients = 0;
$active_clients = 0;
$expired_clients = 0;
$today_clients = 0;
$total_walkins = 0;
$active_walkins = 0;

if ($conn) {
    // Client stats
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM members WHERE member_type = 'client' AND deleted_at IS NULL");
    $stmt->execute();
    $total_clients = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM members WHERE member_type = 'client' AND status = 'active' AND expiry_date >= CURDATE() AND deleted_at IS NULL");
    $stmt->execute();
    $active_clients = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM members WHERE member_type = 'client' AND (status = 'expired' OR expiry_date < CURDATE()) AND deleted_at IS NULL");
    $stmt->execute();
    $expired_clients = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM members WHERE member_type = 'client' AND DATE(created_at) = CURDATE() AND deleted_at IS NULL");
    $stmt->execute();
    $today_clients = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();
    
    // Walk-in stats
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM members WHERE member_type = 'walk-in' AND deleted_at IS NULL");
    $stmt->execute();
    $total_walkins = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM members WHERE member_type = 'walk-in' AND status = 'active' AND expiry_date >= CURDATE() AND deleted_at IS NULL");
    $stmt->execute();
    $active_walkins = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();
}

// Debug: Check if we can query the members table
error_log("Stats - Total Clients: $total_clients, Total Walk-ins: $total_walkins");
?>
<?php require_once 'includes/trainer_header.php'; ?>
<?php require_once 'includes/trainer_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 p-6 overflow-auto">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-yellow-400 flex items-center gap-2">
                <i data-lucide="user-plus"></i>
                Member Registration
            </h1>
            <div class="text-sm text-gray-400 flex items-center gap-2">
                <i data-lucide="dollar-sign" class="text-green-400"></i>
                <span>Revenue automatically recorded in financial system</span>
            </div>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-error">
                <div class="flex items-center gap-3">
                    <i data-lucide="alert-circle"></i>
                    <span class="font-semibold">Registration Error</span>
                </div>
                <div class="mt-2 text-sm text-red-300">
                    <?php echo $error_message; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Registration Form -->
            <div class="lg:col-span-2">
                <div class="tab-container">
                    <!-- Tab Header -->
                    <div class="tab-header">
                        <button class="tab-button <?php echo $active_tab === 'client' ? 'active' : ''; ?>" data-tab="client">
                            <i data-lucide="user-check"></i>
                            Client Registration
                        </button>
                        <button class="tab-button <?php echo $active_tab === 'walk-in' ? 'active' : ''; ?>" data-tab="walk-in">
                            <i data-lucide="user"></i>
                            Walk-in Registration
                        </button>
                    </div>

                    <!-- Client Registration Tab -->
                    <div class="tab-content <?php echo $active_tab === 'client' ? 'active' : ''; ?>" id="client-tab">
                        <form method="POST" class="space-y-6" id="clientForm">
                            <input type="hidden" name="member_type" value="client">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            
                            <!-- Personal Information -->
                            <div>
                                <h3 class="text-md font-semibold text-yellow-400 mb-3">Personal Information</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="form-label">Full Name</label>
                                        <input type="text" name="full_name" class="form-input" 
                                               value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" 
                                               required minlength="2" oninput="validateName(this)">
                                        <div class="validation-message" id="nameValidation"></div>
                                    </div>
                                    <div>
                                        <label class="form-label">Age</label>
                                        <input type="number" name="age" class="form-input" 
                                               value="<?php echo htmlspecialchars($_POST['age'] ?? ''); ?>" 
                                               min="16" max="100" required oninput="validateAge(this)">
                                        <div class="validation-message" id="ageValidation"></div>
                                    </div>
                                    <div>
                                        <label class="form-label">Contact Number</label>
                                        <input type="tel" name="contact_number" class="form-input" 
                                               value="<?php echo htmlspecialchars($_POST['contact_number'] ?? ''); ?>" 
                                               pattern="[0-9]{11}" placeholder="09XXXXXXXXX" required 
                                               maxlength="11" oninput="validateContact(this)">
                                        <div class="validation-message" id="contactValidation"></div>
                                    </div>
                                    <div>
                                        <label class="form-label">Gender</label>
                                        <select name="gender" class="form-select" required>
                                            <option value="">Select gender</option>
                                            <option value="male" <?php echo ($_POST['gender'] ?? '') == 'male' ? 'selected' : ''; ?>>Male</option>
                                            <option value="female" <?php echo ($_POST['gender'] ?? '') == 'female' ? 'selected' : ''; ?>>Female</option>
                                            <option value="other" <?php echo ($_POST['gender'] ?? '') == 'other' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="form-label">Address</label>
                                        <input type="text" name="address" class="form-input" 
                                               value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>" 
                                               required minlength="5" oninput="validateAddress(this)">
                                        <div class="validation-message" id="addressValidation"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Account Information -->
                            <div>
                                <h3 class="text-md font-semibold text-yellow-400 mb-3">Account Information</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="duplicate-check">
                                        <label class="form-label">Username</label>
                                        <div class="flex gap-2">
                                            <input type="text" name="username" class="form-input flex-1" 
                                                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" 
                                                   pattern="[a-zA-Z0-9_]{3,20}" required 
                                                   oninput="validateUsername(this); checkDuplicate('username', this.value)">
                                            <button type="button" onclick="generateUsername('client')" class="btn btn-primary whitespace-nowrap">
                                                <i data-lucide="sparkles"></i> Generate
                                            </button>
                                        </div>
                                        <div class="check-indicator" id="usernameCheckIndicator"></div>
                                        <div class="availability-message" id="usernameAvailability"></div>
                                        <div class="validation-message" id="usernameValidation"></div>
                                    </div>
                                    <div class="duplicate-check">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" class="form-input" 
                                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                                               placeholder="client@example.com" required 
                                               oninput="validateEmail(this); checkDuplicate('email', this.value)">
                                        <div class="check-indicator" id="emailCheckIndicator"></div>
                                        <div class="availability-message" id="emailAvailability"></div>
                                        <div class="validation-message" id="emailValidation"></div>
                                    </div>
                                    <div>
                                        <label class="form-label">Password</label>
                                        <input type="password" name="password" class="form-input" 
                                               placeholder="Enter password" minlength="6" required oninput="validatePassword(this)">
                                        <div class="validation-message" id="passwordValidation"></div>
                                    </div>
                                    <div>
                                        <label class="form-label">Confirm Password</label>
                                        <input type="password" name="confirm_password" class="form-input" 
                                               placeholder="Confirm password" minlength="6" required oninput="validateConfirmPassword(this)">
                                        <div class="validation-message" id="confirmPasswordValidation"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Fitness Information -->
                            <div>
                                <h3 class="text-md font-semibold text-yellow-400 mb-3">Fitness Information</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="form-label">Height (cm)</label>
                                        <input type="number" name="height" step="0.1" class="form-input" 
                                               value="<?php echo htmlspecialchars($_POST['height'] ?? ''); ?>" 
                                               min="100" max="250" required oninput="validateHeight(this)">
                                        <div class="validation-message" id="heightValidation"></div>
                                    </div>
                                    <div>
                                        <label class="form-label">Weight (kg)</label>
                                        <input type="number" name="weight" step="0.1" class="form-input" 
                                               value="<?php echo htmlspecialchars($_POST['weight'] ?? ''); ?>" 
                                               min="30" max="300" required oninput="validateWeight(this)">
                                        <div class="validation-message" id="weightValidation"></div>
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="form-label">Fitness Goals</label>
                                        <textarea name="fitness_goals" class="form-input" 
                                               placeholder="e.g., Weight loss, Muscle gain, Strength training" 
                                               rows="3"><?php echo htmlspecialchars($_POST['fitness_goals'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Membership Information -->
                            <div>
                                <h3 class="text-md font-semibold text-yellow-400 mb-3">Membership Information</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="form-label">Membership Plan</label>
                                        <select name="membership_plan" class="form-select" required onchange="updateExpiryDate(this.value)">
                                            <option value="">Select a plan</option>
                                            <option value="daily" <?php echo ($_POST['membership_plan'] ?? '') == 'daily' ? 'selected' : ''; ?>>Per Visit - ₱40</option>
                                            <option value="weekly" <?php echo ($_POST['membership_plan'] ?? '') == 'weekly' ? 'selected' : ''; ?>>Weekly - ₱160</option>
                                            <option value="halfmonth" <?php echo ($_POST['membership_plan'] ?? '') == 'halfmonth' ? 'selected' : ''; ?>>Half Month - ₱250</option>
                                            <option value="monthly" <?php echo ($_POST['membership_plan'] ?? '') == 'monthly' ? 'selected' : ''; ?>>Monthly - ₱400</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label">Expiry Date</label>
                                        <input type="text" id="expiryDate" class="form-input bg-gray-600/50" readonly
                                               value="<?php echo !empty($_POST['membership_plan']) ? calculateExpiryDate($_POST['membership_plan']) : ''; ?>">
                                    </div>
                                </div>
                                <div class="mt-2 text-sm text-green-400 flex items-center gap-2">
                                    <i data-lucide="dollar-sign"></i>
                                    <span>Membership fee will be automatically recorded in revenue system</span>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex gap-3 pt-4 border-t border-gray-700">
                                <button type="reset" class="btn btn-danger" onclick="resetForm('client')">
                                    <i data-lucide="refresh-cw"></i> Reset
                                </button>
                                <button type="submit" class="btn btn-success flex-1" id="clientSubmitBtn">
                                    <i data-lucide="user-check"></i> Register Client
                                </button>
                            </div>
                        </form>
                    </div>

                   <!-- Walk-in Registration Tab -->
<div class="tab-content <?php echo $active_tab === 'walk-in' ? 'active' : ''; ?>" id="walk-in-tab">
    <form method="POST" class="space-y-6" id="walkinForm">
        <input type="hidden" name="member_type" value="walk-in">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        
        <!-- Personal Information -->
        <div>
            <h3 class="text-md font-semibold text-yellow-400 mb-3">Personal Information</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Full Name</label>
                    <input type="text" name="full_name" class="form-input" 
                           value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" 
                           required minlength="2" oninput="validateName(this)">
                    <div class="validation-message" id="walkinNameValidation"></div>
                </div>
                <div>
                    <label class="form-label">Age</label>
                    <input type="number" name="age" class="form-input" 
                           value="<?php echo htmlspecialchars($_POST['age'] ?? ''); ?>" 
                           min="16" max="100" required oninput="validateAge(this)">
                    <div class="validation-message" id="walkinAgeValidation"></div>
                </div>
                <div>
                    <label class="form-label">Contact Number</label>
                    <input type="tel" name="contact_number" class="form-input" 
                           value="<?php echo htmlspecialchars($_POST['contact_number'] ?? ''); ?>" 
                           pattern="[0-9]{11}" placeholder="09XXXXXXXXX" required 
                           maxlength="11" oninput="validateContact(this)">
                    <div class="validation-message" id="walkinContactValidation"></div>
                </div>
                <div class="md:col-span-2">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-input" 
                           value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>" 
                           required minlength="5" oninput="validateAddress(this)">
                    <div class="validation-message" id="walkinAddressValidation"></div>
                </div>
            </div>
        </div>

        <!-- Account Information for Walk-in -->
        <div>
            <h3 class="text-md font-semibold text-yellow-400 mb-3">Account Information</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="duplicate-check">
                    <label class="form-label">Username</label>
                    <div class="flex gap-2">
                        <input type="text" name="username" class="form-input flex-1" 
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" 
                               pattern="[a-zA-Z0-9_]{3,20}" required 
                               oninput="validateUsername(this); checkDuplicate('username', this.value)">
                        <button type="button" onclick="generateWalkinUsername()" class="btn btn-primary whitespace-nowrap">
                            <i data-lucide="sparkles"></i> Suggest
                        </button>
                    </div>
                    <div class="check-indicator" id="walkinUsernameCheckIndicator"></div>
                    <div class="availability-message" id="walkinUsernameAvailability"></div>
                    <div class="validation-message" id="walkinUsernameValidation"></div>
                </div>
                <div class="duplicate-check">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-input" 
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                           placeholder="walkin@example.com" required 
                           oninput="validateEmail(this); checkDuplicate('email', this.value)">
                    <div class="check-indicator" id="walkinEmailCheckIndicator"></div>
                    <div class="availability-message" id="walkinEmailAvailability"></div>
                    <div class="validation-message" id="walkinEmailValidation"></div>
                </div>
                <div>
                    <label class="form-label">Password</label>
                    <div class="password-input">
                        <input type="password" name="password" class="form-input" 
                               placeholder="Enter password" minlength="6" required oninput="validatePassword(this)">
                        <button type="button" class="password-toggle" onclick="togglePassword('walkinPassword')">
                            <i data-lucide="eye"></i>
                        </button>
                    </div>
                    <div class="validation-message" id="walkinPasswordValidation"></div>
                </div>
                <div>
                    <label class="form-label">Confirm Password</label>
                    <div class="password-input">
                        <input type="password" name="confirm_password" class="form-input" 
                               placeholder="Confirm password" minlength="6" required oninput="validateConfirmPassword(this)">
                        <button type="button" class="password-toggle" onclick="togglePassword('walkinConfirmPassword')">
                            <i data-lucide="eye"></i>
                        </button>
                    </div>
                    <div class="validation-message" id="walkinConfirmPasswordValidation"></div>
                </div>
            </div>
        </div>

        <!-- Membership Information -->
        <div>
            <h3 class="text-md font-semibold text-yellow-400 mb-3">Membership Information</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Membership Plan</label>
                    <select name="membership_plan" class="form-select" required onchange="updateWalkinExpiryDate(this.value)">
                        <option value="">Select a plan</option>
                        <option value="daily" <?php echo ($_POST['membership_plan'] ?? '') == 'daily' ? 'selected' : ''; ?>>Per Visit - ₱40</option>
                        <option value="weekly" <?php echo ($_POST['membership_plan'] ?? '') == 'weekly' ? 'selected' : ''; ?>>Weekly - ₱160</option>
                        <option value="halfmonth" <?php echo ($_POST['membership_plan'] ?? '') == 'halfmonth' ? 'selected' : ''; ?>>Half Month - ₱250</option>
                        <option value="monthly" <?php echo ($_POST['membership_plan'] ?? '') == 'monthly' ? 'selected' : ''; ?>>Monthly - ₱400</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Expiry Date</label>
                    <input type="text" id="walkinExpiryDate" class="form-input bg-gray-600/50" readonly
                           value="<?php echo !empty($_POST['membership_plan']) ? calculateExpiryDate($_POST['membership_plan']) : ''; ?>">
                </div>
            </div>
            <div class="mt-2 text-sm text-green-400 flex items-center gap-2">
                <i data-lucide="dollar-sign"></i>
                <span>Membership fee will be automatically recorded in revenue system</span>
            </div>
        </div>

        <!-- Walk-in Information -->
        <div class="bg-yellow-500/10 border border-yellow-500/20 rounded-lg p-4">
            <h3 class="text-md font-semibold text-yellow-400 mb-2 flex items-center gap-2">
                <i data-lucide="info"></i>
                Walk-in Information
            </h3>
            <ul class="text-sm text-yellow-300 space-y-1">
                <li>• Walk-in members get full dashboard access</li>
                <li>• Perfect for one-time or short-term visitors</li>
                <li>• QR code will be generated for attendance</li>
                <li>• Membership fee automatically recorded in revenue</li>
                <li>• Can login to Walk-in Dashboard with credentials above</li>
            </ul>
        </div>

        <!-- Action Buttons -->
        <div class="flex gap-3 pt-4 border-t border-gray-700">
            <button type="reset" class="btn btn-danger" onclick="resetForm('walk-in')">
                <i data-lucide="refresh-cw"></i> Reset
            </button>
            <button type="submit" class="btn btn-success flex-1" id="walkinSubmitBtn">
                <i data-lucide="user"></i> Register Walk-in
            </button>
        </div>
    </form>
</div>
                </div>
            </div>

            <!-- Quick Stats & Info -->
            <div class="space-y-6">
                <div class="grid grid-cols-1 gap-4">
                    <!-- Client Stats -->
                    <div class="stat-card">
                        <div class="text-3xl font-bold text-yellow-400 mb-2">
                            <?php echo $total_clients; ?>
                        </div>
                        <div class="text-gray-400">Total Clients</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="text-3xl font-bold text-green-400 mb-2">
                            <?php echo $active_clients; ?>
                        </div>
                        <div class="text-gray-400">Active Clients</div>
                    </div>
                    
                    <!-- Walk-in Stats -->
                    <div class="stat-card">
                        <div class="text-3xl font-bold text-blue-400 mb-2">
                            <?php echo $total_walkins; ?>
                        </div>
                        <div class="text-gray-400">Total Walk-ins</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="text-3xl font-bold text-purple-400 mb-2">
                            <?php echo $active_walkins; ?>
                        </div>
                        <div class="text-gray-400">Active Walk-ins</div>
                    </div>
                </div>
                
                <!-- Quick Tips -->
                <div class="card">
                    <h3 class="card-title"><i data-lucide="lightbulb"></i> Quick Tips</h3>
                    <div class="space-y-4">
                        <div>
                            <h4 class="text-yellow-400 text-sm font-semibold mb-2">For Clients:</h4>
                            <ul class="text-sm text-gray-400 space-y-1">
                                <li class="flex items-start gap-2">
                                    <i data-lucide="check" class="text-green-400 mt-1 flex-shrink-0"></i>
                                    <span>Full dashboard access with progress tracking</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i data-lucide="check" class="text-green-400 mt-1 flex-shrink-0"></i>
                                    <span>Auto-assigned to you as trainer</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i data-lucide="check" class="text-green-400 mt-1 flex-shrink-0"></i>
                                    <span>Custom workout and meal plans</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i data-lucide="dollar-sign" class="text-green-400 mt-1 flex-shrink-0"></i>
                                    <span>Revenue automatically recorded</span>
                                </li>
                            </ul>
                        </div>
                        <div>
                            <h4 class="text-blue-400 text-sm font-semibold mb-2">For Walk-ins:</h4>
                            <ul class="text-sm text-gray-400 space-y-1">
                                <li class="flex items-start gap-2">
                                    <i data-lucide="check" class="text-green-400 mt-1 flex-shrink-0"></i>
                                    <span>Auto-generated credentials</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i data-lucide="check" class="text-green-400 mt-1 flex-shrink-0"></i>
                                    <span>Limited dashboard access</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i data-lucide="check" class="text-green-400 mt-1 flex-shrink-0"></i>
                                    <span>Perfect for short-term visitors</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i data-lucide="dollar-sign" class="text-green-400 mt-1 flex-shrink-0"></i>
                                    <span>Revenue automatically recorded</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Revenue Integration Info -->
                <div class="card bg-green-500/10 border border-green-500/20">
                    <h3 class="card-title text-green-400">
                        <i data-lucide="dollar-sign"></i>
                        Revenue Integration
                    </h3>
                    <div class="space-y-2 text-sm text-green-300">
                        <p class="flex items-center gap-2">
                            <i data-lucide="check-circle"></i>
                            Membership fees automatically recorded
                        </p>
                        <p class="flex items-center gap-2">
                            <i data-lucide="trending-up"></i>
                            Appears in financial reports instantly
                        </p>
                        <p class="flex items-center gap-2">
                            <i data-lucide="file-text"></i>
                            Trackable in Revenue Management
                        </p>
                        <p class="text-xs text-green-400/70 mt-2">
                            All payments are automatically synchronized with the financial system for accurate revenue tracking.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </main>
  </div>

  <script>
    // Initialize Lucide icons when the page loads
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Lucide icons
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        } else {
            console.error('Lucide icons not loaded');
        }

        // Tab switching
        document.querySelectorAll('.tab-button').forEach(button => {
            button.addEventListener('click', function() {
                const tabId = this.dataset.tab;
                
                // Update active tab button
                document.querySelectorAll('.tab-button').forEach(btn => {
                    btn.classList.remove('active');
                });
                this.classList.add('active');
                
                // Show active tab content
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.remove('active');
                });
                document.getElementById(tabId + '-tab').classList.add('active');
                
                // Update URL without reload
                const url = new URL(window.location);
                url.searchParams.set('type', tabId);
                window.history.replaceState({}, '', url);
            });
        });

        // Sidebar functionality
        document.getElementById('toggleSidebar').addEventListener('click', () => {
            const sidebar = document.getElementById('sidebar');
            if (sidebar.classList.contains('w-60')) {
                sidebar.classList.remove('w-60');
                sidebar.classList.add('w-16', 'sidebar-collapsed');
            } else {
                sidebar.classList.remove('w-16', 'sidebar-collapsed');
                sidebar.classList.add('w-60');
            }
        });

        // Members submenu toggle
        const membersToggle = document.getElementById('membersToggle');
        const membersSubmenu = document.getElementById('membersSubmenu');
        const membersChevron = document.getElementById('membersChevron');
        
        membersToggle.addEventListener('click', () => {
            membersSubmenu.classList.toggle('open');
            membersChevron.classList.toggle('rotate');
        });

        // Plans submenu toggle
        const plansToggle = document.getElementById('plansToggle');
        const plansSubmenu = document.getElementById('plansSubmenu');
        const plansChevron = document.getElementById('plansChevron');
        
        plansToggle.addEventListener('click', () => {
            plansSubmenu.classList.toggle('open');
            plansChevron.classList.toggle('rotate');
        });

        // Initialize dropdown functionality
        setupDropdowns();

        // Initialize expiry dates
        const clientPlan = document.querySelector('#client-tab select[name="membership_plan"]');
        const walkinPlan = document.querySelector('#walk-in-tab select[name="membership_plan"]');
        
        if (clientPlan && clientPlan.value) updateExpiryDate(clientPlan.value);
        if (walkinPlan && walkinPlan.value) updateWalkinExpiryDate(walkinPlan.value);

        // Auto-show success modal
        <?php if ($show_success_modal): ?>
        setTimeout(() => {
            document.getElementById('successModal').classList.add('active');
        }, 100);
        <?php endif; ?>
    });

// Generate suggested username for walk-in
function generateWalkinUsername() {
    const fullName = document.querySelector('#walk-in-tab input[name="full_name"]').value;
    if (!fullName) {
        alert('Please enter full name first');
        return;
    }

    const usernameInput = document.querySelector('#walk-in-tab input[name="username"]');
    let baseUsername = 'walkin_' + fullName.toLowerCase().replace(/[^a-z0-9]/g, '');
    baseUsername = baseUsername.substring(0, 15);
    
    const timestamp = Date.now().toString().slice(-4);
    const suggestedUsername = baseUsername + timestamp;
    
    usernameInput.value = suggestedUsername;
    validateUsername(usernameInput);
    checkDuplicate('username', suggestedUsername);
}

// Toggle password visibility for walk-in form
function togglePassword(fieldId) {
    const input = document.querySelector(`#walk-in-tab input[name="${fieldId}"]`);
    const toggle = event.target;
    const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
    input.setAttribute('type', type);
    
    const icon = toggle.querySelector('i');
    if (type === 'password') {
        icon.setAttribute('data-lucide', 'eye');
    } else {
        icon.setAttribute('data-lucide', 'eye-off');
    }
    lucide.createIcons();
}

// Validation functions for walk-in form
function validateWalkinUsername(input) {
    const validation = document.getElementById('walkinUsernameValidation');
    if (input.value.length < 3) {
        validation.textContent = 'Username must be at least 3 characters';
        validation.className = 'validation-message invalid';
    } else if (!/^[a-zA-Z0-9_]+$/.test(input.value)) {
        validation.textContent = 'Username can only contain letters, numbers, and underscores';
        validation.className = 'validation-message invalid';
    } else {
        validation.textContent = 'Username format is valid';
        validation.className = 'validation-message valid';
    }
}

function validateWalkinEmail(input) {
    const validation = document.getElementById('walkinEmailValidation');
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(input.value)) {
        validation.textContent = 'Please enter a valid email address';
        validation.className = 'validation-message invalid';
    } else {
        validation.textContent = 'Email format is valid';
        validation.className = 'validation-message valid';
    }
}

function validateWalkinPassword(input) {
    const validation = document.getElementById('walkinPasswordValidation');
    if (input.value.length < 6) {
        validation.textContent = 'Password must be at least 6 characters';
        validation.className = 'validation-message invalid';
    } else {
        validation.textContent = 'Password is valid';
        validation.className = 'validation-message valid';
    }
}

function validateWalkinConfirmPassword(input) {
    const password = document.querySelector('#walk-in-tab input[name="password"]').value;
    const validation = document.getElementById('walkinConfirmPasswordValidation');
    if (input.value !== password) {
        validation.textContent = 'Passwords do not match';
        validation.className = 'validation-message invalid';
    } else {
        validation.textContent = 'Passwords match';
        validation.className = 'validation-message valid';
    }
}

    // Enhanced notification functionality
    function setupDropdowns() {
        const notificationBell = document.getElementById('notificationBell');
        const notificationDropdown = document.getElementById('notificationDropdown');
        const userMenuButton = document.getElementById('userMenuButton');
        const userDropdown = document.getElementById('userDropdown');
        
        function closeAllDropdowns() {
            notificationDropdown.classList.add('hidden');
            userDropdown.classList.add('hidden');
        }
        
        notificationBell.addEventListener('click', function(e) {
            e.stopPropagation();
            const isHidden = notificationDropdown.classList.contains('hidden');
            
            closeAllDropdowns();
            
            if (isHidden) {
                notificationDropdown.classList.remove('hidden');
            }
        });
        
        userMenuButton.addEventListener('click', function(e) {
            e.stopPropagation();
            const isHidden = userDropdown.classList.contains('hidden');
            
            closeAllDropdowns();
            
            if (isHidden) {
                userDropdown.classList.remove('hidden');
            }
        });
        
        document.addEventListener('click', function(e) {
            if (!notificationDropdown.contains(e.target) && !notificationBell.contains(e.target) &&
                !userDropdown.contains(e.target) && !userMenuButton.contains(e.target)) {
                closeAllDropdowns();
            }
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAllDropdowns();
            }
        });
    }

    function updateExpiryDate(plan) {
        if (!plan) {
            document.getElementById('expiryDate').value = '';
            return;
        }
        
        const today = new Date();
        let expiryDate = new Date();
        
        switch(plan) {
            case 'daily': expiryDate.setDate(today.getDate() + 1); break;
            case 'weekly': expiryDate.setDate(today.getDate() + 7); break;
            case 'halfmonth': expiryDate.setDate(today.getDate() + 15); break;
            case 'monthly': expiryDate.setMonth(today.getMonth() + 1); break;
        }
        
        document.getElementById('expiryDate').value = expiryDate.toISOString().split('T')[0];
    }

    function updateWalkinExpiryDate(plan) {
        if (!plan) {
            document.getElementById('walkinExpiryDate').value = '';
            return;
        }
        
        const today = new Date();
        let expiryDate = new Date();
        
        switch(plan) {
            case 'daily': expiryDate.setDate(today.getDate() + 1); break;
            case 'weekly': expiryDate.setDate(today.getDate() + 7); break;
            case 'halfmonth': expiryDate.setDate(today.getDate() + 15); break;
            case 'monthly': expiryDate.setMonth(today.getMonth() + 1); break;
        }
        
        document.getElementById('walkinExpiryDate').value = expiryDate.toISOString().split('T')[0];
    }

    // Modal functions
    function closeModal() {
        const modal = document.getElementById('successModal');
        if (modal) {
            modal.classList.remove('active');
            setTimeout(() => {
                modal.remove();
            }, 300);
        }
    }

    function registerAnother() {
        closeModal();
        document.querySelector('form').reset();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal-overlay')) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModal();
        }
    });

    // Enhanced Validation functions
    function validateName(input) {
        const tab = input.closest('.tab-content').id;
        const suffix = tab === 'client-tab' ? '' : 'walkin';
        const validation = document.getElementById(suffix + 'nameValidation');
        if (input.value.length < 2) {
            validation.textContent = 'Name must be at least 2 characters';
            validation.className = 'validation-message invalid';
        } else {
            validation.textContent = 'Name looks good!';
            validation.className = 'validation-message valid';
        }
    }

   

    function validateContact(input) {
        const tab = input.closest('.tab-content').id;
        const suffix = tab === 'client-tab' ? '' : 'walkin';
        const validation = document.getElementById(suffix + 'contactValidation');
        
        // Limit to 11 digits
        if (input.value.length > 11) {
            input.value = input.value.slice(0, 11);
        }
        
        if (!/^[0-9]{11}$/.test(input.value)) {
            validation.textContent = 'Must be exactly 11 digits';
            validation.className = 'validation-message invalid';
        } else {
            validation.textContent = 'Contact number is valid';
            validation.className = 'validation-message valid';
        }
    }

    function validateAddress(input) {
        const tab = input.closest('.tab-content').id;
        const suffix = tab === 'client-tab' ? '' : 'walkin';
        const validation = document.getElementById(suffix + 'addressValidation');
        if (input.value.length < 5) {
            validation.textContent = 'Address should be more specific';
            validation.className = 'validation-message invalid';
        } else {
            validation.textContent = 'Address looks good!';
            validation.className = 'validation-message valid';
        }
    }

    function validateHeight(input) {
        const tab = input.closest('.tab-content').id;
        const suffix = tab === 'client-tab' ? '' : 'walkin';
        const validation = document.getElementById(suffix + 'heightValidation');
        const height = parseFloat(input.value);
        if (height < 100 || height > 250) {
            validation.textContent = 'Height must be between 100cm and 250cm';
            validation.className = 'validation-message invalid';
        } else {
            validation.textContent = 'Height is valid';
            validation.className = 'validation-message valid';
        }
    }

    function validateWeight(input) {
        const tab = input.closest('.tab-content').id;
        const suffix = tab === 'client-tab' ? '' : 'walkin';
        const validation = document.getElementById(suffix + 'weightValidation');
        const weight = parseFloat(input.value);
        if (weight < 30 || weight > 300) {
            validation.textContent = 'Weight must be between 30kg and 300kg';
            validation.className = 'validation-message invalid';
        } else {
            validation.textContent = 'Weight is valid';
            validation.className = 'validation-message valid';
        }
    }

    function validateUsername(input) {
        const validation = document.getElementById('usernameValidation');
        if (input.value.length < 3) {
            validation.textContent = 'Username must be at least 3 characters';
            validation.className = 'validation-message invalid';
        } else if (!/^[a-zA-Z0-9_]+$/.test(input.value)) {
            validation.textContent = 'Username can only contain letters, numbers, and underscores';
            validation.className = 'validation-message invalid';
        } else {
            validation.textContent = 'Username format is valid';
            validation.className = 'validation-message valid';
        }
    }

    function validateEmail(input) {
        const validation = document.getElementById('emailValidation');
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(input.value)) {
            validation.textContent = 'Please enter a valid email address';
            validation.className = 'validation-message invalid';
        } else {
            validation.textContent = 'Email format is valid';
            validation.className = 'validation-message valid';
        }
    }

    function validatePassword(input) {
        const validation = document.getElementById('passwordValidation');
        if (input.value.length < 6) {
            validation.textContent = 'Password must be at least 6 characters';
            validation.className = 'validation-message invalid';
        } else {
            validation.textContent = 'Password is valid';
            validation.className = 'validation-message valid';
        }
    }

    function validateConfirmPassword(input) {
        const password = document.querySelector('input[name="password"]').value;
        const validation = document.getElementById('confirmPasswordValidation');
        if (input.value !== password) {
            validation.textContent = 'Passwords do not match';
            validation.className = 'validation-message invalid';
        } else {
            validation.textContent = 'Passwords match';
            validation.className = 'validation-message valid';
        }
    }

    // Duplicate checking function
    function checkDuplicate(field, value) {
        if (!value || value.length < 3) return;
        
        const indicator = document.getElementById(field + 'CheckIndicator');
        const message = document.getElementById(field + 'Availability');
        
        indicator.className = 'check-indicator checking';
        indicator.innerHTML = '<i data-lucide="loader-2" class="w-3 h-3 animate-spin"></i>';
        
        fetch(`?action=check_duplicate&field=${field}&value=${encodeURIComponent(value)}`)
            .then(response => response.json())
            .then(data => {
                if (data.available) {
                    indicator.className = 'check-indicator available';
                    indicator.innerHTML = '<i data-lucide="check" class="w-3 h-3"></i>';
                    message.textContent = data.message;
                    message.className = 'availability-message available';
                } else {
                    indicator.className = 'check-indicator taken';
                    indicator.innerHTML = '<i data-lucide="x" class="w-3 h-3"></i>';
                    message.textContent = data.message;
                    message.className = 'availability-message taken';
                }
            })
            .catch(error => {
                console.error('Error checking duplicate:', error);
                indicator.className = 'check-indicator';
                message.textContent = 'Error checking availability';
                message.className = 'availability-message taken';
            });
    }

    // Generate username function
    function generateUsername(type) {
        const fullName = document.querySelector(`#${type}-tab input[name="full_name"]`).value;
        if (!fullName) {
            alert('Please enter full name first');
            return;
        }

        const usernameInput = document.querySelector(`#${type}-tab input[name="username"]`);
        let baseUsername;
        
        if (type === 'client') {
            baseUsername = fullName.toLowerCase().replace(/[^a-z0-9]/g, '').substring(0, 15);
        } else {
            baseUsername = 'walkin_' + fullName.toLowerCase().replace(/[^a-z0-9]/g, '').substring(0, 8);
        }
        
        const timestamp = Date.now().toString().slice(-4);
        const generatedUsername = baseUsername + timestamp;
        
        usernameInput.value = generatedUsername;
        validateUsername(usernameInput);
        checkDuplicate('username', generatedUsername);
    }

    // Reset form function
    function resetForm(type) {
        const form = document.getElementById(type + 'Form');
        const validationMessages = form.querySelectorAll('.validation-message');
        const availabilityMessages = form.querySelectorAll('.availability-message');
        const checkIndicators = form.querySelectorAll('.check-indicator');
        
        validationMessages.forEach(el => {
            el.textContent = '';
            el.className = 'validation-message';
        });
        
        availabilityMessages.forEach(el => {
            el.textContent = '';
            el.className = 'availability-message';
        });
        
        checkIndicators.forEach(el => {
            el.className = 'check-indicator';
        });
        
        // Reset expiry date
        if (type === 'client') {
            document.getElementById('expiryDate').value = '';
        } else {
            document.getElementById('walkinExpiryDate').value = '';
        }
    }

    // Additional utility functions for modal
    function printMemberInfo() {
        window.print();
    }

    function downloadQRCode() {
        const qrCodePath = '<?php echo $success_data['qr_code_path'] ?? ''; ?>';
        if (qrCodePath) {
            const link = document.createElement('a');
            link.href = qrCodePath;
            link.download = 'member_qr_code.png';
            link.click();
        }
    }

    // Enhanced input restrictions
    document.addEventListener('DOMContentLoaded', function() {
        // Contact number input restriction
        const contactInputs = document.querySelectorAll('input[name="contact_number"]');
        contactInputs.forEach(input => {
            input.addEventListener('input', function(e) {
                this.value = this.value.replace(/[^0-9]/g, '');
                if (this.value.length > 11) {
                    this.value = this.value.slice(0, 11);
                }
            });
            
            input.addEventListener('keypress', function(e) {
                if (!/[0-9]/.test(e.key)) {
                    e.preventDefault();
                }
            });
        });

        // Height and weight input restrictions
        const heightInputs = document.querySelectorAll('input[name="height"]');
        heightInputs.forEach(input => {
            input.addEventListener('input', function(e) {
                let value = parseFloat(this.value);
                if (value < 100) this.value = 100;
                if (value > 250) this.value = 250;
            });
        });

        const weightInputs = document.querySelectorAll('input[name="weight"]');
        weightInputs.forEach(input => {
            input.addEventListener('input', function(e) {
                let value = parseFloat(this.value);
                if (value < 30) this.value = 30;
                if (value > 300) this.value = 300;
            });
        });

        // Age input restriction
        const ageInputs = document.querySelectorAll('input[name="age"]');
        ageInputs.forEach(input => {
            input.addEventListener('input', function(e) {
                let value = parseInt(this.value);
                if (value < 16) this.value = 16;
                if (value > 100) this.value = 100;
            });
        });
    });

    // Fix notification badge update
    document.addEventListener('click', function(e) {
        if (e.target.closest('.notification-item')) {
            const notificationItem = e.target.closest('.notification-item');
            const notificationId = notificationItem.dataset.notificationId;
            
            // Mark as read via AJAX
            fetch('mark_notification_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `notification_id=${notificationId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update badge count
                    const badge = document.getElementById('notificationBadge');
                    let currentCount = parseInt(badge.textContent);
                    if (currentCount > 1) {
                        badge.textContent = currentCount - 1;
                    } else {
                        badge.classList.add('hidden');
                    }
                }
            })
            .catch(error => console.error('Error marking notification as read:', error));
        }
        
        // Mark all as read
        if (e.target.id === 'markAllRead') {
            fetch('mark_all_notifications_read.php', {
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('notificationBadge').classList.add('hidden');
                }
            })
            .catch(error => console.error('Error marking all notifications as read:', error));
        }
    });
  </script>
<?php require_once 'includes/trainer_footer.php'; ?>
<?php if(isset($conn)) { $conn->close(); } ?>
