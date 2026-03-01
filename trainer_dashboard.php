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

// Include notification functions
require_once 'notification_functions.php';
require_once 'chat_functions.php';

$unread_count = getUnreadCount($_SESSION['user_id'], $conn);
$trainer_user_id = $_SESSION['user_id'];

// Update notification count using the new function
$notification_count = getUnreadNotificationCount($conn, $trainer_user_id);
$notifications = getTrainerNotifications($conn, $trainer_user_id);

// Function to get dashboard statistics for specific trainer
function getDashboardStats($conn, $trainer_user_id) {
    $stats = [];
    
    // Total members (all)
    $result = $conn->query("SELECT COUNT(*) as total FROM members");
    $stats['total_members'] = $result->fetch_assoc()['total'];
    
    // My assigned clients
    $sql = "SELECT COUNT(DISTINCT tca.client_user_id) as total 
            FROM trainer_client_assignments tca 
            WHERE tca.trainer_user_id = ? AND tca.status = 'active'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $trainer_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['my_clients'] = $result->fetch_assoc()['total'];
    
    // Attendance today (all members)
    $today = date('Y-m-d');
    $result = $conn->query("SELECT COUNT(DISTINCT member_id) as total FROM attendance WHERE DATE(check_in) = '$today'");
    $stats['attendance_today'] = $result->fetch_assoc()['total'];
    
    // Expiring soon (within 7 days) - all members
    $nextWeek = date('Y-m-d', strtotime('+7 days'));
    $result = $conn->query("SELECT COUNT(*) as total FROM members WHERE expiry_date BETWEEN CURDATE() AND '$nextWeek' AND status = 'active'");
    $stats['expiring_soon'] = $result->fetch_assoc()['total'];
    
    return $stats;
}

// Function to get real weekly attendance data
function getWeeklyAttendanceData($conn) {
    $attendanceData = [];
    $days = [];
    
    // Get last 7 days including today
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $days[] = date('D', strtotime($date));
        
        $sql = "SELECT COUNT(DISTINCT member_id) as count 
                FROM attendance 
                WHERE DATE(check_in) = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $date);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        $attendanceData[] = $row['count'] ?? 0;
    }
    
    return [
        'labels' => $days,
        'data' => $attendanceData
    ];
}

// Function to get attendance insights
function getAttendanceInsights($conn) {
    $insights = [];
    
    // Today vs yesterday comparison
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    $sql = "SELECT 
            (SELECT COUNT(DISTINCT member_id) FROM attendance WHERE DATE(check_in) = ?) as today_count,
            (SELECT COUNT(DISTINCT member_id) FROM attendance WHERE DATE(check_in) = ?) as yesterday_count";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $today, $yesterday);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    $todayCount = $row['today_count'] ?? 0;
    $yesterdayCount = $row['yesterday_count'] ?? 0;
    
    if ($yesterdayCount > 0) {
        $change = (($todayCount - $yesterdayCount) / $yesterdayCount) * 100;
        $insights['daily_change'] = round($change, 1);
        $insights['trend'] = $change >= 0 ? 'up' : 'down';
    } else {
        $insights['daily_change'] = $todayCount > 0 ? 100 : 0;
        $insights['trend'] = $todayCount > 0 ? 'up' : 'stable';
    }
    
    // Busiest time of day
    $sql = "SELECT HOUR(check_in) as hour, COUNT(*) as count 
            FROM attendance 
            WHERE DATE(check_in) = CURDATE() 
            GROUP BY HOUR(check_in) 
            ORDER BY count DESC 
            LIMIT 1";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $insights['busiest_hour'] = $row['hour'];
        $insights['busiest_count'] = $row['count'];
    } else {
        $insights['busiest_hour'] = null;
        $insights['busiest_count'] = 0;
    }
    
    return $insights;
}

// Function to get upcoming renewals for trainer's clients
function getUpcomingRenewals($conn, $trainer_user_id) {
    $renewals = [];
    
    $sql = "SELECT m.id, m.full_name, m.expiry_date, m.membership_plan,
            DATEDIFF(m.expiry_date, CURDATE()) as days_remaining
            FROM members m 
            INNER JOIN trainer_client_assignments tca ON m.user_id = tca.client_user_id 
            WHERE tca.trainer_user_id = ? 
            AND m.status = 'active'
            AND m.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            ORDER BY m.expiry_date ASC 
            LIMIT 5";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $trainer_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $renewals[] = $row;
    }
    
    return $renewals;
}

