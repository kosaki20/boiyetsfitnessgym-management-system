<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

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

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - BOIYETS FITNESS GYM</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        
        * {
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #111 0%, #0a0a0a 100%);
            color: #e2e8f0;
            min-height: 100vh;
        }
        
        .sidebar { 
            flex-shrink: 0; 
            transition: all 0.3s ease;
            overflow-y: auto;
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        .sidebar::-webkit-scrollbar {
            display: none;
        }
        
        .tooltip {
            position: absolute;
            left: 100%;
            top: 50%;
            transform: translateY(-50%) translateX(-10px);
            margin-left: 6px;
            background: rgba(0,0,0,0.9);
            color: #fff;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
            z-index: 50;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .sidebar-collapsed .sidebar-item .text-sm,
        .sidebar-collapsed .submenu { 
            display: none; 
        }
        
        .sidebar-collapsed .sidebar-item { 
            justify-content: center; 
            padding: 0.6rem;
        }
        
        .sidebar-collapsed .sidebar-item i { 
            margin: 0; 
        }
        
        .sidebar-collapsed .sidebar-item:hover .tooltip { 
            opacity: 1; 
            transform: translateY(-50%) translateX(0); 
        }

        .sidebar-item {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #9ca3af;
            padding: 0.6rem 0.8rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-bottom: 0.25rem;
        }
        
        .sidebar-item.active {
            color: #fbbf24;
            background: rgba(251, 191, 36, 0.12);
        }
        
        .sidebar-item.active::before {
            content: "";
            position: absolute;
            left: 0;
            top: 20%;
            height: 60%;
            width: 3px;
            background: #fbbf24;
            border-radius: 4px;
        }
        
        .sidebar-item:hover { 
            background: rgba(255,255,255,0.05); 
            color: #fbbf24; 
        }
        
        .sidebar-item i { 
            width: 18px; 
            height: 18px; 
            stroke-width: 1.75; 
            flex-shrink: 0; 
            margin-right: 0.75rem; 
        }

        .submenu {
            margin-left: 2.2rem;
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transition: max-height 0.3s ease, opacity 0.3s ease, margin-top 0.3s ease;
        }
        
        .submenu.open { 
            max-height: 500px; 
            opacity: 1; 
            margin-top: 0.25rem; 
        }
        
        .submenu a {
            display: flex;
            align-items: center;
            padding: 0.4rem 0.6rem;
            font-size: 0.8rem;
            color: #9ca3af;
            border-radius: 6px;
            transition: all 0.2s ease;
        }
        
        .submenu a i { 
            width: 14px; 
            height: 14px; 
            margin-right: 0.5rem; 
        }
        
        .submenu a:hover { 
            color: #fbbf24; 
            background: rgba(255,255,255,0.05); 
        }

        .chevron { 
            transition: transform 0.3s ease; 
        }
        
        .chevron.rotate { 
            transform: rotate(90deg); 
        }

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
        
        .form-select {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            color: #fff;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }
        
        .form-select:focus {
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
        
        .checkbox-label {
            color: #e2e8f0;
            font-size: 0.9rem;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
        }
        
        .btn-primary {
            background: rgba(251, 191, 36, 0.2);
            color: #fbbf24;
        }
        
        .btn-primary:hover {
            background: rgba(251, 191, 36, 0.3);
        }
        
        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            color: #e2e8f0;
        }
        
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        
        .btn-danger {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }
        
        .btn-danger:hover {
            background: rgba(239, 68, 68, 0.3);
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
        
        .alert-warning {
            background: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        
        .settings-section {
            margin-bottom: 2rem;
        }
        
        .settings-section:last-child {
            margin-bottom: 0;
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
        
        .danger-title {
            color: #ef4444;
        }
    </style>
</head>
<body class="min-h-screen">

  <!-- Topbar -->
  <header class="topbar flex items-center justify-between px-4 py-3 shadow">
    <div class="flex items-center space-x-3">
      <button id="toggleSidebar" class="text-gray-300 hover:text-yellow-400 transition-colors p-1 rounded-lg hover:bg-white/5">
        <i data-lucide="menu" class="w-5 h-5"></i>
      </button>
      <h1 class="text-lg font-bold text-yellow-400">BOIYETS FITNESS GYM</h1>
    </div>
    <div class="flex items-center space-x-3">
      <!-- Chat Button -->
      <a href="chat.php" class="text-gray-300 hover:text-blue-400 transition-colors p-2 rounded-lg hover:bg-white/5 relative">
        <i data-lucide="message-circle"></i>
        <?php if ($unread_count > 0): ?>
          <span class="absolute -top-1 -right-1 bg-red-500 text-xs rounded-full h-5 w-5 flex items-center justify-center" id="chatBadge">
            <?php echo $unread_count; ?>
          </span>
        <?php endif; ?>
      </a>

      <div class="h-8 w-px bg-gray-700 mx-1"></div>
      
      <!-- User Profile Dropdown -->
      <div class="dropdown-container">
        <button id="userMenuButton" class="flex items-center space-x-2 text-gray-300 hover:text-yellow-400 transition-colors p-2 rounded-lg hover:bg-white/5">
          <img src="<?php echo htmlspecialchars($user['profile_picture'] ?? 'https://i.pravatar.cc/40'); ?>" class="w-8 h-8 rounded-full border border-gray-600" id="userAvatar" />
          <span class="text-sm font-medium hidden md:inline" id="userName">
            <?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?>
          </span>
          <i data-lucide="chevron-down" class="w-4 h-4"></i>
        </button>
        
        <!-- User Dropdown Menu -->
        <div id="userDropdown" class="dropdown user-dropdown hidden">
          <div class="p-4 border-b border-gray-700">
            <p class="text-white font-semibold text-sm"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?></p>
            <p class="text-gray-400 text-xs capitalize"><?php echo $_SESSION['role']; ?></p>
          </div>
          <div class="p-2">
            <a href="profile.php" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-300 hover:text-yellow-400 hover:bg-white/5 rounded-lg transition-colors">
              <i data-lucide="user" class="w-4 h-4"></i>
              My Profile
            </a>
            <a href="edit_profile.php" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-300 hover:text-yellow-400 hover:bg-white/5 rounded-lg transition-colors">
              <i data-lucide="edit-2" class="w-4 h-4"></i>
              Edit Profile
            </a>
            <a href="settings.php" class="flex items-center gap-2 px-3 py-2 text-sm text-yellow-400 hover:bg-white/5 rounded-lg transition-colors">
              <i data-lucide="settings" class="w-4 h-4"></i>
              Settings
            </a>
            <div class="border-t border-gray-700 my-1"></div>
            <a href="logout.php" class="flex items-center gap-2 px-3 py-2 text-sm text-red-400 hover:text-red-300 hover:bg-red-400/10 rounded-lg transition-colors">
              <i data-lucide="log-out" class="w-4 h-4"></i>
              Logout
            </a>
          </div>
        </div>
      </div>
    </div>
  </header>

  <div class="flex">
    <!-- Sidebar -->
    <?php if ($_SESSION['role'] == 'admin'): ?>
    <!-- Admin Sidebar -->
    <aside id="sidebar" class="sidebar w-60 bg-[#0d0d0d] h-screen p-3 space-y-2 flex flex-col overflow-y-auto border-r border-gray-800">
      <nav class="space-y-1 flex-1">
        <a href="admin_dashboard.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="home"></i>
            <span class="text-sm font-medium">Dashboard</span>
          </div>
          <span class="tooltip">Dashboard</span>
        </a>

        <a href="view_users.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="users"></i>
            <span class="text-sm font-medium">View All Users</span>
          </div>
          <span class="tooltip">View All Users</span>
        </a>

        <a href="revenue.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="dollar-sign"></i>
            <span class="text-sm font-medium">Revenue & Members</span>
          </div>
          <span class="tooltip">Revenue & Members</span>
        </a>

        <a href="products.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="package"></i>
            <span class="text-sm font-medium">Products & Inventory</span>
          </div>
          <span class="tooltip">Products & Inventory</span>
        </a>

        <a href="adminannouncement.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="megaphone"></i>
            <span class="text-sm font-medium">Announcements</span>
          </div>
          <span class="tooltip">Announcements</span>
        </a>

        <a href="feedbacksadmin.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="message-square"></i>
            <span class="text-sm font-medium">Feedback & Reports</span>
          </div>
          <span class="tooltip">Feedback & Reports</span>
        </a>

        <div class="pt-4 border-t border-gray-800 mt-auto">
          <a href="logout.php" class="sidebar-item">
            <div class="flex items-center">
              <i data-lucide="log-out"></i>
              <span class="text-sm font-medium">Logout</span>
            </div>
            <span class="tooltip">Logout</span>
          </a>
        </div>
      </nav>
    </aside>
    <?php elseif ($_SESSION['role'] == 'trainer'): ?>
    <!-- Trainer Sidebar -->
    <aside id="sidebar" class="sidebar w-60 bg-[#0d0d0d] h-screen p-3 space-y-2 flex flex-col overflow-y-auto border-r border-gray-800">
      <nav class="space-y-1 flex-1">
        <a href="trainer_dashboard.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="home"></i>
            <span class="text-sm font-medium">Dashboard</span>
          </div>
          <span class="tooltip">Dashboard</span>
        </a>

        <!-- Members Section -->
        <div>
          <div id="membersToggle" class="sidebar-item">
            <div class="flex items-center">
              <i data-lucide="users"></i>
              <span class="text-sm font-medium">Members</span>
            </div>
            <i id="membersChevron" data-lucide="chevron-right" class="chevron"></i>
            <span class="tooltip">Members</span>
          </div>
          <div id="membersSubmenu" class="submenu space-y-1">
            <a href="walkin_registration.php"><i data-lucide="user"></i> Walk-in Registration</a>
            <a href="client_registration.php"><i data-lucide="user-plus"></i> Client Registration</a>
            <a href="membership_status.php"><i data-lucide="id-card"></i> Membership Status</a>
            <a href="all_members.php"><i data-lucide="list"></i> All Members</a>
          </div>
        </div>

        <!-- Client Progress -->
        <a href="clientprogress.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="activity"></i>
            <span class="text-sm font-medium">Client Progress</span>
          </div>
          <span class="tooltip">Client Progress</span>
        </a>

        <!-- Counter Sales -->
        <a href="countersales.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="package"></i>
            <span class="text-sm font-medium">Counter Sales</span>
          </div>
          <span class="tooltip">Counter Sales</span>
        </a>

        <!-- Feedbacks -->
        <a href="feedbackstrainer.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="message-square"></i>
            <span class="text-sm font-medium">Feedbacks</span>
          </div>
          <span class="tooltip">Feedbacks</span>
        </a>

        <!-- Attendance Logs -->
        <a href="attendance_logs.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="calendar"></i>
            <span class="text-sm font-medium">Attendance Logs</span>
          </div>
          <span class="tooltip">Attendance Logs</span>
        </a>

        <!-- QR Code Management -->
        <a href="trainermanageqrcodes.php" class="sidebar-item">
          <div class="flex items-center">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M4 4H8V8H4V4ZM10 4H14V8H10V4ZM16 4H20V8H16V4ZM4 10H8V14H4V10ZM10 10H14V14H10V10ZM16 10H20V14H16V10ZM4 16H8V20H4V16ZM10 16H14V20H10V16ZM16 16H20V20H16V16Z" 
                    fill="currentColor"/>
              <rect x="2" y="2" width="8" height="8" rx="1.5" stroke="currentColor" stroke-width="1.5" fill="none"/>
              <rect x="14" y="2" width="8" height="8" rx="1.5" stroke="currentColor" stroke-width="1.5" fill="none"/>
              <rect x="2" y="14" width="8" height="8" rx="1.5" stroke="currentColor" stroke-width="1.5" fill="none"/>
            </svg>
            <span class="text-sm font-medium">QR Code Management</span>
          </div>
          <span class="tooltip">Manage Client QR Codes</span>
        </a>

        <!-- Training Plans Section -->
        <div>
          <div id="plansToggle" class="sidebar-item">
            <div class="flex items-center">
              <i data-lucide="clipboard-list"></i>
              <span class="text-sm font-medium">Training Plans</span>
            </div>
            <i id="plansChevron" data-lucide="chevron-right" class="chevron"></i>
            <span class="tooltip">Training Plans</span>
          </div>
          <div id="plansSubmenu" class="submenu space-y-1">
            <a href="trainerworkout.php"><i data-lucide="dumbbell"></i> Workout Plans</a>
            <a href="trainermealplan.php"><i data-lucide="utensils"></i> Meal Plans</a>
          </div>
        </div>

        <div class="pt-4 border-t border-gray-800 mt-auto">
          <a href="logout.php" class="sidebar-item">
            <div class="flex items-center">
              <i data-lucide="log-out"></i>
              <span class="text-sm font-medium">Logout</span>
            </div>
            <span class="tooltip">Logout</span>
          </a>
        </div>
      </nav>
    </aside>
    <?php else: ?>
    <!-- Client Sidebar -->
    <aside id="sidebar" class="sidebar w-60 bg-[#0d0d0d] h-screen p-3 space-y-2 flex flex-col overflow-y-auto border-r border-gray-800">
      <nav class="space-y-1 flex-1">
        <a href="client_dashboard.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="home"></i>
            <span class="text-sm font-medium">Dashboard</span>
          </div>
          <span class="tooltip">Dashboard</span>
        </a>

        <a href="my_workouts.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="dumbbell"></i>
            <span class="text-sm font-medium">My Workouts</span>
          </div>
          <span class="tooltip">My Workouts</span>
        </a>

        <a href="my_meal_plans.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="utensils"></i>
            <span class="text-sm font-medium">My Meal Plans</span>
          </div>
          <span class="tooltip">My Meal Plans</span>
        </a>

        <a href="my_progress.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="activity"></i>
            <span class="text-sm font-medium">My Progress</span>
          </div>
          <span class="tooltip">My Progress</span>
        </a>

        <a href="my_attendance.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="calendar"></i>
            <span class="text-sm font-medium">My Attendance</span>
          </div>
          <span class="tooltip">My Attendance</span>
        </a>

        <a href="profile.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="user"></i>
            <span class="text-sm font-medium">My Profile</span>
          </div>
          <span class="tooltip">My Profile</span>
        </a>

        <a href="settings.php" class="sidebar-item active">
          <div class="flex items-center">
            <i data-lucide="settings"></i>
            <span class="text-sm font-medium">Settings</span>
          </div>
          <span class="tooltip">Settings</span>
        </a>

        <div class="pt-4 border-t border-gray-800 mt-auto">
          <a href="logout.php" class="sidebar-item">
            <div class="flex items-center">
              <i data-lucide="log-out"></i>
              <span class="text-sm font-medium">Logout</span>
            </div>
            <span class="tooltip">Logout</span>
          </a>
        </div>
      </nav>
    </aside>
    <?php endif; ?>

    <!-- Main Content -->
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

  <script>
    document.addEventListener('DOMContentLoaded', function() {
        lucide.createIcons();
        
        // Sidebar toggle
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

        // Members submenu toggle (for trainers)
        const membersToggle = document.getElementById('membersToggle');
        const membersSubmenu = document.getElementById('membersSubmenu');
        const membersChevron = document.getElementById('membersChevron');
        
        if (membersToggle) {
            membersToggle.addEventListener('click', () => {
                membersSubmenu.classList.toggle('open');
                membersChevron.classList.toggle('rotate');
            });
        }

        // Plans submenu toggle (for trainers)
        const plansToggle = document.getElementById('plansToggle');
        const plansSubmenu = document.getElementById('plansSubmenu');
        const plansChevron = document.getElementById('plansChevron');
        
        if (plansToggle) {
            plansToggle.addEventListener('click', () => {
                plansSubmenu.classList.toggle('open');
                plansChevron.classList.toggle('rotate');
            });
        }
        
        // Hover to open sidebar (for collapsed state)
        const sidebar = document.getElementById('sidebar');
        sidebar.addEventListener('mouseenter', () => {
            if (sidebar.classList.contains('sidebar-collapsed')) {
                sidebar.classList.remove('w-16', 'sidebar-collapsed');
                sidebar.classList.add('w-60');
            }
        });
        
        sidebar.addEventListener('mouseleave', () => {
            if (!sidebar.classList.contains('sidebar-collapsed') && window.innerWidth > 768) {
                sidebar.classList.remove('w-60');
                sidebar.classList.add('w-16', 'sidebar-collapsed');
            }
        });

        // Enhanced dropdown functionality
        function setupDropdowns() {
            const userMenuButton = document.getElementById('userMenuButton');
            const userDropdown = document.getElementById('userDropdown');
            
            // Close all dropdowns
            function closeAllDropdowns() {
                if (userDropdown) userDropdown.classList.add('hidden');
            }
            
            // Toggle user dropdown
            if (userMenuButton) {
                userMenuButton.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const isHidden = userDropdown.classList.contains('hidden');
                    
                    closeAllDropdowns();
                    
                    if (isHidden) {
                        userDropdown.classList.remove('hidden');
                    }
                });
            }
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function(e) {
                if ((!userDropdown || !userDropdown.contains(e.target)) && (!userMenuButton || !userMenuButton.contains(e.target))) {
                    closeAllDropdowns();
                }
            });
            
            // Close dropdowns when pressing Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeAllDropdowns();
                }
            });
        }

        setupDropdowns();
    });
  </script>
</body>
</html>



