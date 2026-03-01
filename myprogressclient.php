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

function getClientProgress($conn, $user_id) {
    $progress = [];
    $sql = "SELECT cp.* FROM client_progress cp 
            INNER JOIN members m ON cp.member_id = m.id 
            INNER JOIN users u ON m.user_id = u.id 
            WHERE u.id = ? ORDER BY cp.progress_date ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $progress[] = $row;
    }
    
    return $progress;
}

// Handle progress submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_progress'])) {
    $weight = floatval($_POST['weight']);
    $notes = mysqli_real_escape_string($conn, $_POST['notes']);
    $progress_date = $_POST['progress_date'];
    
    // Get member_id from user_id
    $member_sql = "SELECT id FROM members WHERE user_id = ?";
    $stmt = $conn->prepare($member_sql);
    $stmt->bind_param("i", $logged_in_user_id);
    $stmt->execute();
    $member_result = $stmt->get_result();
    $member = $member_result->fetch_assoc();
    $member_id = $member['id'];
    
    $insert_sql = "INSERT INTO client_progress (member_id, weight, notes, progress_date) 
                   VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($insert_sql);
    $stmt->bind_param("idss", $member_id, $weight, $notes, $progress_date);
    
    if ($stmt->execute()) {
        $success_message = "Progress recorded successfully!";
        // Refresh progress data
        $progress = getClientProgress($conn, $logged_in_user_id);
    } else {
        $error_message = "Error recording progress: " . $conn->error;
    }
}

$client = getClientDetails($conn, $logged_in_user_id);
$progress = getClientProgress($conn, $logged_in_user_id);

// Calculate progress statistics
$total_entries = count($progress);
$latest_weight = $total_entries > 0 ? end($progress)['weight'] : null;
$starting_weight = $total_entries > 0 ? $progress[0]['weight'] : null;
$weight_change = $latest_weight && $starting_weight ? $latest_weight - $starting_weight : 0;
$weight_change_percentage = $starting_weight ? round(($weight_change / $starting_weight) * 100, 1) : 0;

// Prepare data for charts
$chart_labels = [];
$chart_weights = [];

