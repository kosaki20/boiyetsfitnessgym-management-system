<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'trainer') {
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
$current_trainer_id = $_SESSION['user_id'];

// Function to get unread notifications count
function getUnreadNotificationsCount($conn, $user_id, $role) {
    $sql = "SELECT COUNT(*) as count FROM notifications 
            WHERE (user_id = ? AND role = ?) OR (role = ? AND user_id IS NULL)
            AND read_status = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $user_id, $role, $role);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['count'];
}

// Function to get notifications
function getNotifications($conn, $user_id, $role, $limit = 10) {
    $sql = "SELECT * FROM notifications 
            WHERE (user_id = ? AND role = ?) OR (role = ? AND user_id IS NULL)
            ORDER BY created_at DESC 
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("issi", $user_id, $role, $role, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    return $notifications;
}

// Function to mark notification as read
function markNotificationAsRead($conn, $notification_id, $user_id) {
    $sql = "UPDATE notifications SET read_status = 1 WHERE id = ? AND (user_id = ? OR user_id IS NULL)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $notification_id, $user_id);
    return $stmt->execute();
}

// Function to mark all notifications as read
function markAllNotificationsAsRead($conn, $user_id, $role) {
    $sql = "UPDATE notifications SET read_status = 1 
            WHERE (user_id = ? AND role = ?) OR (role = ? AND user_id IS NULL)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $user_id, $role, $role);
    return $stmt->execute();
}

// Function to create notification
function createNotification($conn, $user_id, $role, $title, $message, $type = 'system', $priority = 'medium') {
    $sql = "INSERT INTO notifications (user_id, role, title, message, type, priority) 
            VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isssss", $user_id, $role, $title, $message, $type, $priority);
    return $stmt->execute();
}

// Get unread notifications count
$unread_notifications_count = getUnreadNotificationsCount($conn, $current_trainer_id, 'trainer');

// Mark all as read if requested
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    if (markAllNotificationsAsRead($conn, $current_trainer_id, 'trainer')) {
        $unread_notifications_count = 0;
        header("Location: trainermealplan.php");
        exit();
    }
}

// Mark single notification as read via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_as_read'])) {
    $notification_id = $_POST['notification_id'];
    if (markNotificationAsRead($conn, $notification_id, $current_trainer_id)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// Get notifications for display
$notifications = getNotifications($conn, $current_trainer_id, 'trainer', 10);

// Function to get meal plans
function getMealPlans($conn, $trainer_id = null) {
    $mealPlans = [];
    $sql = "SELECT mp.*, m.full_name, m.fitness_goals 
            FROM meal_plans mp 
            JOIN members m ON mp.member_id = m.id 
            WHERE m.member_type = 'client'";
    
    if ($trainer_id) {
        $sql .= " AND mp.created_by = ?";
    }
    
    $sql .= " ORDER BY mp.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if ($trainer_id) {
        $stmt->bind_param("i", $trainer_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $row['meals'] = json_decode($row['meals'], true) ?: [];
        $mealPlans[] = $row;
    }
    
    return $mealPlans;
}

// Function to get meal templates
function getMealTemplates($conn, $trainer_id = null) {
    $templates = [];
    $sql = "SELECT * FROM meal_templates WHERE 1=1";
    
    if ($trainer_id) {
        $sql .= " AND created_by = ?";
    }
    
    $sql .= " ORDER BY created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if ($trainer_id) {
        $stmt->bind_param("i", $trainer_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $row['meals'] = json_decode($row['meals'], true) ?: [];
        $templates[] = $row;
    }
    
    return $templates;
}

// Function to get clients
function getClients($conn) {
    $clients = [];
    $sql = "SELECT * FROM members WHERE member_type = 'client' AND status = 'active' ORDER BY full_name";
    $result = $conn->query($sql);
    
    while ($row = $result->fetch_assoc()) {
        $clients[] = $row;
    }
    
    return $clients;
}

// Create meal_templates table if not exists
$createTableSQL = "CREATE TABLE IF NOT EXISTS meal_templates (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    template_name VARCHAR(100) NOT NULL,
    description TEXT,
    meals LONGTEXT NOT NULL,
    daily_calories INT(11),
    protein_goal DECIMAL(5,2),
    carbs_goal DECIMAL(5,2),
    fat_goal DECIMAL(5,2),
    goal ENUM('weight_loss','muscle_gain','maintenance','performance') DEFAULT 'maintenance',
    created_by INT(11) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if (!$conn->query($createTableSQL)) {
    die("Error creating meal_templates table: " . $conn->error);
}

// Process meal template form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_meal_template'])) {
    $template_name = $_POST['template_name'];
    $description = $_POST['description'];
    $daily_calories = $_POST['daily_calories'];
    $protein_goal = $_POST['protein_goal'];
    $carbs_goal = $_POST['carbs_goal'];
    $fat_goal = $_POST['fat_goal'];
    $goal = $_POST['goal'];
    
    $meals = [];
    if (isset($_POST['meal_names'])) {
        for ($i = 0; $i < count($_POST['meal_names']); $i++) {
            if (!empty($_POST['meal_names'][$i])) {
                $meals[] = [
                    'name' => $_POST['meal_names'][$i],
                    'time' => $_POST['meal_times'][$i] ?? '',
                    'calories' => $_POST['meal_calories'][$i] ?? 0,
                    'description' => $_POST['meal_descriptions'][$i] ?? ''
                ];
            }
        }
    }
    
    if (empty($meals)) {
        $meal_error = "Please add at least one meal to the template.";
    } else {
        $meals_json = json_encode($meals);
        
        $sql = "INSERT INTO meal_templates (template_name, description, daily_calories, protein_goal, carbs_goal, fat_goal, goal, meals, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssidddssi", $template_name, $description, $daily_calories, $protein_goal, $carbs_goal, $fat_goal, $goal, $meals_json, $current_trainer_id);
        
        if ($stmt->execute()) {
            $meal_success = "Meal template created successfully!";
            // Create notification for the trainer
            createNotification($conn, $current_trainer_id, 'trainer', 'Meal Template Created', 
                "You created a new meal template: " . $template_name, 'system', 'medium');
            header("Refresh: 2");
        } else {
            $meal_error = "Error creating meal template: " . $conn->error;
        }
    }
}

// Process template assignment to client
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_template'])) {
    $template_id = $_POST['template_id'];
    $member_id = $_POST['member_id'];
    $plan_name = $_POST['plan_name'];
    
    // Get template data
    $sql = "SELECT * FROM meal_templates WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $template_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $template = $result->fetch_assoc();
    
    if ($template) {
        $sql = "INSERT INTO meal_plans (member_id, plan_name, description, daily_calories, protein_goal, carbs_goal, fat_goal, meals, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isiddddsi", $member_id, $plan_name, $template['description'], $template['daily_calories'], $template['protein_goal'], $template['carbs_goal'], $template['fat_goal'], $template['meals'], $current_trainer_id);
        
        if ($stmt->execute()) {
            $meal_success = "Meal template assigned to client successfully!";
            // Create notification for the trainer
            createNotification($conn, $current_trainer_id, 'trainer', 'Meal Plan Assigned', 
                "You assigned a meal plan to a client: " . $plan_name, 'system', 'medium');
            header("Refresh: 2");
        } else {
            $meal_error = "Error assigning template: " . $conn->error;
        }
    }
}

// Process custom meal plan form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_meal_plan'])) {
    $member_id = $_POST['member_id'];
    $plan_name = $_POST['plan_name'];
    $description = $_POST['description'];
    $daily_calories = $_POST['daily_calories'];
    $protein_goal = $_POST['protein_goal'];
    $carbs_goal = $_POST['carbs_goal'];
    $fat_goal = $_POST['fat_goal'];
    
    $meals = [];
    if (isset($_POST['meal_names'])) {
        for ($i = 0; $i < count($_POST['meal_names']); $i++) {
            if (!empty($_POST['meal_names'][$i])) {
                $meals[] = [
                    'name' => $_POST['meal_names'][$i],
                    'time' => $_POST['meal_times'][$i] ?? '',
                    'calories' => $_POST['meal_calories'][$i] ?? 0,
                    'description' => $_POST['meal_descriptions'][$i] ?? ''
                ];
            }
        }
    }
    
    if (empty($meals)) {
        $meal_error = "Please add at least one meal.";
    } else {
        $meals_json = json_encode($meals);
        
        $sql = "INSERT INTO meal_plans (member_id, plan_name, description, daily_calories, protein_goal, carbs_goal, fat_goal, meals, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("issddddsi", $member_id, $plan_name, $description, $daily_calories, $protein_goal, $carbs_goal, $fat_goal, $meals_json, $current_trainer_id);
        
        if ($stmt->execute()) {
            $meal_success = "Meal plan created successfully!";
            // Create notification for the trainer
            createNotification($conn, $current_trainer_id, 'trainer', 'Custom Meal Plan Created', 
                "You created a custom meal plan: " . $plan_name, 'system', 'medium');
            header("Refresh: 2");
        } else {
            $meal_error = "Error creating meal plan: " . $conn->error;
        }
    }
}

// Delete meal plan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_meal_plan'])) {
    $plan_id = $_POST['plan_id'];
    
    $sql = "DELETE FROM meal_plans WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $plan_id);
    
    if ($stmt->execute()) {
        $meal_success = "Meal plan deleted successfully!";
        // Create notification for the trainer
        createNotification($conn, $current_trainer_id, 'trainer', 'Meal Plan Deleted', 
            "You deleted a meal plan", 'system', 'medium');
        header("Refresh: 2");
    } else {
        $meal_error = "Error deleting meal plan: " . $conn->error;
    }
}

