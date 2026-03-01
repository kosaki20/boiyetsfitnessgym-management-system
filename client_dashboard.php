<?php
session_start();

// Skip login check - for development only
// if (!isset($_SESSION['username']) || $_SESSION['role'] != 'client') {
//     header("Location: index.php");
//     exit();
// }

// Auto-set session for direct access
$_SESSION['username'] = 'client';
$_SESSION['role'] = 'client';
$_SESSION['user_id'] = 2;

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

$logged_in_user_id = $_SESSION['user_id'];
$client_type = $_SESSION['client_type'] ?? 'walk-in'; // Keep for reference but don't restrict

// ADD THIS SECTION FOR CHAT FUNCTIONALITY
require_once 'chat_functions.php';
$unread_count = getUnreadCount($_SESSION['user_id'], $conn);

// Notification functionality for clients
function getClientNotifications($conn, $user_id) {
    $notifications = [];
    try {
        // Get notifications for the client
        $sql = "SELECT * FROM notifications 
                WHERE (user_id = ? AND role = 'client') 
                OR (role IS NULL AND user_id IS NULL)
                ORDER BY created_at DESC 
                LIMIT 10";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $notifications[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error fetching notifications: " . $e->getMessage());
        $notifications = [];
    }
    return $notifications;
}

function getUnreadNotificationCount($conn, $user_id) {
    try {
        $sql = "SELECT COUNT(*) as count FROM notifications 
                WHERE ((user_id = ? AND role = 'client') 
                OR (role IS NULL AND user_id IS NULL))
                AND read_status = 0";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row['count'];
    } catch (Exception $e) {
        error_log("Error fetching unread notification count: " . $e->getMessage());
        return 0;
    }
}

// Get notification data
$notification_count = getUnreadNotificationCount($conn, $logged_in_user_id);
$notifications = getClientNotifications($conn, $logged_in_user_id);

// Add mobile-specific caching headers if needed
if (strpos($_SERVER['HTTP_USER_AGENT'], 'Mobile') !== false) {
    header("Cache-Control: max-age=300"); // 5 minutes for mobile
}

