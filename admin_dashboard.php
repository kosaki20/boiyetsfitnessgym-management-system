<?php
session_start();

if (!isset($_SESSION['username']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';
// Include Admin Dashboard Functions
require_once 'includes/admin_functions.php';

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

// Chat functionality
require_once 'chat_functions.php';
$unread_count = getUnreadCount($_SESSION['user_id'], $conn);

// Notification functions
require_once 'notification_functions.php';
$notification_count = getUnreadNotificationCount($conn, $user_id);
$notifications = getAdminNotifications($conn);

// Fetch Dashboard Data
$maintenance_stats = getMaintenanceStats($conn);
$total_maintenance_issues = $maintenance_stats['total_issues'];

$recent_maintenance_result = getRecentMaintenance($conn);

$dashboard_stats = getDashboardStats($conn);
$total_users = $dashboard_stats['total_users'];
$total_products = $dashboard_stats['total_products'];
$total_feedback = $dashboard_stats['total_feedback'];
$revenue_today = $dashboard_stats['revenue_today'];
$total_members = $dashboard_stats['total_members'];
$attendance_today = $dashboard_stats['attendance_today'];
$expiring_members = $dashboard_stats['expiring_members'];

// Handle Trainer Creation
$user_message = '';
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_user'])) {
    $user_message = handleTrainerCreation($conn, $_POST);
}

$conn->close();
?>

<?php require_once 'includes/admin_header.php'; ?>
<?php require_once 'includes/admin_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 p-4 space-y-4 overflow-auto">
      <!-- Header -->
      <div class="flex justify-between items-center mb-4">
        <h1 class="text-xl font-bold text-yellow-400 flex items-center gap-2">
          <i data-lucide="home"></i>
          Admin Dashboard
        </h1>
        <div class="text-sm text-gray-400">
          Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?>
          
        </div>
      </div>

      <?php if (!empty($user_message)): ?>
        <div class="alert alert-<?php echo strpos($user_message, 'Error') !== false ? 'error' : 'success'; ?>">
          <i data-lucide="<?php echo strpos($user_message, 'Error') !== false ? 'alert-circle' : 'check-circle'; ?>" class="w-5 h-5 mr-2"></i>
          <?php echo htmlspecialchars($user_message); ?>
        </div>
      <?php endif; ?>

      <!-- Statistics Grid - Compact -->
      <div class="compact-grid mb-4">
        <div class="card compact-card">
          <p class="compact-title"><i data-lucide="users"></i><span>Total Members</span></p>
          <p class="compact-value"><?php echo $total_members; ?></p>
          <p class="text-xs text-green-400 mt-1 flex items-center"><i data-lucide="trending-up" class="w-3 h-3 mr-1"></i>Active members</p>
        </div>
        <div class="card compact-card">
          <p class="compact-title"><i data-lucide="user-check"></i><span>Today's Attendance</span></p>
          <p class="compact-value"><?php echo $attendance_today; ?></p>
          <p class="text-xs text-blue-400 mt-1 flex items-center"><i data-lucide="calendar" class="w-3 h-3 mr-1"></i><?php echo date('M j'); ?></p>
        </div>
        <div class="card compact-card">
          <p class="compact-title"><i data-lucide="dollar-sign"></i><span>Today's Revenue</span></p>
          <p class="compact-value">₱<?php echo number_format($revenue_today, 2); ?></p>
          <p class="text-xs text-purple-400 mt-1 flex items-center"><i data-lucide="shopping-cart" class="w-3 h-3 mr-1"></i>Sales</p>
        </div>
        <div class="card compact-card border-l-4 border-red-500">
          <p class="compact-title"><i data-lucide="alert-triangle"></i><span>Expiring Soon</span></p>
          <p class="compact-value"><?php echo $expiring_members; ?></p>
          <p class="text-xs text-red-400 mt-1 flex items-center"><i data-lucide="clock" class="w-3 h-3 mr-1"></i>Within 3 days</p>
        </div>
      </div>

      <!-- Main Content Area -->
      <div class="grid grid-cols-1 xl:grid-cols-5 gap-4">
        <!-- Left Column - Charts and Management (3/5 width) -->
        <div class="xl:col-span-3 space-y-4">
          <!-- Maintenance Alerts -->
          <div class="card">
            <h2 class="text-lg font-semibold text-yellow-400 mb-3 flex items-center gap-2">
              <i data-lucide="alert-triangle"></i>
              Maintenance Alerts
            </h2>
            
            <div class="grid grid-cols-3 gap-2 mb-3">
              <div class="text-center p-2 rounded-lg border border-red-500/30 bg-red-500/10">
                <div class="text-lg font-bold text-red-400"><?php echo $maintenance_stats['broken'] ?? 0; ?></div>
                <div class="text-xs text-red-300">Broken</div>
              </div>
              <div class="text-center p-2 rounded-lg border border-orange-500/30 bg-orange-500/10">
                <div class="text-lg font-bold text-orange-400"><?php echo $maintenance_stats['under_repair'] ?? 0; ?></div>
                <div class="text-xs text-orange-300">Repairing</div>
              </div>
              <div class="text-center p-2 rounded-lg border border-yellow-500/30 bg-yellow-500/10">
                <div class="text-lg font-bold text-yellow-400"><?php echo $maintenance_stats['needs_maintenance'] ?? 0; ?></div>
                <div class="text-xs text-yellow-300">Maintenance</div>
              </div>
            </div>

            <?php if ($recent_maintenance_result && $recent_maintenance_result->num_rows > 0): ?>
              <div class="space-y-2">
                <h3 class="text-sm font-semibold text-gray-300">Recent Maintenance Issues</h3>
                <?php while($item = $recent_maintenance_result->fetch_assoc()): ?>
                  <div class="flex items-center justify-between p-2 bg-gray-800/50 rounded-lg">
                    <div class="flex items-center gap-2">
                      <div class="w-2 h-2 rounded-full 
                        <?php echo $item['status'] == 'Broken' ? 'bg-red-500' : 
                               ($item['status'] == 'Under Repair' ? 'bg-orange-500' : 'bg-yellow-500'); ?>">
                      </div>
                      <span class="text-sm text-white"><?php echo htmlspecialchars($item['name']); ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                      <span class="text-xs px-2 py-1 rounded-full 
                        <?php echo $item['status'] == 'Broken' ? 'bg-red-500/20 text-red-400' : 
                               ($item['status'] == 'Under Repair' ? 'bg-orange-500/20 text-orange-400' : 'bg-yellow-500/20 text-yellow-400'); ?>">
                        <?php echo htmlspecialchars($item['status']); ?>
                      </span>
                      <span class="text-xs text-gray-400">
                        <?php echo date('M j', strtotime($item['last_updated'])); ?>
                      </span>
                    </div>
                  </div>
                <?php endwhile; ?>
              </div>
              <div class="mt-3">
                <a href="maintenance_report.php" class="button-sm btn-primary w-full justify-center text-sm">
                  <i data-lucide="clipboard-list"></i> View Full Maintenance Report
                </a>
              </div>
            <?php else: ?>
              <div class="text-center py-4 text-gray-500">
                <i data-lucide="check-circle" class="w-8 h-8 mx-auto text-green-400 mb-2"></i>
                <p class="text-sm">No maintenance issues!</p>
                <p class="text-xs">All equipment is operational</p>
              </div>
            <?php endif; ?>
          </div>

          <!-- Trainer Management Section -->
          <div class="card">
            <div class="flex justify-between items-center mb-3">
              <h2 class="text-lg font-semibold text-yellow-400 flex items-center gap-2">
                <i data-lucide="user-plus"></i>
                Trainer Management
              </h2>
              <button id="toggleUserForm" class="button-sm bg-yellow-600 hover:bg-yellow-700 text-white text-sm">
                <i data-lucide="eye"></i> Hide Form
              </button>
            </div>
            
            <div id="userForm">
              <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
                <div>
                  <label class="form-label text-sm">Username</label>
                  <input type="text" name="new_username" class="form-input text-sm" required>
                </div>
                <div>
                  <label class="form-label text-sm">Password</label>
                  <input type="password" name="new_password" class="form-input text-sm" required>
                </div>
                <div>
                  <label class="form-label text-sm">Full Name</label>
                  <input type="text" name="full_name" class="form-input text-sm" required>
                </div>
                <div>
                  <label class="form-label text-sm">Email</label>
                  <input type="email" name="email" class="form-input text-sm" required>
                </div>
                <div class="md:col-span-2">
                  <p class="text-xs text-gray-400 mt-1">Only trainer accounts can be created</p>
                </div>
                <div class="md:col-span-2">
                  <button type="submit" name="create_user" class="w-full bg-green-600 hover:bg-green-700 transition text-white rounded-lg button-sm justify-center flex items-center gap-2 text-sm">
                    <i data-lucide="user-plus"></i> Create Trainer Account
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- Right Column - Quick Actions and Additional Info (2/5 width) -->
        <div class="xl:col-span-2 space-y-4">
          <!-- Quick Actions -->
          <div class="card">
            <h2 class="text-lg font-semibold text-yellow-400 mb-3 flex items-center gap-2">
              <i data-lucide="zap"></i>
              Quick Actions
            </h2>
            
            <div class="grid grid-cols-2 gap-2">
              <a href="products.php?action=add" class="flex flex-col items-center p-3 bg-yellow-500/10 border border-yellow-500/30 rounded-lg hover:bg-yellow-500/20 transition-colors">
                <i data-lucide="package-plus" class="w-5 h-5 text-yellow-400 mb-1"></i>
                <span class="text-xs text-white text-center">Add Product</span>
              </a>
              
              <a href="adminannouncement.php" class="flex flex-col items-center p-3 bg-yellow-500/10 border border-yellow-500/30 rounded-lg hover:bg-yellow-500/20 transition-colors">
                <i data-lucide="megaphone" class="w-5 h-5 text-yellow-400 mb-1"></i>
                <span class="text-xs text-white text-center">New Announcement</span>
              </a>
              
              <a href="equipment_monitoring.php" class="flex flex-col items-center p-3 bg-yellow-500/10 border border-yellow-500/30 rounded-lg hover:bg-yellow-500/20 transition-colors">
                <i data-lucide="wrench" class="w-5 h-5 text-yellow-400 mb-1"></i>
                <span class="text-xs text-white text-center">Check Equipment</span>
              </a>
              
              <a href="revenue.php" class="flex flex-col items-center p-3 bg-yellow-500/10 border border-yellow-500/30 rounded-lg hover:bg-yellow-500/20 transition-colors">
                <i data-lucide="dollar-sign" class="w-5 h-5 text-yellow-400 mb-1"></i>
                <span class="text-xs text-white text-center">View Revenue</span>
              </a>
            </div>
          </div>

          <!-- Additional Stats -->
          <div class="card">
            <h2 class="text-lg font-semibold text-yellow-400 mb-3 flex items-center gap-2">
              <i data-lucide="bar-chart-3"></i>
              System Overview
            </h2>
            
            <div class="space-y-3">
              <div class="flex items-center justify-between p-2 bg-gray-800/50 rounded-lg">
                <div class="flex items-center gap-2">
                  <i data-lucide="users" class="w-4 h-4 text-blue-400"></i>
                  <span class="text-sm text-white">Total Users</span>
                </div>
                <span class="text-sm font-semibold text-blue-400"><?php echo $total_users; ?></span>
              </div>
              
              <div class="flex items-center justify-between p-2 bg-gray-800/50 rounded-lg">
                <div class="flex items-center gap-2">
                  <i data-lucide="package" class="w-4 h-4 text-green-400"></i>
                  <span class="text-sm text-white">Total Products</span>
                </div>
                <span class="text-sm font-semibold text-green-400"><?php echo $total_products; ?></span>
              </div>
              
              <div class="flex items-center justify-between p-2 bg-gray-800/50 rounded-lg">
                <div class="flex items-center gap-2">
                  <i data-lucide="message-square" class="w-4 h-4 text-purple-400"></i>
                  <span class="text-sm text-white">Total Feedback</span>
                </div>
                <span class="text-sm font-semibold text-purple-400"><?php echo $total_feedback; ?></span>
              </div>
              
              <div class="flex items-center justify-between p-2 bg-gray-800/50 rounded-lg">
                <div class="flex items-center gap-2">
                  <i data-lucide="alert-triangle" class="w-4 h-4 text-red-400"></i>
                  <span class="text-sm text-white">Maintenance Issues</span>
                </div>
                <span class="text-sm font-semibold text-red-400"><?php echo $total_maintenance_issues; ?></span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>
<?php require_once 'includes/admin_footer.php'; ?>


