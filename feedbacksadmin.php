<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'trainer')) {
    header("Location: index.php");
    exit();
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "boiyetsdb";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Include chat functions if the file exists
$unread_count = 0;
if (file_exists('chat_functions.php')) {
    require_once 'chat_functions.php';
    $unread_count = getUnreadCount($_SESSION['user_id'], $conn);
}

// Handle feedback status updates
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_feedback_status'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = "Security token invalid. Please try again.";
        header("Location: feedbacksadmin.php");
        exit();
    }

    $feedback_id = (int)$_POST['feedback_id'];
    $new_status = $_POST['status'];
    $admin_notes = trim($_POST['admin_notes']);
    $updated_by = $_SESSION['user_id'];

    $update_sql = "UPDATE feedback SET status = ?, admin_notes = ?, updated_at = NOW() WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    
    if (!$update_stmt) {
        $_SESSION['error'] = "SQL prepare failed: " . $conn->error;
        header("Location: feedbacksadmin.php");
        exit();
    }
    
    $update_stmt->bind_param("ssi", $new_status, $admin_notes, $feedback_id);
    
    if ($update_stmt->execute()) {
        $_SESSION['success'] = "Feedback status updated successfully!";
        
        // If feedback is about equipment/facility and marked as resolved, check if we need to update equipment status
        if ($new_status == 'resolved') {
            // Get feedback details to see if it's equipment/facility related
            $feedback_sql = "SELECT category, message FROM feedback WHERE id = ?";
            $feedback_stmt = $conn->prepare($feedback_sql);
            $feedback_stmt->bind_param("i", $feedback_id);
            $feedback_stmt->execute();
            $feedback_result = $feedback_stmt->get_result();
            
            if ($feedback_row = $feedback_result->fetch_assoc()) {
                // Check if this is equipment/facility feedback that might require status updates
                if (in_array($feedback_row['category'], ['equipment', 'facility'])) {
                    // You could add logic here to automatically update equipment/facility status
                    // based on the feedback being resolved
                    $_SESSION['info'] = "Feedback resolved. Consider checking equipment/facility status if needed.";
                }
            }
            $feedback_stmt->close();
        }
    } else {
        $_SESSION['error'] = "Error updating feedback status: " . $update_stmt->error;
    }
    $update_stmt->close();
    header("Location: feedbacksadmin.php");
    exit();
}

// Handle linking feedback to equipment/facility (for maintenance tracking)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['link_to_maintenance'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = "Security token invalid. Please try again.";
        header("Location: feedbacksadmin.php");
        exit();
    }

    $feedback_id = (int)$_POST['feedback_id'];
    $target_type = $_POST['target_type']; // 'equipment' or 'facility'
    $target_id = (int)$_POST['target_id'];
    $maintenance_notes = trim($_POST['maintenance_notes']);

    // Get feedback details
    $feedback_sql = "SELECT * FROM feedback WHERE id = ?";
    $feedback_stmt = $conn->prepare($feedback_sql);
    $feedback_stmt->bind_param("i", $feedback_id);
    $feedback_stmt->execute();
    $feedback_result = $feedback_stmt->get_result();
    
    if ($feedback_row = $feedback_result->fetch_assoc()) {
        if ($target_type == 'equipment') {
            // Update equipment status to 'Needs Maintenance'
            $update_equipment_sql = "UPDATE equipment SET status = 'Needs Maintenance', notes = CONCAT(COALESCE(notes, ''), ?), last_updated = NOW() WHERE id = ?";
            $update_stmt = $conn->prepare($update_equipment_sql);
            $maintenance_note = "\n\nMaintenance Request from Feedback #" . $feedback_id . ": " . $maintenance_notes;
            $update_stmt->bind_param("si", $maintenance_note, $target_id);
            
            if ($update_stmt->execute()) {
                // Log the equipment status change
                $log_sql = "INSERT INTO equipment_logs (equipment_id, old_status, new_status, updated_by, note) 
                           SELECT id, status, 'Needs Maintenance', ?, ? FROM equipment WHERE id = ?";
                $log_stmt = $conn->prepare($log_sql);
                $log_note = "Maintenance requested via feedback system: " . $maintenance_notes;
                $log_stmt->bind_param("isi", $_SESSION['user_id'], $log_note, $target_id);
                $log_stmt->execute();
                $log_stmt->close();
                
                $_SESSION['success'] = "Feedback linked to equipment and maintenance request created!";
            }
            $update_stmt->close();
            
        } elseif ($target_type == 'facility') {
            // Update facility condition
            $update_facility_sql = "UPDATE facilities SET facility_condition = 'Needs Maintenance', notes = CONCAT(COALESCE(notes, ''), ?), last_updated = NOW(), updated_by = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_facility_sql);
            $maintenance_note = "\n\nMaintenance Request from Feedback #" . $feedback_id . ": " . $maintenance_notes;
            $update_stmt->bind_param("sii", $maintenance_note, $_SESSION['user_id'], $target_id);
            
            if ($update_stmt->execute()) {
                $_SESSION['success'] = "Feedback linked to facility and maintenance request created!";
            }
            $update_stmt->close();
        }
        
        // Update feedback status to reviewed
        $update_feedback_sql = "UPDATE feedback SET status = 'reviewed', admin_notes = CONCAT(COALESCE(admin_notes, ''), ?) WHERE id = ?";
        $update_feedback_stmt = $conn->prepare($update_feedback_sql);
        $admin_note = "\n\nLinked to " . $target_type . " maintenance. " . $maintenance_notes;
        $update_feedback_stmt->bind_param("si", $admin_note, $feedback_id);
        $update_feedback_stmt->execute();
        $update_feedback_stmt->close();
    }
    $feedback_stmt->close();
    header("Location: feedbacksadmin.php");
    exit();
}

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$category_filter = $_GET['category'] ?? '';
$urgent_filter = $_GET['urgent'] ?? '';
$search = $_GET['search'] ?? '';

