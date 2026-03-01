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

// Function to get client's meal plans
function getClientMealPlans($conn, $user_id) {
    $mealPlans = [];
    try {
        $sql = "SELECT mp.*, u.full_name as trainer_name FROM meal_plans mp 
                INNER JOIN members m ON mp.member_id = m.id 
                INNER JOIN users u ON mp.created_by = u.id 
                WHERE m.user_id = ? ORDER BY mp.created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $row['meals'] = json_decode($row['meals'], true) ?? [];
            $mealPlans[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error fetching meal plans: " . $e->getMessage());
        $mealPlans = [];
    }
    
    return $mealPlans;
}

// Function to get today's completed meals
function getTodayCompletedMeals($conn, $user_id) {
    $today = date('Y-m-d');
    $completedMeals = [];
    
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
        
        if ($member) {
            $member_id = $member['id'];
            
            $sql = "SELECT completed_meals FROM nutrition_sessions 
                    WHERE member_id = ? AND session_date = ? 
                    ORDER BY created_at DESC LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("is", $member_id, $today);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $completedMeals = json_decode($row['completed_meals'], true) ?: [];
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching completed meals: " . $e->getMessage());
        $completedMeals = [];
    }
    
    return $completedMeals;
}

// Handle marking meal as done
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['mark_meal_done'])) {
    $meal_plan_id = intval($_POST['meal_plan_id']);
    $meal_name = trim($_POST['meal_name']);
    $today = date('Y-m-d');
    
    try {
        // Get member_id from user_id
        $member_sql = "SELECT m.id FROM members m 
                       INNER JOIN users u ON m.user_id = u.id 
                       WHERE u.id = ?";
        $stmt = $conn->prepare($member_sql);
        $stmt->bind_param("i", $logged_in_user_id);
        $stmt->execute();
        $member_result = $stmt->get_result();
        $member = $member_result->fetch_assoc();
        
        if ($member) {
            $member_id = $member['id'];
            
            // Check if nutrition session exists for today
            $check_session_sql = "SELECT * FROM nutrition_sessions 
                                  WHERE member_id = ? AND meal_plan_id = ? AND session_date = ?";
            $stmt = $conn->prepare($check_session_sql);
            $stmt->bind_param("iis", $member_id, $meal_plan_id, $today);
            $stmt->execute();
            $session_result = $stmt->get_result();
            $existing_session = $session_result->fetch_assoc();
            
            if ($existing_session) {
                // Update existing session
                $completed_meals = json_decode($existing_session['completed_meals'], true) ?: [];
                
                // Add meal if not already completed
                if (!in_array($meal_name, $completed_meals)) {
                    $completed_meals[] = $meal_name;
                    $completed_json = json_encode($completed_meals);
                    
                    $update_sql = "UPDATE nutrition_sessions SET completed_meals = ? WHERE id = ?";
                    $stmt = $conn->prepare($update_sql);
                    $stmt->bind_param("si", $completed_json, $existing_session['id']);
                    
                    if ($stmt->execute()) {
                        $success_message = "Meal '$meal_name' marked as completed!";
                    } else {
                        $error_message = "Error updating meal: " . $conn->error;
                    }
                } else {
                    $info_message = "Meal '$meal_name' is already completed for today!";
                }
            } else {
                // Create new session
                $completed_meals = [$meal_name];
                $completed_json = json_encode($completed_meals);
                
                $insert_sql = "INSERT INTO nutrition_sessions (member_id, meal_plan_id, session_date, completed_meals) 
                               VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($insert_sql);
                $stmt->bind_param("iiss", $member_id, $meal_plan_id, $today, $completed_json);
                
                if ($stmt->execute()) {
                    $success_message = "Meal '$meal_name' marked as completed!";
                } else {
                    $error_message = "Error creating nutrition session: " . $conn->error;
                }
            }
        } else {
            $error_message = "Member profile not found.";
        }
    } catch (Exception $e) {
        error_log("Error marking meal as done: " . $e->getMessage());
        $error_message = "Error completing meal. Please try again.";
    }
}

