<?php
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

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ADD THIS SECTION FOR CHAT FUNCTIONALITY
require_once 'chat_functions.php';
$unread_count = getUnreadCount($_SESSION['user_id'], $conn);
$trainer_user_id = $_SESSION['user_id'];

// Function to get trainer notifications
function getTrainerNotifications($conn, $trainer_user_id) {
    $notifications = [];
    
    $sql = "SELECT * FROM notifications 
            WHERE (user_id = ? OR user_id IS NULL OR role = 'trainer') 
            AND (read_status = 0 OR read_status IS NULL)
            ORDER BY created_at DESC 
            LIMIT 10";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $trainer_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    
    return $notifications;
}

// Get notifications for the current trainer
$notifications = getTrainerNotifications($conn, $trainer_user_id);
$notification_count = count($notifications);

// Include QR Code library
require_once 'phpqrcode/qrlib.php';

// Handle QR code actions
$message = '';
$message_type = '';

// Check for stored messages from redirect
if (isset($_SESSION['qr_management_message'])) {
    $message = $_SESSION['qr_management_message'];
    $message_type = $_SESSION['qr_management_message_type'];
    unset($_SESSION['qr_management_message']);
    unset($_SESSION['qr_management_message_type']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['generate_qr'])) {
        $member_id = $_POST['member_id'];
        
        // Get member details
        $stmt = $conn->prepare("SELECT m.*, u.username FROM members m JOIN users u ON m.user_id = u.id WHERE m.id = ?");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        
        if ($member) {
            // Create QR code directory if it doesn't exist
            $qr_dir = 'qrcodes';
            if (!is_dir($qr_dir)) {
                mkdir($qr_dir, 0755, true);
            }
            
            // Generate unique filename
            $filename = "client_" . $member['user_id'] . "_" . time() . ".png";
            $filepath = $qr_dir . '/' . $filename;
            
            // FIXED: QR code content - Use simple member ID only
            $qr_content = "CLIENT_" . $member['id']; // Standardized format
            
            // Generate QR code
            QRcode::png($qr_content, $filepath, QR_ECLEVEL_L, 10);
            
            // Update database with QR code path
            $update_stmt = $conn->prepare("UPDATE members SET qr_code_path = ? WHERE id = ?");
            $update_stmt->bind_param("si", $filepath, $member_id);
            
            if ($update_stmt->execute()) {
                $message = "QR code generated successfully for " . $member['full_name'];
                $message_type = 'success';
            } else {
                $message = "Error updating QR code path: " . $conn->error;
                $message_type = 'error';
            }
        } else {
            $message = "Member not found!";
            $message_type = 'error';
        }
    }
    
    if (isset($_POST['delete_qr'])) {
        $member_id = $_POST['member_id'];
        
        // Get current QR code path
        $stmt = $conn->prepare("SELECT qr_code_path FROM members WHERE id = ?");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result && $result['qr_code_path']) {
            // Delete the file
            if (file_exists($result['qr_code_path'])) {
                unlink($result['qr_code_path']);
            }
            
            // Update database
            $update_stmt = $conn->prepare("UPDATE members SET qr_code_path = NULL WHERE id = ?");
            if ($update_stmt->execute()) {
                $message = "QR code deleted successfully!";
                $message_type = 'success';
            } else {
                $message = "Error deleting QR code from database!";
                $message_type = 'error';
            }
        } else {
            $message = "No QR code found to delete!";
            $message_type = 'error';
        }
    }
    
    if (isset($_POST['regenerate_qr'])) {
        $member_id = $_POST['member_id'];
        
        // First delete existing QR code
        $stmt = $conn->prepare("SELECT qr_code_path FROM members WHERE id = ?");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result && $result['qr_code_path'] && file_exists($result['qr_code_path'])) {
            unlink($result['qr_code_path']);
        }
        
        // Then generate new one
        $stmt = $conn->prepare("SELECT m.*, u.username FROM members m JOIN users u ON m.user_id = u.id WHERE m.id = ?");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        
        if ($member) {
            $qr_dir = 'qrcodes';
            if (!is_dir($qr_dir)) {
                mkdir($qr_dir, 0755, true);
            }
            
            $filename = "client_" . $member['user_id'] . "_" . time() . ".png";
            $filepath = $qr_dir . '/' . $filename;
            
            // FIXED: QR code content - Use simple member ID only
            $qr_content = "CLIENT_" . $member['id']; // Standardized format
            
            QRcode::png($qr_content, $filepath, QR_ECLEVEL_L, 10);
            
            $update_stmt = $conn->prepare("UPDATE members SET qr_code_path = ? WHERE id = ?");
            $update_stmt->bind_param("si", $filepath, $member_id);
            
            if ($update_stmt->execute()) {
                $message = "QR code regenerated successfully for " . $member['full_name'];
                $message_type = 'success';
            } else {
                $message = "Error regenerating QR code!";
                $message_type = 'error';
            }
        }
    }
    
    // After processing any form action, redirect back to prevent form resubmission
    if (isset($_POST['generate_qr']) || isset($_POST['delete_qr']) || isset($_POST['regenerate_qr'])) {
        // Store message in session for display after redirect
        if ($message && $message_type) {
            $_SESSION['qr_management_message'] = $message;
            $_SESSION['qr_management_message_type'] = $message_type;
        }
        
        // Redirect to prevent form resubmission
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Get all clients with their QR code status
$clients_query = "
    SELECT m.id, m.full_name, m.membership_plan, m.expiry_date, m.status, m.qr_code_path, 
           u.username, u.email, m.created_at 
    FROM members m 
    JOIN users u ON m.user_id = u.id 
    WHERE m.member_type = 'client' 
    ORDER BY m.created_at DESC
";
$clients_result = $conn->query($clients_query);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BOIYETS FITNESS GYM - QR Code Management</title>
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
    
    /* FIXED DROPDOWN STYLES - Matching trainer_dashboard.php */
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
    
    .status-active {
      color: #10b981;
      background: rgba(16, 185, 129, 0.1);
      padding: 0.25rem 0.75rem;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 500;
    }
    
    .status-expired {
      color: #ef4444;
      background: rgba(239, 68, 68, 0.1);
      padding: 0.25rem 0.75rem;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 500;
    }
    
    .status-expiring {
      color: #f59e0b;
      background: rgba(245, 158, 11, 0.1);
      padding: 0.25rem 0.75rem;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 500;
    }
    
    .plan-badge {
      background: rgba(59, 130, 246, 0.2);
      color: #3b82f6;
      padding: 0.25rem 0.75rem;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 500;
    }
    
    .qr-status {
      padding: 0.25rem 0.75rem;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 500;
    }
    
    .qr-available {
      background: rgba(16, 185, 129, 0.2);
      color: #10b981;
    }
    
    .qr-missing {
      background: rgba(107, 114, 128, 0.2);
      color: #6b7280;
    }
    
    .qr-preview {
      max-width: 150px;
      border: 2px solid #374151;
      border-radius: 8px;
      padding: 0.5rem;
      background: white;
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
    
    .stat-card {
      background: rgba(55, 65, 81, 0.8);
      border-radius: 12px;
      padding: 1.5rem;
      text-align: center;
      transition: all 0.3s ease;
    }
    
    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
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

    /* Toast notifications */
    .toast {
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 1rem 1.5rem;
        border-radius: 8px;
        color: white;
        z-index: 1000;
        transform: translateX(400px);
        transition: transform 0.3s ease;
        max-width: 300px;
    }

    .toast.show {
        transform: translateX(0);
    }

    .toast.success {
        background: rgba(16, 185, 129, 0.9);
    }

    .toast.error {
        background: rgba(239, 68, 68, 0.9);
    }

    .toast.warning {
        background: rgba(245, 158, 11, 0.9);
    }

    .toast.info {
        background: rgba(59, 130, 246, 0.9);
    }

    /* Loading states */
    .btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    .animate-spin {
        animation: spin 1s linear infinite;
    }
    
    .fade-out {
        opacity: 0;
        transition: opacity 0.3s ease;
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
        <a href="trainermanageqrcodes.php" class="sidebar-item active">
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
          <i data-lucide="qrcode"></i>
          QR Code Management
        </h1>
        <div class="flex gap-2">
          <button onclick="openQRScanner()" class="btn btn-primary">
            <i data-lucide="scan"></i> Test QR Scanner
          </button>
        </div>
      </div>

      <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
          <i data-lucide="<?php echo $message_type === 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-5 h-5 mr-2"></i>
          <?php echo $message; ?>
        </div>
      <?php endif; ?>

      <!-- Statistics -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="stat-card">
          <div class="text-3xl font-bold text-yellow-400 mb-2">
            <?php echo $clients_result->num_rows; ?>
          </div>
          <div class="text-gray-400">Total Clients</div>
        </div>
        
        <div class="stat-card">
          <div class="text-3xl font-bold text-yellow-400 mb-2">
            <?php
            $qr_count = 0;
            if ($clients_result->num_rows > 0) {
              $clients_result->data_seek(0);
              while ($client = $clients_result->fetch_assoc()) {
                if ($client['qr_code_path'] && file_exists($client['qr_code_path'])) {
                  $qr_count++;
                }
              }
              $clients_result->data_seek(0); // Reset pointer
            }
            echo $qr_count;
            ?>
          </div>
          <div class="text-gray-400">QR Codes Generated</div>
        </div>
        
        <div class="stat-card">
          <div class="text-3xl font-bold text-yellow-400 mb-2">
            <?php echo $clients_result->num_rows - $qr_count; ?>
          </div>
          <div class="text-gray-400">Pending QR Codes</div>
        </div>
        
        <div class="stat-card">
          <div class="text-3xl font-bold text-yellow-400 mb-2">
            <?php
            $active_count = 0;
            if ($clients_result->num_rows > 0) {
              $clients_result->data_seek(0);
              while ($client = $clients_result->fetch_assoc()) {
                if ($client['status'] == 'active') {
                  $active_count++;
                }
              }
              $clients_result->data_seek(0); // Reset pointer
            }
            echo $active_count;
            ?>
          </div>
          <div class="text-gray-400">Active Members</div>
        </div>
      </div>

      <!-- Clients List -->
      <div class="card">
        <div class="flex justify-between items-center mb-6">
          <h2 class="text-lg font-semibold text-yellow-400 flex items-center gap-2">
            <i data-lucide="users"></i>
            Client QR Code Management
          </h2>
          
          <div class="flex gap-2">
            <button onclick="generateAllQRCodes()" class="btn btn-success">
              <i data-lucide="download"></i> Generate All Missing QR Codes
            </button>
          </div>
        </div>
        
        <div class="table-container">
          <table>
            <thead>
              <tr>
                <th>Client Information</th>
                <th>Membership</th>
                <th>QR Code Status</th>
                <th>QR Code Preview</th>
                <th class="text-center">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($clients_result->num_rows > 0): ?>
                <?php while ($client = $clients_result->fetch_assoc()): ?>
                  <tr>
                    <td>
                      <div class="font-medium text-white"><?php echo htmlspecialchars($client['full_name']); ?></div>
                      <div class="text-sm text-gray-400">@<?php echo htmlspecialchars($client['username']); ?></div>
                      <div class="text-sm text-gray-400"><?php echo htmlspecialchars($client['email']); ?></div>
                      <div class="text-xs text-yellow-400 mt-1">Member ID: <?php echo $client['id']; ?></div>
                    </td>
                    <td>
                      <span class="plan-badge"><?php echo ucfirst($client['membership_plan']); ?></span>
                      <div class="mt-2">
                        <span class="status-<?php echo $client['status']; ?>">
                          <?php echo ucfirst($client['status']); ?>
                        </span>
                      </div>
                      <div class="text-xs text-gray-400 mt-1">
                        Expires: <?php echo date('M j, Y', strtotime($client['expiry_date'])); ?>
                      </div>
                    </td>
                    <td>
                      <?php if ($client['qr_code_path'] && file_exists($client['qr_code_path'])): ?>
                        <span class="qr-status qr-available">
                          <i data-lucide="check-circle" class="w-3 h-3 inline mr-1"></i>
                          Generated
                        </span>
                        <div class="text-xs text-gray-400 mt-1">
                          Contains: Member ID <?php echo $client['id']; ?>
                        </div>
                      <?php else: ?>
                        <span class="qr-status qr-missing">
                          <i data-lucide="x-circle" class="w-3 h-3 inline mr-1"></i>
                          Not Generated
                        </span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($client['qr_code_path'] && file_exists($client['qr_code_path'])): ?>
                        <img src="<?php echo $client['qr_code_path']; ?>" 
                             alt="QR Code" 
                             class="qr-preview mx-auto">
                        <div class="text-center mt-2">
                          <a href="<?php echo $client['qr_code_path']; ?>" 
                             download 
                             class="text-blue-400 hover:text-blue-300 text-sm flex items-center justify-center gap-1">
                            <i data-lucide="download" class="w-3 h-3"></i>
                            Download
                          </a>
                        </div>
                      <?php else: ?>
                        <div class="text-gray-500 text-center">
                          <i data-lucide="qrcode" class="w-8 h-8 mx-auto mb-1"></i>
                          <div class="text-xs">No QR Code</div>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="flex flex-col gap-2">
                        <?php if (!$client['qr_code_path'] || !file_exists($client['qr_code_path'])): ?>
                          <form method="POST" class="inline">
                            <input type="hidden" name="member_id" value="<?php echo $client['id']; ?>">
                            <button type="submit" name="generate_qr" class="btn btn-primary w-full">
                              <i data-lucide="plus" class="w-4 h-4"></i>
                              Generate QR
                            </button>
                          </form>
                        <?php else: ?>
                          <form method="POST" class="inline">
                            <input type="hidden" name="member_id" value="<?php echo $client['id']; ?>">
                            <button type="submit" name="regenerate_qr" class="btn btn-success w-full">
                              <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                              Regenerate
                            </button>
                          </form>
                          
                          <form method="POST" class="inline" onsubmit="return confirmDelete(this);">
                            <input type="hidden" name="member_id" value="<?php echo $client['id']; ?>">
                            <button type="submit" name="delete_qr" class="btn btn-danger w-full">
                              <i data-lucide="trash-2" class="w-4 h-4"></i>
                              Delete
                            </button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr>
                  <td colspan="5" class="empty-state">
                    <i data-lucide="users" class="w-16 h-16 mx-auto mb-4"></i>
                    <p>No clients found.</p>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>

  <!-- QR Scanner Container - MOVABLE & TOGGLEABLE -->
  <div id="qrScanner" class="qr-scanner-container hidden">
    <div class="qr-scanner-header cursor-move" id="qrScannerHeader">
      <div class="qr-scanner-title">
        <i data-lucide="scan"></i>
        <span>QR Code Test Scanner</span>
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

        // Dropdown functionality
        setupDropdowns();

        // Initialize QR Scanner
        setupQRScanner();

        // Enhanced form handling
        setupFormHandling();
    });

    // Enhanced form handling with loading states
    function setupFormHandling() {
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                
                // Show loading state for all form submissions
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i data-lucide="loader" class="animate-spin w-4 h-4"></i> Processing...';
                    lucide.createIcons();
                }
                
                // For delete operations, we'll let the form submit normally
                // The PHP redirect will handle the page refresh
            });
        });
    }

    // Enhanced delete confirmation
    function confirmDelete(form) {
        const memberName = form.closest('tr').querySelector('.font-medium').textContent;
        return confirm(`Are you sure you want to delete the QR code for ${memberName}? This action cannot be undone.`);
    }

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
        
        // Close dropdowns when pressing Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAllDropdowns();
            }
        });
    }

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
                
                // Show member information if available
                if (data.member_name) {
                    showQRResult('success', 'Member Found', `QR code belongs to: ${data.member_name}`);
                }
                
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

    function setLoadingState(button, isLoading) {
        if (isLoading) {
            button.disabled = true;
            button.style.opacity = '0.7';
        } else {
            button.disabled = false;
            button.style.opacity = '1';
        }
    }

    function showToast(message, type = 'info', duration = 3000) {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <div class="flex items-center gap-2">
                <i data-lucide="${getToastIcon(type)}" class="w-4 h-4"></i>
                <span>${message}</span>
            </div>
        `;
        
        document.body.appendChild(toast);
        
        // Trigger animation
        setTimeout(() => toast.classList.add('show'), 100);
        
        // Remove toast after duration
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, duration);
        
        lucide.createIcons();
    }

    function getToastIcon(type) {
        switch(type) {
            case 'success': return 'check-circle';
            case 'error': return 'alert-circle';
            case 'warning': return 'alert-triangle';
            default: return 'info';
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

    function generateAllQRCodes() {
        if (confirm('This will generate QR codes for all clients who don\'t have one. Continue?')) {
            // Get all clients without QR codes
            const rows = document.querySelectorAll('tbody tr');
            let pendingGenerations = 0;
            
            rows.forEach(row => {
                const qrStatus = row.querySelector('.qr-status');
                if (qrStatus && qrStatus.classList.contains('qr-missing')) {
                    const generateForm = row.querySelector('form button[name="generate_qr"]');
                    if (generateForm) {
                        pendingGenerations++;
                        generateForm.click();
                    }
                }
            });
            
            if (pendingGenerations === 0) {
                alert('All clients already have QR codes!');
            } else {
                // Show loading message
                const messageDiv = document.createElement('div');
                messageDiv.className = 'alert alert-success';
                messageDiv.innerHTML = '<i data-lucide="loader" class="w-5 h-5 mr-2"></i>Generating ' + pendingGenerations + ' QR codes... Please wait.';
                document.querySelector('.flex-1').insertBefore(messageDiv, document.querySelector('.card'));
                
                // Reload page after a delay to show results
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            }
        }
    }
  </script>
</body>
</html>