// Build feedback query with filters
$feedback_where_conditions = ["1=1"];
$feedback_params = [];
$feedback_types = "";

if ($status_filter) {
    $feedback_where_conditions[] = "f.status = ?";
    $feedback_params[] = $status_filter;
    $feedback_types .= "s";
}

if ($category_filter) {
    $feedback_where_conditions[] = "f.category = ?";
    $feedback_params[] = $category_filter;
    $feedback_types .= "s";
}

if ($urgent_filter !== '') {
    $feedback_where_conditions[] = "f.urgent = ?";
    $feedback_params[] = $urgent_filter;
    $feedback_types .= "i";
}

if ($search) {
    $feedback_where_conditions[] = "(f.subject LIKE ? OR f.message LIKE ? OR u.full_name LIKE ?)";
    $feedback_params[] = "%$search%";
    $feedback_params[] = "%$search%";
    $feedback_params[] = "%$search%";
    $feedback_types .= "sss";
}

$feedback_where_sql = implode(" AND ", $feedback_where_conditions);

// Get feedback data with user information
$feedback_sql = "SELECT f.*, u.full_name, u.username, u.role as user_role 
                 FROM feedback f 
                 LEFT JOIN users u ON f.user_id = u.id 
                 WHERE $feedback_where_sql 
                 ORDER BY f.created_at DESC";

$feedback_stmt = $conn->prepare($feedback_sql);
if ($feedback_stmt && !empty($feedback_params)) {
    $feedback_stmt->bind_param($feedback_types, ...$feedback_params);
    $feedback_stmt->execute();
    $feedback_result = $feedback_stmt->get_result();
} else {
    $feedback_result = $conn->query($feedback_sql);
}

// Get equipment and facilities for linking
$equipment_result = $conn->query("SELECT id, name, category, location FROM equipment ORDER BY name");
$facilities_result = $conn->query("SELECT id, name FROM facilities ORDER BY name");

// Get feedback statistics
$stats_sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as reviewed,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
                SUM(CASE WHEN urgent = 1 THEN 1 ELSE 0 END) as urgent
              FROM feedback";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

