<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'trainer') {
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

// Chat functionality with fallback
if (file_exists('chat_functions.php')) {
    require_once 'chat_functions.php';
} else {
    function getUnreadCount($user_id, $conn) {
        $sql = "SELECT COUNT(*) as unread_count FROM chat_messages 
                WHERE receiver_id = ? AND is_read = 0";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row['unread_count'] ?? 0;
    }
}

$unread_count = getUnreadCount($_SESSION['user_id'], $conn);
$trainer_id = $_SESSION['user_id'];

// Handle GCash verification
$success_message = '';
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_gcash'])) {
    $request_id = $_POST['request_id'];
    $action = $_POST['action'];
    $notes = $_POST['notes'] ?? '';
    
    $status = $action === 'approve' ? 'paid' : 'rejected';
    
    $update_sql = "UPDATE membership_renewal_requests 
                  SET status = ?, verified_by = ?, verified_at = CURRENT_TIMESTAMP, notes = ?
                  WHERE id = ? AND trainer_id = ?";
    
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("sisii", $status, $trainer_id, $notes, $request_id, $trainer_id);
    
    if ($stmt->execute()) {
        $success_message = "GCash payment " . ($action === 'approve' ? 'verified' : 'rejected') . " successfully";
    } else {
        $error_message = "Failed to update payment status";
    }
    $stmt->close();
}

// Pagination settings
$records_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Search and filter functionality
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : '';

// Build WHERE clause for filters
$where_conditions = ["tca.trainer_user_id = ? AND tca.status = 'active'"];
$query_params = [$trainer_id];

if (!empty($search)) {
    $where_conditions[] = "(m.full_name LIKE ? OR m.contact_number LIKE ? OR u.email LIKE ?)";
    $search_term = "%$search%";
    $query_params = array_merge($query_params, [$search_term, $search_term, $search_term]);
}

if (!empty($status_filter)) {
    $today = date('Y-m-d');
    switch($status_filter) {
        case 'active':
            $where_conditions[] = "m.status = 'active' AND m.expiry_date >= ?";
            $query_params[] = $today;
            break;
        case 'expiring':
            $where_conditions[] = "m.status = 'active' AND m.expiry_date >= ? AND m.expiry_date <= DATE_ADD(?, INTERVAL 7 DAY)";
            $query_params[] = $today;
            $query_params[] = $today;
            break;
        case 'expired':
            $where_conditions[] = "(m.status = 'expired' OR m.expiry_date < ?)";
            $query_params[] = $today;
            break;
    }
}

$where_clause = implode(' AND ', $where_conditions);

// Get total count for pagination
$total_records = 0;
$total_pages = 1;
try {
    $count_sql = "SELECT COUNT(*) as total 
                  FROM members m 
                  INNER JOIN trainer_client_assignments tca ON m.user_id = tca.client_user_id 
                  INNER JOIN users u ON m.user_id = u.id
                  WHERE $where_clause";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param(str_repeat('s', count($query_params)), ...$query_params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_records = $count_result->fetch_assoc()['total'];
    $total_pages = ceil($total_records / $records_per_page);
    $count_stmt->close();
} catch (Exception $e) {
    // Continue with default values
}

// Get trainer's members with pagination and filters
$trainerMembers = [];
try {
    $members_sql = "SELECT m.*, u.email, u.username, u.client_type 
                    FROM members m 
                    INNER JOIN trainer_client_assignments tca ON m.user_id = tca.client_user_id 
                    INNER JOIN users u ON m.user_id = u.id
                    WHERE $where_clause 
                    ORDER BY m.full_name 
                    LIMIT ? OFFSET ?";

    $members_stmt = $conn->prepare($members_sql);
    $query_params_with_pagination = $query_params;
    $query_params_with_pagination[] = $records_per_page;
    $query_params_with_pagination[] = $offset;

    $param_types = str_repeat('s', count($query_params)) . 'ii';
    $members_stmt->bind_param($param_types, ...$query_params_with_pagination);
    $members_stmt->execute();
    $members_result = $members_stmt->get_result();
    
    while ($row = $members_result->fetch_assoc()) {
        // Calculate days left
        $expiry = new DateTime($row['expiry_date']);
        $today = new DateTime();
        $daysLeft = $today->diff($expiry)->days;
        $daysLeft = $today <= $expiry ? $daysLeft : -$daysLeft;
        $row['days_left'] = $daysLeft;
        $trainerMembers[] = $row;
    }
    $members_stmt->close();
} catch (Exception $e) {
    // Continue with empty members array
}

