<?php
session_start();

require_once 'includes/db_connection.php';
$token = $_GET['token'] ?? '';
$message = '';
$success = false;

if (!empty($token)) {
    // Check if token exists and is not expired
    $stmt = $conn->prepare("SELECT id, email, token_expiry FROM users WHERE verification_token = ? AND email_verified = 0");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $current_time = date('Y-m-d H:i:s');
        
        if ($current_time > $user['token_expiry']) {
            $message = "Verification link has expired. Please request a new one.";
        } else {
            // Update user as verified
            $update_stmt = $conn->prepare("UPDATE users SET email_verified = 1, verification_token = NULL, token_expiry = NULL WHERE id = ?");
            $update_stmt->bind_param("i", $user['id']);
            
            if ($update_stmt->execute()) {
                $message = "Email verified successfully! You can now login to your account.";
                $success = true;
            } else {
                $message = "Error verifying email. Please try again.";
            }
        }
    } else {
        $message = "Invalid or already used verification token.";
    }
} else {
    $message = "No verification token provided.";
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - Boiyets Fitness Gym</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            color: #ffffff;
        }
        
        .container {
            width: 100%;
            max-width: 500px;
            text-align: center;
        }
        
        .verification-card {
            background-color: rgba(255, 255, 255, 0.05);
            border-radius: 15px;
            padding: 40px 35px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            position: relative;
            overflow: hidden;
        }
        
        .verification-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, <?php echo $success ? '#10b981' : '#ef4444'; ?>, <?php echo $success ? '#059669' : '#dc2626'; ?>);
        }
        
        .icon {
            width: 80px;
            height: 80px;
            background: rgba(<?php echo $success ? '16, 185, 129' : '239, 68, 68'; ?>, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            border: 3px solid <?php echo $success ? '#10b981' : '#ef4444'; ?>;
        }
        
        .icon i {
            color: <?php echo $success ? '#10b981' : '#ef4444'; ?>;
            font-size: 2rem;
        }
        
        h1 {
            color: <?php echo $success ? '#10b981' : '#ef4444'; ?>;
            font-size: 2rem;
            margin-bottom: 20px;
        }
        
        p {
            color: #94a3b8;
            margin-bottom: 20px;
            line-height: 1.6;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: <?php echo $success ? '#10b981' : '#ef4444'; ?>;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            background: <?php echo $success ? '#059669' : '#dc2626'; ?>;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="verification-card">
            <div class="icon">
                <i class="fas <?php echo $success ? 'fa-check' : 'fa-times'; ?>"></i>
            </div>
            
            <h1><?php echo $success ? 'Verification Successful!' : 'Verification Failed'; ?></h1>
            
            <p><?php echo $message; ?></p>
            
            <?php if ($success): ?>
            <a href="index.php" class="btn">
                <i class="fas fa-sign-in-alt"></i>
                Proceed to Login
            </a>
            <?php else: ?>
            <a href="signup.php" class="btn">
                <i class="fas fa-user-plus"></i>
                Back to Registration
            </a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>