$username = $_SESSION['username'] ?? 'User';
$role = $_SESSION['role'] ?? 'trainer';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BOIYETS FITNESS GYM - Feedback Management</title>
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

    .sidebar { 
      flex-shrink: 0; 
      transition: all 0.3s ease;
      overflow-y: auto;
      -ms-overflow-style: none;
      scrollbar-width: none;
      position: fixed;
      height: 100vh;
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
      position: sticky;
      top: 0;
      z-index: 100;
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
      text-decoration: none;
    }

    .button-sm:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
    }

    .btn-primary {
      background: rgba(251, 191, 36, 0.2);
      color: #fbbf24;
      border: 1px solid rgba(251, 191, 36, 0.3);
    }

    .btn-primary:hover {
      background: rgba(251, 191, 36, 0.3);
    }

    .btn-active {
      background: #fbbf24;
      color: white;
      border: 1px solid #fbbf24;
    }

    .btn-outline {
      background: transparent;
      border: 1px solid #fbbf24;
      color: #fbbf24;
    }

    .btn-outline:hover {
      background: #fbbf24;
      color: white;
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 0.75rem;
    }

    .stat-card {
      background: rgba(251, 191, 36, 0.1);
      border-left: 4px solid #fbbf24;
    }

    .expense-card {
      background: rgba(239, 68, 68, 0.1);
      border-left: 4px solid #ef4444;
    }

    .profit-card {
      background: rgba(34, 197, 94, 0.1);
      border-left: 4px solid #22c55e;
    }

    .membership-card {
      background: rgba(59, 130, 246, 0.1);
      border-left: 4px solid #3b82f6;
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

    .badge {
      padding: 0.25rem 0.5rem;
      border-radius: 6px;
      font-size: 0.75rem;
      font-weight: 500;
      display: inline-flex;
      align-items: center;
      gap: 0.25rem;
    }

    .badge-good {
      background: rgba(34, 197, 94, 0.2);
      color: #22c55e;
      border: 1px solid rgba(34, 197, 94, 0.3);
    }

    .badge-needs-maintenance {
      background: rgba(245, 158, 11, 0.2);
      color: #f59e0b;
      border: 1px solid rgba(245, 158, 11, 0.3);
    }

    .badge-under-repair {
      background: rgba(59, 130, 246, 0.2);
      color: #3b82f6;
      border: 1px solid rgba(59, 130, 246, 0.3);
    }

    .badge-broken {
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
      border: 1px solid rgba(239, 68, 68, 0.3);
    }

    .badge-closed {
      background: rgba(107, 114, 128, 0.2);
      color: #6b7280;
      border: 1px solid rgba(107, 114, 128, 0.3);
    }

    .badge-pending {
      background: rgba(245, 158, 11, 0.2);
      color: #f59e0b;
      border: 1px solid rgba(245, 158, 11, 0.3);
    }
    
    .badge-reviewed {
      background: rgba(59, 130, 246, 0.2);
      color: #3b82f6;
      border: 1px solid rgba(59, 130, 246, 0.3);
    }
    
    .badge-resolved {
      background: rgba(34, 197, 94, 0.2);
      color: #22c55e;
      border: 1px solid rgba(34, 197, 94, 0.3);
    }

    .badge-outline {
      background: transparent;
      border: 1px solid #fbbf24;
      color: #fbbf24;
    }

    /* Modal styles */
    .modal {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.8);
      z-index: 1000;
      backdrop-filter: blur(5px);
    }

    .modal-content {
      background: #1a1a1a;
      margin: 2rem auto;
      padding: 2rem;
      border-radius: 12px;
      max-width: 500px;
      width: 90%;
      border: 1px solid rgba(255, 255, 255, 0.1);
      max-height: 90vh;
      overflow-y: auto;
      position: relative;
    }

    .form-input {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 8px;
      padding: 0.75rem;
      color: white;
      width: 100%;
      transition: all 0.2s ease;
      box-sizing: border-box;
    }

    .form-input:focus {
      outline: none;
      border-color: #fbbf24;
      box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.2);
    }

    .form-label {
      display: block;
      font-size: 0.875rem;
      font-weight: 500;
      color: #9ca3af;
      margin-bottom: 0.5rem;
    }

    /* Table styles */
    table {
      width: 100%;
      border-collapse: collapse;
    }

    th, td {
      padding: 0.75rem 1rem;
      text-align: left;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    th {
      background: rgba(255, 255, 255, 0.05);
      font-weight: 600;
      color: #9ca3af;
    }

    tr:hover {
      background: rgba(255, 255, 255, 0.02);
    }

    /* Status indicator styles */
    .status-indicator {
      display: inline-block;
      width: 8px;
      height: 8px;
      border-radius: 50%;
      margin-right: 6px;
    }

    .status-good { background: #22c55e; }
    .status-needs-maintenance { background: #f59e0b; }
    .status-under-repair { background: #3b82f6; }
    .status-broken { background: #ef4444; }
    .status-closed { background: #6b7280; }

    /* Dropdown styles */
    .dropdown-menu {
      display: none;
      position: absolute;
      right: 0;
      top: 100%;
      background: #1a1a1a;
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 8px;
      padding: 0.5rem;
      min-width: 200px;
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
      z-index: 1000;
    }

    .dropdown-menu.show {
      display: block;
    }

    .dropdown-item {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.75rem;
      color: #e2e8f0;
      border-radius: 6px;
      cursor: pointer;
      transition: all 0.2s ease;
      text-decoration: none;
    }

    .dropdown-item:hover {
      background: rgba(251, 191, 36, 0.1);
      color: #fbbf24;
    }

    .dropdown-header {
      padding: 0.75rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .dropdown-header h3 {
      font-weight: 600;
      color: #fbbf24;
      margin: 0;
      font-size: 0.9rem;
    }

    .dropdown-header p {
      color: #9ca3af;
      margin: 0.25rem 0 0 0;
      font-size: 0.8rem;
    }

    .dropdown-divider {
      height: 1px;
      background: rgba(255, 255, 255, 0.1);
      margin: 0.5rem 0;
    }

    /* Main content adjustment */
    main {
      margin-left: 240px; /* Match sidebar width */
      transition: margin-left 0.3s ease;
    }

    .sidebar-collapsed + main {
      margin-left: 64px; /* Match collapsed sidebar width */
    }

    .urgent-indicator {
      animation: pulse 2s infinite;
      background: rgba(239, 68, 68, 0.1);
    }
    
    @keyframes pulse {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.7; }
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
          <img src="<?php echo htmlspecialchars($_SESSION['profile_picture'] ?? 'https://i.pravatar.cc/40'); ?>" class="w-8 h-8 rounded-full border border-gray-600" id="userAvatar" />
          <span class="text-sm font-medium hidden md:inline" id="userName">
            <?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?>
          </span>
          <i data-lucide="chevron-down" class="w-4 h-4"></i>
        </button>
        <div id="userDropdown" class="dropdown-menu">
          <div class="dropdown-header">
            <h3><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?></h3>
            <p><?php echo htmlspecialchars($_SESSION['role'] ?? 'User'); ?></p>
          </div>
          <a href="profile.php" class="dropdown-item">
            <i data-lucide="user"></i>
            <span>Profile</span>
          </a>
          <a href="settings.php" class="dropdown-item">
            <i data-lucide="settings"></i>
            <span>Settings</span>
          </a>
          <div class="dropdown-divider"></div>
          <a href="logout.php" class="dropdown-item">
            <i data-lucide="log-out"></i>
            <span>Logout</span>
          </a>
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

        <a href="feedbacksadmin.php" class="sidebar-item active">
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
          <i data-lucide="message-square"></i>
          Feedback & Reports Management
        </h1>
        <div class="text-sm text-gray-400">
          Welcome back, <?php echo htmlspecialchars($username); ?>
        </div>
      </div>

      <!-- Flash Messages -->
      <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-500/20 border border-green-500 text-green-400 px-4 py-3 rounded-lg mb-4">
          <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
      <?php endif; ?>
      
      <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-500/20 border border-red-500 text-red-400 px-4 py-3 rounded-lg mb-4">
          <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
      <?php endif; ?>

      <?php if (isset($_SESSION['info'])): ?>
        <div class="bg-blue-500/20 border border-blue-500 text-blue-400 px-4 py-3 rounded-lg mb-4">
          <?php echo $_SESSION['info']; unset($_SESSION['info']); ?>
        </div>
      <?php endif; ?>

      <!-- Feedback Statistics -->
      <div class="stats-grid mb-8">
        <div class="card stat-card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="message-square"></i><span>Total Feedback</span></p>
              <p class="card-value"><?php echo $stats['total']; ?></p>
              <p class="text-xs text-gray-400">All feedback items</p>
            </div>
            <div class="p-3 bg-yellow-500/10 rounded-lg">
              <i data-lucide="message-square" class="w-6 h-6 text-yellow-400"></i>
            </div>
          </div>
        </div>

        <div class="card expense-card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="clock"></i><span>Pending</span></p>
              <p class="card-value"><?php echo $stats['pending']; ?></p>
              <p class="text-xs text-gray-400">Awaiting review</p>
            </div>
            <div class="p-3 bg-red-500/10 rounded-lg">
              <i data-lucide="clock" class="w-6 h-6 text-red-400"></i>
            </div>
          </div>
        </div>

        <div class="card membership-card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="eye"></i><span>Reviewed</span></p>
              <p class="card-value"><?php echo $stats['reviewed']; ?></p>
              <p class="text-xs text-gray-400">Under investigation</p>
            </div>
            <div class="p-3 bg-blue-500/10 rounded-lg">
              <i data-lucide="eye" class="w-6 h-6 text-blue-400"></i>
            </div>
          </div>
        </div>

        <div class="card profit-card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="check-circle"></i><span>Resolved</span></p>
              <p class="card-value"><?php echo $stats['resolved']; ?></p>
              <p class="text-xs text-gray-400">Completed</p>
            </div>
            <div class="p-3 bg-green-500/10 rounded-lg">
              <i data-lucide="check-circle" class="w-6 h-6 text-green-400"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Filters -->
      <div class="card">
        <div class="flex flex-wrap gap-2 justify-between mb-4">
          <div class="flex gap-2">
            <!-- Quick Status Filters -->
            <a href="?status=" class="button-sm <?php echo empty($status_filter) ? 'btn-active' : 'btn-outline'; ?>">
              All
            </a>
            <a href="?status=pending" class="button-sm <?php echo $status_filter == 'pending' ? 'btn-active' : 'btn-outline'; ?>">
              Pending
            </a>
            <a href="?status=reviewed" class="button-sm <?php echo $status_filter == 'reviewed' ? 'btn-active' : 'btn-outline'; ?>">
              Reviewed
            </a>
            <a href="?status=resolved" class="button-sm <?php echo $status_filter == 'resolved' ? 'btn-active' : 'btn-outline'; ?>">
              Resolved
            </a>
            <a href="?urgent=1" class="button-sm <?php echo $urgent_filter == '1' ? 'btn-active' : 'btn-outline'; ?>">
              <i data-lucide="alert-triangle"></i> Urgent
            </a>
          </div>
        </div>

        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4" id="filterForm">
          <div>
            <label class="form-label">Category</label>
            <select name="category" class="form-input">
              <option value="">All Categories</option>
              <option value="workout" <?php echo $category_filter == 'workout' ? 'selected' : ''; ?>>Workout</option>
              <option value="nutrition" <?php echo $category_filter == 'nutrition' ? 'selected' : ''; ?>>Nutrition</option>
              <option value="trainer" <?php echo $category_filter == 'trainer' ? 'selected' : ''; ?>>Trainer</option>
              <option value="facility" <?php echo $category_filter == 'facility' ? 'selected' : ''; ?>>Facility</option>
              <option value="equipment" <?php echo $category_filter == 'equipment' ? 'selected' : ''; ?>>Equipment</option>
              <option value="service" <?php echo $category_filter == 'service' ? 'selected' : ''; ?>>Service</option>
              <option value="other" <?php echo $category_filter == 'other' ? 'selected' : ''; ?>>Other</option>
            </select>
          </div>
          <div class="md:col-span-2">
            <label class="form-label">Search</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search feedback..." class="form-input">
          </div>
          <div class="flex items-end">
            <button type="submit" class="button-sm btn-primary w-full">
              <i data-lucide="filter"></i> Apply Filters
            </button>
          </div>
        </form>
      </div>

      <!-- Feedback Table -->
      <div class="card">
        <h2 class="text-lg font-semibold text-yellow-400 mb-4 flex items-center gap-2">
          <i data-lucide="message-square"></i>
          Customer Feedback (<?php echo $feedback_result ? $feedback_result->num_rows : 0; ?>)
        </h2>
        
        <?php if ($feedback_result && $feedback_result->num_rows > 0): ?>
          <div class="overflow-x-auto">
            <table class="w-full text-sm text-left text-gray-400">
              <thead class="text-xs text-gray-400 uppercase bg-gray-800">
                <tr>
                  <th class="px-4 py-3">User / Subject</th>
                  <th class="px-4 py-3">Category</th>
                  <th class="px-4 py-3">Rating</th>
                  <th class="px-4 py-3">Status</th>
                  <th class="px-4 py-3">Date</th>
                  <th class="px-4 py-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php while($feedback = $feedback_result->fetch_assoc()): ?>
                <tr class="border-b border-gray-700 hover:bg-gray-800 <?php echo $feedback['urgent'] ? 'urgent-indicator' : ''; ?>">
                  <td class="px-4 py-3">
                    <div class="font-medium text-white">
                      <?php if ($feedback['urgent']): ?>
                        <i data-lucide="alert-triangle" class="w-4 h-4 inline text-red-400 mr-1"></i>
                      <?php endif; ?>
                      <?php echo htmlspecialchars($feedback['subject'] ?: 'No Subject'); ?>
                    </div>
                    <div class="text-xs text-gray-400 mt-1">
                      By: <?php echo htmlspecialchars($feedback['full_name'] ?: $feedback['username'] ?: 'Anonymous'); ?>
                      (<?php echo htmlspecialchars($feedback['user_role'] ?: 'Unknown'); ?>)
                    </div>
                    <div class="text-sm text-gray-300 mt-1"><?php echo nl2br(htmlspecialchars(substr($feedback['message'], 0, 100) . (strlen($feedback['message']) > 100 ? '...' : ''))); ?></div>
                  </td>
                  <td class="px-4 py-3">
                    <span class="badge badge-outline">
                      <?php echo htmlspecialchars(ucfirst($feedback['category'])); ?>
                    </span>
                  </td>
                  <td class="px-4 py-3">
                    <?php if ($feedback['rating']): ?>
                      <div class="flex items-center gap-1">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                          <i data-lucide="star" class="w-4 h-4 <?php echo $i <= $feedback['rating'] ? 'text-yellow-400 fill-yellow-400' : 'text-gray-600'; ?>"></i>
                        <?php endfor; ?>
                      </div>
                    <?php else: ?>
                      <span class="text-gray-500">-</span>
                    <?php endif; ?>
                  </td>
                  <td class="px-4 py-3">
                    <?php
                      $status_class = '';
                      switch($feedback['status']) {
                        case 'pending': $status_class = 'badge-pending'; break;
                        case 'reviewed': $status_class = 'badge-reviewed'; break;
                        case 'resolved': $status_class = 'badge-resolved'; break;
                      }
                    ?>
                    <span class="badge <?php echo $status_class; ?>">
                      <?php echo htmlspecialchars(ucfirst($feedback['status'])); ?>
                    </span>
                  </td>
                  <td class="px-4 py-3 whitespace-nowrap">
                    <?php echo date('M j, Y g:i A', strtotime($feedback['created_at'])); ?>
                  </td>
                  <td class="px-4 py-3">
                    <div class="flex gap-2">
                      <button onclick="openFeedbackModal(<?php echo htmlspecialchars(json_encode($feedback)); ?>)" class="text-blue-400 hover:text-blue-300 transition-colors" title="View Details">
                        <i data-lucide="eye" class="w-4 h-4"></i>
                      </button>
                      <?php if (in_array($feedback['category'], ['equipment', 'facility']) && $feedback['status'] == 'pending'): ?>
                        <button onclick="openLinkMaintenanceModal(<?php echo htmlspecialchars(json_encode($feedback)); ?>)" class="text-green-400 hover:text-green-300 transition-colors" title="Link to Maintenance">
                          <i data-lucide="link" class="w-4 h-4"></i>
                        </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="empty-state">
            <i data-lucide="message-square" class="w-12 h-12 mx-auto"></i>
            <p>No feedback found</p>
            <p class="text-sm mt-2">All feedback has been processed or no matches for your filters</p>
          </div>
        <?php endif; ?>
      </div>

    </main>
  </div>

  <!-- Feedback Detail Modal -->
  <div id="feedbackModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-semibold text-yellow-400">Feedback Details</h3>
        <button onclick="closeFeedbackModal()" class="text-gray-400 hover:text-white transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>
      
      <div id="feedbackContent" class="space-y-4">
        <!-- Content will be loaded via JavaScript -->
      </div>
      
      <div class="flex gap-2 mt-6">
        <button onclick="closeFeedbackModal()" class="flex-1 button-sm btn-outline">Close</button>
      </div>
    </div>
  </div>

  <!-- Update Feedback Status Modal -->
  <div id="updateFeedbackModal" class="modal">
    <div class="modal-content">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-semibold text-yellow-400">Update Feedback Status</h3>
        <button onclick="closeUpdateFeedbackModal()" class="text-gray-400 hover:text-white transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>
      
      <form method="POST" id="updateFeedbackForm">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="feedback_id" id="update_feedback_id">
        <div class="space-y-4">
          <div>
            <label class="form-label">Subject</label>
            <input type="text" id="feedback_subject_display" class="form-input" readonly>
          </div>
          
          <div>
            <label class="form-label">New Status *</label>
            <select name="status" id="feedback_status" required class="form-input">
              <option value="pending">Pending</option>
              <option value="reviewed">Reviewed</option>
              <option value="resolved">Resolved</option>
            </select>
          </div>
          
          <div>
            <label class="form-label">Admin Notes</label>
            <textarea name="admin_notes" id="feedback_admin_notes" rows="4" class="form-input" placeholder="Add notes about how this feedback was handled..."></textarea>
          </div>
        </div>
        
        <div class="flex gap-2 mt-6">
          <button type="button" onclick="closeUpdateFeedbackModal()" class="flex-1 button-sm btn-outline">Cancel</button>
          <button type="submit" name="update_feedback_status" class="flex-1 button-sm btn-primary">Update Status</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Link to Maintenance Modal -->
  <div id="linkMaintenanceModal" class="modal">
    <div class="modal-content">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-semibold text-yellow-400">Link to Maintenance</h3>
        <button onclick="closeLinkMaintenanceModal()" class="text-gray-400 hover:text-white transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>
      
      <form method="POST" id="linkMaintenanceForm">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="feedback_id" id="link_feedback_id">
        <div class="space-y-4">
          <div>
            <label class="form-label">Feedback</label>
            <input type="text" id="link_feedback_subject" class="form-input" readonly>
          </div>
          
          <div>
            <label class="form-label">Link To *</label>
            <select name="target_type" id="target_type" required class="form-input" onchange="toggleTargetSelection()">
              <option value="">Select Type</option>
              <option value="equipment">Equipment</option>
              <option value="facility">Facility</option>
            </select>
          </div>
          
          <div id="equipment_selection" style="display: none;">
            <label class="form-label">Select Equipment</label>
            <select name="target_id" class="form-input">
              <option value="">Select Equipment</option>
              <?php 
              $equipment_result->data_seek(0); // Reset pointer
              while($equipment = $equipment_result->fetch_assoc()): ?>
                <option value="<?php echo $equipment['id']; ?>">
                  <?php echo htmlspecialchars($equipment['name']); ?> (<?php echo htmlspecialchars($equipment['category']); ?> - <?php echo htmlspecialchars($equipment['location']); ?>)
                </option>
              <?php endwhile; ?>
            </select>
          </div>
          
          <div id="facility_selection" style="display: none;">
            <label class="form-label">Select Facility</label>
            <select name="target_id" class="form-input">
              <option value="">Select Facility</option>
              <?php 
              $facilities_result->data_seek(0); // Reset pointer
              while($facility = $facilities_result->fetch_assoc()): ?>
                <option value="<?php echo $facility['id']; ?>">
                  <?php echo htmlspecialchars($facility['name']); ?>
                </option>
              <?php endwhile; ?>
            </select>
          </div>
          
          <div>
            <label class="form-label">Maintenance Notes *</label>
            <textarea name="maintenance_notes" rows="4" class="form-input" placeholder="Describe the maintenance required..." required></textarea>
          </div>
        </div>
        
        <div class="flex gap-2 mt-6">
          <button type="button" onclick="closeLinkMaintenanceModal()" class="flex-1 button-sm btn-outline">Cancel</button>
          <button type="submit" name="link_to_maintenance" class="flex-1 button-sm btn-primary">Create Maintenance Request</button>
        </div>
      </form>
    </div>
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

        // Dropdown functionality
        const userMenuButton = document.getElementById('userMenuButton');
        const userDropdown = document.getElementById('userDropdown');

        userMenuButton.addEventListener('click', (e) => {
            e.stopPropagation();
            userDropdown.classList.toggle('show');
        });

        document.addEventListener('click', (e) => {
            if (!userMenuButton.contains(e.target) && !userDropdown.contains(e.target)) {
                userDropdown.classList.remove('show');
            }
        });
    });

    // Modal functions
    function openFeedbackModal(feedback) {
        const content = document.getElementById('feedbackContent');
        let html = `
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="form-label">User</label>
                    <div class="form-input bg-gray-800">${feedback.full_name || feedback.username || 'Anonymous'} (${feedback.user_role || 'Unknown'})</div>
                </div>
                <div>
                    <label class="form-label">Category</label>
                    <div class="form-input bg-gray-800">${feedback.category}</div>
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="form-label">Status</label>
                    <div class="form-input bg-gray-800">
                        <span class="badge ${getFeedbackStatusClass(feedback.status)}">${feedback.status}</span>
                        ${feedback.urgent ? '<span class="badge badge-broken ml-2">URGENT</span>' : ''}
                    </div>
                </div>
                <div>
                    <label class="form-label">Rating</label>
                    <div class="form-input bg-gray-800">
                        ${feedback.rating ? 
                            Array.from({length: 5}, (_, i) => 
                                `<i data-lucide="star" class="w-4 h-4 inline ${i < feedback.rating ? 'text-yellow-400 fill-yellow-400' : 'text-gray-600'}"></i>`
                            ).join('') 
                            : 'No rating'
                        }
                    </div>
                </div>
            </div>
            
            <div>
                <label class="form-label">Subject</label>
                <div class="form-input bg-gray-800">${feedback.subject || 'No Subject'}</div>
            </div>
            
            <div>
                <label class="form-label">Message</label>
                <div class="form-input bg-gray-800 min-h-[100px]">${feedback.message.replace(/\n/g, '<br>')}</div>
            </div>
            
            ${feedback.admin_notes ? `
            <div>
                <label class="form-label">Admin Notes</label>
                <div class="form-input bg-gray-800 min-h-[80px]">${feedback.admin_notes.replace(/\n/g, '<br>')}</div>
            </div>
            ` : ''}
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Created</label>
                    <div class="form-input bg-gray-800">${new Date(feedback.created_at).toLocaleString()}</div>
                </div>
                <div>
                    <label class="form-label">Last Updated</label>
                    <div class="form-input bg-gray-800">${new Date(feedback.updated_at).toLocaleString()}</div>
                </div>
            </div>
        `;
        
        content.innerHTML = html;
        lucide.createIcons();
        
        // Add action buttons
        const actions = document.createElement('div');
        actions.className = 'flex gap-2 mt-4';
        actions.innerHTML = `
            <button onclick="openUpdateFeedbackModal(${JSON.stringify(feedback).replace(/"/g, '&quot;')})" class="button-sm btn-primary">
                <i data-lucide="edit"></i> Update Status
            </button>
            ${feedback.category === 'equipment' || feedback.category === 'facility' ? `
            <button onclick="openLinkMaintenanceModal(${JSON.stringify(feedback).replace(/"/g, '&quot;')})" class="button-sm btn-outline">
                <i data-lucide="link"></i> Link to Maintenance
            </button>
            ` : ''}
        `;
        content.appendChild(actions);
        
        document.getElementById('feedbackModal').style.display = 'block';
    }

    function closeFeedbackModal() {
        document.getElementById('feedbackModal').style.display = 'none';
    }

    function openUpdateFeedbackModal(feedback) {
        document.getElementById('update_feedback_id').value = feedback.id;
        document.getElementById('feedback_subject_display').value = feedback.subject || 'No Subject';
        document.getElementById('feedback_status').value = feedback.status;
        document.getElementById('feedback_admin_notes').value = feedback.admin_notes || '';
        document.getElementById('updateFeedbackModal').style.display = 'block';
    }

    function closeUpdateFeedbackModal() {
        document.getElementById('updateFeedbackModal').style.display = 'none';
    }

    function openLinkMaintenanceModal(feedback) {
        document.getElementById('link_feedback_id').value = feedback.id;
        document.getElementById('link_feedback_subject').value = feedback.subject || 'No Subject';
        
        // Set default target type based on feedback category
        if (feedback.category === 'equipment') {
            document.getElementById('target_type').value = 'equipment';
            toggleTargetSelection();
        } else if (feedback.category === 'facility') {
            document.getElementById('target_type').value = 'facility';
            toggleTargetSelection();
        }
        
        document.getElementById('linkMaintenanceModal').style.display = 'block';
    }

    function closeLinkMaintenanceModal() {
        document.getElementById('linkMaintenanceModal').style.display = 'none';
    }

    function toggleTargetSelection() {
        const targetType = document.getElementById('target_type').value;
        document.getElementById('equipment_selection').style.display = targetType === 'equipment' ? 'block' : 'none';
        document.getElementById('facility_selection').style.display = targetType === 'facility' ? 'block' : 'none';
        
        // Clear selections when switching types
        if (targetType === 'equipment') {
            document.querySelector('#facility_selection select').value = '';
        } else if (targetType === 'facility') {
            document.querySelector('#equipment_selection select').value = '';
        }
    }

    function getFeedbackStatusClass(status) {
        switch(status) {
            case 'pending': return 'badge-pending';
            case 'reviewed': return 'badge-reviewed';
            case 'resolved': return 'badge-resolved';
            default: return 'badge-pending';
        }
    }

    // Close modals when clicking outside
    window.onclick = function(event) {
        const modals = ['feedbackModal', 'updateFeedbackModal', 'linkMaintenanceModal'];
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        });
    }
  </script>
</body>
</html>

<?php 
// Close statements and connection properly
if (isset($feedback_stmt)) $feedback_stmt->close();
if (isset($feedback_result)) $feedback_result->free();
if (isset($equipment_result)) $equipment_result->free();
if (isset($facilities_result)) $facilities_result->free();
if (isset($stats_result)) $stats_result->free();
$conn->close(); 
?>



