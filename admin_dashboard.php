<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Skip login check - for development only
// if (!isset($_SESSION['username']) || $_SESSION['role'] != 'admin') {
//     header("Location: index.php");
//     exit();
// }

// Auto-set session for direct access
$_SESSION['username'] = 'admin';
$_SESSION['role'] = 'admin';
$_SESSION['user_id'] = 1;

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "boiyetsdb";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Maintenance Alerts for Dashboard
$maintenance_stats_sql = "SELECT 
    COUNT(*) as total_issues,
    SUM(CASE WHEN status = 'Needs Maintenance' THEN 1 ELSE 0 END) as needs_maintenance,
    SUM(CASE WHEN status = 'Under Repair' THEN 1 ELSE 0 END) as under_repair,
    SUM(CASE WHEN status = 'Broken' THEN 1 ELSE 0 END) as broken
FROM equipment 
WHERE status IN ('Needs Maintenance', 'Under Repair', 'Broken')";

$maintenance_stats_result = $conn->query($maintenance_stats_sql);
$maintenance_stats = $maintenance_stats_result->fetch_assoc();
$total_maintenance_issues = $maintenance_stats['total_issues'];

// Get recent maintenance items
$recent_maintenance_sql = "SELECT name, status, last_updated 
                          FROM equipment 
                          WHERE status IN ('Needs Maintenance', 'Under Repair', 'Broken') 
                          ORDER BY last_updated DESC 
                          LIMIT 3";
$recent_maintenance_result = $conn->query($recent_maintenance_sql);

// Get user data with profile picture
$user_id = $_SESSION['user_id'];
$sql = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!isset($_SESSION['profile_picture']) && isset($user['profile_picture'])) {
    $_SESSION['profile_picture'] = $user['profile_picture'];
}

// Chat functionality
require_once 'chat_functions.php';
$unread_count = getUnreadCount($_SESSION['user_id'], $conn);

// Notification functions
require_once 'notification_functions.php';
$notification_count = getUnreadNotificationCount($conn, $user_id);
$notifications = getAdminNotifications($conn);

// Handle form submissions
$user_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_user'])) {
    $new_username = mysqli_real_escape_string($conn, $_POST['new_username']);
    $new_password = mysqli_real_escape_string($conn, $_POST['new_password']);
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    
    // Check if username already exists
    $checkUser = $conn->query("SELECT id FROM users WHERE username = '$new_username'");
    if ($checkUser->num_rows > 0) {
        $user_message = "Error: Username already exists!";
    } else {
        $sql = "INSERT INTO users (username, password, role, full_name, email) 
                VALUES ('$new_username', MD5('$new_password'), 'trainer', '$full_name', '$email')";
        
        if ($conn->query($sql)) {
            $user_message = "Trainer account created successfully!";
            
            // Create notification for the action
            createNotification($conn, null, 'admin', 'New Trainer Created', 
                "Trainer account created for $full_name", 'system', 'medium');
        } else {
            $user_message = "Error creating trainer account: " . $conn->error;
        }
    }
}

// Fetch data for statistics
$users_result = $conn->query("SELECT COUNT(*) as total FROM users WHERE role != 'admin'");
$products_result = $conn->query("SELECT COUNT(*) as total FROM products");
$feedback_result = $conn->query("SELECT COUNT(*) as total FROM feedback");
$revenue_result = $conn->query("SELECT SUM(total) as total_revenue FROM sales WHERE DATE(sold_at) = CURDATE()");
$members_result = $conn->query("SELECT COUNT(*) as total FROM members");
$attendance_result = $conn->query("SELECT COUNT(DISTINCT member_id) as total FROM attendance WHERE DATE(check_in) = CURDATE()");

$total_users = $users_result->fetch_assoc()['total'] ?? 0;
$total_products = $products_result->fetch_assoc()['total'] ?? 0;
$total_feedback = $feedback_result->fetch_assoc()['total'] ?? 0;
$revenue_today = $revenue_result->fetch_assoc()['total_revenue'] ?? 0;
$total_members = $members_result->fetch_assoc()['total'] ?? 0;
$attendance_today = $attendance_result->fetch_assoc()['total'] ?? 0;

// Get expiring memberships (within 3 days)
$expiring_members = $conn->query("
    SELECT COUNT(*) as count 
    FROM members 
    WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY) 
    AND status = 'active'
