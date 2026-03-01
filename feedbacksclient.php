<?php
session_start();
if (!isset($_SESSION['username']) || $_SESSION['role'] != 'client') {
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
// ADD THIS SECTION FOR CHAT FUNCTIONALITY
require_once 'chat_functions.php';
$unread_count = getUnreadCount($_SESSION['user_id'], $conn);
$logged_in_user_id = $_SESSION['user_id'];

// Add mobile-specific caching headers if needed
if (strpos($_SERVER['HTTP_USER_AGENT'], 'Mobile') !== false) {
    header("Cache-Control: max-age=300"); // 5 minutes for mobile
}

function getClientDetails($conn, $user_id) {
    $sql = "SELECT m.* FROM members m 
            INNER JOIN users u ON m.user_id = u.id 
            WHERE u.id = ? AND m.member_type = 'client'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function getClientFeedback($conn, $user_id) {
    $feedback = [];
    $sql = "SELECT f.* FROM feedback f 
            WHERE f.user_id = ? ORDER BY f.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $feedback[] = $row;
    }
    
    return $feedback;
}

// Handle feedback submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_feedback'])) {
    $subject = mysqli_real_escape_string($conn, $_POST['subject']);
    $category = mysqli_real_escape_string($conn, $_POST['category']);
    $message = mysqli_real_escape_string($conn, $_POST['message']);
    $rating = isset($_POST['rating']) ? intval($_POST['rating']) : NULL;
    $urgent = isset($_POST['urgent']) ? 1 : 0;
    
    $insert_sql = "INSERT INTO feedback (user_id, user_role, subject, category, message, rating, urgent, status, created_at) 
                   VALUES (?, 'client', ?, ?, ?, ?, ?, 'pending', NOW())";
    $stmt = $conn->prepare($insert_sql);
    $stmt->bind_param("isssii", $logged_in_user_id, $subject, $category, $message, $rating, $urgent);
    
    if ($stmt->execute()) {
        $success_message = "Feedback submitted successfully! We'll review it soon.";
        // Clear form
        $_POST = array();
    } else {
        $error_message = "Error submitting feedback: " . $conn->error;
    }
}