// Handle export request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_meal_plan'])) {
    $meal_plan_id = $_POST['meal_plan_id'];
    
    try {
        // Get meal plan details
        $sql = "SELECT mp.*, m.full_name as client_name, u.full_name as trainer_name 
                FROM meal_plans mp 
                INNER JOIN members m ON mp.member_id = m.id 
                INNER JOIN users u ON mp.created_by = u.id 
                WHERE mp.id = ? AND m.user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $meal_plan_id, $logged_in_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $plan = $result->fetch_assoc();
        
        if ($plan) {
            $plan['meals'] = json_decode($plan['meals'], true) ?? [];
            
            // Generate HTML for printable meal plan
            $html = generateMealPlanHTML($plan);
            
            // Output as downloadable HTML file
            header('Content-Type: text/html');
            header('Content-Disposition: attachment; filename="meal_plan_' . $plan['plan_name'] . '_' . date('Y-m-d') . '.html"');
            echo $html;
            exit;
        } else {
            $error_message = "Meal plan not found.";
        }
    } catch (Exception $e) {
        error_log("Error exporting meal plan: " . $e->getMessage());
        $error_message = "Error generating meal plan. Please try again.";
    }
}

// Function to generate printable meal plan HTML
function generateMealPlanHTML($plan) {
    $html = '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Meal Plan - ' . htmlspecialchars($plan['plan_name']) . '</title>
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
            .nutrition-goals {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 15px;
                margin-bottom: 20px;
            }
            .goal-card {
                background: white;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 15px;
                text-align: center;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            .goal-value {
                font-size: 1.5rem;
                font-weight: bold;
                color: #2c5aa0;
                margin-bottom: 5px;
            }
            .goal-label {
                font-size: 0.9rem;
                color: #666;
            }
            .meal-table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
            }
            .meal-table th {
                background: #2c5aa0;
                color: white;
                padding: 12px;
                text-align: left;
                border: 1px solid #ddd;
            }
            .meal-table td {
                padding: 12px;
                border: 1px solid #ddd;
            }
            .meal-table tr:nth-child(even) {
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
                .meal-table { break-inside: avoid; }
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>BOIYETS FITNESS GYM</h1>
            <h2>Nutrition & Meal Plan</h2>
            <h3>' . htmlspecialchars($plan['plan_name']) . '</h3>
        </div>
        
        <div class="plan-info">
            <p><strong>Client:</strong> ' . htmlspecialchars($plan['client_name']) . '</p>
            <p><strong>Trainer:</strong> ' . htmlspecialchars($plan['trainer_name']) . '</p>
            <p><strong>Date Printed:</strong> ' . date('F j, Y') . '</p>';
            
            if (!empty($plan['description'])) {
                $html .= '<p><strong>Description:</strong> ' . htmlspecialchars($plan['description']) . '</p>';
            }
            
    $html .= '</div>';
    
    // Nutrition Goals Section
    if (isset($plan['daily_calories']) || isset($plan['protein_goal']) || isset($plan['carbs_goal']) || isset($plan['fat_goal'])) {
        $html .= '<div class="nutrition-goals">';
        if (isset($plan['daily_calories'])) {
            $html .= '<div class="goal-card">
                <div class="goal-value">' . $plan['daily_calories'] . '</div>
                <div class="goal-label">Daily Calories</div>
            </div>';
        }
        if (isset($plan['protein_goal'])) {
            $html .= '<div class="goal-card">
                <div class="goal-value">' . $plan['protein_goal'] . 'g</div>
                <div class="goal-label">Protein</div>
            </div>';
        }
        if (isset($plan['carbs_goal'])) {
            $html .= '<div class="goal-card">
                <div class="goal-value">' . $plan['carbs_goal'] . 'g</div>
                <div class="goal-label">Carbohydrates</div>
            </div>';
        }
        if (isset($plan['fat_goal'])) {
            $html .= '<div class="goal-card">
                <div class="goal-value">' . $plan['fat_goal'] . 'g</div>
                <div class="goal-label">Fat</div>
            </div>';
        }
        $html .= '</div>';
    }
    
    $html .= '<table class="meal-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Meal</th>
                    <th>Time</th>
                    <th>Calories</th>
                    <th>Description</th>
                    <th class="checklist-column">Completed</th>
                </tr>
            </thead>
            <tbody>';
            
            foreach ($plan['meals'] as $index => $meal) {
                $html .= '<tr>
                    <td>' . ($index + 1) . '</td>
                    <td><strong>' . htmlspecialchars($meal['name']) . '</strong></td>
                    <td>' . (isset($meal['time']) ? htmlspecialchars($meal['time']) : 'N/A') . '</td>
                    <td>' . (isset($meal['calories']) ? $meal['calories'] . ' kcal' : 'N/A') . '</td>
                    <td>' . (isset($meal['description']) ? htmlspecialchars($meal['description']) : '-') . '</td>
                    <td class="checklist-column"><div class="checkbox"></div></td>
                </tr>';
            }
            
    $html .= '</tbody>
        </table>
        
        <div class="notes">
            <h4>Nutrition Notes:</h4>
            <p>________________________________________________________________</p>
            <p>________________________________________________________________</p>
            <p>________________________________________________________________</p>
        </div>
        
        <div class="footer">
            <p>Generated by BOIYETS FITNESS GYM Nutrition System</p>
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
$mealPlans = getClientMealPlans($conn, $logged_in_user_id);
$todayCompletedMeals = getTodayCompletedMeals($conn, $logged_in_user_id);

// Calculate statistics
$totalMealsToday = 0;
$completedMealsToday = 0;

foreach ($mealPlans as $plan) {
    if (isset($plan['meals']) && is_array($plan['meals'])) {
        $totalMealsToday += count($plan['meals']);
        foreach ($plan['meals'] as $meal) {
            if (in_array($meal['name'], $todayCompletedMeals)) {
                $completedMealsToday++;
            }
        }
    }
}

$progressPercentage = $totalMealsToday > 0 ? round(($completedMealsToday / $totalMealsToday) * 100) : 0;

// Calculate nutrition streak
$streak = 0;
try {
    $streak_sql = "SELECT COUNT(DISTINCT session_date) as streak 
                   FROM nutrition_sessions ns 
                   INNER JOIN meal_plans mp ON ns.meal_plan_id = mp.id 
                   INNER JOIN members m ON mp.member_id = m.id 
                   INNER JOIN users u ON m.user_id = u.id 
                   WHERE u.id = ? AND ns.session_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    $streak_stmt = $conn->prepare($streak_sql);
    $streak_stmt->bind_param("i", $logged_in_user_id);
    $streak_stmt->execute();
    $streak_result = $streak_stmt->get_result();
    $streak_data = $streak_result->fetch_assoc();
    $streak = $streak_data['streak'] ?? 0;
} catch (Exception $e) {
    error_log("Error calculating nutrition streak: " . $e->getMessage());
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
$page_title = 'My Nutrition Plans';
require_once 'includes/client_header.php'; 
?>
<style>
    /* Meal Styles */
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

    .completed-meal {
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

    <!-- Main Content -->
    <main id="mainContent" class="main-content flex-1 p-4 space-y-4 overflow-auto">
      <!-- Header Section -->
      <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
        <div>
          <h1 class="text-2xl font-bold text-yellow-400">My Nutrition Plans</h1>
          <p class="text-gray-400">Track your daily meals and monitor your nutrition goals</p>
        </div>
        <div class="flex gap-3">
          <a href="workoutplansclient.php" class="bg-yellow-500/20 text-yellow-400 px-4 py-2 rounded-lg flex items-center gap-2 hover:bg-yellow-500/30 transition-colors">
            <i data-lucide="dumbbell" class="w-4 h-4"></i>
            Workout Plans
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

      <?php if (!empty($info_message)): ?>
        <div class="bg-blue-500/20 border border-blue-500/30 text-blue-400 p-4 rounded-lg">
          <div class="flex items-center gap-2">
            <i data-lucide="info" class="w-5 h-5"></i>
            <?php echo $info_message; ?>
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
          <p class="text-xs text-gray-400 mt-1"><?php echo $completedMealsToday; ?> of <?php echo $totalMealsToday; ?> meals</p>
        </div>
        <div class="card">
          <p class="card-title"><i data-lucide="calendar"></i><span>Nutrition Streak</span></p>
          <p class="card-value"><?php echo $streak; ?> days</p>
          <p class="text-xs text-green-400 mt-1 flex items-center"><i data-lucide="flame" class="w-3 h-3 mr-1"></i>Consecutive days</p>
        </div>
        <div class="card">
          <p class="card-title"><i data-lucide="utensils"></i><span>Total Plans</span></p>
          <p class="card-value"><?php echo count($mealPlans); ?></p>
          <p class="text-xs text-gray-400 mt-1">Active nutrition plans</p>
        </div>
        <div class="card">
          <p class="card-title"><i data-lucide="trending-up"></i><span>Meals Today</span></p>
          <p class="card-value"><?php echo $completedMealsToday; ?></p>
          <p class="text-xs text-gray-400 mt-1">Completed meals</p>
        </div>
      </div>

      <!-- Meal Plans Section -->
      <div id="meal-plans" class="space-y-6">
        <div class="flex justify-between items-center">
          <h2 class="text-xl font-bold text-yellow-400 flex items-center gap-2">
            <i data-lucide="list"></i>
            My Meal Plans
          </h2>
          <span class="text-gray-400"><?php echo count($mealPlans); ?> active plans</span>
        </div>

        <?php if (!empty($mealPlans)): ?>
          <?php foreach ($mealPlans as $plan): ?>
            <div class="card">
              <div class="flex justify-between items-start mb-6">
                <div class="flex-1">
                  <div class="flex items-center gap-3 mb-2">
                    <h3 class="font-semibold text-white text-xl"><?php echo htmlspecialchars($plan['plan_name']); ?></h3>
                    <span class="badge badge-green">Active</span>
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
                  <!-- Export Meal Plan Button -->
                  <form method="POST" class="inline">
                    <input type="hidden" name="meal_plan_id" value="<?php echo $plan['id']; ?>">
                    <button type="submit" name="export_meal_plan" class="export-btn">
                      <i data-lucide="download" class="w-4 h-4"></i>
                      Export Plan
                    </button>
                  </form>
                </div>
              </div>

              <!-- Nutrition Goals -->
              <?php if (isset($plan['daily_calories']) || isset($plan['protein_goal']) || isset($plan['carbs_goal']) || isset($plan['fat_goal'])): ?>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                  <?php if (isset($plan['daily_calories'])): ?>
                    <div class="text-center bg-gray-800/50 rounded-lg p-4">
                      <div class="text-lg font-bold text-yellow-400"><?php echo $plan['daily_calories']; ?></div>
                      <div class="text-xs text-gray-400">Daily Calories</div>
                    </div>
                  <?php endif; ?>
                  <?php if (isset($plan['protein_goal'])): ?>
                    <div class="text-center bg-gray-800/50 rounded-lg p-4">
                      <div class="text-lg font-bold text-yellow-400"><?php echo $plan['protein_goal']; ?>g</div>
                      <div class="text-xs text-gray-400">Protein</div>
                    </div>
                  <?php endif; ?>
                  <?php if (isset($plan['carbs_goal'])): ?>
                    <div class="text-center bg-gray-800/50 rounded-lg p-4">
                      <div class="text-lg font-bold text-yellow-400"><?php echo $plan['carbs_goal']; ?>g</div>
                      <div class="text-xs text-gray-400">Carbs</div>
                    </div>
                  <?php endif; ?>
                  <?php if (isset($plan['fat_goal'])): ?>
                    <div class="text-center bg-gray-800/50 rounded-lg p-4">
                      <div class="text-lg font-bold text-yellow-400"><?php echo $plan['fat_goal']; ?>g</div>
                      <div class="text-xs text-gray-400">Fat</div>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <!-- Meals Table -->
              <div class="overflow-x-auto">
                <table class="w-full">
                  <thead>
                    <tr class="border-b border-gray-700">
                      <th class="text-left py-3 px-4 text-yellow-400 font-semibold">Meal</th>
                      <th class="text-left py-3 px-4 text-yellow-400 font-semibold">Time</th>
                      <th class="text-left py-3 px-4 text-yellow-400 font-semibold">Calories</th>
                      <th class="text-left py-3 px-4 text-yellow-400 font-semibold">Description</th>
                      <th class="text-left py-3 px-4 text-yellow-400 font-semibold">Status</th>
                      <th class="text-left py-3 px-4 text-yellow-400 font-semibold">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (isset($plan['meals']) && is_array($plan['meals'])): ?>
                      <?php foreach ($plan['meals'] as $index => $meal): ?>
                        <?php 
                        $isCompleted = in_array($meal['name'], $todayCompletedMeals);
                        ?>
                        <tr class="border-b border-gray-800 <?php echo $isCompleted ? 'completed-meal' : ''; ?>">
                          <td class="py-3 px-4">
                            <div class="flex items-center gap-2">
                              <span class="badge badge-green"><?php echo $index + 1; ?></span>
                              <span class="font-medium"><?php echo htmlspecialchars($meal['name']); ?></span>
                            </div>
                          </td>
                          <td class="py-3 px-4"><?php echo isset($meal['time']) ? $meal['time'] : 'N/A'; ?></td>
                          <td class="py-3 px-4"><?php echo isset($meal['calories']) ? $meal['calories'] . ' kcal' : 'N/A'; ?></td>
                          <td class="py-3 px-4 text-gray-400 text-sm">
                            <?php if (isset($meal['description']) && $meal['description']): ?>
                              <?php echo htmlspecialchars($meal['description']); ?>
                            <?php else: ?>
                              <span class="text-gray-500">-</span>
                            <?php endif; ?>
                          </td>
                          <td class="py-3 px-4">
                            <?php if ($isCompleted): ?>
                              <span class="badge badge-green">Completed</span>
                            <?php else: ?>
                              <span class="badge badge-yellow">Pending</span>
                            <?php endif; ?>
                          </td>
                          <td class="py-3 px-4">
                            <?php if (!$isCompleted): ?>
                              <form method="POST" class="inline">
                                <input type="hidden" name="meal_plan_id" value="<?php echo $plan['id']; ?>">
                                <input type="hidden" name="meal_name" value="<?php echo htmlspecialchars($meal['name']); ?>">
                                <button type="submit" name="mark_meal_done" class="mark-done-btn flex items-center gap-2">
                                  <i data-lucide="check" class="w-4 h-4"></i>
                                  Mark Done
                                </button>
                              </form>
                            <?php else: ?>
                              <button disabled class="bg-gray-600 text-white px-3 py-2 rounded-lg flex items-center gap-2 cursor-not-allowed">
                                <i data-lucide="check" class="w-4 h-4"></i>
                                Completed
                              </button>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <tr>
                        <td colspan="6" class="py-4 px-4 text-center text-gray-500">
                          No meals found in this plan.
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
            <i data-lucide="utensils" class="w-16 h-16 mx-auto mb-4"></i>
            <h3 class="text-xl font-semibold text-gray-400 mb-2">No Meal Plans Assigned</h3>
            <p class="text-gray-500">Your trainer will assign personalized nutrition plans soon.</p>
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



