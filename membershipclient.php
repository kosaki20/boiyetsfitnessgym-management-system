<?php
session_start();
if (!isset($_SESSION['username']) || $_SESSION['role'] != 'client') {
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

// Initialize variables
$client = [];
$membership = [];
$unread_count = 0;
$notification_count = 0;
$notifications = [];
$logged_in_user_id = $_SESSION['user_id'] ?? 0;

function getClientDetails($conn, $user_id) {
    $sql = "SELECT m.* FROM members m 
            INNER JOIN users u ON m.user_id = u.id 
            WHERE u.id = ? AND m.member_type = 'client'";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc() ?? [];
}

function getClientMembershipStatus($conn, $user_id) {
    $sql = "SELECT m.* FROM members m 
            INNER JOIN users u ON m.user_id = u.id 
            WHERE u.id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc() ?? [];
}

// Get client and membership data
$client = getClientDetails($conn, $logged_in_user_id);
$membership = getClientMembershipStatus($conn, $logged_in_user_id);

// Calculate days until expiry
if ($membership && isset($membership['expiry_date']) && $membership['expiry_date']) {
    $expiry = new DateTime($membership['expiry_date']);
    $today = new DateTime();
    $daysLeft = $today->diff($expiry)->days;
    if ($today > $expiry) {
        $daysLeft = -$daysLeft;
    }
    $membership['days_left'] = $daysLeft;
} else {
    $membership['days_left'] = 0;
    $membership['status'] = 'inactive';
    $membership['expiry_date'] = null;
    $membership['membership_plan'] = 'none';
}

// Get trainer assignment
$trainer_id = null;
$trainer_name = null;
if ($logged_in_user_id) {
    $trainer_sql = "SELECT u.id, u.full_name 
                   FROM trainer_client_assignments tca 
                   INNER JOIN users u ON tca.trainer_user_id = u.id 
                   WHERE tca.client_user_id = ? AND tca.status = 'active' 
                   LIMIT 1";
    $stmt = $conn->prepare($trainer_sql);
    if ($stmt) {
        $stmt->bind_param("i", $logged_in_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $trainer_id = $row['id'];
            $trainer_name = $row['full_name'];
        }
        $stmt->close();
    }
}

// Membership plans with pricing
$membership_plans = [
    'daily' => ['name' => 'Per Visit', 'price' => 40, 'duration' => '1 day'],
    'weekly' => ['name' => 'Weekly', 'price' => 160, 'duration' => '7 days'],
    'halfmonth' => ['name' => 'Half Month', 'price' => 250, 'duration' => '15 days'],
    'monthly' => ['name' => 'Monthly', 'price' => 400, 'duration' => '30 days']
];

// Process renewal request if form is submitted
$renewal_success = false;
$success_message = "";
$success_details = [];
$error_message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_renewal'])) {
    $plan_type = $_POST['plan_type'] ?? '';
    $payment_method = $_POST['payment_method'] ?? '';
    
    // Validate input
    if (empty($plan_type)) {
        $error_message = "Please select a membership plan.";
    } elseif (!array_key_exists($plan_type, $membership_plans)) {
        $error_message = "Invalid membership plan selected.";
    } elseif (empty($payment_method)) {
        $error_message = "Please select a payment method.";
    } elseif (!$trainer_id) {
        $error_message = "No assigned trainer found. Please contact administration.";
    } elseif (!$membership || !isset($membership['id'])) {
        $error_message = "Member record not found. Please contact administration.";
    } else {
        $plan = $membership_plans[$plan_type];
        
        // Insert renewal request
        $insert_sql = "INSERT INTO membership_renewal_requests 
                      (member_id, member_name, trainer_id, plan_type, amount, payment_method, status) 
                      VALUES (?, ?, ?, ?, ?, ?, 'pending')";
        
        $stmt = $conn->prepare($insert_sql);
        if ($stmt) {
            $member_name = $client['full_name'] ?? $_SESSION['username'];
            $stmt->bind_param("isisds", 
                $membership['id'], 
                $member_name,
                $trainer_id,
                $plan_type,
                $plan['price'],
                $payment_method
            );
            
            if ($stmt->execute()) {
                $renewal_request_id = $stmt->insert_id;
                
                // Create notification for trainer
                $notification_sql = "INSERT INTO notifications 
                                    (user_id, role, title, message, type, priority) 
                                    VALUES (?, 'trainer', 'Membership Renewal Request', 
                                    'Client {$member_name} requested {$plan['name']} renewal via {$payment_method}', 
                                    'membership', 'high')";
                $notif_stmt = $conn->prepare($notification_sql);
                if ($notif_stmt) {
                    $notif_stmt->bind_param("i", $trainer_id);
                    $notif_stmt->execute();
                    $notif_stmt->close();
                }
                
                $renewal_success = true;
                $success_message = "Renewal request submitted successfully! Your trainer {$trainer_name} will review and process your request.";
                $success_details = [
                    'plan_type' => $plan['name'],
                    'amount' => $plan['price'],
                    'payment_method' => $payment_method,
                    'trainer' => $trainer_name
                ];
                
                if ($payment_method === 'gcash') {
                    $success_message .= " Please send payment to GCash number 0917 123 4567 and wait for verification.";
                }
            } else {
                $error_message = "Error submitting renewal request: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error_message = "Database error: " . $conn->error;
        }
    }
}

// Get pending renewal requests
$pending_requests = [];
if ($membership && isset($membership['id'])) {
    $requests_sql = "SELECT * FROM membership_renewal_requests 
                    WHERE member_id = ? AND status IN ('pending', 'paid') 
                    ORDER BY created_at DESC";
    $stmt = $conn->prepare($requests_sql);
    if ($stmt) {
        $stmt->bind_param("i", $membership['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $pending_requests[] = $row;
        }
        $stmt->close();
    }
}

// Get unread messages count
$unread_sql = "SELECT COUNT(*) as unread_count FROM chat_messages 
               WHERE receiver_id = ? AND is_read = 0";
$unread_stmt = $conn->prepare($unread_sql);
if ($unread_stmt) {
    $unread_stmt->bind_param("i", $logged_in_user_id);
    $unread_stmt->execute();
    $unread_result = $unread_stmt->get_result();
    $unread_data = $unread_result->fetch_assoc();
    $unread_count = $unread_data['unread_count'] ?? 0;
    $unread_stmt->close();
}

// Get notifications count
$notification_sql = "SELECT COUNT(*) as notification_count FROM notifications 
                    WHERE (user_id = ? OR role = 'client') AND read_status = 0";
$notification_stmt = $conn->prepare($notification_sql);
if ($notification_stmt) {
    $notification_stmt->bind_param("i", $logged_in_user_id);
    $notification_stmt->execute();
    $notification_result = $notification_stmt->get_result();
    $notification_data = $notification_result->fetch_assoc();
    $notification_count = $notification_data['notification_count'] ?? 0;
    $notification_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membership - BOIYETS FITNESS GYM</title>
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
        
        .mobile-sidebar {
            transform: translateX(-100%);
            transition: transform 0.3s ease-in-out;
            z-index: 50;
            display: none;
            will-change: transform;
        }
        
        .mobile-sidebar.open {
            transform: translateX(0);
        }
        
        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 40;
        }
        
        .overlay.active {
            display: block;
        }
        
        .sidebar { 
            flex-shrink: 0; 
            transition: all 0.3s ease;
            overflow-y: auto;
            -ms-overflow-style: none;
            scrollbar-width: none;
            height: calc(100vh - 64px);
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
            min-height: 44px;
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
            min-height: 44px;
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
        
        @media (min-width: 769px) {
            .sidebar-item:hover { 
                background: rgba(255,255,255,0.05); 
                color: #fbbf24; 
            }
            
            .submenu a:hover { 
                color: #fbbf24; 
                background: rgba(255,255,255,0.05); 
            }
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
            min-height: 44px;
        }
        
        .submenu a i { 
            width: 14px; 
            height: 14px; 
            margin-right: 0.5rem; 
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
            will-change: transform;
        }
        
        .card:hover {
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.2);
            transform: translateY(-2px);
        }

        .membership-card {
            background: rgba(26, 26, 26, 0.8);
            border-radius: 12px;
            padding: 1.5rem;
            color: #fbbf24;
            border-left: 6px solid #fbbf24;
        }
        
        .plan-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 2rem;
            border: 2px solid transparent;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .plan-card:hover {
            border-color: #fbbf24;
            transform: translateY(-5px);
        }
        
        .plan-card.selected {
            border-color: #fbbf24;
            background: rgba(251, 191, 36, 0.1);
        }
        
        .plan-card.popular {
            border-color: #fbbf24;
            background: rgba(251, 191, 36, 0.1);
        }
        
        .topbar {
            background: rgba(13, 13, 13, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            position: sticky;
            top: 0;
            z-index: 30;
            height: 64px;
        }
        
        .main-content {
            flex: 1;
            overflow-y: auto;
            height: calc(100vh - 64px);
        }
        
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .status-active {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .status-expired {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        .warning-banner {
            background: rgba(245, 158, 11, 0.2);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 8px;
            padding: 1rem;
        }
        
        .danger-banner {
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 8px;
            padding: 1rem;
        }

        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 1rem;
        }
        
        .modal-content {
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

        .success-modal {
            background: rgba(26, 26, 26, 0.95);
            border: 2px solid #10b981;
        }

        .mobile-nav {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(13, 13, 13, 0.95);
            backdrop-filter: blur(10px);
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            z-index: 30;
        }
        
        .mobile-nav-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0.5rem;
            color: #9ca3af;
            transition: all 0.2s ease;
            min-height: 44px;
        }
        
        .mobile-nav-item.active {
            color: #fbbf24;
        }
        
        .mobile-nav-item i {
            width: 20px;
            height: 20px;
            margin-bottom: 0.25rem;
        }
        
        .mobile-nav-label {
            font-size: 0.7rem;
        }

        @media (max-width: 768px) {
            .desktop-sidebar {
                display: none !important;
            }
            
            .mobile-sidebar {
                display: block;
            }
            
            .mobile-nav {
                display: flex;
            }
            
            .main-content {
                padding-bottom: 80px;
                height: calc(100vh - 144px);
            }
            
            .card {
                padding: 1rem;
            }
            
            .membership-card {
                padding: 1rem;
            }
            
            .plan-card {
                padding: 1.5rem;
            }
            
            .status-badge {
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
            }
            
            .card:hover {
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                transform: none;
            }
            
            .plan-card:hover {
                transform: none;
                border-color: transparent;
            }
            
            .plan-card.popular:hover {
                border-color: #fbbf24;
            }
            
            .plan-card.selected {
                border-color: #fbbf24;
            }
        }
        
        @media (max-width: 480px) {
            .grid-cols-1 {
                grid-template-columns: 1fr;
            }
            
            .grid-cols-2 {
                grid-template-columns: 1fr;
            }
            
            .membership-card .flex {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .membership-card .text-center {
                text-align: left;
                margin-top: 1rem;
            }
            
            .plan-card {
                padding: 1rem;
            }
        }

        .dropdown-container {
            position: relative;
        }
        
        .dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            width: 320px;
            background: #1a1a1a;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
            z-index: 1000;
            margin-top: 0.5rem;
        }
        
        .dropdown.hidden {
            display: none;
        }
        
        .notification-item {
            padding: 0.75rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
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

        .gcash-info {
            background: rgba(0, 150, 136, 0.1);
            border: 1px solid rgba(0, 150, 136, 0.3);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .gcash-details {
            background: rgba(0, 150, 136, 0.05);
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
        }
        
        .gcash-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: #00796b;
            margin: 1rem 0;
        }
        
        .qr-code-container {
            max-width: 200px;
            margin: 0 auto;
            padding: 1rem;
            background: white;
            border-radius: 8px;
        }
        
        .upload-area {
            border: 2px dashed #d1d5db;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .upload-area:hover {
            border-color: #fbbf24;
            background: rgba(251, 191, 36, 0.05);
        }
        
        .upload-area.dragover {
            border-color: #00796b;
            background: rgba(0, 150, 136, 0.1);
        }
        
        .request-status {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .status-pending {
            background: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
        }
        
        .status-paid {
            background: rgba(59, 130, 246, 0.2);
            color: #3b82f6;
        }
        
        .status-approved {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
        }
        
        .status-rejected {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }
    </style>
</head>
<body class="min-h-screen js-enabled">
  <!-- Mobile Overlay -->
  <div class="overlay" id="mobileOverlay"></div>

  <!-- Topbar with Enhanced Notification & Profile -->
  <header class="topbar flex items-center justify-between px-4 py-3 shadow">
    <div class="flex items-center space-x-3">
      <button id="toggleSidebar" class="text-gray-300 hover:text-yellow-400 transition-colors p-1 rounded-lg hover:bg-white/5" aria-label="Toggle sidebar" aria-expanded="false" aria-controls="desktopSidebar">
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
              <div class="text-center py-8 text-gray-500">
                <i data-lucide="bell-off" class="w-12 h-12 mx-auto mb-3 opacity-50"></i>
                <p>No notifications</p>
                <p class="text-sm mt-1">You're all caught up!</p>
              </div>
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
          <img src="<?php echo htmlspecialchars($_SESSION['profile_picture'] ?? 'https://i.pravatar.cc/120'); ?>" class="w-8 h-8 rounded-full border border-gray-600" id="userAvatar" />
          <span class="text-sm font-medium hidden md:inline" id="userName">
            <?php echo htmlspecialchars(isset($client['full_name']) ? $client['full_name'] : $_SESSION['username']); ?>
          </span>
          <i data-lucide="chevron-down" class="w-4 h-4"></i>
        </button>
        
        <!-- User Dropdown Menu -->
        <div id="userDropdown" class="dropdown user-dropdown hidden">
          <div class="p-4 border-b border-gray-700">
            <p class="text-white font-semibold text-sm"><?php echo htmlspecialchars(isset($client['full_name']) ? $client['full_name'] : $_SESSION['username']); ?></p>
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

  <div class="flex h-[calc(100vh-64px)]">
    <!-- Desktop Sidebar - Only for desktop -->
    <aside id="desktopSidebar" class="desktop-sidebar sidebar w-60 bg-[#0d0d0d] p-3 space-y-2 flex flex-col border-r border-gray-800" aria-label="Main navigation">
      <nav class="space-y-1 flex-1">
        <a href="client_dashboard.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="home"></i>
            <span class="text-sm font-medium">Dashboard</span>
          </div>
          <span class="tooltip">Dashboard</span>
        </a>

        <!-- Workout Plans Section -->
        <div>
          <div id="workoutToggle" class="sidebar-item">
            <div class="flex items-center">
              <i data-lucide="dumbbell"></i>
              <span class="text-sm font-medium">Workout Plans</span>
            </div>
            <i id="workoutChevron" data-lucide="chevron-right" class="chevron"></i>
            <span class="tooltip">Workout Plans</span>
          </div>
          <div id="workoutSubmenu" class="submenu space-y-1">
            <a href="workoutplansclient.php"><i data-lucide="list"></i> My Workout Plans</a>
            <a href="workoutprogress.php"><i data-lucide="activity"></i> Workout Progress</a>
          </div>
        </div>

        <!-- Nutrition Plans Section -->
        <div>
          <div id="nutritionToggle" class="sidebar-item">
            <div class="flex items-center">
              <i data-lucide="utensils"></i>
              <span class="text-sm font-medium">Nutrition Plans</span>
            </div>
            <i id="nutritionChevron" data-lucide="chevron-right" class="chevron"></i>
            <span class="tooltip">Nutrition Plans</span>
          </div>
          <div id="nutritionSubmenu" class="submenu space-y-1">
            <a href="nutritionplansclient.php"><i data-lucide="list"></i> My Meal Plans</a>
            <a href="nutritiontracking.php"><i data-lucide="chart-bar"></i> Nutrition Tracking</a>
          </div>
        </div>

        <!-- Progress Tracking -->
        <a href="myprogressclient.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="activity"></i>
            <span class="text-sm font-medium">My Progress</span>
          </div>
          <span class="tooltip">My Progress</span>
        </a>

        <!-- Attendance -->
        <a href="attendanceclient.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="calendar"></i>
            <span class="text-sm font-medium">Attendance</span>
          </div>
          <span class="tooltip">Attendance</span>
        </a>

        <!-- Membership -->
        <a href="membershipclient.php" class="sidebar-item active">
          <div class="flex items-center">
            <i data-lucide="id-card"></i>
            <span class="text-sm font-medium">Membership</span>
          </div>
          <span class="tooltip">Membership</span>
        </a>

        <!-- Feedbacks -->
        <a href="feedbacksclient.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="message-square"></i>
            <span class="text-sm font-medium">Send Feedback</span>
          </div>
          <span class="tooltip">Send Feedback</span>
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
  <main id="mainContent" class="main-content flex-1 p-4 space-y-6 overflow-auto">
    <!-- Error Message -->
    <?php if (isset($error_message) && !empty($error_message)): ?>
      <div class="bg-red-500/20 border border-red-500/30 rounded-lg p-4 mb-6">
        <div class="flex items-center gap-3">
          <i data-lucide="alert-circle" class="w-6 h-6 text-red-400"></i>
          <div>
            <h3 class="font-semibold text-red-400">Error!</h3>
            <p class="text-red-300 text-sm"><?php echo htmlspecialchars($error_message); ?></p>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Success Message -->
    <?php if (isset($renewal_success) && $renewal_success): ?>
      <div class="bg-green-500/20 border border-green-500/30 rounded-lg p-4 mb-6">
        <div class="flex items-center gap-3">
          <i data-lucide="check-circle" class="w-6 h-6 text-green-400"></i>
          <div>
            <h3 class="font-semibold text-green-400">Success!</h3>
            <p class="text-green-300 text-sm"><?php echo htmlspecialchars($success_message); ?></p>
            <?php if (!empty($success_details)): ?>
              <div class="mt-2 text-xs text-green-200">
                <p>Plan: <?php echo htmlspecialchars($success_details['plan_type']); ?></p>
                <p>Amount: ₱<?php echo number_format($success_details['amount']); ?></p>
                <p>Payment Method: <?php echo htmlspecialchars($success_details['payment_method']); ?></p>
                <p>Assigned Trainer: <?php echo htmlspecialchars($success_details['trainer']); ?></p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="flex justify-between items-center mb-6">
      <div class="flex items-center space-x-3">
        <a href="client_dashboard.php" class="text-gray-300 hover:text-yellow-400 transition-colors p-2 rounded-lg hover:bg-white/5">
          <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <h2 class="text-2xl font-bold text-yellow-400 flex items-center gap-3">
          <i data-lucide="id-card" class="w-8 h-8"></i>
          My Membership
        </h2>
      </div>
      <div class="text-right">
        <div class="text-sm text-gray-400">Member Since</div>
        <div class="font-semibold">
          <?php echo $membership && isset($membership['start_date']) ? date('F j, Y', strtotime($membership['start_date'])) : 'Not available'; ?>
        </div>
      </div>
    </div>

    <!-- Current Membership Status -->
    <div class="membership-card">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between">
        <div>
          <h3 class="text-2xl font-bold mb-4">Current Membership</h3>
          <div class="space-y-3">
            <div class="flex items-center gap-3">
              <span class="text-lg">Plan:</span>
              <span class="font-bold text-xl">
                <?php 
                if ($membership && isset($membership['membership_plan']) && isset($membership_plans[$membership['membership_plan']])) {
                  echo $membership_plans[$membership['membership_plan']]['name'];
                } else {
                  echo 'No active plan';
                }
                ?>
              </span>
            </div>
            <div class="flex items-center gap-3">
              <span class="text-lg">Status:</span>
              <span class="font-bold text-xl <?php echo $membership && $membership['status'] === 'active' ? 'text-green-300' : 'text-red-300'; ?>">
                <?php echo $membership ? ucfirst($membership['status']) : 'Inactive'; ?>
              </span>
            </div>
            <div class="flex items-center gap-3">
              <span class="text-lg">Expiry:</span>
              <span class="font-bold text-xl">
                <?php echo $membership && isset($membership['expiry_date']) ? date('F j, Y', strtotime($membership['expiry_date'])) : 'Not set'; ?>
              </span>
            </div>
            <?php if ($trainer_name): ?>
            <div class="flex items-center gap-3">
              <span class="text-lg">Trainer:</span>
              <span class="font-bold text-xl text-yellow-300"><?php echo htmlspecialchars($trainer_name); ?></span>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="mt-4 md:mt-0 text-center md:text-right">
          <div class="text-4xl font-bold mb-2 
            <?php 
            if ($membership && $membership['days_left'] > 7) echo 'text-green-300';
            elseif ($membership && $membership['days_left'] > 0) echo 'text-yellow-300';
            else echo 'text-red-300';
            ?>">
            <?php echo $membership && isset($membership['days_left']) ? ($membership['days_left'] > 0 ? $membership['days_left'] : 'Expired') : '0'; ?>
          </div>
          <div class="text-lg">Days <?php echo $membership && $membership['days_left'] > 0 ? 'Remaining' : 'Expired'; ?></div>
        </div>
      </div>
      
      <?php if ($membership && $membership['days_left'] <= 7 && $membership['days_left'] > 0): ?>
        <div class="warning-banner mt-4">
          <div class="flex items-center gap-2">
            <i data-lucide="alert-triangle" class="w-5 h-5 text-yellow-300"></i>
            <span class="font-semibold text-yellow-300">Your membership expires soon!</span>
          </div>
          <p class="text-yellow-200 mt-1">Request renewal now to continue uninterrupted access to all gym facilities.</p>
        </div>
      <?php elseif ($membership && $membership['days_left'] <= 0): ?>
        <div class="danger-banner mt-4">
          <div class="flex items-center gap-2">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-300"></i>
            <span class="font-semibold text-red-300">Your membership has expired!</span>
          </div>
          <p class="text-red-200 mt-1">Request renewal immediately to regain access to gym facilities.</p>
        </div>
      <?php endif; ?>
    </div>

    <!-- Pending Renewal Requests -->
    <?php if (!empty($pending_requests)): ?>
    <div class="card">
      <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
        <i data-lucide="clock" class="w-5 h-5"></i>
        Pending Renewal Requests
      </h3>
      
      <div class="space-y-4">
        <?php foreach ($pending_requests as $request): ?>
          <div class="bg-gray-800 rounded-lg p-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between">
              <div>
                <div class="flex items-center gap-3 mb-2">
                  <span class="font-semibold text-white">
                    <?php echo $membership_plans[$request['plan_type']]['name']; ?> Plan
                  </span>
                  <span class="request-status status-<?php echo $request['status']; ?>">
                    <?php echo ucfirst($request['status']); ?>
                  </span>
                </div>
                <div class="text-sm text-gray-400">
                  Amount: ₱<?php echo number_format($request['amount']); ?> • 
                  Payment: <?php echo ucfirst($request['payment_method']); ?> • 
                  Requested: <?php echo date('M j, Y g:i A', strtotime($request['created_at'])); ?>
                </div>
              </div>
              <div class="mt-2 md:mt-0">
                <?php if ($request['status'] === 'pending'): ?>
                  <span class="text-yellow-300 text-sm">Waiting for trainer approval</span>
                <?php elseif ($request['status'] === 'paid'): ?>
                  <span class="text-blue-300 text-sm">Payment verified - Processing</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Request Renewal Section -->
    <div class="card">
      <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
        <i data-lucide="credit-card" class="w-5 h-5"></i>
        Request Membership Renewal
      </h3>
      
      <?php if (!$trainer_id): ?>
        <div class="bg-red-500/20 border border-red-500/30 rounded-lg p-4">
          <div class="flex items-center gap-3">
            <i data-lucide="alert-triangle" class="w-6 h-6 text-red-400"></i>
            <div>
              <h3 class="font-semibold text-red-400">No Trainer Assigned</h3>
              <p class="text-red-300 text-sm">You don't have an assigned trainer. Please contact the gym administration to get assigned to a trainer.</p>
            </div>
          </div>
        </div>
      <?php else: ?>
      
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6" id="planSelection">
        <?php foreach ($membership_plans as $plan_key => $plan): ?>
          <div class="plan-card <?php echo $plan_key === 'monthly' ? 'popular' : ''; ?>" data-plan="<?php echo $plan_key; ?>">
            <?php if ($plan_key === 'monthly'): ?>
              <div class="bg-yellow-500 text-white text-sm font-bold px-3 py-1 rounded-full inline-block mb-4">
                Most Popular
              </div>
            <?php endif; ?>
            
            <h4 class="text-xl font-bold text-white mb-2"><?php echo htmlspecialchars($plan['name']); ?></h4>
            <div class="text-3xl font-bold text-yellow-400 mb-2">₱<?php echo number_format($plan['price']); ?></div>
            <div class="text-gray-300 mb-4"><?php echo htmlspecialchars($plan['duration']); ?></div>
            
            <button class="w-full bg-yellow-500 hover:bg-yellow-600 text-white py-2 px-4 rounded-lg transition-colors font-semibold flex items-center justify-center gap-2 min-h-[44px] select-plan-btn">
              <i data-lucide="shopping-cart" class="w-4 h-4"></i>
              Select Plan
            </button>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Renewal Request Form (Hidden by default) -->
      <div id="renewalForm" class="mt-6 p-6 bg-gray-800 rounded-lg hidden">
        <h4 class="text-lg font-bold text-yellow-400 mb-4">Submit Renewal Request</h4>
        <form id="requestForm" method="POST" enctype="multipart/form-data">
          <input type="hidden" name="request_renewal" value="1">
          <input type="hidden" name="plan_type" id="selectedPlan">
          
          <div class="mb-4">
            <label class="block text-gray-300 text-sm font-medium mb-2">Selected Plan</label>
            <div id="selectedPlanDisplay" class="p-3 bg-gray-700 rounded-lg text-white font-semibold"></div>
          </div>
          
          <div class="mb-4">
            <label class="block text-gray-300 text-sm font-medium mb-2">Payment Method</label>
            <select name="payment_method" id="paymentMethod" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white" required onchange="toggleGCashFields()">
              <option value="">Select Payment Method</option>
              <option value="cash">Cash (Pay at Counter)</option>
              <option value="gcash">GCash (Online Payment)</option>
            </select>
          </div>
          
          <!-- GCash Payment Fields (Hidden by default) -->
          <div id="gcashFields" class="hidden">
            <div class="gcash-info">
                <div class="flex items-center gap-2 mb-3">
                    <i data-lucide="smartphone" class="w-5 h-5 text-green-400"></i>
                    <h5 class="font-semibold text-green-400">GCash Payment Instructions</h5>
                </div>
                <p class="text-sm text-gray-300 mb-3">Send your payment to the following GCash number:</p>
                
                <div class="gcash-details">
                    <div class="text-sm text-gray-400">Send payment to:</div>
                    <div class="gcash-number">0917 123 4567</div>
                    <div class="text-sm text-gray-400 mb-3">(BOIYETS FITNESS GYM)</div>
                    <p class="text-sm text-gray-300">Your trainer will verify the payment once received.</p>
                </div>
            </div>
          </div>
          
          <div class="mb-4">
            <div class="bg-blue-500/20 border border-blue-500/30 rounded-lg p-3">
              <div class="flex items-center gap-2">
                <i data-lucide="info" class="w-4 h-4 text-blue-400"></i>
                <span class="text-blue-300 text-sm">Your renewal request will be sent to your trainer <?php echo htmlspecialchars($trainer_name); ?> for approval.</span>
              </div>
            </div>
          </div>
          
          <div class="flex space-x-3">
            <button type="button" id="cancelRequest" class="flex-1 bg-gray-600 text-white py-3 rounded-lg font-semibold hover:bg-gray-700 transition-colors">
              Cancel
            </button>
            <button type="submit" class="flex-1 bg-yellow-500 text-black py-3 rounded-lg font-semibold hover:bg-yellow-600 transition-colors">
              Submit Request
            </button>
          </div>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <!-- Membership History -->
    <div class="card">
      <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
        <i data-lucide="history" class="w-5 h-5"></i>
        Membership History
      </h3>
      
      <div class="space-y-4">
        <?php if ($membership && isset($membership['start_date'])): ?>
          <div class="flex flex-col md:flex-row md:items-center md:justify-between p-4 bg-gray-800 rounded-lg">
            <div>
              <div class="font-semibold text-white text-lg">
                <?php 
                if (isset($membership_plans[$membership['membership_plan']])) {
                  echo $membership_plans[$membership['membership_plan']]['name'] . ' Plan';
                } else {
                  echo 'Membership Plan';
                }
                ?>
              </div>
              <div class="text-gray-400">
                Started: <?php echo date('M j, Y', strtotime($membership['start_date'])); ?>
                <?php if (isset($membership['expiry_date'])): ?>
                  • Expires: <?php echo date('M j, Y', strtotime($membership['expiry_date'])); ?>
                <?php endif; ?>
              </div>
            </div>
            <div class="mt-2 md:mt-0">
              <span class="status-badge <?php echo $membership['status'] === 'active' ? 'status-active' : 'status-expired'; ?>">
                <?php echo ucfirst($membership['status']); ?>
              </span>
            </div>
          </div>
        <?php endif; ?>
        
        <!-- Additional history entries would go here -->
        <div class="text-center py-8 text-gray-500">
          <i data-lucide="receipt" class="w-16 h-16 mx-auto mb-4"></i>
          <p class="text-lg">Your membership history will appear here</p>
        </div>
      </div>
    </div>
  </main>
</div>

<!-- Mobile Bottom Navigation -->
<nav class="mobile-nav" aria-label="Mobile navigation">
  <a href="client_dashboard.php" class="mobile-nav-item">
    <i data-lucide="home"></i>
    <span class="mobile-nav-label">Dashboard</span>
  </a>
  <a href="workoutplansclient.php" class="mobile-nav-item">
    <i data-lucide="dumbbell"></i>
    <span class="mobile-nav-label">Workouts</span>
  </a>
  <a href="nutritionplansclient.php" class="mobile-nav-item">
    <i data-lucide="utensils"></i>
    <span class="mobile-nav-label">Nutrition</span>
  </a>
  <a href="membershipclient.php" class="mobile-nav-item active">
    <i data-lucide="id-card"></i>
    <span class="mobile-nav-label">Membership</span>
  </a>
  <button id="mobileMenuButton" class="mobile-nav-item">
    <i data-lucide="menu"></i>
    <span class="mobile-nav-label">Menu</span>
  </button>
</nav>

<!-- Mobile Sidebar - Only for mobile -->
<aside id="mobileSidebar" class="mobile-sidebar fixed top-0 left-0 w-full h-full bg-[#0d0d0d] p-6 space-y-2 overflow-y-auto z-50">
  <div class="flex justify-between items-center mb-8">
    <h2 class="text-xl font-bold text-yellow-400">BOIYETS FITNESS</h2>
    <button id="closeMobileSidebar" class="text-gray-400 hover:text-white p-2">
      <i data-lucide="x" class="w-6 h-6"></i>
    </button>
  </div>
  
  <nav class="space-y-1">
    <a href="client_dashboard.php" class="sidebar-item" onclick="closeMobileSidebar()">
      <div class="flex items-center">
        <i data-lucide="home"></i>
        <span class="text-sm font-medium">Dashboard</span>
      </div>
    </a>

    <!-- Workout Plans Section -->
    <div>
      <div id="mobileWorkoutToggle" class="sidebar-item">
        <div class="flex items-center">
          <i data-lucide="dumbbell"></i>
          <span class="text-sm font-medium">Workout Plans</span>
        </div>
        <i id="mobileWorkoutChevron" data-lucide="chevron-right" class="chevron"></i>
      </div>
      <div id="mobileWorkoutSubmenu" class="submenu space-y-1">
        <a href="workoutplansclient.php" onclick="closeMobileSidebar()"><i data-lucide="list"></i> My Workout Plans</a>
        <a href="workoutprogress.php" onclick="closeMobileSidebar()"><i data-lucide="activity"></i> Workout Progress</a>
      </div>
    </div>

    <!-- Nutrition Plans Section -->
    <div>
      <div id="mobileNutritionToggle" class="sidebar-item">
        <div class="flex items-center">
          <i data-lucide="utensils"></i>
          <span class="text-sm font-medium">Nutrition Plans</span>
        </div>
        <i id="mobileNutritionChevron" data-lucide="chevron-right" class="chevron"></i>
      </div>
      <div id="mobileNutritionSubmenu" class="submenu space-y-1">
        <a href="nutritionplansclient.php" onclick="closeMobileSidebar()"><i data-lucide="list"></i> My Meal Plans</a>
        <a href="nutritiontracking.php" onclick="closeMobileSidebar()"><i data-lucide="chart-bar"></i> Nutrition Tracking</a>
      </div>
    </div>

    <!-- Progress Tracking -->
    <a href="myprogressclient.php" class="sidebar-item" onclick="closeMobileSidebar()">
      <div class="flex items-center">
        <i data-lucide="activity"></i>
        <span class="text-sm font-medium">My Progress</span>
      </div>
    </a>

    <!-- Attendance -->
    <a href="attendanceclient.php" class="sidebar-item" onclick="closeMobileSidebar()">
      <div class="flex items-center">
        <i data-lucide="calendar"></i>
        <span class="text-sm font-medium">Attendance</span>
      </div>
    </a>

    <!-- Membership -->
    <a href="membershipclient.php" class="sidebar-item active" onclick="closeMobileSidebar()">
      <div class="flex items-center">
        <i data-lucide="id-card"></i>
        <span class="text-sm font-medium">Membership</span>
      </div>
    </a>

    <!-- Feedbacks -->
    <a href="feedbacksclient.php" class="sidebar-item" onclick="closeMobileSidebar()">
      <div class="flex items-center">
        <i data-lucide="message-square"></i>
        <span class="text-sm font-medium">Send Feedback</span>
      </div>
    </a>

    <div class="pt-8 border-t border-gray-800 mt-8">
      <a href="logout.php" class="sidebar-item" onclick="closeMobileSidebar()">
        <div class="flex items-center">
          <i data-lucide="log-out"></i>
          <span class="text-sm font-medium">Logout</span>
        </div>
      </a>
    </div>
  </nav>
</aside>

<noscript>
  <style>
    .mobile-sidebar { display: none; }
    .desktop-sidebar { display: block !important; }
    .mobile-nav { display: none !important; }
    .js-enabled { display: none; }
  </style>
</noscript>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    // Initialize icons
    lucide.createIcons();

    // Renewal functionality
    let selectedPlan = null;
    let selectedPlanName = null;
    let selectedPlanPrice = null;

    // Plan selection
    document.querySelectorAll('.select-plan-btn').forEach(button => {
      button.addEventListener('click', function() {
        const planCard = this.closest('.plan-card');
        const planType = planCard.dataset.plan;
        const planName = planCard.querySelector('h4').textContent;
        const planPrice = planCard.querySelector('.text-3xl').textContent;

        // Remove selected class from all cards
        document.querySelectorAll('.plan-card').forEach(card => {
          card.classList.remove('selected');
        });

        // Add selected class to clicked card
        planCard.classList.add('selected');

        // Store selected plan info
        selectedPlan = planType;
        selectedPlanName = planName;
        selectedPlanPrice = planPrice;

        // Show renewal form
        document.getElementById('selectedPlan').value = selectedPlan;
        document.getElementById('selectedPlanDisplay').textContent = `${selectedPlanName} - ${selectedPlanPrice}`;
        document.getElementById('renewalForm').classList.remove('hidden');

        // Reset GCash fields
        document.getElementById('paymentMethod').value = '';
        document.getElementById('gcashFields').classList.add('hidden');
        
        // Scroll to renewal form
        document.getElementById('renewalForm').scrollIntoView({ behavior: 'smooth' });
      });
    });

    // Cancel request
    document.getElementById('cancelRequest').addEventListener('click', function() {
      document.getElementById('renewalForm').classList.add('hidden');
      document.querySelectorAll('.plan-card').forEach(card => {
        card.classList.remove('selected');
      });
      selectedPlan = null;
    });

    // Form submission
    document.getElementById('requestForm').addEventListener('submit', function(e) {
        const paymentMethod = document.querySelector('select[name="payment_method"]').value;
        if (!paymentMethod) {
            e.preventDefault();
            alert('Please select a payment method.');
            return;
        }
        
        // Show loading state
        const submitBtn = this.querySelector('button[type="submit"]');
        submitBtn.innerHTML = '<i data-lucide="loader" class="w-5 h-5 animate-spin mx-auto"></i> Submitting...';
        submitBtn.disabled = true;
        lucide.createIcons();
    });

    // Mobile sidebar functionality
    const mobileSidebar = document.getElementById('mobileSidebar');
    const mobileOverlay = document.getElementById('mobileOverlay');
    const toggleSidebar = document.getElementById('toggleSidebar');
    const closeMobileSidebar = document.getElementById('closeMobileSidebar');
    const mobileMenuButton = document.getElementById('mobileMenuButton');
    const desktopSidebar = document.getElementById('desktopSidebar');

    function openMobileSidebar() {
      mobileSidebar.classList.add('open');
      mobileOverlay.classList.add('active');
      document.body.style.overflow = 'hidden';
    }

    function closeMobileSidebarFunc() {
      mobileSidebar.classList.remove('open');
      mobileOverlay.classList.remove('active');
      document.body.style.overflow = '';
    }

    // Smart toggle function that detects screen size
    function smartToggleSidebar() {
      if (window.innerWidth <= 768) {
        // Mobile: Toggle mobile sidebar
        if (mobileSidebar.classList.contains('open')) {
          closeMobileSidebarFunc();
        } else {
          openMobileSidebar();
        }
      } else {
        // Desktop: Toggle desktop sidebar
        if (desktopSidebar.classList.contains('w-60')) {
          desktopSidebar.classList.remove('w-60');
          desktopSidebar.classList.add('w-16', 'sidebar-collapsed');
        } else {
          desktopSidebar.classList.remove('w-16', 'sidebar-collapsed');
          desktopSidebar.classList.add('w-60');
        }
      }
    }

    // Event listeners
    if (toggleSidebar) {
      toggleSidebar.addEventListener('click', smartToggleSidebar);
    }
    
    if (mobileMenuButton) {
      mobileMenuButton.addEventListener('click', openMobileSidebar);
    }
    
    if (closeMobileSidebar) {
      closeMobileSidebar.addEventListener('click', closeMobileSidebarFunc);
    }
    
    if (mobileOverlay) {
      mobileOverlay.addEventListener('click', closeMobileSidebarFunc);
    }

    // Workout submenu toggle (Desktop)
    const workoutToggle = document.getElementById('workoutToggle');
    const workoutSubmenu = document.getElementById('workoutSubmenu');
    const workoutChevron = document.getElementById('workoutChevron');
    
    if (workoutToggle) {
      workoutToggle.addEventListener('click', () => {
        workoutSubmenu.classList.toggle('open');
        workoutChevron.classList.toggle('rotate');
      });
    }

    // Nutrition submenu toggle (Desktop)
    const nutritionToggle = document.getElementById('nutritionToggle');
    const nutritionSubmenu = document.getElementById('nutritionSubmenu');
    const nutritionChevron = document.getElementById('nutritionChevron');
    
    if (nutritionToggle) {
      nutritionToggle.addEventListener('click', () => {
        nutritionSubmenu.classList.toggle('open');
        nutritionChevron.classList.toggle('rotate');
      });
    }

    // Mobile submenu toggles
    const mobileWorkoutToggle = document.getElementById('mobileWorkoutToggle');
    const mobileWorkoutSubmenu = document.getElementById('mobileWorkoutSubmenu');
    const mobileWorkoutChevron = document.getElementById('mobileWorkoutChevron');
    
    if (mobileWorkoutToggle) {
      mobileWorkoutToggle.addEventListener('click', () => {
        mobileWorkoutSubmenu.classList.toggle('open');
        mobileWorkoutChevron.classList.toggle('rotate');
      });
    }

    const mobileNutritionToggle = document.getElementById('mobileNutritionToggle');
    const mobileNutritionSubmenu = document.getElementById('mobileNutritionSubmenu');
    const mobileNutritionChevron = document.getElementById('mobileNutritionChevron');
    
    if (mobileNutritionToggle) {
      mobileNutritionToggle.addEventListener('click', () => {
        mobileNutritionSubmenu.classList.toggle('open');
        mobileNutritionChevron.classList.toggle('rotate');
      });
    }

    // Dropdown functionality
    const notificationBell = document.getElementById('notificationBell');
    const notificationDropdown = document.getElementById('notificationDropdown');
    const userMenuButton = document.getElementById('userMenuButton');
    const userDropdown = document.getElementById('userDropdown');

    if (notificationBell && notificationDropdown) {
      notificationBell.addEventListener('click', (e) => {
        e.stopPropagation();
        notificationDropdown.classList.toggle('hidden');
        userDropdown.classList.add('hidden');
      });
    }

    if (userMenuButton && userDropdown) {
      userMenuButton.addEventListener('click', (e) => {
        e.stopPropagation();
        userDropdown.classList.toggle('hidden');
        notificationDropdown.classList.add('hidden');
      });
    }

    // Close dropdowns when clicking outside
    document.addEventListener('click', () => {
      if (notificationDropdown) notificationDropdown.classList.add('hidden');
      if (userDropdown) userDropdown.classList.add('hidden');
    });

    // Prevent dropdowns from closing when clicking inside them
    [notificationDropdown, userDropdown].forEach(dropdown => {
      if (dropdown) {
        dropdown.addEventListener('click', (e) => {
          e.stopPropagation();
        });
      }
    });

    // Hover to open sidebar (for desktop collapsed state) - Only on desktop
    if (desktopSidebar && window.innerWidth > 768) {
      desktopSidebar.addEventListener('mouseenter', () => {
        if (desktopSidebar.classList.contains('sidebar-collapsed')) {
          desktopSidebar.classList.remove('w-16', 'sidebar-collapsed');
          desktopSidebar.classList.add('w-60');
        }
      });
      
      desktopSidebar.addEventListener('mouseleave', () => {
        if (!desktopSidebar.classList.contains('sidebar-collapsed')) {
          desktopSidebar.classList.remove('w-60');
          desktopSidebar.classList.add('w-16', 'sidebar-collapsed');
        }
      });
    }

    // Update mobile nav active state
    updateMobileNavActive();
  });

  // Update mobile navigation active state
  function updateMobileNavActive() {
    const currentPage = window.location.pathname.split('/').pop();
    const mobileNavItems = document.querySelectorAll('.mobile-nav-item');
    
    mobileNavItems.forEach(item => {
      item.classList.remove('active');
      // Simple page matching logic
      if (item.getAttribute('href') === currentPage) {
        item.classList.add('active');
      }
    });
  }

  // Global function to close mobile sidebar (used in onclick events)
  function closeMobileSidebar() {
    const mobileSidebar = document.getElementById('mobileSidebar');
    const mobileOverlay = document.getElementById('mobileOverlay');
    
    if (mobileSidebar) {
      mobileSidebar.classList.remove('open');
    }
    if (mobileOverlay) {
      mobileOverlay.classList.remove('active');
    }
    document.body.style.overflow = '';
  }

  function toggleGCashFields() {
    const paymentMethod = document.getElementById('paymentMethod').value;
    const gcashFields = document.getElementById('gcashFields');
    
    if (paymentMethod === 'gcash') {
      gcashFields.classList.remove('hidden');
    } else {
      gcashFields.classList.add('hidden');
    }
  }

  // Handle window resize
  window.addEventListener('resize', function() {
    const mobileSidebar = document.getElementById('mobileSidebar');
    const mobileOverlay = document.getElementById('mobileOverlay');
    
    // Close mobile sidebar when resizing to desktop
    if (window.innerWidth > 768 && mobileSidebar && mobileSidebar.classList.contains('open')) {
      closeMobileSidebar();
    }

    // Update mobile nav on resize
    updateMobileNavActive();
  });

  // AJAX function to process renewal directly (for instant renewals)
  function processInstantRenewal(memberId, planType, paymentMethod) {
    const formData = new FormData();
    formData.append('member_id', memberId);
    formData.append('plan_type', planType);
    formData.append('payment_method', paymentMethod);
    
    return fetch('renew_membership.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            return data;
        } else {
            throw new Error(data.message);
        }
    });
  }
</script>
</body>
</html>
<?php $conn->close(); ?>



