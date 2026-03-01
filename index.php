<?php
session_start();

require_once 'includes/db_connection.php';
// Handle login form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    // Basic validation
    if (empty($username) || empty($password)) {
        $error = "Please fill in all fields";
    } else {
        // Query to check user credentials using prepared statement
        $stmt = $conn->prepare("SELECT u.*, m.member_type, u.client_type FROM users u LEFT JOIN members m ON u.id = m.user_id WHERE u.username = ? AND u.deleted_at IS NULL");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            
            // Verify password - handle both bcrypt and MD5 with migration
            if (password_verify($password, $user['password'])) {
                // Login successful - bcrypt password
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['client_type'] = $user['client_type'] ?? 'walk-in';
                
                // Redirect based on role and client type
                switch($user['role']) {
                    case 'admin':
                        header("Location: admin_dashboard.php");
                        break;
                    case 'trainer':
                        header("Location: trainer_dashboard.php");
                        break;
                    case 'client':
                        // Check if it's a walk-in client
                        if (($user['client_type'] === 'walk-in') || ($user['member_type'] === 'walk-in')) {
                            header("Location: walkin_dashboard.php");
                        } else {
                            header("Location: client_dashboard.php");
                        }
                        break;
                    default:
                        header("Location: index.php");
                }
                exit();
            } elseif (md5($password) === $user['password']) {
                // Login successful - MD5 password, upgrade to bcrypt
                $new_hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $update_stmt->bind_param("si", $new_hashed_password, $user['id']);
                
                if ($update_stmt->execute()) {
                    error_log("Password migrated from MD5 to bcrypt for user: " . $user['username']);
                } else {
                    error_log("Failed to migrate password for user: " . $user['username']);
                }
                
                // Set session variables
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['client_type'] = $user['client_type'] ?? 'walk-in';
                
                // Redirect based on role and client type
                switch($user['role']) {
                    case 'admin':
                        header("Location: admin_dashboard.php");
                        break;
                    case 'trainer':
                        header("Location: trainer_dashboard.php");
                        break;
                    case 'client':
                        // Check if it's a walk-in client
                        if (($user['client_type'] === 'walk-in') || ($user['member_type'] === 'walk-in')) {
                            header("Location: walkin_dashboard.php");
                        } else {
                            header("Location: client_dashboard.php");
                        }
                        break;
                    default:
                        header("Location: index.php");
                }
                exit();
            } else {
                $error = "Invalid username or password";
            }
        } else {
            $error = "Invalid username or password";
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
    <title>Login - Boiyets Fitness Gym</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Your existing CSS styles here */
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
            transition: color 0.3s ease;
        }
        
        .password-toggle:hover {
            color: var(--gold);
        }
        
        .btn-login {
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
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(251, 191, 36, 0.4);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .form-links {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .forgot-password a, .signup-link a {
            color: #94a3b8;
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .forgot-password a:hover, .signup-link a:hover {
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
            
            .form-links {
                flex-direction: column;
                gap: 15px;
                text-align: center;
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
                <h3>Welcome Back</h3>
                <p>Sign in to your account</p>
            </div>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-error" id="errorAlert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <form id="loginForm" action="index.php" method="POST">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-control" 
                           placeholder="Enter your username" required 
                           value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-input">
                        <input type="password" id="password" name="password" class="form-control" 
                               placeholder="Enter your password" required>
                        <button type="button" class="password-toggle" id="passwordToggle">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i>
                    <span>Login to Account</span>
                </button>
                
                <div class="form-links">
                    <div class="forgot-password">
                        <a href="forgotpassword.php">
                            <i class="fas fa-lock"></i>
                            Forgot Password?
                        </a>
                    </div>
                    <div class="signup-link">
                        <a href="signup.php">
                            <i class="fas fa-user-plus"></i>
                            Create Account
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
            
            passwordToggle.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                const icon = this.querySelector('i');
                icon.className = type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
            });

            // Form submission handling
            document.getElementById('loginForm').addEventListener('submit', function(e) {
                const username = document.getElementById('username').value.trim();
                const password = document.getElementById('password').value.trim();
                
                if (!username || !password) {
                    e.preventDefault();
                    showError('Please fill in all fields');
                    return false;
                }
                
                const btn = this.querySelector('.btn-login');
                const btnText = btn.querySelector('span');
                btnText.innerHTML = '<span class="loading-spinner" style="color: #1a1a1a;"></span> Logging in...';
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
                
                document.querySelector('.login-header').parentNode.insertBefore(alertDiv, document.querySelector('.login-header').nextSibling);
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
        });
    </script>
</body>
</html>



