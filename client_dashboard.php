<?php
session_start();

if (!isset($_SESSION['username']) || $_SESSION['role'] != 'client') {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';


$logged_in_user_id = $_SESSION['user_id'];
$client_type = $_SESSION['client_type'] ?? 'walk-in'; // Keep for reference but don't restrict

// ADD THIS SECTION FOR CHAT FUNCTIONALITY
require_once 'chat_functions.php';
$unread_count = getUnreadCount($_SESSION['user_id'], $conn);

// Notification functionality for clients
function getClientNotifications($conn, $user_id) {
    $notifications = [];
    try {
        // Get notifications for the client
        $sql = "SELECT * FROM notifications 
                WHERE (user_id = ? AND role = 'client') 
                OR (role IS NULL AND user_id IS NULL)
                ORDER BY created_at DESC 
                LIMIT 10";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $notifications[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error fetching notifications: " . $e->getMessage());
        $notifications = [];
    }
    return $notifications;
}

function getUnreadNotificationCount($conn, $user_id) {
    try {
        $sql = "SELECT COUNT(*) as count FROM notifications 
                WHERE ((user_id = ? AND role = 'client') 
                OR (role IS NULL AND user_id IS NULL))
                AND read_status = 0";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row['count'];
    } catch (Exception $e) {
        error_log("Error fetching unread notification count: " . $e->getMessage());
        return 0;
    }
}

// Get notification data
$notification_count = getUnreadNotificationCount($conn, $logged_in_user_id);
$notifications = getClientNotifications($conn, $logged_in_user_id);

// Add mobile-specific caching headers if needed
if (strpos($_SERVER['HTTP_USER_AGENT'], 'Mobile') !== false) {
    header("Cache-Control: max-age=300"); // 5 minutes for mobile
}

