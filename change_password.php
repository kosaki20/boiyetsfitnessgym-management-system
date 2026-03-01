<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';
// Get user data
$user_id = $_SESSION['user_id'];
$sql = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // Validate inputs
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $message = "All fields are required!";
        $message_type = "error";
    } elseif ($new_password !== $confirm_password) {
        $message = "New passwords do not match!";
        $message_type = "error";
    } elseif (strlen($new_password) < 6) {
        $message = "New password must be at least 6 characters long!";
        $message_type = "error";
    } else {
        // Verify current password
        $current_password_md5 = md5($current_password);
        if ($current_password_md5 !== $user['password']) {
            $message = "Current password is incorrect!";
            $message_type = "error";
        } else {
            // Update password
            $new_password_md5 = md5($new_password);
            $update_sql = "UPDATE users SET password = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("si", $new_password_md5, $user_id);

            if ($update_stmt->execute()) {
                $message = "Password changed successfully!";
                $message_type = "success";
                
                // Clear form fields
                $_POST = array();
            } else {
                $message = "Error changing password. Please try again.";
                $message_type = "error";
            }
        }
    }
}

// Include chat functionality
require_once 'chat_functions.php';
$unread_count = getUnreadCount($_SESSION['user_id'], $conn);

$conn->close();
?>