")->fetch_assoc()['count'] ?? 0;

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BOIYETS FITNESS GYM - Admin Dashboard</title>
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
    
    .sidebar-collapsed .sidebar-item .text-sm { 
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
      color: #9ca3af;
      padding: 0.6rem 0.8rem;
      border-radius: 8px;
      cursor: pointer;
      transition: all 0.2s ease;
      margin-bottom: 0.25rem;
      text-decoration: none;
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
      text-decoration: none;
    }
    
    .sidebar-item i { 
      width: 18px; 
      height: 18px; 
      stroke-width: 1.75; 
      flex-shrink: 0; 
      margin-right: 0.75rem; 
    }

    .card {
      background: rgba(26, 26, 26, 0.7);
      border-radius: 12px;
      padding: 1rem;
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.05);
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
      transition: all 0.2s ease;
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
    
    .topbar {
      background: rgba(13, 13, 13, 0.95);
      backdrop-filter: blur(10px);
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      position: relative;
      z-index: 100;
    }
    
    .table-container {
      background: rgba(26, 26, 26, 0.7);
      border-radius: 12px;
      overflow: hidden;
      border: 1px solid rgba(255, 255, 255, 0.05);
    }
    
    table {
      width: 100%;
      border-collapse: collapse;
    }
    
    th {
      background: rgba(251, 191, 36, 0.1);
      color: #fbbf24;
      padding: 1rem;
      text-align: left;
      font-weight: 600;
      font-size: 0.875rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    td {
      padding: 1rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
      font-size: 0.875rem;
    }
    
    tr:hover {
      background: rgba(255, 255, 255, 0.02);
    }
    
    .form-input {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 8px;
      padding: 0.5rem 0.75rem;
      color: white;
      width: 100%;
      transition: all 0.2s ease;
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
    
    .alert {
      padding: 1rem;
      border-radius: 8px;
      margin-bottom: 1rem;
      border-left: 4px solid;
    }
    
    .alert-success {
      background: rgba(16, 185, 129, 0.1);
      border-left-color: #10b981;
      color: #10b981;
    }
    
    .alert-error {
      background: rgba(239, 68, 68, 0.1);
      border-left-color: #ef4444;
      color: #ef4444;
    }
    
    .chart-container {
      width: 100%;
      height: 200px;
    }
    
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 0.75rem;
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

    .button-sm { 
      padding: 0.5rem 0.75rem; 
      font-size: 0.8rem; 
      border-radius: 8px;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 0.4rem;
      transition: all 0.2s ease;
    }
    
    .button-sm:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
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

    /* Mobile optimizations */
    @media (max-width: 768px) {
      .sidebar {
        position: fixed;
        height: 100vh;
        z-index: 1000;
        transform: translateX(-100%);
        transition: transform 0.3s ease;
      }
      
      .sidebar.mobile-open {
        transform: translateX(0);
      }
      
      .sidebar-collapsed {
        transform: translateX(-100%);
      }
      
      .sidebar-item:hover .tooltip {
        display: none !important;
      }
      
      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
      }
      
      .dropdown {
        position: fixed;
        left: 50%;
        transform: translateX(-50%);
        width: 90vw;
        max-width: 400px;
      }
    }

    /* Compact dashboard styles */
    .compact-card {
      padding: 0.75rem;
    }
    
    .compact-table th,
    .compact-table td {
      padding: 0.5rem;
      font-size: 0.75rem;
    }
    
    .compact-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
      gap: 0.5rem;
    }
    
    .compact-value {
      font-size: 1.25rem;
    }
    
    .compact-title {
      font-size: 0.8rem;
      margin-bottom: 0.25rem;
    }
    
    .section-header {
      margin-bottom: 0.5rem;
    }

    /* Fix for sidebar links */
    .sidebar-link {
      display: flex;
      align-items: center;
      width: 100%;
      color: inherit;
      text-decoration: none;
    }
    
    .sidebar-link:hover {
      color: inherit;
      text-decoration: none;
    }
  </style>
