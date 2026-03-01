<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'trainer') {
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

// Pagination settings
$records_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Search functionality
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$member_type_filter = isset($_GET['member_type']) ? $conn->real_escape_string($_GET['member_type']) : '';
$status_filter = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : '';

// Build WHERE clause for filters
$where_conditions = [];
$query_params = [];

if (!empty($search)) {
    $where_conditions[] = "(full_name LIKE ? OR contact_number LIKE ? OR email LIKE ?)";
    $search_term = "%$search%";
    $query_params = array_merge($query_params, [$search_term, $search_term, $search_term]);
}

if (!empty($member_type_filter)) {
    $where_conditions[] = "member_type = ?";
    $query_params[] = $member_type_filter;
}

if (!empty($status_filter)) {
    $today = date('Y-m-d');
    switch($status_filter) {
        case 'active':
            $where_conditions[] = "status = 'active' AND expiry_date >= ?";
            $query_params[] = $today;
            break;
        case 'expiring':
            $where_conditions[] = "status = 'active' AND expiry_date >= ? AND expiry_date <= DATE_ADD(?, INTERVAL 7 DAY)";
            $query_params[] = $today;
            $query_params[] = $today;
            break;
        case 'expired':
            $where_conditions[] = "(status = 'expired' OR expiry_date < ?)";
            $query_params[] = $today;
            break;
    }
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(' AND ', $where_conditions);
}

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM members $where_clause";
$count_stmt = $conn->prepare($count_sql);
if (!empty($query_params)) {
    $count_stmt->bind_param(str_repeat('s', count($query_params)), ...$query_params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_records = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get members with pagination and filters
$members_sql = "SELECT m.*, u.email, u.username 
                FROM members m 
                LEFT JOIN users u ON m.user_id = u.id 
                $where_clause 
                ORDER BY m.full_name 
                LIMIT ? OFFSET ?";

$members_stmt = $conn->prepare($members_sql);
$query_params_with_pagination = $query_params;
$query_params_with_pagination[] = $records_per_page;
$query_params_with_pagination[] = $offset;

if (!empty($query_params_with_pagination)) {
    $param_types = str_repeat('s', count($query_params)) . 'ii';
    $members_stmt->bind_param($param_types, ...$query_params_with_pagination);
} else {
    $members_stmt->bind_param('ii', $records_per_page, $offset);
}

$members_stmt->execute();
$members_result = $members_stmt->get_result();
$allMembers = [];

while ($row = $members_result->fetch_assoc()) {
    // Calculate days left
    $expiry = new DateTime($row['expiry_date']);
    $today = new DateTime();
    $daysLeft = $today->diff($expiry)->days;
    if ($today > $expiry) {
        $daysLeft = -$daysLeft;
    }
    $row['days_left'] = $daysLeft;
    $allMembers[] = $row;
}

// Handle member view request
$view_member = null;
if (isset($_GET['view_member_id'])) {
    $member_id = (int)$_GET['view_member_id'];
    $view_sql = "SELECT m.*, u.email, u.username, u.client_type 
                 FROM members m 
                 LEFT JOIN users u ON m.user_id = u.id 
                 WHERE m.id = ?";
    $view_stmt = $conn->prepare($view_sql);
    $view_stmt->bind_param("i", $member_id);
    $view_stmt->execute();
    $view_result = $view_stmt->get_result();
    $view_member = $view_result->fetch_assoc();
    
    if ($view_member) {
        // Calculate days left for viewed member
        $expiry = new DateTime($view_member['expiry_date']);
        $today = new DateTime();
        $daysLeft = $today->diff($expiry)->days;
        if ($today > $expiry) {
            $daysLeft = -$daysLeft;
        }
        $view_member['days_left'] = $daysLeft;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BOIYETS FITNESS GYM - All Members</title>
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

    .topbar {
      background: rgba(13, 13, 13, 0.95);
      backdrop-filter: blur(10px);
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      position: relative;
      z-index: 100;
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
    
    .btn-success {
      background: rgba(16, 185, 129, 0.2);
      color: #10b981;
    }
    
    .btn-success:hover {
      background: rgba(16, 185, 129, 0.3);
    }
    
    .btn-danger {
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
    }
    
    .btn-danger:hover {
      background: rgba(239, 68, 68, 0.3);
    }
    
    .btn-info {
      background: rgba(59, 130, 246, 0.2);
      color: #3b82f6;
    }
    
    .btn-info:hover {
      background: rgba(59, 130, 246, 0.3);
    }
    
    .status-badge {
      padding: 0.25rem 0.75rem;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 500;
    }
    
    .status-active {
      background: rgba(16, 185, 129, 0.2);
      color: #10b981;
    }
    
    .status-expired {
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
    }
    
    .status-expiring {
      background: rgba(245, 158, 11, 0.2);
      color: #f59e0b;
    }
    
    .member-type-badge {
      padding: 0.25rem 0.75rem;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 500;
    }
    
    .type-client {
      background: rgba(59, 130, 246, 0.2);
      color: #3b82f6;
    }
    
    .type-walkin {
      background: rgba(139, 92, 246, 0.2);
      color: #8b5cf6;
    }
    
    .stat-card {
      background: rgba(26, 26, 26, 0.7);
      border-radius: 12px;
      padding: 1.5rem;
      text-align: center;
      transition: all 0.3s ease;
      border: 1px solid rgba(255, 255, 255, 0.05);
    }
    
    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
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
    
    /* Ensure main content doesn't interfere */
    main {
      position: relative;
      z-index: 1;
    }

    /* QR Scanner Styles */
    .qr-scanner-container {
      position: fixed;
      bottom: 80px;
      right: 20px;
      z-index: 90;
      background: rgba(26, 26, 26, 0.95);
      border-radius: 12px;
      padding: 1rem;
      border: 1px solid rgba(251, 191, 36, 0.3);
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
      backdrop-filter: blur(10px);
      transition: all 0.3s ease;
      max-width: 320px;
      width: 100%;
      cursor: move;
    }

    .qr-scanner-container.dragging {
      opacity: 0.8;
      cursor: grabbing;
    }

    .qr-scanner-container.hidden {
      transform: translateX(400px);
      opacity: 0;
      pointer-events: none;
    }

    .qr-scanner-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 0.75rem;
      user-select: none;
    }

    .cursor-move {
      cursor: move;
    }

    .cursor-grabbing {
      cursor: grabbing;
    }

    .qr-scanner-title {
      font-weight: 600;
      color: #fbbf24;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .qr-scanner-status {
      font-size: 0.8rem;
      padding: 0.25rem 0.5rem;
      border-radius: 20px;
      font-weight: 500;
    }

    .qr-scanner-status.active {
      background: rgba(16, 185, 129, 0.2);
      color: #10b981;
    }

    .qr-scanner-status.disabled {
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
    }

    .qr-input {
      width: 100%;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 8px;
      padding: 0.75rem;
      color: white;
      margin-bottom: 0.75rem;
      transition: all 0.2s ease;
      font-size: 0.9rem;
    }

    .qr-input:focus {
      outline: none;
      border-color: #fbbf24;
      box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.2);
    }

    .qr-scanner-buttons {
      display: flex;
      gap: 0.5rem;
    }

    .qr-scanner-btn {
      flex: 1;
      padding: 0.5rem;
      border-radius: 8px;
      font-size: 0.8rem;
      font-weight: 500;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.4rem;
      transition: all 0.2s ease;
      border: none;
      cursor: pointer;
    }

    .qr-scanner-btn.primary {
      background: rgba(251, 191, 36, 0.2);
      color: #fbbf24;
    }

    .qr-scanner-btn.primary:hover {
      background: rgba(251, 191, 36, 0.3);
    }

    .qr-scanner-btn.secondary {
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
    }

    .qr-scanner-btn.secondary:hover {
      background: rgba(239, 68, 68, 0.3);
    }

    .qr-scanner-result {
      margin-top: 0.75rem;
      padding: 0.75rem;
      border-radius: 8px;
      background: rgba(255, 255, 255, 0.05);
      display: none;
    }

    .qr-scanner-result.success {
      display: block;
      border-left: 4px solid #10b981;
    }

    .qr-scanner-result.error {
      display: block;
      border-left: 4px solid #ef4444;
    }

    .qr-scanner-result.info {
      display: block;
      border-left: 4px solid #3b82f6;
    }

    .qr-result-title {
      font-weight: 600;
      margin-bottom: 0.25rem;
    }

    .qr-result-message {
      font-size: 0.8rem;
      color: #d1d5db;
    }

    .scanner-instructions {
      font-size: 0.75rem;
      color: #9ca3af;
      margin-top: 0.5rem;
      text-align: center;
    }

    /* Modal Styles */
    .modal-overlay {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.8);
      backdrop-filter: blur(5px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1000;
      opacity: 0;
      visibility: hidden;
      transition: all 0.3s ease;
    }

    .modal-overlay.active {
      opacity: 1;
      visibility: visible;
    }

    .modal {
      background: rgba(26, 26, 26, 0.95);
      border-radius: 16px;
      padding: 2rem;
      max-width: 800px;
      width: 90%;
      max-height: 90vh;
      overflow-y: auto;
      border: 1px solid rgba(251, 191, 36, 0.2);
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
      transform: scale(0.9);
      opacity: 0;
      transition: all 0.3s ease;
    }

    .modal-overlay.active .modal {
      transform: scale(1);
      opacity: 1;
    }

    .modal-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 1.5rem;
      padding-bottom: 1rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .modal-title {
      font-size: 1.5rem;
      font-weight: 700;
      color: #fbbf24;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .modal-close {
      background: none;
      border: none;
      color: #9ca3af;
      cursor: pointer;
      padding: 0.5rem;
      border-radius: 6px;
      transition: all 0.2s ease;
    }

    .modal-close:hover {
      background: rgba(255, 255, 255, 0.1);
      color: #fbbf24;
    }

    /* Pagination Styles */
    .pagination {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 0.5rem;
      margin-top: 2rem;
    }

    .pagination-btn {
      padding: 0.5rem 1rem;
      border-radius: 8px;
      background: rgba(255, 255, 255, 0.05);
      color: #9ca3af;
      border: 1px solid rgba(255, 255, 255, 0.1);
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .pagination-btn:hover {
      background: rgba(251, 191, 36, 0.2);
      color: #fbbf24;
      border-color: #fbbf24;
    }

    .pagination-btn.active {
      background: rgba(251, 191, 36, 0.2);
      color: #fbbf24;
      border-color: #fbbf24;
    }

    .pagination-btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .pagination-btn:disabled:hover {
      background: rgba(255, 255, 255, 0.05);
      color: #9ca3af;
      border-color: rgba(255, 255, 255, 0.1);
    }

    /* Member Info Grid */
    .info-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 1.5rem;
      margin-bottom: 2rem;
    }

    .info-card {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 12px;
      padding: 1.5rem;
      border-left: 4px solid #fbbf24;
    }

    .info-label {
      font-size: 0.875rem;
      color: #9ca3af;
      margin-bottom: 0.5rem;
    }

    .info-value {
      font-size: 1.125rem;
      font-weight: 600;
      color: white;
    }

    /* Mobile responsive */
    @media (max-width: 640px) {
      .qr-scanner-container {
        position: fixed;
        bottom: 80px;
        left: 50%;
        transform: translateX(-50%);
        max-width: 90vw;
        margin: 0;
      }
      
      .qr-scanner-container.hidden {
        transform: translateX(-50%) translateY(100px);
        opacity: 0;
      }

      .info-grid {
        grid-template-columns: 1fr;
      }
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
        <a href="trainer_dashboard.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="home"></i>
            <span class="text-sm font-medium">Dashboard</span>
          </div>
          <span class="tooltip">Dashboard</span>
        </a>

        <!-- Members Section -->
        <div>
          <div id="membersToggle" class="sidebar-item active">
            <div class="flex items-center">
              <i data-lucide="users"></i>
              <span class="text-sm font-medium">Members</span>
            </div>
            <i id="membersChevron" data-lucide="chevron-right" class="chevron rotate"></i>
            <span class="tooltip">Members</span>
          </div>
          <div id="membersSubmenu" class="submenu open space-y-1">
            <a href="member_registration.php"><i data-lucide="user-plus"></i> Member Registration</a>
            <a href="membership_status.php"><i data-lucide="id-card"></i> Membership Status</a>
            <a href="all_members.php" class="active"><i data-lucide="list"></i> All Members</a>
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
<!-- Equipment Monitoring -->
<a href="trainer_equipment_monitoring.php" class="sidebar-item">
  <div class="flex items-center">
    <i data-lucide="wrench"></i>
    <span class="text-sm font-medium">Equipment Monitoring</span>
  </div>
  <span class="tooltip">Equipment Monitoring</span>
</a>

<!-- Maintenance Report -->
<a href="trainer_maintenance_report.php" class="sidebar-item">
  <div class="flex items-center">
    <i data-lucide="alert-triangle"></i>
    <span class="text-sm font-medium">Maintenance Report</span>
  </div>
  <span class="tooltip">Maintenance Report</span>
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

    <!-- Main Content -->
    <main class="flex-1 p-6 overflow-auto">
      <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-yellow-400 flex items-center gap-2">
          <i data-lucide="users"></i>
          All Members
        </h1>
        <div class="flex gap-3">
          <a href="member_registration.php?type=walk-in" class="btn btn-primary">
            <i data-lucide="user-plus"></i> Add Walk-in
          </a>
          <a href="member_registration.php?type=client" class="btn btn-success">
            <i data-lucide="user-check"></i> Add Client
          </a>
        </div>
      </div>

      <!-- Statistics -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="stat-card">
          <div class="text-3xl font-bold text-yellow-400 mb-2"><?php echo $total_records; ?></div>
          <div class="text-gray-400">Total Members</div>
        </div>
        
        <div class="stat-card">
          <div class="text-3xl font-bold text-yellow-400 mb-2">
            <?php 
              $activeCount = array_filter($allMembers, function($member) { 
                return $member['days_left'] > 7; 
              });
              echo count($activeCount);
            ?>
          </div>
          <div class="text-gray-400">Active Members</div>
        </div>
        
        <div class="stat-card">
          <div class="text-3xl font-bold text-yellow-400 mb-2">
            <?php 
              $expiringCount = array_filter($allMembers, function($member) { 
                return $member['days_left'] > 0 && $member['days_left'] <= 7; 
              });
              echo count($expiringCount);
            ?>
          </div>
          <div class="text-gray-400">Expiring Soon</div>
        </div>
        
        <div class="stat-card">
          <div class="text-3xl font-bold text-yellow-400 mb-2">
            <?php 
              $expiredCount = array_filter($allMembers, function($member) { 
                return $member['days_left'] <= 0; 
              });
              echo count($expiredCount);
            ?>
          </div>
          <div class="text-gray-400">Expired</div>
        </div>
      </div>

      <!-- Search and Filter Section -->
      <div class="card mb-6">
        <h2 class="text-lg font-semibold text-yellow-400 mb-4 flex items-center gap-2">
          <i data-lucide="search"></i>
          Search & Filter Members
        </h2>
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div>
            <label class="form-label">Search</label>
            <input type="text" name="search" class="form-input" placeholder="Name, contact, or email..." value="<?php echo htmlspecialchars($search); ?>">
          </div>
          <div>
            <label class="form-label">Member Type</label>
            <select name="member_type" class="form-input">
              <option value="">All Types</option>
              <option value="client" <?php echo $member_type_filter === 'client' ? 'selected' : ''; ?>>Client</option>
              <option value="walk-in" <?php echo $member_type_filter === 'walk-in' ? 'selected' : ''; ?>>Walk-in</option>
            </select>
          </div>
          <div>
            <label class="form-label">Status</label>
            <select name="status" class="form-input">
              <option value="">All Status</option>
              <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
              <option value="expiring" <?php echo $status_filter === 'expiring' ? 'selected' : ''; ?>>Expiring Soon</option>
              <option value="expired" <?php echo $status_filter === 'expired' ? 'selected' : ''; ?>>Expired</option>
            </select>
          </div>
          <div class="flex items-end gap-2">
            <button type="submit" class="btn btn-primary flex-1">
              <i data-lucide="filter"></i> Apply Filters
            </button>
            <a href="all_members.php" class="btn btn-danger">
              <i data-lucide="refresh-cw"></i> Reset
            </a>
          </div>
        </form>
      </div>

      <!-- Members Table -->
      <div class="card">
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-6">
          <h2 class="text-lg font-semibold text-yellow-400 flex items-center gap-2">
            <i data-lucide="list"></i>
            Member Directory (<?php echo $total_records; ?> total)
          </h2>
          
          <div class="text-sm text-gray-400">
            Showing <?php echo count($allMembers); ?> of <?php echo $total_records; ?> members
          </div>
        </div>
        
        <div class="table-container">
          <table>
            <thead>
              <tr>
                <th>Member Name</th>
                <th>Type</th>
                <th>Contact</th>
                <th>Membership Plan</th>
                <th>Expiry Date</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="membersTableBody">
              <?php foreach ($allMembers as $member): ?>
                <tr>
                  <td class="font-medium"><?php echo htmlspecialchars($member['full_name']); ?></td>
                  <td>
                    <span class="member-type-badge type-<?php echo $member['member_type']; ?>">
                      <?php echo ucfirst($member['member_type']); ?>
                    </span>
                  </td>
                  <td><?php echo htmlspecialchars($member['contact_number']); ?></td>
                  <td><?php echo ucfirst($member['membership_plan']); ?></td>
                  <td><?php echo date('M j, Y', strtotime($member['expiry_date'])); ?></td>
                  <td>
                    <?php if ($member['days_left'] > 7): ?>
                      <span class="status-badge status-active">Active (<?php echo $member['days_left']; ?> days)</span>
                    <?php elseif ($member['days_left'] > 0): ?>
                      <span class="status-badge status-expiring">Expiring in <?php echo $member['days_left']; ?> days</span>
                    <?php else: ?>
                      <span class="status-badge status-expired">Expired</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <button onclick="viewMember(<?php echo $member['id']; ?>)" class="btn btn-info button-sm">
                      <i data-lucide="eye"></i> View
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
              
              <?php if (empty($allMembers)): ?>
                <tr>
                  <td colspan="7" class="empty-state">
                    <i data-lucide="users" class="w-12 h-12 mx-auto"></i>
                    <p>No members found. Register a new member to get started!</p>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
          <button 
            class="pagination-btn" 
            <?php echo $page <= 1 ? 'disabled' : ''; ?>
            onclick="changePage(<?php echo $page - 1; ?>)"
          >
            <i data-lucide="chevron-left"></i> Previous
          </button>
          
          <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <button 
              class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>"
              onclick="changePage(<?php echo $i; ?>)"
            >
              <?php echo $i; ?>
            </button>
          <?php endfor; ?>
          
          <button 
            class="pagination-btn" 
            <?php echo $page >= $total_pages ? 'disabled' : ''; ?>
            onclick="changePage(<?php echo $page + 1; ?>)"
          >
            Next <i data-lucide="chevron-right"></i>
          </button>
        </div>
        <?php endif; ?>
      </div>
    </main>
  </div>

  <!-- Member View Modal -->
  <?php if ($view_member): ?>
  <div id="memberModal" class="modal-overlay active">
    <div class="modal">
      <div class="modal-header">
        <h2 class="modal-title">
          <i data-lucide="user"></i>
          Member Information - <?php echo htmlspecialchars($view_member['full_name']); ?>
        </h2>
        <button class="modal-close" onclick="closeModal()">
          <i data-lucide="x" class="w-6 h-6"></i>
        </button>
      </div>

      <div class="space-y-6">
        <!-- Personal Information -->
        <div>
          <h3 class="text-yellow-400 font-semibold text-lg mb-4 flex items-center gap-2">
            <i data-lucide="user"></i>
            Personal Information
          </h3>
          <div class="info-grid">
            <div class="info-card">
              <div class="info-label">Full Name</div>
              <div class="info-value"><?php echo htmlspecialchars($view_member['full_name']); ?></div>
            </div>
            <div class="info-card">
              <div class="info-label">Age</div>
              <div class="info-value"><?php echo $view_member['age']; ?> years old</div>
            </div>
            <div class="info-card">
              <div class="info-label">Contact Number</div>
              <div class="info-value"><?php echo htmlspecialchars($view_member['contact_number']); ?></div>
            </div>
            <div class="info-card">
              <div class="info-label">Email</div>
              <div class="info-value"><?php echo htmlspecialchars($view_member['email'] ?? 'N/A'); ?></div>
            </div>
            <div class="info-card">
              <div class="info-label">Username</div>
              <div class="info-value"><?php echo htmlspecialchars($view_member['username'] ?? 'N/A'); ?></div>
            </div>
            <div class="info-card">
              <div class="info-label">Client Type</div>
              <div class="info-value"><?php echo htmlspecialchars($view_member['client_type'] ?? 'N/A'); ?></div>
            </div>
            <?php if ($view_member['member_type'] === 'client'): ?>
            <div class="info-card">
              <div class="info-label">Gender</div>
              <div class="info-value"><?php echo htmlspecialchars($view_member['gender'] ?? 'N/A'); ?></div>
            </div>
            <div class="info-card">
              <div class="info-label">Height</div>
              <div class="info-value"><?php echo $view_member['height'] ?? 'N/A'; ?> cm</div>
            </div>
            <div class="info-card">
              <div class="info-label">Weight</div>
              <div class="info-value"><?php echo $view_member['weight'] ?? 'N/A'; ?> kg</div>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Address Information -->
        <div>
          <h3 class="text-yellow-400 font-semibold text-lg mb-4 flex items-center gap-2">
            <i data-lucide="map-pin"></i>
            Address Information
          </h3>
          <div class="info-card">
            <div class="info-label">Address</div>
            <div class="info-value"><?php echo htmlspecialchars($view_member['address']); ?></div>
          </div>
        </div>

        <!-- Membership Information -->
        <div>
          <h3 class="text-yellow-400 font-semibold text-lg mb-4 flex items-center gap-2">
            <i data-lucide="id-card"></i>
            Membership Information
          </h3>
          <div class="info-grid">
            <div class="info-card">
              <div class="info-label">Member Type</div>
              <div class="info-value">
                <span class="member-type-badge type-<?php echo $view_member['member_type']; ?>">
                  <?php echo ucfirst($view_member['member_type']); ?>
                </span>
              </div>
            </div>
            <div class="info-card">
              <div class="info-label">Membership Plan</div>
              <div class="info-value"><?php echo ucfirst($view_member['membership_plan']); ?></div>
            </div>
            <div class="info-card">
              <div class="info-label">Start Date</div>
              <div class="info-value"><?php echo date('M j, Y', strtotime($view_member['start_date'])); ?></div>
            </div>
            <div class="info-card">
              <div class="info-label">Expiry Date</div>
              <div class="info-value"><?php echo date('M j, Y', strtotime($view_member['expiry_date'])); ?></div>
            </div>
            <div class="info-card">
              <div class="info-label">Status</div>
              <div class="info-value">
                <?php if ($view_member['days_left'] > 7): ?>
                  <span class="status-badge status-active">Active (<?php echo $view_member['days_left']; ?> days left)</span>
                <?php elseif ($view_member['days_left'] > 0): ?>
                  <span class="status-badge status-expiring">Expiring in <?php echo $view_member['days_left']; ?> days</span>
                <?php else: ?>
                  <span class="status-badge status-expired">Expired</span>
                <?php endif; ?>
              </div>
            </div>
            <div class="info-card">
              <div class="info-label">Registration Date</div>
              <div class="info-value"><?php echo date('M j, Y', strtotime($view_member['created_at'])); ?></div>
            </div>
          </div>
        </div>

        <?php if ($view_member['member_type'] === 'client' && !empty($view_member['fitness_goals'])): ?>
        <!-- Fitness Goals -->
        <div>
          <h3 class="text-yellow-400 font-semibold text-lg mb-4 flex items-center gap-2">
            <i data-lucide="target"></i>
            Fitness Goals
          </h3>
          <div class="info-card">
            <div class="info-label">Goals & Objectives</div>
            <div class="info-value"><?php echo htmlspecialchars($view_member['fitness_goals']); ?></div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="flex gap-3 pt-4 border-t border-gray-700">
          <button onclick="closeModal()" class="btn btn-primary flex-1">
            <i data-lucide="check"></i> Close
          </button>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- QR Scanner Container - MOVABLE & TOGGLEABLE -->
  <div id="qrScanner" class="qr-scanner-container hidden">
    <div class="qr-scanner-header cursor-move" id="qrScannerHeader">
      <div class="qr-scanner-title">
        <i data-lucide="scan"></i>
        <span>QR Attendance Scanner</span>
      </div>
      <div class="flex items-center gap-2">
        <div id="qrScannerStatus" class="qr-scanner-status active">Active</div>
        <button id="closeQRScanner" class="text-gray-400 hover:text-white transition-colors">
          <i data-lucide="x" class="w-4 h-4"></i>
        </button>
      </div>
    </div>
    <input type="text" id="qrInput" class="qr-input" placeholder="Scan QR code or enter code manually..." autocomplete="off">
    <div class="scanner-instructions">
      Press Enter or click Process after scanning
    </div>
    <div class="qr-scanner-buttons">
      <button id="processQR" class="qr-scanner-btn primary">
        <i data-lucide="check"></i> Process
      </button>
      <button id="toggleScanner" class="qr-scanner-btn secondary">
        <i data-lucide="power"></i> Disable
      </button>
    </div>
    <div id="qrResult" class="qr-scanner-result"></div>
  </div>

  <!-- QR Scanner Toggle Button -->
  <button id="toggleQRScannerBtn" class="fixed bottom-4 right-4 bg-yellow-500 hover:bg-yellow-600 text-white rounded-full w-12 h-12 flex items-center justify-center cursor-pointer shadow-lg z-40 transition-all duration-300">
    <i data-lucide="scan" class="w-6 h-6"></i>
  </button>

  <script>
    // Modal functions
    function viewMember(memberId) {
        window.location.href = `?view_member_id=${memberId}&<?php echo http_build_query($_GET); ?>`;
    }

    function closeModal() {
        // Remove the view_member_id from URL and reload
        const url = new URL(window.location);
        url.searchParams.delete('view_member_id');
        window.location.href = url.toString();
    }

    // Pagination function
    function changePage(page) {
        const url = new URL(window.location);
        url.searchParams.set('page', page);
        window.location.href = url.toString();
    }

    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModal();
        }
    });

    // Close modal when clicking outside
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal-overlay')) {
            closeModal();
        }
    });

    // QR Scanner functionality - MOVABLE & TOGGLEABLE VERSION
    let qrScannerActive = true;
    let lastProcessedQR = '';
    let lastProcessedTime = 0;
    let qrProcessing = false;
    let qrCooldown = false;
    let isDragging = false;
    let dragOffset = { x: 0, y: 0 };

    function setupQRScanner() {
        const qrScanner = document.getElementById('qrScanner');
        const qrScannerHeader = document.getElementById('qrScannerHeader');
        const qrInput = document.getElementById('qrInput');
        const processQRBtn = document.getElementById('processQR');
        const toggleScannerBtn = document.getElementById('toggleScanner');
        const toggleQRScannerBtn = document.getElementById('toggleQRScannerBtn');
        const closeQRScannerBtn = document.getElementById('closeQRScanner');
        const qrScannerStatus = document.getElementById('qrScannerStatus');

        // Toggle QR scanner visibility
        toggleQRScannerBtn.addEventListener('click', function() {
            qrScanner.classList.toggle('hidden');
            if (!qrScanner.classList.contains('hidden') && qrScannerActive) {
                setTimeout(() => qrInput.focus(), 100);
            }
        });

        // Close QR scanner
        closeQRScannerBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            qrScanner.classList.add('hidden');
        });

        // Drag and drop functionality
        qrScannerHeader.addEventListener('mousedown', startDrag);
        qrScannerHeader.addEventListener('touchstart', function(e) {
            startDrag(e.touches[0]);
        });

        document.addEventListener('mousemove', drag);
        document.addEventListener('touchmove', function(e) {
            drag(e.touches[0]);
            e.preventDefault();
        }, { passive: false });

        document.addEventListener('mouseup', stopDrag);
        document.addEventListener('touchend', stopDrag);

        function startDrag(e) {
            if (e.target.closest('button')) return; // Don't drag if clicking buttons
            
            isDragging = true;
            qrScanner.classList.add('dragging');
            
            const rect = qrScanner.getBoundingClientRect();
            dragOffset.x = e.clientX - rect.left;
            dragOffset.y = e.clientY - rect.top;
            
            document.body.classList.add('cursor-grabbing');
        }

        function drag(e) {
            if (!isDragging) return;
            
            const x = e.clientX - dragOffset.x;
            const y = e.clientY - dragOffset.y;
            
            // Keep within viewport bounds
            const maxX = window.innerWidth - qrScanner.offsetWidth;
            const maxY = window.innerHeight - qrScanner.offsetHeight;
            
            const boundedX = Math.max(0, Math.min(x, maxX));
            const boundedY = Math.max(0, Math.min(y, maxY));
            
            qrScanner.style.left = boundedX + 'px';
            qrScanner.style.top = boundedY + 'px';
            qrScanner.style.right = 'auto';
            qrScanner.style.bottom = 'auto';
            qrScanner.style.transform = 'none';
        }

        function stopDrag() {
            if (!isDragging) return;
            
            isDragging = false;
            qrScanner.classList.remove('dragging');
            document.body.classList.remove('cursor-grabbing');
        }

        // Process QR code when Enter is pressed
        qrInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && qrScannerActive && !qrProcessing && !qrCooldown) {
                processQRCode();
                e.preventDefault();
            }
        });
        
        // Process QR code when button is clicked
        processQRBtn.addEventListener('click', function() {
            if (qrScannerActive && !qrProcessing && !qrCooldown) {
                processQRCode();
            }
        });
        
        // Toggle scanner on/off
        toggleScannerBtn.addEventListener('click', function() {
            qrScannerActive = !qrScannerActive;
            
            if (qrScannerActive) {
                qrScannerStatus.textContent = 'Active';
                qrScannerStatus.classList.remove('disabled');
                qrScannerStatus.classList.add('active');
                toggleScannerBtn.innerHTML = '<i data-lucide="power"></i> Disable';
                qrInput.disabled = false;
                qrInput.placeholder = 'Scan QR code or enter code manually...';
                processQRBtn.disabled = false;
                if (!qrScanner.classList.contains('hidden')) {
                    qrInput.focus();
                }
                showToast('QR scanner enabled', 'success', 2000);
            } else {
                qrScannerStatus.textContent = 'Disabled';
                qrScannerStatus.classList.remove('active');
                qrScannerStatus.classList.add('disabled');
                toggleScannerBtn.innerHTML = '<i data-lucide="power"></i> Enable';
                qrInput.disabled = true;
                qrInput.placeholder = 'Scanner disabled';
                processQRBtn.disabled = true;
                showToast('QR scanner disabled', 'warning', 2000);
            }
            
            lucide.createIcons();
        });
        
        // Smart focus management
        document.addEventListener('click', function(e) {
            if (qrScannerActive && 
                !qrScanner.classList.contains('hidden') &&
                !e.target.closest('form') && 
                !e.target.closest('select') && 
                !e.target.closest('button') &&
                e.target !== qrInput) {
                setTimeout(() => {
                    if (document.activeElement.tagName !== 'INPUT' && 
                        document.activeElement.tagName !== 'TEXTAREA' &&
                        document.activeElement.tagName !== 'SELECT') {
                        qrInput.focus();
                    }
                }, 100);
            }
        });
        
        // Clear input after successful processing
        qrInput.addEventListener('input', function() {
            if (this.value === lastProcessedQR) {
                this.value = '';
            }
        });
        
        // Close scanner with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !qrScanner.classList.contains('hidden')) {
                qrScanner.classList.add('hidden');
            }
        });
        
        // Initial focus
        setTimeout(() => {
            if (qrScannerActive && !qrScanner.classList.contains('hidden')) {
                qrInput.focus();
            }
        }, 1000);
    }

    function processQRCode() {
        if (qrProcessing || qrCooldown) return;
        
        const qrInput = document.getElementById('qrInput');
        const qrResult = document.getElementById('qrResult');
        const processQRBtn = document.getElementById('processQR');
        const qrCode = qrInput.value.trim();
        
        if (!qrCode) {
            showQRResult('error', 'Error', 'Please enter a QR code');
            showToast('Please enter a QR code', 'error');
            return;
        }
        
        // Prevent processing the same QR code twice in quick succession
        const currentTime = Date.now();
        if (qrCode === lastProcessedQR && (currentTime - lastProcessedTime) < 3000) {
            const timeLeft = Math.ceil((3000 - (currentTime - lastProcessedTime)) / 1000);
            showQRResult('error', 'Cooldown', `Please wait ${timeLeft} seconds before scanning this QR code again`);
            showToast(`Please wait ${timeLeft} seconds before rescanning`, 'warning');
            qrInput.value = '';
            qrInput.focus();
            return;
        }
        
        qrProcessing = true;
        qrCooldown = true;
        setLoadingState(processQRBtn, true);
        processQRBtn.innerHTML = '<i data-lucide="loader" class="animate-spin"></i> Processing';
        lucide.createIcons();
        
        // Show processing message
        showQRResult('info', 'Processing', 'Scanning QR code...');
        showToast('Processing QR code...', 'info', 2000);
        
        // Make AJAX call to process the QR code
        fetch('process_qr.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'qr_code=' + encodeURIComponent(qrCode)
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                showQRResult('success', 'Success', data.message);
                showToast(data.message, 'success');
                lastProcessedQR = qrCode;
                lastProcessedTime = Date.now();
                
                // Update attendance count if element exists
                const attendanceCount = document.getElementById('attendanceCount');
                if (attendanceCount) {
                    const currentCount = parseInt(attendanceCount.textContent || '0');
                    attendanceCount.textContent = currentCount + 1;
                }
                
                // Trigger custom event for other components
                window.dispatchEvent(new CustomEvent('qrScanSuccess', { 
                    detail: { message: data.message, qrCode: qrCode } 
                }));
                
            } else {
                showQRResult('error', 'Error', data.message || 'Unknown error occurred');
                showToast(data.message || 'Unknown error occurred', 'error');
                lastProcessedQR = qrCode;
                lastProcessedTime = Date.now();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showQRResult('error', 'Network Error', 'Failed to process QR code. Please try again.');
            showToast('Network error occurred', 'error');
            lastProcessedQR = qrCode;
            lastProcessedTime = Date.now();
        })
        .finally(() => {
            qrProcessing = false;
            setLoadingState(processQRBtn, false);
            processQRBtn.innerHTML = '<i data-lucide="check"></i> Process';
            lucide.createIcons();
            
            // Clear input and refocus after processing
            setTimeout(() => {
                qrInput.value = '';
                const qrScanner = document.getElementById('qrScanner');
                if (qrScannerActive && !qrScanner.classList.contains('hidden')) {
                    qrInput.focus();
                }
            }, 500);
            
            // Enable scanning again after 3 seconds
            setTimeout(() => {
                qrCooldown = false;
            }, 3000);
        });
    }

    function showQRResult(type, title, message) {
        const qrResult = document.getElementById('qrResult');
        qrResult.className = 'qr-scanner-result ' + type;
        qrResult.innerHTML = `
            <div class="qr-result-title">${title}</div>
            <div class="qr-result-message">${message}</div>
        `;
        qrResult.style.display = 'block';
        
        // Auto-hide result after appropriate time
        let hideTime = type === 'success' ? 4000 : 5000;
        if (title === 'Cooldown') hideTime = 3000;
        if (title === 'Processing') hideTime = 2000;
        
        setTimeout(() => {
            qrResult.style.display = 'none';
        }, hideTime);
    }

    // Helper functions
    function setLoadingState(button, isLoading) {
        button.disabled = isLoading;
        button.style.opacity = isLoading ? 0.7 : 1;
    }

    function showToast(message, type = 'info', duration = 3000) {
        // Simple toast implementation
        const toast = document.createElement('div');
        toast.textContent = message;
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : type === 'warning' ? '#f59e0b' : '#3b82f6'};
            color: white;
            padding: 12px 16px;
            border-radius: 8px;
            z-index: 1000;
            animation: slideIn 0.3s ease;
        `;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.remove();
        }, duration);
    }

    // Add CSS animation for toast
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    `;
    document.head.appendChild(style);

    // Global function to open QR scanner
    function openQRScanner() {
        const qrScanner = document.getElementById('qrScanner');
        const qrInput = document.getElementById('qrInput');
        
        qrScanner.classList.remove('hidden');
        if (qrScannerActive) {
            setTimeout(() => qrInput.focus(), 100);
        }
    }

    // Sidebar functionality
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize icons
        lucide.createIcons();
        
        // Setup QR Scanner
        setupQRScanner();
        
        // Sidebar toggle functionality
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

        // Members submenu toggle
        const membersToggle = document.getElementById('membersToggle');
        const membersSubmenu = document.getElementById('membersSubmenu');
        const membersChevron = document.getElementById('membersChevron');
        
        membersToggle.addEventListener('click', () => {
            membersSubmenu.classList.toggle('open');
            membersChevron.classList.toggle('rotate');
        });

        // Plans submenu toggle
        const plansToggle = document.getElementById('plansToggle');
        const plansSubmenu = document.getElementById('plansSubmenu');
        const plansChevron = document.getElementById('plansChevron');
        
        plansToggle.addEventListener('click', () => {
            plansSubmenu.classList.toggle('open');
            plansChevron.classList.toggle('rotate');
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

        // Initialize dropdown functionality
        setupDropdowns();
    });

    // Dropdown functionality
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
                loadNotifications();
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
            // In a real app, you'd make an API call to mark notifications as read
            document.getElementById('notificationBadge').classList.add('hidden');
            // You could also update the notification list to remove the "new" indicators
        });
        
        // Load notifications periodically
        loadNotifications();
        setInterval(loadNotifications, 30000); // Refresh every 30 seconds
        
        // Close dropdowns when pressing Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAllDropdowns();
            }
        });
    }
    
    function loadNotifications() {
        fetch('get_notifications.php')
            .then(response => response.json())
            .then(data => {
                updateNotificationBadge(data.total_count);
                updateNotificationList(data.notifications);
            })
            .catch(error => {
                console.error('Error loading notifications:', error);
            });
    }
    
    function updateNotificationBadge(count) {
        const badge = document.getElementById('notificationBadge');
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    }
    
    function updateNotificationList(notifications) {
        const notificationList = document.getElementById('notificationList');
        
        if (notifications.length === 0) {
            notificationList.innerHTML = `
                <div class="text-center py-8 text-gray-500">
                    <i data-lucide="bell-off" class="w-12 h-12 mx-auto mb-3 opacity-50"></i>
                    <p>No notifications</p>
                    <p class="text-sm mt-1">You're all caught up!</p>
                </div>
            `;
            lucide.createIcons();
            return;
        }
        
        notificationList.innerHTML = notifications.map(notification => `
            <div class="p-3 border-b border-gray-700 last:border-b-0 hover:bg-white/5 transition-colors cursor-pointer">
                <div class="flex items-start gap-3">
                    <div class="flex-shrink-0 mt-1">
                        ${getNotificationIcon(notification.type)}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex justify-between items-start mb-1">
                            <p class="text-white font-medium text-sm truncate">${notification.title}</p>
                            <span class="text-xs text-gray-400 whitespace-nowrap ml-2">
                                ${formatTime(notification.time)}
                            </span>
                        </div>
                        <p class="text-gray-400 text-xs line-clamp-2">${notification.message}</p>
                        ${notification.priority === 'high' ? `
                            <span class="inline-block mt-1 px-2 py-1 bg-red-500/20 text-red-400 text-xs rounded-full">
                                Important
                            </span>
                        ` : ''}
                    </div>
                </div>
            </div>
        `).join('');
        
        lucide.createIcons();
    }
    
    function getNotificationIcon(type) {
        const icons = {
            'announcement': '<i data-lucide="megaphone" class="w-4 h-4 text-yellow-400"></i>',
            'membership': '<i data-lucide="id-card" class="w-4 h-4 text-blue-400"></i>',
            'message': '<i data-lucide="message-circle" class="w-4 h-4 text-green-400"></i>'
        };
        return icons[type] || '<i data-lucide="bell" class="w-4 h-4 text-gray-400"></i>';
    }
    
    function formatTime(timeString) {
        const time = new Date(timeString);
        const now = new Date();
        const diffMs = now - time;
        const diffMins = Math.floor(diffMs / (1000 * 60));
        const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
        const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
        
        if (diffMins < 1) return 'Just now';
        if (diffMins < 60) return `${diffMins}m ago`;
        if (diffHours < 24) return `${diffHours}h ago`;
        if (diffDays < 7) return `${diffDays}d ago`;
        return time.toLocaleDateString();
    }
  </script>
</body>
</html>
<?php $conn->close(); ?>



