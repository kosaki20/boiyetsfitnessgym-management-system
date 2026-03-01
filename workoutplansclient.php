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
$success_message = $error_message = $info_message = '';

// Add mobile-specific caching headers if needed
if (strpos($_SERVER['HTTP_USER_AGENT'], 'Mobile') !== false) {
    header("Cache-Control: max-age=300"); // 5 minutes for mobile
}

// Function to get client details by user_id
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

// Function to get client's workout plans with exercises
function getClientWorkoutPlans($conn, $user_id) {
    $workoutPlans = [];
    try {
        $sql = "SELECT wp.*, u.full_name as trainer_name FROM workout_plans wp 
                INNER JOIN members m ON wp.member_id = m.id 
                INNER JOIN users u ON wp.created_by = u.id 
                WHERE m.user_id = ? ORDER BY wp.created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $row['exercises'] = json_decode($row['exercises'], true) ?? [];
            $workoutPlans[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error fetching workout plans: " . $e->getMessage());
        $workoutPlans = [];
    }
    return $workoutPlans;
}

// Function to get today's completed exercises
function getTodayCompletedExercises($conn, $user_id) {
    $completed = [];
    $today = date('Y-m-d');
    
    try {
        $sql = "SELECT ws.completed_exercises, ws.exercise_weights, wp.id as plan_id 
                FROM workout_sessions ws 
                INNER JOIN workout_plans wp ON ws.workout_plan_id = wp.id 
                INNER JOIN members m ON wp.member_id = m.id 
                WHERE m.user_id = ? AND ws.session_date = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $user_id, $today);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            // Store JSON data in variables first
            $completed_exercises_json = $row['completed_exercises'];
            $exercise_weights_json = $row['exercise_weights'];
            
            $exercises = json_decode($completed_exercises_json, true) ?? [];
            $weights = json_decode($exercise_weights_json, true) ?? [];
            
            foreach ($exercises as $index => $exercise) {
                $key = $row['plan_id'] . '_' . $exercise;
                $completed[$key] = [
                    'completed' => true,
                    'weight' => $weights[$index] ?? null
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching completed exercises: " . $e->getMessage());
        $completed = [];
    }
    
    return $completed;
}

// Handle exercise completion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_done'])) {
    $workout_plan_id = $_POST['workout_plan_id'];
    $exercise_name = $_POST['exercise_name'];
    $weight_used = $_POST['weight_used'];
    $today = date('Y-m-d');

    try {
        // Get member_id from user_id
        $member_sql = "SELECT m.id FROM members m WHERE m.user_id = ?";
        $member_stmt = $conn->prepare($member_sql);
        $member_stmt->bind_param("i", $logged_in_user_id);
        $member_stmt->execute();
        $member_result = $member_stmt->get_result();
        $member = $member_result->fetch_assoc();
        
        if ($member) {
            $member_id = $member['id'];
            
            // Check if session exists for today
            $session_sql = "SELECT id, completed_exercises, exercise_weights FROM workout_sessions WHERE member_id = ? AND workout_plan_id = ? AND session_date = ?";
            $session_stmt = $conn->prepare($session_sql);
            $session_stmt->bind_param("iis", $member_id, $workout_plan_id, $today);
            $session_stmt->execute();
            $session_result = $session_stmt->get_result();
            $session = $session_result->fetch_assoc();

            if ($session) {
                // Update existing session
                $completed_exercises = json_decode($session['completed_exercises'], true) ?? [];
                $exercise_weights = json_decode($session['exercise_weights'], true) ?? [];
                
                // Add new exercise and weight
                $completed_exercises[] = $exercise_name;
                $exercise_weights[] = $weight_used;
                
                // Update session
                $update_sql = "UPDATE workout_sessions SET completed_exercises = ?, exercise_weights = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ssi", json_encode($completed_exercises), json_encode($exercise_weights), $session['id']);
                if ($update_stmt->execute()) {
                    $success_message = "Exercise marked as completed!";
                } else {
                    $error_message = "Error updating exercise: " . $conn->error;
                }
            } else {
                // Create new session
                $completed_exercises = json_encode([$exercise_name]);
                $exercise_weights = json_encode([$weight_used]);
                
                $insert_sql = "INSERT INTO workout_sessions (member_id, workout_plan_id, session_date, completed_exercises, exercise_weights) VALUES (?, ?, ?, ?, ?)";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param("iisss", $member_id, $workout_plan_id, $today, $completed_exercises, $exercise_weights);
                if ($insert_stmt->execute()) {
                    $success_message = "Exercise marked as completed!";
                } else {
                    $error_message = "Error creating workout session: " . $conn->error;
                }
            }
        } else {
            $error_message = "Member profile not found.";
        }
    } catch (Exception $e) {
        error_log("Error marking exercise as done: " . $e->getMessage());
        $error_message = "Error completing exercise. Please try again.";
    }
}

