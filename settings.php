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

// Get user settings or set defaults
$settings_sql = "SELECT * FROM user_settings WHERE user_id = ?";
$settings_stmt = $conn->prepare($settings_sql);
$settings_stmt->bind_param("i", $user_id);
$settings_stmt->execute();
$user_settings = $settings_stmt->get_result()->fetch_assoc();

// Default settings if none exist
if (!$user_settings) {
    $user_settings = [
        'email_notifications' => 1,
        'push_notifications' => 1,
        'newsletter' => 0,
        'theme' => 'dark',
        'language' => 'english',
        'timezone' => 'UTC',
        'privacy_level' => 'public',
        'activity_visibility' => 'all',
        'auto_logout' => 30
    ];
}

$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_preferences') {
        $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
        $push_notifications = isset($_POST['push_notifications']) ? 1 : 0;
        $newsletter = isset($_POST['newsletter']) ? 1 : 0;
        $theme = $_POST['theme'] ?? 'dark';
        $language = $_POST['language'] ?? 'english';
        $timezone = $_POST['timezone'] ?? 'UTC';
        
        // Check if settings already exist
        $check_sql = "SELECT id FROM user_settings WHERE user_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $user_id);
        $check_stmt->execute();
        $settings_exist = $check_stmt->get_result()->fetch_assoc();
        
        if ($settings_exist) {
            // Update existing settings
            $update_sql = "UPDATE user_settings SET email_notifications = ?, push_notifications = ?, newsletter = ?, theme = ?, language = ?, timezone = ?, updated_at = NOW() WHERE user_id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("iiisssi", $email_notifications, $push_notifications, $newsletter, $theme, $language, $timezone, $user_id);
        } else {
            // Insert new settings
            $update_sql = "INSERT INTO user_settings (user_id, email_notifications, push_notifications, newsletter, theme, language, timezone, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("iiissss", $user_id, $email_notifications, $push_notifications, $newsletter, $theme, $language, $timezone);
        }
        
        if ($update_stmt->execute()) {
            $message = "Preferences updated successfully!";
            $message_type = "success";
            
            // Update session theme if changed
            if ($theme !== ($_SESSION['theme'] ?? 'dark')) {
                $_SESSION['theme'] = $theme;
            }
            
            // Refresh settings
            $user_settings['email_notifications'] = $email_notifications;
            $user_settings['push_notifications'] = $push_notifications;
            $user_settings['newsletter'] = $newsletter;
            $user_settings['theme'] = $theme;
            $user_settings['language'] = $language;
            $user_settings['timezone'] = $timezone;
        } else {
            $message = "Error updating preferences. Please try again.";
            $message_type = "error";
        }
    }
    elseif ($action === 'update_privacy') {
        $privacy_level = $_POST['privacy_level'] ?? 'public';
        $activity_visibility = $_POST['activity_visibility'] ?? 'all';
        $auto_logout = $_POST['auto_logout'] ?? 30;
        
        // Check if settings already exist
        $check_sql = "SELECT id FROM user_settings WHERE user_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $user_id);
        $check_stmt->execute();
        $settings_exist = $check_stmt->get_result()->fetch_assoc();
        
        if ($settings_exist) {
            // Update existing settings
            $update_sql = "UPDATE user_settings SET privacy_level = ?, activity_visibility = ?, auto_logout = ?, updated_at = NOW() WHERE user_id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("ssii", $privacy_level, $activity_visibility, $auto_logout, $user_id);
        } else {
            // Insert new settings
            $update_sql = "INSERT INTO user_settings (user_id, privacy_level, activity_visibility, auto_logout, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("issi", $user_id, $privacy_level, $activity_visibility, $auto_logout);
        }
        
        if ($update_stmt->execute()) {
            $message = "Privacy settings updated successfully!";
            $message_type = "success";
            
            // Refresh settings
            $user_settings['privacy_level'] = $privacy_level;
            $user_settings['activity_visibility'] = $activity_visibility;
            $user_settings['auto_logout'] = $auto_logout;
        } else {
            $message = "Error updating privacy settings. Please try again.";
            $message_type = "error";
        }
    }
    elseif ($action === 'export_data') {
        // Generate data export (simplified version)
        $export_data = [
            'user_info' => [
                'username' => $user['username'],
                'email' => $user['email'],
                'full_name' => $user['full_name'],
                'role' => $user['role'],
                'member_since' => $user['created_at']
            ],
            'settings' => $user_settings,
            'export_date' => date('Y-m-d H:i:s')
        ];
        
        $json_data = json_encode($export_data, JSON_PRETTY_PRINT);
        $filename = "boiyets_gym_data_export_" . date('Y-m-d') . ".json";
        
        // Set headers for download
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $json_data;
        exit();
    }
    elseif ($action === 'delete_account') {
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($confirm_password)) {
            $message = "Please enter your password to confirm account deletion.";
            $message_type = "error";
        } else {
            // Verify password
            $confirm_password_md5 = md5($confirm_password);
            if ($confirm_password_md5 === $user['password']) {
                $message = "Account deletion feature would be implemented here. For security reasons, please contact admin for account deletion.";
                $message_type = "warning";
            } else {
                $message = "Incorrect password. Please try again.";
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
    $page_title = 'Settings';
    require_once 'includes/admin_header.php';
} elseif ($role == 'trainer') {
    $page_title = 'Settings';
    require_once 'includes/trainer_header.php';
} else {
    $page_title = 'Settings';
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
    
    .form-input, .form-select {
        width: 100%;
        padding: 0.75rem 1rem;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        color: #fff;
        font-size: 0.9rem;
        transition: all 0.2s ease;
    }
    
    .form-input:focus, .form-select:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.1);
    }
    
    .checkbox-container {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 1rem;
    }
    
    .checkbox-input {
        width: 18px;
        height: 18px;
        accent-color: #fbbf24;
    }
    
    .settings-section {
        margin-bottom: 2rem;
    }
    
    .section-title {
        font-size: 1.25rem;
        font-weight: 600;
        color: #fbbf24;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .danger-zone {
        border: 2px solid rgba(239, 68, 68, 0.3);
        background: rgba(239, 68, 68, 0.05);
    }
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
<main class="flex-1 p-6 overflow-auto">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-yellow-400 flex items-center gap-2">
                <i data-lucide="settings"></i>
                Settings
            </h1>
            <a href="profile.php" class="btn btn-secondary">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>
                Back to Profile
            </a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i data-lucide="<?php echo $message_type == 'success' ? 'check-circle' : ($message_type == 'warning' ? 'alert-triangle' : 'alert-circle'); ?>" class="w-5 h-5"></i>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Preferences Card -->
            <div class="card">
                <div class="settings-section">
                    <h2 class="section-title">
                        <i data-lucide="bell"></i>
                        Notification Preferences
                    </h2>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_preferences">
                        
                        <div class="checkbox-container">
                            <input type="checkbox" id="email_notifications" name="email_notifications" class="checkbox-input" 
                                   <?php echo ($user_settings['email_notifications'] ?? 1) ? 'checked' : ''; ?>>
                            <label for="email_notifications" class="checkbox-label">Email Notifications</label>
                        </div>
                        
                        <div class="checkbox-container">
                            <input type="checkbox" id="push_notifications" name="push_notifications" class="checkbox-input" 
                                   <?php echo ($user_settings['push_notifications'] ?? 1) ? 'checked' : ''; ?>>
                            <label for="push_notifications" class="checkbox-label">Push Notifications</label>
                        </div>
                        
                        <div class="checkbox-container">
                            <input type="checkbox" id="newsletter" name="newsletter" class="checkbox-input" 
                                   <?php echo ($user_settings['newsletter'] ?? 0) ? 'checked' : ''; ?>>
                            <label for="newsletter" class="checkbox-label">Gym Newsletter</label>
                        </div>
                    </div>

                    <div class="settings-section">
                        <h2 class="section-title">
                            <i data-lucide="palette"></i>
                            Appearance
                        </h2>
                        
                        <div class="form-group">
                            <label class="form-label" for="theme">Theme</label>
                            <select id="theme" name="theme" class="form-select">
                                <option value="dark" <?php echo ($user_settings['theme'] ?? 'dark') == 'dark' ? 'selected' : ''; ?>>Dark</option>
                                <option value="light" <?php echo ($user_settings['theme'] ?? 'dark') == 'light' ? 'selected' : ''; ?>>Light</option>
                                <option value="auto" <?php echo ($user_settings['theme'] ?? 'dark') == 'auto' ? 'selected' : ''; ?>>Auto</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="language">Language</label>
                            <select id="language" name="language" class="form-select">
                                <option value="english" <?php echo ($user_settings['language'] ?? 'english') == 'english' ? 'selected' : ''; ?>>English</option>
                                <option value="spanish" <?php echo ($user_settings['language'] ?? 'english') == 'spanish' ? 'selected' : ''; ?>>Spanish</option>
                                <option value="french" <?php echo ($user_settings['language'] ?? 'english') == 'french' ? 'selected' : ''; ?>>French</option>
                            </select>
                        </div>
                    </div>

                    <div class="settings-section">
                        <h2 class="section-title">
                            <i data-lucide="globe"></i>
                            Regional Settings
                        </h2>
                        
                        <div class="form-group">
                            <label class="form-label" for="timezone">Timezone</label>
                            <select id="timezone" name="timezone" class="form-select">
                                <option value="UTC" <?php echo ($user_settings['timezone'] ?? 'UTC') == 'UTC' ? 'selected' : ''; ?>>UTC</option>
                                <option value="EST" <?php echo ($user_settings['timezone'] ?? 'UTC') == 'EST' ? 'selected' : ''; ?>>Eastern Time (EST)</option>
                                <option value="PST" <?php echo ($user_settings['timezone'] ?? 'UTC') == 'PST' ? 'selected' : ''; ?>>Pacific Time (PST)</option>
                                <option value="CET" <?php echo ($user_settings['timezone'] ?? 'UTC') == 'CET' ? 'selected' : ''; ?>>Central European Time (CET)</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-full">
                        <i data-lucide="save" class="w-4 h-4"></i>
                        Save Preferences
                    </button>
                </form>
            </div>

            <!-- Privacy & Security Card -->
            <div class="card">
                <div class="settings-section">
                    <h2 class="section-title">
                        <i data-lucide="shield"></i>
                        Privacy & Security
                    </h2>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_privacy">
                        
                        <div class="form-group">
                            <label class="form-label" for="privacy_level">Privacy Level</label>
                            <select id="privacy_level" name="privacy_level" class="form-select">
                                <option value="public" <?php echo ($user_settings['privacy_level'] ?? 'public') == 'public' ? 'selected' : ''; ?>>Public</option>
                                <option value="friends" <?php echo ($user_settings['privacy_level'] ?? 'public') == 'friends' ? 'selected' : ''; ?>>Friends Only</option>
                                <option value="private" <?php echo ($user_settings['privacy_level'] ?? 'public') == 'private' ? 'selected' : ''; ?>>Private</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="activity_visibility">Activity Visibility</label>
                            <select id="activity_visibility" name="activity_visibility" class="form-select">
                                <option value="all" <?php echo ($user_settings['activity_visibility'] ?? 'all') == 'all' ? 'selected' : ''; ?>>Show All Activity</option>
                                <option value="workouts" <?php echo ($user_settings['activity_visibility'] ?? 'all') == 'workouts' ? 'selected' : ''; ?>>Workouts Only</option>
                                <option value="none" <?php echo ($user_settings['activity_visibility'] ?? 'all') == 'none' ? 'selected' : ''; ?>>Hide All Activity</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="auto_logout">Auto Logout (minutes)</label>
                            <select id="auto_logout" name="auto_logout" class="form-select">
                                <option value="15" <?php echo ($user_settings['auto_logout'] ?? 30) == 15 ? 'selected' : ''; ?>>15 minutes</option>
                                <option value="30" <?php echo ($user_settings['auto_logout'] ?? 30) == 30 ? 'selected' : ''; ?>>30 minutes</option>
                                <option value="60" <?php echo ($user_settings['auto_logout'] ?? 30) == 60 ? 'selected' : ''; ?>>1 hour</option>
                                <option value="120" <?php echo ($user_settings['auto_logout'] ?? 30) == 120 ? 'selected' : ''; ?>>2 hours</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn btn-primary w-full">
                            <i data-lucide="save" class="w-4 h-4"></i>
                            Update Privacy Settings
                        </button>
                    </form>
                </div>

                <!-- Data Management -->
                <div class="settings-section">
                    <h2 class="section-title">
                        <i data-lucide="database"></i>
                        Data Management
                    </h2>
                    
                    <form method="POST" action="" class="mb-4">
                        <input type="hidden" name="action" value="export_data">
                        <button type="submit" class="btn btn-secondary w-full">
                            <i data-lucide="download" class="w-4 h-4"></i>
                            Export My Data
                        </button>
                    </form>
                    
                    <p class="text-gray-400 text-sm mb-4">
                        Download a copy of your personal data including profile information, settings, and activity history.
                    </p>
                </div>

                <!-- Danger Zone -->
                <div class="settings-section danger-zone p-4 rounded-lg">
                    <h2 class="section-title danger-title">
                        <i data-lucide="alert-triangle"></i>
                        Danger Zone
                    </h2>
                    
                    <form method="POST" action="" id="deleteAccountForm">
                        <input type="hidden" name="action" value="delete_account">
                        
                        <div class="form-group">
                            <label class="form-label text-red-400" for="confirm_password">
                                Confirm Password to Delete Account
                            </label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-input" placeholder="Enter your password">
                            <p class="text-red-400 text-xs mt-1">
                                This action cannot be undone. All your data will be permanently deleted.
                            </p>
                        </div>
                        
                        <button type="submit" class="btn btn-danger w-full" onclick="return confirm('Are you sure you want to delete your account? This action cannot be undone.')">
                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                            Delete My Account
                        </button>
                    </form>
                </div>
            </div>
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