// Handle member view request
$view_member = null;
if (isset($_GET['view_member_id'])) {
    try {
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
            $daysLeft = $today <= $expiry ? $daysLeft : -$daysLeft;
            $view_member['days_left'] = $daysLeft;
        }
        $view_stmt->close();
    } catch (Exception $e) {
        // Continue without view member
    }
}

// GET RENEWAL REQUESTS FOR THIS TRAINER
$renewal_requests = [];
$pending_requests_count = 0;

try {
    $requests_sql = "SELECT mr.*, m.full_name as member_name, m.contact_number 
                     FROM membership_renewal_requests mr
                     INNER JOIN members m ON mr.member_id = m.id
                     WHERE mr.trainer_id = ? 
                     ORDER BY mr.created_at DESC";
    $stmt = $conn->prepare($requests_sql);
    $stmt->bind_param("i", $trainer_id);
    $stmt->execute();
    $requests_result = $stmt->get_result();
    $renewal_requests = $requests_result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    // Continue with empty renewal requests
}

// Count pending requests
try {
    $pending_count_sql = "SELECT COUNT(*) as count FROM membership_renewal_requests 
                         WHERE trainer_id = ? AND status IN ('pending', 'paid')";
    $count_stmt = $conn->prepare($pending_count_sql);
    $count_stmt->bind_param("i", $trainer_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $pending_data = $count_result->fetch_assoc();
    $pending_requests_count = $pending_data['count'] ?? 0;
    $count_stmt->close();
} catch (Exception $e) {
    // Continue with zero count
}

// Membership plans for display
$membership_plans = [
    'daily' => ['name' => 'Per Visit', 'price' => 40],
    'weekly' => ['name' => 'Weekly', 'price' => 160],
    'halfmonth' => ['name' => 'Half Month', 'price' => 250],
    'monthly' => ['name' => 'Monthly', 'price' => 400]
];

// Get notifications
$notification_count = 0;
$notifications = [];
try {
    $notification_sql = "SELECT * FROM notifications 
                        WHERE (user_id = ? OR role = 'trainer') 
                        AND read_status = 0 
                        ORDER BY created_at DESC 
                        LIMIT 10";
    $notification_stmt = $conn->prepare($notification_sql);
    $notification_stmt->bind_param("i", $trainer_id);
    $notification_stmt->execute();
    $notification_result = $notification_stmt->get_result();
    $notifications = $notification_result->fetch_all(MYSQLI_ASSOC);
    $notification_stmt->close();
    $notification_count = count($notifications);
} catch (Exception $e) {
    // Continue with empty notifications
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BOIYETS FITNESS GYM - Membership Status</title>
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
    
    .action-buttons {
      display: flex;
      gap: 0.5rem;
    }
    
    .action-btn {
      padding: 0.4rem 0.75rem;
      border-radius: 6px;
      font-size: 0.75rem;
      cursor: pointer;
      transition: all 0.2s ease;
      border: none;
      display: flex;
      align-items: center;
      gap: 0.4rem;
      font-weight: 500;
    }
    
    .action-btn.renew {
      background: rgba(16, 185, 129, 0.2);
      color: #10b981;
    }
    
    .action-btn.renew:hover {
      background: rgba(16, 185, 129, 0.3);
    }
    
    .action-btn.alert {
      background: rgba(245, 158, 11, 0.2);
      color: #f59e0b;
    }
    
    .action-btn.alert:hover {
      background: rgba(245, 158, 11, 0.3);
    }
    
    .action-btn.view {
      background: rgba(59, 130, 246, 0.2);
      color: #3b82f6;
    }
    
    .action-btn.view:hover {
      background: rgba(59, 130, 246, 0.3);
    }
    
    .action-btn:hover {
      transform: translateY(-1px);
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
    
    .section-title {
      font-size: 1.5rem;
      font-weight: 700;
      color: #fbbf24;
      margin-bottom: 1rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1rem;
      margin-bottom: 2rem;
    }
    
    .stat-card {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 8px;
      padding: 1.5rem;
      text-align: center;
    }
    
    .stat-number {
      font-size: 2rem;
      font-weight: 700;
      margin-bottom: 0.5rem;
    }
    
    .stat-label {
      font-size: 0.875rem;
      color: #9ca3af;
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

    /* Renewal Modal Styles */
    .renew-modal-content {
      background: #1a1a1a;
      border-radius: 12px;
      border: 1px solid rgba(251, 191, 36, 0.3);
      padding: 1.5rem;
      width: 100%;
      max-width: 400px;
      position: relative;
    }
    
    .plan-option {
      border: 2px solid #374151;
      border-radius: 8px;
      padding: 1rem;
      cursor: pointer;
      transition: all 0.2s ease;
      margin-bottom: 0.5rem;
    }
    
    .plan-option:hover {
      border-color: #fbbf24;
    }
    
    .plan-option.selected {
      border-color: #fbbf24;
      background: rgba(251, 191, 36, 0.1);
    }

    /* Mobile responsive */
    @media (max-width: 640px) {
      .info-grid {
        grid-template-columns: 1fr;
      }
      
      .action-buttons {
        flex-direction: column;
      }
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
    
    /* Ensure main content doesn't interfere */
    main {
      position: relative;
      z-index: 1;
    }

    /* Renewal Requests Tab Styles */
    .tab-container {
      background: rgba(26, 26, 26, 0.7);
      border-radius: 12px;
      overflow: hidden;
      border: 1px solid rgba(255, 255, 255, 0.05);
    }

    .tab-header {
      display: flex;
      background: rgba(251, 191, 36, 0.1);
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .tab-button {
      flex: 1;
      padding: 1rem;
      background: none;
      border: none;
      color: #9ca3af;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
    }

    .tab-button.active {
      color: #fbbf24;
      background: rgba(251, 191, 36, 0.15);
      border-bottom: 2px solid #fbbf24;
    }

    .tab-button:hover:not(.active) {
      background: rgba(255, 255, 255, 0.05);
      color: #fbbf24;
    }

    .tab-content {
      display: none;
      padding: 1.5rem;
    }

    .tab-content.active {
      display: block;
    }

    .request-badge {
      background: #ef4444;
      color: white;
      border-radius: 50%;
      width: 20px;
      height: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.75rem;
      margin-left: 0.5rem;
    }

    /* Renewal Request Status Badges */
    .status-pending { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
    .status-paid { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }
    .status-completed { background: rgba(16, 185, 129, 0.2); color: #10b981; }
    .status-rejected { background: rgba(239, 68, 68, 0.2); color: #ef4444; }

    /* Loading Styles */
    .loading {
      display: inline-block;
      width: 16px;
      height: 16px;
      border: 2px solid #ffffff;
      border-radius: 50%;
      border-top-color: transparent;
      animation: spin 1s ease-in-out infinite;
    }
    
    @keyframes spin {
      to { transform: rotate(360deg); }
    }
    
    .btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
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
          <img src="<?php echo htmlspecialchars($_SESSION['profile_picture'] ?? 'https://i.pravatar.cc/40'); ?>" class="w-8 h-8 rounded-full border border-gray-600" id="userAvatar" />
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
            <a href="logout.php" class="flex items-center gap=2 px-3 py-2 text-sm text-red-400 hover:text-red-300 hover:bg-red-400/10 rounded-lg transition-colors">
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
            <a href="membership_status.php" class="active"><i data-lucide="id-card"></i> Membership Status</a>
            <a href="all_members.php"><i data-lucide="list"></i> All Members</a>
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
      <!-- Success/Error Messages -->
      <?php if (!empty($success_message)): ?>
        <div class="bg-green-500/20 border border-green-500/30 rounded-lg p-4 mb-6">
          <div class="flex items-center gap-3">
            <i data-lucide="check-circle" class="w-6 h-6 text-green-400"></i>
            <span class="text-green-300"><?php echo $success_message; ?></span>
            <span class="loading ml-2"></span>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!empty($error_message)): ?>
        <div class="bg-red-500/20 border border-red-500/30 rounded-lg p-4 mb-6">
          <div class="flex items-center gap=3">
            <i data-lucide="alert-circle" class="w-6 h-6 text-red-400"></i>
            <span class="text-red-300"><?php echo $error_message; ?></span>
          </div>
        </div>
      <?php endif; ?>

      <div class="tab-container">
        <!-- Tab Header -->
        <div class="tab-header">
          <button class="tab-button active" data-tab="members">
            <i data-lucide="users"></i>
            My Members
          </button>
          <button class="tab-button" data-tab="renewals">
            <i data-lucide="refresh-cw"></i>
            Renewal Requests
            <?php if ($pending_requests_count > 0): ?>
              <span class="request-badge"><?php echo $pending_requests_count; ?></span>
            <?php endif; ?>
          </button>
        </div>

        <!-- Members Tab Content -->
        <div id="members-tab" class="tab-content active">
          <div class="flex justify-between items-center mb-6">
            <h2 class="section-title"><i data-lucide="id-card"></i> My Members - Membership Status</h2>
            <div class="flex space-x-3">
              <a href="member_registration.php?type=walk-in" class="btn btn-primary">
                <i data-lucide="user" class="w-4 h-4"></i> Walk-in Registration
              </a>
              <a href="member_registration.php?type=client" class="btn btn-success">
                <i data-lucide="user-plus" class="w-4 h-4"></i> Client Registration
              </a>
            </div>
          </div>

          <!-- Search and Filter Section -->
          <div class="card mb-6">
            <h2 class="text-lg font-semibold text-yellow-400 mb-4 flex items-center gap=2">
              <i data-lucide="search"></i>
              Search & Filter Members
            </h2>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-input" placeholder="Name, contact, or email..." value="<?php echo htmlspecialchars($search); ?>">
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
                <a href="membership_status.php" class="btn btn-danger">
                  <i data-lucide="refresh-cw"></i> Reset
                </a>
              </div>
            </form>
          </div>

          <!-- Statistics -->
          <?php 
          $total_members = $total_records;
          $active_members = 0;
          $expiring_members = 0;
          $expired_members = 0;
          
          foreach ($trainerMembers as $member) {
              if ($member['days_left'] > 7) {
                  $active_members++;
              } elseif ($member['days_left'] > 0) {
                  $expiring_members++;
              } else {
                  $expired_members++;
              }
          }
          ?>
          
          <div class="stats-grid mb-6">
            <div class="stat-card">
              <div class="stat-number text-yellow-400"><?php echo $total_members; ?></div>
              <div class="stat-label">Total Members</div>
            </div>
            <div class="stat-card">
              <div class="stat-number text-green-400"><?php echo $active_members; ?></div>
              <div class="stat-label">Active Members</div>
            </div>
            <div class="stat-card">
              <div class="stat-number text-orange-400"><?php echo $expiring_members; ?></div>
              <div class="stat-label">Expiring Soon</div>
            </div>
            <div class="stat-card">
              <div class="stat-number text-red-400"><?php echo $expired_members; ?></div>
              <div class="stat-label">Expired</div>
            </div>
          </div>
          
          <div class="table-container">
            <table>
              <thead>
                <tr>
                  <th>Member Name</th>
                  <th>Type</th>
                  <th>Membership Plan</th>
                  <th>Start Date</th>
                  <th>Expiry Date</th>
                  <th>Days Left</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($trainerMembers)): ?>
                  <?php foreach ($trainerMembers as $member): ?>
                    <tr>
                      <td class="font-medium"><?php echo htmlspecialchars($member['full_name']); ?></td>
                      <td>
                        <span class="member-type-badge type-<?php echo $member['member_type']; ?>">
                          <?php echo ucfirst($member['member_type']); ?>
                        </span>
                      </td>
                      <td><?php echo ucfirst($member['membership_plan']); ?></td>
                      <td><?php echo date('M j, Y', strtotime($member['start_date'])); ?></td>
                      <td><?php echo date('M j, Y', strtotime($member['expiry_date'])); ?></td>
                      <td>
                        <?php if ($member['days_left'] > 0): ?>
                          <span class="text-green-400 font-semibold"><?php echo $member['days_left']; ?> days</span>
                        <?php else: ?>
                          <span class="text-red-400 font-semibold">Expired <?php echo abs($member['days_left']); ?> days ago</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ($member['days_left'] > 7): ?>
                          <span class="status-badge status-active">Active</span>
                        <?php elseif ($member['days_left'] > 0): ?>
                          <span class="status-badge status-expiring">Expiring</span>
                        <?php else: ?>
                          <span class="status-badge status-expired">Expired</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <div class="action-buttons">
                          <button class="action-btn view" onclick="viewMember(<?php echo $member['id']; ?>)">
                            <i data-lucide="eye" class="w-3 h-3"></i> View
                          </button>
                          <button class="action-btn renew" onclick="renewMembership(<?php echo $member['id']; ?>, '<?php echo htmlspecialchars($member['full_name']); ?>', '<?php echo $member['membership_plan']; ?>')">
                            <i data-lucide="refresh-cw" class="w-3 h-3"></i> Renew
                          </button>
                          <?php if ($member['user_id']): ?>
                          <a href="chat.php?user_id=<?php echo $member['user_id']; ?>" class="action-btn alert">
                            <i data-lucide="message-circle" class="w-3 h-3"></i> Message
                          </a>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="8" class="text-center py-8 text-gray-500">
                      <i data-lucide="users" class="w-12 h-12 mx-auto mb-4 opacity-50"></i>
                      <p>No assigned members found.</p>
                      <?php if (!empty($search) || !empty($status_filter)): ?>
                        <p class="text-sm mt-2">Try adjusting your search filters.</p>
                      <?php endif; ?>
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
          
          <?php if (!empty($trainerMembers)): ?>
            <div class="mt-4 text-sm text-gray-500 text-center">
              Showing <?php echo count($trainerMembers); ?> of <?php echo $total_records; ?> members
            </div>
          <?php endif; ?>
        </div>

        <!-- Renewal Requests Tab Content - UPDATED WITH WORKING BUTTONS -->
        <div id="renewals-tab" class="tab-content">
          <div class="flex justify-between items-center mb-6">
            <h2 class="section-title"><i data-lucide="refresh-cw"></i> Membership Renewal Requests</h2>
            <div class="text-sm text-gray-400">
              <?php echo $pending_requests_count; ?> pending request<?php echo $pending_requests_count != 1 ? 's' : ''; ?>
            </div>
          </div>

          <?php if (empty($renewal_requests)): ?>
            <div class="text-center py-12 text-gray-500">
              <i data-lucide="inbox" class="w-16 h-16 mx-auto mb-4 opacity-50"></i>
              <p class="text-lg">No renewal requests found</p>
              <p class="text-sm mt-2">Renewal requests from your clients will appear here</p>
            </div>
          <?php else: ?>
            <div class="overflow-x-auto">
              <table class="w-full">
                <thead>
                  <tr class="border-b border-gray-700">
                    <th class="text-left p-3 text-yellow-400">Member</th>
                    <th class="text-left p-3 text-yellow-400">Plan</th>
                    <th class="text-left p-3 text-yellow-400">Amount</th>
                    <th class="text-left p-3 text-yellow-400">Payment Method</th>
                    <th class="text-left p-3 text-yellow-400">Status</th>
                    <th class="text-left p-3 text-yellow-400">Requested</th>
                    <th class="text-left p-3 text-yellow-400">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($renewal_requests as $request): ?>
                    <tr class="border-b border-gray-800 hover:bg-gray-800/50">
                      <td class="p-3">
                        <div class="font-semibold"><?php echo htmlspecialchars($request['member_name']); ?></div>
                        <div class="text-sm text-gray-400"><?php echo htmlspecialchars($request['contact_number']); ?></div>
                      </td>
                      <td class="p-3">
                        <?php echo $membership_plans[$request['plan_type']]['name'] ?? ucfirst($request['plan_type']); ?>
                      </td>
                      <td class="p-3 font-semibold">
                        ₱<?php echo number_format($request['amount']); ?>
                      </td>
                      <td class="p-3">
                        <span class="capitalize"><?php echo $request['payment_method']; ?></span>
                      </td>
                      <td class="p-3">
                        <?php echo $request['gcash_reference'] ?: '—'; ?>
                      </td>
                      <td class="p-3">
                        <span class="status-badge status-<?php echo $request['status']; ?>">
                          <?php echo ucfirst($request['status']); ?>
                        </span>
                      </td>
                      <td class="p-3 text-sm text-gray-400">
                        <?php echo date('M j, Y g:i A', strtotime($request['created_at'])); ?>
                      </td>
                      <td class="p-3">
    <div class="flex gap-2">
        <?php if ($request['status'] === 'pending'): ?>
            <!-- Single Process Button for BOTH GCash and Cash -->
            <button onclick="processRenewal(this, <?php echo $request['member_id']; ?>, '<?php echo $request['plan_type']; ?>', '<?php echo $request['payment_method']; ?>')" 
                    class="action-btn renew">
                <i data-lucide="refresh-cw" class="w-3 h-3"></i> Process Renewal
            </button>
            
            <?php if ($request['payment_method'] === 'gcash' && $request['gcash_screenshot']): ?>
                <button onclick="viewScreenshot('<?php echo $request['gcash_screenshot']; ?>')" 
                        class="action-btn view">
                    <i data-lucide="image" class="w-3 h-3"></i> View Proof
                </button>
            <?php endif; ?>
            
        <?php elseif ($request['status'] === 'completed'): ?>
            <span class="text-green-400 text-sm">Completed</span>
        <?php elseif ($request['status'] === 'rejected'): ?>
            <span class="text-red-400 text-sm">Rejected</span>
        <?php endif; ?>
    </div>
</td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
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
          <h3 class="text-yellow-400 font-semibold text-lg mb-4 flex items-center gap=2">
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
          <h3 class="text-yellow-400 font-semibold text-lg mb-4 flex items-center gap=2">
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
          <h3 class="text-yellow-400 font-semibold text-lg mb-4 flex items-center gap=2">
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
          <h3 class="text-yellow-400 font-semibold text-lg mb-4 flex items-center gap=2">
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
          <button onclick="renewMembership(<?php echo $view_member['id']; ?>, '<?php echo htmlspecialchars($view_member['full_name']); ?>', '<?php echo $view_member['membership_plan']; ?>')" class="btn btn-success flex-1">
            <i data-lucide="refresh-cw"></i> Renew Membership
          </button>
          <?php if ($view_member['user_id']): ?>
          <a href="chat.php?user_id=<?php echo $view_member['user_id']; ?>" class="btn btn-info flex-1">
            <i data-lucide="message-circle"></i> Send Message
          </a>
          <?php endif; ?>
          <button onclick="closeModal()" class="btn btn-primary">
            <i data-lucide="check"></i> Close
          </button>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Renewal Modal -->
  <div id="renewModal" class="modal-overlay hidden">
    <div class="renew-modal-content">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-yellow-400 text-xl font-bold">Renew Membership</h3>
        <button onclick="closeRenewModal()" class="text-gray-400 hover:text-white">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>
      
      <div class="mb-4">
        <p class="text-gray-300">Member: <span id="modalMemberName" class="text-white font-semibold"></span></p>
        <p class="text-gray-300">Current Plan: <span id="modalCurrentPlan" class="text-white font-semibold"></span></p>
      </div>
      
      <div class="space-y-3 mb-6">
        <div class="plan-option" data-plan="daily" data-price="40">
          <div class="flex justify-between items-center">
            <div>
              <div class="font-semibold text-white">Daily Plan</div>
              <div class="text-sm text-gray-400">24-hour access</div>
            </div>
            <div class="text-yellow-400 font-bold">₱40.00</div>
          </div>
        </div>
        
        <div class="plan-option" data-plan="weekly" data-price="160">
          <div class="flex justify-between items-center">
            <div>
              <div class="font-semibold text-white">Weekly Plan</div>
              <div class="text-sm text-gray-400">7 days access</div>
            </div>
            <div class="text-yellow-400 font-bold">₱160.00</div>
          </div>
        </div>
        
        <div class="plan-option" data-plan="halfmonth" data-price="250">
          <div class="flex justify-between items-center">
            <div>
              <div class="font-semibold text-white">Half Month</div>
              <div class="text-sm text-gray-400">15 days access</div>
            </div>
            <div class="text-yellow-400 font-bold">₱250.00</div>
          </div>
        </div>
        
        <div class="plan-option" data-plan="monthly" data-price="400">
          <div class="flex justify-between items-center">
            <div>
              <div class="font-semibold text-white">Monthly Plan</div>
              <div class="text-sm text-gray-400">30 days access</div>
            </div>
            <div class="text-yellow-400 font-bold">₱400.00</div>
          </div>
        </div>
      </div>
      
      <div class="mb-4">
        <label class="block text-gray-300 text-sm font-medium mb-2">Payment Method</label>
        <select id="paymentMethod" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white">
          <option value="cash">Cash</option>
          <option value="gcash">GCash</option>
        </select>
      </div>
      
      <div class="flex space-x-3">
        <button onclick="closeRenewModal()" class="flex-1 bg-gray-600 text-white py-3 rounded-lg font-semibold hover:bg-gray-700 transition-colors">Cancel</button>
        <button id="confirmRenew" class="flex-1 bg-yellow-400 text-black py-3 rounded-lg font-semibold hover:bg-yellow-500 transition-colors opacity-50 cursor-not-allowed" disabled>
          Process Payment
        </button>
      </div>
    </div>
  </div>

  <!-- Success Modal -->
  <div id="successModal" class="modal-overlay hidden">
    <div class="renew-modal-content text-center">
      <div class="text-green-400 mb-4">
        <i data-lucide="check-circle" class="w-16 h-16 mx-auto"></i>
      </div>
      <h3 class="text-green-400 text-xl font-bold mb-2">Payment Successful!</h3>
      <p id="successMessage" class="text-gray-300 mb-4"></p>
      <button onclick="closeSuccessModal()" class="w-full bg-green-400 text-black py-3 rounded-lg font-semibold hover:bg-green-500 transition-colors">
        Continue
      </button>
    </div>
  </div>

  <!-- Screenshot Modal -->
  <div id="screenshotModal" class="modal-overlay hidden">
    <div class="renew-modal-content" style="max-width: 600px;">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-yellow-400 text-xl font-bold">GCash Screenshot</h3>
        <button onclick="closeScreenshotModal()" class="text-gray-400 hover:text-white">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>
      <img id="screenshotImage" src="" alt="GCash Screenshot" class="w-full h-auto rounded-lg border border-gray-600">
    </div>
  </div>

  <script>
    // Initialize icons
    lucide.createIcons();

    // Tab functionality
    document.addEventListener('DOMContentLoaded', function() {
        const tabButtons = document.querySelectorAll('.tab-button');
        const tabContents = document.querySelectorAll('.tab-content');

        tabButtons.forEach(button => {
            button.addEventListener('click', () => {
                const tabId = button.getAttribute('data-tab');
                
                // Update buttons
                tabButtons.forEach(btn => btn.classList.remove('active'));
                button.classList.add('active');
                
                // Update contents
                tabContents.forEach(content => content.classList.remove('active'));
                document.getElementById(`${tabId}-tab`).classList.add('active');
            });
        });

        // Dropdown functionality
        const notificationBell = document.getElementById('notificationBell');
        const notificationDropdown = document.getElementById('notificationDropdown');
        const userMenuButton = document.getElementById('userMenuButton');
        const userDropdown = document.getElementById('userDropdown');

        // Notification dropdown
        notificationBell.addEventListener('click', (e) => {
            e.stopPropagation();
            notificationDropdown.classList.toggle('hidden');
            userDropdown.classList.add('hidden');
        });

        // User dropdown
        userMenuButton.addEventListener('click', (e) => {
            e.stopPropagation();
            userDropdown.classList.toggle('hidden');
            notificationDropdown.classList.add('hidden');
        });

        // Close dropdowns when clicking outside
        document.addEventListener('click', () => {
            notificationDropdown.classList.add('hidden');
            userDropdown.classList.add('hidden');
        });

        // Prevent dropdowns from closing when clicking inside them
        notificationDropdown.addEventListener('click', (e) => e.stopPropagation());
        userDropdown.addEventListener('click', (e) => e.stopPropagation());
    });

  



    // WORKING Process Renewal Function with Loading State
    function processRenewal(button, memberId, planType, paymentMethod) {
        if (!confirm('Process this membership renewal?')) return;

        const originalText = button.innerHTML;
        button.innerHTML = '<span class="loading"></span> Processing...';
        button.disabled = true;

        const formData = new FormData();
        formData.append('member_id', memberId);
        formData.append('plan_type', planType);
        formData.append('payment_method', paymentMethod);
        
        fetch('renew_membership.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                button.innerHTML = '<i data-lucide="check" class="w-4 h-4"></i> Completed';
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                alert('Error: ' + data.message);
                button.innerHTML = originalText;
                button.disabled = false;
                lucide.createIcons();
            }
        })
        .catch(error => {
            alert('Network error. Please try again.');
            button.innerHTML = originalText;
            button.disabled = false;
            lucide.createIcons();
        });
    }

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

    // Screenshot functions
    function viewScreenshot(imagePath) {
        document.getElementById('screenshotImage').src = imagePath;
        document.getElementById('screenshotModal').classList.remove('hidden');
    }

    function closeScreenshotModal() {
        document.getElementById('screenshotModal').classList.add('hidden');
    }

    // Renewal Modal Functions
    let currentMemberId = null;
    let currentMemberName = null;
    let selectedPlan = null;
    let selectedPrice = null;

    function renewMembership(memberId, memberName, currentPlan) {
        currentMemberId = memberId;
        currentMemberName = memberName;
        
        document.getElementById('modalMemberName').textContent = memberName;
        document.getElementById('modalCurrentPlan').textContent = currentPlan;
        
        // Reset modal state
        document.querySelectorAll('.plan-option').forEach(option => {
            option.classList.remove('selected');
        });
        document.getElementById('confirmRenew').disabled = true;
        document.getElementById('confirmRenew').classList.add('opacity-50', 'cursor-not-allowed');
        document.getElementById('confirmRenew').textContent = 'Process Payment';
        
        // Show modal
        document.getElementById('renewModal').classList.remove('hidden');
        
        // Add event listeners for plan selection
        document.querySelectorAll('.plan-option').forEach(option => {
            option.addEventListener('click', function() {
                document.querySelectorAll('.plan-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                this.classList.add('selected');
                selectedPlan = this.dataset.plan;
                selectedPrice = this.dataset.price;
                
                const confirmBtn = document.getElementById('confirmRenew');
                confirmBtn.disabled = false;
                confirmBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                confirmBtn.textContent = `Pay ₱${this.dataset.price}.00`;
            });
        });
    }

    function closeRenewModal() {
        document.getElementById('renewModal').classList.add('hidden');
        currentMemberId = null;
        currentMemberName = null;
        selectedPlan = null;
    }

    function closeSuccessModal() {
        document.getElementById('successModal').classList.add('hidden');
        location.reload();
    }

    // Enhanced payment processing
    document.getElementById('confirmRenew').addEventListener('click', function() {
        if (!selectedPlan || !currentMemberId) return;
        
        const paymentMethod = document.getElementById('paymentMethod').value;
        const confirmBtn = document.getElementById('confirmRenew');
        const originalText = confirmBtn.innerHTML;
        
        confirmBtn.innerHTML = '<i data-lucide="loader" class="w-5 h-5 animate-spin mx-auto"></i> Processing...';
        confirmBtn.disabled = true;
        
        const formData = new FormData();
        formData.append('member_id', currentMemberId);
        formData.append('plan_type', selectedPlan);
        formData.append('payment_method', paymentMethod);
        
        fetch('renew_membership.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('renewModal').classList.add('hidden');
                document.getElementById('successMessage').innerHTML = `
                    <div class="text-center">
                        <div class="text-green-400 mb-2">
                            <i data-lucide="check-circle" class="w-12 h-12 mx-auto"></i>
                        </div>
                        <p class="mb-3"><strong>${currentMemberName}</strong>'s membership has been successfully renewed!</p>
                        <div class="bg-gray-800 rounded-lg p-3 text-left">
                            <div class="flex justify-between mb-1"><span>Plan:</span> <span class="font-semibold">${data.plan_type}</span></div>
                            <div class="flex justify-between mb-1"><span>Amount Paid:</span> <span class="font-semibold">₱${data.amount_paid}.00</span></div>
                            <div class="flex justify-between"><span>New Expiry:</span> <span class="font-semibold text-green-400">${data.new_expiry}</span></div>
                        </div>
                    </div>
                `;
                document.getElementById('successModal').classList.remove('hidden');
            } else {
                alert('Error: ' + data.message);
                confirmBtn.innerHTML = originalText;
                confirmBtn.disabled = false;
            }
        })
        .catch(error => {
            alert('Network error. Please try again.');
            confirmBtn.innerHTML = originalText;
            confirmBtn.disabled = false;
        });
    });

    // Sidebar functionality
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize icons
        lucide.createIcons();
        
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
    });

    // Close modals when clicking outside
    document.addEventListener('click', function(event) {
        const modals = ['renewModal', 'successModal', 'gcashModal', 'screenshotModal'];
        modals.forEach(modal => {
            if (event.target.id === modal) {
                closeRenewModal();
                closeSuccessModal();
                closeGCashModal();
                closeScreenshotModal();
            }
        });
    });

    // Close modals with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeRenewModal();
            closeSuccessModal();
            closeGCashModal();
            closeScreenshotModal();
        }
    });

    // Close modal when clicking outside
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal-overlay')) {
            closeModal();
        }
    });

    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModal();
        }
    });
  </script>
</body>
</html>
<?php $conn->close(); ?>



