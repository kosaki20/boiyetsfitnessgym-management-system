<?php
session_start();

require_once 'includes/db_connection.php';
$message = '';
$error = '';
$show_form = false;
$valid_token = false;

// Check if token is provided
if (isset($_GET['token'])) {
    $token = trim($_GET['token']);
    
    // Validate token exists and is not expired
    $stmt = $conn->prepare("SELECT id, username, reset_expiry FROM users WHERE reset_token = ? AND reset_expiry > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        $valid_token = true;
        $show_form = true;
        
        // Handle password reset form submission
        if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['new_password'])) {
            $new_password = trim($_POST['new_password']);
            $confirm_password = trim($_POST['confirm_password']);
            
            if (empty($new_password) || empty($confirm_password)) {
                $error = "Please fill in all password fields";
            } elseif ($new_password !== $confirm_password) {
                $error = "Passwords do not match";
            } elseif (strlen($new_password) < 6) {
                $error = "Password must be at least 6 characters long";
            } else {
                // Hash the new password using MD5 (to match your existing format)
                $hashed_password = md5($new_password);
                
                // Update password and clear reset token
                $update_stmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expiry = NULL WHERE id = ?");
                $update_stmt->bind_param("si", $hashed_password, $user['id']);
                
                if ($update_stmt->execute()) {
                    $message = "Password reset successfully! You can now login with your new password.";
                    $show_form = false;
                    
                    // Auto-redirect to login after 3 seconds
                    header("Refresh: 3; url=index.php");
                } else {
                    $error = "Error resetting password. Please try again.";
                }
                $update_stmt->close();
            }
        }
    } else {
        // Check what's wrong with the token
        $debug_stmt = $conn->prepare("SELECT id, username, reset_token, reset_expiry FROM users WHERE reset_token = ?");
        $debug_stmt->bind_param("s", $token);
        $debug_stmt->execute();
        $debug_result = $debug_stmt->get_result();
        
        if ($debug_result->num_rows == 1) {
            $debug_user = $debug_result->fetch_assoc();
            $error = "Reset link has expired. Please request a new reset link.";
        } else {
            $error = "Invalid reset token. Please request a new reset link.";
        }
        $debug_stmt->close();
    }
    $stmt->close();
} else {
    $error = "No reset token provided.";
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Boiyets Fitness Gym</title>
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
            max-width: 450px;
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
        
        .login-box {
            background-color: rgba(255, 255, 255, 0.05);
            border-radius: 15px;
            padding: 40px 35px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            position: relative;
            overflow: hidden;
        }
        
        .login-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--gold), var(--gold-dark));
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header h3 {
            color: #ffffff;
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .login-header p {
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
        
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #6ee7b7;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            color: #cbd5e1;
            margin-bottom: 10px;
            font-size: 0.95rem;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 15px 20px;
            background-color: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 10px;
            color: #ffffff;
            font-size: 1rem;
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
        
        .btn-reset {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--gold), var(--gold-dark));
            color: #1a1a1a;
            border: none;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(251, 191, 36, 0.4);
        }
        
        .btn-reset:active {
            transform: translateY(0);
        }
        
        .back-to-login {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .back-to-login a {
            color: #94a3b8;
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        
        .back-to-login a:hover {
            color: var(--gold);
        }
        
        .password-input {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 5px;
        }
        
        .redirect-notice {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #3b82f6;
            padding: 10px;
            border-radius: 5px;
            margin-top: 15px;
            text-align: center;
            font-size: 0.9rem;
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
        
        .login-box {
            animation: fadeIn 0.6s ease;
        }
        
        /* Loading animation */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid transparent;
            border-top: 2px solid currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        @media (max-width: 480px) {
            .container {
                max-width: 100%;
            }
            
            .login-box {
                padding: 25px 20px;
            }
            
            .logo h1 {
                font-size: 2.2rem;
            }
            
            .logo h2 {
                font-size: 1rem;
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
        
        <div class="login-box">
            <div class="login-header">
                <h3>Reset Your Password</h3>
                <p>Enter your new password below</p>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-error" id="errorAlert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                    <?php if (strpos($error, 'Invalid') !== false || strpos($error, 'expired') !== false): ?>
                        <br>
                        <a href="forgotpassword.php" style="color: var(--gold); margin-top: 10px; display: inline-block;">
                            <i class="fas fa-redo"></i> Request New Reset Link
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($message)): ?>
                <div class="alert alert-success" id="successAlert">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo $message; ?></span>
                </div>
                <div class="redirect-notice">
                    <i class="fas fa-info-circle"></i>
                    You will be redirected to the login page in 3 seconds...
                </div>
            <?php endif; ?>
            
            <?php if ($show_form): ?>
            <form id="resetForm" action="resetpassword.php?token=<?php echo htmlspecialchars($_GET['token']); ?>" method="POST">
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <div class="password-input">
                        <input type="password" id="new_password" name="new_password" class="form-control" 
                               placeholder="Enter new password" required minlength="6">
                        <button type="button" class="password-toggle" id="passwordToggle">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <div class="password-input">
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" 
                               placeholder="Confirm new password" required minlength="6">
                        <button type="button" class="password-toggle" id="confirmPasswordToggle">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn-reset">
                    <i class="fas fa-key"></i>
                    <span>Reset Password</span>
                </button>
            </form>
            <?php endif; ?>
            
            <div class="back-to-login">
                <a href="index.php">
                    <i class="fas fa-arrow-left"></i>
                    Back to Login
                </a>
            </div>
        </div>
    </div>

    <script>
        // Password toggle functionality
        const passwordToggle = document.getElementById('passwordToggle');
        const passwordInput = document.getElementById('new_password');
        const confirmPasswordToggle = document.getElementById('confirmPasswordToggle');
        const confirmPasswordInput = document.getElementById('confirm_password');
        
        if (passwordToggle && passwordInput) {
            passwordToggle.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
            });
        }
        
        if (confirmPasswordToggle && confirmPasswordInput) {
            confirmPasswordToggle.addEventListener('click', function() {
                const type = confirmPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                confirmPasswordInput.setAttribute('type', type);
                this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
            });
        }

        // Form submission handling
        const resetForm = document.getElementById('resetForm');
        if (resetForm) {
            resetForm.addEventListener('submit', function(e) {
                const newPassword = document.getElementById('new_password').value;
                const confirmPassword = document.getElementById('confirm_password').value;
                
                if (newPassword.length < 6) {
                    e.preventDefault();
                    showError('Password must be at least 6 characters long');
                    return false;
                }
                
                if (newPassword !== confirmPassword) {
                    e.preventDefault();
                    showError('Passwords do not match');
                    return false;
                }
                
                // Show loading state
                const btn = this.querySelector('.btn-reset');
                const btnText = btn.querySelector('span');
                btnText.innerHTML = '<span class="loading-spinner" style="color: #1a1a1a;"></span> Resetting Password...';
                btn.disabled = true;
            });
        }

        function showError(message) {
            // Remove existing error alert
            const existingAlert = document.getElementById('errorAlert');
            if (existingAlert) {
                existingAlert.remove();
            }
            
            // Create new error alert
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-error';
            alertDiv.id = 'errorAlert';
            alertDiv.innerHTML = `
                <i class="fas fa-exclamation-circle"></i>
                <span>${message}</span>
            `;
            
            // Insert after login header
            const loginHeader = document.querySelector('.login-header');
            loginHeader.parentNode.insertBefore(alertDiv, loginHeader.nextSibling);
        }

        // Auto-hide success message after 5 seconds (if not redirecting)
        setTimeout(() => {
            const successAlert = document.getElementById('successAlert');
            if (successAlert && !window.location.href.includes('redirect')) {
                successAlert.style.opacity = '0';
                setTimeout(() => {
                    if (successAlert.parentNode) {
                        successAlert.parentNode.removeChild(successAlert);
                    }
                }, 300);
            }
        }, 5000);
    </script>
</body>
</html>



