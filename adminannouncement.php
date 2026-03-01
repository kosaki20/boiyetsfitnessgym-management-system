<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
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
// Handle form submissions
$message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['create_announcement'])) {
        $title = mysqli_real_escape_string($conn, $_POST['announcement_title']);
        $content = mysqli_real_escape_string($conn, $_POST['announcement_content']);
        $priority = mysqli_real_escape_string($conn, $_POST['priority']);
        $target_audience = mysqli_real_escape_string($conn, $_POST['target_audience']);
        $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
        
        $sql = "INSERT INTO announcements (title, content, created_by, priority, target_audience, expiry_date) 
                VALUES ('$title', '$content', '" . $_SESSION['username'] . "', '$priority', '$target_audience', " . 
                ($expiry_date ? "'$expiry_date'" : "NULL") . ")";
        
        if ($conn->query($sql)) {
            $message = "<div class='alert alert-success'>Announcement created successfully!</div>";
        } else {
            $message = "<div class='alert alert-error'>Error creating announcement: " . $conn->error . "</div>";
        }
    }
    
    if (isset($_POST['delete_announcement'])) {
        $announcement_id = intval($_POST['announcement_id']);
        
        $sql = "DELETE FROM announcements WHERE id = $announcement_id";
        
        if ($conn->query($sql)) {
            $message = "<div class='alert alert-success'>Announcement deleted successfully!</div>";
        } else {
            $message = "<div class='alert alert-error'>Error deleting announcement: " . $conn->error . "</div>";
        }
    }
    
    if (isset($_POST['update_announcement'])) {
        $announcement_id = intval($_POST['announcement_id']);
        $title = mysqli_real_escape_string($conn, $_POST['edit_title']);
        $content = mysqli_real_escape_string($conn, $_POST['edit_content']);
        $priority = mysqli_real_escape_string($conn, $_POST['edit_priority']);
        $target_audience = mysqli_real_escape_string($conn, $_POST['edit_target_audience']);
        $expiry_date = !empty($_POST['edit_expiry_date']) ? $_POST['edit_expiry_date'] : null;
        
        $sql = "UPDATE announcements SET 
                title = '$title', 
                content = '$content', 
                priority = '$priority', 
                target_audience = '$target_audience', 
                expiry_date = " . ($expiry_date ? "'$expiry_date'" : "NULL") . "
                WHERE id = $announcement_id";
        
        if ($conn->query($sql)) {
            $message = "<div class='alert alert-success'>Announcement updated successfully!</div>";
        } else {
            $message = "<div class='alert alert-error'>Error updating announcement: " . $conn->error . "</div>";
        }
    }
}

