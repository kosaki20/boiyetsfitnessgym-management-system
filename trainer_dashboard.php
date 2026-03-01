<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'trainer') {
    header("Location: index.php");
    exit();
}

// Database connection
require_once 'includes/db_connection.php';

// Get user data with profile picture
$user_id = $_SESSION['user_id'];
$sql = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

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
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM members");
    $stmt->execute();
    $stats['total_members'] = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    
    // My assigned clients
    $sql = "SELECT COUNT(DISTINCT tca.client_user_id) as total 
            FROM trainer_client_assignments tca 
            WHERE tca.trainer_user_id = ? AND tca.status = 'active'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $trainer_user_id);
    $stmt->execute();
    $stats['my_clients'] = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    
    // Attendance today (all members)
    $today = date('Y-m-d');
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT member_id) as total FROM attendance WHERE DATE(check_in) = ?");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $stats['attendance_today'] = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    
    // Expiring soon (within 7 days) - all members
    $nextWeek = date('Y-m-d', strtotime('+7 days'));
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM members WHERE expiry_date BETWEEN CURDATE() AND ? AND status = 'active'");
    $stmt->bind_param("s", $nextWeek);
    $stmt->execute();
    $stats['expiring_soon'] = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    
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
        $stmt->close();
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
    $stmt->close();
    
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
    $stmt = $conn->prepare("SELECT HOUR(check_in) as hour, COUNT(*) as count 
            FROM attendance 
            WHERE DATE(check_in) = CURDATE() 
            GROUP BY HOUR(check_in) 
            ORDER BY count DESC 
            LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $insights['busiest_hour'] = $row['hour'];
        $insights['busiest_count'] = $row['count'];
    } else {
        $insights['busiest_hour'] = null;
        $insights['busiest_count'] = 0;
    }
    $stmt->close();
    
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
    $stmt->close();
    
    return $renewals;
}

