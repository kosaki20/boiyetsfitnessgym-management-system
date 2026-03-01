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

$logged_in_user_id = $_SESSION['user_id'];

// Check if user is actually a walk-in client
function isWalkInClient($conn, $user_id) {
    $sql = "SELECT u.client_type, m.member_type 
            FROM users u 
            LEFT JOIN members m ON u.id = m.user_id 
            WHERE u.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    // Check both user client_type and member member_type
    return ($user && ($user['client_type'] === 'walk-in' || $user['member_type'] === 'walk-in'));
}

// Redirect if not a walk-in client
if (!isWalkInClient($conn, $logged_in_user_id)) {
    header("Location: client_dashboard.php");
    exit();
}

// Function to get walk-in client details
function getWalkInDetails($conn, $user_id) {
    $sql = "SELECT m.*, u.client_type FROM members m 
            INNER JOIN users u ON m.user_id = u.id 
            WHERE u.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Function to get walk-in attendance
function getWalkInAttendance($conn, $user_id) {
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
        error_log("Error fetching attendance: " . $e->getMessage());
        return 0;
    }
}

// Function to get recent attendance (last 7 visits)
function getRecentAttendance($conn, $user_id) {
    $attendance = [];
    try {
        $sql = "SELECT a.check_in, a.duration_minutes FROM attendance a 
                INNER JOIN members m ON a.member_id = m.id 
                INNER JOIN users u ON m.user_id = u.id 
                WHERE u.id = ? 
                ORDER BY a.check_in DESC 
                LIMIT 7";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $attendance[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error fetching recent attendance: " . $e->getMessage());
        $attendance = [];
    }
    return $attendance;
}