</head>
<body class="min-h-screen">

  <!-- Toast Notification Container -->
  <div id="toastContainer"></div>

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
                        <?php if ($notification['priority'] === 'high'): ?>
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
          <img src="<?php echo htmlspecialchars($_SESSION['profile_picture'] ?? $user['profile_picture'] ?? 'https://i.pravatar.cc/40'); ?>" class="w-8 h-8 rounded-full border border-gray-600" id="userAvatar" />
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

  <div class="flex">
    <!-- Sidebar -->
    <aside id="sidebar" class="sidebar w-60 bg-[#0d0d0d] h-screen p-3 space-y-2 flex flex-col overflow-y-auto border-r border-gray-800">
      <nav class="space-y-1 flex-1">
        <a href="admin_dashboard.php" class="sidebar-item active">
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
            <span class="text-sm font-medium">Revenue Tracking</span>
          </div>
          <span class="tooltip">Revenue Tracking</span>
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

        <a href="equipment_monitoring.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="wrench"></i>
            <span class="text-sm font-medium">Equipment Monitoring</span>
          </div>
          <span class="tooltip">Equipment Monitoring</span>
        </a>

        <a href="maintenance_report.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="alert-triangle"></i>
            <span class="text-sm font-medium">Maintenance Report</span>
          </div>
          <span class="tooltip">Maintenance Report</span>
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

    <!-- Main Content -->
    <main class="flex-1 p-4 space-y-4 overflow-auto">
      <!-- Header -->
      <div class="flex justify-between items-center mb-4">
        <h1 class="text-xl font-bold text-yellow-400 flex items-center gap-2">
          <i data-lucide="home"></i>
          Admin Dashboard
        </h1>
        <div class="text-sm text-gray-400">
          Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?>
          
        </div>
      </div>

      <?php if (!empty($user_message)): ?>
        <div class="alert alert-<?php echo strpos($user_message, 'Error') !== false ? 'error' : 'success'; ?>">
          <i data-lucide="<?php echo strpos($user_message, 'Error') !== false ? 'alert-circle' : 'check-circle'; ?>" class="w-5 h-5 mr-2"></i>
          <?php echo htmlspecialchars($user_message); ?>
        </div>
      <?php endif; ?>

      <!-- Statistics Grid - Compact -->
      <div class="compact-grid mb-4">
        <div class="card compact-card">
          <p class="compact-title"><i data-lucide="users"></i><span>Total Members</span></p>
          <p class="compact-value"><?php echo $total_members; ?></p>
          <p class="text-xs text-green-400 mt-1 flex items-center"><i data-lucide="trending-up" class="w-3 h-3 mr-1"></i>Active members</p>
        </div>
        <div class="card compact-card">
          <p class="compact-title"><i data-lucide="user-check"></i><span>Today's Attendance</span></p>
          <p class="compact-value"><?php echo $attendance_today; ?></p>
          <p class="text-xs text-blue-400 mt-1 flex items-center"><i data-lucide="calendar" class="w-3 h-3 mr-1"></i><?php echo date('M j'); ?></p>
        </div>
        <div class="card compact-card">
          <p class="compact-title"><i data-lucide="dollar-sign"></i><span>Today's Revenue</span></p>
          <p class="compact-value">₱<?php echo number_format($revenue_today, 2); ?></p>
          <p class="text-xs text-purple-400 mt-1 flex items-center"><i data-lucide="shopping-cart" class="w-3 h-3 mr-1"></i>Sales</p>
        </div>
        <div class="card compact-card border-l-4 border-red-500">
          <p class="compact-title"><i data-lucide="alert-triangle"></i><span>Expiring Soon</span></p>
          <p class="compact-value"><?php echo $expiring_members; ?></p>
          <p class="text-xs text-red-400 mt-1 flex items-center"><i data-lucide="clock" class="w-3 h-3 mr-1"></i>Within 3 days</p>
        </div>
      </div>

      <!-- Main Content Area -->
      <div class="grid grid-cols-1 xl:grid-cols-5 gap-4">
        <!-- Left Column - Charts and Management (3/5 width) -->
        <div class="xl:col-span-3 space-y-4">
          <!-- Maintenance Alerts -->
          <div class="card">
            <h2 class="text-lg font-semibold text-yellow-400 mb-3 flex items-center gap-2">
              <i data-lucide="alert-triangle"></i>
              Maintenance Alerts
            </h2>
            
            <div class="grid grid-cols-3 gap-2 mb-3">
              <div class="text-center p-2 rounded-lg border border-red-500/30 bg-red-500/10">
                <div class="text-lg font-bold text-red-400"><?php echo $maintenance_stats['broken'] ?? 0; ?></div>
                <div class="text-xs text-red-300">Broken</div>
              </div>
              <div class="text-center p-2 rounded-lg border border-orange-500/30 bg-orange-500/10">
                <div class="text-lg font-bold text-orange-400"><?php echo $maintenance_stats['under_repair'] ?? 0; ?></div>
                <div class="text-xs text-orange-300">Repairing</div>
              </div>
              <div class="text-center p-2 rounded-lg border border-yellow-500/30 bg-yellow-500/10">
                <div class="text-lg font-bold text-yellow-400"><?php echo $maintenance_stats['needs_maintenance'] ?? 0; ?></div>
                <div class="text-xs text-yellow-300">Maintenance</div>
              </div>
            </div>

            <?php if ($recent_maintenance_result && $recent_maintenance_result->num_rows > 0): ?>
              <div class="space-y-2">
                <h3 class="text-sm font-semibold text-gray-300">Recent Maintenance Issues</h3>
                <?php while($item = $recent_maintenance_result->fetch_assoc()): ?>
                  <div class="flex items-center justify-between p-2 bg-gray-800/50 rounded-lg">
                    <div class="flex items-center gap-2">
                      <div class="w-2 h-2 rounded-full 
                        <?php echo $item['status'] == 'Broken' ? 'bg-red-500' : 
                               ($item['status'] == 'Under Repair' ? 'bg-orange-500' : 'bg-yellow-500'); ?>">
                      </div>
                      <span class="text-sm text-white"><?php echo htmlspecialchars($item['name']); ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                      <span class="text-xs px-2 py-1 rounded-full 
                        <?php echo $item['status'] == 'Broken' ? 'bg-red-500/20 text-red-400' : 
                               ($item['status'] == 'Under Repair' ? 'bg-orange-500/20 text-orange-400' : 'bg-yellow-500/20 text-yellow-400'); ?>">
                        <?php echo htmlspecialchars($item['status']); ?>
                      </span>
                      <span class="text-xs text-gray-400">
                        <?php echo date('M j', strtotime($item['last_updated'])); ?>
                      </span>
                    </div>
                  </div>
                <?php endwhile; ?>
              </div>
              <div class="mt-3">
                <a href="maintenance_report.php" class="button-sm btn-primary w-full justify-center text-sm">
                  <i data-lucide="clipboard-list"></i> View Full Maintenance Report
                </a>
              </div>
            <?php else: ?>
              <div class="text-center py-4 text-gray-500">
                <i data-lucide="check-circle" class="w-8 h-8 mx-auto text-green-400 mb-2"></i>
                <p class="text-sm">No maintenance issues!</p>
                <p class="text-xs">All equipment is operational</p>
              </div>
            <?php endif; ?>
          </div>

          <!-- Trainer Management Section -->
          <div class="card">
            <div class="flex justify-between items-center mb-3">
              <h2 class="text-lg font-semibold text-yellow-400 flex items-center gap-2">
                <i data-lucide="user-plus"></i>
                Trainer Management
              </h2>
              <button id="toggleUserForm" class="button-sm bg-yellow-600 hover:bg-yellow-700 text-white text-sm">
                <i data-lucide="eye"></i> Hide Form
              </button>
            </div>
            
            <div id="userForm">
              <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
                <div>
                  <label class="form-label text-sm">Username</label>
                  <input type="text" name="new_username" class="form-input text-sm" required>
                </div>
                <div>
                  <label class="form-label text-sm">Password</label>
                  <input type="password" name="new_password" class="form-input text-sm" required>
                </div>
                <div>
                  <label class="form-label text-sm">Full Name</label>
                  <input type="text" name="full_name" class="form-input text-sm" required>
                </div>
                <div>
                  <label class="form-label text-sm">Email</label>
                  <input type="email" name="email" class="form-input text-sm" required>
                </div>
                <div class="md:col-span-2">
                  <p class="text-xs text-gray-400 mt-1">Only trainer accounts can be created</p>
                </div>
                <div class="md:col-span-2">
                  <button type="submit" name="create_user" class="w-full bg-green-600 hover:bg-green-700 transition text-white rounded-lg button-sm justify-center flex items-center gap-2 text-sm">
                    <i data-lucide="user-plus"></i> Create Trainer Account
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- Right Column - Quick Actions and Additional Info (2/5 width) -->
        <div class="xl:col-span-2 space-y-4">
          <!-- Quick Actions -->
          <div class="card">
            <h2 class="text-lg font-semibold text-yellow-400 mb-3 flex items-center gap-2">
              <i data-lucide="zap"></i>
              Quick Actions
            </h2>
            
            <div class="grid grid-cols-2 gap-2">
              <a href="products.php?action=add" class="flex flex-col items-center p-3 bg-blue-500/10 border border-blue-500/30 rounded-lg hover:bg-blue-500/20 transition-colors">
                <i data-lucide="package-plus" class="w-5 h-5 text-blue-400 mb-1"></i>
                <span class="text-xs text-white text-center">Add Product</span>
              </a>
              
              <a href="adminannouncement.php" class="flex flex-col items-center p-3 bg-green-500/10 border border-green-500/30 rounded-lg hover:bg-green-500/20 transition-colors">
                <i data-lucide="megaphone" class="w-5 h-5 text-green-400 mb-1"></i>
                <span class="text-xs text-white text-center">New Announcement</span>
              </a>
              
              <a href="equipment_monitoring.php" class="flex flex-col items-center p-3 bg-purple-500/10 border border-purple-500/30 rounded-lg hover:bg-purple-500/20 transition-colors">
                <i data-lucide="wrench" class="w-5 h-5 text-purple-400 mb-1"></i>
                <span class="text-xs text-white text-center">Check Equipment</span>
              </a>
              
              <a href="revenue.php" class="flex flex-col items-center p-3 bg-orange-500/10 border border-orange-500/30 rounded-lg hover:bg-orange-500/20 transition-colors">
                <i data-lucide="dollar-sign" class="w-5 h-5 text-orange-400 mb-1"></i>
                <span class="text-xs text-white text-center">View Revenue</span>
              </a>
            </div>
          </div>

          <!-- Additional Stats -->
          <div class="card">
            <h2 class="text-lg font-semibold text-yellow-400 mb-3 flex items-center gap-2">
              <i data-lucide="bar-chart-3"></i>
              System Overview
            </h2>
            
            <div class="space-y-3">
              <div class="flex items-center justify-between p-2 bg-gray-800/50 rounded-lg">
                <div class="flex items-center gap-2">
                  <i data-lucide="users" class="w-4 h-4 text-blue-400"></i>
                  <span class="text-sm text-white">Total Users</span>
                </div>
                <span class="text-sm font-semibold text-blue-400"><?php echo $total_users; ?></span>
              </div>
              
              <div class="flex items-center justify-between p-2 bg-gray-800/50 rounded-lg">
                <div class="flex items-center gap-2">
                  <i data-lucide="package" class="w-4 h-4 text-green-400"></i>
                  <span class="text-sm text-white">Total Products</span>
                </div>
                <span class="text-sm font-semibold text-green-400"><?php echo $total_products; ?></span>
              </div>
              
              <div class="flex items-center justify-between p-2 bg-gray-800/50 rounded-lg">
                <div class="flex items-center gap-2">
                  <i data-lucide="message-square" class="w-4 h-4 text-purple-400"></i>
                  <span class="text-sm text-white">Total Feedback</span>
                </div>
                <span class="text-sm font-semibold text-purple-400"><?php echo $total_feedback; ?></span>
              </div>
              
              <div class="flex items-center justify-between p-2 bg-gray-800/50 rounded-lg">
                <div class="flex items-center gap-2">
                  <i data-lucide="alert-triangle" class="w-4 h-4 text-red-400"></i>
                  <span class="text-sm text-white">Maintenance Issues</span>
                </div>
                <span class="text-sm font-semibold text-red-400"><?php echo $total_maintenance_issues; ?></span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>

  <script>
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
        
        setTimeout(() => toast.classList.add('show'), 100);
        
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

    document.addEventListener('DOMContentLoaded', function() {
        lucide.createIcons();
        
        // Sidebar toggle with hover functionality
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

        // Toggle user form
        document.getElementById('toggleUserForm').addEventListener('click', function() {
            const form = document.getElementById('userForm');
            const button = this;
            
            if (form.style.display === 'none') {
                form.style.display = 'block';
                button.innerHTML = '<i data-lucide="eye"></i> Hide Form';
            } else {
                form.style.display = 'none';
                button.innerHTML = '<i data-lucide="eye"></i> Show Form';
            }
            lucide.createIcons();
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
                body: 'action=mark_all_read'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('All notifications marked as read', 'success');
                    // Hide notification badge
                    document.getElementById('notificationBadge').classList.add('hidden');
                    // Refresh the page to update notifications
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('Failed to mark notifications as read', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Network error occurred', 'error');
            });
        }

        // Initialize dropdowns
        setupDropdowns();

        // Mobile sidebar handling
        function setupMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            const toggleBtn = document.getElementById('toggleSidebar');
            
            // Check if mobile
            function isMobile() {
                return window.innerWidth <= 768;
            }
            
            // Toggle sidebar on mobile
            toggleBtn.addEventListener('click', function() {
                if (isMobile()) {
                    sidebar.classList.toggle('mobile-open');
                }
            });
            
            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(e) {
                if (isMobile() && 
                    !sidebar.contains(e.target) && 
                    !toggleBtn.contains(e.target) &&
                    sidebar.classList.contains('mobile-open')) {
                    sidebar.classList.remove('mobile-open');
                }
            });
        }

        setupMobileSidebar();
    });
  </script>
</body>
</html>



