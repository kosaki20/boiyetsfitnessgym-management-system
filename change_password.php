<?php
session_start();
if (!isset($_SESSION['user_id'])) {
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
// Get user data
$user_id = $_SESSION['user_id'];
$sql = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // Validate inputs
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $message = "All fields are required!";
        $message_type = "error";
    } elseif ($new_password !== $confirm_password) {
        $message = "New passwords do not match!";
        $message_type = "error";
    } elseif (strlen($new_password) < 6) {
        $message = "New password must be at least 6 characters long!";
        $message_type = "error";
    } else {
        // Verify current password
        $current_password_md5 = md5($current_password);
        if ($current_password_md5 !== $user['password']) {
            $message = "Current password is incorrect!";
            $message_type = "error";
        } else {
            // Update password
            $new_password_md5 = md5($new_password);
            $update_sql = "UPDATE users SET password = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("si", $new_password_md5, $user_id);

            if ($update_stmt->execute()) {
                $message = "Password changed successfully!";
                $message_type = "success";
                
                // Clear form fields
                $_POST = array();
            } else {
                $message = "Error changing password. Please try again.";
                $message_type = "error";
            }
        }
    }
}

// Include chat functionality
require_once 'chat_functions.php';
$unread_count = getUnreadCount($_SESSION['user_id'], $conn);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - BOIYETS FITNESS GYM</title>
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
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #fbbf24;
        }
        
        .form-input {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            color: #fff;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #fbbf24;
            box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.1);
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
        
        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            color: #e2e8f0;
        }
        
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        .password-strength {
            margin-top: 0.5rem;
            font-size: 0.8rem;
        }
        
        .strength-weak { color: #ef4444; }
        .strength-medium { color: #f59e0b; }
        .strength-strong { color: #10b981; }
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
          <img src="https://i.pravatar.cc/40" class="w-8 h-8 rounded-full border border-gray-600" id="userAvatar" />
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
            <a href="change_password.php" class="flex items-center gap-2 px-3 py-2 text-sm text-yellow-400 hover:bg-white/5 rounded-lg transition-colors">
              <i data-lucide="key" class="w-4 h-4"></i>
              Change Password
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
    <?php if ($_SESSION['role'] == 'admin'): ?>
    <!-- Admin Sidebar (same as profile.php) -->
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
            <span class="text-sm font-medium">Revenue & Members</span>
          </div>
          <span class="tooltip">Revenue & Members</span>
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
    <?php elseif ($_SESSION['role'] == 'trainer'): ?>
    <!-- Trainer Sidebar (same as profile.php) -->
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
            <a href="walkin_registration.php"><i data-lucide="user"></i> Walk-in Registration</a>
            <a href="client_registration.php"><i data-lucide="user-plus"></i> Client Registration</a>
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
    <?php else: ?>
    <!-- Client Sidebar -->
    <aside id="sidebar" class="sidebar w-60 bg-[#0d0d0d] h-screen p-3 space-y-2 flex flex-col overflow-y-auto border-r border-gray-800">
      <nav class="space-y-1 flex-1">
        <a href="client_dashboard.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="home"></i>
            <span class="text-sm font-medium">Dashboard</span>
          </div>
          <span class="tooltip">Dashboard</span>
        </a>

        <a href="my_workouts.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="dumbbell"></i>
            <span class="text-sm font-medium">My Workouts</span>
          </div>
          <span class="tooltip">My Workouts</span>
        </a>

        <a href="my_meal_plans.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="utensils"></i>
            <span class="text-sm font-medium">My Meal Plans</span>
          </div>
          <span class="tooltip">My Meal Plans</span>
        </a>

        <a href="my_progress.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="activity"></i>
            <span class="text-sm font-medium">My Progress</span>
          </div>
          <span class="tooltip">My Progress</span>
        </a>

        <a href="my_attendance.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="calendar"></i>
            <span class="text-sm font-medium">My Attendance</span>
          </div>
          <span class="tooltip">My Attendance</span>
        </a>

        <a href="profile.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="user"></i>
            <span class="text-sm font-medium">My Profile</span>
          </div>
          <span class="tooltip">My Profile</span>
        </a>

        <a href="change_password.php" class="sidebar-item active">
          <div class="flex items-center">
            <i data-lucide="key"></i>
            <span class="text-sm font-medium">Change Password</span>
          </div>
          <span class="tooltip">Change Password</span>
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
    <?php endif; ?>

    <!-- Main Content -->
    <main class="flex-1 p-6 overflow-auto">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-yellow-400 flex items-center gap-2">
                <i data-lucide="key"></i>
                Change Password
            </h1>
            <a href="profile.php" class="btn btn-secondary">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>
                Back to Profile
            </a>
        </div>

        <div class="card max-w-2xl">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <i data-lucide="<?php echo $message_type == 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-5 h-5"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label" for="current_password">
                        <i data-lucide="lock" class="w-4 h-4 inline mr-2"></i>
                        Current Password
                    </label>
                    <input type="password" id="current_password" name="current_password" class="form-input" 
                           value="<?php echo htmlspecialchars($_POST['current_password'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="new_password">
                        <i data-lucide="lock" class="w-4 h-4 inline mr-2"></i>
                        New Password
                    </label>
                    <input type="password" id="new_password" name="new_password" class="form-input" 
                           value="<?php echo htmlspecialchars($_POST['new_password'] ?? ''); ?>" required>
                    <div id="passwordStrength" class="password-strength"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="confirm_password">
                        <i data-lucide="lock" class="w-4 h-4 inline mr-2"></i>
                        Confirm New Password
                    </label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-input" 
                           value="<?php echo htmlspecialchars($_POST['confirm_password'] ?? ''); ?>" required>
                    <div id="passwordMatch" class="password-strength"></div>
                </div>

                <div class="bg-yellow-400/10 border border-yellow-400/30 rounded-lg p-4 mb-6">
                    <div class="flex items-start gap-3">
                        <i data-lucide="shield" class="w-5 h-5 text-yellow-400 mt-0.5 flex-shrink-0"></i>
                        <div>
                            <h3 class="text-yellow-400 font-semibold mb-2">Password Requirements</h3>
                            <ul class="text-sm text-gray-300 space-y-1">
                                <li>• At least 6 characters long</li>
                                <li>• Should not be the same as your current password</li>
                                <li>• Use a combination of letters, numbers, and symbols for better security</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="flex gap-4 mt-8 pt-6 border-t border-gray-700">
                    <button type="submit" class="btn btn-primary">
                        <i data-lucide="key" class="w-4 h-4"></i>
                        Change Password
                    </button>
                    <a href="profile.php" class="btn btn-secondary">
                        <i data-lucide="x" class="w-4 h-4"></i>
                        Cancel
                    </a>
                </div>
            </form>
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

        // Members submenu toggle (for trainers)
        const membersToggle = document.getElementById('membersToggle');
        const membersSubmenu = document.getElementById('membersSubmenu');
        const membersChevron = document.getElementById('membersChevron');
        
        if (membersToggle) {
            membersToggle.addEventListener('click', () => {
                membersSubmenu.classList.toggle('open');
                membersChevron.classList.toggle('rotate');
            });
        }

        // Plans submenu toggle (for trainers)
        const plansToggle = document.getElementById('plansToggle');
        const plansSubmenu = document.getElementById('plansSubmenu');
        const plansChevron = document.getElementById('plansChevron');
        
        if (plansToggle) {
            plansToggle.addEventListener('click', () => {
                plansSubmenu.classList.toggle('open');
                plansChevron.classList.toggle('rotate');
            });
        }
        
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

        // Enhanced dropdown functionality
        function setupDropdowns() {
            const userMenuButton = document.getElementById('userMenuButton');
            const userDropdown = document.getElementById('userDropdown');
            
            // Close all dropdowns
            function closeAllDropdowns() {
                if (userDropdown) userDropdown.classList.add('hidden');
            }
            
            // Toggle user dropdown
            if (userMenuButton) {
                userMenuButton.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const isHidden = userDropdown.classList.contains('hidden');
                    
                    closeAllDropdowns();
                    
                    if (isHidden) {
                        userDropdown.classList.remove('hidden');
                    }
                });
            }
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function(e) {
                if ((!userDropdown || !userDropdown.contains(e.target)) && (!userMenuButton || !userMenuButton.contains(e.target))) {
                    closeAllDropdowns();
                }
            });
            
            // Close dropdowns when pressing Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeAllDropdowns();
                }
            });
        }

        // Password strength checker
        function checkPasswordStrength(password) {
            let strength = 0;
            const strengthText = document.getElementById('passwordStrength');
            
            if (password.length >= 6) strength++;
            if (password.match(/[a-z]+/)) strength++;
            if (password.match(/[A-Z]+/)) strength++;
            if (password.match(/[0-9]+/)) strength++;
            if (password.match(/[!@#$%^&*(),.?":{}|<>]+/)) strength++;
            
            let text = '';
            let className = '';
            
            switch(strength) {
                case 0:
                case 1:
                    text = 'Weak password';
                    className = 'strength-weak';
                    break;
                case 2:
                case 3:
                    text = 'Medium password';
                    className = 'strength-medium';
                    break;
                case 4:
                case 5:
                    text = 'Strong password';
                    className = 'strength-strong';
                    break;
            }
            
            strengthText.textContent = text;
            strengthText.className = 'password-strength ' + className;
        }

        // Password match checker
        function checkPasswordMatch() {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const matchText = document.getElementById('passwordMatch');
            
            if (confirmPassword === '') {
                matchText.textContent = '';
                return;
            }
            
            if (password === confirmPassword) {
                matchText.textContent = 'Passwords match';
                matchText.className = 'password-strength strength-strong';
            } else {
                matchText.textContent = 'Passwords do not match';
                matchText.className = 'password-strength strength-weak';
            }
        }

        // Add event listeners for password validation
        document.getElementById('new_password').addEventListener('input', function() {
            checkPasswordStrength(this.value);
            checkPasswordMatch();
        });
        
        document.getElementById('confirm_password').addEventListener('input', checkPasswordMatch);

        setupDropdowns();
    });
  </script>
</body>
</html>



