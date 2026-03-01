<?php
session_start();
if (!isset($_SESSION['username']) || $_SESSION['role'] != 'client') {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';
// ADD THIS SECTION FOR CHAT FUNCTIONALITY
require_once 'chat_functions.php';
$unread_count = getUnreadCount($_SESSION['user_id'], $conn);
$logged_in_user_id = $_SESSION['user_id'];

// Add mobile-specific caching headers if needed
if (strpos($_SERVER['HTTP_USER_AGENT'], 'Mobile') !== false) {
    header("Cache-Control: max-age=300"); // 5 minutes for mobile
}

function getClientDetails($conn, $user_id) {
    $sql = "SELECT m.* FROM members m 
            INNER JOIN users u ON m.user_id = u.id 
            WHERE u.id = ? AND m.member_type = 'client'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function getClientAttendance($conn, $user_id) {
    $attendance = [];
    $sql = "SELECT a.* FROM attendance a 
            INNER JOIN members m ON a.member_id = m.id 
            INNER JOIN users u ON m.user_id = u.id 
            WHERE u.id = ? ORDER BY a.check_in DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $attendance[] = $row;
    }
    
    return $attendance;
}

// Get client details including QR code
$client = getClientDetails($conn, $logged_in_user_id);
$attendance = getClientAttendance($conn, $logged_in_user_id);

// Get today's check-in for status display
$today = date('Y-m-d');
$today_checkin_sql = "SELECT * FROM attendance a 
                     INNER JOIN members m ON a.member_id = m.id 
                     INNER JOIN users u ON m.user_id = u.id 
                     WHERE u.id = ? AND DATE(a.check_in) = ? 
                     ORDER BY a.check_in DESC LIMIT 1";
$stmt = $conn->prepare($today_checkin_sql);
$stmt->bind_param("is", $logged_in_user_id, $today);
$stmt->execute();
$today_checkin_result = $stmt->get_result();
$today_checkin = $today_checkin_result->fetch_assoc();

// Get client's QR code path
$qr_code_path = $client['qr_code_path'] ?? null;
$has_qr_code = $qr_code_path && file_exists($qr_code_path);
?>
<?php 
$page_title = 'My Attendance';
require_once 'includes/client_header.php'; 
?>
<style>
    .attendance-item {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
        border-left: 4px solid #fbbf24;
    }
    
    .stats-item {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
    }
    
    .status-badge {
        padding: 0.5rem 1rem;
        border-radius: 20px;
        font-size: 0.875rem;
        font-weight: 500;
    }
    
    .status-completed {
        background: rgba(16, 185, 129, 0.2);
        color: #10b981;
        border: 1px solid rgba(16, 185, 129, 0.3);
    }
    
    .status-checked-in {
        background: rgba(245, 158, 11, 0.2);
        color: #f59e0b;
        border: 1px solid rgba(245, 158, 11, 0.3);
    }

    /* QR Code Styles */
    .qr-code-container {
        background: white;
        padding: 1rem;
        border-radius: 12px;
        display: inline-block;
        margin: 1rem 0;
        border: 2px solid #fbbf24;
    }
    
    .qr-code-image {
        width: 200px;
        height: 200px;
        object-fit: contain;
    }
    
    .qr-instructions {
        background: rgba(251, 191, 36, 0.1);
        border: 1px solid rgba(251, 191, 36, 0.3);
        border-radius: 8px;
        padding: 1rem;
        margin-top: 1rem;
    }
    
    .qr-status {
        padding: 0.5rem 1rem;
        border-radius: 20px;
        font-size: 0.875rem;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .qr-available {
        background: rgba(16, 185, 129, 0.2);
        color: #10b981;
        border: 1px solid rgba(16, 185, 129, 0.3);
    }
    
    .qr-missing {
        background: rgba(239, 68, 68, 0.2);
        color: #ef4444;
        border: 1px solid rgba(239, 68, 68, 0.3);
    }
</style>

    <!-- Main Content -->
    <main id="mainContent" class="main-content flex-1 p-4 space-y-6 overflow-auto">
      <div class="flex justify-between items-center mb-6">
        <div class="flex items-center space-x-3">
          <a href="client_dashboard.php" class="text-gray-300 hover:text-yellow-400 transition-colors p-2 rounded-lg hover:bg-white/5">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
          </a>
          <h2 class="text-2xl font-bold text-yellow-400 flex items-center gap-3">
            <i data-lucide="calendar" class="w-8 h-8"></i>
            My Attendance
          </h2>
        </div>
        <span class="bg-green-500 text-white px-4 py-2 rounded-full text-sm font-semibold">Total Visits: <?php echo count($attendance); ?></span>
      </div>

      <!-- Quick Actions -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Status Card -->
        <div class="card">
          <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
            <i data-lucide="clock" class="w-5 h-5"></i>
            Today's Status
          </h3>
          
          <div class="text-center">
            <div class="text-4xl font-bold text-white mb-4">
              <?php echo date('g:i A'); ?>
            </div>
            <div class="text-gray-400 mb-6">
              <?php echo date('l, F j, Y'); ?>
            </div>
            
            <?php if (!$today_checkin): ?>
              <div class="bg-yellow-500/20 border border-yellow-500/30 text-yellow-300 p-6 rounded-lg">
                <div class="font-semibold text-lg mb-2">Not Checked In</div>
                <p class="text-sm">Use your QR code at the gym scanner to check in</p>
              </div>
            <?php else: ?>
              <div class="bg-green-500/20 border border-green-500/30 text-green-300 p-6 rounded-lg">
                <div class="font-semibold text-lg mb-2">Checked In Today</div>
                <div class="text-xl mb-2"><?php echo date('g:i A', strtotime($today_checkin['check_in'])); ?></div>
                <p class="text-sm">You have successfully checked in for today</p>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Stats Card -->
        <div class="card">
          <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
            <i data-lucide="bar-chart-3" class="w-5 h-5"></i>
            Attendance Stats
          </h3>
          
          <div class="space-y-4">
            <div class="stats-item">
              <div class="flex justify-between items-center">
                <div class="text-gray-300">This Week</div>
                <div class="text-2xl font-bold text-white">
                  <?php
                  $week_start = date('Y-m-d', strtotime('monday this week'));
                  $week_sql = "SELECT COUNT(*) as count FROM attendance a 
                              INNER JOIN members m ON a.member_id = m.id 
                              INNER JOIN users u ON m.user_id = u.id 
                              WHERE u.id = ? AND DATE(a.check_in) >= ?";
                  $stmt = $conn->prepare($week_sql);
                  $stmt->bind_param("is", $logged_in_user_id, $week_start);
                  $stmt->execute();
                  $week_result = $stmt->get_result();
                  $week_count = $week_result->fetch_assoc()['count'];
                  echo $week_count;
                  ?>
                </div>
              </div>
            </div>
            
            <div class="stats-item">
              <div class="flex justify-between items-center">
                <div class="text-gray-300">This Month</div>
                <div class="text-2xl font-bold text-white">
                  <?php
                  $month_start = date('Y-m-01');
                  $month_sql = "SELECT COUNT(*) as count FROM attendance a 
                              INNER JOIN members m ON a.member_id = m.id 
                              INNER JOIN users u ON m.user_id = u.id 
                              WHERE u.id = ? AND DATE(a.check_in) >= ?";
                  $stmt = $conn->prepare($month_sql);
                  $stmt->bind_param("is", $logged_in_user_id, $month_start);
                  $stmt->execute();
                  $month_result = $stmt->get_result();
                  $month_count = $month_result->fetch_assoc()['count'];
                  echo $month_count;
                  ?>
                </div>
              </div>
            </div>
            
            <div class="stats-item">
              <div class="flex justify-between items-center">
                <div class="text-gray-300">Last Visit</div>
                <div class="text-lg font-bold text-white">
                  <?php
                  if (!empty($attendance)) {
                    $last_visit = date('M j', strtotime($attendance[0]['check_in']));
                    echo $last_visit;
                  } else {
                    echo 'No visits yet';
                  }
                  ?>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- QR Code Section -->
      <div class="card">
        <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
          <i data-lucide="qrcode" class="w-5 h-5"></i>
          Your Attendance QR Code
        </h3>
        
        <div class="text-center">
          <?php if ($has_qr_code): ?>
            <div class="mb-4">
              <span class="qr-status qr-available">
                <i data-lucide="check-circle" class="w-4 h-4"></i>
                QR Code Available
              </span>
            </div>
            
            <div class="qr-code-container">
              <img src="<?php echo $qr_code_path; ?>" 
                   alt="Your Attendance QR Code" 
                   class="qr-code-image mx-auto">
            </div>
            
            <div class="mt-4">
              <a href="<?php echo $qr_code_path; ?>" 
                 download="boiyets_qr_code_<?php echo $client['id']; ?>.png" 
                 class="inline-flex items-center gap-2 bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg transition-colors">
                <i data-lucide="download" class="w-4 h-4"></i>
                Download QR Code
              </a>
            </div>
            
            <div class="qr-instructions mt-4">
              <h4 class="font-semibold text-yellow-400 mb-2 flex items-center gap-2">
                <i data-lucide="info" class="w-4 h-4"></i>
                How to Use Your QR Code:
              </h4>
              <ul class="text-sm text-gray-300 space-y-1 text-left">
                <li class="flex items-start gap-2">
                  <i data-lucide="check" class="w-3 h-3 text-green-400 mt-1 flex-shrink-0"></i>
                  <span>Show this QR code at the gym entrance scanner</span>
                </li>
                <li class="flex items-start gap-2">
                  <i data-lucide="check" class="w-3 h-3 text-green-400 mt-1 flex-shrink-0"></i>
                  <span>The scanner will automatically check you in/out</span>
                </li>
                <li class="flex items-start gap-2">
                  <i data-lucide="check" class="w-3 h-3 text-green-400 mt-1 flex-shrink-0"></i>
                  <span>You can also download and print the QR code</span>
                </li>
                <li class="flex items-start gap-2">
                  <i data-lucide="check" class="w-3 h-3 text-green-400 mt-1 flex-shrink-0"></i>
                  <span>Keep your QR code secure - it's linked to your account</span>
                </li>
              </ul>
            </div>
          <?php else: ?>
            <div class="bg-red-500/20 border border-red-500/30 text-red-300 p-6 rounded-lg">
              <div class="flex items-center justify-center gap-2 mb-2">
                <i data-lucide="x-circle" class="w-6 h-6"></i>
                <span class="font-semibold text-lg">QR Code Not Available</span>
              </div>
              <p class="text-sm mb-4">Your QR code hasn't been generated yet.</p>
              <div class="qr-instructions">
                <p class="text-sm text-yellow-300">
                  Please contact your trainer to generate your QR code. 
                  Once generated, it will automatically appear here and be kept up-to-date.
                </p>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Attendance History -->
      <div class="card">
        <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
          <i data-lucide="list" class="w-5 h-5"></i>
          Attendance History
        </h3>
        
        <div class="space-y-4">
          <?php foreach ($attendance as $visit): ?>
            <div class="attendance-item">
              <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                <div class="mb-3 md:mb-0">
                  <div class="font-semibold text-white text-lg">
                    <?php echo date('l, F j, Y', strtotime($visit['check_in'])); ?>
                  </div>
                  <div class="text-gray-400 text-sm">
                    Check-in: <?php echo date('g:i A', strtotime($visit['check_in'])); ?>
                    <?php if ($visit['check_out']): ?>
                      <br>Check-out: <?php echo date('g:i A', strtotime($visit['check_out'])); ?>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="flex items-center gap-3">
                  <span class="status-badge <?php echo $visit['check_out'] ? 'status-completed' : 'status-checked-in'; ?>">
                    <?php echo $visit['check_out'] ? 'Completed' : 'Checked In'; ?>
                  </span>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
          
          <?php if (empty($attendance)): ?>
            <div class="text-center py-8 text-gray-500">
              <i data-lucide="calendar" class="w-16 h-16 mx-auto mb-4"></i>
              <p class="text-lg">No attendance records yet.</p>
              <p class="text-sm">Use your QR code at the gym to start tracking your visits!</p>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </main>
  </div>