// Fetch all announcements
$announcements_result = $conn->query("SELECT * FROM announcements ORDER BY created_at DESC");

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BOIYETS FITNESS GYM - Announcement Management</title>
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
    
    .form-input {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 8px;
      padding: 0.75rem;
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
    
    .form-textarea {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 8px;
      padding: 0.75rem;
      color: white;
      width: 100%;
      min-height: 120px;
      resize: vertical;
      transition: all 0.2s ease;
    }
    
    .form-textarea:focus {
      outline: none;
      border-color: #fbbf24;
      box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.2);
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
    
    .empty-state {
      text-align: center;
      padding: 3rem 1rem;
      color: #6b7280;
    }
    
    .empty-state i {
      margin-bottom: 1rem;
      opacity: 0.5;
    }

    .modal { 
      display: none; 
      position: fixed; 
      z-index: 1000; 
      left: 0; 
      top: 0; 
      width: 100%; 
      height: 100%; 
      background-color: rgba(0,0,0,0.8); 
    }
    
    .modal-content { 
      background: rgba(26, 26, 26, 0.95);
      margin: 5% auto; 
      padding: 2rem; 
      border-radius: 12px; 
      width: 90%; 
      max-width: 600px; 
      border: 1px solid rgba(255, 255, 255, 0.1);
      backdrop-filter: blur(10px);
    }

    .priority-high { 
      border-left: 4px solid #ef4444; 
    }
    
    .priority-medium { 
      border-left: 4px solid #f59e0b; 
    }
    
    .priority-low { 
      border-left: 4px solid #3b82f6; 
    }
    
    .badge {
      padding: 0.25rem 0.75rem;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 500;
    }
    
    .badge-high {
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
    }
    
    .badge-medium {
      background: rgba(245, 158, 11, 0.2);
      color: #f59e0b;
    }
    
    .badge-low {
      background: rgba(59, 130, 246, 0.2);
      color: #3b82f6;
    }
    
    .badge-audience {
      background: rgba(139, 92, 246, 0.2);
      color: #8b5cf6;
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

        <a href="adminannouncement.php" class="sidebar-item active">
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
          <i data-lucide="megaphone"></i>
          Announcement Management
        </h1>
        <div class="text-sm text-gray-400">
          Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?>
        </div>
      </div>

      <?php echo $message; ?>

      <!-- Create Announcement Form -->
      <div class="card">
        <h2 class="text-lg font-semibold text-yellow-400 mb-4 flex items-center gap-2">
          <i data-lucide="plus"></i>
          Create New Announcement
        </h2>
        <form method="POST" class="space-y-4">
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div>
              <label class="form-label">Title</label>
              <input type="text" name="announcement_title" class="form-input" required placeholder="Enter announcement title">
            </div>
            
            <div>
              <label class="form-label">Target Audience</label>
              <select name="target_audience" class="form-select" required>
                <option value="all">All Users</option>
                <option value="clients">Clients Only</option>
                <option value="trainers">Trainers Only</option>
              </select>
            </div>
          </div>
          
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div>
              <label class="form-label">Priority Level</label>
              <select name="priority" class="form-select" required>
                <option value="low">Low</option>
                <option value="medium" selected>Medium</option>
                <option value="high">High</option>
              </select>
            </div>
            
            <div>
              <label class="form-label">Expiry Date (Optional)</label>
              <input type="date" name="expiry_date" class="form-input" min="<?php echo date('Y-m-d'); ?>">
              <p class="text-xs text-gray-400 mt-1">Leave empty if announcement should not expire</p>
            </div>
          </div>
          
          <div>
            <label class="form-label">Content</label>
            <textarea name="announcement_content" class="form-textarea" required placeholder="Enter announcement content"></textarea>
          </div>
          
          <div class="flex justify-end">
            <button type="submit" name="create_announcement" class="button-sm bg-yellow-600 hover:bg-yellow-700 text-white flex items-center gap-2">
              <i data-lucide="plus" class="w-4 h-4"></i> Create Announcement
            </button>
          </div>
        </form>
      </div>

      <!-- Announcements List -->
      <div class="card">
        <div class="flex justify-between items-center mb-6">
          <h2 class="text-lg font-semibold text-yellow-400 flex items-center gap-2">
            <i data-lucide="list"></i>
            All Announcements
          </h2>
          <span class="text-gray-400"><?php echo $announcements_result->num_rows; ?> announcement(s)</span>
        </div>
        
        <div class="space-y-4">
          <?php if ($announcements_result->num_rows > 0): ?>
            <?php while($announcement = $announcements_result->fetch_assoc()): ?>
            <div class="bg-gray-800 rounded-lg p-4 <?php echo 'priority-' . $announcement['priority']; ?> hover:bg-gray-750 transition-colors">
              <div class="flex justify-between items-start mb-3">
                <div>
                  <h3 class="font-semibold text-lg text-white"><?php echo htmlspecialchars($announcement['title']); ?></h3>
                  <div class="flex items-center gap-2 mt-1">
                    <span class="badge <?php echo 'badge-' . $announcement['priority']; ?>">
                      <?php echo ucfirst($announcement['priority']); ?> Priority
                    </span>
                    <span class="badge badge-audience">
                      <?php echo ucfirst($announcement['target_audience']); ?>
                    </span>
                    <?php if ($announcement['expiry_date']): ?>
                      <span class="text-xs text-gray-400">
                        Expires: <?php echo date('M j, Y', strtotime($announcement['expiry_date'])); ?>
                      </span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="flex items-center gap-2">
                  <button onclick="openEditModal(<?php echo $announcement['id']; ?>, '<?php echo addslashes($announcement['title']); ?>', '<?php echo addslashes($announcement['content']); ?>', '<?php echo $announcement['priority']; ?>', '<?php echo $announcement['target_audience']; ?>', '<?php echo $announcement['expiry_date']; ?>')" 
                          class="button-sm btn-success">
                    <i data-lucide="edit" class="w-4 h-4"></i> Edit
                  </button>
                  <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this announcement?');">
                    <input type="hidden" name="announcement_id" value="<?php echo $announcement['id']; ?>">
                    <button type="submit" name="delete_announcement" class="button-sm btn-danger">
                      <i data-lucide="trash-2" class="w-4 h-4"></i> Delete
                    </button>
                  </form>
                </div>
              </div>
              
              <p class="text-gray-300 mb-3 whitespace-pre-wrap"><?php echo nl2br(htmlspecialchars($announcement['content'])); ?></p>
              
              <div class="flex justify-between items-center text-sm text-gray-400">
                <span>Posted by <?php echo htmlspecialchars($announcement['created_by']); ?></span>
                <span><?php echo date('M j, Y g:i A', strtotime($announcement['created_at'])); ?></span>
              </div>
            </div>
            <?php endwhile; ?>
          <?php else: ?>
            <div class="empty-state">
              <i data-lucide="megaphone" class="w-12 h-12 mx-auto"></i>
              <p>No announcements found. Create your first announcement above.</p>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </main>
  </div>

  <!-- Edit Announcement Modal -->
  <div id="editModal" class="modal">
    <div class="modal-content">
      <div class="flex justify-between items-center mb-6">
        <h3 class="text-xl font-semibold text-yellow-400">Edit Announcement</h3>
        <button type="button" class="text-gray-400 hover:text-white text-2xl" onclick="closeEditModal()">
          <i data-lucide="x" class="w-6 h-6"></i>
        </button>
      </div>
      
      <form method="POST" id="editForm">
        <input type="hidden" name="announcement_id" id="edit_announcement_id">
        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
          <div>
            <label class="form-label">Title</label>
            <input type="text" id="edit_title" name="edit_title" class="form-input" required>
          </div>
          
          <div>
            <label class="form-label">Target Audience</label>
            <select id="edit_target_audience" name="edit_target_audience" class="form-select" required>
              <option value="all">All Users</option>
              <option value="clients">Clients Only</option>
              <option value="trainers">Trainers Only</option>
            </select>
          </div>
        </div>
        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
          <div>
            <label class="form-label">Priority Level</label>
            <select id="edit_priority" name="edit_priority" class="form-select" required>
              <option value="low">Low</option>
              <option value="medium">Medium</option>
              <option value="high">High</option>
            </select>
          </div>
          
          <div>
            <label class="form-label">Expiry Date (Optional)</label>
            <input type="date" id="edit_expiry_date" name="edit_expiry_date" class="form-input">
            <p class="text-xs text-gray-400 mt-1">Leave empty if announcement should not expire</p>
          </div>
        </div>
        
        <div class="mb-6">
          <label class="form-label">Content</label>
          <textarea id="edit_content" name="edit_content" class="form-textarea" required></textarea>
        </div>
        
        <div class="flex justify-end gap-3">
          <button type="button" class="button-sm btn-primary" onclick="closeEditModal()">Cancel</button>
          <button type="submit" name="update_announcement" class="button-sm bg-yellow-600 hover:bg-yellow-700 text-white">
            Update Announcement
          </button>
        </div>
      </form>
    </div>
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

        // Set minimum date for expiry date fields to today
        const today = new Date().toISOString().split('T')[0];
        document.querySelector('input[name="expiry_date"]').min = today;
        document.getElementById('edit_expiry_date').min = today;
    });

    // Edit Modal Functions
    function openEditModal(id, title, content, priority, audience, expiryDate) {
      document.getElementById('edit_announcement_id').value = id;
      document.getElementById('edit_title').value = title;
      document.getElementById('edit_content').value = content;
      document.getElementById('edit_priority').value = priority;
      document.getElementById('edit_target_audience').value = audience;
      document.getElementById('edit_expiry_date').value = expiryDate;
      
      document.getElementById('editModal').style.display = 'block';
    }
    
    function closeEditModal() {
      document.getElementById('editModal').style.display = 'none';
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
      const modal = document.getElementById('editModal');
      if (event.target === modal) {
        closeEditModal();
      }
    }
  </script>
</body>
</html>



