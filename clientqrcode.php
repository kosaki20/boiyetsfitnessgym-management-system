<?php
session_start();
if (!isset($_SESSION['username']) || $_SESSION['role'] != 'client') {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';
// Get the logged-in client's user ID from session
$logged_in_user_id = $_SESSION['user_id'];

// Function to get client's QR code information
function getClientQRCode($conn, $user_id) {
    $sql = "SELECT m.qr_code_path, m.full_name, m.id as member_id, u.username, m.expiry_date, m.membership_plan
            FROM members m 
            INNER JOIN users u ON m.user_id = u.id 
            WHERE u.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

$qrCodeInfo = getClientQRCode($conn, $logged_in_user_id);
$conn->close();

require_once 'chat_functions.php';
$unread_count = getUnreadCount($_SESSION['user_id'], $conn);
?>

<?php 
$page_title = 'My QR Code';
require_once 'includes/client_header.php'; 
?>
<div class="flex h-[calc(100vh-64px)]">
    <!-- Desktop Sidebar -->
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
            <a href="attendanceclient.php" class="sidebar-item active">
                <div class="flex items-center">
                    <i data-lucide="calendar"></i>
                    <span class="text-sm font-medium">Attendance</span>
                </div>
                <span class="tooltip">Attendance</span>
            </a>

            <!-- Membership -->
            <a href="membershipclient.php" class="sidebar-item">
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
    <main id="mainContent" class="main-content flex-1 p-6 overflow-auto">

  <main class="max-w-4xl mx-auto p-6">
    <div class="bg-gray-800 rounded-xl p-8 text-center">
      <h2 class="text-2xl font-bold text-yellow-400 mb-2">Your Gym Access QR Code</h2>
      <p class="text-gray-400 mb-6">Scan this code at the gym entrance for attendance tracking</p>
      
      <?php if ($qrCodeInfo && $qrCodeInfo['qr_code_path'] && file_exists($qrCodeInfo['qr_code_path'])): ?>
        <div class="bg-white p-6 rounded-lg inline-block mb-6">
          <img src="<?php echo $qrCodeInfo['qr_code_path']; ?>" 
               alt="Your QR Code" 
               class="w-64 h-64 mx-auto">
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
          <div class="bg-gray-700 rounded-lg p-4 text-left">
            <h3 class="font-semibold text-yellow-400 mb-2">Member Information</h3>
            <p><strong>Name:</strong> <?php echo htmlspecialchars($qrCodeInfo['full_name']); ?></p>
            <p><strong>Username:</strong> <?php echo htmlspecialchars($qrCodeInfo['username']); ?></p>
            <p><strong>Member ID:</strong> <?php echo $qrCodeInfo['member_id']; ?></p>
            <p><strong>Plan:</strong> <?php echo ucfirst($qrCodeInfo['membership_plan']); ?></p>
            <p><strong>Expiry:</strong> <?php echo date('M j, Y', strtotime($qrCodeInfo['expiry_date'])); ?></p>
          </div>
          
          <div class="bg-gray-700 rounded-lg p-4 text-left">
            <h3 class="font-semibold text-yellow-400 mb-2">Usage Instructions</h3>
            <ul class="space-y-2 text-sm">
              <li>• Show this QR code at the gym entrance</li>
              <li>• Trainer will scan it to record your attendance</li>
              <li>• Keep this code secure</li>
              <li>• Download a copy for offline use</li>
              <li>• Contact trainer if code doesn't work</li>
            </ul>
          </div>
        </div>
        
        <div class="flex gap-4 justify-center">
          <a href="<?php echo $qrCodeInfo['qr_code_path']; ?>" 
             download="boiyets_qr_code_<?php echo htmlspecialchars($qrCodeInfo['username']); ?>.png" 
             class="bg-yellow-500 hover:bg-yellow-600 text-black px-6 py-3 rounded-lg font-semibold flex items-center gap-2">
            <i data-lucide="download"></i> Download QR Code
          </a>
          <a href="client_dashboard.php" class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-3 rounded-lg font-semibold flex items-center gap-2">
            <i data-lucide="home"></i> Back to Dashboard
          </a>
        </div>
      <?php else: ?>
        <div class="bg-red-900/30 border border-red-700 rounded-lg p-8 max-w-md mx-auto">
          <i data-lucide="qrcode" class="w-16 h-16 text-red-400 mx-auto mb-4"></i>
          <h3 class="text-xl font-semibold text-red-400 mb-2">QR Code Not Available</h3>
          <p class="text-gray-300 mb-4">Your QR code hasn't been generated yet. Please contact your trainer to generate your QR code for gym access.</p>
          <a href="client_dashboard.php" class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-2 rounded-lg font-semibold inline-flex items-center gap-2">
            <i data-lucide="home"></i> Back to Dashboard
          </a>
        </div>
      <?php endif; ?>
    </div>
    </main>
</div>
<?php require_once 'includes/client_footer.php'; ?>



