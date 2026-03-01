<?php
session_start();

// Database connection
$servername = "localhost";
$username = "root";  // full Hostinger DB username
$password = "";           // your Hostinger DB password
$dbname = "boiyetsdb";         // full Hostinger DB name

$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$message = '';
$error = '';

// Handle password reset request
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    
    // Basic validation
    if (empty($email)) {
        $error = "Please enter your email address";
    } else {
        // Check if user exists with this email
        $stmt = $conn->prepare("SELECT id, username, full_name, role FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            
            // Generate secure reset token
            $reset_token = bin2hex(random_bytes(32));
            
            // Use MySQL's date function to avoid timezone issues
            $expiry_result = $conn->query("SELECT DATE_ADD(NOW(), INTERVAL 1 HOUR) as expiry");
            $expiry_row = $expiry_result->fetch_assoc();
            $expiry = $expiry_row['expiry'];
            
            // Store reset token and expiry
            $update_stmt = $conn->prepare("UPDATE users SET reset_token = ?, reset_expiry = ? WHERE id = ?");
            $update_stmt->bind_param("ssi", $reset_token, $expiry, $user['id']);
            
            if ($update_stmt->execute()) {
                // In a real application, you would send an email with the reset link
                // For demo purposes, we'll show the reset link on screen
                $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/resetpassword.php?token=" . $reset_token;
                
                $message = "Password reset link generated!<br><br>
                           <strong>Reset Link:</strong> <a href='{$reset_link}' style='color: #fbbf24; text-decoration: underline;'>{$reset_link}</a><br><br>
                           This link will expire in 1 hour.";
            } else {
                $error = "Error generating reset link. Please try again.";
            }
            $update_stmt->close();
        } else {
            $error = "No account found with this email address.";
        }
        $stmt->close();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Boiyets Fitness Gym</title>
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
        
        .reset-link-box {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
        }
        
        .reset-link-box a {
            color: var(--gold);
            text-decoration: none;
            word-break: break-all;
            display: block;
            margin: 10px 0;
            padding: 10px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        
        .reset-link-box a:hover {
            background: rgba(255, 255, 255, 0.1);
            text-decoration: underline;
        }
        
        .demo-accounts {
            background: rgba(251, 191, 36, 0.1);
            border: 1px solid rgba(251, 191, 36, 0.3);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
        }
        
        .demo-accounts h4 {
            color: var(--gold);
            margin-bottom: 12px;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .demo-accounts h4 i {
            font-size: 1.1rem;
        }
        
        .demo-accounts ul {
            list-style: none;
            text-align: left;
        }
        
        .demo-accounts li {
            margin-bottom: 8px;
            padding-left: 20px;
            position: relative;
            color: #cbd5e1;
            font-size: 0.9rem;
        }
        
        .demo-accounts li:before {
            content: "•";
            color: var(--gold);
            position: absolute;
            left: 8px;
            font-size: 1.2rem;
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
        
        @media (max-width: 768px) {
            .container {
                max-width: 450px;
            }
            
            .login-box {
                padding: 30px 25px;
            }
            
            .logo h1 {
                font-size: 2.5rem;
            }
            
            .logo h2 {
                font-size: 1.1rem;
            }
        }
        
        @media (max-width: 480px) {
            body {
                padding: 15px;
            }
            
            .container {
                max-width: 100%;
            }
            
            .login-box {
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
            
            .demo-accounts {
                padding: 15px;
            }
            
            .demo-accounts li {
                font-size: 0.85rem;
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
                <p>Enter your email to get a reset link</p>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-error" id="errorAlert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($message)): ?>
                <div class="alert alert-success" id="successAlert">
                    <i class="fas fa-check-circle"></i>
                    <span>Password reset link generated successfully!</span>
                </div>
                
                <div class="reset-link-box">
                    <p><strong>Reset Link (Click to test):</strong></p>
                    <?php
                    // Extract the reset link from the message
                    preg_match('/href=\'([^\']+)\'/', $message, $matches);
                    $reset_link = $matches[1] ?? '';
                    ?>
                    <a href="<?php echo htmlspecialchars($reset_link); ?>" target="_blank">
                        <i class="fas fa-external-link-alt"></i>
                        <?php echo htmlspecialchars($reset_link); ?>
                    </a>
                    <p style="color: #94a3b8; font-size: 0.9rem; margin-top: 10px;">
                        <i class="fas fa-clock"></i> This link will expire in 1 hour
                    </p>
                </div>
                
                <div class="demo-accounts">
                    <h4><i class="fas fa-info-circle"></i> Demo Information</h4>
                    <p style="color: #cbd5e1; font-size: 0.9rem; text-align: center;">
                        In a production environment, this reset link would be sent to your email address automatically.
                    </p>
                </div>
            <?php endif; ?>
            
         
            
            <?php if (empty($message)): ?>
            <form id="resetForm" action="forgotpassword.php" method="POST">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" 
                           placeholder="Enter your registered email" required 
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
                
                <button type="submit" class="btn-reset">
                    <i class="fas fa-key"></i>
                    <span>Get Reset Link</span>
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
        // Form submission handling
        document.addEventListener('DOMContentLoaded', function() {
            const resetForm = document.getElementById('resetForm');
            if (resetForm) {
                resetForm.addEventListener('submit', function(e) {
                    const email = document.getElementById('email').value.trim();
                    
                    if (!email) {
                        e.preventDefault();
                        showError('Please enter your email address');
                        return false;
                    }
                    
                    // Validate email format
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(email)) {
                        e.preventDefault();
                        showError('Please enter a valid email address');
                        return false;
                    }
                    
                    // Show loading state
                    const btn = this.querySelector('.btn-reset');
                    const btnText = btn.querySelector('span');
                    btnText.innerHTML = '<span class="loading-spinner" style="color: #1a1a1a;"></span> Generating Reset Link...';
                    btn.disabled = true;
                });
            }

            // Error message function
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
                
                // Auto-hide after 5 seconds
                setTimeout(() => {
                    alertDiv.style.opacity = '0';
                    setTimeout(() => {
                        if (alertDiv.parentNode) {
                            alertDiv.parentNode.removeChild(alertDiv);
                        }
                    }, 300);
                }, 5000);
            }

            // Auto-hide messages after 8 seconds
            setTimeout(() => {
                const errorAlert = document.getElementById('errorAlert');
                const successAlert = document.getElementById('successAlert');
                
                if (errorAlert) {
                    errorAlert.style.opacity = '0';
                    setTimeout(() => {
                        if (errorAlert.parentNode) {
                            errorAlert.parentNode.removeChild(errorAlert);
                        }
                    }, 300);
                }
                
                if (successAlert) {
                    successAlert.style.opacity = '0';
                    setTimeout(() => {
                        if (successAlert.parentNode) {
                            successAlert.parentNode.removeChild(successAlert);
                        }
                    }, 300);
                }
            }, 8000);

            // Preserve form values on page refresh
            const email = document.getElementById('email');
            if (email) {
                const savedEmail = sessionStorage.getItem('resetEmail');
                if (savedEmail) {
                    email.value = savedEmail;
                }
                
                email.addEventListener('input', function() {
                    sessionStorage.setItem('resetEmail', this.value);
                });
            }

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
        });
    </script>
</body>
</html>