$client = getClientDetails($conn, $logged_in_user_id);
$feedback_history = getClientFeedback($conn, $logged_in_user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback - BOIYETS FITNESS GYM</title>
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
            margin: 0;
            padding: 0;
        }
        
        /* Mobile Sidebar Styles */
        .mobile-sidebar {
            transform: translateX(-100%);
            transition: transform 0.3s ease-in-out;
            z-index: 50;
            display: none; /* Hidden by default */
            will-change: transform;
        }
        
        .mobile-sidebar.open {
            transform: translateX(0);
        }
        
        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 40;
        }
        
        .overlay.active {
            display: block;
        }
        
        .sidebar { 
            flex-shrink: 0; 
            transition: all 0.3s ease;
            overflow-y: auto;
            -ms-overflow-style: none;
            scrollbar-width: none;
            height: calc(100vh - 64px);
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
            min-height: 44px;
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
            min-height: 44px;
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
        
        /* Remove hover effects on mobile */
        @media (min-width: 769px) {
            .sidebar-item:hover { 
                background: rgba(255,255,255,0.05); 
                color: #fbbf24; 
            }
            
            .submenu a:hover { 
                color: #fbbf24; 
                background: rgba(255,255,255,0.05); 
            }
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
            min-height: 44px;
        }
        
        .submenu a i { 
            width: 14px; 
            height: 14px; 
            margin-right: 0.5rem; 
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
            will-change: transform;
        }
        
        .card:hover {
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.2);
            transform: translateY(-2px);
        }
        
        .feedback-item {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 4px solid #fbbf24;
        }
        
        .topbar {
            background: rgba(13, 13, 13, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            position: sticky;
            top: 0;
            z-index: 30;
            height: 64px;
        }
        
        .main-content {
            flex: 1;
            overflow-y: auto;
            height: calc(100vh - 64px);
        }
        
        .form-input {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 0.75rem;
            color: white;
            width: 100%;
            transition: all 0.2s ease;
            min-height: 44px;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #fbbf24;
            box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.2);
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #d1d5db;
        }
        
        .form-select {
            background: rgba(255, 255, 255, 0.05) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            border-radius: 8px;
            padding: 0.75rem;
            color: white !important;
            width: 100%;
            transition: all 0.2s ease;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23fbbf24' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") !important;
            background-repeat: no-repeat !important;
            background-position: right 0.75rem center !important;
            background-size: 16px !important;
            min-height: 44px;
        }
        
        .form-select:focus {
            outline: none;
            border-color: #fbbf24 !important;
            box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.2) !important;
        }
        
        .form-select option {
            background: #1a1a1a !important;
            color: white !important;
            padding: 0.5rem;
        }
        
        .form-select:focus-visible {
            outline: none;
        }
        
        @-moz-document url-prefix() {
            .form-select {
                color: white !important;
                text-shadow: 0 0 0 white;
            }
            
            .form-select option {
                background: #1a1a1a !important;
                color: white !important;
            }
        }
        
        .star-rating {
            display: flex;
            gap: 0.5rem;
        }
        
        .star {
            cursor: pointer;
            color: #6b7280;
            transition: color 0.2s ease;
            min-width: 32px;
            min-height: 32px;
        }
        
        .star:hover, .star.active {
            color: #fbbf24;
        }
        
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .status-pending {
            background: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        
        .status-reviewed {
            background: rgba(59, 130, 246, 0.2);
            color: #3b82f6;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        
        .status-resolved {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .urgent-badge {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        /* Mobile Navigation */
        .mobile-nav {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(13, 13, 13, 0.95);
            backdrop-filter: blur(10px);
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            z-index: 30;
        }
        
        .mobile-nav-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0.5rem;
            color: #9ca3af;
            transition: all 0.2s ease;
            min-height: 44px;
        }
        
        .mobile-nav-item.active {
            color: #fbbf24;
        }
        
        .mobile-nav-item i {
            width: 20px;
            height: 20px;
            margin-bottom: 0.25rem;
        }
        
        .mobile-nav-label {
            font-size: 0.7rem;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .desktop-sidebar {
                display: none !important; /* Force hide on mobile */
            }
            
            .mobile-sidebar {
                display: block; /* Show mobile sidebar on mobile */
            }
            
            .mobile-nav {
                display: flex;
            }
            
            .main-content {
                padding-bottom: 80px;
                height: calc(100vh - 144px);
            }
            
            .card {
                padding: 1rem;
            }
            
            .feedback-item {
                padding: 0.75rem;
            }
            
            .status-badge {
                padding: 0.4rem 0.8rem;
                font-size: 0.7rem;
            }
            
            .urgent-badge {
                padding: 0.2rem 0.4rem;
                font-size: 0.6rem;
            }
            
            .star {
                min-width: 28px;
                min-height: 28px;
            }
            
            /* Hide hover effects on mobile */
            .card:hover {
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                transform: none;
            }
        }
        
        @media (max-width: 480px) {
            .grid-cols-1 {
                grid-template-columns: 1fr;
            }
            
            .grid-cols-3 {
                grid-template-columns: 1fr;
            }
            
            .feedback-item .flex {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .feedback-item .flex > div:last-child {
                margin-top: 0.5rem;
            }
            
            .star-rating {
                justify-content: center;
            }
        }
    </style>
</head>
<body class="min-h-screen js-enabled">
  <!-- Mobile Overlay -->
  <div class="overlay" id="mobileOverlay"></div>

  <!-- Topbar with Enhanced Notification & Profile -->
  <header class="topbar flex items-center justify-between px-4 py-3 shadow">
    <div class="flex items-center space-x-3">
      <button id="toggleSidebar" class="text-gray-300 hover:text-yellow-400 transition-colors p-1 rounded-lg hover:bg-white/5" aria-label="Toggle sidebar" aria-expanded="false" aria-controls="desktopSidebar">
        <i data-lucide="menu" class="w-5 h-5"></i>
      </button>
      <h1 class="text-lg font-bold text-yellow-400">BOIYETS FITNESS GYM</h1>
    </div>
    <div class="flex items-center space-x-3">
      <!-- Client Type Badge -->
     
      
      <!-- Chat Button -->
      <a href="chat.php" class="text-gray-300 hover:text-blue-400 transition-colors p-2 rounded-lg hover:bg-white/5 relative">
        <i data-lucide="message-circle"></i>
        <?php if ($unread_count > 0): ?>
          <span class="absolute -top-1 -right-1 bg-red-500 text-xs rounded-full h-5 w-5 flex items-center justify-center" id="chatBadge">
            <?php echo $unread_count; ?>
          </span>
        <?php endif; ?>
      </a>

      <!-- Notification Bell -->
      <div class="dropdown-container">
        <button id="notificationBell" class="text-gray-300 hover:text-yellow-400 transition-colors p-2 rounded-lg hover:bg-white/5 relative">
          <i data-lucide="bell" class="w-5 h-5"></i>
          <span class="absolute -top-1 -right-1 bg-red-500 text-xs rounded-full h-5 w-5 flex items-center justify-center <?php echo $notification_count > 0 ? '' : 'hidden'; ?>" id="notificationBadge">
            <?php echo $notification_count > 99 ? '99+' : $notification_count; ?>
          </span>
        </button>
        
        <!-- Notification Dropdown -->
        <div id="notificationDropdown" class="dropdown notification-dropdown hidden">
          <div class="p-4 border-b border-gray-700">
            <div class="flex justify-between items-center">
              <h3 class="text-yellow-400 font-semibold">Notifications</h3>
              <?php if ($notification_count > 0): ?>
                <button id="markAllRead" class="text-xs text-gray-400 hover:text-yellow-400">Mark all read</button>
              <?php endif; ?>
            </div>
          </div>
          <div class="max-h-96 overflow-y-auto">
            <div id="notificationList" class="p-2">
              <?php if (empty($notifications)): ?>
                <div class="text-center py-8 text-gray-500">
                  <i data-lucide="bell-off" class="w-12 h-12 mx-auto mb-3 opacity-50"></i>
                  <p>No notifications</p>
                  <p class="text-sm mt-1">You're all caught up!</p>
                </div>
              <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                  <div class="notification-item" data-notification-id="<?php echo $notification['id']; ?>">
                    <div class="flex items-start gap-3">
                      <div class="flex-shrink-0 mt-1">
                        <?php
                        $icon = 'bell';
                        $color = 'gray-400';
                        switch($notification['type']) {
                          case 'announcement': $icon = 'megaphone'; $color = 'yellow-400'; break;
                          case 'membership': $icon = 'id-card'; $color = 'blue-400'; break;
                          case 'message': $icon = 'message-circle'; $color = 'green-400'; break;
                          case 'system': $icon = 'settings'; $color = 'purple-400'; break;
                          case 'reminder': $icon = 'clock'; $color = 'orange-400'; break;
                          default: $icon = 'bell'; $color = 'gray-400';
                        }
                        ?>
                        <i data-lucide="<?php echo $icon; ?>" class="w-4 h-4 text-<?php echo $color; ?>"></i>
                      </div>
                      <div class="flex-1 min-w-0">
                        <div class="flex justify-between items-start mb-1">
                          <p class="text-white font-medium text-sm"><?php echo htmlspecialchars($notification['title']); ?></p>
                          <span class="text-xs text-gray-400 whitespace-nowrap ml-2">
                            <?php
                            $time = strtotime($notification['created_at']);
                            $now = time();
                            $diff = $now - $time;
                            
                            if ($diff < 60) {
                              echo 'Just now';
                            } elseif ($diff < 3600) {
                              echo floor($diff / 60) . 'm ago';
                            } elseif ($diff < 86400) {
                              echo floor($diff / 3600) . 'h ago';
                            } elseif ($diff < 604800) {
                              echo floor($diff / 86400) . 'd ago';
                            } else {
                              echo date('M j, Y', $time);
                            }
                            ?>
                          </span>
                        </div>
                        <p class="text-gray-400 text-xs line-clamp-2"><?php echo htmlspecialchars($notification['message']); ?></p>
                        <?php if (isset($notification['priority']) && $notification['priority'] === 'high'): ?>
                          <span class="inline-block mt-1 px-2 py-1 bg-red-500/20 text-red-400 text-xs rounded-full">
                            Important
                          </span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
          <div class="p-3 border-t border-gray-700 text-center">
            <a href="notifications.php" class="text-yellow-400 text-sm hover:text-yellow-300">View All Notifications</a>
          </div>
        </div>
      </div>

      <div class="h-8 w-px bg-gray-700 mx-1"></div>
      
      <!-- User Profile Dropdown -->
      <div class="dropdown-container">
        <button id="userMenuButton" class="flex items-center space-x-2 text-gray-300 hover:text-yellow-400 transition-colors p-2 rounded-lg hover:bg-white/5">
          <img src="<?php echo htmlspecialchars($_SESSION['profile_picture'] ?? 'https://i.pravatar.cc/40'); ?>" class="w-8 h-8 rounded-full border border-gray-600" id="userAvatar" />
          <span class="text-sm font-medium hidden md:inline" id="userName">
            <?php echo htmlspecialchars(isset($client['full_name']) ? $client['full_name'] : $_SESSION['username']); ?>
          </span>
          <i data-lucide="chevron-down" class="w-4 h-4"></i>
        </button>
        
        <!-- User Dropdown Menu -->
        <div id="userDropdown" class="dropdown user-dropdown hidden">
          <div class="p-4 border-b border-gray-700">
            <p class="text-white font-semibold text-sm"><?php echo htmlspecialchars(isset($client['full_name']) ? $client['full_name'] : $_SESSION['username']); ?></p>
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
            <a href="settings.php" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-300 hover:text-yellow-400 hover:bg-white/5 rounded-lg transition-colors">
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

  <div class="flex h-[calc(100vh-64px)]">
    <!-- Desktop Sidebar - Only for desktop -->
    <aside id="desktopSidebar" class="desktop-sidebar sidebar w-60 bg-[#0d0d0d] p-3 space-y-2 flex flex-col border-r border-gray-800" aria-label="Main navigation">
      <nav class="space-y-1 flex-1">
        <a href="client_dashboard.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="home"></i>
            <span class="text-sm font-medium">Dashboard</span>
          </div>
          <span class="tooltip">Dashboard</span>
        </a>

        <!-- Workout Plans Section -->
        <div>
          <div id="workoutToggle" class="sidebar-item">
            <div class="flex items-center">
              <i data-lucide="dumbbell"></i>
              <span class="text-sm font-medium">Workout Plans</span>
            </div>
            <i id="workoutChevron" data-lucide="chevron-right" class="chevron"></i>
            <span class="tooltip">Workout Plans</span>
          </div>
          <div id="workoutSubmenu" class="submenu space-y-1">
            <a href="workoutplansclient.php"><i data-lucide="list"></i> My Workout Plans</a>
            <a href="workoutprogress.php"><i data-lucide="activity"></i> Workout Progress</a>
          </div>
        </div>

        <!-- Nutrition Plans Section -->
        <div>
          <div id="nutritionToggle" class="sidebar-item">
            <div class="flex items-center">
              <i data-lucide="utensils"></i>
              <span class="text-sm font-medium">Nutrition Plans</span>
            </div>
            <i id="nutritionChevron" data-lucide="chevron-right" class="chevron"></i>
            <span class="tooltip">Nutrition Plans</span>
          </div>
          <div id="nutritionSubmenu" class="submenu space-y-1">
            <a href="nutritionplansclient.php"><i data-lucide="list"></i> My Meal Plans</a>
            <a href="nutritiontracking.php"><i data-lucide="chart-bar"></i> Nutrition Tracking</a>
          </div>
        </div>

        <!-- Progress Tracking -->
        <a href="myprogressclient.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="activity"></i>
            <span class="text-sm font-medium">My Progress</span>
          </div>
          <span class="tooltip">My Progress</span>
        </a>

        <!-- Attendance -->
        <a href="attendanceclient.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="calendar"></i>
            <span class="text-sm font-medium">Attendance</span>
          </div>
          <span class="tooltip">Attendance</span>
        </a>

        <!-- Membership -->
        <a href="membershipclient.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="id-card"></i>
            <span class="text-sm font-medium">Membership</span>
          </div>
          <span class="tooltip">Membership</span>
        </a>

        <!-- Feedbacks -->
        <a href="feedbacksclient.php" class="sidebar-item active">
          <div class="flex items-center">
            <i data-lucide="message-square"></i>
            <span class="text-sm font-medium">Send Feedback</span>
          </div>
          <span class="tooltip">Send Feedback</span>
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

    <!-- Main Content -->
    <main id="mainContent" class="main-content flex-1 p-4 space-y-6 overflow-auto">
      <div class="flex justify-between items-center mb-6">
        <div class="flex items-center space-x-3">
          <a href="client_dashboard.php" class="text-gray-300 hover:text-yellow-400 transition-colors p-2 rounded-lg hover:bg-white/5">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
          </a>
          <h2 class="text-2xl font-bold text-yellow-400 flex items-center gap-3">
            <i data-lucide="message-square" class="w-8 h-8"></i>
            Send Feedback
          </h2>
        </div>
        <span class="bg-green-500 text-white px-4 py-2 rounded-full text-sm font-semibold">Submitted: <?php echo count($feedback_history); ?></span>
      </div>

      <!-- Success/Error Messages -->
      <?php if (isset($success_message)): ?>
        <div class="bg-green-500/20 border border-green-500/30 text-green-300 px-4 py-3 rounded-lg">
          <?php echo htmlspecialchars($success_message); ?>
        </div>
      <?php endif; ?>
      
      <?php if (isset($error_message)): ?>
        <div class="bg-red-500/20 border border-red-500/30 text-red-300 px-4 py-3 rounded-lg">
          <?php echo htmlspecialchars($error_message); ?>
        </div>
      <?php endif; ?>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Feedback Form -->
        <div class="card">
          <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
            <i data-lucide="send" class="w-5 h-5"></i>
            Send Your Feedback
          </h3>

          <form method="POST" class="space-y-4">
            <div>
              <label class="form-label">Subject</label>
              <input type="text" name="subject" class="form-input" placeholder="What is your feedback about?" 
                     value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>" required>
            </div>
            
            <div>
              <label class="form-label">Category</label>
              <select name="category" class="form-select" required>
                <option value="">Select a category</option>
                <option value="workout" <?php echo ($_POST['category'] ?? '') == 'workout' ? 'selected' : ''; ?>>Workout Plan</option>
                <option value="nutrition" <?php echo ($_POST['category'] ?? '') == 'nutrition' ? 'selected' : ''; ?>>Nutrition Plan</option>
                <option value="trainer" <?php echo ($_POST['category'] ?? '') == 'trainer' ? 'selected' : ''; ?>>Trainer Feedback</option>
                <option value="facility" <?php echo ($_POST['category'] ?? '') == 'facility' ? 'selected' : ''; ?>>Gym Facility</option>
                <option value="equipment" <?php echo ($_POST['category'] ?? '') == 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                <option value="service" <?php echo ($_POST['category'] ?? '') == 'service' ? 'selected' : ''; ?>>Customer Service</option>
                <option value="other" <?php echo ($_POST['category'] ?? '') == 'other' ? 'selected' : ''; ?>>Other</option>
              </select>
            </div>
            
            <div>
              <label class="form-label">Rating (Optional)</label>
              <div class="star-rating" id="starRating">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                  <i data-lucide="star" class="w-8 h-8 star" data-rating="<?php echo $i; ?>"></i>
                <?php endfor; ?>
              </div>
              <input type="hidden" name="rating" id="selectedRating" value="<?php echo $_POST['rating'] ?? ''; ?>">
            </div>
            
            <div>
              <label class="form-label">Your Message</label>
              <textarea name="message" class="form-input" rows="6" placeholder="Please provide detailed feedback about your experience..." required><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
            </div>
            
            <div class="flex items-center gap-3">
              <input type="checkbox" name="urgent" id="urgent" class="rounded bg-gray-800 border-gray-700 w-5 h-5" 
                     <?php echo isset($_POST['urgent']) ? 'checked' : ''; ?>>
              <label for="urgent" class="text-sm text-gray-300">
                Mark as urgent (Requires immediate attention)
              </label>
            </div>
            
            <button type="submit" name="submit_feedback" class="w-full bg-yellow-500 hover:bg-yellow-600 text-white py-3 px-4 rounded-lg transition-colors font-semibold flex items-center justify-center gap-2 min-h-[44px]">
              <i data-lucide="send" class="w-4 h-4"></i>
              Submit Feedback
            </button>
          </form>
        </div>

        <!-- Feedback History -->
        <div class="card">
          <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
            <i data-lucide="history" class="w-5 h-5"></i>
            Feedback History
          </h3>
          
          <div class="space-y-4">
            <?php foreach ($feedback_history as $feedback): ?>
              <div class="feedback-item">
                <div class="flex flex-col md:flex-row md:items-start md:justify-between mb-3">
                  <div class="mb-2 md:mb-0">
                    <div class="font-semibold text-white">
                      <?php 
                      $categories = [
                        'workout' => 'Workout Plan',
                        'nutrition' => 'Nutrition Plan', 
                        'trainer' => 'Trainer Feedback',
                        'facility' => 'Gym Facility',
                        'equipment' => 'Equipment',
                        'service' => 'Customer Service',
                        'other' => 'Other'
                      ];
                      echo $categories[$feedback['category']] ?? 'General Feedback';
                      ?>
                    </div>
                    <div class="text-sm text-gray-400">
                      <?php echo date('F j, Y g:i A', strtotime($feedback['created_at'])); ?>
                    </div>
                  </div>
                  <div class="flex items-center gap-2">
                    <?php if ($feedback['rating']): ?>
                      <div class="flex items-center gap-1">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                          <i data-lucide="star" class="w-4 h-4 <?php echo $i <= $feedback['rating'] ? 'text-yellow-400 fill-yellow-400' : 'text-gray-600'; ?>"></i>
                        <?php endfor; ?>
                      </div>
                    <?php endif; ?>
                    <?php if ($feedback['urgent']): ?>
                      <span class="urgent-badge">Urgent</span>
                    <?php endif; ?>
                  </div>
                </div>
                
                <?php if (!empty($feedback['subject'])): ?>
                  <div class="font-medium text-white mb-2"><?php echo htmlspecialchars($feedback['subject']); ?></div>
                <?php endif; ?>
                
                <div class="text-gray-300 mb-3">
                  <?php echo nl2br(htmlspecialchars($feedback['message'])); ?>
                </div>
                
                <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                  <span class="status-badge status-<?php echo $feedback['status'] ?? 'pending'; ?>">
                    <?php 
                    $statuses = [
                      'pending' => 'Under Review',
                      'reviewed' => 'Being Addressed',
                      'resolved' => 'Resolved'
                    ];
                    echo $statuses[$feedback['status']] ?? 'Submitted';
                    ?>
                  </span>
                  <?php if (isset($feedback['admin_notes'])): ?>
                    <span class="text-xs text-gray-400">
                      Admin Response: <?php echo htmlspecialchars($feedback['admin_notes']); ?>
                    </span>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
            
            <?php if (empty($feedback_history)): ?>
              <div class="text-center py-8 text-gray-500">
                <i data-lucide="message-square" class="w-16 h-16 mx-auto mb-4"></i>
                <p class="text-lg">No feedback submitted yet.</p>
                <p class="text-sm">Share your thoughts with us!</p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Help Section -->
      <div class="card">
        <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
          <i data-lucide="help-circle" class="w-5 h-5"></i>
          Need Help?
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="text-center p-4 bg-gray-800 rounded-lg">
            <i data-lucide="phone" class="w-8 h-8 text-yellow-400 mx-auto mb-2"></i>
            <div class="font-semibold">Call Us</div>
            <div class="text-sm text-gray-400">0936-129-2735</div>
          </div>
          <div class="text-center p-4 bg-gray-800 rounded-lg">
            <i data-lucide="facebook" class="w-8 h-8 text-yellow-400 mx-auto mb-2"></i>
            <div class="font-semibold">Facebook</div>
            <a href="https://www.facebook.com/profile.php?id=61565470524306" target="_blank" class="text-sm text-gray-400 hover:text-yellow-400 transition-colors">
              Boiyets Fitness Gym
            </a>
          </div>
          <div class="text-center p-4 bg-gray-800 rounded-lg">
            <i data-lucide="map-pin" class="w-8 h-8 text-yellow-400 mx-auto mb-2"></i>
            <div class="font-semibold">Visit Us</div>
            <div class="text-sm text-gray-400">Sta. Romana Subd. Abar 1st, San Jose City, Nueva Ecija</div>
          </div>
        </div>
      </div>
    </main>
  </div>

  <!-- Mobile Bottom Navigation -->
  <nav class="mobile-nav" aria-label="Mobile navigation">
    <a href="client_dashboard.php" class="mobile-nav-item">
      <i data-lucide="home"></i>
      <span class="mobile-nav-label">Dashboard</span>
    </a>
    <a href="workoutplansclient.php" class="mobile-nav-item">
      <i data-lucide="dumbbell"></i>
      <span class="mobile-nav-label">Workouts</span>
    </a>
    <a href="nutritionplansclient.php" class="mobile-nav-item">
      <i data-lucide="utensils"></i>
      <span class="mobile-nav-label">Nutrition</span>
    </a>
    <a href="feedbacksclient.php" class="mobile-nav-item active">
      <i data-lucide="message-square"></i>
      <span class="mobile-nav-label">Feedback</span>
    </a>
    <button id="mobileMenuButton" class="mobile-nav-item">
      <i data-lucide="menu"></i>
      <span class="mobile-nav-label">Menu</span>
    </button>
  </nav>

  <!-- Mobile Sidebar - Only for mobile -->
  <aside id="mobileSidebar" class="mobile-sidebar fixed top-0 left-0 w-full h-full bg-[#0d0d0d] p-6 space-y-2 overflow-y-auto z-50">
    <div class="flex justify-between items-center mb-8">
      <h2 class="text-xl font-bold text-yellow-400">BOIYETS FITNESS</h2>
      <button id="closeMobileSidebar" class="text-gray-400 hover:text-white p-2">
        <i data-lucide="x" class="w-6 h-6"></i>
      </button>
    </div>
    
    <nav class="space-y-1">
      <a href="client_dashboard.php" class="sidebar-item" onclick="closeMobileSidebar()">
        <div class="flex items-center">
          <i data-lucide="home"></i>
          <span class="text-sm font-medium">Dashboard</span>
        </div>
      </a>

      <!-- Workout Plans Section -->
      <div>
        <div id="mobileWorkoutToggle" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="dumbbell"></i>
            <span class="text-sm font-medium">Workout Plans</span>
          </div>
          <i id="mobileWorkoutChevron" data-lucide="chevron-right" class="chevron"></i>
        </div>
        <div id="mobileWorkoutSubmenu" class="submenu space-y-1">
          <a href="workoutplansclient.php" onclick="closeMobileSidebar()"><i data-lucide="list"></i> My Workout Plans</a>
          <a href="workoutprogress.php" onclick="closeMobileSidebar()"><i data-lucide="activity"></i> Workout Progress</a>
        </div>
      </div>

      <!-- Nutrition Plans Section -->
      <div>
        <div id="mobileNutritionToggle" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="utensils"></i>
            <span class="text-sm font-medium">Nutrition Plans</span>
          </div>
          <i id="mobileNutritionChevron" data-lucide="chevron-right" class="chevron"></i>
        </div>
        <div id="mobileNutritionSubmenu" class="submenu space-y-1">
          <a href="nutritionplansclient.php" onclick="closeMobileSidebar()"><i data-lucide="list"></i> My Meal Plans</a>
          <a href="nutritiontracking.php" onclick="closeMobileSidebar()"><i data-lucide="chart-bar"></i> Nutrition Tracking</a>
        </div>
      </div>

      <!-- Progress Tracking -->
      <a href="myprogressclient.php" class="sidebar-item" onclick="closeMobileSidebar()">
        <div class="flex items-center">
          <i data-lucide="activity"></i>
          <span class="text-sm font-medium">My Progress</span>
        </div>
      </a>

      <!-- Attendance -->
      <a href="attendanceclient.php" class="sidebar-item" onclick="closeMobileSidebar()">
        <div class="flex items-center">
          <i data-lucide="calendar"></i>
          <span class="text-sm font-medium">Attendance</span>
        </div>
      </a>

      <!-- Membership -->
      <a href="membershipclient.php" class="sidebar-item" onclick="closeMobileSidebar()">
        <div class="flex items-center">
          <i data-lucide="id-card"></i>
          <span class="text-sm font-medium">Membership</span>
        </div>
      </a>

      <!-- Feedbacks -->
      <a href="feedbacksclient.php" class="sidebar-item active" onclick="closeMobileSidebar()">
        <div class="flex items-center">
          <i data-lucide="message-square"></i>
          <span class="text-sm font-medium">Send Feedback</span>
        </div>
      </a>

      <div class="pt-8 border-t border-gray-800 mt-8">
        <a href="logout.php" class="sidebar-item" onclick="closeMobileSidebar()">
          <div class="flex items-center">
            <i data-lucide="log-out"></i>
            <span class="text-sm font-medium">Logout</span>
          </div>
        </a>
      </div>
    </nav>
  </aside>

  <noscript>
    <style>
      .mobile-sidebar { display: none; }
      .desktop-sidebar { display: block !important; }
      .mobile-nav { display: none !important; }
      .js-enabled { display: none; }
    </style>
  </noscript>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Initialize icons
      lucide.createIcons();

      // Mobile sidebar functionality
      const mobileSidebar = document.getElementById('mobileSidebar');
      const mobileOverlay = document.getElementById('mobileOverlay');
      const toggleSidebar = document.getElementById('toggleSidebar');
      const closeMobileSidebar = document.getElementById('closeMobileSidebar');
      const mobileMenuButton = document.getElementById('mobileMenuButton');
      const desktopSidebar = document.getElementById('desktopSidebar');

      function openMobileSidebar() {
        mobileSidebar.classList.add('open');
        mobileOverlay.classList.add('active');
        document.body.style.overflow = 'hidden';
      }

      function closeMobileSidebarFunc() {
        mobileSidebar.classList.remove('open');
        mobileOverlay.classList.remove('active');
        document.body.style.overflow = '';
      }

      // Smart toggle function that detects screen size
      function smartToggleSidebar() {
        if (window.innerWidth <= 768) {
          // Mobile: Toggle mobile sidebar
          if (mobileSidebar.classList.contains('open')) {
            closeMobileSidebarFunc();
          } else {
            openMobileSidebar();
          }
        } else {
          // Desktop: Toggle desktop sidebar
          if (desktopSidebar.classList.contains('w-60')) {
            desktopSidebar.classList.remove('w-60');
            desktopSidebar.classList.add('w-16', 'sidebar-collapsed');
          } else {
            desktopSidebar.classList.remove('w-16', 'sidebar-collapsed');
            desktopSidebar.classList.add('w-60');
          }
        }
      }

      // Event listeners
      if (toggleSidebar) {
        toggleSidebar.addEventListener('click', smartToggleSidebar);
      }
      
      if (mobileMenuButton) {
        mobileMenuButton.addEventListener('click', openMobileSidebar);
      }
      
      if (closeMobileSidebar) {
        closeMobileSidebar.addEventListener('click', closeMobileSidebarFunc);
      }
      
      if (mobileOverlay) {
        mobileOverlay.addEventListener('click', closeMobileSidebarFunc);
      }

      // Workout submenu toggle (Desktop)
      const workoutToggle = document.getElementById('workoutToggle');
      const workoutSubmenu = document.getElementById('workoutSubmenu');
      const workoutChevron = document.getElementById('workoutChevron');
      
      if (workoutToggle) {
        workoutToggle.addEventListener('click', () => {
          workoutSubmenu.classList.toggle('open');
          workoutChevron.classList.toggle('rotate');
        });
      }

      // Nutrition submenu toggle (Desktop)
      const nutritionToggle = document.getElementById('nutritionToggle');
      const nutritionSubmenu = document.getElementById('nutritionSubmenu');
      const nutritionChevron = document.getElementById('nutritionChevron');
      
      if (nutritionToggle) {
        nutritionToggle.addEventListener('click', () => {
          nutritionSubmenu.classList.toggle('open');
          nutritionChevron.classList.toggle('rotate');
        });
      }

      // Mobile submenu toggles
      const mobileWorkoutToggle = document.getElementById('mobileWorkoutToggle');
      const mobileWorkoutSubmenu = document.getElementById('mobileWorkoutSubmenu');
      const mobileWorkoutChevron = document.getElementById('mobileWorkoutChevron');
      
      if (mobileWorkoutToggle) {
        mobileWorkoutToggle.addEventListener('click', () => {
          mobileWorkoutSubmenu.classList.toggle('open');
          mobileWorkoutChevron.classList.toggle('rotate');
        });
      }

      const mobileNutritionToggle = document.getElementById('mobileNutritionToggle');
      const mobileNutritionSubmenu = document.getElementById('mobileNutritionSubmenu');
      const mobileNutritionChevron = document.getElementById('mobileNutritionChevron');
      
      if (mobileNutritionToggle) {
        mobileNutritionToggle.addEventListener('click', () => {
          mobileNutritionSubmenu.classList.toggle('open');
          mobileNutritionChevron.classList.toggle('rotate');
        });
      }

      // Hover to open sidebar (for desktop collapsed state) - Only on desktop
      if (desktopSidebar && window.innerWidth > 768) {
        desktopSidebar.addEventListener('mouseenter', () => {
          if (desktopSidebar.classList.contains('sidebar-collapsed')) {
            desktopSidebar.classList.remove('w-16', 'sidebar-collapsed');
            desktopSidebar.classList.add('w-60');
          }
        });
        
        desktopSidebar.addEventListener('mouseleave', () => {
          if (!desktopSidebar.classList.contains('sidebar-collapsed')) {
            desktopSidebar.classList.remove('w-60');
            desktopSidebar.classList.add('w-16', 'sidebar-collapsed');
          }
        });
      }

      // Star rating functionality
      const stars = document.querySelectorAll('.star');
      const selectedRating = document.getElementById('selectedRating');
      
      stars.forEach(star => {
        star.addEventListener('click', function() {
          const rating = this.getAttribute('data-rating');
          selectedRating.value = rating;
          
          // Update star display
          stars.forEach((s, index) => {
            if (index < rating) {
              s.classList.add('active', 'fill-yellow-400');
              s.style.color = '#fbbf24';
            } else {
              s.classList.remove('active', 'fill-yellow-400');
              s.style.color = '#6b7280';
            }
          });
        });
        
        star.addEventListener('mouseover', function() {
          const rating = this.getAttribute('data-rating');
          stars.forEach((s, index) => {
            if (index < rating) {
              s.style.color = '#fbbf24';
            }
          });
        });
        
        star.addEventListener('mouseout', function() {
          const currentRating = selectedRating.value;
          stars.forEach((s, index) => {
            if (!currentRating || index >= currentRating) {
              s.style.color = '#6b7280';
            }
          });
        });
      });
      
      // Initialize stars if there's a previous rating
      if (selectedRating.value) {
        stars.forEach((star, index) => {
          if (index < selectedRating.value) {
            star.classList.add('active', 'fill-yellow-400');
            star.style.color = '#fbbf24';
          }
        });
      }

      // Update mobile nav active state
      updateMobileNavActive();
    });

    // Update mobile navigation active state
    function updateMobileNavActive() {
      const currentPage = window.location.pathname.split('/').pop();
      const mobileNavItems = document.querySelectorAll('.mobile-nav-item');
      
      mobileNavItems.forEach(item => {
        item.classList.remove('active');
        // Simple page matching logic
        if (item.getAttribute('href') === currentPage) {
          item.classList.add('active');
        }
      });
    }

    // Global function to close mobile sidebar (used in onclick events)
    function closeMobileSidebar() {
      const mobileSidebar = document.getElementById('mobileSidebar');
      const mobileOverlay = document.getElementById('mobileOverlay');
      
      if (mobileSidebar) {
        mobileSidebar.classList.remove('open');
      }
      if (mobileOverlay) {
        mobileOverlay.classList.remove('active');
      }
      document.body.style.overflow = '';
    }

    // Handle window resize
    window.addEventListener('resize', function() {
      const mobileSidebar = document.getElementById('mobileSidebar');
      const mobileOverlay = document.getElementById('mobileOverlay');
      
      // Close mobile sidebar when resizing to desktop
      if (window.innerWidth > 768 && mobileSidebar && mobileSidebar.classList.contains('open')) {
        closeMobileSidebar();
      }

      // Update mobile nav on resize
      updateMobileNavActive();
    });
  </script>
</body>
</html>
<?php $conn->close(); ?>