// Delete meal template
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_meal_template'])) {
    $template_id = $_POST['template_id'];
    
    $sql = "DELETE FROM meal_templates WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $template_id);
    
    if ($stmt->execute()) {
        $meal_success = "Meal template deleted successfully!";
        // Create notification for the trainer
        createNotification($conn, $current_trainer_id, 'trainer', 'Meal Template Deleted', 
            "You deleted a meal template", 'system', 'medium');
        header("Refresh: 2");
    } else {
        $meal_error = "Error deleting meal template: " . $conn->error;
    }
}

$clients = getClients($conn);
$mealPlans = getMealPlans($conn, $current_trainer_id);
$mealTemplates = getMealTemplates($conn, $current_trainer_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BOIYETS FITNESS GYM - Meal Plans</title>
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
      min-height: 100vh;
    }
    
    .sidebar { 
      flex-shrink: 0; 
      transition: all 0.3s ease;
      overflow-y: auto;
      -ms-overflow-style: none;
      scrollbar-width: none;
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
    
    .sidebar-item:hover { 
      background: rgba(255,255,255,0.05); 
      color: #fbbf24; 
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
    }
    
    .submenu a i { 
      width: 14px; 
      height: 14px; 
      margin-right: 0.5rem; 
    }
    
    .submenu a:hover { 
      color: #fbbf24; 
      background: rgba(255,255,255,0.05); 
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
      padding: 1.5rem;
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.05);
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
      transition: all 0.2s ease;
    }
    
    .card:hover {
      box-shadow: 0 10px 15px rgba(0, 0, 0, 0.2);
      transform: translateY(-2px);
    }
    
    .button-sm { 
      padding: 0.5rem 0.75rem; 
      font-size: 0.8rem; 
      border-radius: 8px;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 0.4rem;
      transition: all 0.2s ease;
    }
    
    .button-sm:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
    }

    .topbar {
      background: rgba(13, 13, 13, 0.95);
      backdrop-filter: blur(10px);
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      position: relative;
      z-index: 100;
    }
    
    .table-container {
      background: rgba(26, 26, 26, 0.7);
      border-radius: 12px;
      overflow: hidden;
      border: 1px solid rgba(255, 255, 255, 0.05);
    }
    
    table {
      width: 100%;
      border-collapse: collapse;
    }
    
    th {
      background: rgba(251, 191, 36, 0.1);
      color: #fbbf24;
      padding: 1rem;
      text-align: left;
      font-weight: 600;
      font-size: 0.875rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    td {
      padding: 1rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
      font-size: 0.875rem;
    }
    
    tr:hover {
      background: rgba(255, 255, 255, 0.02);
    }
    
    .form-input {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 8px;
      padding: 0.75rem;
      color: white;
      width: 100%;
      transition: all 0.2s ease;
    }
    
    .form-input:focus {
      outline: none;
      border-color: #fbbf24;
      box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.2);
    }
    
    .form-label {
      display: block;
      margin-bottom: 0.5rem;
      font-weight: 500;
      color: #d1d5db;
    }
    
    .form-select {
      background: rgba(255, 255, 255, 0.05) !important;
      border: 1px solid rgba(255, 255, 255, 0.1) !important;
      border-radius: 8px;
      padding: 0.75rem;
      color: white !important;
      width: 100%;
      transition: all 0.2s ease;
      appearance: none;
      -webkit-appearance: none;
      -moz-appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23fbbf24' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") !important;
      background-repeat: no-repeat !important;
      background-position: right 0.75rem center !important;
      background-size: 16px !important;
    }
    
    .form-select:focus {
      outline: none;
      border-color: #fbbf24 !important;
      box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.2) !important;
    }
    
    .form-select option {
      background: #1a1a1a !important;
      color: white !important;
      padding: 0.5rem;
    }
    
    .btn {
      padding: 0.75rem 1.5rem;
      border-radius: 8px;
      font-weight: 500;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      transition: all 0.2s ease;
      border: none;
      cursor: pointer;
    }
    
    .btn-primary {
      background: rgba(251, 191, 36, 0.2);
      color: #fbbf24;
    }
    
    .btn-primary:hover {
      background: rgba(251, 191, 36, 0.3);
    }
    
    .btn-success {
      background: rgba(16, 185, 129, 0.2);
      color: #10b981;
    }
    
    .btn-success:hover {
      background: rgba(16, 185, 129, 0.3);
    }
    
    .btn-danger {
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
    }
    
    .btn-danger:hover {
      background: rgba(239, 68, 68, 0.3);
    }
    
    .btn-purple {
      background: rgba(139, 92, 246, 0.2);
      color: #8b5cf6;
    }
    
    .btn-purple:hover {
      background: rgba(139, 92, 246, 0.3);
    }
    
    .alert {
      padding: 1rem;
      border-radius: 8px;
      margin-bottom: 1rem;
      border-left: 4px solid;
    }
    
    .alert-success {
      background: rgba(16, 185, 129, 0.1);
      border-left-color: #10b981;
      color: #10b981;
    }
    
    .alert-error {
      background: rgba(239, 68, 68, 0.1);
      border-left-color: #ef4444;
      color: #ef4444;
    }
    
    .stat-card {
      background: rgba(55, 65, 81, 0.8);
      border-radius: 12px;
      padding: 1.5rem;
      text-align: center;
      transition: all 0.3s ease;
    }
    
    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
    }
    
    .meal-item {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 1.5rem;
      border-left: 4px solid #10b981;
      transition: all 0.3s ease;
    }
    
    .badge {
      padding: 0.25rem 0.75rem;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 500;
    }
    
    .badge-blue {
      background: rgba(59, 130, 246, 0.2);
      color: #3b82f6;
    }
    
    .badge-yellow {
      background: rgba(245, 158, 11, 0.2);
      color: #f59e0b;
    }
    
    .badge-green {
      background: rgba(16, 185, 129, 0.2);
      color: #10b981;
    }
    
    .badge-purple {
      background: rgba(139, 92, 246, 0.2);
      color: #8b5cf6;
    }
    
    .badge-red {
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
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
    
    /* Quick Actions */
    .quick-actions {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1rem;
      margin-bottom: 2rem;
    }
    
    .action-card {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 12px;
      padding: 1.5rem;
      text-align: center;
      transition: all 0.3s ease;
      border: 1px solid rgba(255, 255, 255, 0.1);
      cursor: pointer;
    }
    
    .action-card:hover {
      transform: translateY(-4px);
      border-color: #fbbf24;
      box-shadow: 0 8px 25px rgba(251, 191, 36, 0.2);
    }
    
    .action-card i {
      color: #fbbf24;
      margin-bottom: 1rem;
    }
    
    /* YouTube Card */
    .youtube-card {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 12px;
      padding: 1.5rem;
      border: 2px solid rgba(255, 0, 0, 0.3);
      transition: all 0.3s ease;
    }
    
    .youtube-card:hover {
      border-color: #ff0000;
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(255, 0, 0, 0.2);
    }
    
    .youtube-link {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      background: #ff0000;
      color: white;
      padding: 0.75rem 1.5rem;
      border-radius: 8px;
      text-decoration: none;
      font-weight: 600;
      transition: all 0.2s ease;
    }
    
    .youtube-link:hover {
      background: #cc0000;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(255, 0, 0, 0.3);
    }

    /* Plan Card */
    .plan-card {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 12px;
      padding: 1.5rem;
      border-left: 4px solid #fbbf24;
      transition: all 0.3s ease;
      margin-bottom: 1rem;
    }
    
    .plan-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
    }

    /* Template Card */
    .template-card {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 12px;
      padding: 1.5rem;
      border-left: 4px solid #8b5cf6;
      transition: all 0.3s ease;
      margin-bottom: 1rem;
    }
    
    .template-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(139, 92, 246, 0.2);
    }

    /* Section Headers */
    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
      padding-bottom: 0.75rem;
      border-bottom: 2px solid rgba(251, 191, 36, 0.3);
    }

    .section-title {
      font-size: 1.5rem;
      font-weight: 700;
      color: #fbbf24;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    /* Tabs */
    .tabs {
      display: flex;
      gap: 0.5rem;
      margin-bottom: 2rem;
      border-bottom: 2px solid rgba(255, 255, 255, 0.1);
    }
    
    .tab {
      padding: 1rem 2rem;
      background: transparent;
      border: none;
      color: #9ca3af;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.2s ease;
      border-bottom: 2px solid transparent;
    }
    
    .tab.active {
      color: #fbbf24;
      border-bottom-color: #fbbf24;
    }
    
    .tab:hover {
      color: #fbbf24;
    }
    
    .tab-content {
      display: none;
    }
    
    .tab-content.active {
      display: block;
    }

    /* Dropdown Styles */
    .dropdown-container {
      position: relative;
    }
    
    .dropdown {
      position: absolute;
      right: 0;
      top: 100%;
      margin-top: 0.5rem;
      background: #1a1a1a;
      border: 1px solid #374151;
      border-radius: 8px;
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5), 0 10px 10px -5px rgba(0, 0, 0, 0.4);
      z-index: 1000;
      min-width: 200px;
      backdrop-filter: blur(10px);
    }
    
    .notification-dropdown {
      width: 380px;
      max-width: 90vw;
    }
    
    .user-dropdown {
      width: 240px;
    }
    
    .notification-item {
      padding: 0.75rem 1rem;
      border-bottom: 1px solid #374151;
      cursor: pointer;
      transition: background-color 0.2s ease;
    }
    
    .notification-item:hover {
      background: rgba(255, 255, 255, 0.05);
    }
    
    .notification-item:last-child {
      border-bottom: none;
    }
    
    .line-clamp-2 {
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    .notification-unread {
      background: rgba(251, 191, 36, 0.05);
      border-left: 3px solid #fbbf24;
    }

    /* QR Scanner Styles - MOVABLE & TOGGLEABLE */
    .qr-scanner-container {
      position: fixed;
      bottom: 80px;
      right: 20px;
      z-index: 90;
      background: rgba(26, 26, 26, 0.95);
      border-radius: 12px;
      padding: 1rem;
      border: 1px solid rgba(251, 191, 36, 0.3);
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
      backdrop-filter: blur(10px);
      transition: all 0.3s ease;
      max-width: 320px;
      width: 100%;
      cursor: move;
    }

    .qr-scanner-container.dragging {
      opacity: 0.8;
      cursor: grabbing;
    }

    .qr-scanner-container.hidden {
      transform: translateX(400px);
      opacity: 0;
      pointer-events: none;
    }

    .qr-scanner-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 0.75rem;
      user-select: none;
    }

    .cursor-move {
      cursor: move;
    }

    .cursor-grabbing {
      cursor: grabbing;
    }

    .qr-scanner-title {
      font-weight: 600;
      color: #fbbf24;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .qr-scanner-status {
      font-size: 0.8rem;
      padding: 0.25rem 0.5rem;
      border-radius: 20px;
      font-weight: 500;
    }

    .qr-scanner-status.active {
      background: rgba(16, 185, 129, 0.2);
      color: #10b981;
    }

    .qr-scanner-status.disabled {
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
    }

    .qr-input {
      width: 100%;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 8px;
      padding: 0.75rem;
      color: white;
      margin-bottom: 0.75rem;
      transition: all 0.2s ease;
      font-size: 0.9rem;
    }

    .qr-input:focus {
      outline: none;
      border-color: #fbbf24;
      box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.2);
    }

    .qr-scanner-buttons {
      display: flex;
      gap: 0.5rem;
    }

    .qr-scanner-btn {
      flex: 1;
      padding: 0.5rem;
      border-radius: 8px;
      font-size: 0.8rem;
      font-weight: 500;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.4rem;
      transition: all 0.2s ease;
      border: none;
      cursor: pointer;
    }

    .qr-scanner-btn.primary {
      background: rgba(251, 191, 36, 0.2);
      color: #fbbf24;
    }

    .qr-scanner-btn.primary:hover {
      background: rgba(251, 191, 36, 0.3);
    }

    .qr-scanner-btn.secondary {
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
    }

    .qr-scanner-btn.secondary:hover {
      background: rgba(239, 68, 68, 0.3);
    }

    .qr-scanner-result {
      margin-top: 0.75rem;
      padding: 0.75rem;
      border-radius: 8px;
      background: rgba(255, 255, 255, 0.05);
      display: none;
    }

    .qr-scanner-result.success {
      display: block;
      border-left: 4px solid #10b981;
    }

    .qr-scanner-result.error {
      display: block;
      border-left: 4px solid #ef4444;
    }

    .qr-scanner-result.info {
      display: block;
      border-left: 4px solid #3b82f6;
    }

    .qr-result-title {
      font-weight: 600;
      margin-bottom: 0.25rem;
    }

    .qr-result-message {
      font-size: 0.8rem;
      color: #d1d5db;
    }

    .scanner-instructions {
      font-size: 0.75rem;
      color: #9ca3af;
      margin-top: 0.5rem;
      text-align: center;
    }

    /* Mobile responsive */
    @media (max-width: 640px) {
      .qr-scanner-container {
        position: fixed;
        bottom: 80px;
        left: 50%;
        transform: translateX(-50%);
        max-width: 90vw;
        margin: 0;
      }
      
      .qr-scanner-container.hidden {
        transform: translateX(-50%) translateY(100px);
        opacity: 0;
      }
    }
  </style>