// Function to get equipment status for dashboard
function getEquipmentStatus($conn) {
    $equipment_stats = [];
    
    $sql = "SELECT status, COUNT(*) as count 
            FROM equipment 
            WHERE status IN ('Needs Maintenance', 'Under Repair', 'Broken')
            GROUP BY status";
    
    $result = $conn->query($sql);
    while($row = $result->fetch_assoc()) {
        $equipment_stats[$row['status']] = $row['count'];
    }
    
    return $equipment_stats;
}

// Function to get trainer's assigned clients for dropdown
function getTrainerClients($conn, $trainer_user_id) {
    $clients = [];
    
    $sql = "SELECT m.id, m.full_name 
            FROM members m 
            INNER JOIN trainer_client_assignments tca ON m.user_id = tca.client_user_id 
            WHERE tca.trainer_user_id = ? AND tca.status = 'active' 
            ORDER BY m.full_name";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $trainer_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $clients[] = $row;
    }
    
    return $clients;
}

// Function to get today's check-ins
function getTodaysCheckins($conn) {
    $todaysCheckins = [];
    $today = date('Y-m-d');
    
    $sql = "SELECT a.*, m.full_name, m.member_type 
            FROM attendance a 
            JOIN members m ON a.member_id = m.id 
            WHERE DATE(a.check_in) = ?
            ORDER BY a.check_in DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $todaysCheckins[] = $row;
    }
    
    return $todaysCheckins;
}

// Function to get active announcements
function getActiveAnnouncements($conn) {
    $announcements = [];
    $result = $conn->query("SELECT * FROM announcements WHERE expiry_date >= CURDATE() OR expiry_date IS NULL ORDER BY created_at DESC");
    while ($row = $result->fetch_assoc()) {
        $announcements[] = $row;
    }
    return $announcements;
}

// Input validation function
function validateMemberId($conn, $id) {
    if (!is_numeric($id) || $id <= 0) {
        return false;
    }
    
    $stmt = $conn->prepare("SELECT id FROM members WHERE id = ? AND status = 'active'");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

// Handle manual attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_attendance'])) {
    $memberId = $_POST['member_id'];
    
    if (!validateMemberId($conn, $memberId)) {
        $message = "Invalid member selection";
        $messageType = "error";
    } else {
        $stmt = $conn->prepare("SELECT id, full_name FROM members WHERE id = ?");
        $stmt->bind_param("i", $memberId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $member = $result->fetch_assoc();
            
            $today = date('Y-m-d');
            $checkStmt = $conn->prepare("SELECT id FROM attendance WHERE member_id = ? AND DATE(check_in) = ?");
            $checkStmt->bind_param("is", $memberId, $today);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows > 0) {
                $message = "Member is already checked in today";
                $messageType = "error";
            } else {
                $insertStmt = $conn->prepare("INSERT INTO attendance (member_id, check_in) VALUES (?, NOW())");
                $insertStmt->bind_param("i", $memberId);
                if ($insertStmt->execute()) {
                    $message = "Successfully checked in " . htmlspecialchars($member['full_name']);
                    $messageType = "success";
                    
                    // Refresh all dashboard data after successful check-in
                    $stats = getDashboardStats($conn, $trainer_user_id);
                    $todaysCheckins = getTodaysCheckins($conn);
                    $weeklyData = getWeeklyAttendanceData($conn);
                    $attendanceInsights = getAttendanceInsights($conn);
                    
                    // Add notification for successful check-in
                    $notification_sql = "INSERT INTO notifications (user_id, role, title, message, type, priority) VALUES (?, 'trainer', 'Manual Check-in', ?, 'system', 'medium')";
                    $notification_stmt = $conn->prepare($notification_sql);
                    $notification_message = "Manually checked in " . htmlspecialchars($member['full_name']);
                    $notification_stmt->bind_param("is", $trainer_user_id, $notification_message);
                    $notification_stmt->execute();
                    
                } else {
                    $message = "Error checking in member";
                    $messageType = "error";
                }
            }
        } else {
            $message = "Member not found";
            $messageType = "error";
        }
    }
    
    // Refresh notification data
    $notification_count = getUnreadNotificationCount($conn, $trainer_user_id);
    $notifications = getTrainerNotifications($conn, $trainer_user_id);
}

// Get trainer's assigned clients for dropdown
$trainer_clients = getTrainerClients($conn, $trainer_user_id);

