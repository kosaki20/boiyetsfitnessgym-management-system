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
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $username = trim($_POST['username']);

    // Validate inputs
    if (empty($full_name) || empty($email) || empty($username)) {
        $message = "All fields are required!";
        $message_type = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address!";
        $message_type = "error";
    } else {
        // Check if username or email already exists (excluding current user)
        $check_sql = "SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ssi", $username, $email, $user_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();

        if ($result->num_rows > 0) {
            $message = "Username or email already exists!";
            $message_type = "error";
        } else {
            // Handle profile picture upload
            $profile_picture = $user['profile_picture'] ?? ''; // Keep existing picture by default
            
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'profile_pictures/';
                
                // Create directory if it doesn't exist
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_tmp = $_FILES['profile_picture']['tmp_name'];
                $file_name = time() . '_' . basename($_FILES['profile_picture']['name']);
                $file_path = $upload_dir . $file_name;
                
                // Validate file type
                $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                $file_type = mime_content_type($file_tmp);
                
                if (in_array($file_type, $allowed_types)) {
                    // Validate file size (max 5MB)
                    if ($_FILES['profile_picture']['size'] <= 5 * 1024 * 1024) {
                        if (move_uploaded_file($file_tmp, $file_path)) {
                            // Delete old profile picture if it exists and is not the default
                            if (!empty($user['profile_picture']) && 
                                $user['profile_picture'] != 'https://i.pravatar.cc/120' &&
                                file_exists($user['profile_picture'])) {
                                unlink($user['profile_picture']);
                            }
                            $profile_picture = $file_path;
                        } else {
                            $message = "Failed to upload profile picture.";
                            $message_type = "error";
                        }
                    } else {
                        $message = "Profile picture must be less than 5MB.";
                        $message_type = "error";
                    }
                } else {
                    $message = "Only JPG, PNG, and GIF files are allowed.";
                    $message_type = "error";
                }
            }
            
            // Only proceed with update if no file upload errors
            if ($message_type !== 'error') {
                // Update user profile
                $update_sql = "UPDATE users SET full_name = ?, email = ?, username = ?, profile_picture = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ssssi", $full_name, $email, $username, $profile_picture, $user_id);

                if ($update_stmt->execute()) {
                    // Update session variables
                    $_SESSION['full_name'] = $full_name;
                    $_SESSION['username'] = $username;
                    $_SESSION['profile_picture'] = $profile_picture;
                    
                    $message = "Profile updated successfully!";
                    $message_type = "success";
                    
                    // Refresh user data
                    $user['full_name'] = $full_name;
                    $user['email'] = $email;
                    $user['username'] = $username;
                    $user['profile_picture'] = $profile_picture;
                } else {
                    $message = "Error updating profile. Please try again.";
                    $message_type = "error";
                }
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
    $page_title = 'Edit Profile';
    require_once 'includes/admin_header.php';
} elseif ($role == 'trainer') {
    $page_title = 'Edit Profile';
    require_once 'includes/trainer_header.php';
} else {
    $page_title = 'Edit Profile';
    require_once 'includes/client_header.php';
}
?>
<style>
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
    
    .profile-picture-container {
        position: relative;
        display: inline-block;
    }
    
    .profile-picture {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        object-fit: cover;
        border: 4px solid #fbbf24;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .profile-picture:hover {
        opacity: 0.8;
    }
    
    .profile-picture-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.7);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity 0.3s ease;
        cursor: pointer;
    }
    
    .profile-picture-container:hover .profile-picture-overlay {
        opacity: 1;
    }
    
    .file-input {
        display: none;
    }
    
    .file-info {
        margin-top: 0.5rem;
        font-size: 0.8rem;
        color: #9ca3af;
    }
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
<script>
    function previewImage(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('profilePicturePreview').src = e.target.result;
            }
            reader.readAsDataURL(input.files[0]);
        }
    }
</script>

    <!-- Main Content -->
    <main class="flex-1 p-6 overflow-auto">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-yellow-400 flex items-center gap-2">
                <i data-lucide="edit-2"></i>
                Edit Profile
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

            <form method="POST" action="" enctype="multipart/form-data">
                <!-- Profile Picture Section -->
                <div class="form-group text-center mb-8">
                    <label class="form-label block text-center mb-4">
                        <i data-lucide="camera" class="w-4 h-4 inline mr-2"></i>
                        Profile Picture
                    </label>
                    <div class="profile-picture-container mx-auto">
                        <img src="<?php echo htmlspecialchars($user['profile_picture'] ?? 'https://i.pravatar.cc/120'); ?>" 
                             alt="Profile Picture" 
                             class="profile-picture"
                             id="profilePicturePreview">
                        <div class="profile-picture-overlay" onclick="document.getElementById('profile_picture').click()">
                            <i data-lucide="camera" class="w-6 h-6 text-white"></i>
                        </div>
                    </div>
                    <input type="file" 
                           id="profile_picture" 
                           name="profile_picture" 
                           class="file-input" 
                           accept="image/jpeg,image/jpg,image/png,image/gif"
                           onchange="previewImage(this)">
                    <div class="file-info">
                        Click on the picture to change it. Max size: 5MB (JPG, PNG, GIF)
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="full_name">
                        <i data-lucide="user" class="w-4 h-4 inline mr-2"></i>
                        Full Name
                    </label>
                    <input type="text" id="full_name" name="full_name" class="form-input" 
                           value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="email">
                        <i data-lucide="mail" class="w-4 h-4 inline mr-2"></i>
                        Email Address
                    </label>
                    <input type="email" id="email" name="email" class="form-input" 
                           value="<?php echo htmlspecialchars($user['email']); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="username">
                        <i data-lucide="at-sign" class="w-4 h-4 inline mr-2"></i>
                        Username
                    </label>
                    <input type="text" id="username" name="username" class="form-input" 
                           value="<?php echo htmlspecialchars($user['username']); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        <i data-lucide="shield" class="w-4 h-4 inline mr-2"></i>
                        Account Role
                    </label>
                    <div class="form-input bg-opacity-50 cursor-not-allowed">
                        <?php echo ucfirst($user['role']); ?>
                    </div>
                    <p class="text-gray-400 text-sm mt-1">Account role cannot be changed</p>
                </div>

                <div class="flex gap-4 mt-8 pt-6 border-t border-gray-700">
                    <button type="submit" class="btn btn-primary">
                        <i data-lucide="save" class="w-4 h-4"></i>
                        Save Changes
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

<?php
if ($role == 'admin') {
    require_once 'includes/admin_footer.php';
} elseif ($role == 'trainer') {
    require_once 'includes/trainer_footer.php';
} else {
    require_once 'includes/client_footer.php';
}
?>