// Function to get equipment status for dashboard
function getEquipmentStatus($conn) {
    $equipment_stats = [];
    
    $stmt = $conn->prepare("SELECT status, COUNT(*) as count 
            FROM equipment 
            WHERE status IN ('Needs Maintenance', 'Under Repair', 'Broken')
            GROUP BY status");
    $stmt->execute();
    $result = $stmt->get_result();
    while($row = $result->fetch_assoc()) {
        $equipment_stats[$row['status']] = $row['count'];
    }
    $stmt->close();
    
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
    $stmt->close();
    
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
    $stmt->close();
    
    return $todaysCheckins;
}

// Function to get active announcements
function getActiveAnnouncements($conn) {
    $announcements = [];
    $stmt = $conn->prepare("SELECT * FROM announcements WHERE expiry_date >= CURDATE() OR expiry_date IS NULL ORDER BY created_at DESC");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $announcements[] = $row;
    }
    $stmt->close();
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
    $count = $result->num_rows;
    $stmt->close();
    return $count > 0;
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
            $stmt->close();
            
            $today = date('Y-m-d');
            $checkStmt = $conn->prepare("SELECT id FROM attendance WHERE member_id = ? AND DATE(check_in) = ?");
            $checkStmt->bind_param("is", $memberId, $today);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows > 0) {
                $message = "Member is already checked in today";
                $messageType = "error";
                $checkStmt->close();
            } else {
                $checkStmt->close();
                $insertStmt = $conn->prepare("INSERT INTO attendance (member_id, check_in) VALUES (?, NOW())");
                $insertStmt->bind_param("i", $memberId);
                if ($insertStmt->execute()) {
                    $insertStmt->close();
                    $message = "Successfully checked in " . htmlspecialchars($member['full_name']);
                    $messageType = "success";
                    
                    // Add notification for successful check-in
                    $notification_sql = "INSERT INTO notifications (user_id, role, title, message, type, priority) VALUES (?, 'trainer', 'Manual Check-in', ?, 'system', 'medium')";
                    $notification_stmt = $conn->prepare($notification_sql);
                    $notification_message = "Manually checked in " . htmlspecialchars($member['full_name']);
                    $notification_stmt->bind_param("is", $trainer_user_id, $notification_message);
                    $notification_stmt->execute();
                    $notification_stmt->close();
                    
                } else {
                    $insertStmt->close();
                    $message = "Error checking in member";
                    $messageType = "error";
                }
            }
        } else {
            $stmt->close();
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
$members_stmt = $conn->prepare("SELECT id, full_name FROM members WHERE status = 'active' ORDER BY full_name");
$members_stmt->execute();
$all_members_result = $members_stmt->get_result();
$members_stmt->close();

// Get data from database
$stats = getDashboardStats($conn, $trainer_user_id);
$announcements = getActiveAnnouncements($conn);
$todaysCheckins = getTodaysCheckins($conn);
$weeklyData = getWeeklyAttendanceData($conn);
$attendanceInsights = getAttendanceInsights($conn);
$upcoming_renewals = getUpcomingRenewals($conn, $trainer_user_id);
$equipment_stats = getEquipmentStatus($conn);

require_once 'includes/trainer_header.php';
require_once 'includes/trainer_sidebar.php';
?>

<!-- Main Content -->
<main id="mainContent" class="main-content flex-1 p-4 space-y-4 overflow-auto">
      <!-- Dashboard Section -->
      <div id="dashboard" class="section active">
        <!-- Welcome Header -->
        <div class="mb-4">
          <h1 class="text-xl font-bold text-yellow-400 mb-1">Trainer Dashboard</h1>
          <p class="text-gray-400 text-sm">Welcome back! Here's your overview for today.</p>
        </div>

        <?php if (isset($message)): ?>
        <div class="mb-4 p-3 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-500/10 text-green-400 border border-green-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20'; ?>">
          <div class="flex items-center gap-2">
            <i data-lucide="<?php echo $messageType === 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-5 h-5"></i>
            <p><?php echo $message; ?></p>
          </div>
        </div>
        <?php endif; ?>

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
              <div class="chart-container" style="position: relative; height:300px;">
                <canvas id="attendanceChart"></canvas>
              </div>
              <?php if (isset($attendanceInsights['busiest_hour'])): ?>
                <div class="mt-2 text-xs text-gray-400 text-center">
                  <?php if ($attendanceInsights['busiest_hour'] !== null): ?>
                    Peak hour: <?php echo date('g A', strtotime($attendanceInsights['busiest_hour'] . ':00')); ?> 
                    (<?php echo $attendanceInsights['busiest_count']; ?> check-ins)
                  <?php else: ?>
                    No check-ins recorded today
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- Manual Attendance Card -->
            <div class="card">
              <h2 class="text-lg font-semibold text-yellow-400 mb-4 flex items-center gap-2">
                <i data-lucide="log-in"></i>
                Manual Member Check-in
              </h2>
              <form method="POST" class="flex flex-col md:flex-row gap-3">
                <input type="hidden" name="manual_attendance" value="1">
                <div class="flex-1">
                  <select name="member_id" class="w-full bg-gray-900 border border-gray-700 rounded-lg p-2.5 text-white focus:ring-yellow-500 focus:border-yellow-500" required>
                    <option value="">Select Member to Check-in...</option>
                    <?php if (!empty($trainer_clients)): ?>
                      <optgroup label="My Assigned Clients">
                        <?php foreach ($trainer_clients as $client): ?>
                          <option value="<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['full_name']); ?></option>
                        <?php endforeach; ?>
                      </optgroup>
                    <?php endif; ?>
                    <optgroup label="Other Members">
                      <?php 
                      while ($row = $all_members_result->fetch_assoc()) {
                          // Skip if already in assigned clients to avoid duplicates
                          $isAssigned = false;
                          foreach ($trainer_clients as $tc) {
                              if ($tc['id'] == $row['id']) { $isAssigned = true; break; }
                          }
                          if (!$isAssigned) {
                              echo "<option value=\"{$row['id']}\">" . htmlspecialchars($row['full_name']) . "</option>";
                          }
                      }
                      ?>
                    </optgroup>
                  </select>
                </div>
                <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2.5 px-6 rounded-lg transition-colors flex items-center justify-center gap-2">
                  <i data-lucide="log-in" class="w-5 h-5"></i> Check In Member
                </button>
              </form>
            </div>

            <!-- Today's Check-Ins -->
            <div class="card">
              <div class="flex justify-between items-center mb-3">
                <p class="card-title"><i data-lucide="calendar"></i><span>Today's Check-Ins</span></p>
                <div class="flex items-center gap-2">
                  <span class="text-xs text-gray-500"><?php echo date('F j, Y'); ?></span>
                  <button id="refreshData" class="button-sm bg-gray-700 hover:bg-gray-600 text-white text-xs px-2 py-1 rounded">
                    <i data-lucide="refresh-cw" class="w-3 h-3 inline mr-1"></i> Refresh
                  </button>
                </div>
              </div>
              <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                  <thead>
                    <tr class="text-gray-400 text-xs uppercase border-b border-gray-800">
                      <th class="py-3 px-4">Member Name</th>
                      <th class="py-3 px-4">Type</th>
                      <th class="py-3 px-4">Check-In Time</th>
                      <th class="py-3 px-4">Status</th>
                    </tr>
                  </thead>
                  <tbody class="text-sm">
                    <?php if (empty($todaysCheckins)): ?>
                      <tr>
                        <td colspan="4" class="py-8 text-center text-gray-500">
                          <i data-lucide="calendar" class="w-8 h-8 mx-auto mb-2 opacity-20"></i>
                          <p>No check-ins yet today</p>
                        </td>
                      </tr>
                    <?php else: ?>
                      <?php foreach ($todaysCheckins as $record): ?>
                        <tr class="border-b border-gray-800 hover:bg-white/5 transition-colors">
                          <td class="py-3 px-4 font-medium"><?php echo htmlspecialchars($record['full_name']); ?></td>
                          <td class="py-3 px-4">
                            <span class="px-2 py-0.5 rounded-full text-xs <?php echo $record['member_type'] === 'client' ? 'bg-blue-500/20 text-blue-400 border border-blue-500/30' : 'bg-green-500/20 text-green-400 border border-green-500/30'; ?>">
                              <?php echo ucfirst($record['member_type']); ?>
                            </span>
                          </td>
                          <td class="py-3 px-4"><?php echo date('g:i A', strtotime($record['check_in'])); ?></td>
                          <td class="py-3 px-4">
                            <span class="px-2 py-0.5 rounded-full text-xs <?php echo $record['check_out'] ? 'bg-gray-500/20 text-gray-400' : 'bg-green-500/20 text-green-400'; ?>">
                              <?php echo $record['check_out'] ? 'Checked Out' : 'Checked In'; ?>
                            </span>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
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
                <a href="member_registration.php" class="flex flex-col items-center p-3 bg-blue-500/10 border border-blue-500/30 rounded-lg hover:bg-blue-500/20 transition-all hover:-translate-y-1">
                  <i data-lucide="user-plus" class="w-5 h-5 text-blue-400 mb-1"></i>
                  <span class="text-[10px] text-white text-center font-medium">Register Member</span>
                </a>
                
                <a href="trainerworkout.php" class="flex flex-col items-center p-3 bg-green-500/10 border border-green-500/30 rounded-lg hover:bg-green-500/20 transition-all hover:-translate-y-1">
                  <i data-lucide="dumbbell" class="w-5 h-5 text-green-400 mb-1"></i>
                  <span class="text-[10px] text-white text-center font-medium">Workout Plans</span>
                </a>
                
                <a href="trainermealplan.php" class="flex flex-col items-center p-3 bg-purple-500/10 border border-purple-500/30 rounded-lg hover:bg-purple-500/20 transition-all hover:-translate-y-1">
                  <i data-lucide="utensils" class="w-5 h-5 text-purple-400 mb-1"></i>
                  <span class="text-[10px] text-white text-center font-medium">Meal Plans</span>
                </a>
                
                <a href="clientprogress.php" class="flex flex-col items-center p-3 bg-orange-500/10 border border-orange-500/30 rounded-lg hover:bg-orange-500/20 transition-all hover:-translate-y-1">
                  <i data-lucide="activity" class="w-5 h-5 text-orange-400 mb-1"></i>
                  <span class="text-[10px] text-white text-center font-medium">Client Progress</span>
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
                  <div class="text-[10px] text-red-300">Broken</div>
                </div>
                <div class="text-center p-2 rounded-lg border border-orange-500/30 bg-orange-500/10">
                  <div class="text-lg font-bold text-orange-400"><?php echo $equipment_stats['Under Repair'] ?? 0; ?></div>
                  <div class="text-[10px] text-orange-300">Repairing</div>
                </div>
                <div class="text-center p-2 rounded-lg border border-yellow-500/30 bg-yellow-500/10">
                  <div class="text-lg font-bold text-yellow-400"><?php echo $equipment_stats['Needs Maintenance'] ?? 0; ?></div>
                  <div class="text-[10px] text-yellow-300">Maintenance</div>
                </div>
              </div>

              <a href="trainer_maintenance_report.php" class="flex items-center justify-center gap-2 bg-yellow-600/20 hover:bg-yellow-600/30 text-yellow-400 border border-yellow-600/30 py-2 rounded-lg text-sm transition-colors">
                <i data-lucide="clipboard-list" class="w-4 h-4"></i> View Reports
              </a>
            </div>

            <!-- Upcoming Renewals -->
            <div class="card">
              <h2 class="text-lg font-semibold text-yellow-400 mb-3 flex items-center gap-2">
                <i data-lucide="calendar"></i>
                Upcoming Renewals
              </h2>
              
              <?php if (!empty($upcoming_renewals)): ?>
                <div class="space-y-2 max-h-60 overflow-y-auto pr-1">
                  <?php foreach ($upcoming_renewals as $renewal): ?>
                    <div class="flex items-center justify-between p-2 bg-white/5 rounded-lg border border-white/5">
                      <div class="flex items-center gap-2">
                        <div class="w-2 h-2 rounded-full 
                          <?php echo $renewal['days_remaining'] <= 2 ? 'bg-red-500' : 
                                 ($renewal['days_remaining'] <= 5 ? 'bg-orange-500' : 'bg-yellow-500'); ?>">
                        </div>
                        <div>
                          <div class="text-sm font-medium text-white"><?php echo htmlspecialchars($renewal['full_name']); ?></div>
                          <div class="text-[10px] text-gray-500"><?php echo ucfirst($renewal['membership_plan']); ?></div>
                        </div>
                      </div>
                      <div class="text-right">
                        <div class="text-[10px] font-bold <?php echo $renewal['days_remaining'] <= 2 ? 'text-red-400' : 
                                                   ($renewal['days_remaining'] <= 5 ? 'text-orange-400' : 'text-yellow-400'); ?>">
                          <?php echo $renewal['days_remaining'] == 0 ? 'Today' : 
                                 ($renewal['days_remaining'] == 1 ? 'Tomorrow' : 
                                 $renewal['days_remaining'] . ' days'); ?>
                        </div>
                        <div class="text-[10px] text-gray-600">
                          <?php echo date('M j', strtotime($renewal['expiry_date'])); ?>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="mt-3">
                  <a href="membership_status.php" class="flex items-center justify-center gap-2 bg-gray-800 hover:bg-gray-700 text-white py-2 rounded-lg text-sm transition-colors">
                    <i data-lucide="refresh-cw" class="w-4 h-4"></i> Manage Renewals
                  </a>
                </div>
              <?php else: ?>
                <div class="text-center py-6">
                  <div class="w-12 h-12 bg-green-500/10 rounded-full flex items-center justify-center mx-auto mb-2 text-green-500">
                    <i data-lucide="check-circle" class="w-6 h-6"></i>
                  </div>
                  <p class="text-sm text-gray-400">No upcoming renewals!</p>
                </div>
              <?php endif; ?>
            </div>

            <!-- Active Announcements -->
            <div class="card">
              <div class="flex justify-between items-center mb-3">
                <h2 class="card-title"><i data-lucide="megaphone"></i> Announcements</h2>
                <span class="text-[10px] text-gray-500" id="announcementCount"><?php echo count($announcements); ?> active</span>
              </div>
              
              <div id="announcementsList" class="max-h-60 overflow-y-auto space-y-2">
                <?php foreach ($announcements as $announcement): ?>
                  <div class="p-3 rounded-lg border <?php echo $announcement['priority'] === 'high' ? 'bg-red-500/10 border-red-500/20' : ($announcement['priority'] === 'medium' ? 'bg-yellow-500/10 border-yellow-500/20' : 'bg-blue-500/10 border-blue-500/20'); ?>">
                    <div class="flex justify-between items-start mb-1">
                      <div class="text-xs font-bold text-white uppercase tracking-wider">
                        <?php echo htmlspecialchars($announcement['title']); ?>
                      </div>
                      <span class="text-[10px] font-bold px-1.5 py-0.5 rounded uppercase <?php echo $announcement['priority'] === 'high' ? 'bg-red-500 text-white' : ($announcement['priority'] === 'medium' ? 'bg-yellow-500 text-black' : 'bg-blue-500 text-white'); ?>">
                        <?php echo $announcement['priority']; ?>
                      </span>
                    </div>
                    <div class="text-[10px] text-gray-400 mb-2">
                      <?php echo date('M j, Y', strtotime($announcement['created_at'])); ?> 
                    </div>
                    <div class="text-xs text-gray-300 leading-relaxed">
                      <?php echo nl2br(htmlspecialchars($announcement['content'])); ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              
              <?php if (empty($announcements)): ?>
                <div id="noAnnouncements" class="text-center py-6">
                  <i data-lucide="megaphone" class="w-10 h-10 text-gray-800 mx-auto mb-2"></i>
                  <p class="text-sm text-gray-600">No active announcements</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Initialize charts and functionality
    document.addEventListener('DOMContentLoaded', function() {
      // Attendance Chart with Real Data
      const ctx = document.getElementById('attendanceChart');
      if (ctx) {
        new Chart(ctx.getContext('2d'), {
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
                        boxPadding: 6
                    }
                },
                scales: {
                    x: { 
                        ticks: { color: '#94a3b8', font: { size: 10 } }, 
                        grid: { color: 'rgba(255, 255, 255, 0.05)' } 
                    },
                    y: { 
                        ticks: { color: '#94a3b8', font: { size: 10 } }, 
                        grid: { color: 'rgba(255, 255, 255, 0.05)' }, 
                        beginAtZero: true,
                        precision: 0
                    }
                }
            }
        });
      }

      // Auto-refresh attendance data every 2 minutes
      setInterval(() => {
          if (document.visibilityState === 'visible') {
              fetch('attendance_ajax.php?action=get_today_count')
                  .then(response => response.json())
                  .then(data => {
                      if (data.success) {
                          const countEl = document.getElementById('attendanceCount');
                          if (countEl) countEl.textContent = data.count;
                      }
                  });
          }
      }, 120000);

      // Setup refresh button
      const refreshBtn = document.getElementById('refreshData');
      if (refreshBtn) {
          refreshBtn.addEventListener('click', () => window.location.reload());
      }
    });
</script>
<?php require_once 'includes/trainer_footer.php'; ?>
<?php if (isset($conn)) { $conn->close(); } ?>