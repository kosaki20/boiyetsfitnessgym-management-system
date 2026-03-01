<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
if (!isset($_SESSION['user_id'])) {
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

// Handle equipment status updates
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_equipment_status'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = "Security token invalid. Please try again.";
        header("Location: equipment_monitoring.php");
        exit();
    }

    $equipment_id = (int)$_POST['equipment_id'];
    $new_status = $_POST['status'];
    $note = trim($_POST['note']);
    $updated_by = $_SESSION['user_id'];

    // Get current status
    $current_sql = "SELECT status FROM equipment WHERE id = ?";
    $current_stmt = $conn->prepare($current_sql);
    
    if (!$current_stmt) {
        $_SESSION['error'] = "SQL prepare failed: " . $conn->error;
        header("Location: equipment_monitoring.php");
        exit();
    }
    
    $current_stmt->bind_param("i", $equipment_id);
    
    if (!$current_stmt->execute()) {
        $_SESSION['error'] = "Error fetching current equipment status: " . $current_stmt->error;
        header("Location: equipment_monitoring.php");
        exit();
    }
    
    $current_result = $current_stmt->get_result();
    
    if ($current_result->num_rows > 0) {
        $current_data = $current_result->fetch_assoc();
        $old_status = $current_data['status'];

        // Update equipment status
        $update_sql = "UPDATE equipment SET status = ?, last_updated = NOW() WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        
        if (!$update_stmt) {
            $_SESSION['error'] = "SQL prepare failed: " . $conn->error;
            header("Location: equipment_monitoring.php");
            exit();
        }
        
        $update_stmt->bind_param("si", $new_status, $equipment_id);
        
        if ($update_stmt->execute()) {
            // Log the change - FIX: Ensure updated_by is properly set
            $log_sql = "INSERT INTO equipment_logs (equipment_id, old_status, new_status, updated_by, note) VALUES (?, ?, ?, ?, ?)";
            $log_stmt = $conn->prepare($log_sql);
            
            if (!$log_stmt) {
                $_SESSION['error'] = "Log SQL prepare failed: " . $conn->error;
                header("Location: equipment_monitoring.php");
                exit();
            }
            
            $log_stmt->bind_param("issis", $equipment_id, $old_status, $new_status, $updated_by, $note);
            
            if ($log_stmt->execute()) {
                $_SESSION['success'] = "Equipment status updated successfully!";
            } else {
                $_SESSION['error'] = "Status updated but failed to log: " . $log_stmt->error;
            }
            $log_stmt->close();
        } else {
            $_SESSION['error'] = "Error updating equipment status: " . $update_stmt->error;
        }
        $update_stmt->close();
    } else {
        $_SESSION['error'] = "Equipment not found!";
    }
    $current_stmt->close();
    header("Location: equipment_monitoring.php");
    exit();
}
// Handle facility status updates
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_facility_status'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = "Security token invalid. Please try again.";
        header("Location: equipment_monitoring.php");
        exit();
    }

    $facility_id = (int)$_POST['facility_id'];
    $new_condition = $_POST['condition'];
    $notes = trim($_POST['notes']);
    $updated_by = $_SESSION['user_id'];

    // FIX: Use correct column name 'facility_condition'
    $update_sql = "UPDATE facilities SET facility_condition = ?, notes = ?, last_updated = NOW(), updated_by = ? WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    
    if (!$update_stmt) {
        $_SESSION['error'] = "SQL prepare failed: " . $conn->error;
        header("Location: equipment_monitoring.php");
        exit();
    }
    
    $update_stmt->bind_param("ssii", $new_condition, $notes, $updated_by, $facility_id);
    
    if ($update_stmt->execute()) {
        $_SESSION['success'] = "Facility condition updated successfully!";
    } else {
        $_SESSION['error'] = "Error updating facility condition: " . $update_stmt->error;
    }
    $update_stmt->close();
    header("Location: equipment_monitoring.php");
    exit();
}