</head>
<body class="min-h-screen">

  <!-- Topbar -->
  <header class="topbar flex items-center justify-between px-4 py-3 shadow">
    <div class="flex items-center space-x-3">
      <button id="toggleSidebar" class="text-gray-300 hover:text-yellow-400 transition-colors p-1 rounded-lg hover:bg-white/5">
        <i data-lucide="menu" class="w-5 h-5"></i>
      </button>
      <h1 class="text-lg font-bold text-yellow-400">BOIYETS FITNESS GYM</h1>
    </div>
    <div class="flex items-center space-x-3">
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
                        <?php if ($notification['priority'] === 'high'): ?>
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
          <img src="<?php echo htmlspecialchars($_SESSION['profile_picture'] ?? $user['profile_picture'] ?? 'https://i.pravatar.cc/40'); ?>" class="w-8 h-8 rounded-full border border-gray-600" id="userAvatar" />
          <span class="text-sm font-medium hidden md:inline" id="userName">
            <?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?>
          </span>
          <i data-lucide="chevron-down" class="w-4 h-4"></i>
        </button>
        
        <!-- User Dropdown Menu -->
        <div id="userDropdown" class="dropdown user-dropdown hidden">
          <div class="p-4 border-b border-gray-700">
            <p class="text-white font-semibold text-sm"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?></p>
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

  <div class="flex">
    <!-- Sidebar -->
    <aside id="sidebar" class="sidebar w-60 bg-[#0d0d0d] h-screen p-3 space-y-2 flex flex-col overflow-y-auto border-r border-gray-800">
      <nav class="space-y-1 flex-1">
        <a href="trainer_dashboard.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="home"></i>
            <span class="text-sm font-medium">Dashboard</span>
          </div>
          <span class="tooltip">Dashboard</span>
        </a>

        <!-- Members Section -->
        <div>
          <div id="membersToggle" class="sidebar-item">
            <div class="flex items-center">
              <i data-lucide="users"></i>
              <span class="text-sm font-medium">Members</span>
            </div>
            <i id="membersChevron" data-lucide="chevron-right" class="chevron"></i>
            <span class="tooltip">Members</span>
          </div>
          <div id="membersSubmenu" class="submenu space-y-1">
            <a href="member_registration.php"><i data-lucide="user-plus"></i> Member Registration</a>
            <a href="membership_status.php"><i data-lucide="id-card"></i> Membership Status</a>
            <a href="all_members.php"><i data-lucide="list"></i> All Members</a>
          </div>
        </div>

        <!-- Client Progress -->
        <a href="clientprogress.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="activity"></i>
            <span class="text-sm font-medium">Client Progress</span>
          </div>
          <span class="tooltip">Client Progress</span>
        </a>

        <!-- Counter Sales -->
        <a href="countersales.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="package"></i>
            <span class="text-sm font-medium">Counter Sales</span>
          </div>
          <span class="tooltip">Counter Sales</span>
        </a>
<!-- Equipment Monitoring -->
<a href="trainer_equipment_monitoring.php" class="sidebar-item">
  <div class="flex items-center">
    <i data-lucide="wrench"></i>
    <span class="text-sm font-medium">Equipment Monitoring</span>
  </div>
  <span class="tooltip">Equipment Monitoring</span>
</a>

<!-- Maintenance Report -->
<a href="trainer_maintenance_report.php" class="sidebar-item">
  <div class="flex items-center">
    <i data-lucide="alert-triangle"></i>
    <span class="text-sm font-medium">Maintenance Report</span>
  </div>
  <span class="tooltip">Maintenance Report</span>