// Function to get client details by user_id
function getClientDetails($conn, $user_id) {
    $sql = "SELECT m.* FROM members m 
            INNER JOIN users u ON m.user_id = u.id 
            WHERE u.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Function to get client's workout plans (available for all clients)
function getClientWorkoutPlans($conn, $user_id) {
    $workoutPlans = [];
    try {
        $sql = "SELECT wp.* FROM workout_plans wp 
                INNER JOIN members m ON wp.member_id = m.id 
                INNER JOIN users u ON m.user_id = u.id 
                WHERE u.id = ? ORDER BY wp.created_at DESC LIMIT 3";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $row['exercises'] = json_decode($row['exercises'], true);
            $workoutPlans[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error fetching workout plans: " . $e->getMessage());
        $workoutPlans = [];
    }
    return $workoutPlans;
}

// Function to get client's meal plans (available for all clients)
function getClientMealPlans($conn, $user_id) {
    $mealPlans = [];
    try {
        $sql = "SELECT mp.* FROM meal_plans mp 
                INNER JOIN members m ON mp.member_id = m.id 
                INNER JOIN users u ON m.user_id = u.id 
                WHERE u.id = ? ORDER BY mp.created_at DESC LIMIT 3";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $row['meals'] = json_decode($row['meals'], true);
            $mealPlans[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error fetching meal plans: " . $e->getMessage());
        $mealPlans = [];
    }
    return $mealPlans;
}

// Function to get client progress history (available for all clients)
function getClientProgress($conn, $user_id) {
    $progress = [];
    try {
        $sql = "SELECT cp.* FROM client_progress cp 
                INNER JOIN members m ON cp.member_id = m.id 
                INNER JOIN users u ON m.user_id = u.id 
                WHERE u.id = ? ORDER BY cp.progress_date DESC LIMIT 3";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $progress[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error fetching client progress: " . $e->getMessage());
        $progress = [];
    }
    return $progress;
}

// Function to get client attendance (available for both types)
function getClientAttendance($conn, $user_id) {
    try {
        $sql = "SELECT COUNT(*) as visit_count FROM attendance a 
                INNER JOIN members m ON a.member_id = m.id 
                INNER JOIN users u ON m.user_id = u.id 
                WHERE u.id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc()['visit_count'];
    } catch (Exception $e) {
        error_log("Error fetching client attendance: " . $e->getMessage());
        return 0;
    }
}

// Function to get active announcements for clients
function getClientAnnouncements($conn) {
    $announcements = [];
    try {
        $column_check = $conn->query("SHOW COLUMNS FROM announcements LIKE 'target_audience'");
        
        if ($column_check->num_rows > 0) {
            $result = $conn->query("SELECT * FROM announcements WHERE (expiry_date IS NULL OR expiry_date >= CURDATE()) AND (target_audience = 'all' OR target_audience = 'clients') ORDER BY created_at DESC LIMIT 3");
        } else {
            $result = $conn->query("SELECT * FROM announcements WHERE (expiry_date IS NULL OR expiry_date >= CURDATE()) ORDER BY created_at DESC LIMIT 3");
        }
        
        while ($row = $result->fetch_assoc()) {
            $announcements[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error fetching announcements: " . $e->getMessage());
        $announcements = [];
    }
    return $announcements;
}

// Function to get client's membership status
function getClientMembershipStatus($conn, $user_id) {
    try {
        $sql = "SELECT m.membership_plan, m.expiry_date, m.status
                FROM members m 
                INNER JOIN users u ON m.user_id = u.id 
                WHERE u.id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    } catch (Exception $e) {
        error_log("Error fetching membership status: " . $e->getMessage());
        return null;
    }
}

// Get all data
$client = getClientDetails($conn, $logged_in_user_id);
$workoutPlans = getClientWorkoutPlans($conn, $logged_in_user_id);
$mealPlans = getClientMealPlans($conn, $logged_in_user_id);
$progress = getClientProgress($conn, $logged_in_user_id);
$attendanceCount = getClientAttendance($conn, $logged_in_user_id);
$announcements = getClientAnnouncements($conn);
$membership = getClientMembershipStatus($conn, $logged_in_user_id);

// Calculate days until expiry
if ($membership) {
    $expiry = new DateTime($membership['expiry_date']);
    $today = new DateTime();
    $daysLeft = $today->diff($expiry)->days;
    if ($today > $expiry) {
        $daysLeft = -$daysLeft;
    }
    $membership['days_left'] = $daysLeft;
}

// If client not found
if (!$client) {
    $username = $_SESSION['username'];
    $sql = "SELECT m.* FROM members m 
            INNER JOIN users u ON m.user_id = u.id 
            WHERE u.username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $client = $result->fetch_assoc();
    
    if (!$client) {
        $client = [
            'full_name' => $_SESSION['username'],
            'member_type' => 'walk-in'
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BOIYETS FITNESS GYM - Client Dashboard</title>
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
      display: none;
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
      padding: 1rem;
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.05);
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
      transition: all 0.2s ease;
      will-change: transform;
      position: relative;
    }
    
    .card:hover {
      box-shadow: 0 10px 15px rgba(0, 0, 0, 0.2);
      transform: translateY(-2px);
    }
    
    .card-title {
      font-size: 0.9rem;
      font-weight: 600;
      color: #fbbf24;
      margin-bottom: 0.5rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    
    .card-value {
      font-size: 1.5rem;
      font-weight: 700;
      color: #f8fafc;
    }
    
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 0.75rem;
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
    
    .announcement-item {
      border-left: 4px solid #fbbf24;
      padding: 1rem;
      margin-bottom: 1rem;
      background: rgba(255, 255, 255, 0.05);
      border-radius: 8px;
      transition: all 0.2s ease;
    }
    
    .announcement-item:hover {
      background: rgba(255, 255, 255, 0.08);
    }
    
    .announcement-item.urgent {
      border-left-color: #ef4444;
    }
    
    .announcement-item.info {
      border-left-color: #3b82f6;
    }
    
    .announcement-title {
      font-weight: 600;
      color: #fbbf24;
      margin-bottom: 0.5rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    
    .announcement-date {
      font-size: 0.8rem;
      color: #9ca3af;
      margin-bottom: 0.5rem;
    }
    
    .announcement-content {
      color: #d1d5db;
      line-height: 1.5;
    }
    
    .priority-badge {
      padding: 0.25rem 0.5rem;
      border-radius: 20px;
      font-size: 0.7rem;
      font-weight: 500;
    }
    
    .priority-high {
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
    }
    
    .priority-medium {
      background: rgba(245, 158, 11, 0.2);
      color: #f59e0b;
    }
    
    .priority-low {
      background: rgba(16, 185, 129, 0.2);
      color: #10b981;
    }

    .empty-state {
      text-align: center;
      padding: 3rem 1rem;
      color: #6b7280;
    }
    
    .empty-state i {
      margin-bottom: 1rem;
      opacity: 0.5;
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
        display: none !important;
      }
      
      .mobile-sidebar {
        display: block;
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
      
      .card-value {
        font-size: 1.25rem;
      }
      
      .announcement-item {
        padding: 0.75rem;
      }
      
      /* Hide hover effects on mobile */
      .card:hover {
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        transform: none;
      }
      
      .announcement-item:hover {
        background: rgba(255, 255, 255, 0.05);
      }
    }
    
    @media (max-width: 480px) {
      .stats-grid {
        grid-template-columns: 1fr;
      }
      
      .card-title {
        font-size: 0.8rem;
      }
      
      .card-value {
        font-size: 1.1rem;
      }
    }

    /* Loading skeleton */
    .skeleton {
      background: linear-gradient(90deg, #2d2d2d 25%, #3d3d3d 50%, #2d2d2d 75%);
      background-size: 200% 100%;
      animation: loading 1.5s infinite;
    }
    
    @keyframes loading {
      0% { background-position: 200% 0; }
      100% { background-position: -200% 0; }
    }

    /* Dropdown Styles */
    .dropdown-container {
      position: relative;
    }
    
    .dropdown {
      position: absolute;
      right: 0;
      top: 100%;
      margin-top: 0.5rem;
      background: #1a1a1a;
      border: 1px solid #374151;
      border-radius: 8px;
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5), 0 10px 10px -5px rgba(0, 0, 0, 0.4);
      z-index: 1000;
      min-width: 200px;
      backdrop-filter: blur(10px);
    }
    
    .notification-dropdown {
      width: 380px;
      max-width: 90vw;
    }
    
    .user-dropdown {
      width: 240px;
    }
    
    .notification-item {
      padding: 0.75rem 1rem;
      border-bottom: 1px solid #374151;
      cursor: pointer;
      transition: background-color 0.2s ease;
    }
    
    .notification-item:hover {
      background: rgba(255, 255, 255, 0.05);
    }
    
    .notification-item:last-child {
      border-bottom: none;
    }
    
    .line-clamp-2 {
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    /* Toast notification */
    .toast {
      position: fixed;
      top: 20px;
      right: 20px;
      padding: 1rem 1.5rem;
      border-radius: 8px;
      color: white;
      z-index: 1000;
      max-width: 400px;
      box-shadow: 0 10px 15px rgba(0, 0, 0, 0.3);
      transform: translateX(400px);
      transition: transform 0.3s ease;
    }
    
    .toast.show {
      transform: translateX(0);
    }
    
    .toast.success {
      background: #10b981;
    }
    
    .toast.error {
      background: #ef4444;
    }
    
    .toast.warning {
      background: #f59e0b;
    }
    
    .toast.info {
      background: #3b82f6;
    }
  </style>
</head>
<body class="min-h-screen js-enabled">
  <!-- Toast Notification Container -->
  <div id="toastContainer"></div>

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

  <!-- Main Layout Container -->
  <div class="flex h-[calc(100vh-64px)]">
    <!-- Desktop Sidebar - Only for desktop -->
    <aside id="desktopSidebar" class="desktop-sidebar sidebar w-60 bg-[#0d0d0d] p-3 space-y-2 flex flex-col border-r border-gray-800" aria-label="Main navigation">
      <nav class="space-y-1 flex-1">
        <a href="client_dashboard.php" class="sidebar-item active">
          <div class="flex items-center">
            <i data-lucide="home"></i>
            <span class="text-sm font-medium">Dashboard</span>
          </div>
          <span class="tooltip">Dashboard</span>
        </a>

        <!-- Workout Plans Section - Available for all clients -->
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

        <!-- Nutrition Plans Section - Available for all clients -->
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

        <!-- Progress Tracking - Available for all clients -->
        <a href="myprogressclient.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="activity"></i>
            <span class="text-sm font-medium">My Progress</span>
          </div>
          <span class="tooltip">My Progress</span>
        </a>

        <!-- Attendance - Available for all -->
        <a href="attendanceclient.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="calendar"></i>
            <span class="text-sm font-medium">Attendance</span>
          </div>
          <span class="tooltip">Attendance</span>
        </a>

        <!-- Membership - Available for all -->
        <a href="membershipclient.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="id-card"></i>
            <span class="text-sm font-medium">Membership</span>
          </div>
          <span class="tooltip">Membership</span>
        </a>

        <!-- Feedbacks - Available for all -->
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
    <main id="mainContent" class="main-content flex-1 p-4 space-y-4 overflow-auto">
      <!-- Welcome Section -->
      <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
        <div>
          <h1 class="text-2xl font-bold text-yellow-400">Client Dashboard</h1>
          <p class="text-gray-400">Welcome back, <?php echo isset($client['full_name']) ? htmlspecialchars($client['full_name']) : htmlspecialchars($_SESSION['username']); ?></p>
          <p class="text-sm text-gray-500 mt-1">
            <?php if ($client_type === 'walk-in'): ?>
              <i data-lucide="user" class="w-4 h-4 inline mr-1"></i>
              Walk-in Client
            <?php else: ?>
              <i data-lucide="star" class="w-4 h-4 inline mr-1 text-yellow-400"></i>
              Full-time Client
            <?php endif; ?>
          </p>
        </div>
        <div class="text-right">
          <p class="text-sm text-gray-400">Today is</p>
          <p class="font-semibold"><?php echo date('l, F j, Y'); ?></p>
        </div>
      </div>

      <!-- Stats Cards -->
      <div class="stats-grid">
        <div class="card">
          <p class="card-title"><i data-lucide="dumbbell"></i><span>Workout Plans</span></p>
          <p class="card-value"><?php echo count($workoutPlans); ?></p>
          <p class="text-xs text-green-400 mt-1 flex items-center"><i data-lucide="trending-up" class="w-3 h-3 mr-1"></i>Active plans</p>
        </div>
        <div class="card">
          <p class="card-title"><i data-lucide="utensils"></i><span>Meal Plans</span></p>
          <p class="card-value"><?php echo count($mealPlans); ?></p>
          <p class="text-xs text-gray-400 mt-1">Nutrition guides</p>
        </div>
        
       
        <div class="card border-l-4 border-red-500">
          <p class="card-title"><i data-lucide="alert-triangle"></i><span>Days Left</span></p>
          <p class="card-value"><?php echo ($membership && isset($membership['days_left']) && $membership['days_left'] > 0) ? $membership['days_left'] : '0'; ?></p>
          <p class="text-xs text-red-400 mt-1 flex items-center"><i data-lucide="clock" class="w-3 h-3 mr-1"></i>Membership</p>
        </div>
      </div>

      <!-- Main Content Grid -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Announcements & Recent Activity -->
        <div class="lg:col-span-2 space-y-6">
          <!-- Announcements - Available for all -->
          <div class="card">
            <div class="flex justify-between items-center mb-4">
              <h2 class="card-title"><i data-lucide="megaphone"></i> Announcements</h2>
              <span class="text-xs text-gray-500"><?php echo count($announcements); ?> announcements</span>
            </div>
            
            <div id="announcementsList">
              <?php foreach ($announcements as $announcement): ?>
                <div class="announcement-item <?php echo isset($announcement['priority']) && $announcement['priority'] === 'high' ? 'urgent' : ''; ?>">
                  <div class="announcement-title">
                    <?php echo htmlspecialchars($announcement['title']); ?>
                    <?php if (isset($announcement['priority'])): ?>
                      <span class="priority-badge priority-<?php echo $announcement['priority']; ?>">
                        <?php echo ucfirst($announcement['priority']); ?>
                      </span>
                    <?php endif; ?>
                  </div>
                  <div class="announcement-date">
                    <?php echo date('M j, Y', strtotime($announcement['created_at'])); ?>
                    <?php if (isset($announcement['expiry_date'])): ?>
                      • Expires: <?php echo date('M j, Y', strtotime($announcement['expiry_date'])); ?>
                    <?php endif; ?>
                  </div>
                  <div class="announcement-content">
                    <?php echo htmlspecialchars($announcement['content']); ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            
            <?php if (empty($announcements)): ?>
              <div class="empty-state">
                <i data-lucide="megaphone" class="w-12 h-12 mx-auto"></i>
                <p>No announcements</p>
              </div>
            <?php endif; ?>
          </div>

          <!-- Recent Activity -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Recent Workouts - Available for all clients -->
            <div class="card">
              <div class="flex justify-between items-center mb-4">
                <h2 class="card-title"><i data-lucide="dumbbell"></i> Recent Workouts</h2>
                <a href="workoutplansclient.php" class="text-xs text-yellow-400 hover:underline">View All</a>
              </div>
              <div class="space-y-3">
                <?php foreach ($workoutPlans as $plan): ?>
                  <div class="flex justify-between items-center p-3 bg-gray-800 rounded-lg">
                    <div>
                      <p class="font-medium text-sm"><?php echo htmlspecialchars($plan['plan_name']); ?></p>
                      <p class="text-xs text-gray-400 mt-1">
                        <?php echo isset($plan['exercises']) && is_array($plan['exercises']) ? count($plan['exercises']) . ' exercises' : 'No exercises'; ?>
                      </p>
                    </div>
                    <div class="text-right">
                      <p class="text-xs text-gray-400"><?php echo date('M j', strtotime($plan['created_at'])); ?></p>
                    </div>
                  </div>
                <?php endforeach; ?>
                <?php if (empty($workoutPlans)): ?>
                  <div class="empty-state">
                    <i data-lucide="dumbbell" class="w-12 h-12 mx-auto opacity-50"></i>
                    <p class="text-gray-400 mt-2">No workout plans yet</p>
                    <a href="workoutplansclient.php" class="text-yellow-400 text-sm mt-2 inline-block">Get started</a>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Recent Progress - Available for all clients -->
            <div class="card">
              <div class="flex justify-between items-center mb-4">
                <h2 class="card-title"><i data-lucide="activity"></i> Recent Progress</h2>
                <a href="myprogressclient.php" class="text-xs text-yellow-400 hover:underline">View All</a>
              </div>
              <div class="space-y-3">
                <?php foreach ($progress as $entry): ?>
                  <div class="flex justify-between items-center p-3 bg-gray-800 rounded-lg">
                    <div>
                      <p class="font-medium text-sm"><?php echo date('M j, Y', strtotime($entry['progress_date'])); ?></p>
                      <p class="text-xs text-gray-400 mt-1">
                        <?php 
                          $details = [];
                          if (isset($entry['weight'])) $details[] = "Weight: {$entry['weight']}kg";
                          if (isset($entry['body_fat'])) $details[] = "Fat: {$entry['body_fat']}%";
                          echo implode(' • ', $details);
                        ?>
                      </p>
                    </div>
                  </div>
                <?php endforeach; ?>
                <?php if (empty($progress)): ?>
                  <p class="text-gray-400 text-center py-4">No progress records</p>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <!-- Membership Status - Available for all -->
        <?php if ($membership): ?>
        <div class="space-y-6">
          <div class="card">
            <h2 class="card-title"><i data-lucide="id-card"></i> Membership Status</h2>
            <div class="space-y-3 text-sm">
              <div class="flex justify-between items-center">
                <span class="text-gray-400">Plan:</span>
                <span class="font-semibold"><?php echo ucfirst($membership['membership_plan']); ?></span>
              </div>
              <div class="flex justify-between items-center">
                <span class="text-gray-400">Status:</span>
                <span class="font-semibold <?php echo $membership['status'] === 'active' ? 'text-green-400' : 'text-red-400'; ?>">
                  <?php echo ucfirst($membership['status']); ?>
                </span>
              </div>
              <div class="flex justify-between items-center">
                <span class="text-gray-400">Expires:</span>
                <span class="font-semibold"><?php echo date('M j, Y', strtotime($membership['expiry_date'])); ?></span>
              </div>
              <?php if ($membership['days_left'] <= 7 && $membership['days_left'] > 0): ?>
                <div class="mt-3 p-3 bg-yellow-500/20 border border-yellow-500/30 rounded-lg">
                  <p class="text-yellow-300 text-sm font-semibold">Your membership expires in <?php echo $membership['days_left']; ?> days</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </main>
  </div>

  <!-- Mobile Bottom Navigation -->
  <nav class="mobile-nav" aria-label="Mobile navigation">
    <a href="client_dashboard.php" class="mobile-nav-item active">
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
    <a href="myprogressclient.php" class="mobile-nav-item">
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
      <a href="client_dashboard.php" class="sidebar-item active" onclick="closeMobileSidebar()">
        <div class="flex items-center">
          <i data-lucide="home"></i>
          <span class="text-sm font-medium">Dashboard</span>
        </div>
      </a>

      <!-- Workout Plans Section - Available for all clients -->
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

      <!-- Nutrition Plans Section - Available for all clients -->
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

      <!-- Progress Tracking - Available for all clients -->
      <a href="myprogressclient.php" class="sidebar-item" onclick="closeMobileSidebar()">
        <div class="flex items-center">
          <i data-lucide="activity"></i>
          <span class="text-sm font-medium">My Progress</span>
        </div>
      </a>

      <!-- Attendance - Available for all -->
      <a href="attendanceclient.php" class="sidebar-item" onclick="closeMobileSidebar()">
        <div class="flex items-center">
          <i data-lucide="calendar"></i>
          <span class="text-sm font-medium">Attendance</span>
        </div>
      </a>

      <!-- Membership - Available for all -->
      <a href="membershipclient.php" class="sidebar-item" onclick="closeMobileSidebar()">
        <div class="flex items-center">
          <i data-lucide="id-card"></i>
          <span class="text-sm font-medium">Membership</span>
        </div>
      </a>

      <!-- Feedbacks - Available for all -->
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

      // Initialize dropdown functionality
      setupDropdowns();

      // Update mobile nav active state
      updateMobileNavActive();
    });

    // Enhanced notification functionality
    function setupDropdowns() {
        const notificationBell = document.getElementById('notificationBell');
        const notificationDropdown = document.getElementById('notificationDropdown');
        const userMenuButton = document.getElementById('userMenuButton');
        const userDropdown = document.getElementById('userDropdown');
        
        // Close all dropdowns
        function closeAllDropdowns() {
            notificationDropdown.classList.add('hidden');
            userDropdown.classList.add('hidden');
        }
        
        // Toggle notification dropdown
        notificationBell.addEventListener('click', function(e) {
            e.stopPropagation();
            const isHidden = notificationDropdown.classList.contains('hidden');
            
            closeAllDropdowns();
            
            if (isHidden) {
                notificationDropdown.classList.remove('hidden');
            }
        });
        
        // Toggle user dropdown
        userMenuButton.addEventListener('click', function(e) {
            e.stopPropagation();
            const isHidden = userDropdown.classList.contains('hidden');
            
            closeAllDropdowns();
            
            if (isHidden) {
                userDropdown.classList.remove('hidden');
            }
        });
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!notificationDropdown.contains(e.target) && !notificationBell.contains(e.target) &&
                !userDropdown.contains(e.target) && !userMenuButton.contains(e.target)) {
                closeAllDropdowns();
            }
        });
        
        // Mark all as read
        document.getElementById('markAllRead')?.addEventListener('click', function(e) {
            e.stopPropagation();
            markAllNotificationsAsRead();
        });
        
        // Close dropdowns when pressing Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAllDropdowns();
            }
        });
    }

    // AJAX function to mark all notifications as read
    function markAllNotificationsAsRead() {
        fetch('notification_ajax.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=mark_all_read&user_id=<?php echo $logged_in_user_id; ?>&role=client'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Hide notification badge
                document.getElementById('notificationBadge').classList.add('hidden');
                // Refresh the page to update notifications
                location.reload();
            }
        })
        .catch(error => console.error('Error:', error));
    }

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

    // Toast notification system
    function showToast(message, type = 'success', duration = 5000) {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <div class="flex items-center gap-2">
                <i data-lucide="${getToastIcon(type)}" class="w-4 h-4"></i>
                <span>${message}</span>
            </div>
        `;
        
        container.appendChild(toast);
        
        // Animate in
        setTimeout(() => toast.classList.add('show'), 100);
        
        // Remove after duration
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, duration);
        
        lucide.createIcons();
    }

    function getToastIcon(type) {
        const icons = {
            success: 'check-circle',
            error: 'x-circle',
            warning: 'alert-triangle',
            info: 'info'
        };
        return icons[type] || 'info';
    }
  </script>
</body>
</html>
<?php
$conn->close();
?>



