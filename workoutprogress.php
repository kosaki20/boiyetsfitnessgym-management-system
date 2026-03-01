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

// Function to get workout progress statistics
function getWorkoutProgressStats($conn, $user_id) {
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
        
        // Total workout sessions
        $total_sessions_sql = "SELECT COUNT(*) as total FROM workout_sessions WHERE member_id = ?";
        $stmt = $conn->prepare($total_sessions_sql);
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats['total_sessions'] = $result->fetch_assoc()['total'];
        
        // This week's sessions
        $week_start = date('Y-m-d', strtotime('monday this week'));
        $week_sql = "SELECT COUNT(*) as count FROM workout_sessions 
                     WHERE member_id = ? AND session_date >= ?";
        $stmt = $conn->prepare($week_sql);
        $stmt->bind_param("is", $member_id, $week_start);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats['this_week'] = $result->fetch_assoc()['count'];
        
        // This month's sessions
        $month_start = date('Y-m-01');
        $month_sql = "SELECT COUNT(*) as count FROM workout_sessions 
                      WHERE member_id = ? AND session_date >= ?";
        $stmt = $conn->prepare($month_sql);
        $stmt->bind_param("is", $member_id, $month_start);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats['this_month'] = $result->fetch_assoc()['count'];
        
        // Last workout date
        $last_workout_sql = "SELECT session_date FROM workout_sessions 
                             WHERE member_id = ? 
                             ORDER BY session_date DESC LIMIT 1";
        $stmt = $conn->prepare($last_workout_sql);
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $last_workout = $result->fetch_assoc();
        $stats['last_workout'] = $last_workout ? $last_workout['session_date'] : null;
        
        // Weekly completion rate (last 4 weeks)
        $four_weeks_ago = date('Y-m-d', strtotime('-4 weeks'));
        $weekly_sql = "SELECT 
                        YEARWEEK(session_date) as week,
                        COUNT(*) as sessions,
                        GROUP_CONCAT(DISTINCT session_date) as dates
                       FROM workout_sessions 
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
        error_log("Error fetching workout progress stats: " . $e->getMessage());
        $stats = [
            'total_sessions' => 0,
            'this_week' => 0,
            'this_month' => 0,
            'last_workout' => null,
            'weekly_data' => []
        ];
    }
    
    return $stats;
}