foreach ($progress as $entry) {
    $chart_labels[] = date('M j', strtotime($entry['progress_date']));
    $chart_weights[] = $entry['weight'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Body Progress - BOIYETS FITNESS GYM</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        .progress-item {
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
            margin-bottom: 0.25rem;
            font-size: 0.8rem;
            color: #d1d5db;
        }
        
        .chart-container {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 1.5rem;
            height: 300px;
        }
        
        .stats-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
            transition: all 0.2s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .progress-positive {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .progress-negative {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        .progress-neutral {
            background: rgba(156, 163, 175, 0.2);
            color: #9ca3af;
            border: 1px solid rgba(156, 163, 175, 0.3);
        }
        
        .tab-button {
            background: transparent;
            color: #9ca3af;
            padding: 0.75rem 1.5rem;
            border: none;
            border-bottom: 2px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
            font-weight: 500;
            min-height: 44px;
        }
        
        .tab-button.active {
            color: #fbbf24;
            border-bottom-color: #fbbf24;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .measurement-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .measurement-item {
            background: rgba(255, 255, 255, 0.03);
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
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
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.5rem;
            }
            
            .card {
                padding: 1rem;
            }
            
            .chart-container {
                padding: 1rem;
                height: 250px;
            }
            
            .tab-button {
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
            }
            
            /* Hide hover effects on mobile */
            .card:hover {
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                transform: none;
            }
            
            .stats-card:hover {
                transform: none;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .measurement-grid {
                grid-template-columns: 1fr;
            }
            
            .tab-button {
                padding: 0.5rem 0.75rem;
                font-size: 0.8rem;
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
        <a href="myprogressclient.php" class="sidebar-item active">
          <div class="flex items-center">
            <i data-lucide="activity"></i>
            <span class="text-sm font-medium">Body Progress</span>
          </div>
          <span class="tooltip">Body Progress</span>
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
        <a href="feedbacksclient.php" class="sidebar-item">
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
            <i data-lucide="activity" class="w-8 h-8"></i>
            Weight Progress Tracking
          </h2>
        </div>
        <span class="bg-green-500 text-white px-4 py-2 rounded-full text-sm font-semibold">Records: <?php echo $total_entries; ?></span>
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

      <!-- Progress Overview Stats -->
      <?php if ($total_entries > 0): ?>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
        <div class="stats-card">
          <div class="text-3xl font-bold text-yellow-400 mb-2"><?php echo $latest_weight; ?> kg</div>
          <div class="text-gray-400">Current Weight</div>
          <div class="text-sm text-gray-500 mt-2">Latest measurement</div>
        </div>
        
        <div class="stats-card">
          <div class="text-3xl font-bold <?php echo $weight_change < 0 ? 'text-green-400' : ($weight_change > 0 ? 'text-red-400' : 'text-gray-400'); ?> mb-2">
            <?php echo ($weight_change > 0 ? '+' : '') . number_format($weight_change, 1); ?> kg
          </div>
          <div class="text-gray-400">Total Change</div>
          <div class="text-sm text-gray-500 mt-2">Since you started</div>
        </div>
        
        <div class="stats-card">
          <div class="text-3xl font-bold text-blue-400 mb-2"><?php echo $total_entries; ?></div>
          <div class="text-gray-400">Total Entries</div>
          <div class="text-sm text-gray-500 mt-2">Records tracked</div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Tabs Navigation -->
      <div class="border-b border-gray-700 mb-6">
        <div class="flex space-x-4 overflow-x-auto">
          <button class="tab-button active" data-tab="charts">
            <i data-lucide="trending-up" class="w-4 h-4 mr-2"></i>
            Progress Chart
          </button>
          <button class="tab-button" data-tab="add-entry">
            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
            Add Weight Entry
          </button>
          <button class="tab-button" data-tab="history">
            <i data-lucide="history" class="w-4 h-4 mr-2"></i>
            Weight History
          </button>
        </div>
      </div>

      <!-- Charts Tab -->
      <div class="tab-content active" id="charts">
        <?php if ($total_entries > 1): ?>
        <div class="card">
          <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
            <i data-lucide="scale" class="w-5 h-5"></i>
            Weight Progress
          </h3>
          <div class="chart-container">
            <canvas id="weightChart"></canvas>
          </div>
        </div>
        <?php else: ?>
        <div class="card text-center py-12">
          <i data-lucide="trending-up" class="w-16 h-16 text-gray-600 mx-auto mb-4"></i>
          <h3 class="text-2xl font-semibold text-gray-400 mb-4">Not Enough Data Yet</h3>
          <p class="text-gray-500 text-lg mb-6">Add at least 2 weight entries to see progress chart.</p>
        </div>
        <?php endif; ?>
      </div>

      <!-- Add Entry Tab -->
      <div class="tab-content" id="add-entry">
        <div class="card">
          <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
            <i data-lucide="plus" class="w-5 h-5"></i>
            Add Weight Entry
          </h3>
          
          <form method="POST" class="space-y-6">
            <div>
              <label class="form-label">Date</label>
              <input type="date" name="progress_date" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            
            <div>
              <label class="form-label">Weight (kg) *</label>
              <input type="number" name="weight" step="0.1" class="form-input" placeholder="70.5" required>
            </div>
            
            <div>
              <label class="form-label">Notes & Observations</label>
              <textarea name="notes" class="form-input" rows="3" placeholder="How are you feeling? Any challenges or achievements? Changes in energy levels, etc."></textarea>
            </div>
            
            <button type="submit" name="add_progress" class="w-full bg-yellow-500 hover:bg-yellow-600 text-white py-3 px-4 rounded-lg transition-colors font-semibold flex items-center justify-center gap-2">
              <i data-lucide="save" class="w-4 h-4"></i>
              Save Weight Entry
            </button>
          </form>
        </div>
      </div>

      <!-- History Tab -->
      <div class="tab-content" id="history">
        <div class="card">
          <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
            <i data-lucide="history" class="w-5 h-5"></i>
            Weight History
          </h3>
          
          <div class="space-y-4">
            <?php foreach (array_reverse($progress) as $index => $entry): ?>
              <div class="progress-item">
                <div class="flex justify-between items-start mb-4">
                  <h4 class="font-semibold text-white text-lg"><?php echo date('F j, Y', strtotime($entry['progress_date'])); ?></h4>
                  <span class="bg-blue-500 text-white px-2 py-1 rounded text-xs font-semibold">
                    <?php echo $total_entries - $index; ?><?php echo $total_entries - $index == 1 ? 'st' : ($total_entries - $index == 2 ? 'nd' : ($total_entries - $index == 3 ? 'rd' : 'th')); ?> Entry
                  </span>
                </div>
                
                <div class="measurement-grid">
                  <div class="measurement-item">
                    <div class="text-2xl font-bold text-yellow-400"><?php echo $entry['weight']; ?> kg</div>
                    <div class="text-sm text-gray-400">Weight</div>
                  </div>
                </div>
                
                <?php 
                // Calculate changes from previous entry
                $current_index = array_search($entry, $progress);
                if ($current_index > 0) {
                  $previous_entry = $progress[$current_index - 1];
                  $weight_change = $entry['weight'] - $previous_entry['weight'];
                  $change_class = $weight_change < 0 ? 'progress-positive' : ($weight_change > 0 ? 'progress-negative' : 'progress-neutral');
                  $change_icon = $weight_change < 0 ? 'trending-down' : ($weight_change > 0 ? 'trending-up' : 'minus');
                ?>
                  <div class="mt-3 p-3 bg-gray-800 rounded-lg">
                    <div class="flex items-center gap-2 text-sm font-semibold <?php echo str_replace('progress-', 'text-', $change_class); ?>">
                      <i data-lucide="<?php echo $change_icon; ?>" class="w-4 h-4"></i>
                      Weight Change: <?php echo ($weight_change > 0 ? '+' : '') . number_format($weight_change, 1); ?> kg
                    </div>
                  </div>
                <?php } ?>
                
                <?php if (!empty($entry['notes'])): ?>
                  <div class="mt-3 p-3 bg-gray-800 rounded-lg">
                    <p class="text-sm text-gray-300"><?php echo htmlspecialchars($entry['notes']); ?></p>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
            
            <?php if (empty($progress)): ?>
              <div class="text-center py-8 text-gray-500">
                <i data-lucide="activity" class="w-16 h-16 mx-auto mb-4"></i>
                <p class="text-lg">No weight entries yet.</p>
                <p class="text-sm">Start tracking your fitness journey by adding your first weight entry!</p>
              </div>
            <?php endif; ?>
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
    <a href="myprogressclient.php" class="mobile-nav-item active">
      <i data-lucide="activity"></i>
      <span class="mobile-nav-label">Progress</span>
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
      <a href="myprogressclient.php" class="sidebar-item active" onclick="closeMobileSidebar()">
        <div class="flex items-center">
          <i data-lucide="activity"></i>
          <span class="text-sm font-medium">Body Progress</span>
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
      <a href="feedbacksclient.php" class="sidebar-item" onclick="closeMobileSidebar()">
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

      // Tab functionality
      const tabButtons = document.querySelectorAll('.tab-button');
      const tabContents = document.querySelectorAll('.tab-content');
      
      tabButtons.forEach(button => {
        button.addEventListener('click', () => {
          const tabId = button.getAttribute('data-tab');
          
          // Update active tab button
          tabButtons.forEach(btn => btn.classList.remove('active'));
          button.classList.add('active');
          
          // Show active tab content
          tabContents.forEach(content => content.classList.remove('active'));
          document.getElementById(tabId).classList.add('active');
        });
      });

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
    
    <?php if ($total_entries > 1): ?>
    // Initialize Weight Chart
    const weightChart = new Chart(
      document.getElementById('weightChart'),
      {
        type: 'line',
        data: {
          labels: <?php echo json_encode($chart_labels); ?>,
          datasets: [{
            label: 'Weight (kg)',
            data: <?php echo json_encode($chart_weights); ?>,
            borderColor: '#fbbf24',
            backgroundColor: 'rgba(251, 191, 36, 0.1)',
            borderWidth: 3,
            fill: true,
            tension: 0.4,
            pointBackgroundColor: '#fbbf24',
            pointBorderColor: '#ffffff',
            pointBorderWidth: 2,
            pointRadius: 5,
            pointHoverRadius: 7
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              labels: {
                color: '#e2e8f0',
                font: {
                  size: 14
                }
              }
            },
            tooltip: {
              backgroundColor: 'rgba(26, 26, 26, 0.9)',
              titleColor: '#fbbf24',
              bodyColor: '#e2e8f0',
              borderColor: '#fbbf24',
              borderWidth: 1
            }
          },
          scales: {
            x: {
              grid: {
                color: 'rgba(255, 255, 255, 0.1)'
              },
              ticks: {
                color: '#9ca3af'
              }
            },
            y: {
              grid: {
                color: 'rgba(255, 255, 255, 0.1)'
              },
              ticks: {
                color: '#9ca3af',
                callback: function(value) {
                  return value + ' kg';
                }
              }
            }
          }
        }
      }
    );
    <?php endif; ?>
  </script>
</body>
</html>
<?php $conn->close(); ?>



