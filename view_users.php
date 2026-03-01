<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// ... rest of your code
session_start();
if (!isset($_SESSION['username']) || $_SESSION['role'] != 'admin') {
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
// Handle user actions
$action_message = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['delete_user'])) {
        $user_id = intval($_POST['user_id']);
        if ($conn->query("DELETE FROM users WHERE id = $user_id")) {
            $action_message = "User deleted successfully!";
        } else {
            $action_message = "Error deleting user: " . $conn->error;
        }
    }
}

// Fetch all users
$users_result = $conn->query("SELECT * FROM users ORDER BY created_at DESC");

// Get role counts
$admin_count = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'admin'")->fetch_assoc()['count'];
$trainer_count = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'trainer'")->fetch_assoc()['count'];
$client_count = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'client'")->fetch_assoc()['count'];

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BOIYETS FITNESS GYM - Manage Users</title>
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
    
    /* FIXED DROPDOWN STYLES - Matching trainer_dashboard.php */
    .form-select {
      background: rgba(255, 255, 255, 0.05) !important;
      border: 1px solid rgba(255, 255, 255, 0.1) !important;
      border-radius: 8px;
      padding: 0.5rem 0.75rem;
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
    
    .button-sm { 
      padding: 0.5rem 0.75rem; 
      font-size: 0.8rem; 
      border-radius: 8px;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 0.4rem;
      transition: all 0.2s ease;
      border: none;
      cursor: pointer;
    }
    
    .button-sm:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
    }
    
    .btn-primary {
      background: rgba(251, 191, 36, 0.2);
      color: #fbbf24;
    }
    
    .btn-primary:hover {
      background: rgba(251, 191, 36, 0.3);
    }
    
    .btn-danger {
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
    }
    
    .btn-danger:hover {
      background: rgba(239, 68, 68, 0.3);
    }
    
    .btn-success {
      background: rgba(16, 185, 129, 0.2);
      color: #10b981;
    }
    
    .btn-success:hover {
      background: rgba(16, 185, 129, 0.3);
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

    .role-badge {
      padding: 0.25rem 0.75rem;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 500;
    }
    
    .role-admin {
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
    }
    
    .role-trainer {
      background: rgba(59, 130, 246, 0.2);
      color: #3b82f6;
    }
    
    .role-client {
      background: rgba(16, 185, 129, 0.2);
      color: #10b981;
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
            <a href="logout.php" class="flex items-center gap-2 px=3 py=2 text-sm text-red-400 hover:text-red-300 hover:bg-red-400/10 rounded-lg transition-colors">
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
        <a href="admin_dashboard.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="home"></i>
            <span class="text-sm font-medium">Dashboard</span>
          </div>
          <span class="tooltip">Dashboard</span>
        </a>

        <a href="view_users.php" class="sidebar-item active">
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
      <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-yellow-400 flex items-center gap-2">
          <i data-lucide="users"></i>
          Manage Users
        </h1>
        <div class="text-sm text-gray-400">
          Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?>
        </div>
      </div>

      <?php if (!empty($action_message)): ?>
        <div class="alert alert-<?php echo strpos($action_message, 'Error') !== false ? 'error' : 'success'; ?>">
          <i data-lucide="<?php echo strpos($action_message, 'Error') !== false ? 'alert-circle' : 'check-circle'; ?>" class="w-5 h-5 mr-2"></i>
          <?php echo htmlspecialchars($action_message); ?>
        </div>
      <?php endif; ?>

      <!-- Quick Stats -->
      <div class="stats-grid mb-8">
        <div class="card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="shield"></i><span>Total Admins</span></p>
              <p class="card-value"><?php echo $admin_count; ?></p>
            </div>
            <div class="p-3 bg-red-500/10 rounded-lg">
              <i data-lucide="shield" class="w-6 h-6 text-red-400"></i>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="dumbbell"></i><span>Total Trainers</span></p>
              <p class="card-value"><?php echo $trainer_count; ?></p>
            </div>
            <div class="p-3 bg-blue-500/10 rounded-lg">
              <i data-lucide="dumbbell" class="w-6 h-6 text-blue-400"></i>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="user"></i><span>Total Clients</span></p>
              <p class="card-value"><?php echo $client_count; ?></p>
            </div>
            <div class="p-3 bg-green-500/10 rounded-lg">
              <i data-lucide="user" class="w-6 h-6 text-green-400"></i>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="users"></i><span>Total Users</span></p>
              <p class="card-value"><?php echo $users_result->num_rows; ?></p>
            </div>
            <div class="p-3 bg-yellow-500/10 rounded-lg">
              <i data-lucide="users" class="w-6 h-6 text-yellow-400"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Users Table Card -->
      <div class="card">
        <div class="flex justify-between items-center mb-6">
          <h2 class="text-lg font-semibold text-yellow-400 flex items-center gap-2">
            <i data-lucide="list"></i>
            All Users
          </h2>
          <div class="flex gap-3">
            <div class="relative">
              <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
              <input type="text" id="searchUsers" placeholder="Search users..." class="form-input pl-10 pr-4 py-2" style="width: 250px;">
            </div>
            <a href="admin_dashboard.php" class="button-sm bg-yellow-600 hover:bg-yellow-700 text-white flex items-center gap-2">
              <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to Dashboard
            </a>
          </div>
        </div>

        <div class="table-container">
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Full Name</th>
                <th>Username</th>
                <th>Email</th>
                <th>Role</th>
                <th>Joined Date</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($users_result->num_rows > 0): ?>
                <?php while($user = $users_result->fetch_assoc()): ?>
                <tr>
                  <td class="font-medium"><?php echo $user['id']; ?></td>
                  <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                  <td><?php echo htmlspecialchars($user['username']); ?></td>
                  <td><?php echo htmlspecialchars($user['email']); ?></td>
                  <td>
                    <span class="role-badge role-<?php echo $user['role']; ?>">
                      <?php echo ucfirst($user['role']); ?>
                    </span>
                  </td>
                  <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                  <td>
                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this user? This action cannot be undone.')">
                      <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                      <button type="submit" name="delete_user" class="button-sm btn-danger flex items-center gap-1">
                        <i data-lucide="trash-2" class="w-3 h-3"></i> Delete
                      </button>
                    </form>
                  </td>
                </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr>
                  <td colspan="7" class="empty-state">
                    <i data-lucide="users" class="w-12 h-12 mx-auto"></i>
                    <p>No users found in the system.</p>
                    <a href="admin_dashboard.php" class="button-sm bg-yellow-600 hover:bg-yellow-700 text-white mt-3 flex items-center gap-1 mx-auto w-fit">
                      <i data-lucide="user-plus" class="w-3 h-3"></i> Create First User
                    </a>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="flex justify-between items-center mt-6 pt-4 border-t border-gray-700">
          <span class="text-sm text-gray-400">Showing <?php echo $users_result->num_rows; ?> users</span>
          <div class="flex gap-2">
            <button class="button-sm btn-primary disabled:opacity-50" disabled>
              <i data-lucide="chevron-left" class="w-4 h-4"></i> Previous
            </button>
            <button class="button-sm btn-primary disabled:opacity-50" disabled>
              Next <i data-lucide="chevron-right" class="w-4 h-4"></i>
            </button>
          </div>
        </div>
      </div>
    </main>
  </div>

  <script>
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

        // Search functionality
        document.getElementById('searchUsers').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
    });
  </script>
</body>
</html>



