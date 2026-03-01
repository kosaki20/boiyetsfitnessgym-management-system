<?php
session_start();
if (!isset($_SESSION['username']) || $_SESSION['role'] != 'client') {
    header("Location: index.php");
    exit();
}

// Database connection
$servername = "localhost";
$username = "root";  // full Hostinger DB username
$password = "";           // your Hostinger DB password
$dbname = "boiyetsdb";         // full Hostinger DB name

$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
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
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BOIYETS FITNESS GYM - My Nutrition Plans</title>
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
    
    /* Mobile Sidebar Styles */
    .mobile-sidebar {
      transform: translateX(-100%);
      transition: transform 0.3s ease-in-out;
      z-index: 50;
      display: none; /* Hidden by default */
      will-change: transform;
    }
    
    .mobile-sidebar.open {
      transform: translateX(0);
    }
    
    .overlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.5);
      z-index: 40;
    }
    
    .overlay.active {
      display: block;
    }
    
    .sidebar { 
      flex-shrink: 0; 
      transition: all 0.3s ease;
      overflow-y: auto;
      -ms-overflow-style: none;
      scrollbar-width: none;
      height: calc(100vh - 64px);
    }
    
    .sidebar::-webkit-scrollbar {
      display: none;
    }
    
    .tooltip {
      position: absolute;
      left: 100%;
      top: 50%;
      transform: translateY(-50%) translateX(-10px);
      margin-left: 6px;
      background: rgba(0,0,0,0.9);
      color: #fff;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 0.75rem;
      white-space: nowrap;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.2s ease, transform 0.2s ease;
      z-index: 50;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }
    
    .sidebar-collapsed .sidebar-item .text-sm,
    .sidebar-collapsed .submenu { 
      display: none; 
    }
    
    .sidebar-collapsed .sidebar-item { 
      justify-content: center; 
      padding: 0.6rem;
      min-height: 44px;
    }
    
    .sidebar-collapsed .sidebar-item i { 
      margin: 0; 
    }
    
    .sidebar-collapsed .sidebar-item:hover .tooltip { 
      opacity: 1; 
      transform: translateY(-50%) translateX(0); 
    }

    .sidebar-item {
      position: relative;
      display: flex;
      align-items: center;
      justify-content: space-between;
      color: #9ca3af;
      padding: 0.6rem 0.8rem;
      border-radius: 8px;
      cursor: pointer;
      transition: all 0.2s ease;
      margin-bottom: 0.25rem;
      min-height: 44px;
    }
    
    .sidebar-item.active {
      color: #fbbf24;
      background: rgba(251, 191, 36, 0.12);
    }
    
    .sidebar-item.active::before {
      content: "";
      position: absolute;
      left: 0;
      top: 20%;
      height: 60%;
      width: 3px;
      background: #fbbf24;
      border-radius: 4px;
    }
    
    /* Remove hover effects on mobile */
    @media (min-width: 769px) {
      .sidebar-item:hover { 
        background: rgba(255,255,255,0.05); 
        color: #fbbf24; 
      }
      
      .submenu a:hover { 
        color: #fbbf24; 
        background: rgba(255,255,255,0.05); 
      }
    }
    
    .sidebar-item i { 
      width: 18px; 
      height: 18px; 
      stroke-width: 1.75; 
      flex-shrink: 0; 
      margin-right: 0.75rem; 
    }

    .submenu {
      margin-left: 2.2rem;
      max-height: 0;
      opacity: 0;
      overflow: hidden;
      transition: max-height 0.3s ease, opacity 0.3s ease, margin-top 0.3s ease;
    }
    
    .submenu.open { 
      max-height: 500px; 
      opacity: 1; 
      margin-top: 0.25rem; 
    }
    
    .submenu a {
      display: flex;
      align-items: center;
      padding: 0.4rem 0.6rem;
      font-size: 0.8rem;
      color: #9ca3af;
      border-radius: 6px;
      transition: all 0.2s ease;
      min-height: 44px;
    }
    
    .submenu a i { 
      width: 14px; 
      height: 14px; 
      margin-right: 0.5rem; 
    }

    .chevron { 
      transition: transform 0.3s ease; 
    }
    
    .chevron.rotate { 
      transform: rotate(90deg); 
    }

    .card {
      background: rgba(26, 26, 26, 0.7);
      border-radius: 12px;
      padding: 1rem;
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.05);
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
      transition: all 0.2s ease;
      will-change: transform;
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
    
    .main-content {
      flex: 1;
      overflow-y: auto;
      height: calc(100vh - 64px);
    }
    
    .empty-state {
      text-align: center;
      padding: 3rem 1rem;
      color: #6b7280;
    }
    
    .empty-state i {
      margin-bottom: 1rem;
      opacity: 0.5;
    }

    /* Mobile Navigation */
    .mobile-nav {
      display: none;
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

    /* Loading skeleton */
    .skeleton {
      background: linear-gradient(90deg, #2d2d2d 25%, #3d3d3d 50%, #2d2d2d 75%);
      background-size: 200% 100%;
      animation: loading 1.5s infinite;
    }
    
    @keyframes loading {
      0% { background-position: 200% 0; }
      100% { background-position: -200% 0; }
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
      .desktop-sidebar {
        display: none !important; /* Force hide on mobile */
      }
      
      .mobile-sidebar {
        display: block; /* Show mobile sidebar on mobile */
      }
      
      .mobile-nav {
        display: flex;
      }
      
      .main-content {
        padding-bottom: 80px;
        height: calc(100vh - 144px);
      }
      
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
      
      /* Hide hover effects on mobile */
      .card:hover {
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        transform: none;
      }
    }
    
    @media (max-width: 480px) {
      .stats-grid {
        grid-template-columns: 1fr;
      }
      
      .card-title {
        font-size: 0.8rem;
      }
      
      .card-value {
        font-size: 1.1rem;
      }
    }
  </style>
</head>
<body class="min-h-screen js-enabled">
  <!-- Loading Skeleton -->
  <div id="loadingSkeleton" class="hidden">
    <div class="card">
      <div class="animate-pulse">
        <div class="h-4 bg-gray-700 rounded w-1/4 mb-2 skeleton"></div>
        <div class="h-6 bg-gray-700 rounded w-1/2 skeleton"></div>
      </div>
    </div>
  </div>

  <!-- Mobile Overlay -->
  <div class="overlay" id="mobileOverlay"></div>

   <!-- Topbar with Enhanced Notification & Profile -->
  <header class="topbar flex items-center justify-between px-4 py-3 shadow">
    <div class="flex items-center space-x-3">
      <button id="toggleSidebar" class="text-gray-300 hover:text-yellow-400 transition-colors p-1 rounded-lg hover:bg-white/5" aria-label="Toggle sidebar" aria-expanded="false" aria-controls="desktopSidebar">
        <i data-lucide="menu" class="w-5 h-5"></i>
      </button>
      <h1 class="text-lg font-bold text-yellow-400">BOIYETS FITNESS GYM</h1>
    </div>
    <div class="flex items-center space-x-3">
      <!-- Client Type Badge -->
     
      
      <!-- Chat Button -->
      <a href="chat.php" class="text-gray-300 hover:text-blue-400 transition-colors p-2 rounded-lg hover:bg-white/5 relative">
        <i data-lucide="message-circle"></i>
        <?php if ($unread_count > 0): ?>
          <span class="absolute -top-1 -right-1 bg-red-500 text-xs rounded-full h-5 w-5 flex items-center justify-center" id="chatBadge">
            <?php echo $unread_count; ?>
          </span>
        <?php endif; ?>
      </a>

      <!-- Notification Bell -->
      <div class="dropdown-container">
        <button id="notificationBell" class="text-gray-300 hover:text-yellow-400 transition-colors p-2 rounded-lg hover:bg-white/5 relative">
          <i data-lucide="bell" class="w-5 h-5"></i>
          <span class="absolute -top-1 -right-1 bg-red-500 text-xs rounded-full h-5 w-5 flex items-center justify-center <?php echo $notification_count > 0 ? '' : 'hidden'; ?>" id="notificationBadge">
            <?php echo $notification_count > 99 ? '99+' : $notification_count; ?>
          </span>
        </button>
        
        <!-- Notification Dropdown -->
        <div id="notificationDropdown" class="dropdown notification-dropdown hidden">
          <div class="p-4 border-b border-gray-700">
            <div class="flex justify-between items-center">
              <h3 class="text-yellow-400 font-semibold">Notifications</h3>
              <?php if ($notification_count > 0): ?>
                <button id="markAllRead" class="text-xs text-gray-400 hover:text-yellow-400">Mark all read</button>
              <?php endif; ?>
            </div>
          </div>
          <div class="max-h-96 overflow-y-auto">
            <div id="notificationList" class="p-2">
              <?php if (empty($notifications)): ?>
                <div class="text-center py-8 text-gray-500">
                  <i data-lucide="bell-off" class="w-12 h-12 mx-auto mb-3 opacity-50"></i>
                  <p>No notifications</p>
                  <p class="text-sm mt-1">You're all caught up!</p>
                </div>
              <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                  <div class="notification-item" data-notification-id="<?php echo $notification['id']; ?>">
                    <div class="flex items-start gap-3">
                      <div class="flex-shrink-0 mt-1">
                        <?php
                        $icon = 'bell';
                        $color = 'gray-400';
                        switch($notification['type']) {
                          case 'announcement': $icon = 'megaphone'; $color = 'yellow-400'; break;
                          case 'membership': $icon = 'id-card'; $color = 'blue-400'; break;
                          case 'message': $icon = 'message-circle'; $color = 'green-400'; break;
                          case 'system': $icon = 'settings'; $color = 'purple-400'; break;
                          case 'reminder': $icon = 'clock'; $color = 'orange-400'; break;
                          default: $icon = 'bell'; $color = 'gray-400';
                        }
                        ?>
                        <i data-lucide="<?php echo $icon; ?>" class="w-4 h-4 text-<?php echo $color; ?>"></i>
                      </div>
                      <div class="flex-1 min-w-0">
                        <div class="flex justify-between items-start mb-1">
                          <p class="text-white font-medium text-sm"><?php echo htmlspecialchars($notification['title']); ?></p>
                          <span class="text-xs text-gray-400 whitespace-nowrap ml-2">
                            <?php
                            $time = strtotime($notification['created_at']);
                            $now = time();
                            $diff = $now - $time;
                            
                            if ($diff < 60) {
                              echo 'Just now';
                            } elseif ($diff < 3600) {
                              echo floor($diff / 60) . 'm ago';
                            } elseif ($diff < 86400) {
                              echo floor($diff / 3600) . 'h ago';
                            } elseif ($diff < 604800) {
                              echo floor($diff / 86400) . 'd ago';
                            } else {
                              echo date('M j, Y', $time);
                            }
                            ?>
                          </span>
                        </div>
                        <p class="text-gray-400 text-xs line-clamp-2"><?php echo htmlspecialchars($notification['message']); ?></p>
                        <?php if (isset($notification['priority']) && $notification['priority'] === 'high'): ?>
                          <span class="inline-block mt-1 px-2 py-1 bg-red-500/20 text-red-400 text-xs rounded-full">
                            Important
                          </span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
          <div class="p-3 border-t border-gray-700 text-center">
            <a href="notifications.php" class="text-yellow-400 text-sm hover:text-yellow-300">View All Notifications</a>
          </div>
        </div>
      </div>

      <div class="h-8 w-px bg-gray-700 mx-1"></div>
      
      <!-- User Profile Dropdown -->
      <div class="dropdown-container">
        <button id="userMenuButton" class="flex items-center space-x-2 text-gray-300 hover:text-yellow-400 transition-colors p-2 rounded-lg hover:bg-white/5">
          <img src="<?php echo htmlspecialchars($_SESSION['profile_picture'] ?? 'https://i.pravatar.cc/40'); ?>" class="w-8 h-8 rounded-full border border-gray-600" id="userAvatar" />
          <span class="text-sm font-medium hidden md:inline" id="userName">
            <?php echo htmlspecialchars(isset($client['full_name']) ? $client['full_name'] : $_SESSION['username']); ?>
          </span>
          <i data-lucide="chevron-down" class="w-4 h-4"></i>
        </button>
        
        <!-- User Dropdown Menu -->
        <div id="userDropdown" class="dropdown user-dropdown hidden">
          <div class="p-4 border-b border-gray-700">
            <p class="text-white font-semibold text-sm"><?php echo htmlspecialchars(isset($client['full_name']) ? $client['full_name'] : $_SESSION['username']); ?></p>
            <p class="text-gray-400 text-xs capitalize"><?php echo $_SESSION['role']; ?></p>
          </div>
          <div class="p-2">
            <a href="profile.php" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-300 hover:text-yellow-400 hover:bg-white/5 rounded-lg transition-colors">
              <i data-lucide="user" class="w-4 h-4"></i>
              My Profile
            </a>
            <a href="edit_profile.php" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-300 hover:text-yellow-400 hover:bg-white/5 rounded-lg transition-colors">
              <i data-lucide="edit-2" class="w-4 h-4"></i>
              Edit Profile
            </a>
            <a href="settings.php" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-300 hover:text-yellow-400 hover:bg-white/5 rounded-lg transition-colors">
              <i data-lucide="settings" class="w-4 h-4"></i>
              Settings
            </a>
            <div class="border-t border-gray-700 my-1"></div>
            <a href="logout.php" class="flex items-center gap-2 px-3 py-2 text-sm text-red-400 hover:text-red-300 hover:bg-red-400/10 rounded-lg transition-colors">
              <i data-lucide="log-out" class="w-4 h-4"></i>
              Logout
            </a>
          </div>
        </div>
      </div>
    </div>
  </header>


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
            <a href="nutritionplansclient.php" class="text-yellow-400"><i data-lucide="list"></i> My Meal Plans</a>
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
            <a href="nutritionplansclient.php" class="text-yellow-400" onclick="closeMobileSidebar()"><i data-lucide="list"></i> My Meal Plans</a>
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

  <!-- Mobile Bottom Navigation -->
  <nav class="mobile-nav" aria-label="Mobile navigation">
    <a href="client_dashboard.php" class="mobile-nav-item">
      <i data-lucide="home"></i>
      <span class="mobile-nav-label">Dashboard</span>
    </a>
    <a href="workoutplansclient.php" class="mobile-nav-item">
      <i data-lucide="dumbbell"></i>
      <span class="mobile-nav-label">Workouts</span>
    </a>
    <a href="nutritionplansclient.php" class="mobile-nav-item active">
      <i data-lucide="utensils"></i>
      <span class="mobile-nav-label">Nutrition</span>
    </a>
    <a href="myprogressclient.php" class="mobile-nav-item">
      <i data-lucide="activity"></i>
      <span class="mobile-nav-label">Progress</span>
    </a>
    <button id="mobileMenuButton" class="mobile-nav-item">
      <i data-lucide="menu"></i>
      <span class="mobile-nav-label">More</span>
    </button>
  </nav>

  <noscript>
    <style>
      .mobile-sidebar { display: none; }
      .desktop-sidebar { display: block !important; }
      .mobile-nav { display: none !important; }
      .js-enabled { display: none; }
    </style>
  </noscript>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Initialize icons
      lucide.createIcons();

      // Mobile sidebar functionality
      const mobileSidebar = document.getElementById('mobileSidebar');
      const mobileOverlay = document.getElementById('mobileOverlay');
      const toggleSidebar = document.getElementById('toggleSidebar');
      const closeMobileSidebar = document.getElementById('closeMobileSidebar');
      const mobileMenuButton = document.getElementById('mobileMenuButton');
      const desktopSidebar = document.getElementById('desktopSidebar');

      function openMobileSidebar() {
        mobileSidebar.classList.add('open');
        mobileOverlay.classList.add('active');
        document.body.style.overflow = 'hidden';
      }

      function closeMobileSidebarFunc() {
        mobileSidebar.classList.remove('open');
        mobileOverlay.classList.remove('active');
        document.body.style.overflow = '';
      }

      // Smart toggle function that detects screen size
      function smartToggleSidebar() {
        if (window.innerWidth <= 768) {
          // Mobile: Toggle mobile sidebar
          if (mobileSidebar.classList.contains('open')) {
            closeMobileSidebarFunc();
          } else {
            openMobileSidebar();
          }
        } else {
          // Desktop: Toggle desktop sidebar
          if (desktopSidebar.classList.contains('w-60')) {
            desktopSidebar.classList.remove('w-60');
            desktopSidebar.classList.add('w-16', 'sidebar-collapsed');
          } else {
            desktopSidebar.classList.remove('w-16', 'sidebar-collapsed');
            desktopSidebar.classList.add('w-60');
          }
        }
      }

      // Event listeners
      if (toggleSidebar) {
        toggleSidebar.addEventListener('click', smartToggleSidebar);
      }
      
      if (mobileMenuButton) {
        mobileMenuButton.addEventListener('click', openMobileSidebar);
      }
      
      if (closeMobileSidebar) {
        closeMobileSidebar.addEventListener('click', closeMobileSidebarFunc);
      }
      
      if (mobileOverlay) {
        mobileOverlay.addEventListener('click', closeMobileSidebarFunc);
      }

      // Workout submenu toggle (Desktop)
      const workoutToggle = document.getElementById('workoutToggle');
      const workoutSubmenu = document.getElementById('workoutSubmenu');
      const workoutChevron = document.getElementById('workoutChevron');
      
      if (workoutToggle) {
        workoutToggle.addEventListener('click', () => {
          workoutSubmenu.classList.toggle('open');
          workoutChevron.classList.toggle('rotate');
        });
      }

      // Nutrition submenu toggle (Desktop)
      const nutritionToggle = document.getElementById('nutritionToggle');
      const nutritionSubmenu = document.getElementById('nutritionSubmenu');
      const nutritionChevron = document.getElementById('nutritionChevron');
      
      if (nutritionToggle) {
        nutritionToggle.addEventListener('click', () => {
          nutritionSubmenu.classList.toggle('open');
          nutritionChevron.classList.toggle('rotate');
        });
      }

      // Mobile submenu toggles
      const mobileWorkoutToggle = document.getElementById('mobileWorkoutToggle');
      const mobileWorkoutSubmenu = document.getElementById('mobileWorkoutSubmenu');
      const mobileWorkoutChevron = document.getElementById('mobileWorkoutChevron');
      
      if (mobileWorkoutToggle) {
        mobileWorkoutToggle.addEventListener('click', () => {
          mobileWorkoutSubmenu.classList.toggle('open');
          mobileWorkoutChevron.classList.toggle('rotate');
        });
      }

      const mobileNutritionToggle = document.getElementById('mobileNutritionToggle');
      const mobileNutritionSubmenu = document.getElementById('mobileNutritionSubmenu');
      const mobileNutritionChevron = document.getElementById('mobileNutritionChevron');
      
      if (mobileNutritionToggle) {
        mobileNutritionToggle.addEventListener('click', () => {
          mobileNutritionSubmenu.classList.toggle('open');
          mobileNutritionChevron.classList.toggle('rotate');
        });
      }

      // Hover to open sidebar (for desktop collapsed state) - Only on desktop
      if (desktopSidebar && window.innerWidth > 768) {
        desktopSidebar.addEventListener('mouseenter', () => {
          if (desktopSidebar.classList.contains('sidebar-collapsed')) {
            desktopSidebar.classList.remove('w-16', 'sidebar-collapsed');
            desktopSidebar.classList.add('w-60');
          }
        });
        
        desktopSidebar.addEventListener('mouseleave', () => {
          if (!desktopSidebar.classList.contains('sidebar-collapsed')) {
            desktopSidebar.classList.remove('w-60');
            desktopSidebar.classList.add('w-16', 'sidebar-collapsed');
          }
        });
      }

      // Update mobile nav active state
      updateMobileNavActive();
    });

    // Update mobile navigation active state
    function updateMobileNavActive() {
      const currentPage = window.location.pathname.split('/').pop();
      const mobileNavItems = document.querySelectorAll('.mobile-nav-item');
      
      mobileNavItems.forEach(item => {
        item.classList.remove('active');
        // Simple page matching logic
        if (item.getAttribute('href') === currentPage) {
          item.classList.add('active');
        }
      });
    }

    // Global function to close mobile sidebar (used in onclick events)
    function closeMobileSidebar() {
      const mobileSidebar = document.getElementById('mobileSidebar');
      const mobileOverlay = document.getElementById('mobileOverlay');
      
      if (mobileSidebar) {
        mobileSidebar.classList.remove('open');
      }
      if (mobileOverlay) {
        mobileOverlay.classList.remove('active');
      }
      document.body.style.overflow = '';
    }

    // Handle window resize
    window.addEventListener('resize', function() {
      const mobileSidebar = document.getElementById('mobileSidebar');
      const mobileOverlay = document.getElementById('mobileOverlay');
      
      // Close mobile sidebar when resizing to desktop
      if (window.innerWidth > 768 && mobileSidebar && mobileSidebar.classList.contains('open')) {
        closeMobileSidebar();
      }

      // Update mobile nav on resize
      updateMobileNavActive();
    });
  </script>
</body>
</html>
<?php
$conn->close();
?>