// Handle new equipment addition (admin only)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_equipment']) && $_SESSION['role'] == 'admin') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = "Security token invalid. Please try again.";
        header("Location: equipment_monitoring.php");
        exit();
    }

    $name = trim($_POST['name']);
    $category = trim($_POST['category']);
    $location = trim($_POST['location']);
    $status = $_POST['status'];
    $notes = trim($_POST['notes']);
    $created_by = $_SESSION['user_id'];

    $insert_sql = "INSERT INTO equipment (name, category, location, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?)";
    $insert_stmt = $conn->prepare($insert_sql);
    $insert_stmt->bind_param("sssssi", $name, $category, $location, $status, $notes, $created_by);
    
    if ($insert_stmt->execute()) {
        $_SESSION['success'] = "Equipment added successfully!";
    } else {
        $_SESSION['error'] = "Error adding equipment: " . $conn->error;
    }
    $insert_stmt->close();
    header("Location: equipment_monitoring.php");
    exit();
}

// Get filter parameters
$tab = $_GET['tab'] ?? 'equipment';
$status_filter = $_GET['status'] ?? '';
$category_filter = $_GET['category'] ?? '';
$location_filter = $_GET['location'] ?? '';
$search = $_GET['search'] ?? '';

// Build equipment query with filters
$equipment_where_conditions = ["1=1"];
$equipment_params = [];
$equipment_types = "";

if ($status_filter) {
    $equipment_where_conditions[] = "e.status = ?";
    $equipment_params[] = $status_filter;
    $equipment_types .= "s";
}

if ($category_filter) {
    $equipment_where_conditions[] = "e.category = ?";
    $equipment_params[] = $category_filter;
    $equipment_types .= "s";
}

if ($location_filter) {
    $equipment_where_conditions[] = "e.location = ?";
    $equipment_params[] = $location_filter;
    $equipment_types .= "s";
}

if ($search) {
    $equipment_where_conditions[] = "(e.name LIKE ? OR e.notes LIKE ?)";
    $equipment_params[] = "%$search%";
    $equipment_params[] = "%$search%";
    $equipment_types .= "ss";
}

$equipment_where_sql = implode(" AND ", $equipment_where_conditions);

// Get equipment data
$equipment_sql = "SELECT e.*, u.username as created_by_name 
                  FROM equipment e 
                  LEFT JOIN users u ON e.created_by = u.id 
                  WHERE $equipment_where_sql 
                  ORDER BY e.name ASC";

$equipment_stmt = $conn->prepare($equipment_sql);
if ($equipment_stmt && !empty($equipment_params)) {
    $equipment_stmt->bind_param($equipment_types, ...$equipment_params);
    $equipment_stmt->execute();
    $equipment_result = $equipment_stmt->get_result();
} else {
    $equipment_result = $conn->query($equipment_sql);
}

// Get facilities data
$facilities_sql = "SELECT f.*, u.username as updated_by_name 
                   FROM facilities f 
                   LEFT JOIN users u ON f.updated_by = u.id 
                   ORDER BY f.name ASC";
$facilities_result = $conn->query($facilities_sql);

// Get equipment statistics
$stats_sql = "SELECT COUNT(*) as total FROM equipment";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get unique categories and locations for filters
$categories_result = $conn->query("SELECT DISTINCT category FROM equipment ORDER BY category");
$locations_result = $conn->query("SELECT DISTINCT location FROM equipment ORDER BY location");

$username = $_SESSION['username'] ?? 'User';
$role = $_SESSION['role'] ?? 'member';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BOIYETS FITNESS GYM - Equipment Monitoring</title>
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

.btn-danger {
  background: rgba(239, 68, 68, 0.2);
  color: #ef4444;
  border: 1px solid #ef4444;
}