// Get all members for dropdown (fallback if no assigned clients)
$all_members_result = $conn->query("SELECT id, full_name FROM members WHERE status = 'active' ORDER BY full_name");

// Get data from database with caching consideration
$stats = getDashboardStats($conn, $trainer_user_id);
$announcements = getActiveAnnouncements($conn);
$todaysCheckins = getTodaysCheckins($conn);
$weeklyData = getWeeklyAttendanceData($conn);
$attendanceInsights = getAttendanceInsights($conn);
$upcoming_renewals = getUpcomingRenewals($conn, $trainer_user_id);
$equipment_stats = getEquipmentStatus($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BOIYETS FITNESS GYM - Trainer Dashboard</title>
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

    .chart-container {
      width: 100%;
      height: 200px;
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
      position: relative;
      z-index: 100;
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
    
    /* FIXED DROPDOWN STYLES */
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

    /* Movable QR Scanner Styles */
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
    }

    /* Table Styles */
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
      padding: 0.75rem;
      text-align: left;
      font-weight: 600;
      font-size: 0.8rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    td {
      padding: 0.75rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
      font-size: 0.8rem;
    }
    
    tr:hover {
      background: rgba(255, 255, 255, 0.02);
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
      padding: 2rem 1rem;
      color: #6b7280;
    }
    
    .empty-state i {
      margin-bottom: 1rem;
      opacity: 0.5;
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

    /* Loading states */
    .loading {
      opacity: 0.7;
      pointer-events: none;
    }
    
    .loading::after {
      content: '';
      position: absolute;
      top: 50%;
      left: 50%;
      width: 20px;
      height: 20px;
      margin: -10px 0 0 -10px;
      border: 2px solid #fbbf24;
      border-top: 2px solid transparent;
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
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
        <a href="#" class="sidebar-item active" onclick="showSection('dashboard')">
          <div class="flex items-center">
            <i data-lucide="home"></i>
            <span class="text-sm font-medium">Dashboard</span>
          </div>
          <span class="tooltip">Dashboard</span>
        </a>

        <!-- Members Section -->
        <div>
          <div id="membersToggle" class="sidebar-item">
            <div class="flex items-center">
              <i data-lucide="users"></i>
              <span class="text-sm font-medium">Members</span>
            </div>
            <i id="membersChevron" data-lucide="chevron-right" class="chevron"></i>
            <span class="tooltip">Members</span>
          </div>
          <div id="membersSubmenu" class="submenu space-y-1">
            <a href="member_registration.php"><i data-lucide="user-plus"></i> Member Registration</a>
            <a href="membership_status.php"><i data-lucide="id-card"></i> Membership Status</a>
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
    <main id="mainContent" class="main-content flex-1 p-4 space-y-4 overflow-auto">
      <!-- Dashboard Section -->
      <div id="dashboard" class="section active">
        <!-- Welcome Header -->
        <div class="mb-4">
          <h1 class="text-xl font-bold text-yellow-400 mb-1">Trainer Dashboard</h1>
          <p class="text-gray-400 text-sm">Welcome back! Here's your overview for today.</p>
        </div>

        <!-- Stats Grid - Compact -->
        <div class="mb-4">
          <div class="compact-grid">
            <div class="card compact-card">
              <p class="compact-title"><i data-lucide="users"></i><span>Total Members</span></p>
              <p class="compact-value"><?php echo $stats['total_members']; ?></p>
              <p class="text-xs text-green-400 mt-1 flex items-center"><i data-lucide="trending-up" class="w-3 h-3 mr-1"></i>From database</p>
            </div>
            <div class="card compact-card">
              <p class="compact-title"><i data-lucide="user-plus"></i><span>My Clients</span></p>
              <p class="compact-value"><?php echo $stats['my_clients']; ?></p>
              <p class="text-xs text-blue-400 mt-1">Assigned clients</p>
            </div>
            <div class="card compact-card">
              <p class="compact-title"><i data-lucide="check-square"></i><span>Today's Check-ins</span></p>
              <p class="compact-value" id="attendanceCount"><?php echo $stats['attendance_today']; ?></p>
              <p class="text-xs text-purple-400 mt-1">Last updated: <?php echo date('H:i'); ?></p>
            </div>
            <div class="card compact-card">
              <p class="compact-title"><i data-lucide="calendar"></i><span>Upcoming Renewals</span></p>
              <p class="compact-value"><?php echo count($upcoming_renewals); ?></p>
              <p class="text-xs text-orange-400 mt-1 flex items-center"><i data-lucide="clock" class="w-3 h-3 mr-1"></i>Next 7 days</p>
            </div>
          </div>
        </div>

        <!-- Main Content Area - Optimized Layout -->
        <div class="grid grid-cols-1 xl:grid-cols-5 gap-4">
          <!-- Left Column - Chart and Check-ins (3/5 width) -->
          <div class="xl:col-span-3 space-y-4">
            <!-- Attendance Chart with Real Data -->
            <div class="card">
              <div class="flex justify-between items-center mb-3">
                <p class="card-title"><i data-lucide="bar-chart-3"></i><span>Weekly Attendance Trend</span></p>
                <div class="flex items-center gap-2">
                  <?php if (isset($attendanceInsights['daily_change'])): ?>
                    <span class="text-xs <?php echo $attendanceInsights['trend'] === 'up' ? 'text-green-400' : ($attendanceInsights['trend'] === 'down' ? 'text-red-400' : 'text-gray-400'); ?>">
                      <i data-lucide="<?php echo $attendanceInsights['trend'] === 'up' ? 'trending-up' : ($attendanceInsights['trend'] === 'down' ? 'trending-down' : 'minus'); ?>" class="w-3 h-3 inline mr-1"></i>
                      <?php echo abs($attendanceInsights['daily_change']); ?>% from yesterday
                    </span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="chart-container">
                <canvas id="attendanceChart"></canvas>
              </div>
              <?php if (isset($attendanceInsights['busiest_hour'])): ?>
                <div class="mt-2 text-xs text-gray-400 text-center">
                  <?php if ($attendanceInsights['busiest_hour']): ?>
                    Peak hour: <?php echo date('g A', strtotime($attendanceInsights['busiest_hour'] . ':00')); ?> 
                    (<?php echo $attendanceInsights['busiest_count']; ?> check-ins)
                  <?php else: ?>
                    No check-ins recorded today
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- Today's Check-Ins - Moved to top for better visibility -->
            <div class="card">
              <div class="flex justify-between items-center mb-3">
                <p class="card-title"><i data-lucide="calendar"></i><span>Today's Check-Ins</span></p>
                <div class="flex items-center gap-2">
                  <span class="text-xs text-gray-500"><?php echo date('F j, Y'); ?></span>
                  <button id="refreshData" class="button-sm bg-gray-700 hover:bg-gray-600 text-white text-xs">
                    <i data-lucide="refresh-cw" class="w-3 h-3"></i> Refresh
                  </button>
                </div>
              </div>
              <div class="table-container">
                <table class="compact-table">
                  <thead>
                    <tr>
                      <th>Member Name</th>
                      <th>Type</th>
                      <th>Check-In Time</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($todaysCheckins)): ?>
                      <tr>
                        <td colspan="4" class="empty-state">
                          <i data-lucide="calendar" class="w-8 h-8 mx-auto"></i>
                          <p class="text-sm">No check-ins yet today</p>
                        </td>
                      </tr>
                    <?php else: ?>
                      <?php foreach ($todaysCheckins as $record): ?>
                        <tr>
                          <td class="font-medium"><?php echo htmlspecialchars($record['full_name']); ?></td>
                          <td>
                            <span class="px-2 py-1 rounded-full text-xs <?php echo $record['member_type'] === 'client' ? 'bg-blue-500/20 text-blue-400' : 'bg-green-500/20 text-green-400'; ?>">
                              <?php echo ucfirst($record['member_type']); ?>
                            </span>
                          </td>
                          <td><?php echo date('g:i A', strtotime($record['check_in'])); ?></td>
                          <td>
                            <span class="px-2 py-1 rounded-full text-xs <?php echo $record['check_out'] ? 'bg-gray-500/20 text-gray-400' : 'bg-green-500/20 text-green-400'; ?>">
                              <?php echo $record['check_out'] ? 'Checked Out' : 'Checked In'; ?>
                            </span>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
              <?php if (!empty($todaysCheckins)): ?>
                <div class="mt-3 text-xs text-gray-500 text-center">
                  Showing <?php echo count($todaysCheckins); ?> check-in<?php echo count($todaysCheckins) !== 1 ? 's' : ''; ?> for today
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Right Column - Actions and Info (2/5 width) -->
          <div class="xl:col-span-2 space-y-4">
            <!-- Quick Actions -->
            <div class="card">
              <h2 class="text-lg font-semibold text-yellow-400 mb-3 flex items-center gap-2">
                <i data-lucide="zap"></i>
                Quick Actions
              </h2>
              
              <div class="grid grid-cols-2 gap-2">
                <a href="member_registration.php" class="flex flex-col items-center p-3 bg-blue-500/10 border border-blue-500/30 rounded-lg hover:bg-blue-500/20 transition-colors">
                  <i data-lucide="user-plus" class="w-5 h-5 text-blue-400 mb-1"></i>
                  <span class="text-xs text-white text-center">Register Member</span>
                </a>
                
                <a href="trainerworkout.php" class="flex flex-col items-center p-3 bg-green-500/10 border border-green-500/30 rounded-lg hover:bg-green-500/20 transition-colors">
                  <i data-lucide="dumbbell" class="w-5 h-5 text-green-400 mb-1"></i>
                  <span class="text-xs text-white text-center">Workout Plans</span>
                </a>
                
                <a href="trainermealplan.php" class="flex flex-col items-center p-3 bg-purple-500/10 border border-purple-500/30 rounded-lg hover:bg-purple-500/20 transition-colors">
                  <i data-lucide="utensils" class="w-5 h-5 text-purple-400 mb-1"></i>
                  <span class="text-xs text-white text-center">Meal Plans</span>
                </a>
                
                <a href="clientprogress.php" class="flex flex-col items-center p-3 bg-orange-500/10 border border-orange-500/30 rounded-lg hover:bg-orange-500/20 transition-colors">
                  <i data-lucide="activity" class="w-5 h-5 text-orange-400 mb-1"></i>
                  <span class="text-xs text-white text-center">Client Progress</span>
                </a>
              </div>
            </div>

      
            <!-- Equipment Status -->
            <div class="card">
              <h2 class="text-lg font-semibold text-yellow-400 mb-3 flex items-center gap-2">
                <i data-lucide="alert-triangle"></i>
                Equipment Status
              </h2>
              
              <div class="grid grid-cols-3 gap-2 mb-3">
                <div class="text-center p-2 rounded-lg border border-red-500/30 bg-red-500/10">
                  <div class="text-lg font-bold text-red-400"><?php echo $equipment_stats['Broken'] ?? 0; ?></div>
                  <div class="text-xs text-red-300">Broken</div>
                </div>
                <div class="text-center p-2 rounded-lg border border-orange-500/30 bg-orange-500/10">
                  <div class="text-lg font-bold text-orange-400"><?php echo $equipment_stats['Under Repair'] ?? 0; ?></div>
                  <div class="text-xs text-orange-300">Repairing</div>
                </div>
                <div class="text-center p-2 rounded-lg border border-yellow-500/30 bg-yellow-500/10">
                  <div class="text-lg font-bold text-yellow-400"><?php echo $equipment_stats['Needs Maintenance'] ?? 0; ?></div>
                  <div class="text-xs text-yellow-300">Maintenance</div>
                </div>
              </div>

              <div class="mt-2">
                <a href="trainer_maintenance_report.php" class="button-sm bg-yellow-600 hover:bg-yellow-700 text-white w-full justify-center text-sm">
                  <i data-lucide="clipboard-list"></i> View Maintenance Report
                </a>
              </div>
            </div>

            <!-- Upcoming Renewals -->
            <div class="card">
              <h2 class="text-lg font-semibold text-yellow-400 mb-3 flex items-center gap-2">
                <i data-lucide="calendar"></i>
                Upcoming Renewals
              </h2>
              
              <?php if (!empty($upcoming_renewals)): ?>
                <div class="space-y-2 max-h-60 overflow-y-auto">
                  <?php foreach ($upcoming_renewals as $renewal): ?>
                    <div class="flex items-center justify-between p-2 bg-gray-800/50 rounded-lg">
                      <div class="flex items-center gap-2">
                        <div class="w-2 h-2 rounded-full 
                          <?php echo $renewal['days_remaining'] <= 2 ? 'bg-red-500' : 
                                 ($renewal['days_remaining'] <= 5 ? 'bg-orange-500' : 'bg-yellow-500'); ?>">
                        </div>
                        <div>
                          <div class="text-sm text-white"><?php echo htmlspecialchars($renewal['full_name']); ?></div>
                          <div class="text-xs text-gray-400"><?php echo ucfirst($renewal['membership_plan']); ?></div>
                        </div>
                      </div>
                      <div class="text-right">
                        <div class="text-xs <?php echo $renewal['days_remaining'] <= 2 ? 'text-red-400' : 
                                                   ($renewal['days_remaining'] <= 5 ? 'text-orange-400' : 'text-yellow-400'); ?>">
                          <?php echo $renewal['days_remaining'] == 0 ? 'Today' : 
                                 ($renewal['days_remaining'] == 1 ? 'Tomorrow' : 
                                 $renewal['days_remaining'] . ' days'); ?>
                        </div>
                        <div class="text-xs text-gray-400">
                          <?php echo date('M j', strtotime($renewal['expiry_date'])); ?>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="mt-3">
                  <a href="membership_status.php" class="button-sm bg-yellow-600 hover:bg-yellow-700 text-white w-full justify-center text-sm">
                    <i data-lucide="refresh-cw"></i> Manage Renewals
                  </a>
                </div>
              <?php else: ?>
                <div class="text-center py-4 text-gray-500">
                  <i data-lucide="check-circle" class="w-8 h-8 mx-auto text-green-400 mb-2"></i>
                  <p class="text-sm">No upcoming renewals!</p>
                  <p class="text-xs">All memberships are up to date</p>
                </div>
              <?php endif; ?>
            </div>

            <!-- Active Announcements -->
            <div class="card">
              <div class="flex justify-between items-center mb-3">
                <h2 class="card-title"><i data-lucide="megaphone"></i> Announcements</h2>
                <span class="text-xs text-gray-500" id="announcementCount"><?php echo count($announcements); ?> active</span>
              </div>
              
              <div id="announcementsList" class="max-h-60 overflow-y-auto">
                <?php foreach ($announcements as $announcement): ?>
                  <div class="announcement-item <?php echo $announcement['priority'] === 'high' ? 'urgent' : ($announcement['priority'] === 'medium' ? '' : 'info'); ?>">
                    <div class="announcement-title text-sm">
                      <?php echo htmlspecialchars($announcement['title']); ?>
                      <span class="priority-badge priority-<?php echo $announcement['priority']; ?>">
                        <?php echo ucfirst($announcement['priority']); ?>
                      </span>
                    </div>
                    <div class="announcement-date text-xs">
                      <?php echo date('M j, Y', strtotime($announcement['created_at'])); ?> 
                      <?php if ($announcement['expiry_date']): ?>
                        • Expires: <?php echo date('M j, Y', strtotime($announcement['expiry_date'])); ?>
                      <?php endif; ?>
                    </div>
                    <div class="announcement-content text-sm">
                      <?php echo htmlspecialchars($announcement['content']); ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              
              <?php if (empty($announcements)): ?>
                <div id="noAnnouncements" class="text-center py-4">
                  <i data-lucide="megaphone" class="w-8 h-8 text-gray-600 mx-auto mb-2"></i>
                  <p class="text-sm text-gray-500">No active announcements</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>

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
    // Auto-refresh attendance data every 2 minutes
    function setupAutoRefresh() {
        setInterval(() => {
            if (document.visibilityState === 'visible') {
                refreshAttendanceData();
            }
        }, 120000);
    }

    // Refresh only attendance data (lighter than full refresh)
    function refreshAttendanceData() {
        fetch('attendance_ajax.php?action=get_today_count')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('attendanceCount').textContent = data.count;
                    const element = document.getElementById('attendanceCount');
                    element.classList.add('text-green-400');
                    setTimeout(() => element.classList.remove('text-green-400'), 1000);
                }
            })
            .catch(error => console.error('Error refreshing attendance:', error));
    }

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

    // Loading state management
    function setLoadingState(element, isLoading) {
        if (isLoading) {
            element.classList.add('loading');
            element.disabled = true;
        } else {
            element.classList.remove('loading');
            element.disabled = false;
        }
    }

    // Initialize charts and functionality
    document.addEventListener('DOMContentLoaded', function() {
      // Attendance Chart with Real Data
      const ctx = document.getElementById('attendanceChart').getContext('2d');

      new Chart(ctx, {
          type: 'line',
          data: {
              labels: <?php echo json_encode($weeklyData['labels']); ?>,
              datasets: [{
                  label: 'Daily Check-ins',
                  data: <?php echo json_encode($weeklyData['data']); ?>,
                  borderColor: '#fbbf24',
                  backgroundColor: 'rgba(251, 191, 36, 0.15)',
                  fill: true,
                  tension: 0.4,
                  borderWidth: 3,
                  pointBackgroundColor: '#fbbf24',
                  pointRadius: 5,
                  pointHoverRadius: 7,
                  pointBorderColor: '#0f172a',
                  pointBorderWidth: 2
              }]
          },
          options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: { 
                  legend: { display: false },
                  tooltip: {
                      backgroundColor: 'rgba(13, 13, 13, 0.9)',
                      titleColor: '#fbbf24',
                      bodyColor: '#e2e8f0',
                      borderColor: 'rgba(251, 191, 36, 0.2)',
                      borderWidth: 1,
                      padding: 12,
                      boxPadding: 6,
                      callbacks: {
                          title: function(context) {
                              return context[0].label + ' Attendance';
                          },
                          label: function(context) {
                              return `Check-ins: ${context.parsed.y}`;
                          }
                      }
                  }
              },
              scales: {
                  x: { 
                      ticks: { color: '#94a3b8', font: { size: 11 } }, 
                      grid: { color: 'rgba(255, 255, 255, 0.05)' } 
                  },
                  y: { 
                      ticks: { color: '#94a3b8', font: { size: 11 } }, 
                      grid: { color: 'rgba(255, 255, 255, 0.05)' }, 
                      beginAtZero: true,
                      precision: 0
                  }
              },
              interaction: {
                  intersect: false,
                  mode: 'index'
              }
          }
      });

      // Initialize icons
      lucide.createIcons();
      
      // Initialize QR scanner
      setupQRScanner();

      // Initialize dropdown functionality
      setupDropdowns();

      // Prevent double form submission
      setupFormSubmissionProtection();

      // Setup refresh button
      document.getElementById('refreshData').addEventListener('click', refreshDashboardData);

      // Mobile sidebar handling
      setupMobileSidebar();
      
      // Setup auto-refresh
      setupAutoRefresh();
    });

    // Mobile sidebar setup
    function setupMobileSidebar() {
        const sidebar = document.getElementById('sidebar');
        const toggleBtn = document.getElementById('toggleSidebar');
        
        function isMobile() {
            return window.innerWidth <= 768;
        }
        
        toggleBtn.addEventListener('click', function() {
            if (isMobile()) {
                sidebar.classList.toggle('mobile-open');
            } else {
                if (sidebar.classList.contains('w-60')) {
                    sidebar.classList.remove('w-60');
                    sidebar.classList.add('w-16', 'sidebar-collapsed');
                } else {
                    sidebar.classList.remove('w-16', 'sidebar-collapsed');
                    sidebar.classList.add('w-60');
                }
            }
        });
        
        document.addEventListener('click', function(e) {
            if (isMobile() && 
                !sidebar.contains(e.target) && 
                !toggleBtn.contains(e.target) &&
                sidebar.classList.contains('mobile-open')) {
                sidebar.classList.remove('mobile-open');
            }
        });
        
        if (isMobile()) {
            document.querySelectorAll('.sidebar-item').forEach(item => {
                item.style.cursor = 'pointer';
            });
        }
    }

    // Refresh dashboard data
    function refreshDashboardData() {
        const refreshBtn = document.getElementById('refreshData');
        setLoadingState(refreshBtn, true);
        
        showToast('Refreshing data...', 'info', 2000);
        
        setTimeout(() => {
            window.location.reload();
        }, 1000);
    }

    // Prevent double form submissions
    function setupFormSubmissionProtection() {
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    setLoadingState(submitBtn, true);
                    submitBtn.innerHTML = '<i data-lucide="loader" class="animate-spin"></i> Processing...';
                    lucide.createIcons();
                    
                    setTimeout(() => {
                        setLoadingState(submitBtn, false);
                        submitBtn.innerHTML = '<i data-lucide="log-in"></i> Check In Member';
                        lucide.createIcons();
                    }, 5000);
                }
            });
        });
    }

    // Section navigation
    function showSection(sectionId) {
      document.querySelectorAll('.section').forEach(section => {
        section.classList.remove('active');
      });
      
      document.getElementById(sectionId).classList.add('active');
      
      document.querySelectorAll('.sidebar-item').forEach(item => {
        item.classList.remove('active');
      });
      
      if (sectionId === 'dashboard') {
        document.querySelector('.sidebar-item').classList.add('active');
      } else {
        const sidebarItem = document.querySelector(`.sidebar-item[onclick="showSection('${sectionId}')"]`);
        if (sidebarItem) {
          sidebarItem.classList.add('active');
        }
      }
      
      if (sectionId !== 'dashboard') {
        document.getElementById('membersSubmenu').classList.remove('open');
        document.getElementById('membersChevron').classList.remove('rotate');
      }
      
      if (window.innerWidth <= 768) {
        document.getElementById('sidebar').classList.remove('mobile-open');
      }
    }

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
    
    // Enhanced notification functionality
    function setupDropdowns() {
        const notificationBell = document.getElementById('notificationBell');
        const notificationDropdown = document.getElementById('notificationDropdown');
        const userMenuButton = document.getElementById('userMenuButton');
        const userDropdown = document.getElementById('userDropdown');
        
        function closeAllDropdowns() {
            notificationDropdown.classList.add('hidden');
            userDropdown.classList.add('hidden');
        }
        
        notificationBell.addEventListener('click', function(e) {
            e.stopPropagation();
            const isHidden = notificationDropdown.classList.contains('hidden');
            
            closeAllDropdowns();
            
            if (isHidden) {
                notificationDropdown.classList.remove('hidden');
            }
        });
        
        userMenuButton.addEventListener('click', function(e) {
            e.stopPropagation();
            const isHidden = userDropdown.classList.contains('hidden');
            
            closeAllDropdowns();
            
            if (isHidden) {
                userDropdown.classList.remove('hidden');
            }
        });
        
        document.addEventListener('click', function(e) {
            if (!notificationDropdown.contains(e.target) && !notificationBell.contains(e.target) &&
                !userDropdown.contains(e.target) && !userMenuButton.contains(e.target)) {
                closeAllDropdowns();
            }
        });
        
        document.getElementById('markAllRead')?.addEventListener('click', function(e) {
            e.stopPropagation();
            markAllNotificationsAsRead();
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAllDropdowns();
            }
        });
    }

    // AJAX function to mark all notifications as read
    function markAllNotificationsAsRead() {
        setLoadingState(document.getElementById('markAllRead'), true);
        
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
                document.getElementById('notificationBadge').classList.add('hidden');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('Failed to mark notifications as read', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Network error occurred', 'error');
        })
        .finally(() => {
            setLoadingState(document.getElementById('markAllRead'), false);
        });
    }

    function setupQRScanner() {
        const qrScanner = document.getElementById('qrScanner');
        const qrScannerHeader = document.getElementById('qrScannerHeader');
        const qrInput = document.getElementById('qrInput');
        const processQRBtn = document.getElementById('processQR');
        const toggleScannerBtn = document.getElementById('toggleScanner');
        const toggleQRScannerBtn = document.getElementById('toggleQRScannerBtn');
        const closeQRScannerBtn = document.getElementById('closeQRScanner');
        const qrScannerStatus = document.getElementById('qrScannerStatus');

        let qrScannerActive = true;
        let lastProcessedQR = '';
        let lastProcessedTime = 0;
        let qrProcessing = false;
        let qrCooldown = false;
        let isDragging = false;
        let dragOffset = { x: 0, y: 0 };

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
            if (e.target.closest('button')) return;
            
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
            
            showQRResult('info', 'Processing', 'Scanning QR code...');
            showToast('Processing QR code...', 'info', 2000);
            
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
                    
                    const currentCount = parseInt(document.getElementById('attendanceCount').textContent);
                    document.getElementById('attendanceCount').textContent = currentCount + 1;
                    
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
                
                setTimeout(() => {
                    qrInput.value = '';
                    const qrScanner = document.getElementById('qrScanner');
                    if (qrScannerActive && !qrScanner.classList.contains('hidden')) {
                        qrInput.focus();
                    }
                }, 500);
                
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
            
            let hideTime = type === 'success' ? 4000 : 5000;
            if (title === 'Cooldown') hideTime = 3000;
            if (title === 'Processing') hideTime = 2000;
            
            setTimeout(() => {
                qrResult.style.display = 'none';
            }, hideTime);
        }
    }

    // Global function to open QR scanner
    function openQRScanner() {
        const qrScanner = document.getElementById('qrScanner');
        const qrInput = document.getElementById('qrInput');
        
        qrScanner.classList.remove('hidden');
        if (qrScannerActive) {
            setTimeout(() => qrInput.focus(), 100);
        }
    }

    // Listen for QR scan success events
    window.addEventListener('qrScanSuccess', function(event) {
        setTimeout(() => {
            refreshDashboardData();
        }, 2000);
    });
  </script>
</body>
</html>
<?php
$conn->close();
?>



