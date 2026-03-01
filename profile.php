<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';
// Get user data
$user_id = $_SESSION['user_id'];
$sql = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// ADD THIS SECTION FOR CHAT FUNCTIONALITY
require_once 'chat_functions.php';
$unread_count = getUnreadCount($_SESSION['user_id'], $conn);

// Initialize notification variables
$notification_count = 0;
$notifications = [];

// Include notification functions if trainer or admin
if (in_array($_SESSION['role'], ['trainer', 'admin'])) {
    require_once 'notification_functions.php';
    $notification_count = getUnreadNotificationCount($conn, $user_id);
    
    if ($_SESSION['role'] == 'trainer') {
        $notifications = getTrainerNotifications($conn, $user_id);
    } else {
        $notifications = getAdminNotifications($conn);
    }
}

$conn->close();
?>

<?php
$role = $_SESSION['role'];
if ($role == 'admin') {
    $page_title = 'My Profile';
    require_once 'includes/admin_header.php';
} elseif ($role == 'trainer') {
    $page_title = 'My Profile';
    require_once 'includes/trainer_header.php';
} else {
    $page_title = 'My Profile';
    require_once 'includes/client_header.php';
}
?>
<style>
    .profile-card {
        background: rgba(26, 26, 26, 0.7);
        border-radius: 16px;
        padding: 2rem;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.05);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.5rem;
        margin-top: 2rem;
    }

    .info-item {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 12px;
        padding: 1.5rem;
        border-left: 4px solid #fbbf24;
    }

    .info-label {
        font-size: 0.875rem;
        color: #9ca3af;
        margin-bottom: 0.5rem;
        font-weight: 500;
    }

    .info-value {
        font-size: 1.125rem;
        color: #f8fafc;
        font-weight: 600;
    }

    .role-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        border-radius: 20px;
        font-size: 0.875rem;
        font-weight: 500;
    }

    .role-admin {
        background: rgba(239, 68, 68, 0.2);
        color: #ef4444;
    }

    .role-trainer {
        background: rgba(59, 130, 246, 0.2);
        color: #3b82f6;
    }

    .role-client {
        background: rgba(16, 185, 129, 0.2);
        color: #10b981;
    }
</style>
    </style>
</head>
<body class="min-h-screen">

<?php
if ($role == 'admin') {
    require_once 'includes/admin_sidebar.php';
} elseif ($role == 'trainer') {
    require_once 'includes/trainer_sidebar.php';
}
// Client sidebar is already included in client_header.php
?>

    <!-- Main Content -->
    <main class="flex-1 p-6 overflow-auto">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-yellow-400 flex items-center gap-2">
                <i data-lucide="user"></i>
                My Profile
            </h1>
        </div>

        <div class="profile-card">
            <!-- Profile Header -->
            <div class="flex items-center gap-6 mb-8">
                <div class="relative">
                    <img src="https://i.pravatar.cc/120" class="w-24 h-24 rounded-full border-4 border-yellow-400/50">
                    <div class="absolute -bottom-2 -right-2">
                        <span class="role-badge role-<?php echo $_SESSION['role']; ?>">
                            <i data-lucide="<?php echo $_SESSION['role'] == 'admin' ? 'shield' : ($_SESSION['role'] == 'trainer' ? 'dumbbell' : 'user'); ?>" class="w-4 h-4"></i>
                            <?php echo ucfirst($_SESSION['role']); ?>
                        </span>
                    </div>
                </div>
                <div>
                    <h2 class="text-2xl font-bold text-white"><?php echo htmlspecialchars($user['full_name']); ?></h2>
                    <p class="text-gray-400">Welcome to your profile dashboard</p>
                </div>
            </div>

            <!-- Profile Information Grid -->
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">
                        <i data-lucide="user" class="w-4 h-4 inline mr-2"></i>
                        Username
                    </div>
                    <div class="info-value"><?php echo htmlspecialchars($user['username']); ?></div>
                </div>

                <div class="info-item">
                    <div class="info-label">
                        <i data-lucide="mail" class="w-4 h-4 inline mr-2"></i>
                        Email Address
                    </div>
                    <div class="info-value"><?php echo htmlspecialchars($user['email']); ?></div>
                </div>

                <div class="info-item">
                    <div class="info-label">
                        <i data-lucide="shield" class="w-4 h-4 inline mr-2"></i>
                        Account Role
                    </div>
                    <div class="info-value">
                        <span class="role-badge role-<?php echo $_SESSION['role']; ?>">
                            <?php echo ucfirst($_SESSION['role']); ?>
                        </span>
                    </div>
                </div>

                <div class="info-item">
                    <div class="info-label">
                        <i data-lucide="calendar" class="w-4 h-4 inline mr-2"></i>
                        Member Since
                    </div>
                    <div class="info-value"><?php echo date('F j, Y', strtotime($user['created_at'])); ?></div>
                </div>

                <?php if ($user['client_type']): ?>
                <div class="info-item">
                    <div class="info-label">
                        <i data-lucide="users" class="w-4 h-4 inline mr-2"></i>
                        Client Type
                    </div>
                    <div class="info-value capitalize"><?php echo str_replace('-', ' ', $user['client_type']); ?></div>
                </div>
                <?php endif; ?>

                <div class="info-item">
                    <div class="info-label">
                        <i data-lucide="clock" class="w-4 h-4 inline mr-2"></i>
                        Last Login
                    </div>
                    <div class="info-value"><?php echo $user['last_activity'] ? date('F j, Y g:i A', strtotime($user['last_activity'])) : 'Never'; ?></div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex gap-4 mt-8 pt-6 border-t border-gray-700">
                <a href="edit_profile.php" class="btn btn-primary">
                    <i data-lucide="edit-2" class="w-4 h-4"></i>
                    Edit Profile
                </a>
                <a href="change_password.php" class="btn btn-secondary">
                    <i data-lucide="key" class="w-4 h-4"></i>
                    Change Password
                </a>
            </div>
        </div>
    </main>
  </div>

<?php
if ($role == 'admin') {
    require_once 'includes/admin_footer.php';
} elseif ($role == 'trainer') {
    require_once 'includes/trainer_footer.php';
} else {
    require_once 'includes/client_footer.php';
}
?>