<?php
$role = $_SESSION['role'];
if ($role == 'admin') {
    $page_title = 'Change Password';
    require_once 'includes/admin_header.php';
} elseif ($role == 'trainer') {
    $page_title = 'Change Password';
    require_once 'includes/trainer_header.php';
} else {
    $page_title = 'Change Password';
    require_once 'includes/client_header.php';
}
?>
<style>
    .card {
        background: rgba(26, 26, 26, 0.7);
        border-radius: 12px;
        padding: 1.5rem;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.05);
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        transition: all 0.2s ease;
    }
    
    .card:hover {
        box-shadow: 0 10px 15px rgba(0, 0, 0, 0.2);
        transform: translateY(-2px);
    }
    
    .form-group {
        margin-bottom: 1.5rem;
    }
    
    .form-label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 500;
        color: #fbbf24;
    }
    
    .form-input {
        width: 100%;
        padding: 0.75rem 1rem;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        color: #fff;
        font-size: 0.9rem;
        transition: all 0.2s ease;
    }
    
    .form-input:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.1);
    }
    
    .alert {
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .alert-success {
        background: rgba(16, 185, 129, 0.2);
        color: #10b981;
        border: 1px solid rgba(16, 185, 129, 0.3);
    }
    
    .alert-error {
        background: rgba(239, 68, 68, 0.2);
        color: #ef4444;
        border: 1px solid rgba(239, 68, 68, 0.3);
    }
    
    .password-strength {
        margin-top: 0.5rem;
        font-size: 0.8rem;
    }
    
    .strength-weak { color: #ef4444; }
    .strength-medium { color: #f59e0b; }
    .strength-strong { color: #10b981; }
</style>
    </style>
</head>
<body class="min-h-screen">

<?php
if ($role == 'admin') {
    require_once 'includes/admin_sidebar.php';
} elseif ($role == 'trainer') {
    require_once 'includes/trainer_sidebar.php';
}
// Client sidebar is already included in client_header.php
?>

    <!-- Main Content -->
    <main class="flex-1 p-6 overflow-auto">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-yellow-400 flex items-center gap-2">
                <i data-lucide="key"></i>
                Change Password
            </h1>
            <a href="profile.php" class="btn btn-secondary">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>
                Back to Profile
            </a>
        </div>

        <div class="card max-w-2xl">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <i data-lucide="<?php echo $message_type == 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-5 h-5"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label" for="current_password">
                        <i data-lucide="lock" class="w-4 h-4 inline mr-2"></i>
                        Current Password
                    </label>
                    <input type="password" id="current_password" name="current_password" class="form-input" 
                           value="<?php echo htmlspecialchars($_POST['current_password'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="new_password">
                        <i data-lucide="lock" class="w-4 h-4 inline mr-2"></i>
                        New Password
                    </label>
                    <input type="password" id="new_password" name="new_password" class="form-input" 
                           value="<?php echo htmlspecialchars($_POST['new_password'] ?? ''); ?>" required>
                    <div id="passwordStrength" class="password-strength"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="confirm_password">
                        <i data-lucide="lock" class="w-4 h-4 inline mr-2"></i>
                        Confirm New Password
                    </label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-input" 
                           value="<?php echo htmlspecialchars($_POST['confirm_password'] ?? ''); ?>" required>
                    <div id="passwordMatch" class="password-strength"></div>
                </div>

                <div class="bg-yellow-400/10 border border-yellow-400/30 rounded-lg p-4 mb-6">
                    <div class="flex items-start gap-3">
                        <i data-lucide="shield" class="w-5 h-5 text-yellow-400 mt-0.5 flex-shrink-0"></i>
                        <div>
                            <h3 class="text-yellow-400 font-semibold mb-2">Password Requirements</h3>
                            <ul class="text-sm text-gray-300 space-y-1">
                                <li>• At least 6 characters long</li>
                                <li>• Should not be the same as your current password</li>
                                <li>• Use a combination of letters, numbers, and symbols for better security</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="flex gap-4 mt-8 pt-6 border-t border-gray-700">
                    <button type="submit" class="btn btn-primary">
                        <i data-lucide="key" class="w-4 h-4"></i>
                        Change Password
                    </button>
                    <a href="profile.php" class="btn btn-secondary">
                        <i data-lucide="x" class="w-4 h-4"></i>
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </main>
  </div>

  <script>
    // Password strength checker
    function checkPasswordStrength(password) {
        let strength = 0;
        const strengthText = document.getElementById('passwordStrength');
        if (!strengthText) return;
        
        if (password.length >= 6) strength++;
        if (password.match(/[a-z]+/)) strength++;
        if (password.match(/[A-Z]+/)) strength++;
        if (password.match(/[0-9]+/)) strength++;
        if (password.match(/[!@#$%^&*(),.?":{}|<>]+/)) strength++;
        
        let text = '';
        let className = '';
        
        switch(strength) {
            case 0:
            case 1:
                text = 'Weak password';
                className = 'strength-weak';
                break;
            case 2:
            case 3:
                text = 'Medium password';
                className = 'strength-medium';
                break;
            case 4:
            case 5:
                text = 'Strong password';
                className = 'strength-strong';
                break;
        }
        
        strengthText.textContent = text;
        strengthText.className = 'password-strength ' + className;
    }

    // Password match checker
    function checkPasswordMatch() {
        const password = document.getElementById('new_password').value;
        const confirmPassword = document.getElementById('confirm_password').value;
        const matchText = document.getElementById('passwordMatch');
        if (!matchText) return;
        
        if (confirmPassword === '') {
            matchText.textContent = '';
            return;
        }
        
        if (password === confirmPassword) {
            matchText.textContent = 'Passwords match';
            matchText.className = 'password-strength strength-strong';
        } else {
            matchText.textContent = 'Passwords do not match';
            matchText.className = 'password-strength strength-weak';
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const newPassInput = document.getElementById('new_password');
        const confirmPassInput = document.getElementById('confirm_password');
        
        if (newPassInput) {
            newPassInput.addEventListener('input', function() {
                checkPasswordStrength(this.value);
                checkPasswordMatch();
            });
        }
        
        if (confirmPassInput) {
            confirmPassInput.addEventListener('input', checkPasswordMatch);
        }
    });
  </script>
<?php
if ($role == 'admin') {
    require_once 'includes/admin_footer.php';
} elseif ($role == 'trainer') {
    require_once 'includes/trainer_footer.php';
} else {
    require_once 'includes/client_footer.php';
}
?>