</a>
        <!-- Feedbacks -->
        <a href="feedbackstrainer.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="message-square"></i>
            <span class="text-sm font-medium">Feedbacks</span>
          </div>
          <span class="tooltip">Feedbacks</span>
        </a>

        <!-- Attendance Logs -->
        <a href="attendance_logs.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="calendar"></i>
            <span class="text-sm font-medium">Attendance Logs</span>
          </div>
          <span class="tooltip">Attendance Logs</span>
        </a>

        <!-- QR Code Management -->
        <a href="trainermanageqrcodes.php" class="sidebar-item">
          <div class="flex items-center">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M4 4H8V8H4V4ZM10 4H14V8H10V4ZM16 4H20V8H16V4ZM4 10H8V14H4V10ZM10 10H14V14H10V10ZM16 10H20V14H16V10ZM4 16H8V20H4V16ZM10 16H14V20H10V16ZM16 16H20V20H16V16Z" 
                    fill="currentColor"/>
              <rect x="2" y="2" width="8" height="8" rx="1.5" stroke="currentColor" stroke-width="1.5" fill="none"/>
              <rect x="14" y="2" width="8" height="8" rx="1.5" stroke="currentColor" stroke-width="1.5" fill="none"/>
              <rect x="2" y="14" width="8" height="8" rx="1.5" stroke="currentColor" stroke-width="1.5" fill="none"/>
            </svg>
            <span class="text-sm font-medium">QR Code Management</span>
          </div>
          <span class="tooltip">Manage Client QR Codes</span>
        </a>

        <!-- Training Plans Section -->
        <div>
          <div id="plansToggle" class="sidebar-item active">
            <div class="flex items-center">
              <i data-lucide="clipboard-list"></i>
              <span class="text-sm font-medium">Training Plans</span>
            </div>
            <i id="plansChevron" data-lucide="chevron-right" class="chevron"></i>
            <span class="tooltip">Training Plans</span>
          </div>
          <div id="plansSubmenu" class="submenu space-y-1 open">
            <a href="trainerworkout.php"><i data-lucide="dumbbell"></i> Workout Plans</a>
            <a href="trainermealplan.php" class="text-yellow-400"><i data-lucide="utensils"></i> Meal Plans</a>
          </div>
        </div>

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
    <main class="flex-1 p-6 overflow-auto">
      <!-- Header -->
      <div class="flex justify-between items-center mb-8">
        <div>
          <h1 class="text-3xl font-bold text-yellow-400 flex items-center gap-3">
            <i data-lucide="utensils"></i>
            Meal Plans & Templates
          </h1>
          <p class="text-gray-400 mt-2">Create templates and assign personalized meal plans to clients</p>
        </div>
        <div class="flex gap-3">
          <a href="trainerworkout.php" class="btn btn-primary">
            <i data-lucide="dumbbell"></i> Workout Plans
          </a>
        </div>
      </div>

      <?php if (isset($meal_success)): ?>
        <div class="alert alert-success">
          <i data-lucide="check-circle" class="w-5 h-5 mr-2"></i>
          <?php echo $meal_success; ?>
        </div>
      <?php endif; ?>

      <?php if (isset($meal_error)): ?>
        <div class="alert alert-error">
          <i data-lucide="alert-circle" class="w-5 h-5 mr-2"></i>
          <?php echo $meal_error; ?>
        </div>
      <?php endif; ?>

      <!-- Quick Actions -->
      <div class="quick-actions mb-8">
        <div class="action-card" onclick="showTab('templates-tab')">
          <i data-lucide="layout-template" class="w-8 h-8 mx-auto"></i>
          <h3 class="font-semibold text-white mb-1">Create Template</h3>
          <p class="text-gray-400 text-sm">Design reusable meal plans</p>
        </div>
        
        <div class="action-card" onclick="showTab('assign-tab')">
          <i data-lucide="user-check" class="w-8 h-8 mx-auto"></i>
          <h3 class="font-semibold text-white mb-1">Assign to Client</h3>
          <p class="text-gray-400 text-sm">Use templates for clients</p>
        </div>
        
        <div class="action-card" onclick="showTab('custom-tab')">
          <i data-lucide="plus" class="w-8 h-8 mx-auto"></i>
          <h3 class="font-semibold text-white mb-1">Custom Plan</h3>
          <p class="text-gray-400 text-sm">Create unique meal plan</p>
        </div>
        
        <div class="action-card" onclick="showTab('plans-tab')">
          <i data-lucide="list" class="w-8 h-8 mx-auto"></i>
          <h3 class="font-semibold text-white mb-1">View All Plans</h3>
          <p class="text-gray-400 text-sm"><?php echo count($mealPlans); ?> active plans</p>
        </div>
      </div>

      <!-- Statistics Overview -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="stat-card">
          <div class="text-2xl font-bold text-yellow-400 mb-2"><?php echo count($mealPlans); ?></div>
          <div class="text-gray-400 text-sm">Active Plans</div>
        </div>
        <div class="stat-card">
          <div class="text-2xl font-bold text-purple-400 mb-2"><?php echo count($mealTemplates); ?></div>
          <div class="text-gray-400 text-sm">Templates</div>
        </div>
        <div class="stat-card">
          <div class="text-2xl font-bold text-green-400 mb-2"><?php echo count($clients); ?></div>
          <div class="text-gray-400 text-sm">Active Clients</div>
        </div>
        <div class="stat-card">
          <div class="text-2xl font-bold text-blue-400 mb-2">
            <?php echo count(array_unique(array_column($mealPlans, 'member_id'))); ?>
          </div>
          <div class="text-gray-400 text-sm">Clients with Plans</div>
        </div>
      </div>

      <!-- Tabs Navigation -->
      <div class="tabs">
        <button class="tab active" onclick="showTab('templates-tab')">
          <i data-lucide="layout-template" class="w-4 h-4 mr-2"></i>
          Meal Templates
        </button>
        <button class="tab" onclick="showTab('assign-tab')">
          <i data-lucide="user-check" class="w-4 h-4 mr-2"></i>
          Assign to Clients
        </button>
        <button class="tab" onclick="showTab('custom-tab')">
          <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
          Custom Plans
        </button>
        <button class="tab" onclick="showTab('plans-tab')">
          <i data-lucide="list" class="w-4 h-4 mr-2"></i>
          All Plans (<?php echo count($mealPlans); ?>)
        </button>
      </div>

      <!-- Meal Templates Tab -->
      <div id="templates-tab" class="tab-content active">
        <div class="card">
          <div class="section-header">
            <h2 class="section-title">
              <i data-lucide="layout-template"></i>
              Create Meal Template
            </h2>
            <span class="text-gray-400"><?php echo count($mealTemplates); ?> templates created</span>
          </div>
          
          <form method="POST" id="templateForm">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
              <!-- Template Info -->
              <div class="space-y-6">
                <div>
                  <label class="form-label">Template Name *</label>
                  <input type="text" name="template_name" class="form-input" placeholder="e.g., Weight Loss Meal Plan" required>
                </div>
                
                <div>
                  <label class="form-label">Primary Goal</label>
                  <select name="goal" class="form-select" required>
                    <option value="weight_loss">Weight Loss</option>
                    <option value="muscle_gain">Muscle Gain</option>
                    <option value="maintenance">Maintenance</option>
                    <option value="performance">Performance</option>
                  </select>
                </div>
                
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                  <div>
                    <label class="form-label">Daily Calories</label>
                    <input type="number" name="daily_calories" class="form-input" placeholder="2000" required>
                  </div>
                  <div>
                    <label class="form-label">Protein (g)</label>
                    <input type="number" name="protein_goal" class="form-input" step="0.1" placeholder="150" required>
                  </div>
                  <div>
                    <label class="form-label">Carbs (g)</label>
                    <input type="number" name="carbs_goal" class="form-input" step="0.1" placeholder="250" required>
                  </div>
                  <div>
                    <label class="form-label">Fat (g)</label>
                    <input type="number" name="fat_goal" class="form-input" step="0.1" placeholder="70" required>
                  </div>
                </div>
                
                <div>
                  <label class="form-label">Nutritional Guidance</label>
                  <textarea name="description" class="form-input" rows="4" placeholder="Provide dietary advice, food timing recommendations, and nutritional focus..."></textarea>
                </div>
              </div>

              <!-- Meals Section -->
              <div>
                <div class="flex justify-between items-center mb-4">
                  <label class="form-label">Daily Meal Structure *</label>
                  <span class="text-sm text-gray-400" id="template-meal-count">1 meal added</span>
                </div>
                
                <div id="template-meals-container" class="space-y-4 max-h-96 overflow-y-auto pr-2">
                  <div class="meal-item">
                    <div class="flex justify-between items-center mb-3">
                      <h4 class="font-semibold text-white">Meal #1</h4>
                      <button type="button" onclick="this.parentElement.parentElement.remove(); updateTemplateMealCount()" class="text-red-400 hover:text-red-300">
                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                      </button>
                    </div>
                    <div class="space-y-3">
                      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                          <label class="form-label text-xs">Meal Name *</label>
                          <input type="text" name="meal_names[]" class="form-input" placeholder="e.g., Breakfast" required>
                        </div>
                        <div>
                          <label class="form-label text-xs">Time *</label>
                          <input type="text" name="meal_times[]" class="form-input" placeholder="e.g., 8:00 AM" required>
                        </div>
                      </div>
                      <div>
                        <label class="form-label text-xs">Estimated Calories *</label>
                        <input type="number" name="meal_calories[]" class="form-input" placeholder="e.g., 450" required>
                      </div>
                      <div>
                        <label class="form-label text-xs">Meal Description & Ingredients</label>
                        <textarea name="meal_descriptions[]" class="form-input" placeholder="Detailed meal description, ingredients list, preparation instructions..." rows="2" required></textarea>
                      </div>
                    </div>
                  </div>
                </div>
                
                <button type="button" onclick="addTemplateMeal()" class="btn btn-primary w-full mt-4">
                  <i data-lucide="plus"></i> Add Another Meal
                </button>
                
                <button type="submit" name="save_meal_template" class="btn btn-purple w-full mt-4">
                  <i data-lucide="save"></i> Save Meal Template
                </button>
              </div>
            </div>
          </form>
        </div>

        <!-- Templates List -->
        <?php if (!empty($mealTemplates)): ?>
          <div class="card mt-8">
            <div class="section-header">
              <h2 class="section-title">
                <i data-lucide="library"></i>
                My Meal Templates
              </h2>
              <span class="text-gray-400"><?php echo count($mealTemplates); ?> templates</span>
            </div>
            
            <div class="space-y-4">
              <?php foreach ($mealTemplates as $template): ?>
                <div class="template-card">
                  <div class="flex justify-between items-start mb-4">
                    <div class="flex-1">
                      <div class="flex items-center gap-3 mb-2">
                        <h3 class="font-semibold text-white text-xl"><?php echo htmlspecialchars($template['template_name']); ?></h3>
                        <span class="badge badge-purple"><?php echo ucfirst(str_replace('_', ' ', $template['goal'])); ?></span>
                        <span class="badge badge-green"><?php echo $template['daily_calories']; ?> cal</span>
                      </div>
                      <?php if ($template['description']): ?>
                        <p class="text-gray-300 text-sm mb-3"><?php echo htmlspecialchars($template['description']); ?></p>
                      <?php endif; ?>
                      <div class="grid grid-cols-3 gap-4 mb-3">
                        <div class="text-center">
                          <div class="text-sm font-bold text-yellow-400"><?php echo $template['protein_goal']; ?>g</div>
                          <div class="text-xs text-gray-400">Protein</div>
                        </div>
                        <div class="text-center">
                          <div class="text-sm font-bold text-yellow-400"><?php echo $template['carbs_goal']; ?>g</div>
                          <div class="text-xs text-gray-400">Carbs</div>
                        </div>
                        <div class="text-center">
                          <div class="text-sm font-bold text-yellow-400"><?php echo $template['fat_goal']; ?>g</div>
                          <div class="text-xs text-gray-400">Fat</div>
                        </div>
                      </div>
                      <div class="text-sm text-gray-400">
                        Created: <?php echo date('M j, Y', strtotime($template['created_at'])); ?>
                      </div>
                    </div>
                    <div class="flex gap-2">
                      <button onclick="assignTemplate(<?php echo $template['id']; ?>, '<?php echo htmlspecialchars($template['template_name']); ?>')" class="btn btn-success">
                        <i data-lucide="user-check"></i> Assign
                      </button>
                      <form method="POST" onsubmit="return confirm('Are you sure you want to delete this template?')">
                        <input type="hidden" name="template_id" value="<?php echo $template['id']; ?>">
                        <button type="submit" name="delete_meal_template" class="btn btn-danger">
                          <i data-lucide="trash-2"></i>
                        </button>
                      </form>
                    </div>
                  </div>
                  
                  <div class="table-container">
                    <table>
                      <thead>
                        <tr>
                          <th>Meal</th>
                          <th>Time</th>
                          <th>Calories</th>
                          <th>Description</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($template['meals'] as $index => $meal): ?>
                          <tr>
                            <td class="font-medium">
                              <span class="badge badge-green mr-2"><?php echo $index + 1; ?></span>
                              <?php echo htmlspecialchars($meal['name']); ?>
                            </td>
                            <td><?php echo isset($meal['time']) ? $meal['time'] : 'N/A'; ?></td>
                            <td><?php echo isset($meal['calories']) ? $meal['calories'] : 'N/A'; ?> cal</td>
                            <td class="text-gray-400 text-sm">
                              <?php if (isset($meal['description']) && $meal['description']): ?>
                                <?php echo htmlspecialchars($meal['description']); ?>
                              <?php else: ?>
                                <span class="text-gray-500">-</span>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Assign Template Tab -->
      <div id="assign-tab" class="tab-content">
        <div class="card">
          <div class="section-header">
            <h2 class="section-title">
              <i data-lucide="user-check"></i>
              Assign Template to Client
            </h2>
          </div>
          
          <form method="POST" id="assignTemplateForm">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
              <div class="space-y-6">
                <div>
                  <label class="form-label">Select Template *</label>
                  <select name="template_id" class="form-select" required onchange="updateTemplatePreview(this)">
                    <option value="">Choose a template...</option>
                    <?php foreach ($mealTemplates as $template): ?>
                      <option value="<?php echo $template['id']; ?>" data-meals='<?php echo json_encode($template['meals']); ?>'>
                        <?php echo htmlspecialchars($template['template_name']); ?> (<?php echo ucfirst(str_replace('_', ' ', $template['goal'])); ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                
                <div>
                  <label class="form-label">Select Client *</label>
                  <select name="member_id" class="form-select" required onchange="updateClientGoals(this)">
                    <option value="">Choose a client...</option>
                    <?php foreach ($clients as $client): ?>
                      <option value="<?php echo $client['id']; ?>" data-goals="<?php echo htmlspecialchars($client['fitness_goals']); ?>">
                        <?php echo htmlspecialchars($client['full_name']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div id="assign-client-goals" class="text-sm text-yellow-400 mt-2 hidden">
                    <i data-lucide="target" class="w-4 h-4 inline"></i>
                    <strong>Goals:</strong> <span id="assign-goals-text"></span>
                  </div>
                </div>
                
                <div>
                  <label class="form-label">Plan Name *</label>
                  <input type="text" name="plan_name" class="form-input" placeholder="e.g., Personalized Nutrition Plan" required>
                </div>
                
                <button type="submit" name="assign_template" class="btn btn-success w-full">
                  <i data-lucide="user-check"></i> Assign Template to Client
                </button>
              </div>
              
              <div>
                <h3 class="font-semibold text-white mb-4">Template Preview</h3>
                <div id="template-preview" class="bg-gray-800/50 rounded-lg p-4 min-h-200">
                  <p class="text-gray-400 text-center">Select a template to preview meals</p>
                </div>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- Custom Plan Tab -->
      <div id="custom-tab" class="tab-content">
        <div class="card">
          <div class="section-header">
            <h2 class="section-title">
              <i data-lucide="plus"></i>
              Create Custom Meal Plan
            </h2>
          </div>
          
          <form method="POST" id="mealForm">
            <div class="space-y-4">
              <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Client Selection & Basic Info -->
                <div class="space-y-6">
                  <div>
                    <label class="form-label">Select Client *</label>
                    <select name="member_id" class="form-select" required onchange="updateClientGoals(this)">
                      <option value="">Choose a client...</option>
                      <?php foreach ($clients as $client): ?>
                        <option value="<?php echo $client['id']; ?>" data-goals="<?php echo htmlspecialchars($client['fitness_goals']); ?>">
                          <?php echo htmlspecialchars($client['full_name']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <div id="custom-client-goals" class="text-sm text-yellow-400 mt-2 hidden">
                      <i data-lucide="target" class="w-4 h-4 inline"></i>
                      <strong>Goals:</strong> <span id="custom-goals-text"></span>
                    </div>
                  </div>
                  
                  <div>
                    <label class="form-label">Plan Name *</label>
                    <input type="text" name="plan_name" class="form-input" placeholder="e.g., Weight Loss Nutrition Plan" required>
                  </div>
                  
                  <div>
                    <label class="form-label">Nutritional Guidance</label>
                    <textarea name="description" class="form-input" rows="3" placeholder="Provide dietary advice, food timing recommendations, and nutritional focus..."></textarea>
                  </div>
                  
                  <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                      <label class="form-label">Daily Calories</label>
                      <input type="number" name="daily_calories" class="form-input" placeholder="2000" required>
                    </div>
                    <div>
                      <label class="form-label">Protein (g)</label>
                      <input type="number" name="protein_goal" class="form-input" step="0.1" placeholder="150" required>
                    </div>
                    <div>
                      <label class="form-label">Carbs (g)</label>
                      <input type="number" name="carbs_goal" class="form-input" step="0.1" placeholder="250" required>
                    </div>
                    <div>
                      <label class="form-label">Fat (g)</label>
                      <input type="number" name="fat_goal" class="form-input" step="0.1" placeholder="70" required>
                    </div>
                  </div>
                </div>

                <!-- Meals Section -->
                <div>
                  <div class="flex justify-between items-center mb-4">
                    <label class="form-label">Daily Meal Structure *</label>
                    <span class="text-sm text-gray-400" id="meal-count">1 meal added</span>
                  </div>
                  
                  <div id="meals-container" class="space-y-4 max-h-96 overflow-y-auto pr-2">
                    <div class="meal-item">
                      <div class="flex justify-between items-center mb-3">
                        <h4 class="font-semibold text-white">Meal #1</h4>
                        <button type="button" onclick="this.parentElement.parentElement.remove(); updateMealCount()" class="text-red-400 hover:text-red-300">
                          <i data-lucide="trash-2" class="w-4 h-4"></i>
                        </button>
                      </div>
                      <div class="space-y-3">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                          <div>
                            <label class="form-label text-xs">Meal Name *</label>
                            <input type="text" name="meal_names[]" class="form-input" placeholder="e.g., Breakfast" required>
                          </div>
                          <div>
                            <label class="form-label text-xs">Time *</label>
                            <input type="text" name="meal_times[]" class="form-input" placeholder="e.g., 8:00 AM" required>
                          </div>
                        </div>
                        <div>
                          <label class="form-label text-xs">Estimated Calories *</label>
                          <input type="number" name="meal_calories[]" class="form-input" placeholder="e.g., 450" required>
                        </div>
                        <div>
                          <label class="form-label text-xs">Meal Description & Ingredients</label>
                          <textarea name="meal_descriptions[]" class="form-input" placeholder="Detailed meal description, ingredients list, preparation instructions..." rows="2" required></textarea>
                        </div>
                      </div>
                    </div>
                  </div>
                  
                  <button type="button" onclick="addMeal()" class="btn btn-primary w-full mt-4">
                    <i data-lucide="plus"></i> Add Another Meal
                  </button>
                </div>
              </div>
              
              <button type="submit" name="save_meal_plan" class="btn btn-success w-full">
                <i data-lucide="save"></i> Create Meal Plan
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- All Plans Tab -->
      <div id="plans-tab" class="tab-content">
        <div class="card">
          <div class="section-header">
            <h2 class="section-title">
              <i data-lucide="list"></i>
              All Client Meal Plans
            </h2>
            <span class="text-gray-400"><?php echo count($mealPlans); ?> active plans</span>
          </div>
          
          <div class="space-y-6">
            <?php if (!empty($mealPlans)): ?>
              <?php foreach ($mealPlans as $plan): ?>
                <div class="plan-card">
                  <div class="flex justify-between items-start mb-4">
                    <div class="flex-1">
                      <div class="flex items-center gap-3 mb-2">
                        <h3 class="font-semibold text-white text-xl"><?php echo htmlspecialchars($plan['plan_name']); ?></h3>
                        <span class="badge badge-green"><?php echo $plan['daily_calories']; ?> cal</span>
                      </div>
                      <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                        <div>
                          <span class="text-gray-400">Client:</span>
                          <span class="text-white font-medium"><?php echo htmlspecialchars($plan['full_name']); ?></span>
                        </div>
                        <div>
                          <span class="text-gray-400">Goals:</span>
                          <span class="text-yellow-400"><?php echo htmlspecialchars($plan['fitness_goals']); ?></span>
                        </div>
                        <div>
                          <span class="text-gray-400">Created:</span>
                          <span class="text-gray-300"><?php echo date('M j, Y', strtotime($plan['created_at'])); ?></span>
                        </div>
                      </div>
                    </div>
                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this meal plan?')">
                      <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                      <button type="submit" name="delete_meal_plan" class="btn btn-danger">
                        <i data-lucide="trash-2"></i> Delete
                      </button>
                    </form>
                  </div>
                  
                  <?php if ($plan['description']): ?>
                    <p class="text-gray-300 mb-4 text-sm bg-gray-800/50 p-3 rounded-lg"><?php echo htmlspecialchars($plan['description']); ?></p>
                  <?php endif; ?>
                  
                  <div class="grid grid-cols-3 gap-4 mb-4">
                    <div class="text-center">
                      <div class="text-lg font-bold text-yellow-400"><?php echo $plan['protein_goal']; ?>g</div>
                      <div class="text-xs text-gray-400">Protein</div>
                    </div>
                    <div class="text-center">
                      <div class="text-lg font-bold text-yellow-400"><?php echo $plan['carbs_goal']; ?>g</div>
                      <div class="text-xs text-gray-400">Carbs</div>
                    </div>
                    <div class="text-center">
                      <div class="text-lg font-bold text-yellow-400"><?php echo $plan['fat_goal']; ?>g</div>
                      <div class="text-xs text-gray-400">Fat</div>
                    </div>
                  </div>
                  
                  <div class="table-container">
                    <table>
                      <thead>
                        <tr>
                          <th>Meal</th>
                          <th>Time</th>
                          <th>Calories</th>
                          <th>Description</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($plan['meals'] as $index => $meal): ?>
                          <tr>
                            <td class="font-medium">
                              <span class="badge badge-green mr-2"><?php echo $index + 1; ?></span>
                              <?php echo htmlspecialchars($meal['name']); ?>
                            </td>
                            <td><?php echo isset($meal['time']) ? $meal['time'] : 'N/A'; ?></td>
                            <td><?php echo isset($meal['calories']) ? $meal['calories'] : 'N/A'; ?> cal</td>
                            <td class="text-gray-400 text-sm">
                              <?php if (isset($meal['description']) && $meal['description']): ?>
                                <?php echo htmlspecialchars($meal['description']); ?>
                              <?php else: ?>
                                <span class="text-gray-500">-</span>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="empty-state">
                <i data-lucide="utensils" class="w-16 h-16 mx-auto mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-400 mb-2">No Meal Plans Yet</h3>
                <p class="text-gray-500">Create your first meal plan or assign a template to get started!</p>
                <div class="flex gap-3 justify-center mt-4">
                  <button onclick="showTab('templates-tab')" class="btn btn-purple">
                    <i data-lucide="layout-template"></i> Create Template
                  </button>
                  <button onclick="showTab('custom-tab')" class="btn btn-primary">
                    <i data-lucide="plus"></i> Create Plan
                  </button>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </main>
  </div>

  <!-- QR Scanner Container - MOVABLE & TOGGLEABLE -->
  <div id="qrScanner" class="qr-scanner-container hidden">
    <div class="qr-scanner-header cursor-move" id="qrScannerHeader">
      <div class="qr-scanner-title">
        <i data-lucide="scan"></i>
        <span>QR Attendance Scanner</span>
      </div>
      <div class="flex items-center gap-2">
        <div id="qrScannerStatus" class="qr-scanner-status active">Active</div>
        <button id="closeQRScanner" class="text-gray-400 hover:text-white transition-colors">
          <i data-lucide="x" class="w-4 h-4"></i>
        </button>
      </div>
    </div>
    <input type="text" id="qrInput" class="qr-input" placeholder="Scan QR code or enter code manually..." autocomplete="off">
    <div class="scanner-instructions">
      Press Enter or click Process after scanning
    </div>
    <div class="qr-scanner-buttons">
      <button id="processQR" class="qr-scanner-btn primary">
        <i data-lucide="check"></i> Process
      </button>
      <button id="toggleScanner" class="qr-scanner-btn secondary">
        <i data-lucide="power"></i> Disable
      </button>
    </div>
    <div id="qrResult" class="qr-scanner-result"></div>
  </div>

  <!-- QR Scanner Toggle Button -->
  <button id="toggleQRScannerBtn" class="fixed bottom-4 right-4 bg-yellow-500 hover:bg-yellow-600 text-white rounded-full w-12 h-12 flex items-center justify-center cursor-pointer shadow-lg z-40 transition-all duration-300">
    <i data-lucide="scan" class="w-6 h-6"></i>
  </button>

  <!-- Assign Template Modal -->
  <div id="assignModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-gray-800 rounded-lg p-6 w-full max-w-md">
      <h3 class="text-xl font-bold text-yellow-400 mb-4">Assign Template</h3>
      <form method="POST" id="quickAssignForm">
        <input type="hidden" name="template_id" id="modal_template_id">
        <div class="space-y-4">
          <div>
            <label class="form-label">Plan Name</label>
            <input type="text" name="plan_name" id="modal_plan_name" class="form-input" required>
          </div>
          <div>
            <label class="form-label">Select Client</label>
            <select name="member_id" class="form-select" required>
              <option value="">Choose a client...</option>
              <?php foreach ($clients as $client): ?>
                <option value="<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['full_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="flex gap-3 mt-6">
          <button type="button" onclick="closeAssignModal()" class="btn btn-danger flex-1">Cancel</button>
          <button type="submit" name="assign_template" class="btn btn-success flex-1">Assign</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // QR Scanner functionality - MOVABLE & TOGGLEABLE VERSION
    let qrScannerActive = true;
    let lastProcessedQR = '';
    let lastProcessedTime = 0;
    let qrProcessing = false;
    let qrCooldown = false;
    let isDragging = false;
    let dragOffset = { x: 0, y: 0 };

    function setupQRScanner() {
        const qrScanner = document.getElementById('qrScanner');
        const qrScannerHeader = document.getElementById('qrScannerHeader');
        const qrInput = document.getElementById('qrInput');
        const processQRBtn = document.getElementById('processQR');
        const toggleScannerBtn = document.getElementById('toggleScanner');
        const toggleQRScannerBtn = document.getElementById('toggleQRScannerBtn');
        const closeQRScannerBtn = document.getElementById('closeQRScanner');
        const qrScannerStatus = document.getElementById('qrScannerStatus');

        // Toggle QR scanner visibility
        toggleQRScannerBtn.addEventListener('click', function() {
            qrScanner.classList.toggle('hidden');
            if (!qrScanner.classList.contains('hidden') && qrScannerActive) {
                setTimeout(() => qrInput.focus(), 100);
            }
        });

        // Close QR scanner
        closeQRScannerBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            qrScanner.classList.add('hidden');
        });

        // Drag and drop functionality
        qrScannerHeader.addEventListener('mousedown', startDrag);
        qrScannerHeader.addEventListener('touchstart', function(e) {
            startDrag(e.touches[0]);
        });

        document.addEventListener('mousemove', drag);
        document.addEventListener('touchmove', function(e) {
            drag(e.touches[0]);
            e.preventDefault();
        }, { passive: false });

        document.addEventListener('mouseup', stopDrag);
        document.addEventListener('touchend', stopDrag);

        function startDrag(e) {
            if (e.target.closest('button')) return; // Don't drag if clicking buttons
            
            isDragging = true;
            qrScanner.classList.add('dragging');
            
            const rect = qrScanner.getBoundingClientRect();
            dragOffset.x = e.clientX - rect.left;
            dragOffset.y = e.clientY - rect.top;
            
            document.body.classList.add('cursor-grabbing');
        }

        function drag(e) {
            if (!isDragging) return;
            
            const x = e.clientX - dragOffset.x;
            const y = e.clientY - dragOffset.y;
            
            // Keep within viewport bounds
            const maxX = window.innerWidth - qrScanner.offsetWidth;
            const maxY = window.innerHeight - qrScanner.offsetHeight;
            
            const boundedX = Math.max(0, Math.min(x, maxX));
            const boundedY = Math.max(0, Math.min(y, maxY));
            
            qrScanner.style.left = boundedX + 'px';
            qrScanner.style.top = boundedY + 'px';
            qrScanner.style.right = 'auto';
            qrScanner.style.bottom = 'auto';
            qrScanner.style.transform = 'none';
        }

        function stopDrag() {
            if (!isDragging) return;
            
            isDragging = false;
            qrScanner.classList.remove('dragging');
            document.body.classList.remove('cursor-grabbing');
        }

        // Process QR code when Enter is pressed
        qrInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && qrScannerActive && !qrProcessing && !qrCooldown) {
                processQRCode();
                e.preventDefault();
            }
        });
        
        // Process QR code when button is clicked
        processQRBtn.addEventListener('click', function() {
            if (qrScannerActive && !qrProcessing && !qrCooldown) {
                processQRCode();
            }
        });
        
        // Toggle scanner on/off
        toggleScannerBtn.addEventListener('click', function() {
            qrScannerActive = !qrScannerActive;
            
            if (qrScannerActive) {
                qrScannerStatus.textContent = 'Active';
                qrScannerStatus.classList.remove('disabled');
                qrScannerStatus.classList.add('active');
                toggleScannerBtn.innerHTML = '<i data-lucide="power"></i> Disable';
                qrInput.disabled = false;
                qrInput.placeholder = 'Scan QR code or enter code manually...';
                processQRBtn.disabled = false;
                if (!qrScanner.classList.contains('hidden')) {
                    qrInput.focus();
                }
                showToast('QR scanner enabled', 'success', 2000);
            } else {
                qrScannerStatus.textContent = 'Disabled';
                qrScannerStatus.classList.remove('active');
                qrScannerStatus.classList.add('disabled');
                toggleScannerBtn.innerHTML = '<i data-lucide="power"></i> Enable';
                qrInput.disabled = true;
                qrInput.placeholder = 'Scanner disabled';
                processQRBtn.disabled = true;
                showToast('QR scanner disabled', 'warning', 2000);
            }
            
            lucide.createIcons();
        });
        
        // Smart focus management
        document.addEventListener('click', function(e) {
            if (qrScannerActive && 
                !qrScanner.classList.contains('hidden') &&
                !e.target.closest('form') && 
                !e.target.closest('select') && 
                !e.target.closest('button') &&
                e.target !== qrInput) {
                setTimeout(() => {
                    if (document.activeElement.tagName !== 'INPUT' && 
                        document.activeElement.tagName !== 'TEXTAREA' &&
                        document.activeElement.tagName !== 'SELECT') {
                        qrInput.focus();
                    }
                }, 100);
            }
        });
        
        // Clear input after successful processing
        qrInput.addEventListener('input', function() {
            if (this.value === lastProcessedQR) {
                this.value = '';
            }
        });
        
        // Close scanner with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !qrScanner.classList.contains('hidden')) {
                qrScanner.classList.add('hidden');
            }
        });
        
        // Initial focus
        setTimeout(() => {
            if (qrScannerActive && !qrScanner.classList.contains('hidden')) {
                qrInput.focus();
            }
        }, 1000);
    }

    function processQRCode() {
        if (qrProcessing || qrCooldown) return;
        
        const qrInput = document.getElementById('qrInput');
        const qrResult = document.getElementById('qrResult');
        const processQRBtn = document.getElementById('processQR');
        const qrCode = qrInput.value.trim();
        
        if (!qrCode) {
            showQRResult('error', 'Error', 'Please enter a QR code');
            showToast('Please enter a QR code', 'error');
            return;
        }
        
        // Prevent processing the same QR code twice in quick succession
        const currentTime = Date.now();
        if (qrCode === lastProcessedQR && (currentTime - lastProcessedTime) < 3000) {
            const timeLeft = Math.ceil((3000 - (currentTime - lastProcessedTime)) / 1000);
            showQRResult('error', 'Cooldown', `Please wait ${timeLeft} seconds before scanning this QR code again`);
            showToast(`Please wait ${timeLeft} seconds before rescanning`, 'warning');
            qrInput.value = '';
            qrInput.focus();
            return;
        }
        
        qrProcessing = true;
        qrCooldown = true;
        setLoadingState(processQRBtn, true);
        processQRBtn.innerHTML = '<i data-lucide="loader" class="animate-spin"></i> Processing';
        lucide.createIcons();
        
        // Show processing message
        showQRResult('info', 'Processing', 'Scanning QR code...');
        showToast('Processing QR code...', 'info', 2000);
        
        // Make AJAX call to process the QR code
        fetch('process_qr.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'qr_code=' + encodeURIComponent(qrCode)
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                showQRResult('success', 'Success', data.message);
                showToast(data.message, 'success');
                lastProcessedQR = qrCode;
                lastProcessedTime = Date.now();
                
                // Update attendance count immediately
                const currentCount = parseInt(document.getElementById('attendanceCount').textContent);
                document.getElementById('attendanceCount').textContent = currentCount + 1;
                
                // Trigger custom event for other components
                window.dispatchEvent(new CustomEvent('qrScanSuccess', { 
                    detail: { message: data.message, qrCode: qrCode } 
                }));
                
            } else {
                showQRResult('error', 'Error', data.message || 'Unknown error occurred');
                showToast(data.message || 'Unknown error occurred', 'error');
                lastProcessedQR = qrCode;
                lastProcessedTime = Date.now();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showQRResult('error', 'Network Error', 'Failed to process QR code. Please try again.');
            showToast('Network error occurred', 'error');
            lastProcessedQR = qrCode;
            lastProcessedTime = Date.now();
        })
        .finally(() => {
            qrProcessing = false;
            setLoadingState(processQRBtn, false);
            processQRBtn.innerHTML = '<i data-lucide="check"></i> Process';
            lucide.createIcons();
            
            // Clear input and refocus after processing
            setTimeout(() => {
                qrInput.value = '';
                const qrScanner = document.getElementById('qrScanner');
                if (qrScannerActive && !qrScanner.classList.contains('hidden')) {
                    qrInput.focus();
                }
            }, 500);
            
            // Enable scanning again after 3 seconds
            setTimeout(() => {
                qrCooldown = false;
            }, 3000);
        });
    }

    function showQRResult(type, title, message) {
        const qrResult = document.getElementById('qrResult');
        qrResult.className = 'qr-scanner-result ' + type;
        qrResult.innerHTML = `
            <div class="qr-result-title">${title}</div>
            <div class="qr-result-message">${message}</div>
        `;
        qrResult.style.display = 'block';
        
        // Auto-hide result after appropriate time
        let hideTime = type === 'success' ? 4000 : 5000;
        if (title === 'Cooldown') hideTime = 3000;
        if (title === 'Processing') hideTime = 2000;
        
        setTimeout(() => {
            qrResult.style.display = 'none';
        }, hideTime);
    }

    function setLoadingState(button, isLoading) {
        if (isLoading) {
            button.disabled = true;
            button.style.opacity = '0.7';
        } else {
            button.disabled = false;
            button.style.opacity = '1';
        }
    }

    function showToast(message, type = 'info', duration = 3000) {
        // Create toast element
        const toast = document.createElement('div');
        toast.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg transition-all duration-300 transform translate-x-full ${
            type === 'success' ? 'bg-green-500' : 
            type === 'error' ? 'bg-red-500' : 
            type === 'warning' ? 'bg-yellow-500' : 'bg-blue-500'
        } text-white`;
        toast.innerHTML = `
            <div class="flex items-center gap-2">
                <i data-lucide="${
                    type === 'success' ? 'check-circle' : 
                    type === 'error' ? 'alert-circle' : 
                    type === 'warning' ? 'alert-triangle' : 'info'
                }" class="w-5 h-5"></i>
                <span>${message}</span>
            </div>
        `;
        
        document.body.appendChild(toast);
        lucide.createIcons();
        
        // Animate in
        setTimeout(() => {
            toast.classList.remove('translate-x-full');
        }, 100);
        
        // Auto remove
        setTimeout(() => {
            toast.classList.add('translate-x-full');
            setTimeout(() => {
                document.body.removeChild(toast);
            }, 300);
        }, duration);
    }

    // Global function to open QR scanner
    function openQRScanner() {
        const qrScanner = document.getElementById('qrScanner');
        const qrInput = document.getElementById('qrInput');
        
        qrScanner.classList.remove('hidden');
        if (qrScannerActive) {
            setTimeout(() => qrInput.focus(), 100);
        }
    }

    // Tab functionality
    function showTab(tabId) {
      // Hide all tabs
      document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
      });
      document.querySelectorAll('.tab').forEach(tab => {
        tab.classList.remove('active');
      });
      
      // Show selected tab
      document.getElementById(tabId).classList.add('active');
      event.currentTarget.classList.add('active');
    }

    // Sidebar functionality
    document.addEventListener('DOMContentLoaded', function() {
        lucide.createIcons();
        setupQRScanner();
        
        document.getElementById('toggleSidebar').addEventListener('click', () => {
            const sidebar = document.getElementById('sidebar');
            if (sidebar.classList.contains('w-60')) {
                sidebar.classList.remove('w-60');
                sidebar.classList.add('w-16', 'sidebar-collapsed');
            } else {
                sidebar.classList.remove('w-16', 'sidebar-collapsed');
                sidebar.classList.add('w-60');
            }
        });

        // Submenu toggles
        const membersToggle = document.getElementById('membersToggle');
        const membersSubmenu = document.getElementById('membersSubmenu');
        const membersChevron = document.getElementById('membersChevron');
        
        membersToggle.addEventListener('click', () => {
            membersSubmenu.classList.toggle('open');
            membersChevron.classList.toggle('rotate');
        });

        const plansToggle = document.getElementById('plansToggle');
        const plansSubmenu = document.getElementById('plansSubmenu');
        const plansChevron = document.getElementById('plansChevron');
        
        plansToggle.addEventListener('click', () => {
            plansSubmenu.classList.toggle('open');
            plansChevron.classList.toggle('rotate');
        });
        
        // Hover to open sidebar
        const sidebar = document.getElementById('sidebar');
        sidebar.addEventListener('mouseenter', () => {
            if (sidebar.classList.contains('sidebar-collapsed')) {
                sidebar.classList.remove('w-16', 'sidebar-collapsed');
                sidebar.classList.add('w-60');
            }
        });
        
        sidebar.addEventListener('mouseleave', () => {
            if (!sidebar.classList.contains('sidebar-collapsed') && window.innerWidth > 768) {
                sidebar.classList.remove('w-60');
                sidebar.classList.add('w-16', 'sidebar-collapsed');
            }
        });

        // Dropdown functionality
        const notificationBell = document.getElementById('notificationBell');
        const notificationDropdown = document.getElementById('notificationDropdown');
        const userMenuButton = document.getElementById('userMenuButton');
        const userDropdown = document.getElementById('userDropdown');
        
        // Close all dropdowns
        function closeAllDropdowns() {
            notificationDropdown.classList.add('hidden');
            userDropdown.classList.add('hidden');
        }
        
        // Toggle notification dropdown
        notificationBell.addEventListener('click', function(e) {
            e.stopPropagation();
            const isHidden = notificationDropdown.classList.contains('hidden');
            
            closeAllDropdowns();
            
            if (isHidden) {
                notificationDropdown.classList.remove('hidden');
            }
        });
        
        // Toggle user dropdown
        userMenuButton.addEventListener('click', function(e) {
            e.stopPropagation();
            const isHidden = userDropdown.classList.contains('hidden');
            
            closeAllDropdowns();
            
            if (isHidden) {
                userDropdown.classList.remove('hidden');
            }
        });
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!notificationDropdown.contains(e.target) && !notificationBell.contains(e.target) &&
                !userDropdown.contains(e.target) && !userMenuButton.contains(e.target)) {
                closeAllDropdowns();
            }
        });
        
        // Close dropdowns when pressing Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAllDropdowns();
            }
        });

        updateMealCount();
        updateTemplateMealCount();
    });

    // Function to mark notification as read
    function markNotificationAsRead(notificationId, element) {
        fetch('trainermealplan.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'mark_as_read=true&notification_id=' + notificationId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Remove unread styling
                element.classList.remove('notification-unread');
                element.style.opacity = '0.7';
                
                // Update badge count
                const badge = document.getElementById('notificationBadge');
                if (badge) {
                    let currentCount = parseInt(badge.textContent);
                    if (currentCount > 1) {
                        badge.textContent = currentCount - 1;
                    } else {
                        badge.style.display = 'none';
                    }
                }
            }
        })
        .catch(error => console.error('Error:', error));
    }

    function updateClientGoals(select) {
        const selectedOption = select.options[select.selectedIndex];
        const goals = selectedOption.getAttribute('data-goals');
        const context = select.closest('.tab-content').id;
        
        let goalsElement, goalsText;
        if (context === 'assign-tab') {
            goalsElement = document.getElementById('assign-client-goals');
            goalsText = document.getElementById('assign-goals-text');
        } else if (context === 'custom-tab') {
            goalsElement = document.getElementById('custom-client-goals');
            goalsText = document.getElementById('custom-goals-text');
        }
        
        if (goals && select.value) {
            goalsElement.classList.remove('hidden');
            goalsText.textContent = goals;
        } else {
            goalsElement.classList.add('hidden');
        }
    }
    
    function updateMealCount() {
        const count = document.querySelectorAll('#meals-container .meal-item').length;
        document.getElementById('meal-count').textContent = count + ' meal' + (count !== 1 ? 's' : '') + ' added';
    }
    
    function updateTemplateMealCount() {
        const count = document.querySelectorAll('#template-meals-container .meal-item').length;
        document.getElementById('template-meal-count').textContent = count + ' meal' + (count !== 1 ? 's' : '') + ' added';
    }
    
    function addMeal() {
        const container = document.getElementById('meals-container');
        const mealCount = container.children.length + 1;
        
        const newMeal = document.createElement('div');
        newMeal.className = 'meal-item';
        newMeal.innerHTML = `
            <div class="flex justify-between items-center mb-3">
                <h4 class="font-semibold text-white">Meal #${mealCount}</h4>
                <button type="button" onclick="this.parentElement.parentElement.remove(); updateMealCount()" class="text-red-400 hover:text-red-300">
                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                </button>
            </div>
            <div class="space-y-3">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="form-label text-xs">Meal Name *</label>
                        <input type="text" name="meal_names[]" class="form-input" placeholder="e.g., Breakfast" required>
                    </div>
                    <div>
                        <label class="form-label text-xs">Time *</label>
                        <input type="text" name="meal_times[]" class="form-input" placeholder="e.g., 8:00 AM" required>
                    </div>
                </div>
                <div>
                    <label class="form-label text-xs">Estimated Calories *</label>
                    <input type="number" name="meal_calories[]" class="form-input" placeholder="e.g., 450" required>
                </div>
                <div>
                    <label class="form-label text-xs">Meal Description & Ingredients</label>
                    <textarea name="meal_descriptions[]" class="form-input" placeholder="Detailed meal description, ingredients list, preparation instructions..." rows="2" required></textarea>
                </div>
            </div>
        `;
        container.appendChild(newMeal);
        updateMealCount();
        lucide.createIcons();
    }
    
    function addTemplateMeal() {
        const container = document.getElementById('template-meals-container');
        const mealCount = container.children.length + 1;
        
        const newMeal = document.createElement('div');
        newMeal.className = 'meal-item';
        newMeal.innerHTML = `
            <div class="flex justify-between items-center mb-3">
                <h4 class="font-semibold text-white">Meal #${mealCount}</h4>
                <button type="button" onclick="this.parentElement.parentElement.remove(); updateTemplateMealCount()" class="text-red-400 hover:text-red-300">
                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                </button>
            </div>
            <div class="space-y-3">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="form-label text-xs">Meal Name *</label>
                        <input type="text" name="meal_names[]" class="form-input" placeholder="e.g., Breakfast" required>
                    </div>
                    <div>
                        <label class="form-label text-xs">Time *</label>
                        <input type="text" name="meal_times[]" class="form-input" placeholder="e.g., 8:00 AM" required>
                    </div>
                </div>
                <div>
                    <label class="form-label text-xs">Estimated Calories *</label>
                    <input type="number" name="meal_calories[]" class="form-input" placeholder="e.g., 450" required>
                </div>
                <div>
                    <label class="form-label text-xs">Meal Description & Ingredients</label>
                    <textarea name="meal_descriptions[]" class="form-input" placeholder="Detailed meal description, ingredients list, preparation instructions..." rows="2" required></textarea>
                </div>
            </div>
        `;
        container.appendChild(newMeal);
        updateTemplateMealCount();
        lucide.createIcons();
    }
    
    function updateTemplatePreview(select) {
        const selectedOption = select.options[select.selectedIndex];
        const mealsJson = selectedOption.getAttribute('data-meals');
        const preview = document.getElementById('template-preview');
        
        if (mealsJson && select.value) {
            const meals = JSON.parse(mealsJson);
            let html = '<div class="space-y-3">';
            meals.forEach((meal, index) => {
                html += `
                    <div class="bg-gray-700/50 rounded-lg p-3">
                        <div class="font-semibold text-white">${index + 1}. ${meal.name}</div>
                        <div class="text-sm text-gray-300 flex justify-between mt-2">
                            <span>Time: ${meal.time || 'N/A'}</span>
                            <span>Calories: ${meal.calories || 'N/A'}</span>
                        </div>
                        ${meal.description ? `<div class="text-xs text-gray-400 mt-1">${meal.description}</div>` : ''}
                    </div>
                `;
            });
            html += '</div>';
            preview.innerHTML = html;
        } else {
            preview.innerHTML = '<p class="text-gray-400 text-center">Select a template to preview meals</p>';
        }
    }
    
    function assignTemplate(templateId, templateName) {
        document.getElementById('modal_template_id').value = templateId;
        document.getElementById('modal_plan_name').value = templateName + ' - Customized';
        document.getElementById('assignModal').classList.remove('hidden');
    }
    
    function closeAssignModal() {
        document.getElementById('assignModal').classList.add('hidden');
    }
    
    // Close modal when clicking outside
    document.getElementById('assignModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeAssignModal();
        }
    });
    
    // Form validation
    document.getElementById('mealForm').addEventListener('submit', function(e) {
        const meals = document.querySelectorAll('#meals-container input[name="meal_names[]"]');
        let hasMeals = false;
        meals.forEach(input => {
            if (input.value.trim() !== '') hasMeals = true;
        });
        
        if (!hasMeals) {
            e.preventDefault();
            alert('Please add at least one meal to the meal plan.');
        }
    });
    
    document.getElementById('templateForm').addEventListener('submit', function(e) {
        const meals = document.querySelectorAll('#template-meals-container input[name="meal_names[]"]');
        let hasMeals = false;
        meals.forEach(input => {
            if (input.value.trim() !== '') hasMeals = true;
        });
        
        if (!hasMeals) {
            e.preventDefault();
            alert('Please add at least one meal to the template.');
        }
    });
  </script>
</body>
</html>

<?php 
// Helper functions for notifications
function getNotificationIcon($type) {
    $icons = [
        'announcement' => '<i data-lucide="megaphone" class="w-4 h-4 text-yellow-400"></i>',
        'membership' => '<i data-lucide="id-card" class="w-4 h-4 text-blue-400"></i>',
        'message' => '<i data-lucide="message-circle" class="w-4 h-4 text-green-400"></i>',
        'system' => '<i data-lucide="settings" class="w-4 h-4 text-gray-400"></i>'
    ];
    return $icons[$type] ?? '<i data-lucide="bell" class="w-4 h-4 text-gray-400"></i>';
}

function timeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $time);
    }
}

$conn->close(); 
?>