// Handle exercise removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_exercise'])) {
    $workout_plan_id = $_POST['workout_plan_id'];
    $exercise_name = $_POST['exercise_name'];
    $today = date('Y-m-d');

    try {
        // Get member_id from user_id
        $member_sql = "SELECT m.id FROM members m WHERE m.user_id = ?";
        $member_stmt = $conn->prepare($member_sql);
        $member_stmt->bind_param("i", $logged_in_user_id);
        $member_stmt->execute();
        $member_result = $member_stmt->get_result();
        $member = $member_result->fetch_assoc();
        
        if ($member) {
            $member_id = $member['id'];
            
            $session_sql = "SELECT id, completed_exercises, exercise_weights FROM workout_sessions WHERE member_id = ? AND workout_plan_id = ? AND session_date = ?";
            $session_stmt = $conn->prepare($session_sql);
            $session_stmt->bind_param("iis", $member_id, $workout_plan_id, $today);
            $session_stmt->execute();
            $session_result = $session_stmt->get_result();
            $session = $session_result->fetch_assoc();

            if ($session) {
                $completed_exercises = json_decode($session['completed_exercises'], true) ?? [];
                $exercise_weights = json_decode($session['exercise_weights'], true) ?? [];
                
                // Remove exercise from arrays
                $exercise_index = array_search($exercise_name, $completed_exercises);
                if ($exercise_index !== false) {
                    unset($completed_exercises[$exercise_index]);
                    unset($exercise_weights[$exercise_index]);
                    
                    // Reindex arrays
                    $completed_exercises = array_values($completed_exercises);
                    $exercise_weights = array_values($exercise_weights);
                    
                    $update_sql = "UPDATE workout_sessions SET completed_exercises = ?, exercise_weights = ? WHERE id = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param("ssi", json_encode($completed_exercises), json_encode($exercise_weights), $session['id']);
                    if ($update_stmt->execute()) {
                        $success_message = "Exercise removed from completed list!";
                    } else {
                        $error_message = "Error removing exercise: " . $conn->error;
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error removing exercise: " . $e->getMessage());
        $error_message = "Error removing exercise. Please try again.";
    }
}

// Handle export request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_checklist'])) {
    $workout_plan_id = $_POST['workout_plan_id'];
    
    try {
        // Get workout plan details
        $sql = "SELECT wp.*, m.full_name as client_name, u.full_name as trainer_name 
                FROM workout_plans wp 
                INNER JOIN members m ON wp.member_id = m.id 
                INNER JOIN users u ON wp.created_by = u.id 
                WHERE wp.id = ? AND m.user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $workout_plan_id, $logged_in_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $plan = $result->fetch_assoc();
        
        if ($plan) {
            $plan['exercises'] = json_decode($plan['exercises'], true) ?? [];
            
            // Generate HTML for printable checklist
            $html = generateChecklistHTML($plan);
            
            // Output as downloadable HTML file
            header('Content-Type: text/html');
            header('Content-Disposition: attachment; filename="workout_checklist_' . $plan['plan_name'] . '_' . date('Y-m-d') . '.html"');
            echo $html;
            exit;
        } else {
            $error_message = "Workout plan not found.";
        }
    } catch (Exception $e) {
        error_log("Error exporting checklist: " . $e->getMessage());
        $error_message = "Error generating checklist. Please try again.";
    }
}

// Function to generate printable checklist HTML
function generateChecklistHTML($plan) {
    $html = '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Workout Checklist - ' . htmlspecialchars($plan['plan_name']) . '</title>
        <style>
            body { 
                font-family: Arial, sans-serif; 
                margin: 20px; 
                line-height: 1.6;
                color: #333;
            }
            .header { 
                text-align: center; 
                margin-bottom: 30px;
                border-bottom: 3px solid #333;
                padding-bottom: 20px;
            }
            .header h1 { 
                color: #2c5aa0; 
                margin: 0;
                font-size: 28px;
            }
            .header h2 {
                color: #666;
                margin: 5px 0;
                font-size: 18px;
                font-weight: normal;
            }
            .plan-info {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 8px;
                margin-bottom: 20px;
                border-left: 4px solid #2c5aa0;
            }
            .exercise-table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
            }
            .exercise-table th {
                background: #2c5aa0;
                color: white;
                padding: 12px;
                text-align: left;
                border: 1px solid #ddd;
            }
            .exercise-table td {
                padding: 12px;
                border: 1px solid #ddd;
            }
            .exercise-table tr:nth-child(even) {
                background: #f8f9fa;
            }
            .checklist-column {
                width: 80px;
                text-align: center;
            }
            .checkbox {
                width: 20px;
                height: 20px;
                border: 2px solid #333;
                display: inline-block;
                cursor: pointer;
            }
            .notes {
                margin-top: 30px;
                padding: 15px;
                background: #fff3cd;
                border: 1px solid #ffeaa7;
                border-radius: 5px;
            }
            .footer {
                margin-top: 40px;
                text-align: center;
                color: #666;
                font-size: 12px;
                border-top: 1px solid #ddd;
                padding-top: 20px;
            }
            @media print {
                body { margin: 0; }
                .header { border-bottom: 2px solid #000; }
                .exercise-table { break-inside: avoid; }
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>BOIYETS FITNESS GYM</h1>
            <h2>Workout Checklist</h2>
            <h3>' . htmlspecialchars($plan['plan_name']) . '</h3>
        </div>
        
        <div class="plan-info">
            <p><strong>Client:</strong> ' . htmlspecialchars($plan['client_name']) . '</p>
            <p><strong>Trainer:</strong> ' . htmlspecialchars($plan['trainer_name']) . '</p>
            <p><strong>Schedule:</strong> ' . ucfirst($plan['schedule']) . '</p>
            <p><strong>Date Printed:</strong> ' . date('F j, Y') . '</p>';
            
            if (!empty($plan['description'])) {
                $html .= '<p><strong>Description:</strong> ' . htmlspecialchars($plan['description']) . '</p>';
            }
            
    $html .= '</div>
        
        <table class="exercise-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Exercise</th>
                    <th>Sets</th>
                    <th>Reps</th>
                    <th>Rest</th>
                    <th>Weight</th>
                    <th class="checklist-column">Completed</th>
                </tr>
            </thead>
            <tbody>';
            
            foreach ($plan['exercises'] as $index => $exercise) {
                $html .= '<tr>
                    <td>' . ($index + 1) . '</td>
                    <td><strong>' . htmlspecialchars($exercise['name']) . '</strong>' . 
                    (!empty($exercise['notes']) ? '<br><small>' . htmlspecialchars($exercise['notes']) . '</small>' : '') . '</td>
                    <td>' . (isset($exercise['sets']) ? $exercise['sets'] : 'N/A') . '</td>
                    <td>' . (isset($exercise['reps']) ? $exercise['reps'] : 'N/A') . '</td>
                    <td>' . (isset($exercise['rest']) ? $exercise['rest'] . 's' : 'N/A') . '</td>
                    <td>_________ kg</td>
                    <td class="checklist-column"><div class="checkbox"></div></td>
                </tr>';
            }
            
    $html .= '</tbody>
        </table>
        
        <div class="notes">
            <h4>Workout Notes:</h4>
            <p>________________________________________________________________</p>
            <p>________________________________________________________________</p>
            <p>________________________________________________________________</p>
        </div>
        
        <div class="footer">
            <p>Generated by BOIYETS FITNESS GYM Workout System</p>
            <p>Print Date: ' . date('F j, Y g:i A') . '</p>
        </div>
        
        <script>
            // Auto-print when loaded
            window.onload = function() {
                window.print();
            };
        </script>
    </body>
    </html>';
    
    return $html;
}

// Get all data
$client = getClientDetails($conn, $logged_in_user_id);
$workoutPlans = getClientWorkoutPlans($conn, $logged_in_user_id);
$completedExercises = getTodayCompletedExercises($conn, $logged_in_user_id);

// Calculate statistics
$totalExercisesToday = 0;
$completedExercisesToday = 0;

foreach ($workoutPlans as $plan) {
    if (isset($plan['exercises']) && is_array($plan['exercises'])) {
        $totalExercisesToday += count($plan['exercises']);
        foreach ($plan['exercises'] as $exercise) {
            $exerciseKey = $plan['id'] . '_' . $exercise['name'];
            if (isset($completedExercises[$exerciseKey])) {
                $completedExercisesToday++;
            }
        }
    }
}

$progressPercentage = $totalExercisesToday > 0 ? round(($completedExercisesToday / $totalExercisesToday) * 100) : 0;

// Calculate streak
$streak = 0;
try {
    $streak_sql = "SELECT COUNT(DISTINCT session_date) as streak 
                   FROM workout_sessions ws 
                   INNER JOIN workout_plans wp ON ws.workout_plan_id = wp.id 
                   INNER JOIN members m ON wp.member_id = m.id 
                   WHERE m.user_id = ? AND ws.session_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    $streak_stmt = $conn->prepare($streak_sql);
    $streak_stmt->bind_param("i", $logged_in_user_id);
    $streak_stmt->execute();
    $streak_result = $streak_stmt->get_result();
    $streak_data = $streak_result->fetch_assoc();
    $streak = $streak_data['streak'] ?? 0;
} catch (Exception $e) {
    error_log("Error calculating streak: " . $e->getMessage());
    $streak = 0;
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
$page_title = 'My Workout Plans';
require_once 'includes/client_header.php'; 
?>
<style>
    .badge {
      padding: 0.25rem 0.75rem;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 500;
    }
    
    .badge-blue { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }
    .badge-yellow { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
    .badge-green { background: rgba(16, 185, 129, 0.2); color: #10b981; }
    .badge-purple { background: rgba(139, 92, 246, 0.2); color: #8b5cf6; }
    .badge-red { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
    
    .progress-bar {
      background: rgba(255, 255, 255, 0.1);
      border-radius: 10px;
      overflow: hidden;
      height: 12px;
    }
    
    .progress-fill {
      background: linear-gradient(90deg, #10b981, #059669);
      height: 100%;
      transition: width 0.5s ease;
    }
    
    .weight-input {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 6px;
      padding: 0.5rem;
      color: white;
      width: 80px;
      text-align: center;
    }
    
    .weight-input:focus {
      outline: none;
      border-color: #fbbf24;
    }
    
    .mark-done-btn {
      background: linear-gradient(135deg, #10b981, #059669);
      color: white;
      border: none;
      padding: 0.5rem 1rem;
      border-radius: 6px;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    
    .mark-done-btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 6px rgba(16, 185, 129, 0.3);
    }

    .completed-exercise {
      background: rgba(16, 185, 129, 0.1);
    }

    /* Export Button Styles */
    .export-btn {
      background: linear-gradient(135deg, #3b82f6, #1d4ed8);
      color: white;
      border: none;
      padding: 0.5rem 1rem;
      border-radius: 6px;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.2s ease;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    
    .export-btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 6px rgba(59, 130, 246, 0.3);
    }
</style>

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
            <a href="workoutplansclient.php" class="text-yellow-400" onclick="closeMobileSidebar()"><i data-lucide="list"></i> My Workout Plans</a>
            <a href="workoutprogress.php" onclick="closeMobileSidebar()"><i data-lucide="activity"></i> Workout Progress</a>
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
    <main id="mainContent" class="main-content flex-1 p-4 space-y-4 overflow-auto">
      <!-- Header Section -->
      <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
        <div>
          <h1 class="text-2xl font-bold text-yellow-400">My Workout Plans</h1>
          <p class="text-gray-400">Track your daily workouts and monitor your progress</p>
        </div>
        <div class="flex gap-3">
          <a href="nutritionplansclient.php" class="bg-yellow-500/20 text-yellow-400 px-4 py-2 rounded-lg flex items-center gap-2 hover:bg-yellow-500/30 transition-colors">
            <i data-lucide="utensils" class="w-4 h-4"></i>
            Meal Plans
          </a>
        </div>
      </div>

      <!-- Messages -->
      <?php if (!empty($success_message)): ?>
        <div class="bg-green-500/20 border border-green-500/30 text-green-400 p-4 rounded-lg">
          <div class="flex items-center gap-2">
            <i data-lucide="check-circle" class="w-5 h-5"></i>
            <?php echo $success_message; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!empty($error_message)): ?>
        <div class="bg-red-500/20 border border-red-500/30 text-red-400 p-4 rounded-lg">
          <div class="flex items-center gap-2">
            <i data-lucide="alert-circle" class="w-5 h-5"></i>
            <?php echo $error_message; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Stats Cards -->
      <div class="stats-grid">
        <div class="card">
          <p class="card-title"><i data-lucide="target"></i><span>Today's Progress</span></p>
          <p class="card-value"><?php echo $progressPercentage; ?>%</p>
          <div class="progress-bar mt-2">
            <div class="progress-fill" style="width: <?php echo $progressPercentage; ?>%"></div>
          </div>
          <p class="text-xs text-gray-400 mt-1"><?php echo $completedExercisesToday; ?> of <?php echo $totalExercisesToday; ?> exercises</p>
        </div>
        <div class="card">
          <p class="card-title"><i data-lucide="calendar"></i><span>Current Streak</span></p>
          <p class="card-value"><?php echo $streak; ?> days</p>
          <p class="text-xs text-green-400 mt-1 flex items-center"><i data-lucide="flame" class="w-3 h-3 mr-1"></i>Consecutive days</p>
        </div>
        <div class="card">
          <p class="card-title"><i data-lucide="dumbbell"></i><span>Total Plans</span></p>
          <p class="card-value"><?php echo count($workoutPlans); ?></p>
          <p class="text-xs text-gray-400 mt-1">Active workout plans</p>
        </div>
        <div class="card">
          <p class="card-title"><i data-lucide="trending-up"></i><span>Exercises Today</span></p>
          <p class="card-value"><?php echo $completedExercisesToday; ?></p>
          <p class="text-xs text-gray-400 mt-1">Completed exercises</p>
        </div>
      </div>

      <!-- YouTube Resources -->
      <div class="card mb-8">
        <div class="flex justify-between items-center mb-4">
          <h2 class="text-xl font-bold text-yellow-400 flex items-center gap-2">
            <i data-lucide="book-open"></i>
            Workout Resources
          </h2>
        </div>
        <div class="bg-red-500/10 border-2 border-red-500/30 rounded-xl p-6">
          <div class="flex items-center gap-4 mb-4">
            <i data-lucide="youtube" class="w-12 h-12 text-red-500"></i>
            <div>
              <h3 class="text-xl font-bold text-white">Workout & Exercise Guides</h3>
              <p class="text-gray-300">Access our library of workout tutorials, exercise techniques, and training guidance</p>
            </div>
          </div>
          <a href="https://www.youtube.com/@BoiyetsFitnessGym" target="_blank" class="bg-red-500 text-white px-6 py-3 rounded-lg flex items-center gap-2 hover:bg-red-600 transition-colors w-fit">
            <i data-lucide="play" class="w-5 h-5"></i>
            Explore Workout Videos
          </a>
        </div>
      </div>

      <!-- Workout Plans Section -->
      <div id="workout-plans" class="space-y-6">
        <div class="flex justify-between items-center">
          <h2 class="text-xl font-bold text-yellow-400 flex items-center gap-2">
            <i data-lucide="list"></i>
            My Workout Plans
          </h2>
          <span class="text-gray-400"><?php echo count($workoutPlans); ?> active plans</span>
        </div>

        <?php if (!empty($workoutPlans)): ?>
          <?php foreach ($workoutPlans as $plan): ?>
            <div class="card">
              <div class="flex justify-between items-start mb-6">
                <div class="flex-1">
                  <div class="flex items-center gap-3 mb-2">
                    <h3 class="font-semibold text-white text-xl"><?php echo htmlspecialchars($plan['plan_name']); ?></h3>
                    <span class="badge badge-green">Active</span>
                    <?php if (isset($plan['schedule'])): ?>
                      <span class="badge badge-blue"><?php echo ucfirst($plan['schedule']); ?></span>
                    <?php endif; ?>
                  </div>
                  <?php if (isset($plan['description'])): ?>
                    <p class="text-gray-300 text-sm mb-3"><?php echo htmlspecialchars($plan['description']); ?></p>
                  <?php endif; ?>
                  <div class="text-sm text-gray-400">
                    Created: <?php echo date('M j, Y', strtotime($plan['created_at'])); ?>
                    <?php if (isset($plan['trainer_name'])): ?>
                      <span class="ml-4">by <?php echo htmlspecialchars($plan['trainer_name']); ?></span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="flex gap-2">
                  <!-- Export Checklist Button -->
                  <form method="POST" class="inline">
                    <input type="hidden" name="workout_plan_id" value="<?php echo $plan['id']; ?>">
                    <button type="submit" name="export_checklist" class="export-btn">
                      <i data-lucide="download" class="w-4 h-4"></i>
                      Export Checklist
                    </button>
                  </form>
                </div>
              </div>
              
              <!-- Exercises Table -->
              <div class="overflow-x-auto">
                <table class="w-full">
                  <thead>
                    <tr class="border-b border-gray-700">
                      <th class="text-left py-3 px-4 text-yellow-400 font-semibold">Exercise</th>
                      <th class="text-left py-3 px-4 text-yellow-400 font-semibold">Sets</th>
                      <th class="text-left py-3 px-4 text-yellow-400 font-semibold">Reps</th>
                      <th class="text-left py-3 px-4 text-yellow-400 font-semibold">Rest</th>
                      <th class="text-left py-3 px-4 text-yellow-400 font-semibold">Status</th>
                      <th class="text-left py-3 px-4 text-yellow-400 font-semibold">Weight Used</th>
                      <th class="text-left py-3 px-4 text-yellow-400 font-semibold">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (isset($plan['exercises']) && is_array($plan['exercises'])): ?>
                      <?php foreach ($plan['exercises'] as $index => $exercise): ?>
                        <?php 
                        $exerciseKey = $plan['id'] . '_' . $exercise['name'];
                        $isCompleted = isset($completedExercises[$exerciseKey]);
                        $weightUsed = $isCompleted ? $completedExercises[$exerciseKey]['weight'] : null;
                        ?>
                        <tr class="border-b border-gray-800 <?php echo $isCompleted ? 'completed-exercise' : ''; ?>">
                          <td class="py-3 px-4">
                            <div class="flex items-center gap-2">
                              <span class="badge badge-green"><?php echo $index + 1; ?></span>
                              <span class="font-medium"><?php echo htmlspecialchars($exercise['name']); ?></span>
                            </div>
                            <?php if (!empty($exercise['notes'])): ?>
                              <div class="text-xs text-gray-400 mt-1">
                                <i data-lucide="info" class="w-3 h-3 inline mr-1"></i>
                                <?php echo htmlspecialchars($exercise['notes']); ?>
                              </div>
                            <?php endif; ?>
                          </td>
                          <td class="py-3 px-4"><?php echo isset($exercise['sets']) ? $exercise['sets'] : 'N/A'; ?></td>
                          <td class="py-3 px-4"><?php echo isset($exercise['reps']) ? $exercise['reps'] : 'N/A'; ?></td>
                          <td class="py-3 px-4"><?php echo isset($exercise['rest']) ? $exercise['rest'] . 's' : 'N/A'; ?></td>
                          <td class="py-3 px-4">
                            <?php if ($isCompleted): ?>
                              <span class="badge badge-green">Completed</span>
                            <?php else: ?>
                              <span class="badge badge-yellow">Pending</span>
                            <?php endif; ?>
                          </td>
                          <td class="py-3 px-4">
                            <?php if ($isCompleted && $weightUsed): ?>
                              <span class="text-white font-semibold"><?php echo $weightUsed; ?> kg</span>
                            <?php else: ?>
                              <span class="text-gray-500">-</span>
                            <?php endif; ?>
                          </td>
                          <td class="py-3 px-4">
                            <?php if (!$isCompleted): ?>
                              <form method="POST" class="flex items-center gap-2">
                                <input type="hidden" name="workout_plan_id" value="<?php echo $plan['id']; ?>">
                                <input type="hidden" name="exercise_name" value="<?php echo htmlspecialchars($exercise['name']); ?>">
                                <input type="number" name="weight_used" placeholder="Weight (kg)" step="0.5" min="0" class="weight-input" required>
                                <button type="submit" name="mark_done" class="mark-done-btn flex items-center gap-2">
                                  <i data-lucide="check" class="w-4 h-4"></i>
                                  Mark Done
                                </button>
                              </form>
                            <?php else: ?>
                              <form method="POST">
                                <input type="hidden" name="workout_plan_id" value="<?php echo $plan['id']; ?>">
                                <input type="hidden" name="exercise_name" value="<?php echo htmlspecialchars($exercise['name']); ?>">
                                <button type="submit" name="remove_exercise" class="bg-red-500/20 text-red-400 px-3 py-2 rounded-lg flex items-center gap-2 hover:bg-red-500/30 transition-colors">
                                  <i data-lucide="x" class="w-4 h-4"></i>
                                  Remove
                                </button>
                              </form>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <tr>
                        <td colspan="7" class="py-4 px-4 text-center text-gray-500">
                          No exercises found in this plan.
                        </td>
                      </tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="empty-state">
            <i data-lucide="dumbbell" class="w-16 h-16 mx-auto mb-4"></i>
            <h3 class="text-xl font-semibold text-gray-400 mb-2">No Workout Plans Assigned</h3>
            <p class="text-gray-500">Your trainer will assign personalized workout plans soon.</p>
            <div class="flex gap-3 justify-center mt-4">
              <a href="client_dashboard.php" class="bg-yellow-500/20 text-yellow-400 px-4 py-2 rounded-lg flex items-center gap-2 hover:bg-yellow-500/30 transition-colors">
                <i data-lucide="home" class="w-4 h-4"></i>
                Back to Dashboard
              </a>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </main>
  </div>

<?php require_once 'includes/client_footer.php'; ?>
</body>
</html>
<?php
$conn->close();
?>