// Function to get client details by user_id
function getClientDetails($conn, $user_id) {
    $sql = "SELECT m.* FROM members m 
            INNER JOIN users u ON m.user_id = u.id 
            WHERE u.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Function to get client's workout plans (available for all clients)
function getClientWorkoutPlans($conn, $user_id) {
    $workoutPlans = [];
    try {
        $sql = "SELECT wp.* FROM workout_plans wp 
                INNER JOIN members m ON wp.member_id = m.id 
                INNER JOIN users u ON m.user_id = u.id 
                WHERE u.id = ? ORDER BY wp.created_at DESC LIMIT 3";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $row['exercises'] = json_decode($row['exercises'], true);
            $workoutPlans[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error fetching workout plans: " . $e->getMessage());
        $workoutPlans = [];
    }
    return $workoutPlans;
}

// Function to get client's meal plans (available for all clients)
function getClientMealPlans($conn, $user_id) {
    $mealPlans = [];
    try {
        $sql = "SELECT mp.* FROM meal_plans mp 
                INNER JOIN members m ON mp.member_id = m.id 
                INNER JOIN users u ON m.user_id = u.id 
                WHERE u.id = ? ORDER BY mp.created_at DESC LIMIT 3";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $row['meals'] = json_decode($row['meals'], true);
            $mealPlans[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error fetching meal plans: " . $e->getMessage());
        $mealPlans = [];
    }
    return $mealPlans;
}

// Function to get client progress history (available for all clients)
function getClientProgress($conn, $user_id) {
    $progress = [];
    try {
        $sql = "SELECT cp.* FROM client_progress cp 
                INNER JOIN members m ON cp.member_id = m.id 
                INNER JOIN users u ON m.user_id = u.id 
                WHERE u.id = ? ORDER BY cp.progress_date DESC LIMIT 3";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $progress[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error fetching client progress: " . $e->getMessage());
        $progress = [];
    }
    return $progress;
}

// Function to get client attendance (available for both types)
function getClientAttendance($conn, $user_id) {
    try {
        $sql = "SELECT COUNT(*) as visit_count FROM attendance a 
                INNER JOIN members m ON a.member_id = m.id 
                INNER JOIN users u ON m.user_id = u.id 
                WHERE u.id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc()['visit_count'];
    } catch (Exception $e) {
        error_log("Error fetching client attendance: " . $e->getMessage());
        return 0;
    }
}

// Function to get active announcements for clients
function getClientAnnouncements($conn) {
    $announcements = [];
    try {
        $column_check = $conn->query("SHOW COLUMNS FROM announcements LIKE 'target_audience'");
        
        if ($column_check->num_rows > 0) {
            $result = $conn->query("SELECT * FROM announcements WHERE (expiry_date IS NULL OR expiry_date >= CURDATE()) AND (target_audience = 'all' OR target_audience = 'clients') ORDER BY created_at DESC LIMIT 3");
        } else {
            $result = $conn->query("SELECT * FROM announcements WHERE (expiry_date IS NULL OR expiry_date >= CURDATE()) ORDER BY created_at DESC LIMIT 3");
        }
        
        while ($row = $result->fetch_assoc()) {
            $announcements[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error fetching announcements: " . $e->getMessage());
        $announcements = [];
    }
    return $announcements;
}

// Function to get client's membership status
function getClientMembershipStatus($conn, $user_id) {
    try {
        $sql = "SELECT m.membership_plan, m.expiry_date, m.status
                FROM members m 
                INNER JOIN users u ON m.user_id = u.id 
                WHERE u.id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    } catch (Exception $e) {
        error_log("Error fetching membership status: " . $e->getMessage());
        return null;
    }
}

// Get all data
$client = getClientDetails($conn, $logged_in_user_id);
$workoutPlans = getClientWorkoutPlans($conn, $logged_in_user_id);
$mealPlans = getClientMealPlans($conn, $logged_in_user_id);
$progress = getClientProgress($conn, $logged_in_user_id);
$attendanceCount = getClientAttendance($conn, $logged_in_user_id);
$announcements = getClientAnnouncements($conn);
$membership = getClientMembershipStatus($conn, $logged_in_user_id);

// Calculate days until expiry
if ($membership) {
    $expiry = new DateTime($membership['expiry_date']);
    $today = new DateTime();
    $daysLeft = $today->diff($expiry)->days;
    if ($today > $expiry) {
        $daysLeft = -$daysLeft;
    }
    $membership['days_left'] = $daysLeft;
}

// If client not found
if (!$client) {
    $username = $_SESSION['username'];
    $sql = "SELECT m.* FROM members m 
            INNER JOIN users u ON m.user_id = u.id 
            WHERE u.username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $client = $result->fetch_assoc();
    
    if (!$client) {
        $client = [
            'full_name' => $_SESSION['username'],
            'member_type' => 'walk-in'
        ];
    }
}
?>

<?php 
$page_title = 'Client Dashboard';
require_once 'includes/client_header.php'; 
?>

    <!-- Main Content -->
    <main id="mainContent" class="main-content flex-1 p-4 space-y-4 overflow-auto">
      <!-- Welcome Section -->
      <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
        <div>
          <h1 class="text-2xl font-bold text-yellow-400">Client Dashboard</h1>
          <p class="text-gray-400">Welcome back, <?php echo isset($client['full_name']) ? htmlspecialchars($client['full_name']) : htmlspecialchars($_SESSION['username']); ?></p>
          <p class="text-sm text-gray-500 mt-1">
            <?php if ($client_type === 'walk-in'): ?>
              <i data-lucide="user" class="w-4 h-4 inline mr-1"></i>
              Walk-in Client
            <?php else: ?>
              <i data-lucide="star" class="w-4 h-4 inline mr-1 text-yellow-400"></i>
              Full-time Client
            <?php endif; ?>
          </p>
        </div>
        <div class="text-right">
          <p class="text-sm text-gray-400">Today is</p>
          <p class="font-semibold"><?php echo date('l, F j, Y'); ?></p>
        </div>
      </div>

      <!-- Stats Cards -->
      <div class="stats-grid">
        <div class="card">
          <p class="card-title"><i data-lucide="dumbbell"></i><span>Workout Plans</span></p>
          <p class="card-value"><?php echo count($workoutPlans); ?></p>
          <p class="text-xs text-green-400 mt-1 flex items-center"><i data-lucide="trending-up" class="w-3 h-3 mr-1"></i>Active plans</p>
        </div>
        <div class="card">
          <p class="card-title"><i data-lucide="utensils"></i><span>Meal Plans</span></p>
          <p class="card-value"><?php echo count($mealPlans); ?></p>
          <p class="text-xs text-gray-400 mt-1">Nutrition guides</p>
        </div>
        
       
        <div class="card border-l-4 border-red-500">
          <p class="card-title"><i data-lucide="alert-triangle"></i><span>Days Left</span></p>
          <p class="card-value"><?php echo ($membership && isset($membership['days_left']) && $membership['days_left'] > 0) ? $membership['days_left'] : '0'; ?></p>
          <p class="text-xs text-red-400 mt-1 flex items-center"><i data-lucide="clock" class="w-3 h-3 mr-1"></i>Membership</p>
        </div>
      </div>

      <!-- Main Content Grid -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Announcements & Recent Activity -->
        <div class="lg:col-span-2 space-y-6">
          <!-- Announcements - Available for all -->
          <div class="card">
            <div class="flex justify-between items-center mb-4">
              <h2 class="card-title"><i data-lucide="megaphone"></i> Announcements</h2>
              <span class="text-xs text-gray-500"><?php echo count($announcements); ?> announcements</span>
            </div>
            
            <div id="announcementsList">
              <?php foreach ($announcements as $announcement): ?>
                <div class="announcement-item <?php echo isset($announcement['priority']) && $announcement['priority'] === 'high' ? 'urgent' : ''; ?>">
                  <div class="announcement-title">
                    <?php echo htmlspecialchars($announcement['title']); ?>
                    <?php if (isset($announcement['priority'])): ?>
                      <span class="priority-badge priority-<?php echo $announcement['priority']; ?>">
                        <?php echo ucfirst($announcement['priority']); ?>
                      </span>
                    <?php endif; ?>
                  </div>
                  <div class="announcement-date">
                    <?php echo date('M j, Y', strtotime($announcement['created_at'])); ?>
                    <?php if (isset($announcement['expiry_date'])): ?>
                      • Expires: <?php echo date('M j, Y', strtotime($announcement['expiry_date'])); ?>
                    <?php endif; ?>
                  </div>
                  <div class="announcement-content">
                    <?php echo htmlspecialchars($announcement['content']); ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            
            <?php if (empty($announcements)): ?>
              <div class="empty-state">
                <i data-lucide="megaphone" class="w-12 h-12 mx-auto"></i>
                <p>No announcements</p>
              </div>
            <?php endif; ?>
          </div>

          <!-- Recent Activity -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Recent Workouts - Available for all clients -->
            <div class="card">
              <div class="flex justify-between items-center mb-4">
                <h2 class="card-title"><i data-lucide="dumbbell"></i> Recent Workouts</h2>
                <a href="workoutplansclient.php" class="text-xs text-yellow-400 hover:underline">View All</a>
              </div>
              <div class="space-y-3">
                <?php foreach ($workoutPlans as $plan): ?>
                  <div class="flex justify-between items-center p-3 bg-gray-800 rounded-lg">
                    <div>
                      <p class="font-medium text-sm"><?php echo htmlspecialchars($plan['plan_name']); ?></p>
                      <p class="text-xs text-gray-400 mt-1">
                        <?php echo isset($plan['exercises']) && is_array($plan['exercises']) ? count($plan['exercises']) . ' exercises' : 'No exercises'; ?>
                      </p>
                    </div>
                    <div class="text-right">
                      <p class="text-xs text-gray-400"><?php echo date('M j', strtotime($plan['created_at'])); ?></p>
                    </div>
                  </div>
                <?php endforeach; ?>
                <?php if (empty($workoutPlans)): ?>
                  <div class="empty-state">
                    <i data-lucide="dumbbell" class="w-12 h-12 mx-auto opacity-50"></i>
                    <p class="text-gray-400 mt-2">No workout plans yet</p>
                    <a href="workoutplansclient.php" class="text-yellow-400 text-sm mt-2 inline-block">Get started</a>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Recent Progress - Available for all clients -->
            <div class="card">
              <div class="flex justify-between items-center mb-4">
                <h2 class="card-title"><i data-lucide="activity"></i> Recent Progress</h2>
                <a href="myprogressclient.php" class="text-xs text-yellow-400 hover:underline">View All</a>
              </div>
              <div class="space-y-3">
                <?php foreach ($progress as $entry): ?>
                  <div class="flex justify-between items-center p-3 bg-gray-800 rounded-lg">
                    <div>
                      <p class="font-medium text-sm"><?php echo date('M j, Y', strtotime($entry['progress_date'])); ?></p>
                      <p class="text-xs text-gray-400 mt-1">
                        <?php 
                          $details = [];
                          if (isset($entry['weight'])) $details[] = "Weight: {$entry['weight']}kg";
                          if (isset($entry['body_fat'])) $details[] = "Fat: {$entry['body_fat']}%";
                          echo implode(' • ', $details);
                        ?>
                      </p>
                    </div>
                  </div>
                <?php endforeach; ?>
                <?php if (empty($progress)): ?>
                  <p class="text-gray-400 text-center py-4">No progress records</p>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <!-- Membership Status - Available for all -->
        <?php if ($membership): ?>
        <div class="space-y-6">
          <div class="card">
            <h2 class="card-title"><i data-lucide="id-card"></i> Membership Status</h2>
            <div class="space-y-3 text-sm">
              <div class="flex justify-between items-center">
                <span class="text-gray-400">Plan:</span>
                <span class="font-semibold"><?php echo ucfirst($membership['membership_plan']); ?></span>
              </div>
              <div class="flex justify-between items-center">
                <span class="text-gray-400">Status:</span>
                <span class="font-semibold <?php echo $membership['status'] === 'active' ? 'text-green-400' : 'text-red-400'; ?>">
                  <?php echo ucfirst($membership['status']); ?>
                </span>
              </div>
              <div class="flex justify-between items-center">
                <span class="text-gray-400">Expires:</span>
                <span class="font-semibold"><?php echo date('M j, Y', strtotime($membership['expiry_date'])); ?></span>
              </div>
              <?php if ($membership['days_left'] <= 7 && $membership['days_left'] > 0): ?>
                <div class="mt-3 p-3 bg-yellow-500/20 border border-yellow-500/30 rounded-lg">
                  <p class="text-yellow-300 text-sm font-semibold">Your membership expires in <?php echo $membership['days_left']; ?> days</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>
<?php require_once 'includes/client_footer.php'; ?>
<?php
$conn->close();
?>



