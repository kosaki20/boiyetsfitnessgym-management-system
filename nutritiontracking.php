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

// Function to get client details
function getClientDetails($conn, $user_id) {
    try {
        $sql = "SELECT m.* FROM members m 
                INNER JOIN users u ON m.user_id = u.id 
                WHERE u.id = ? AND m.member_type = 'client'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    } catch (Exception $e) {
        error_log("Error fetching client details: " . $e->getMessage());
        return null;
    }
}

// Function to get nutrition progress statistics
function getNutritionProgressStats($conn, $user_id) {
    $stats = [];
    
    try {
        // Get member_id
        $member_sql = "SELECT m.id FROM members m 
                       INNER JOIN users u ON m.user_id = u.id 
                       WHERE u.id = ?";
        $stmt = $conn->prepare($member_sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $member_result = $stmt->get_result();
        $member = $member_result->fetch_assoc();
        $member_id = $member['id'];
        
        // Total nutrition sessions
        $total_sessions_sql = "SELECT COUNT(*) as total FROM nutrition_sessions WHERE member_id = ?";
        $stmt = $conn->prepare($total_sessions_sql);
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats['total_sessions'] = $result->fetch_assoc()['total'];
        
        // This week's sessions
        $week_start = date('Y-m-d', strtotime('monday this week'));
        $week_sql = "SELECT COUNT(*) as count FROM nutrition_sessions 
                     WHERE member_id = ? AND session_date >= ?";
        $stmt = $conn->prepare($week_sql);
        $stmt->bind_param("is", $member_id, $week_start);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats['this_week'] = $result->fetch_assoc()['count'];
        
        // This month's sessions
        $month_start = date('Y-m-01');
        $month_sql = "SELECT COUNT(*) as count FROM nutrition_sessions 
                      WHERE member_id = ? AND session_date >= ?";
        $stmt = $conn->prepare($month_sql);
        $stmt->bind_param("is", $member_id, $month_start);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats['this_month'] = $result->fetch_assoc()['count'];
        
        // Last nutrition session date
        $last_session_sql = "SELECT session_date FROM nutrition_sessions 
                             WHERE member_id = ? 
                             ORDER BY session_date DESC LIMIT 1";
        $stmt = $conn->prepare($last_session_sql);
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $last_session = $result->fetch_assoc();
        $stats['last_session'] = $last_session ? $last_session['session_date'] : null;
        
        // Weekly completion rate (last 4 weeks)
        $four_weeks_ago = date('Y-m-d', strtotime('-4 weeks'));
        $weekly_sql = "SELECT 
                        YEARWEEK(session_date) as week,
                        COUNT(*) as sessions,
                        GROUP_CONCAT(DISTINCT session_date) as dates
                       FROM nutrition_sessions 
                       WHERE member_id = ? AND session_date >= ?
                       GROUP BY YEARWEEK(session_date)
                       ORDER BY week DESC
                       LIMIT 4";
        $stmt = $conn->prepare($weekly_sql);
        $stmt->bind_param("is", $member_id, $four_weeks_ago);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats['weekly_data'] = [];
        while ($row = $result->fetch_assoc()) {
            $stats['weekly_data'][] = $row;
        }
    } catch (Exception $e) {
        error_log("Error fetching nutrition progress stats: " . $e->getMessage());
        $stats = [
            'total_sessions' => 0,
            'this_week' => 0,
            'this_month' => 0,
            'last_session' => null,
            'weekly_data' => []
        ];
    }
    
    return $stats;
}

// Function to get nutrition sessions with details
function getNutritionSessions($conn, $user_id, $limit = 20) {
    $sessions = [];
    
    try {
        $sql = "SELECT 
                    ns.*,
                    mp.plan_name,
                    mp.meals as plan_meals
                FROM nutrition_sessions ns
                LEFT JOIN meal_plans mp ON ns.meal_plan_id = mp.id
                INNER JOIN members m ON ns.member_id = m.id
                INNER JOIN users u ON m.user_id = u.id
                WHERE u.id = ?
                ORDER BY ns.session_date DESC, ns.created_at DESC
                LIMIT ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $user_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $row['completed_meals'] = json_decode($row['completed_meals'], true) ?: [];
            $row['plan_meals'] = json_decode($row['plan_meals'], true) ?: [];
            $sessions[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error fetching nutrition sessions: " . $e->getMessage());
        $sessions = [];
    }
    
    return $sessions;
}

// Function to get nutrition streak
function getNutritionStreak($conn, $user_id) {
    $streak = 0;
    
    try {
        $member_sql = "SELECT m.id FROM members m 
                       INNER JOIN users u ON m.user_id = u.id 
                       WHERE u.id = ?";
        $stmt = $conn->prepare($member_sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $member_result = $stmt->get_result();
        $member = $member_result->fetch_assoc();
        $member_id = $member['id'];
        
        $current_date = date('Y-m-d');
        $check_date = $current_date;
        
        while (true) {
            $check_sql = "SELECT 1 FROM nutrition_sessions 
                          WHERE member_id = ? AND session_date = ? 
                          LIMIT 1";
            $stmt = $conn->prepare($check_sql);
            $stmt->bind_param("is", $member_id, $check_date);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $streak++;
                $check_date = date('Y-m-d', strtotime($check_date . ' -1 day'));
            } else {
                break;
            }
        }
    } catch (Exception $e) {
        error_log("Error calculating nutrition streak: " . $e->getMessage());
        $streak = 0;
    }
    
    return $streak;
}

$client = getClientDetails($conn, $logged_in_user_id);
$progressStats = getNutritionProgressStats($conn, $logged_in_user_id);
$nutritionSessions = getNutritionSessions($conn, $logged_in_user_id);
$currentStreak = getNutritionStreak($conn, $logged_in_user_id);

// If client not found
if (!$client) {
    $username = $_SESSION['username'];
    try {
        $sql = "SELECT m.* FROM members m 
                INNER JOIN users u ON m.user_id = u.id 
                WHERE u.username = ? AND m.member_type = 'client'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $client = $result->fetch_assoc();
        
        if (!$client) {
            $client = [
                'full_name' => $_SESSION['username'],
                'member_type' => 'client'
            ];
        }
    } catch (Exception $e) {
        error_log("Error fetching client fallback: " . $e->getMessage());
        $client = [
            'full_name' => $_SESSION['username'],
            'member_type' => 'client'
        ];
    }
}
?>
<?php 
$page_title = 'Nutrition Tracking';
require_once 'includes/client_header.php'; 
?>
<style>
    .stats-card {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 8px;
        padding: 1.5rem;
        text-align: center;
    }
    
    .session-item {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
        border-left: 4px solid #10b981;
    }
    
    .meal-badge {
        background: rgba(16, 185, 129, 0.2);
        color: #10b981;
        padding: 0.25rem 0.5rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
    }
    
    .streak-badge {
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
        padding: 0.5rem 1rem;
        border-radius: 20px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .progress-bar {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        height: 8px;
        overflow: hidden;
    }
    
    .progress-fill {
        background: linear-gradient(135deg, #10b981, #059669);
        height: 100%;
        border-radius: 10px;
        transition: width 0.3s ease;
    }
</style>


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
                    <div id="nutritionToggle" class="sidebar-item active">
                        <div class="flex items-center">
                            <i data-lucide="utensils"></i>
                            <span class="text-sm font-medium">Nutrition Plans</span>
                        </div>
                        <i id="nutritionChevron" data-lucide="chevron-right" class="chevron"></i>
                        <span class="tooltip">Nutrition Plans</span>
                    </div>
                    <div id="nutritionSubmenu" class="submenu space-y-1 open">
                        <a href="nutritionplansclient.php"><i data-lucide="list"></i> My Meal Plans</a>
                        <a href="nutritiontracking.php" class="text-yellow-400"><i data-lucide="chart-bar"></i> Nutrition Tracking</a>
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

        <!-- Mobile Sidebar -->
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
                    <div id="mobileNutritionToggle" class="sidebar-item active">
                        <div class="flex items-center">
                            <i data-lucide="utensils"></i>
                            <span class="text-sm font-medium">Nutrition Plans</span>
                        </div>
                        <i id="mobileNutritionChevron" data-lucide="chevron-right" class="chevron"></i>
                    </div>
                    <div id="mobileNutritionSubmenu" class="submenu space-y-1 open">
                        <a href="nutritionplansclient.php" onclick="closeMobileSidebar()"><i data-lucide="list"></i> My Meal Plans</a>
                        <a href="nutritiontracking.php" class="text-yellow-400" onclick="closeMobileSidebar()"><i data-lucide="chart-bar"></i> Nutrition Tracking</a>
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
                <a href="membershipclient.php" class="sidebar-item" onclick="closeMobileSidebar()">
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

        <!-- Main Content -->
        <main id="mainContent" class="main-content flex-1 p-4 space-y-6 overflow-auto">
            <div class="flex justify-between items-center mb-6">
                <div class="flex items-center space-x-3">
                    <a href="client_dashboard.php" class="text-gray-300 hover:text-yellow-400 transition-colors p-2 rounded-lg hover:bg-white/5">
                        <i data-lucide="arrow-left" class="w-5 h-5"></i>
                    </a>
                    <h2 class="text-2xl font-bold text-yellow-400 flex items-center gap-3">
                        <i data-lucide="chart-bar" class="w-8 h-8"></i>
                        Nutrition Tracking
                    </h2>
                </div>
                <div class="flex items-center gap-4">
                    <?php if ($currentStreak > 0): ?>
                        <span class="streak-badge">
                            <i data-lucide="flame" class="w-4 h-4"></i>
                            <?php echo $currentStreak; ?> Day Streak
                        </span>
                    <?php endif; ?>
                    <span class="bg-green-500 text-white px-4 py-2 rounded-full text-sm font-semibold">
                        Total Days: <?php echo $progressStats['total_sessions']; ?>
                    </span>
                </div>
            </div>

            <!-- Stats Overview -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="stats-card">
                    <div class="text-3xl font-bold text-green-400 mb-2"><?php echo $progressStats['total_sessions']; ?></div>
                    <div class="text-gray-400">Total Days</div>
                    <div class="text-sm text-gray-500 mt-2">Following nutrition plan</div>
                </div>
                
                <div class="stats-card">
                    <div class="text-3xl font-bold text-yellow-400 mb-2"><?php echo $progressStats['this_week']; ?></div>
                    <div class="text-gray-400">This Week</div>
                    <div class="text-sm text-gray-500 mt-2">Weekly consistency</div>
                </div>
                
                <div class="stats-card">
                    <div class="text-3xl font-bold text-blue-400 mb-2"><?php echo $progressStats['this_month']; ?></div>
                    <div class="text-gray-400">This Month</div>
                    <div class="text-sm text-gray-500 mt-2">Monthly progress</div>
                </div>
                
                <div class="stats-card">
                    <div class="text-2xl font-bold text-purple-400 mb-2">
                        <?php echo $progressStats['last_session'] ? date('M j', strtotime($progressStats['last_session'])) : 'Never'; ?>
                    </div>
                    <div class="text-gray-400">Last Tracked</div>
                    <div class="text-sm text-gray-500 mt-2">Most recent meal tracking</div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Recent Nutrition Sessions -->
                <div class="card">
                    <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
                        <i data-lucide="history" class="w-5 h-5"></i>
                        Recent Meal Tracking
                    </h3>
                    
                    <div class="space-y-4">
                        <?php if (!empty($nutritionSessions)): ?>
                            <?php foreach ($nutritionSessions as $session): ?>
                                <div class="session-item">
                                    <div class="flex justify-between items-start mb-3">
                                        <div>
                                            <h4 class="font-semibold text-white text-lg">
                                                <?php echo date('l, F j, Y', strtotime($session['session_date'])); ?>
                                            </h4>
                                            <?php if ($session['plan_name']): ?>
                                                <p class="text-gray-400 text-sm">
                                                    Plan: <?php echo htmlspecialchars($session['plan_name']); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <span class="bg-green-500 text-white px-3 py-1 rounded-full text-sm font-semibold">
                                            <?php echo count($session['completed_meals']); ?> meals
                                        </span>
                                    </div>
                                    
                                    <?php if (!empty($session['completed_meals'])): ?>
                                        <div class="flex flex-wrap gap-2">
                                            <?php foreach ($session['completed_meals'] as $meal): ?>
                                                <span class="meal-badge">
                                                    <i data-lucide="check" class="w-3 h-3"></i>
                                                    <?php echo htmlspecialchars($meal); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-gray-500 text-sm">No meals recorded for this day.</p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-8 text-gray-500">
                                <i data-lucide="utensils" class="w-16 h-16 mx-auto mb-4"></i>
                                <p class="text-lg">No nutrition tracking yet.</p>
                                <p class="text-sm">Start marking meals as done to track your nutrition!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Progress Charts -->
                <div class="card">
                    <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
                        <i data-lucide="trending-up" class="w-5 h-5"></i>
                        Nutrition Consistency
                    </h3>
                    
                    <div class="space-y-6">
                        <!-- Weekly Activity -->
                        <div>
                            <h4 class="font-semibold text-white mb-3">Last 4 Weeks</h4>
                            <div class="space-y-3">
                                <?php if (!empty($progressStats['weekly_data'])): ?>
                                    <?php foreach ($progressStats['weekly_data'] as $week): ?>
                                        <div>
                                            <div class="flex justify-between text-sm text-gray-400 mb-1">
                                                <span>Week <?php echo substr($week['week'], 4); ?></span>
                                                <span><?php echo $week['sessions']; ?> day<?php echo $week['sessions'] > 1 ? 's' : ''; ?></span>
                                            </div>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo min($week['sessions'] * 14.28, 100); ?>%"></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-gray-500 text-sm text-center py-4">No weekly data available yet.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Consistency Meter -->
                        <div>
                            <h4 class="font-semibold text-white mb-3">Nutrition Consistency</h4>
                            <div class="grid grid-cols-3 gap-4 text-center">
                                <div class="p-4 bg-gray-800 rounded-lg">
                                    <div class="text-2xl font-bold text-green-400"><?php echo $progressStats['this_week']; ?>/7</div>
                                    <div class="text-xs text-gray-400">This Week</div>
                                </div>
                                <div class="p-4 bg-gray-800 rounded-lg">
                                    <div class="text-2xl font-bold text-yellow-400"><?php echo $currentStreak; ?></div>
                                    <div class="text-xs text-gray-400">Day Streak</div>
                                </div>
                                <div class="p-4 bg-gray-800 rounded-lg">
                                    <div class="text-2xl font-bold text-purple-400">
                                        <?php echo $progressStats['total_sessions'] > 0 ? round(($progressStats['this_month'] / date('j')) * 100) : 0; ?>%
                                    </div>
                                    <div class="text-xs text-gray-400">Month Goal</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tips Section -->
                        <div class="p-4 bg-green-500/10 border border-green-500/30 rounded-lg">
                            <h5 class="font-semibold text-green-400 mb-2 flex items-center gap-2">
                                <i data-lucide="lightbulb" class="w-4 h-4"></i>
                                Nutrition Tip
                            </h5>
                            <p class="text-green-300 text-sm">
                                <?php
                                if ($currentStreak >= 7) {
                                    echo "Excellent nutrition consistency! Your body is getting the fuel it needs for optimal performance.";
                                } elseif ($progressStats['this_week'] >= 5) {
                                    echo "Great week! Consistent nutrition is key to reaching your fitness goals.";
                                } elseif ($progressStats['this_week'] >= 3) {
                                    echo "Good progress! Try to follow your meal plan more consistently this week.";
                                } else {
                                    echo "Start building healthy eating habits! Follow your meal plan to see better results.";
                                }
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

<?php require_once 'includes/client_footer.php'; ?>