// Function to get membership status
function getWalkInMembershipStatus($conn, $user_id) {
    try {
        $sql = "SELECT m.membership_plan, m.expiry_date, m.status, m.start_date
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

// Function to get active announcements for walk-ins
function getWalkInAnnouncements($conn) {
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

// Get all data
$client = getWalkInDetails($conn, $logged_in_user_id);
$attendanceCount = getWalkInAttendance($conn, $logged_in_user_id);
$recentAttendance = getRecentAttendance($conn, $logged_in_user_id);
$announcements = getWalkInAnnouncements($conn);
$membership = getWalkInMembershipStatus($conn, $logged_in_user_id);

// Calculate membership info
if ($membership) {
    $expiry = new DateTime($membership['expiry_date']);
    $today = new DateTime();
    $daysLeft = $today->diff($expiry)->days;
    if ($today > $expiry) {
        $daysLeft = -$daysLeft;
    }
    $membership['days_left'] = $daysLeft;
}

// If client not found, create basic info
if (!$client) {
    $client = [
        'full_name' => $_SESSION['username'],
        'member_type' => 'walk-in',
        'client_type' => 'walk-in'
    ];
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BOIYETS FITNESS GYM - Walk-in Dashboard</title>
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
    
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 0.75rem;
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
    
    .announcement-item {
      border-left: 4px solid #fbbf24;
      padding: 1rem;
      margin-bottom: 1rem;
      background: rgba(255, 255, 255, 0.05);
      border-radius: 8px;
    }
    
    .announcement-item.urgent {
      border-left-color: #ef4444;
    }
    
    .announcement-title {
      font-weight: 600;
      color: #fbbf24;
      margin-bottom: 0.5rem;
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
    
    .empty-state {
      text-align: center;
      padding: 3rem 1rem;
      color: #6b7280;
    }
    
    /* Mobile Navigation */
    .mobile-nav {
      display: flex;
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
    
    .main-content {
      padding-bottom: 80px;
      min-height: calc(100vh - 64px);
    }
    
    @media (max-width: 768px) {
      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 0.5rem;
      }
      
      .card {
        padding: 1rem;
      }
      
      .card-value {
        font-size: 1.25rem;
      }
    }
    
    @media (max-width: 480px) {
      .stats-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <!-- Topbar -->
  <header class="topbar flex items-center justify-between px-4 py-3 shadow">
    <div class="flex items-center space-x-3">
      <h1 class="text-lg font-bold text-yellow-400">BOIYETS FITNESS GYM</h1>
    </div>
    <div class="flex items-center space-x-3">
      <span class="text-xs bg-blue-500/20 text-blue-400 px-2 py-1 rounded-full">
        <i data-lucide="user" class="w-3 h-3 inline mr-1"></i>
        Walk-in Client
      </span>
      <div class="h-8 w-px bg-gray-700 mx-1"></div>
      <div class="flex items-center space-x-2 text-gray-300">
        <img src="<?php echo htmlspecialchars($_SESSION['profile_picture'] ?? 'https://i.pravatar.cc/40'); ?>" class="w-8 h-8 rounded-full border border-gray-600" />
        <span class="text-sm font-medium hidden md:inline">
          <?php echo htmlspecialchars($client['full_name']); ?>
        </span>
      </div>
    </div>
  </header>

  <!-- Main Content -->
  <main class="main-content p-4 space-y-6">
    <!-- Welcome Section -->
    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
      <div>
        <h1 class="text-2xl font-bold text-yellow-400">Walk-in Dashboard</h1>
        <p class="text-gray-400">Welcome, <?php echo htmlspecialchars($client['full_name']); ?></p>
        <p class="text-sm text-blue-400 mt-1 flex items-center">
          <i data-lucide="clock" class="w-4 h-4 inline mr-1"></i>
          Pay-per-use access
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
        <p class="card-title"><i data-lucide="calendar"></i><span>Total Visits</span></p>
        <p class="card-value"><?php echo $attendanceCount; ?></p>
        <p class="text-xs text-gray-400 mt-1">Gym visits</p>
      </div>
      
      <?php if ($membership): ?>
      <div class="card">
        <p class="card-title"><i data-lucide="id-card"></i><span>Membership</span></p>
        <p class="card-value"><?php echo ucfirst($membership['membership_plan']); ?></p>
        <p class="text-xs <?php echo $membership['status'] === 'active' ? 'text-green-400' : 'text-red-400'; ?> mt-1">
          <?php echo ucfirst($membership['status']); ?>
        </p>
      </div>
      
      <div class="card <?php echo ($membership['days_left'] <= 3 && $membership['days_left'] > 0) ? 'border-l-4 border-red-500' : ''; ?>">
        <p class="card-title"><i data-lucide="clock"></i><span>Days Left</span></p>
        <p class="card-value"><?php echo ($membership['days_left'] > 0) ? $membership['days_left'] : '0'; ?></p>
        <p class="text-xs <?php echo ($membership['days_left'] <= 3 && $membership['days_left'] > 0) ? 'text-red-400' : 'text-gray-400'; ?> mt-1">
          Membership expiry
        </p>
      </div>
      <?php else: ?>
      <div class="card border-l-4 border-yellow-500">
        <p class="card-title"><i data-lucide="alert-circle"></i><span>Status</span></p>
        <p class="card-value">No Plan</p>
        <p class="text-xs text-yellow-400 mt-1">Visit reception</p>
      </div>
      <?php endif; ?>
    </div>

    <!-- Main Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Announcements & Recent Activity -->
      <div class="lg:col-span-2 space-y-6">
        <!-- Announcements -->
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
                <div class="text-xs text-gray-400 mb-2">
                  <?php echo date('M j, Y', strtotime($announcement['created_at'])); ?>
                  <?php if (isset($announcement['expiry_date'])): ?>
                    • Expires: <?php echo date('M j, Y', strtotime($announcement['expiry_date'])); ?>
                  <?php endif; ?>
                </div>
                <div class="text-sm text-gray-300">
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

        <!-- Recent Visits -->
        <div class="card">
          <div class="flex justify-between items-center mb-4">
            <h2 class="card-title"><i data-lucide="history"></i> Recent Visits</h2>
            <a href="attendanceclient.php" class="text-xs text-yellow-400 hover:underline">View All</a>
          </div>
          <div class="space-y-3">
            <?php foreach ($recentAttendance as $visit): ?>
              <div class="flex justify-between items-center p-3 bg-gray-800 rounded-lg">
                <div>
                  <p class="font-medium text-sm">
                    <?php echo date('M j, Y', strtotime($visit['check_in'])); ?>
                  </p>
                  <p class="text-xs text-gray-400 mt-1">
                    <?php echo date('g:i A', strtotime($visit['check_in'])); ?>
                    <?php if ($visit['duration_minutes']): ?>
                      • <?php echo $visit['duration_minutes']; ?> mins
                    <?php endif; ?>
                  </p>
                </div>
                <div class="text-right">
                  <span class="text-xs bg-green-500/20 text-green-400 px-2 py-1 rounded-full">
                    Visited
                  </span>
                </div>
              </div>
            <?php endforeach; ?>
            <?php if (empty($recentAttendance)): ?>
              <div class="empty-state">
                <i data-lucide="calendar" class="w-12 h-12 mx-auto opacity-50"></i>
                <p class="text-gray-400 mt-2">No recent visits</p>
                <p class="text-sm text-gray-500">Your visits will appear here</p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Quick Actions & Membership Status -->
      <div class="space-y-6">
        <!-- Quick Actions -->
        <div class="card">
          <h2 class="card-title"><i data-lucide="zap"></i> Quick Actions</h2>
          <div class="space-y-3 mt-4">
            <a href="attendanceclient.php" class="flex items-center gap-3 p-3 bg-gray-800 rounded-lg hover:bg-gray-700 transition-colors">
              <i data-lucide="calendar" class="w-5 h-5 text-blue-400"></i>
              <div>
                <p class="font-medium text-sm">Check Attendance</p>
                <p class="text-xs text-gray-400">View your visit history</p>
              </div>
            </a>
            
            <a href="membershipclient.php" class="flex items-center gap-3 p-3 bg-gray-800 rounded-lg hover:bg-gray-700 transition-colors">
              <i data-lucide="id-card" class="w-5 h-5 text-green-400"></i>
              <div>
                <p class="font-medium text-sm">Membership</p>
                <p class="text-xs text-gray-400">Check status & renew</p>
              </div>
            </a>
            
            <a href="feedbacksclient.php" class="flex items-center gap-3 p-3 bg-gray-800 rounded-lg hover:bg-gray-700 transition-colors">
              <i data-lucide="message-square" class="w-5 h-5 text-yellow-400"></i>
              <div>
                <p class="font-medium text-sm">Send Feedback</p>
                <p class="text-xs text-gray-400">Share your experience</p>
              </div>
            </a>
          </div>
        </div>

        <!-- Membership Status -->
        <?php if ($membership): ?>
        <div class="card">
          <h2 class="card-title"><i data-lucide="id-card"></i> Membership Status</h2>
          <div class="space-y-3 mt-4 text-sm">
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
              <span class="text-gray-400">Started:</span>
              <span class="font-semibold"><?php echo date('M j, Y', strtotime($membership['start_date'])); ?></span>
            </div>
            <div class="flex justify-between items-center">
              <span class="text-gray-400">Expires:</span>
              <span class="font-semibold"><?php echo date('M j, Y', strtotime($membership['expiry_date'])); ?></span>
            </div>
            
            <?php if ($membership['days_left'] <= 7 && $membership['days_left'] > 0): ?>
              <div class="mt-3 p-3 bg-yellow-500/20 border border-yellow-500/30 rounded-lg">
                <p class="text-yellow-300 text-sm font-semibold text-center">
                  Expires in <?php echo $membership['days_left']; ?> days
                </p>
              </div>
            <?php elseif ($membership['days_left'] <= 0): ?>
              <div class="mt-3 p-3 bg-red-500/20 border border-red-500/30 rounded-lg">
                <p class="text-red-400 text-sm font-semibold text-center">
                  Membership expired
                </p>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <?php else: ?>
        <div class="card border-l-4 border-yellow-500">
          <h2 class="card-title"><i data-lucide="alert-circle"></i> No Active Membership</h2>
          <div class="mt-4 text-center">
            <p class="text-gray-400 text-sm mb-3">You don't have an active membership plan</p>
            <a href="membershipclient.php" class="inline-flex items-center gap-2 px-4 py-2 bg-yellow-500 text-black rounded-lg hover:bg-yellow-400 transition-colors text-sm font-medium">
              <i data-lucide="plus" class="w-4 h-4"></i>
              Get Membership
            </a>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </main>

  <!-- Mobile Bottom Navigation -->
  <nav class="mobile-nav" aria-label="Mobile navigation">
    <a href="walkin_dashboard.php" class="mobile-nav-item active">
      <i data-lucide="home"></i>
      <span class="mobile-nav-label">Dashboard</span>
    </a>
    
    <a href="attendanceclient.php" class="mobile-nav-item">
      <i data-lucide="calendar"></i>
      <span class="mobile-nav-label">Attendance</span>
    </a>
    
    <a href="membershipclient.php" class="mobile-nav-item">
      <i data-lucide="id-card"></i>
      <span class="mobile-nav-label">Membership</span>
    </a>
    
    <a href="feedbacksclient.php" class="mobile-nav-item">
      <i data-lucide="message-square"></i>
      <span class="mobile-nav-label">Feedback</span>
    </a>
  </nav>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Initialize icons
      lucide.createIcons();
      
      // Update mobile nav active state
      updateMobileNavActive();
    });

    function updateMobileNavActive() {
      const currentPage = window.location.pathname.split('/').pop();
      const mobileNavItems = document.querySelectorAll('.mobile-nav-item');
      
      mobileNavItems.forEach(item => {
        item.classList.remove('active');
        if (item.getAttribute('href') === currentPage) {
          item.classList.add('active');
        }
      });
    }
  </script>
</body>
</html>



