<?php
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

// Include chat functions if the file exists
$unread_count = 0;
if (file_exists('chat_functions.php')) {
    require_once 'chat_functions.php';
    $unread_count = getUnreadCount($_SESSION['user_id'], $conn);
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$category_filter = $_GET['category'] ?? '';
$export = $_GET['export'] ?? '';

// Build query for equipment needing attention
$where_conditions = ["e.status IN ('Needs Maintenance', 'Under Repair', 'Broken')"];
$params = [];
$types = "";

if ($status_filter) {
    $where_conditions[] = "e.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($category_filter) {
    $where_conditions[] = "e.category = ?";
    $params[] = $category_filter;
    $types .= "s";
}

$where_sql = implode(" AND ", $where_conditions);
// Get maintenance equipment - SIMPLIFIED
$maintenance_sql = "SELECT e.*, u.username as updated_by_name,
                    el.note as last_note, el.date_updated as last_log_date
                    FROM equipment e 
                    LEFT JOIN users u ON e.created_by = u.id 
                    LEFT JOIN equipment_logs el ON e.id = el.equipment_id 
                    WHERE $where_sql 
                    ORDER BY e.last_updated DESC, e.name ASC";

$maintenance_stmt = $conn->prepare($maintenance_sql);
if ($maintenance_stmt && !empty($params)) {
    $maintenance_stmt->bind_param($types, ...$params);
    $maintenance_stmt->execute();
    $maintenance_result = $maintenance_stmt->get_result();
} else {
    $maintenance_result = $conn->query($maintenance_sql);
}

// Get facilities needing attention - FIXED: using correct column name 'facility_condition'
$facilities_sql = "SELECT f.*, u.username as updated_by_name 
                   FROM facilities f 
                   LEFT JOIN users u ON f.updated_by = u.id 
                   WHERE f.facility_condition IN ('Needs Maintenance', 'Under Repair', 'Closed')
                   ORDER BY f.name ASC";
$facilities_result = $conn->query($facilities_sql);

// Get unique categories for filter
$categories_result = $conn->query("SELECT DISTINCT category FROM equipment ORDER BY category");

// Get maintenance statistics
$stats_sql = "SELECT 
                COUNT(*) as total_issues,
                SUM(CASE WHEN status = 'Needs Maintenance' THEN 1 ELSE 0 END) as needs_maintenance,
                SUM(CASE WHEN status = 'Under Repair' THEN 1 ELSE 0 END) as under_repair,
                SUM(CASE WHEN status = 'Broken' THEN 1 ELSE 0 END) as broken
              FROM equipment 
              WHERE status IN ('Needs Maintenance', 'Under Repair', 'Broken')";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Handle export
if ($export == 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="maintenance_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Equipment Name', 'Category', 'Location', 'Status', 'Last Updated', 'Note']);
    
    // Reset and re-fetch equipment data for export
    $export_sql = "SELECT e.* FROM equipment e WHERE $where_sql ORDER BY e.name ASC";
    $export_stmt = $conn->prepare($export_sql);
    if ($export_stmt && !empty($params)) {
        $export_stmt->bind_param($types, ...$params);
        $export_stmt->execute();
        $export_result = $export_stmt->get_result();
    } else {
        $export_result = $conn->query($export_sql);
    }
    
    while($row = $export_result->fetch_assoc()) {
        fputcsv($output, [
            $row['name'],
            $row['category'],
            $row['location'],
            $row['status'],
            $row['last_updated'],
            $row['notes'] ?: 'No notes'
        ]);
    }
    fclose($output);
    exit();
}

$username = $_SESSION['username'] ?? 'User';
$role = $_SESSION['role'] ?? 'trainer';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BOIYETS FITNESS GYM - Maintenance Report</title>
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
    
    /* Additional styles for export dropdown */
    .export-container {
      position: relative;
      display: inline-block;
    }

    .export-dropdown {
      display: none;
      position: absolute;
      top: 100%;
      right: 0;
      background: #1a1a1a;
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 8px;
      padding: 0.5rem;
      min-width: 200px;
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
      z-index: 1000;
    }

    .export-dropdown.show {
      display: block;
    }
    
    .export-option {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.75rem;
      color: #e2e8f0;
      border-radius: 6px;
      cursor: pointer;
      transition: all 0.2s ease;
      text-decoration: none;
      background: transparent;
      border: none;
      width: 100%;
      text-align: left;
      font-size: 0.875rem;
    }
    
    .export-option:hover {
      background: rgba(251, 191, 36, 0.1);
      color: #fbbf24;
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
            <p><?php echo htmlspecialchars($_SESSION['role'] ?? 'Trainer'); ?></p>
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



<a href="maintenance_report.php" class="sidebar-item active">
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
          <i data-lucide="clipboard-list"></i>
          Maintenance Report
        </h1>
        <div class="text-sm text-gray-400">
          Welcome back, <?php echo htmlspecialchars($username); ?>
        </div>
      </div>

      <!-- Maintenance Statistics -->
      <div class="stats-grid mb-8">
        <div class="card expense-card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="alert-triangle"></i><span>Total Issues</span></p>
              <p class="card-value"><?php echo $stats['total_issues']; ?></p>
              <p class="text-xs text-gray-400">All maintenance items</p>
            </div>
            <div class="p-3 bg-red-500/10 rounded-lg">
              <i data-lucide="alert-triangle" class="w-6 h-6 text-red-400"></i>
            </div>
          </div>
        </div>

        <div class="card expense-card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="tool"></i><span>Needs Maintenance</span></p>
              <p class="card-value"><?php echo $stats['needs_maintenance']; ?></p>
              <p class="text-xs text-gray-400">Requires attention</p>
            </div>
            <div class="p-3 bg-red-500/10 rounded-lg">
              <i data-lucide="tool" class="w-6 h-6 text-red-400"></i>
            </div>
          </div>
        </div>

        <div class="card membership-card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="wrench"></i><span>Under Repair</span></p>
              <p class="card-value"><?php echo $stats['under_repair']; ?></p>
              <p class="text-xs text-gray-400">Being fixed</p>
            </div>
            <div class="p-3 bg-blue-500/10 rounded-lg">
              <i data-lucide="wrench" class="w-6 h-6 text-blue-400"></i>
            </div>
          </div>
        </div>

        <div class="card stat-card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="x-circle"></i><span>Broken</span></p>
              <p class="card-value"><?php echo $stats['broken']; ?></p>
              <p class="text-xs text-gray-400">Out of service</p>
            </div>
            <div class="p-3 bg-yellow-500/10 rounded-lg">
              <i data-lucide="x-circle" class="w-6 h-6 text-yellow-400"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Action Bar -->
      <div class="card">
        <div class="flex flex-wrap gap-2 justify-between mb-4">
          <div class="flex gap-2">
            <!-- Export Dropdown -->
            <div class="export-container">
              <button id="exportButton" class="button-sm btn-outline">
                <i data-lucide="download"></i> Export Report
                <i data-lucide="chevron-down" class="w-4 h-4"></i>
              </button>
              <div id="exportDropdown" class="export-dropdown">
                <div class="dropdown-header">
                  <h3>Export Options</h3>
                  <p>Choose export format</p>
                </div>
                <a href="?export=csv&status=<?php echo $status_filter; ?>&category=<?php echo $category_filter; ?>" class="export-option">
                  <i data-lucide="file-spreadsheet"></i> Export as CSV
                </a>
              </div>
            </div>
          </div>
          <div class="flex gap-2">
            <!-- Quick Status Filters -->
            <a href="?status=" class="button-sm <?php echo empty($status_filter) ? 'btn-active' : 'btn-outline'; ?>">
              All Issues
            </a>
            <a href="?status=Needs Maintenance" class="button-sm <?php echo $status_filter == 'Needs Maintenance' ? 'btn-active' : 'btn-outline'; ?>">
              Maintenance
            </a>
            <a href="?status=Under Repair" class="button-sm <?php echo $status_filter == 'Under Repair' ? 'btn-active' : 'btn-outline'; ?>">
              Under Repair
            </a>
            <a href="?status=Broken" class="button-sm <?php echo $status_filter == 'Broken' ? 'btn-active' : 'btn-outline'; ?>">
              Broken
            </a>
          </div>
        </div>

        <!-- Filters -->
        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4" id="filterForm">
          <div>
            <label class="form-label">Category</label>
            <select name="category" class="form-input">
              <option value="">All Categories</option>
              <?php 
              $categories_result->data_seek(0); // Reset pointer
              while($cat = $categories_result->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($cat['category']); ?>" <?php echo $category_filter == $cat['category'] ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($cat['category']); ?>
                </option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="flex items-end">
            <button type="submit" class="button-sm btn-primary w-full">
              <i data-lucide="filter"></i> Apply Filters
            </button>
          </div>
         <div class="flex items-end">
  <a href="maintenance_report.php" class="button-sm btn-outline w-full">
    <i data-lucide="refresh-cw"></i> Clear Filters
  </a>
</div>
        </form>
      </div>

<!-- Section Header -->
<div class="flex items-center gap-2 mb-4">
  <div class="h-0.5 flex-1 bg-gray-700"></div>
  <span class="text-sm font-semibold text-yellow-400 px-4">EQUIPMENT MAINTENANCE</span>
  <div class="h-0.5 flex-1 bg-gray-700"></div>
</div>
      <!-- Equipment Maintenance Section -->
      <div class="card">
        <h2 class="text-lg font-semibold text-yellow-400 mb-4 flex items-center gap-2">
          <i data-lucide="dumbbell"></i>
          Equipment Needing Attention (<?php echo $maintenance_result ? $maintenance_result->num_rows : 0; ?>)
        </h2>
        
        <?php if ($maintenance_result && $maintenance_result->num_rows > 0): ?>
          <div class="overflow-x-auto">
            <table class="w-full text-sm text-left text-gray-400">
              <thead class="text-xs text-gray-400 uppercase bg-gray-800">
                <tr>
                  <th class="px-4 py-3">Equipment</th>
                  <th class="px-4 py-3">Category</th>
                  <th class="px-4 py-3">Location</th>
                  <th class="px-4 py-3">Status</th>
                  <th class="px-4 py-3">Priority</th> <!-- ADD THIS COLUMN -->
                  <th class="px-4 py-3">Last Updated</th>
                  <th class="px-4 py-3">Issue Details</th>
                  <th class="px-4 py-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php while($equipment = $maintenance_result->fetch_assoc()): ?>
                <tr class="border-b border-gray-700 hover:bg-gray-800">
                  <td class="px-4 py-3">
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

<!-- ADD PRIORITY COLUMN HERE -->
<td class="px-4 py-3">
  <?php
    $priority = '';
    $priority_class = '';
    if ($equipment['status'] == 'Broken') {
      $priority = 'High';
      $priority_class = 'badge-broken';
    } elseif ($equipment['status'] == 'Under Repair') {
      $priority = 'Medium';
      $priority_class = 'badge-under-repair';
    } else {
      $priority = 'Low';
      $priority_class = 'badge-needs-maintenance';
    }
  ?>
  <span class="badge <?php echo $priority_class; ?>">
    <?php echo $priority; ?>
  </span>
</td>
<!-- END PRIORITY COLUMN -->

<td class="px-4 py-3 whitespace-nowrap">
  <?php echo date('M j, Y g:i A', strtotime($equipment['last_updated'])); ?>
  <?php if (!empty($equipment['updated_by_name'])): ?>
    <div class="text-xs text-gray-400">by <?php echo htmlspecialchars($equipment['updated_by_name']); ?></div>
  <?php endif; ?>
</td>
                  <td class="px-4 py-3 whitespace-nowrap">
                    <?php echo date('M j, Y g:i A', strtotime($equipment['last_updated'])); ?>
                    <?php if (!empty($equipment['updated_by_name'])): ?>
                      <div class="text-xs text-gray-400">by <?php echo htmlspecialchars($equipment['updated_by_name']); ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="px-4 py-3">
                    <?php 
                      $issue_note = !empty($equipment['last_note']) ? $equipment['last_note'] : $equipment['notes'];
                      echo $issue_note ? htmlspecialchars($issue_note) : '<span class="text-gray-500">No details</span>';
                    ?>
                  </td>
                  <td class="px-4 py-3">
                    <a href="equipment_monitoring.php?tab=equipment" class="text-blue-400 hover:text-blue-300 transition-colors" title="Update Status">
                      <i data-lucide="edit" class="w-4 h-4"></i>
                    </a>
                  </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="empty-state">
            <i data-lucide="check-circle" class="w-12 h-12 mx-auto text-green-400"></i>
            <p>No maintenance issues found!</p>
            <p class="text-sm mt-2">All equipment is in good condition</p>
          </div>
        <?php endif; ?>
      </div>
<!-- Section Header -->
<div class="flex items-center gap-2 mb-4 mt-8">
  <div class="h-0.5 flex-1 bg-gray-700"></div>
  <span class="text-sm font-semibold text-yellow-400 px-4">FACILITY MAINTENANCE</span>
  <div class="h-0.5 flex-1 bg-gray-700"></div>
</div>
      <!-- Facilities Maintenance Section -->
      <div class="card">
        <h2 class="text-lg font-semibold text-yellow-400 mb-4 flex items-center gap-2">
          <i data-lucide="building"></i>
          Facilities Needing Attention (<?php echo $facilities_result ? $facilities_result->num_rows : 0; ?>)
        </h2>
        
        <?php if ($facilities_result && $facilities_result->num_rows > 0): ?>
          <div class="overflow-x-auto">
            <table class="w-full text-sm text-left text-gray-400">
              <thead class="text-xs text-gray-400 uppercase bg-gray-800">
                <tr>
                  <th class="px-4 py-3">Facility</th>
                  <th class="px-4 py-3">Condition</th>
                  <th class="px-4 py-3">Issue Details</th>
                  <th class="px-4 py-3">Last Updated</th>
                  <th class="px-4 py-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php while($facility = $facilities_result->fetch_assoc()): ?>
                <tr class="border-b border-gray-700 hover:bg-gray-800">
                  <td class="px-4 py-3 font-medium text-white"><?php echo htmlspecialchars($facility['name']); ?></td>
                 <td class="px-4 py-3">
    <?php
        $condition_class = '';
        switch($facility['facility_condition']) {  // ← Changed to 'facility_condition'
          case 'Needs Maintenance': $condition_class = 'badge-needs-maintenance'; break;
          case 'Under Repair': $condition_class = 'badge-under-repair'; break;
          case 'Closed': $condition_class = 'badge-closed'; break;
          case 'Good': $condition_class = 'badge-good'; break;  // Added for completeness
        }
    ?>
    <span class="badge <?php echo $condition_class; ?>">
        <span class="status-indicator status-<?php echo strtolower(str_replace(' ', '-', $facility['facility_condition'])); ?>"></span>
        <?php echo htmlspecialchars($facility['facility_condition']); ?>
    </span>
</td>
                  <td class="px-4 py-3">
                    <?php echo !empty($facility['notes']) ? htmlspecialchars($facility['notes']) : '<span class="text-gray-500">No details</span>'; ?>
                  </td>
                  <td class="px-4 py-3 whitespace-nowrap">
                    <?php echo date('M j, Y g:i A', strtotime($facility['last_updated'])); ?>
                    <?php if (!empty($facility['updated_by_name'])): ?>
                      <div class="text-xs text-gray-400">by <?php echo htmlspecialchars($facility['updated_by_name']); ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="px-4 py-3">
                    <a href="equipment_monitoring.php?tab=facilities" class="text-blue-400 hover:text-blue-300 transition-colors" title="Update Condition">
                      <i data-lucide="edit" class="w-4 h-4"></i>
                    </a>
                  </td>
                </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
       <?php else: ?>
  <div class="empty-state">
    <i data-lucide="check-circle" class="w-12 h-12 mx-auto text-green-400"></i>
    <p>All facilities are operational!</p>
    <p class="text-sm mt-2">No maintenance issues reported</p>
  </div>
<?php endif; ?>
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

        // Export dropdown functionality
        const exportButton = document.getElementById('exportButton');
        const exportDropdown = document.getElementById('exportDropdown');

        exportButton.addEventListener('click', (e) => {
            e.stopPropagation();
            exportDropdown.classList.toggle('show');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!exportButton.contains(e.target) && !exportDropdown.contains(e.target)) {
                exportDropdown.classList.remove('show');
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
  </script>
</body>
</html>

<?php 
// Close statements and connection properly
if (isset($maintenance_stmt)) $maintenance_stmt->close();
if (isset($maintenance_result)) $maintenance_result->free();
if (isset($facilities_result)) $facilities_result->free();
if (isset($stats_result)) $stats_result->free();
if (isset($categories_result)) $categories_result->free();
$conn->close(); 
?>