.btn-danger:hover {
  background: #ef4444;
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

/* Tab styles */
.tab-container {
  display: flex;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  margin-bottom: 1rem;
}

.tab {
  padding: 0.75rem 1.5rem;
  cursor: pointer;
  border-bottom: 2px solid transparent;
  transition: all 0.2s ease;
  color: #9ca3af;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.tab.active {
  color: #fbbf24;
  border-bottom-color: #fbbf24;
  background: rgba(251, 191, 36, 0.05);
}

.tab:hover {
  color: #fbbf24;
  background: rgba(251, 191, 36, 0.05);
}

.tab-content {
  display: none;
}

.tab-content.active {
  display: block;
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
<a href="equipment_monitoring.php" class="sidebar-item active">
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
          <i data-lucide="wrench"></i>
          Equipment & Facility Monitoring
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

      <!-- Simple Statistics -->
      <div class="stats-grid mb-8">
        <div class="card stat-card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="dumbbell"></i><span>Total Equipment</span></p>
              <p class="card-value"><?php echo $stats['total']; ?></p>
              <p class="text-xs text-gray-400">All items tracked</p>
            </div>
            <div class="p-3 bg-yellow-500/10 rounded-lg">
              <i data-lucide="dumbbell" class="w-6 h-6 text-yellow-400"></i>
            </div>
          </div>
        </div>

        <div class="card membership-card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="building"></i><span>Facility Areas</span></p>
              <p class="card-value">5</p>
              <p class="text-xs text-gray-400">Monitored areas</p>
            </div>
            <div class="p-3 bg-blue-500/10 rounded-lg">
              <i data-lucide="building" class="w-6 h-6 text-blue-400"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Tabs -->
      <div class="card">
        <div class="tab-container">
          <div class="tab <?php echo $tab == 'equipment' ? 'active' : ''; ?>" onclick="switchTab('equipment')">
            <i data-lucide="dumbbell"></i> Equipment
          </div>
          <div class="tab <?php echo $tab == 'facilities' ? 'active' : ''; ?>" onclick="switchTab('facilities')">
            <i data-lucide="building"></i> Facilities
          </div>
        </div>

     <!-- Action Buttons -->
<div class="flex flex-wrap gap-2 justify-between mb-4">
  <div class="flex gap-2">
    <!-- Dynamic Action Button -->
    <div id="actionButtonContainer">
      <?php if ($tab == 'equipment' && $role == 'admin'): ?>
        <button onclick="openAddEquipmentModal()" class="button-sm btn-primary">
          <i data-lucide="plus"></i> Add Equipment
        </button>
      <?php endif; ?>
      <!-- No add button for facilities since they are fixed areas -->
    </div>
  </div>
  <div class="flex gap-2">
    <!-- Quick Status Filters -->
    <?php if ($tab == 'equipment'): ?>
      <!-- Equipment filters (keep your existing code) -->
      <a href="?tab=equipment&status=" class="button-sm <?php echo empty($status_filter) ? 'btn-active' : 'btn-outline'; ?>">
        All
      </a>
      <a href="?tab=equipment&status=Good" class="button-sm <?php echo $status_filter == 'Good' ? 'btn-active' : 'btn-outline'; ?>">
        Good
      </a>
      <a href="?tab=equipment&status=Needs Maintenance" class="button-sm <?php echo $status_filter == 'Needs Maintenance' ? 'btn-active' : 'btn-outline'; ?>">
        Maintenance
      </a>
      <a href="?tab=equipment&status=Under Repair" class="button-sm <?php echo $status_filter == 'Under Repair' ? 'btn-active' : 'btn-outline'; ?>">
        Repair
      </a>
      <a href="?tab=equipment&status=Broken" class="button-sm <?php echo $status_filter == 'Broken' ? 'btn-active' : 'btn-outline'; ?>">
        Broken
      </a>
    <?php elseif ($tab == 'facilities'): ?>
      <!-- Facility condition filters -->
      <a href="?tab=facilities&status=" class="button-sm <?php echo empty($status_filter) ? 'btn-active' : 'btn-outline'; ?>">
        All
      </a>
      <a href="?tab=facilities&status=Good" class="button-sm <?php echo $status_filter == 'Good' ? 'btn-active' : 'btn-outline'; ?>">
        Good
      </a>
      <a href="?tab=facilities&status=Needs Maintenance" class="button-sm <?php echo $status_filter == 'Needs Maintenance' ? 'btn-active' : 'btn-outline'; ?>">
        Maintenance
      </a>
      <a href="?tab=facilities&status=Under Repair" class="button-sm <?php echo $status_filter == 'Under Repair' ? 'btn-active' : 'btn-outline'; ?>">
        Repair
      </a>
      <a href="?tab=facilities&status=Closed" class="button-sm <?php echo $status_filter == 'Closed' ? 'btn-active' : 'btn-outline'; ?>">
        Closed
      </a>
    <?php endif; ?>
  </div>
</div>

        <!-- Filters -->
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4" id="filterForm">
          <input type="hidden" name="tab" value="<?php echo $tab; ?>">
          <div>
            <label class="form-label">Category</label>
            <select name="category" class="form-input">
              <option value="">All Categories</option>
              <?php while($cat = $categories_result->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($cat['category']); ?>" <?php echo $category_filter == $cat['category'] ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($cat['category']); ?>
                </option>
              <?php endwhile; ?>
            </select>
          </div>
          <div>
            <label class="form-label">Location</label>
            <select name="location" class="form-input">
              <option value="">All Locations</option>
              <?php while($loc = $locations_result->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($loc['location']); ?>" <?php echo $location_filter == $loc['location'] ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($loc['location']); ?>
                </option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="md:col-span-2">
            <label class="form-label">Search</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search equipment..." class="form-input">
          </div>
          <div class="flex items-end">
            <button type="submit" class="button-sm btn-primary w-full">
              <i data-lucide="filter"></i> Apply Filters
            </button>
          </div>
        </form>
      </div>

      <!-- Equipment Tab -->
      <div id="equipment-tab" class="tab-content <?php echo $tab == 'equipment' ? 'active' : ''; ?>">
        <!-- Equipment Table -->
        <div class="card">
          <h2 class="text-lg font-semibold text-yellow-400 mb-4 flex items-center gap-2">
            <i data-lucide="dumbbell"></i>
            Equipment Inventory (<?php echo $equipment_result ? $equipment_result->num_rows : 0; ?>)
          </h2>
          
          <?php if ($equipment_result && $equipment_result->num_rows > 0): ?>
            <div class="overflow-x-auto">
              <table class="w-full text-sm text-left text-gray-400">
                <thead class="text-xs text-gray-400 uppercase bg-gray-800">
                  <tr>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Category</th>
                    <th class="px-4 py-3">Location</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Last Updated</th>
                    <?php if ($role == 'admin' || $role == 'trainer'): ?>
                      <th class="px-4 py-3">Actions</th>
                    <?php endif; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php while($equipment = $equipment_result->fetch_assoc()): ?>
                  <tr class="border-b border-gray-700 hover:bg-gray-800">
                    <td class="px-4 py-3 whitespace-nowrap">
                      <div class="font-medium text-white"><?php echo htmlspecialchars($equipment['name']); ?></div>
                      <?php if (!empty($equipment['notes'])): ?>
                        <div class="text-xs text-gray-400 mt-1"><?php echo htmlspecialchars($equipment['notes']); ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="px-4 py-3"><?php echo htmlspecialchars($equipment['category']); ?></td>
                    <td class="px-4 py-3"><?php echo htmlspecialchars($equipment['location']); ?></td>
                    <td class="px-4 py-3">
                      <?php
                        $status_class = '';
                        switch($equipment['status']) {
                          case 'Good': $status_class = 'badge-good'; break;
                          case 'Needs Maintenance': $status_class = 'badge-needs-maintenance'; break;
                          case 'Under Repair': $status_class = 'badge-under-repair'; break;
                          case 'Broken': $status_class = 'badge-broken'; break;
                        }
                      ?>
                      <span class="badge <?php echo $status_class; ?>">
                        <span class="status-indicator status-<?php echo strtolower(str_replace(' ', '-', $equipment['status'])); ?>"></span>
                        <?php echo htmlspecialchars($equipment['status']); ?>
                      </span>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                      <?php echo date('M j, Y g:i A', strtotime($equipment['last_updated'])); ?>
                    </td>
                    <?php if ($role == 'admin' || $role == 'trainer'): ?>
                      <td class="px-4 py-3">
                        <div class="flex gap-2">
                          <button onclick="openUpdateStatusModal(<?php echo htmlspecialchars(json_encode($equipment)); ?>)" class="text-blue-400 hover:text-blue-300 transition-colors" title="Update Status">
                            <i data-lucide="edit" class="w-4 h-4"></i>
                          </button>
                          <?php if ($role == 'admin'): ?>
                            <button onclick="openViewLogsModal(<?php echo $equipment['id']; ?>)" class="text-green-400 hover:text-green-300 transition-colors" title="View History">
                              <i data-lucide="history" class="w-4 h-4"></i>
                            </button>
                          <?php endif; ?>
                        </div>
                      </td>
                    <?php endif; ?>
                  </tr>
                  <?php endwhile; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="empty-state">
              <i data-lucide="dumbbell" class="w-12 h-12 mx-auto"></i>
              <p>No equipment found</p>
              <p class="text-sm mt-2">Add your first equipment item using the button above</p>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Facilities Tab -->
      <div id="facilities-tab" class="tab-content <?php echo $tab == 'facilities' ? 'active' : ''; ?>">
        <!-- Facilities Table -->
        <div class="card">
          <h2 class="text-lg font-semibold text-yellow-400 mb-4 flex items-center gap-2">
            <i data-lucide="building"></i>
            Facility Areas (<?php echo $facilities_result ? $facilities_result->num_rows : 0; ?>)
          </h2>
          
          <?php if ($facilities_result && $facilities_result->num_rows > 0): ?>
            <div class="overflow-x-auto">
              <table class="w-full text-sm text-left text-gray-400">
                <thead class="text-xs text-gray-400 uppercase bg-gray-800">
                  <tr>
                    <th class="px-4 py-3">Facility Name</th>
                    <th class="px-4 py-3">Condition</th>
                    <th class="px-4 py-3">Notes</th>
                    <th class="px-4 py-3">Last Updated</th>
                    <?php if ($role == 'admin' || $role == 'trainer'): ?>
                      <th class="px-4 py-3">Actions</th>
                    <?php endif; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php while($facility = $facilities_result->fetch_assoc()): ?>
<tr class="border-b border-gray-700 hover:bg-gray-800">
  <td class="px-4 py-3 font-medium text-white"><?php echo htmlspecialchars($facility['name']); ?></td>
  <td class="px-4 py-3">
    <?php
      $condition_class = '';
      // FIX: Change 'condition' to 'facility_condition'
      switch($facility['facility_condition']) {
        case 'Good': $condition_class = 'badge-good'; break;
        case 'Needs Maintenance': $condition_class = 'badge-needs-maintenance'; break;
        case 'Under Repair': $condition_class = 'badge-under-repair'; break;
        case 'Closed': $condition_class = 'badge-closed'; break;
      }
    ?>
    <span class="badge <?php echo $condition_class; ?>">
      <!-- FIX: Change 'condition' to 'facility_condition' -->
      <span class="status-indicator status-<?php echo strtolower(str_replace(' ', '-', $facility['facility_condition'])); ?>"></span>
      <!-- FIX: Change 'condition' to 'facility_condition' -->
      <?php echo htmlspecialchars($facility['facility_condition']); ?>
    </span>
  </td>
  <td class="px-4 py-3">
    <?php echo !empty($facility['notes']) ? htmlspecialchars($facility['notes']) : '<span class="text-gray-500">-</span>'; ?>
  </td>
  <td class="px-4 py-3 whitespace-nowrap">
    <?php echo date('M j, Y g:i A', strtotime($facility['last_updated'])); ?>
    <?php if (!empty($facility['updated_by_name'])): ?>
      <div class="text-xs text-gray-400">by <?php echo htmlspecialchars($facility['updated_by_name']); ?></div>
    <?php endif; ?>
  </td>
  <?php if ($role == 'admin' || $role == 'trainer'): ?>
    <td class="px-4 py-3">
      <button onclick="openUpdateFacilityModal(<?php echo htmlspecialchars(json_encode($facility)); ?>)" class="text-blue-400 hover:text-blue-300 transition-colors" title="Update Condition">
        <i data-lucide="edit" class="w-4 h-4"></i>
      </button>
    </td>
  <?php endif; ?>
</tr>
<?php endwhile; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="empty-state">
              <i data-lucide="building" class="w-12 h-12 mx-auto"></i>
              <p>No facilities found</p>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </main>
  </div>

  <!-- Add Equipment Modal (Admin Only) -->
  <?php if ($role == 'admin'): ?>
  <div id="addEquipmentModal" class="modal">
    <div class="modal-content">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-semibold text-yellow-400">Add New Equipment</h3>
        <button onclick="closeAddEquipmentModal()" class="text-gray-400 hover:text-white transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>
      
      <form method="POST" id="addEquipmentForm">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <div class="space-y-4">
          <div>
            <label class="form-label">Equipment Name *</label>
            <input type="text" name="name" required class="form-input" placeholder="e.g., Treadmill #1">
          </div>
          
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="form-label">Category *</label>
              <input type="text" name="category" required class="form-input" placeholder="e.g., Cardio Machine">
            </div>
            <div>
              <label class="form-label">Location *</label>
              <input type="text" name="location" required class="form-input" placeholder="e.g., Cardio Area">
            </div>
          </div>
          
          <div>
            <label class="form-label">Initial Status *</label>
            <select name="status" required class="form-input">
              <option value="Good">Good</option>
              <option value="Needs Maintenance">Needs Maintenance</option>
              <option value="Under Repair">Under Repair</option>
              <option value="Broken">Broken</option>
            </select>
          </div>
          
          <div>
            <label class="form-label">Notes</label>
            <textarea name="notes" rows="3" class="form-input" placeholder="Optional notes about the equipment"></textarea>
          </div>
        </div>
        
        <div class="flex gap-2 mt-6">
          <button type="button" onclick="closeAddEquipmentModal()" class="flex-1 button-sm btn-outline">Cancel</button>
          <button type="submit" name="add_equipment" class="flex-1 button-sm btn-primary">Add Equipment</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Update Equipment Status Modal -->
  <div id="updateStatusModal" class="modal">
    <div class="modal-content">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-semibold text-yellow-400">Update Equipment Status</h3>
        <button onclick="closeUpdateStatusModal()" class="text-gray-400 hover:text-white transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>
      
      <form method="POST" id="updateStatusForm">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="equipment_id" id="update_equipment_id">
        <div class="space-y-4">
          <div>
            <label class="form-label">Equipment</label>
            <input type="text" id="equipment_name_display" class="form-input" readonly>
          </div>
          
          <div>
            <label class="form-label">New Status *</label>
            <select name="status" id="new_status" required class="form-input">
              <option value="Good">Good</option>
              <option value="Needs Maintenance">Needs Maintenance</option>
              <option value="Under Repair">Under Repair</option>
              <option value="Broken">Broken</option>
            </select>
          </div>
          
          <div>
            <label class="form-label">Note</label>
            <textarea name="note" id="status_note" rows="3" class="form-input" placeholder="Describe the issue or repair details..."></textarea>
          </div>
        </div>
        
        <div class="flex gap-2 mt-6">
          <button type="button" onclick="closeUpdateStatusModal()" class="flex-1 button-sm btn-outline">Cancel</button>
          <button type="submit" name="update_equipment_status" class="flex-1 button-sm btn-primary">Update Status</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Update Facility Modal -->
  <div id="updateFacilityModal" class="modal">
    <div class="modal-content">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-semibold text-yellow-400">Update Facility Condition</h3>
        <button onclick="closeUpdateFacilityModal()" class="text-gray-400 hover:text-white transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>
      
      <form method="POST" id="updateFacilityForm">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="facility_id" id="update_facility_id">
        <div class="space-y-4">
          <div>
            <label class="form-label">Facility</label>
            <input type="text" id="facility_name_display" class="form-input" readonly>
          </div>
          
          <!-- In the update facility modal -->
<div>
    <label class="form-label">New Condition *</label>
    <select name="condition" id="new_condition" required class="form-input">
        <option value="Good">Good</option>
        <option value="Needs Maintenance">Needs Maintenance</option>
        <option value="Under Repair">Under Repair</option>
        <option value="Closed">Closed</option>
    </select>
</div>
          
          <div>
            <label class="form-label">Notes</label>
            <textarea name="notes" id="facility_notes" rows="3" class="form-input" placeholder="Describe the issue or maintenance details..."></textarea>
          </div>
        </div>
        
        <div class="flex gap-2 mt-6">
          <button type="button" onclick="closeUpdateFacilityModal()" class="flex-1 button-sm btn-outline">Cancel</button>
          <button type="submit" name="update_facility_status" class="flex-1 button-sm btn-primary">Update Condition</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Equipment Logs Modal -->
  <div id="viewLogsModal" class="modal">
    <div class="modal-content" style="max-width: 700px;">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-semibold text-yellow-400">Equipment History</h3>
        <button onclick="closeViewLogsModal()" class="text-gray-400 hover:text-white transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>
      
      <div id="logsContent" class="max-h-96 overflow-y-auto">
        <div class="text-center py-8 text-gray-500">
          <i data-lucide="loader" class="w-8 h-8 mx-auto animate-spin"></i>
          <p class="mt-2">Loading history...</p>
        </div>
      </div>
      
      <div class="flex justify-end mt-4">
        <button onclick="closeViewLogsModal()" class="button-sm btn-outline">Close</button>
      </div>
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

    // Modal functions - SINGLE VERSION (no duplicates)
    function openAddEquipmentModal() {
        console.log('Opening add equipment modal');
        document.getElementById('addEquipmentModal').style.display = 'block';
    }

    function closeAddEquipmentModal() {
        document.getElementById('addEquipmentModal').style.display = 'none';
    }

    function openUpdateStatusModal(equipment) {
        console.log('Opening modal for equipment:', equipment);
        document.getElementById('update_equipment_id').value = equipment.id;
        document.getElementById('equipment_name_display').value = equipment.name;
        document.getElementById('new_status').value = equipment.status;
        document.getElementById('status_note').value = '';
        document.getElementById('updateStatusModal').style.display = 'block';
    }

    function closeUpdateStatusModal() {
        document.getElementById('updateStatusModal').style.display = 'none';
    }

function openUpdateFacilityModal(facility) {
    document.getElementById('update_facility_id').value = facility.id;
    document.getElementById('facility_name_display').value = facility.name;
    document.getElementById('new_condition').value = facility.facility_condition; // This is correct
    document.getElementById('facility_notes').value = facility.notes || '';
    document.getElementById('updateFacilityModal').style.display = 'block';
}
    function closeUpdateFacilityModal() {
        document.getElementById('updateFacilityModal').style.display = 'none';
    }

    function openViewLogsModal(equipmentId) {
        console.log('Opening logs modal for equipment ID:', equipmentId);
        document.getElementById('viewLogsModal').style.display = 'block';
        
        // Load logs via AJAX
        fetch(`equipment_ajax.php?action=get_logs&equipment_id=${equipmentId}`)
            .then(response => response.json())
            .then(data => {
                console.log('Logs data received:', data);
                const logsContent = document.getElementById('logsContent');
                if (data.success && data.logs.length > 0) {
                    let html = '<div class="space-y-3">';
                    data.logs.forEach(log => {
                        html += `
                            <div class="border border-gray-700 rounded-lg p-3">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <span class="badge ${getStatusBadgeClass(log.old_status)}">${log.old_status}</span>
                                        <i data-lucide="arrow-right" class="w-4 h-4 inline mx-2 text-gray-400"></i>
                                        <span class="badge ${getStatusBadgeClass(log.new_status)}">${log.new_status}</span>
                                    </div>
                                    <span class="text-xs text-gray-400">${log.formatted_date || log.date_updated}</span>
                                </div>
                                ${log.note ? `<div class="mt-2 text-sm text-gray-300">${log.note}</div>` : ''}
                                <div class="mt-1 text-xs text-gray-400">Updated by: ${log.updated_by_name}</div>
                            </div>
                        `;
                    });
                    html += '</div>';
                    logsContent.innerHTML = html;
                    lucide.createIcons();
                } else {
                    logsContent.innerHTML = `
                        <div class="text-center py-8 text-gray-500">
                            <i data-lucide="history" class="w-12 h-12 mx-auto"></i>
                            <p class="mt-2">No history available</p>
                        </div>
                    `;
                    lucide.createIcons();
                }
            })
            .catch(error => {
                console.error('Error loading logs:', error);
                document.getElementById('logsContent').innerHTML = `
                    <div class="text-center py-8 text-red-500">
                        <i data-lucide="alert-circle" class="w-12 h-12 mx-auto"></i>
                        <p class="mt-2">Error loading history</p>
                    </div>
                `;
                lucide.createIcons();
            });
    }

    function closeViewLogsModal() {
        document.getElementById('viewLogsModal').style.display = 'none';
    }

    function getStatusBadgeClass(status) {
        switch(status) {
            case 'Good': return 'badge-good';
            case 'Needs Maintenance': return 'badge-needs-maintenance';
            case 'Under Repair': return 'badge-under-repair';
            case 'Broken': return 'badge-broken';
            case 'Closed': return 'badge-closed';
            default: return 'badge-good';
        }
    }

    // Tab switching
    function switchTab(tabName) {
        // Update URL without reloading page
        const url = new URL(window.location);
        url.searchParams.set('tab', tabName);
        window.history.replaceState({}, '', url);
        
        // Hide all tab contents
        document.querySelectorAll('.tab-content').forEach(tab => {
            tab.classList.remove('active');
        });
        
        // Show selected tab content
        document.getElementById(tabName + '-tab').classList.add('active');
        
        // Update tab active states
        document.querySelectorAll('.tab').forEach(tab => {
            tab.classList.remove('active');
        });
        event.currentTarget.classList.add('active');
        
        // Update the hidden tab field in filter form
        document.querySelector('input[name="tab"]').value = tabName;
    }

    // Close modals when clicking outside
    window.onclick = function(event) {
        const modals = ['addEquipmentModal', 'updateStatusModal', 'updateFacilityModal', 'viewLogsModal'];
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
if (isset($equipment_stmt)) $equipment_stmt->close();
if (isset($equipment_result)) $equipment_result->free();
if (isset($facilities_result)) $facilities_result->free();
if (isset($stats_result)) $stats_result->free();
if (isset($categories_result)) $categories_result->free();
if (isset($locations_result)) $locations_result->free();
$conn->close(); 
?>