// Function to get workout sessions with details
function getWorkoutSessions($conn, $user_id, $limit = 20) {
    $sessions = [];
    
    try {
        // Get member_id first
        $member_sql = "SELECT m.id FROM members m 
                       INNER JOIN users u ON m.user_id = u.id 
                       WHERE u.id = ?";
        $stmt = $conn->prepare($member_sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $member_result = $stmt->get_result();
        $member = $member_result->fetch_assoc();
        $member_id = $member['id'];
        
        $sql = "SELECT 
                    ws.*,
                    wp.plan_name,
                    wp.exercises as plan_exercises
                FROM workout_sessions ws
                LEFT JOIN workout_plans wp ON ws.workout_plan_id = wp.id
                WHERE ws.member_id = ?
                ORDER BY ws.session_date DESC, ws.created_at DESC
                LIMIT ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $member_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $row['completed_exercises'] = json_decode($row['completed_exercises'], true) ?: [];
            $row['exercise_weights'] = json_decode($row['exercise_weights'], true) ?: [];
            $row['plan_exercises'] = json_decode($row['plan_exercises'], true) ?: [];
            $sessions[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error fetching workout sessions: " . $e->getMessage());
        $sessions = [];
    }
    
    return $sessions;
}

// Function to get completion streak
function getCurrentStreak($conn, $user_id) {
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
            $check_sql = "SELECT 1 FROM workout_sessions 
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
        error_log("Error calculating streak: " . $e->getMessage());
        $streak = 0;
    }
    
    return $streak;
}

// NEW FUNCTION: Get exercise weight progression data (MariaDB compatible)
function getExerciseProgressData($conn, $user_id) {
    $exercise_data = [];
    
    try {
        // Get member_id first
        $member_sql = "SELECT m.id FROM members m 
                       INNER JOIN users u ON m.user_id = u.id 
                       WHERE u.id = ?";
        $stmt = $conn->prepare($member_sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $member_result = $stmt->get_result();
        $member = $member_result->fetch_assoc();
        $member_id = $member['id'];
        
        $sql = "SELECT 
                    ws.session_date,
                    ws.completed_exercises,
                    ws.exercise_weights,
                    wp.plan_name
                FROM workout_sessions ws
                LEFT JOIN workout_plans wp ON ws.workout_plan_id = wp.id
                WHERE ws.member_id = ? 
                AND ws.exercise_weights IS NOT NULL
                AND ws.completed_exercises IS NOT NULL
                ORDER BY ws.session_date ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $completed_exercises = json_decode($row['completed_exercises'], true) ?: [];
            $exercise_weights = json_decode($row['exercise_weights'], true) ?: [];
            
            foreach ($completed_exercises as $index => $exercise_name) {
                if (empty($exercise_name)) continue;
                
                if (!isset($exercise_data[$exercise_name])) {
                    $exercise_data[$exercise_name] = [];
                }
                
                $weight = isset($exercise_weights[$index]) ? floatval($exercise_weights[$index]) : 0;
                
                $exercise_data[$exercise_name][] = [
                    'date' => $row['session_date'],
                    'weight' => $weight,
                    'plan_name' => $row['plan_name']
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching exercise progress data: " . $e->getMessage());
        $exercise_data = [];
    }
    
    return $exercise_data;
}

// NEW FUNCTION: Get top exercises by frequency (MariaDB compatible)
function getTopExercises($conn, $user_id, $limit = 5) {
    $exercises = [];
    
    try {
        // Get member_id first
        $member_sql = "SELECT m.id FROM members m 
                       INNER JOIN users u ON m.user_id = u.id 
                       WHERE u.id = ?";
        $stmt = $conn->prepare($member_sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $member_result = $stmt->get_result();
        $member = $member_result->fetch_assoc();
        $member_id = $member['id'];
        
        // Get all workout sessions with exercise data
        $sessions_sql = "SELECT 
                            ws.session_date,
                            ws.completed_exercises,
                            ws.exercise_weights
                        FROM workout_sessions ws
                        WHERE ws.member_id = ? 
                        AND ws.exercise_weights IS NOT NULL
                        AND ws.completed_exercises IS NOT NULL
                        ORDER BY ws.session_date ASC";
        
        $stmt = $conn->prepare($sessions_sql);
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $exercise_stats = [];
        
        while ($row = $result->fetch_assoc()) {
            $completed_exercises = json_decode($row['completed_exercises'], true) ?: [];
            $exercise_weights = json_decode($row['exercise_weights'], true) ?: [];
            
            foreach ($completed_exercises as $index => $exercise_name) {
                if (empty($exercise_name)) continue;
                
                if (!isset($exercise_stats[$exercise_name])) {
                    $exercise_stats[$exercise_name] = [
                        'frequency' => 0,
                        'weights' => [],
                        'dates' => []
                    ];
                }
                
                $weight = isset($exercise_weights[$index]) ? floatval($exercise_weights[$index]) : 0;
                
                $exercise_stats[$exercise_name]['frequency']++;
                $exercise_stats[$exercise_name]['weights'][] = $weight;
                $exercise_stats[$exercise_name]['dates'][] = $row['session_date'];
            }
        }
        
        // Process the stats to get top exercises
        foreach ($exercise_stats as $exercise_name => $stats) {
            if (count($stats['weights']) > 0) {
                $exercises[] = [
                    'exercise_name' => $exercise_name,
                    'frequency' => $stats['frequency'],
                    'max_weight' => max($stats['weights']),
                    'min_weight' => min($stats['weights']),
                    'avg_weight' => array_sum($stats['weights']) / count($stats['weights'])
                ];
            }
        }
        
        // Sort by frequency and limit results
        usort($exercises, function($a, $b) {
            return $b['frequency'] - $a['frequency'];
        });
        
        return array_slice($exercises, 0, $limit);
    } catch (Exception $e) {
        error_log("Error fetching top exercises: " . $e->getMessage());
        return [];
    }
}

$client = getClientDetails($conn, $logged_in_user_id);
$progressStats = getWorkoutProgressStats($conn, $logged_in_user_id);
$workoutSessions = getWorkoutSessions($conn, $logged_in_user_id);
$currentStreak = getCurrentStreak($conn, $logged_in_user_id);
$exerciseProgressData = getExerciseProgressData($conn, $logged_in_user_id);
$topExercises = getTopExercises($conn, $logged_in_user_id);

// Prepare data for charts
$chart_data = [];
foreach ($exerciseProgressData as $exercise_name => $data) {
    if (count($data) > 1) { // Only show exercises with multiple data points
        $chart_data[$exercise_name] = [
            'labels' => array_column($data, 'date'),
            'weights' => array_column($data, 'weight'),
            'plan_names' => array_column($data, 'plan_name')
        ];
    }
}

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
$page_title = 'Workout Progress';
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
        border-left: 4px solid #fbbf24;
    }
    
    .exercise-badge {
        background: rgba(59, 130, 246, 0.2);
        color: #3b82f6;
        padding: 0.25rem 0.5rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
    }
    
    .streak-badge {
        background: linear-gradient(135deg, #f59e0b, #d97706);
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
    
    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
    }
    
    .exercise-stats {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
    }
    
    .weight-progress {
        color: #10b981;
        font-weight: 600;
    }
    
    .weight-decrease {
        color: #ef4444;
        font-weight: 600;
    }
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                    <div id="mobileWorkoutToggle" class="sidebar-item active">
                        <div class="flex items-center">
                            <i data-lucide="dumbbell"></i>
                            <span class="text-sm font-medium">Workout Plans</span>
                        </div>
                        <i id="mobileWorkoutChevron" data-lucide="chevron-right" class="chevron"></i>
                    </div>
                    <div id="mobileWorkoutSubmenu" class="submenu space-y-1 open">
                        <a href="workoutplansclient.php" onclick="closeMobileSidebar()"><i data-lucide="list"></i> My Workout Plans</a>
                        <a href="workoutprogress.php" class="text-yellow-400" onclick="closeMobileSidebar()"><i data-lucide="activity"></i> Workout Progress</a>
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
                        <i data-lucide="activity" class="w-8 h-8"></i>
                        Workout Progress & Analytics
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
                        Total Sessions: <?php echo $progressStats['total_sessions']; ?>
                    </span>
                </div>
            </div>

            <!-- Stats Overview -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="stats-card">
                    <div class="text-3xl font-bold text-yellow-400 mb-2"><?php echo $progressStats['total_sessions']; ?></div>
                    <div class="text-gray-400">Total Workouts</div>
                    <div class="text-sm text-gray-500 mt-2">All time sessions</div>
                </div>
                
                <div class="stats-card">
                    <div class="text-3xl font-bold text-green-400 mb-2"><?php echo $progressStats['this_week']; ?></div>
                    <div class="text-gray-400">This Week</div>
                    <div class="text-sm text-gray-500 mt-2">Weekly progress</div>
                </div>
                
                <div class="stats-card">
                    <div class="text-3xl font-bold text-blue-400 mb-2"><?php echo $progressStats['this_month']; ?></div>
                    <div class="text-gray-400">This Month</div>
                    <div class="text-sm text-gray-500 mt-2">Monthly sessions</div>
                </div>
                
                <div class="stats-card">
                    <div class="text-2xl font-bold text-purple-400 mb-2">
                        <?php echo $progressStats['last_workout'] ? date('M j', strtotime($progressStats['last_workout'])) : 'Never'; ?>
                    </div>
                    <div class="text-gray-400">Last Workout</div>
                    <div class="text-sm text-gray-500 mt-2">Most recent session</div>
                </div>
            </div>

            <!-- Exercise Progress Charts Section -->
            <?php if (!empty($chart_data)): ?>
                <div class="card mb-6">
                    <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
                        <i data-lucide="trending-up" class="w-5 h-5"></i>
                        Exercise Weight Progression
                    </h3>
                    
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <?php foreach ($chart_data as $exercise_name => $data): ?>
                            <div class="bg-gray-800 rounded-lg p-4">
                                <h4 class="font-semibold text-white mb-4 text-lg"><?php echo htmlspecialchars($exercise_name); ?></h4>
                                <div class="chart-container">
                                    <canvas id="chart-<?php echo md5($exercise_name); ?>"></canvas>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="card mb-6">
                    <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
                        <i data-lucide="trending-up" class="w-5 h-5"></i>
                        Exercise Weight Progression
                    </h3>
                    <div class="text-center py-8 text-gray-500">
                        <i data-lucide="bar-chart" class="w-16 h-16 mx-auto mb-4"></i>
                        <p class="text-lg">No exercise progression data yet.</p>
                        <p class="text-sm">Complete more workouts with weight tracking to see your progress charts!</p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Top Exercises Section -->
            <?php if (!empty($topExercises)): ?>
                <div class="card mb-6">
                    <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
                        <i data-lucide="award" class="w-5 h-5"></i>
                        Your Top Exercises
                    </h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($topExercises as $exercise): ?>
                            <div class="exercise-stats">
                                <div class="flex justify-between items-start mb-3">
                                    <h4 class="font-semibold text-white text-lg"><?php echo htmlspecialchars($exercise['exercise_name']); ?></h4>
                                    <span class="bg-blue-500 text-white px-2 py-1 rounded-full text-xs">
                                        <?php echo $exercise['frequency']; ?>x
                                    </span>
                                </div>
                                
                                <div class="space-y-2">
                                    <div class="flex justify-between text-sm">
                                        <span class="text-gray-400">Max Weight:</span>
                                        <span class="text-green-400 font-semibold"><?php echo $exercise['max_weight']; ?> kg</span>
                                    </div>
                                    <div class="flex justify-between text-sm">
                                        <span class="text-gray-400">Avg Weight:</span>
                                        <span class="text-yellow-400 font-semibold"><?php echo round($exercise['avg_weight'], 1); ?> kg</span>
                                    </div>
                                    <div class="flex justify-between text-sm">
                                        <span class="text-gray-400">Min Weight:</span>
                                        <span class="text-red-400 font-semibold"><?php echo $exercise['min_weight']; ?> kg</span>
                                    </div>
                                </div>
                                
                                <?php 
                                $progress = $exercise['max_weight'] - $exercise['min_weight'];
                                if ($progress > 0): ?>
                                    <div class="mt-3 p-2 bg-green-500/10 border border-green-500/30 rounded">
                                        <div class="flex justify-between text-sm">
                                            <span class="text-green-400">Progress:</span>
                                            <span class="weight-progress">+<?php echo $progress; ?> kg</span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="card mb-6">
                    <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
                        <i data-lucide="award" class="w-5 h-5"></i>
                        Your Top Exercises
                    </h3>
                    <div class="text-center py-8 text-gray-500">
                        <i data-lucide="dumbbell" class="w-16 h-16 mx-auto mb-4"></i>
                        <p class="text-lg">No exercise data available yet.</p>
                        <p class="text-sm">Start tracking your exercises with weights to see your top exercises here!</p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Recent Workout Sessions -->
                <div class="card">
                    <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
                        <i data-lucide="history" class="w-5 h-5"></i>
                        Recent Workout Sessions
                    </h3>
                    
                    <div class="space-y-4">
                        <?php if (!empty($workoutSessions)): ?>
                            <?php foreach ($workoutSessions as $session): ?>
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
                                            <?php echo count($session['completed_exercises']); ?> exercises
                                        </span>
                                    </div>
                                    
                                    <?php if (!empty($session['completed_exercises'])): ?>
                                        <div class="space-y-2">
                                            <?php foreach ($session['completed_exercises'] as $index => $exercise): ?>
                                                <div class="flex justify-between items-center">
                                                    <span class="exercise-badge">
                                                        <i data-lucide="check" class="w-3 h-3"></i>
                                                        <?php echo htmlspecialchars($exercise); ?>
                                                    </span>
                                                    <?php if (isset($session['exercise_weights'][$index])): ?>
                                                        <span class="text-white font-semibold bg-gray-700 px-2 py-1 rounded text-sm">
                                                            <?php echo $session['exercise_weights'][$index]; ?> kg
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-gray-500 text-sm">No exercises recorded for this session.</p>
                                    <?php endif; ?>
                                    
                                    <?php if ($session['duration_minutes']): ?>
                                        <div class="mt-3 flex items-center gap-2 text-sm text-gray-400">
                                            <i data-lucide="clock" class="w-4 h-4"></i>
                                            Duration: <?php echo $session['duration_minutes']; ?> minutes
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($session['notes']): ?>
                                        <div class="mt-3 p-3 bg-blue-500/10 border border-blue-500/30 rounded-lg">
                                            <p class="text-blue-300 text-sm"><?php echo htmlspecialchars($session['notes']); ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-8 text-gray-500">
                                <i data-lucide="calendar" class="w-16 h-16 mx-auto mb-4"></i>
                                <p class="text-lg">No workout sessions yet.</p>
                                <p class="text-sm">Start marking exercises as done to track your progress!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Progress Charts -->
                <div class="card">
                    <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
                        <i data-lucide="trending-up" class="w-5 h-5"></i>
                        Workout Frequency
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
                                                <span><?php echo $week['sessions']; ?> session<?php echo $week['sessions'] > 1 ? 's' : ''; ?></span>
                                            </div>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo min($week['sessions'] * 25, 100); ?>%"></div>
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
                            <h4 class="font-semibold text-white mb-3">Workout Consistency</h4>
                            <div class="grid grid-cols-3 gap-4 text-center">
                                <div class="p-4 bg-gray-800 rounded-lg">
                                    <div class="text-2xl font-bold text-green-400"><?php echo $progressStats['this_week']; ?>/7</div>
                                    <div class="text-xs text-gray-400">This Week</div>
                                </div>
                                <div class="p-4 bg-gray-800 rounded-lg">
                                    <div class="text-2xl font-bold text-blue-400"><?php echo $currentStreak; ?></div>
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
                        <div class="p-4 bg-yellow-500/10 border border-yellow-500/30 rounded-lg">
                            <h5 class="font-semibold text-yellow-400 mb-2 flex items-center gap-2">
                                <i data-lucide="lightbulb" class="w-4 h-4"></i>
                                Progress Tip
                            </h5>
                            <p class="text-yellow-300 text-sm">
                                <?php
                                if ($currentStreak >= 7) {
                                    echo "Amazing consistency! Keep up the great work and maintain your streak.";
                                } elseif ($progressStats['this_week'] >= 5) {
                                    echo "Great week! You're building excellent workout habits.";
                                } elseif ($progressStats['this_week'] >= 3) {
                                    echo "Good progress! Try to add one more session this week.";
                                } else {
                                    echo "Start building momentum! Aim for at least 3 workouts this week.";
                                }
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize exercise progress charts
        <?php foreach ($chart_data as $exercise_name => $data): ?>
            const ctx<?php echo md5($exercise_name); ?> = document.getElementById('chart-<?php echo md5($exercise_name); ?>').getContext('2d');
            if (ctx<?php echo md5($exercise_name); ?>) {
                new Chart(ctx<?php echo md5($exercise_name); ?>, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($data['labels']); ?>,
                        datasets: [{
                            label: 'Weight (kg)',
                            data: <?php echo json_encode($data['weights']); ?>,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: true,
                                labels: {
                                    color: '#e2e8f0'
                                }
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false,
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                titleColor: '#fbbf24',
                                bodyColor: '#e2e8f0',
                                borderColor: '#fbbf24',
                                borderWidth: 1
                            }
                        },
                        scales: {
                            x: {
                                ticks: {
                                    color: '#9ca3af'
                                },
                                grid: {
                                    color: 'rgba(255, 255, 255, 0.1)'
                                }
                            },
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    color: '#9ca3af'
                                },
                                grid: {
                                    color: 'rgba(255, 255, 255, 0.1)'
                                }
                            }
                        }
                    }
                });
            }
        <?php endforeach; ?>
    });
</script>
<?php require_once 'includes/client_footer.php'; ?>



