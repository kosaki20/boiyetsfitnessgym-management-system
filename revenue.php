<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Handle AJAX requests FIRST, before any HTML output
if (isset($_GET['ajax']) && $_GET['ajax'] == 'chart_data') {
    // Set JSON header immediately
    header('Content-Type: application/json');
    ob_clean(); // Clear any output buffers
    
    // Database connection for AJAX
    $servername = "localhost";
    $username = "root";
    $password = "";
    $dbname = "boiyetsdb";

    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        exit();
    }

    // Check if user is authenticated
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        $conn->close();
        exit();
    }

    $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-1 month'));
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    $tab = $_GET['tab'] ?? 'revenue';
    
    try {
        if ($tab == 'revenue') {
            // Get daily revenue data - IMPROVED VERSION
            $chart_sql = "SELECT 
                            dates.date as date,
                            COALESCE(SUM(re.amount), 0) as daily_revenue,
                            COALESCE(SUM(mp.amount), 0) as membership_revenue,
                            (COALESCE(SUM(re.amount), 0) + COALESCE(SUM(mp.amount), 0)) as total_revenue
                          FROM (
                            SELECT DATE(revenue_date) as date FROM revenue_entries 
                            WHERE revenue_date BETWEEN ? AND ?
                            UNION 
                            SELECT DATE(payment_date) as date FROM membership_payments 
                            WHERE payment_date BETWEEN ? AND ?
                            AND status = 'completed'
                            UNION
                            SELECT DATE(? + INTERVAL seq.seq DAY) as date
                            FROM (
                                SELECT a.N + b.N * 10 + c.N * 100 as seq
                                FROM (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a
                                CROSS JOIN (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b
                                CROSS JOIN (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) c
                            ) seq
                            WHERE DATE(? + INTERVAL seq.seq DAY) BETWEEN ? AND ?
                          ) dates
                          LEFT JOIN revenue_entries re ON dates.date = DATE(re.revenue_date)
                          LEFT JOIN membership_payments mp ON dates.date = DATE(mp.payment_date) AND mp.status = 'completed'
                          GROUP BY dates.date
                          ORDER BY dates.date ASC";
            
            $chart_stmt = $conn->prepare($chart_sql);
            if (!$chart_stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            
            $chart_stmt->bind_param("ssssssss", $start_date, $end_date, $start_date, $end_date, $start_date, $start_date, $start_date, $end_date);
            if (!$chart_stmt->execute()) {
                throw new Exception("Execute failed: " . $chart_stmt->error);
            }
            $chart_result = $chart_stmt->get_result();
            
            $revenue_chart_data = [];
            while($row = $chart_result->fetch_assoc()) {
                $total_revenue = floatval($row['daily_revenue']) + floatval($row['membership_revenue']);
                $revenue_chart_data[$row['date']] = $total_revenue;
            }
            $chart_stmt->close();
            
            // Get category data for chart - IMPROVED VERSION
            $category_sql = "SELECT 
                            'Membership Fees' as category_name,
                            '#3b82f6' as category_color,
                            COALESCE(SUM(mp.amount), 0) as total_amount,
                            COUNT(mp.id) as transaction_count,
                            COALESCE(AVG(mp.amount), 0) as average_amount
                          FROM membership_payments mp
                          WHERE mp.payment_date BETWEEN ? AND ?
                          AND mp.status = 'completed'
                          
                          UNION ALL
                          
                          SELECT 
                            rc.name as category_name,
                            rc.color as category_color,
                            COALESCE(SUM(re.amount), 0) as total_amount,
                            COUNT(re.id) as transaction_count,
                            COALESCE(AVG(re.amount), 0) as average_amount
                          FROM revenue_categories rc
                          LEFT JOIN revenue_entries re ON rc.id = re.category_id 
                            AND re.revenue_date BETWEEN ? AND ?
                          WHERE rc.id IN (1, 4)
                          GROUP BY rc.id, rc.name, rc.color
                          
                          HAVING total_amount > 0
                          ORDER BY total_amount DESC";
            
            $category_stmt = $conn->prepare($category_sql);
            if (!$category_stmt) {
                throw new Exception("Prepare failed: " . $category_stmt->error);
            }
            
            $category_stmt->bind_param("ssss", $start_date, $end_date, $start_date, $end_date);
            if (!$category_stmt->execute()) {
                throw new Exception("Execute failed: " . $category_stmt->error);
            }
            $category_result = $category_stmt->get_result();
            
            $category_labels = [];
            $category_data = [];
            $category_colors = [];
            $category_details = [];
            
            while($row = $category_result->fetch_assoc()) {
                $category_labels[] = $row['category_name'];
                $category_data[] = floatval($row['total_amount']);
                $category_colors[] = $row['category_color'];
                $category_details[] = [
                    'transactions' => $row['transaction_count'],
                    'average' => floatval($row['average_amount'])
                ];
            }
            $category_stmt->close();
            
            // Generate chart labels with all dates in range
            $chart_labels = [];
            $chart_revenue = [];
            
            $current_date = $start_date;
            while (strtotime($current_date) <= strtotime($end_date)) {
                $date_key = date('Y-m-d', strtotime($current_date));
                $label = date('M j', strtotime($current_date));
                
                $chart_labels[] = $label;
                $chart_revenue[] = $revenue_chart_data[$date_key] ?? 0;
                
                $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
            }
            
            echo json_encode([
                'success' => true,
                'line_chart' => [
                    'labels' => $chart_labels,
                    'data' => $chart_revenue
                ],
                'pie_chart' => [
                    'labels' => $category_labels,
                    'data' => $category_data,
                    'colors' => $category_colors,
                    'details' => $category_details
                ]
            ]);
            
        } elseif ($tab == 'expenses') {
            // Get daily expenses for chart - IMPROVED VERSION
            $chart_sql = "SELECT 
                            dates.date as date,
                            COALESCE(SUM(e.amount), 0) as daily_expenses
                          FROM (
                            SELECT DATE(expense_date) as date FROM expenses 
                            WHERE expense_date BETWEEN ? AND ?
                            UNION
                            SELECT DATE(? + INTERVAL seq.seq DAY) as date
                            FROM (
                                SELECT a.N + b.N * 10 + c.N * 100 as seq
                                FROM (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a
                                CROSS JOIN (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b
                                CROSS JOIN (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) c
                            ) seq
                            WHERE DATE(? + INTERVAL seq.seq DAY) BETWEEN ? AND ?
                          ) dates
                          LEFT JOIN expenses e ON dates.date = DATE(e.expense_date)
                          GROUP BY dates.date 
                          ORDER BY dates.date ASC";
            
            $chart_stmt = $conn->prepare($chart_sql);
            if (!$chart_stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            
            $chart_stmt->bind_param("ssssss", $start_date, $end_date, $start_date, $start_date, $start_date, $end_date);
            if (!$chart_stmt->execute()) {
                throw new Exception("Execute failed: " . $chart_stmt->error);
            }
            $chart_result = $chart_stmt->get_result();
            
            $expense_chart_data = [];
            while($row = $chart_result->fetch_assoc()) {
                $expense_chart_data[$row['date']] = floatval($row['daily_expenses']);
            }
            $chart_stmt->close();
            
            // Get expense category data for chart
            $category_sql = "SELECT 
                            ec.name as category_name,
                            ec.color as category_color,
                            COALESCE(SUM(e.amount), 0) as total_amount,
                            COUNT(e.id) as transaction_count,
                            COALESCE(AVG(e.amount), 0) as average_amount
                          FROM expense_categories ec
                          LEFT JOIN expenses e ON ec.id = e.category_id AND e.expense_date BETWEEN ? AND ?
                          GROUP BY ec.id, ec.name, ec.color
                          HAVING total_amount > 0
                          ORDER BY total_amount DESC";
            
            $category_stmt = $conn->prepare($category_sql);
            if (!$category_stmt) {
                throw new Exception("Prepare failed: " . $category_stmt->error);
            }
            
            $category_stmt->bind_param("ss", $start_date, $end_date);
            if (!$category_stmt->execute()) {
                throw new Exception("Execute failed: " . $category_stmt->error);
            }
            $category_result = $category_stmt->get_result();
            
            $category_labels = [];
            $category_data = [];
            $category_colors = [];
            $category_details = [];
            
            while($row = $category_result->fetch_assoc()) {
                $category_labels[] = $row['category_name'];
                $category_data[] = floatval($row['total_amount']);
                $category_colors[] = $row['category_color'];
                $category_details[] = [
                    'transactions' => $row['transaction_count'],
                    'average' => floatval($row['average_amount'])
                ];
            }
            $category_stmt->close();
            
            // Generate chart labels with all dates in range
            $chart_labels = [];
            $chart_expenses = [];
            
            $current_date = $start_date;
            while (strtotime($current_date) <= strtotime($end_date)) {
                $date_key = date('Y-m-d', strtotime($current_date));
                $label = date('M j', strtotime($current_date));
                
                $chart_labels[] = $label;
                $chart_expenses[] = $expense_chart_data[$date_key] ?? 0;
                
                $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
            }
            
            echo json_encode([
                'success' => true,
                'line_chart' => [
                    'labels' => $chart_labels,
                    'data' => $chart_expenses
                ],
                'pie_chart' => [
                    'labels' => $category_labels,
                    'data' => $category_data,
                    'colors' => $category_colors,
                    'details' => $category_details
                ]
            ]);
            
        } elseif ($tab == 'profit') {
            // Improved profit SQL query with complete date range
            $profit_sql = "SELECT 
                            dates.date as date,
                            COALESCE(SUM(re.amount), 0) + COALESCE(SUM(mp.amount), 0) as revenue,
                            COALESCE(SUM(e.amount), 0) as expenses,
                            (COALESCE(SUM(re.amount), 0) + COALESCE(SUM(mp.amount), 0) - COALESCE(SUM(e.amount), 0)) as profit
                          FROM (
                            SELECT DATE(? + INTERVAL seq.seq DAY) as date
                            FROM (
                                SELECT a.N + b.N * 10 + c.N * 100 as seq
                                FROM (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a
                                CROSS JOIN (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b
                                CROSS JOIN (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) c
                            ) seq
                            WHERE DATE(? + INTERVAL seq.seq DAY) BETWEEN ? AND ?
                          ) dates
                          LEFT JOIN revenue_entries re ON dates.date = DATE(re.revenue_date)
                          LEFT JOIN membership_payments mp ON dates.date = DATE(mp.payment_date) AND mp.status = 'completed'
                          LEFT JOIN expenses e ON dates.date = DATE(e.expense_date)
                          GROUP BY dates.date
                          ORDER BY dates.date ASC";
            
            $profit_stmt = $conn->prepare($profit_sql);
            if (!$profit_stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            
            $profit_stmt->bind_param("ssss", $start_date, $start_date, $start_date, $end_date);
            if (!$profit_stmt->execute()) {
                throw new Exception("Execute failed: " . $profit_stmt->error);
            }
            $profit_result = $profit_stmt->get_result();
            
            $profit_data = [];
            while($row = $profit_result->fetch_assoc()) {
                $profit_data[$row['date']] = [
                    'revenue' => floatval($row['revenue']),
                    'expenses' => floatval($row['expenses']),
                    'profit' => floatval($row['profit'])
                ];
            }
            $profit_stmt->close();
            
            // Generate chart labels and data with all dates in range
            $chart_labels = [];
            $chart_revenue = [];
            $chart_expenses = [];
            $chart_profit = [];
            
            $current_date = $start_date;
            while (strtotime($current_date) <= strtotime($end_date)) {
                $date_key = date('Y-m-d', strtotime($current_date));
                $label = date('M j', strtotime($current_date));
                
                $chart_labels[] = $label;
                $chart_revenue[] = $profit_data[$date_key]['revenue'] ?? 0;
                $chart_expenses[] = $profit_data[$date_key]['expenses'] ?? 0;
                $chart_profit[] = $profit_data[$date_key]['profit'] ?? 0;
                
                $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
            }
            
            echo json_encode([
                'success' => true,
                'line_chart' => [
                    'labels' => $chart_labels,
                    'revenue' => $chart_revenue,
                    'expenses' => $chart_expenses,
                    'profit' => $chart_profit
                ]
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    
    $conn->close();
    exit();
}

// Handle AJAX request for notifications
if (isset($_GET['ajax']) && $_GET['ajax'] == 'notifications') {
    header('Content-Type: application/json');
    ob_clean();
    
    // Database connection for AJAX
    $servername = "localhost";
    $username = "root";
    $password = "";
    $dbname = "boiyetsdb";

    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        exit();
    }

    // Check if user is authenticated
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        $conn->close();
        exit();
    }
    
    // Get notifications for current user
    $notifications_sql = "SELECT * FROM notifications 
                         WHERE (user_id = ? OR role = ?) 
                         ORDER BY created_at DESC 
                         LIMIT 10";
    $notifications_stmt = $conn->prepare($notifications_sql);
    $notifications_stmt->bind_param("is", $_SESSION['user_id'], $_SESSION['role']);
    $notifications_stmt->execute();
    $notifications_result = $notifications_stmt->get_result();
    
    $notifications = [];
    while($row = $notifications_result->fetch_assoc()) {
        $notifications[] = [
            'id' => $row['id'],
            'title' => $row['title'],
            'message' => $row['message'],
            'type' => $row['type'],
            'read_status' => $row['read_status'],
            'created_at' => $row['created_at'],
            'time_ago' => time_ago($row['created_at'])
        ];
    }
    
    // Mark notifications as read when fetched
    $mark_read_sql = "UPDATE notifications SET read_status = 1 
                     WHERE (user_id = ? OR role = ?) AND read_status = 0";
    $mark_read_stmt = $conn->prepare($mark_read_sql);
    $mark_read_stmt->bind_param("is", $_SESSION['user_id'], $_SESSION['role']);
    $mark_read_stmt->execute();
    $mark_read_stmt->close();
    
    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'unread_count' => 0
    ]);
    
    $notifications_stmt->close();
    $conn->close();
    exit();
}

// Helper function for time ago
function time_ago($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 2592000) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $time);
    }
}

// REGULAR PAGE LOAD
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit();
}

// Database connection for regular page load
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "boiyetsdb";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Include chat functions if the file exists
$unread_count = 0;
if (file_exists('chat_functions.php')) {
    require_once 'chat_functions.php';
    $unread_count = getUnreadCount($_SESSION['user_id'], $conn);
}

// Get unread notifications count
$unread_notifications = 0;
$notification_query = "SELECT COUNT(*) as unread_count FROM notifications WHERE (user_id = ? OR role = ?) AND read_status = 0";
$notification_stmt = $conn->prepare($notification_query);
if ($notification_stmt) {
    $notification_stmt->bind_param("is", $_SESSION['user_id'], $_SESSION['role']);
    $notification_stmt->execute();
    $notification_result = $notification_stmt->get_result();
    if ($notification_result) {            
        $unread_notifications = $notification_result->fetch_assoc()['unread_count'] ?? 0;
    }
    $notification_stmt->close();
}

// Handle form submissions with validation
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = "Security token invalid. Please try again.";
        header("Location: revenue.php?tab=" . ($_POST['tab'] ?? 'revenue'));
        exit();
    }

    // Revenue entries
    if (isset($_POST['add_revenue'])) {
        $category_id = (int)$_POST['category_id'];
        $amount = filter_var($_POST['amount'], FILTER_VALIDATE_FLOAT);
        $description = trim($_POST['description']);
        $payment_method = $_POST['payment_method'];
        $reference_id = !empty($_POST['reference_id']) ? (int)$_POST['reference_id'] : NULL;
        $reference_name = !empty($_POST['reference_name']) ? trim($_POST['reference_name']) : NULL;
        $revenue_date = $_POST['revenue_date'];
        $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : NULL;
        $recorded_by = $_SESSION['user_id'];

        // Validate inputs
        if ($amount === false || $amount <= 0) {
            $_SESSION['error'] = "Please enter a valid amount greater than 0.";
        } elseif (empty($description)) {
            $_SESSION['error'] = "Please enter a description.";
        } elseif (empty($category_id)) {
            $_SESSION['error'] = "Please select a category.";
        } elseif (!in_array($category_id, [1, 4])) {
            $_SESSION['error'] = "Please select a valid category.";
        } else {
            $sql = "INSERT INTO revenue_entries (category_id, amount, description, payment_method, reference_id, reference_name, revenue_date, recorded_by, notes) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            
            if ($stmt) {
                $stmt->bind_param("idssisiss", $category_id, $amount, $description, $payment_method, $reference_id, $reference_name, $revenue_date, $recorded_by, $notes);
                
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Revenue entry added successfully!";
                } else {
                    $_SESSION['error'] = "Error adding revenue entry: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $_SESSION['error'] = "Error preparing statement: " . $conn->error;
            }
        }
        
        header("Location: revenue.php?tab=revenue");
        exit();
    }
    
    if (isset($_POST['update_revenue'])) {
        $id = (int)$_POST['entry_id'];
        $category_id = (int)$_POST['category_id'];
        $amount = filter_var($_POST['amount'], FILTER_VALIDATE_FLOAT);
        $description = trim($_POST['description']);
        $payment_method = $_POST['payment_method'];
        $reference_id = !empty($_POST['reference_id']) ? (int)$_POST['reference_id'] : NULL;
        $reference_name = !empty($_POST['reference_name']) ? trim($_POST['reference_name']) : NULL;
        $revenue_date = $_POST['revenue_date'];
        $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : NULL;

        // Validate inputs
        if ($amount === false || $amount <= 0) {
            $_SESSION['error'] = "Please enter a valid amount greater than 0.";
        } elseif (empty($description)) {
            $_SESSION['error'] = "Please enter a description.";
        } elseif (!in_array($category_id, [1, 4])) {
            $_SESSION['error'] = "Please select a valid category.";
        } else {
            $sql = "UPDATE revenue_entries SET category_id=?, amount=?, description=?, payment_method=?, reference_id=?, reference_name=?, revenue_date=?, notes=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("idssisisi", $category_id, $amount, $description, $payment_method, $reference_id, $reference_name, $revenue_date, $notes, $id);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Revenue entry updated successfully!";
            } else {
                $_SESSION['error'] = "Error updating revenue entry: " . $conn->error;
            }
            $stmt->close();
        }
        header("Location: revenue.php?tab=revenue");
        exit();
    }
    
    if (isset($_POST['delete_revenue'])) {
        $id = (int)$_POST['entry_id'];
        
        // Verify the entry exists and belongs to current user
        $check_sql = "SELECT id FROM revenue_entries WHERE id = ? AND recorded_by = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $id, $_SESSION['user_id']);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $sql = "DELETE FROM revenue_entries WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Revenue entry deleted successfully!";
            } else {
                $_SESSION['error'] = "Error deleting revenue entry: " . $conn->error;
            }
            $stmt->close();
        } else {
            $_SESSION['error'] = "Revenue entry not found or you don't have permission to delete it.";
        }
        $check_stmt->close();
        header("Location: revenue.php?tab=revenue");
        exit();
    }
    
    // Expense entries
    if (isset($_POST['add_expense'])) {
        $category_id = (int)$_POST['expense_category_id'];
        $amount = filter_var($_POST['expense_amount'], FILTER_VALIDATE_FLOAT);
        $description = trim($_POST['expense_description']);
        $payment_method = $_POST['expense_payment_method'];
        $expense_date = $_POST['expense_date'];
        $notes = !empty($_POST['expense_notes']) ? trim($_POST['expense_notes']) : NULL;
        $recorded_by = $_SESSION['user_id'];

        // Validate inputs
        if ($amount === false || $amount <= 0) {
            $_SESSION['error'] = "Please enter a valid amount greater than 0.";
        } elseif (empty($description)) {
            $_SESSION['error'] = "Please enter a description.";
        } elseif (empty($category_id)) {
            $_SESSION['error'] = "Please select a category.";
        } else {
            $sql = "INSERT INTO expenses (category_id, amount, description, payment_method, expense_date, recorded_by, notes) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("idsssis", $category_id, $amount, $description, $payment_method, $expense_date, $recorded_by, $notes);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Expense entry added successfully!";
            } else {
                $_SESSION['error'] = "Error adding expense entry: " . $stmt->error;
            }
            $stmt->close();
        }
        header("Location: revenue.php?tab=expenses");
        exit();
    }
    
    if (isset($_POST['update_expense'])) {
        $id = (int)$_POST['expense_id'];
        $category_id = (int)$_POST['expense_category_id'];
        $amount = filter_var($_POST['expense_amount'], FILTER_VALIDATE_FLOAT);
        $description = trim($_POST['expense_description']);
        $payment_method = $_POST['expense_payment_method'];
        $expense_date = $_POST['expense_date'];
        $notes = !empty($_POST['expense_notes']) ? trim($_POST['expense_notes']) : NULL;

        // Validate inputs
        if ($amount === false || $amount <= 0) {
            $_SESSION['error'] = "Please enter a valid amount greater than 0.";
        } elseif (empty($description)) {
            $_SESSION['error'] = "Please enter a description.";
        } else {
            $sql = "UPDATE expenses SET category_id=?, amount=?, description=?, payment_method=?, expense_date=?, notes=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("idssssi", $category_id, $amount, $description, $payment_method, $expense_date, $notes, $id);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Expense entry updated successfully!";
            } else {
                $_SESSION['error'] = "Error updating expense entry: " . $conn->error;
            }
            $stmt->close();
        }
        header("Location: revenue.php?tab=expenses");
        exit();
    }
    
    if (isset($_POST['delete_expense'])) {
        $id = (int)$_POST['expense_id'];
        
        // Verify the entry exists and belongs to current user
        $check_sql = "SELECT id FROM expenses WHERE id = ? AND recorded_by = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $id, $_SESSION['user_id']);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $sql = "DELETE FROM expenses WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Expense entry deleted successfully!";
            } else {
                $_SESSION['error'] = "Error deleting expense entry: " . $conn->error;
            }
            $stmt->close();
        } else {
            $_SESSION['error'] = "Expense entry not found or you don't have permission to delete it.";
        }
        $check_stmt->close();
        header("Location: revenue.php?tab=expenses");
        exit();
    }
}

// Handle exports - redirect to export script
if (isset($_GET['export'])) {
    $export_params = http_build_query($_GET);
    header("Location: revenue_export.php?$export_params");
    exit();
}

// Get filter parameters with proper defaults
$time_filter = $_GET['time_filter'] ?? 'month';
$category_filter = $_GET['category'] ?? '';
$payment_filter = $_GET['payment_method'] ?? '';
$search = $_GET['search'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$tab = $_GET['tab'] ?? 'revenue';

// Calculate date ranges
$base_date = date('Y-m-d');
switch ($time_filter) {
    case 'week':
        $default_start_date = date('Y-m-d', strtotime('-1 week'));
        break;
    case 'year':
        $default_start_date = date('Y-m-d', strtotime('-1 year'));
        break;
    default:
        $default_start_date = date('Y-m-d', strtotime('-1 month'));
        break;
}

$start_date = $start_date ?: $default_start_date;
$end_date = $end_date ?: $base_date;

// Cache categories to avoid multiple queries
$revenue_categories = [];
$revenue_categories_result = $conn->query("SELECT * FROM revenue_categories WHERE id IN (1, 4) AND is_active = TRUE ORDER BY name");
while($cat = $revenue_categories_result->fetch_assoc()) {
    $revenue_categories[] = $cat;
}

$expense_categories = [];
$expense_categories_result = $conn->query("SELECT * FROM expense_categories WHERE is_active = TRUE ORDER BY name");
while($cat = $expense_categories_result->fetch_assoc()) {
    $expense_categories[] = $cat;
}

// Build filter queries for revenue and expenses
$revenue_where_conditions = ["re.revenue_date BETWEEN ? AND ?"];
$expense_where_conditions = ["e.expense_date BETWEEN ? AND ?"];
$revenue_params = [$start_date, $end_date];
$expense_params = [$start_date, $end_date];
$revenue_types = "ss";
$expense_types = "ss";

if ($category_filter) {
    if ($tab == 'revenue') {
        $revenue_where_conditions[] = "re.category_id = ?";
        $revenue_params[] = $category_filter;
        $revenue_types .= "i";
    } elseif ($tab == 'expenses') {
        $expense_where_conditions[] = "e.category_id = ?";
        $expense_params[] = $category_filter;
        $expense_types .= "i";
    }
}

if ($payment_filter) {
    if ($tab == 'revenue') {
        $revenue_where_conditions[] = "re.payment_method = ?";
        $revenue_params[] = $payment_filter;
        $revenue_types .= "s";
    } elseif ($tab == 'expenses') {
        $expense_where_conditions[] = "e.payment_method = ?";
        $expense_params[] = $payment_filter;
        $expense_types .= "s";
    }
}

if ($search) {
    if ($tab == 'revenue') {
        $revenue_where_conditions[] = "(re.description LIKE ? OR re.reference_name LIKE ? OR rc.name LIKE ?)";
        $revenue_params[] = "%$search%";
        $revenue_params[] = "%$search%";
        $revenue_params[] = "%$search%";
        $revenue_types .= "sss";
    } elseif ($tab == 'expenses') {
        $expense_where_conditions[] = "(e.description LIKE ? OR ec.name LIKE ?)";
        $expense_params[] = "%$search%";
        $expense_params[] = "%$search%";
        $expense_types .= "ss";
    }
}

$revenue_where_sql = implode(" AND ", $revenue_where_conditions);
$expense_where_sql = implode(" AND ", $expense_where_conditions);

// Get revenue entries
$revenue_sql = "SELECT re.*, rc.name as category_name, rc.color as category_color, u.username as recorded_by_name
        FROM revenue_entries re
        JOIN revenue_categories rc ON re.category_id = rc.id
        JOIN users u ON re.recorded_by = u.id
        WHERE $revenue_where_sql
        ORDER BY re.revenue_date DESC, re.created_at DESC";

$revenue_stmt = $conn->prepare($revenue_sql);
if ($revenue_stmt && !empty($revenue_params)) {
    $revenue_stmt->bind_param($revenue_types, ...$revenue_params);
    $revenue_stmt->execute();
    $revenue_result = $revenue_stmt->get_result();
} else {
    $revenue_result = false;
}

// Get expense entries
$expense_sql = "SELECT e.*, ec.name as category_name, ec.color as category_color, u.username as recorded_by_name
        FROM expenses e
        JOIN expense_categories ec ON e.category_id = ec.id
        JOIN users u ON e.recorded_by = u.id
        WHERE $expense_where_sql
        ORDER BY e.expense_date DESC, e.created_at DESC";

$expense_stmt = $conn->prepare($expense_sql);
if ($expense_stmt && !empty($expense_params)) {
    $expense_stmt->bind_param($expense_types, ...$expense_params);
    $expense_stmt->execute();
    $expense_result = $expense_stmt->get_result();
} else {
    $expense_result = false;
}

// Calculate financial metrics - IMPROVED VERSION
$total_revenue = 0;
$total_transactions = 0;

// Get ALL revenue including membership payments within date range - IMPROVED QUERY
$revenue_stats_sql = "SELECT 
                'revenue_entries' as source,
                COUNT(re.id) as transaction_count,
                COALESCE(SUM(re.amount), 0) as total_amount
              FROM revenue_entries re
              WHERE re.revenue_date BETWEEN ? AND ?
              
              UNION ALL
              
              SELECT 
                'membership_payments' as source,
                COUNT(mp.id) as transaction_count,
                COALESCE(SUM(mp.amount), 0) as total_amount
              FROM membership_payments mp
              WHERE mp.payment_date BETWEEN ? AND ?
              AND mp.status = 'completed'";

$revenue_stats_stmt = $conn->prepare($revenue_stats_sql);
if ($revenue_stats_stmt) {
    $revenue_stats_stmt->bind_param("ssss", $start_date, $end_date, $start_date, $end_date);
    $revenue_stats_stmt->execute();
    $revenue_stats_result = $revenue_stats_stmt->get_result();
    
    if ($revenue_stats_result) {
        while($row = $revenue_stats_result->fetch_assoc()) {
            $total_revenue += $row['total_amount'] ?? 0;
            $total_transactions += $row['transaction_count'] ?? 0;
        }
    }
    $revenue_stats_stmt->close();
}

// Get detailed membership revenue breakdown by plan type
$membership_breakdown_sql = "SELECT 
                mp.plan_type,
                COUNT(mp.id) as transaction_count,
                COALESCE(SUM(mp.amount), 0) as total_amount,
                COALESCE(AVG(mp.amount), 0) as average_amount
              FROM membership_payments mp
              WHERE mp.payment_date BETWEEN ? AND ?
              AND mp.status = 'completed'
              GROUP BY mp.plan_type
              ORDER BY total_amount DESC";

$membership_breakdown_stmt = $conn->prepare($membership_breakdown_sql);
$membership_breakdown = [];
if ($membership_breakdown_stmt) {
    $membership_breakdown_stmt->bind_param("ss", $start_date, $end_date);
    $membership_breakdown_stmt->execute();
    $membership_breakdown_result = $membership_breakdown_stmt->get_result();
    while($row = $membership_breakdown_result->fetch_assoc()) {
        $membership_breakdown[] = $row;
    }
    $membership_breakdown_stmt->close();
}

$total_expenses = 0;
$total_expense_transactions = 0;

$expense_stats_sql = "SELECT 
                COUNT(e.id) as transaction_count,
                COALESCE(SUM(e.amount), 0) as total_amount
              FROM expenses e
              WHERE e.expense_date BETWEEN ? AND ?";

$expense_stats_stmt = $conn->prepare($expense_stats_sql);
if ($expense_stats_stmt) {
    $expense_stats_stmt->bind_param("ss", $start_date, $end_date);
    $expense_stats_stmt->execute();
    $expense_stats_result = $expense_stats_stmt->get_result();
    if ($expense_stats_result) {
        $expense_stats = $expense_stats_result->fetch_assoc();
        $total_expenses = $expense_stats['total_amount'] ?? 0;
        $total_expense_transactions = $expense_stats['transaction_count'] ?? 0;
    }
    $expense_stats_stmt->close();
}

$net_profit = $total_revenue - $total_expenses;
$expense_ratio = $total_revenue > 0 ? ($total_expenses / $total_revenue) * 100 : 0;

// Get revenue statistics by category - IMPROVED VERSION
$revenue_stats_sql = "SELECT 
                'Membership Fees' as category_name,
                '#3b82f6' as category_color,
                COUNT(mp.id) as transaction_count,
                COALESCE(SUM(mp.amount), 0) as total_amount,
                COALESCE(AVG(mp.amount), 0) as average_amount
              FROM membership_payments mp
              WHERE mp.payment_date BETWEEN ? AND ?
              AND mp.status = 'completed'
              
              UNION ALL
              
              SELECT 
                rc.name as category_name,
                rc.color as category_color,
                COUNT(re.id) as transaction_count,
                COALESCE(SUM(re.amount), 0) as total_amount,
                COALESCE(AVG(re.amount), 0) as average_amount
              FROM revenue_categories rc
              LEFT JOIN revenue_entries re ON rc.id = re.category_id AND re.revenue_date BETWEEN ? AND ?
              WHERE rc.id IN (1, 4)
              GROUP BY rc.id, rc.name, rc.color
              ORDER BY total_amount DESC";

$revenue_stats_stmt = $conn->prepare($revenue_stats_sql);
if ($revenue_stats_stmt) {
    $revenue_stats_stmt->bind_param("ssss", $start_date, $end_date, $start_date, $end_date);
    $revenue_stats_stmt->execute();
    $revenue_stats_result = $revenue_stats_stmt->get_result();
} else {
    $revenue_stats_result = false;
}

// Get expense statistics by category
$expense_stats_sql = "SELECT 
                ec.name as category_name,
                ec.color as category_color,
                COUNT(e.id) as transaction_count,
                COALESCE(SUM(e.amount), 0) as total_amount,
                COALESCE(AVG(e.amount), 0) as average_amount
              FROM expense_categories ec
              LEFT JOIN expenses e ON ec.id = e.category_id AND e.expense_date BETWEEN ? AND ?
              GROUP BY ec.id, ec.name, ec.color
              ORDER BY total_amount DESC";

$expense_stats_stmt = $conn->prepare($expense_stats_sql);
if ($expense_stats_stmt) {
    $expense_stats_stmt->bind_param("ss", $start_date, $end_date);
    $expense_stats_stmt->execute();
    $expense_stats_result = $expense_stats_stmt->get_result();
} else {
    $expense_stats_result = false;
}

$username = $_SESSION['username'] ?? 'Admin';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BOIYETS FITNESS GYM - Revenue Tracking</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <style>
    /* All your existing CSS styles remain the same */
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    
    * {
      font-family: 'Inter', sans-serif;
    }
    
    body {
      background: linear-gradient(135deg, #111 0%, #0a0a0a 100%);
      color: #e2e8f0;
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
    
    .sidebar-collapsed .sidebar-item .text-sm { 
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
    
    .topbar {
      background: rgba(13, 13, 13, 0.95);
      backdrop-filter: blur(10px);
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
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
      border: none;
      cursor: pointer;
    }
    
    .button-sm:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
    }
    
    .btn-primary {
      background: rgba(251, 191, 36, 0.2);
      color: #fbbf24;
    }
    
    .btn-primary:hover {
      background: rgba(251, 191, 36, 0.3);
    }
    
    .btn-active {
      background: #fbbf24;
      color: white;
    }
    
    .btn-outline {
      background: transparent;
      border: 1px solid #fbbf24;
      color: #fbbf24;
    }
    
    .btn-outline:hover {
      background: #fbbf24;
      color: white;
    }

    .btn-danger {
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
      border: 1px solid #ef4444;
    }
    
    .btn-danger:hover {
      background: #ef4444;
      color: white;
    }

    .chart-container {
      width: 100%;
      height: 300px;
      position: relative;
    }
    
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 0.75rem;
    }
    
    .stat-card {
      background: rgba(251, 191, 36, 0.1);
      border-left: 4px solid #fbbf24;
    }
    
    .expense-card {
      background: rgba(239, 68, 68, 0.1);
      border-left: 4px solid #ef4444;
    }
    
    .profit-card {
      background: rgba(34, 197, 94, 0.1);
      border-left: 4px solid #22c55e;
    }
    
    .membership-card {
      background: rgba(59, 130, 246, 0.1);
      border-left: 4px solid #3b82f6;
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
    
    .badge {
      padding: 0.25rem 0.5rem;
      border-radius: 6px;
      font-size: 0.75rem;
      font-weight: 500;
    }
    
    .badge-active {
      background: rgba(34, 197, 94, 0.2);
      color: #22c55e;
    }
    
    .badge-expired {
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
    }
    
    .badge-client {
      background: rgba(59, 130, 246, 0.2);
      color: #3b82f6;
    }
    
    .badge-walkin {
      background: rgba(168, 85, 247, 0.2);
      color: #a855f7;
    }

    /* Modal styles */
    .modal {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.8);
      z-index: 1000;
      backdrop-filter: blur(5px);
    }
    
    .modal-content {
      background: #1a1a1a;
      margin: 2rem auto;
      padding: 2rem;
      border-radius: 12px;
      max-width: 500px;
      width: 90%;
      border: 1px solid rgba(255, 255, 255, 0.1);
      max-height: 90vh;
      overflow-y: auto;
      color: #e2e8f0;
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
      font-size: 0.875rem;
      font-weight: 500;
      color: #9ca3af;
      margin-bottom: 0.5rem;
    }

    /* Tab styles */
    .tab-container {
      display: flex;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      margin-bottom: 1rem;
    }
    
    .tab {
      padding: 0.75rem 1.5rem;
      cursor: pointer;
      border-bottom: 2px solid transparent;
      transition: all 0.2s ease;
      color: #9ca3af;
    }
    
    .tab.active {
      color: #fbbf24;
      border-bottom-color: #fbbf24;
      background: rgba(251, 191, 36, 0.05);
    }
    
    .tab:hover {
      color: #fbbf24;
      background: rgba(251, 191, 36, 0.05);
    }
    
    .tab-content {
      display: none;
    }
    
    .tab-content.active {
      display: block;
    }

    /* Dropdown styles */
.dropdown-container {
  position: relative;
  display: inline-block;
}

.dropdown-menu {
  position: absolute;
  top: 100%;
  right: 0;
  margin-top: 0.5rem;
  background: #1a1a1a;
  border: 1px solid rgba(251, 191, 36, 0.3);
  border-radius: 8px;
  padding: 0.5rem;
  min-width: 320px;
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.7);
  z-index: 1000;
  display: none;
  backdrop-filter: blur(10px);
  color: #e2e8f0;
}

.dropdown-menu.show {
  display: block;
}

.dropdown-item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.75rem;
  color: #e2e8f0;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.2s ease;
  text-decoration: none;
  background: transparent;
  border: none;
  width: 100%;
  text-align: left;
  font-size: 0.875rem;
}

.dropdown-item:hover {
  background: rgba(251, 191, 36, 0.2);
  color: #fbbf24;
}

.dropdown-item i {
  width: 16px;
  height: 16px;
  stroke-width: 1.75;
}

.dropdown-divider {
  height: 1px;
  background: rgba(251, 191, 36, 0.2);
  margin: 0.5rem 0;
}

.dropdown-header {
  padding: 0.75rem;
  border-bottom: 1px solid rgba(251, 191, 36, 0.2);
  background: rgba(251, 191, 36, 0.1);
}

.dropdown-header h3 {
  font-weight: 600;
  color: #fbbf24;
  margin: 0;
  font-size: 0.9rem;
}

.dropdown-header p {
  color: #d1d5db;
  margin: 0.25rem 0 0 0;
  font-size: 0.8rem;
}

/* Notification styles */
.notification-item {
  padding: 0.75rem;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  cursor: pointer;
  transition: all 0.2s ease;
}

.notification-item:last-child {
  border-bottom: none;
}

.notification-item:hover {
  background: rgba(251, 191, 36, 0.1);
}

.notification-item.unread {
  background: rgba(59, 130, 246, 0.1);
  border-left: 3px solid #3b82f6;
}

.notification-title {
  font-weight: 600;
  color: #fbbf24;
  margin-bottom: 0.25rem;
  font-size: 0.875rem;
}

.notification-message {
  color: #d1d5db;
  font-size: 0.8rem;
  margin-bottom: 0.25rem;
}

.notification-time {
  color: #6b7280;
  font-size: 0.75rem;
}

.notification-badge {
  position: absolute;
  top: -2px;
  right: -2px;
  background: #ef4444;
  color: white;
  border-radius: 50%;
  width: 18px;
  height: 18px;
  font-size: 0.7rem;
  display: flex;
  align-items: center;
  justify-content: center;
}

/* Export dropdown styles */
.export-container {
  position: relative;
  display: inline-block;
}

.export-dropdown {
  position: fixed;
  background: #1a1a1a;
  border: 1px solid rgba(251, 191, 36, 0.3);
  border-radius: 8px;
  padding: 0.5rem;
  min-width: 220px;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.7), 0 10px 10px -5px rgba(0, 0, 0, 0.3);
  z-index: 10000;
  display: none;
  max-height: 80vh;
  overflow-y: auto;
  backdrop-filter: blur(10px);
  color: #e2e8f0;
}

.export-dropdown.show {
  display: block;
}

.export-option {
  display: block;
  padding: 0.75rem;
  color: #e2e8f0;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.2s ease;
  text-decoration: none;
  background: transparent;
  border: none;
  width: 100%;
  text-align: left;
  font-size: 0.875rem;
}

.export-option:hover {
  background: rgba(251, 191, 36, 0.2);
  color: #fbbf24;
}

/* Select dropdown styles */
select.form-input {
  background: #1a1a1a;
  color: #e2e8f0;
  border: 1px solid rgba(255, 255, 255, 0.1);
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%23fbbf24'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 0.75rem center;
  background-size: 16px;
  padding-right: 2.5rem;
}

select.form-input option {
  background: #1a1a1a;
  color: #e2e8f0;
  padding: 0.5rem;
}

select.form-input:focus {
  border-color: #fbbf24;
  box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.2);
}

/* Textarea styles */
textarea.form-input {
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 8px;
  padding: 0.75rem;
  color: white;
  width: 100%;
  resize: vertical;
  min-height: 80px;
}

textarea.form-input:focus {
  outline: none;
  border-color: #fbbf24;
  box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.2);
}

    /* Profit/Loss specific styles */
    .profit-positive {
      color: #22c55e;
    }
    
    .profit-negative {
      color: #ef4444;
    }
    
    .profit-neutral {
      color: #6b7280;
    }

    /* Table styles */
    table {
      width: 100%;
      border-collapse: collapse;
    }
    
    th, td {
      padding: 0.75rem 1rem;
      text-align: left;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    th {
      background: rgba(255, 255, 255, 0.05);
      font-weight: 600;
      color: #9ca3af;
    }
    
    tr:hover {
      background: rgba(255, 255, 255, 0.02);
    }

    /* Chart fallback styles */
    .chart-fallback {
      display: flex;
      align-items: center;
      justify-content: center;
      height: 100%;
      color: #6b7280;
      text-align: center;
    }
    
    .chart-fallback i {
      margin-bottom: 1rem;
      opacity: 0.5;
    }

    /* Loading spinner */
    .loading-spinner {
      border: 2px solid #f3f3f3;
      border-top: 2px solid #fbbf24;
      border-radius: 50%;
      width: 20px;
      height: 20px;
      animation: spin 1s linear infinite;
      display: inline-block;
      margin-right: 8px;
    }
    
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
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
          <?php if ($unread_notifications > 0): ?>
            <span class="notification-badge" id="notificationBadge">
              <?php echo $unread_notifications; ?>
            </span>
          <?php endif; ?>
        </button>
        <div id="notificationDropdown" class="dropdown-menu">
          <div class="dropdown-header">
            <h3>Notifications</h3>
            <p>You have <span id="notificationCount"><?php echo $unread_notifications; ?></span> unread notifications</p>
          </div>
          <div class="max-h-96 overflow-y-auto" id="notificationList">
            <div class="p-3 text-center text-gray-500 text-sm">
              <div class="loading-spinner"></div> Loading notifications...
            </div>
          </div>
          <div class="dropdown-divider"></div>
          <a href="notifications.php" class="dropdown-item">
            <i data-lucide="bell"></i>
            <span>View All Notifications</span>
          </a>
        </div>
      </div>

      <div class="h-8 w-px bg-gray-700 mx-1"></div>
      
      <!-- User Profile Dropdown -->
      <div class="dropdown-container">
        <button id="userMenuButton" class="flex items-center space-x-2 text-gray-300 hover:text-yellow-400 transition-colors p-2 rounded-lg hover:bg-white/5">
          <img src="<?php echo htmlspecialchars($_SESSION['profile_picture'] ?? 'https://i.pravatar.cc/40'); ?>" class="w-8 h-8 rounded-full border border-gray-600" id="userAvatar" />
          <span class="text-sm font-medium hidden md:inline" id="userName">
            <?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?>
          </span>
          <i data-lucide="chevron-down" class="w-4 h-4"></i>
        </button>
        <div id="userDropdown" class="dropdown-menu">
          <div class="dropdown-header">
            <h3><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?></h3>
            <p><?php echo htmlspecialchars($_SESSION['role'] ?? 'Admin'); ?></p>
          </div>
          <a href="profile.php" class="dropdown-item">
            <i data-lucide="user"></i>
            <span>Profile</span>
          </a>
          <a href="settings.php" class="dropdown-item">
            <i data-lucide="settings"></i>
            <span>Settings</span>
          </a>
          <div class="dropdown-divider"></div>
          <a href="logout.php" class="dropdown-item">
            <i data-lucide="log-out"></i>
            <span>Logout</span>
          </a>
        </div>
      </div>
    </div>
  </header>

  <div class="flex">
    <!-- Sidebar -->
    <aside id="sidebar" class="sidebar w-60 bg-[#0d0d0d] h-screen p-3 space-y-2 flex flex-col overflow-y-auto border-r border-gray-800">
      <nav class="space-y-1 flex-1">
        <a href="admin_dashboard.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="home"></i>
            <span class="text-sm font-medium">Dashboard</span>
          </div>
          <span class="tooltip">Dashboard</span>
        </a>

        <a href="view_users.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="users"></i>
            <span class="text-sm font-medium">View All Users</span>
          </div>
          <span class="tooltip">View All Users</span>
        </a>

        <a href="revenue.php" class="sidebar-item active">
          <div class="flex items-center">
            <i data-lucide="dollar-sign"></i>
            <span class="text-sm font-medium">Revenue Tracking</span>
          </div>
          <span class="tooltip">Revenue Tracking</span>
        </a>

        <a href="products.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="package"></i>
            <span class="text-sm font-medium">Products & Inventory</span>
          </div>
          <span class="tooltip">Products & Inventory</span>
        </a>

        <a href="adminannouncement.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="megaphone"></i>
            <span class="text-sm font-medium">Announcements</span>
          </div>
          <span class="tooltip">Announcements</span>
        </a>
<a href="equipment_monitoring.php" class="sidebar-item">
  <div class="flex items-center">
    <i data-lucide="wrench"></i>
    <span class="text-sm font-medium">Equipment Monitoring</span>
  </div>
  <span class="tooltip">Equipment Monitoring</span>
</a>



<a href="maintenance_report.php" class="sidebar-item">
  <div class="flex items-center">
    <i data-lucide="alert-triangle"></i>
    <span class="text-sm font-medium">Maintenance Report</span>
  </div>
  <span class="tooltip">Maintenance Report</span>
</a>

        <a href="feedbacksadmin.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="message-square"></i>
            <span class="text-sm font-medium">Feedback & Reports</span>
          </div>
          <span class="tooltip">Feedback & Reports</span>
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
    <main class="flex-1 p-4 space-y-4 overflow-auto">
      <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-yellow-400 flex items-center gap-2">
          <i data-lucide="dollar-sign"></i>
          Revenue Tracking
        </h1>
        <div class="text-sm text-gray-400">
          Welcome back, <?php echo htmlspecialchars($username); ?>
        </div>
      </div>

      <!-- Flash Messages -->
      <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-500/20 border border-green-500 text-green-400 px-4 py-3 rounded-lg mb-4">
          <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
      <?php endif; ?>
      
      <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-500/20 border border-red-500 text-red-400 px-4 py-3 rounded-lg mb-4">
          <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
      <?php endif; ?>

      <!-- Tabs -->
      <div class="card">
        <div class="tab-container">
          <div class="tab <?php echo $tab == 'revenue' ? 'active' : ''; ?>" onclick="switchTab('revenue')">
            <i data-lucide="trending-up"></i> Revenue
          </div>
          <div class="tab <?php echo $tab == 'expenses' ? 'active' : ''; ?>" onclick="switchTab('expenses')">
            <i data-lucide="trending-down"></i> Expenses
          </div>
          <div class="tab <?php echo $tab == 'profit' ? 'active' : ''; ?>" onclick="switchTab('profit')">
            <i data-lucide="bar-chart-3"></i> Profit & Loss
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-wrap gap-2 justify-between mb-4">
          <div class="flex gap-2">
            <!-- Dynamic Action Button -->
            <div id="actionButtonContainer">
              <?php if ($tab == 'revenue'): ?>
                <button onclick="openAddRevenueModal()" class="button-sm btn-primary">
                  <i data-lucide="plus"></i> Add Revenue
                </button>
              <?php elseif ($tab == 'expenses'): ?>
                <button onclick="openAddExpenseModal()" class="button-sm btn-danger">
                  <i data-lucide="plus"></i> Add Expense
                </button>
              <?php endif; ?>
            </div>
            
            <!-- Export Dropdown -->
            <div class="export-container">
              <button id="exportButton" class="button-sm btn-outline">
                <i data-lucide="download"></i> Export
                <i data-lucide="chevron-down" class="w-4 h-4"></i>
              </button>
              <div id="exportDropdown" class="export-dropdown">
                <div class="dropdown-header">
                  <h3>Export Options</h3>
                  <p>Choose format and report type</p>
                </div>
                <div class="p-2">
                  <div class="text-xs text-gray-400 mb-2">Format:</div>
                  <div class="grid grid-cols-2 gap-1 mb-3">
                    <button class="export-option" data-format="excel">
                      <i data-lucide="file-spreadsheet"></i> Excel
                    </button>
                    <button class="export-option" data-format="csv">
                      <i data-lucide="file-text"></i> CSV
                    </button>
                  </div>
                  <div class="text-xs text-gray-400 mb-2">Report Type:</div>
                  <div class="space-y-1">
                    <button class="export-option" data-report="detailed">
                      <i data-lucide="list"></i> Detailed Report
                    </button>
                    <button class="export-option" data-report="summary">
                      <i data-lucide="pie-chart"></i> Summary Report
                    </button>
                    <?php if ($tab == 'profit'): ?>
                    <button class="export-option" data-report="financial_statement">
                      <i data-lucide="bar-chart-3"></i> Financial Statement
                    </button>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="flex gap-2">
            <a href="?time_filter=week&tab=<?php echo $tab; ?>" class="button-sm <?php echo $time_filter == 'week' ? 'btn-active' : 'btn-outline'; ?>">
              Week
            </a>
            <a href="?time_filter=month&tab=<?php echo $tab; ?>" class="button-sm <?php echo $time_filter == 'month' ? 'btn-active' : 'btn-outline'; ?>">
              Month
            </a>
            <a href="?time_filter=year&tab=<?php echo $tab; ?>" class="button-sm <?php echo $time_filter == 'year' ? 'btn-active' : 'btn-outline'; ?>">
              Year
            </a>
          </div>
        </div>

        <!-- Filters -->
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4" id="filterForm">
          <input type="hidden" name="tab" value="<?php echo $tab; ?>">
          <div>
            <label class="form-label">Start Date</label>
            <input type="date" name="start_date" value="<?php echo $start_date; ?>" class="form-input" id="startDate">
          </div>
          <div>
            <label class="form-label">End Date</label>
            <input type="date" name="end_date" value="<?php echo $end_date; ?>" class="form-input" id="endDate">
          </div>
          <?php if ($tab == 'revenue' || $tab == 'expenses'): ?>
            <div>
              <label class="form-label">Category</label>
              <select name="category" class="form-input">
                <option value="">All Categories</option>
                <?php if ($tab == 'revenue'): ?>
                  <?php foreach($revenue_categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo $category_filter == $cat['id'] ? 'selected' : ''; ?>>
                          <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                  <?php endforeach; ?>
                <?php else: ?>
                  <?php foreach($expense_categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo $category_filter == $cat['id'] ? 'selected' : ''; ?>>
                          <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            </div>
          <?php endif; ?>
          <?php if ($tab == 'revenue' || $tab == 'expenses'): ?>
            <div>
              <label class="form-label">Payment Method</label>
              <select name="payment_method" class="form-input">
                <option value="">All Methods</option>
                <option value="cash" <?php echo $payment_filter == 'cash' ? 'selected' : ''; ?>>Cash</option>
                <option value="gcash" <?php echo $payment_filter == 'gcash' ? 'selected' : ''; ?>>GCash</option>
                <option value="bank_transfer" <?php echo $payment_filter == 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                <option value="card" <?php echo $payment_filter == 'card' ? 'selected' : ''; ?>>Card</option>
                <option value="online" <?php echo $payment_filter == 'online' ? 'selected' : ''; ?>>Online</option>
              </select>
            </div>
          <?php endif; ?>
          <div class="md:col-span-3">
            <label class="form-label">Search</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search descriptions..." class="form-input">
          </div>
          <div class="flex items-end">
            <button type="submit" class="button-sm btn-primary w-full">
              <i data-lucide="filter"></i> Apply Filters
            </button>
          </div>
        </form>
      </div>

      <!-- Revenue Tab -->
      <div id="revenue-tab" class="tab-content <?php echo $tab == 'revenue' ? 'active' : ''; ?>">
        <!-- Revenue Statistics -->
        <div class="stats-grid mb-8">
          <div class="card stat-card">
            <div class="flex items-center justify-between">
              <div>
                <p class="card-title"><i data-lucide="dollar-sign"></i><span>Total Revenue</span></p>
                <p class="card-value">₱<?php echo number_format($total_revenue, 2); ?></p>
                <p class="text-xs text-gray-400">All categories</p>
              </div>
              <div class="p-3 bg-yellow-500/10 rounded-lg">
                <i data-lucide="dollar-sign" class="w-6 h-6 text-yellow-400"></i>
              </div>
            </div>
          </div>

          <div class="card stat-card">
            <div class="flex items-center justify-between">
              <div>
                <p class="card-title"><i data-lucide="credit-card"></i><span>Total Transactions</span></p>
                <p class="card-value"><?php echo $total_transactions; ?></p>
                <p class="text-xs text-gray-400">All payments</p>
              </div>
              <div class="p-3 bg-yellow-500/10 rounded-lg">
                <i data-lucide="credit-card" class="w-6 h-6 text-yellow-400"></i>
              </div>
            </div>
          </div>

          <div class="card membership-card">
            <div class="flex items-center justify-between">
              <div>
                <p class="card-title"><i data-lucide="calendar"></i><span>Date Range</span></p>
                <p class="card-value text-sm"><?php echo date('M j', strtotime($start_date)); ?> - <?php echo date('M j', strtotime($end_date)); ?></p>
                <p class="text-xs text-gray-400">Reporting period</p>
              </div>
              <div class="p-3 bg-blue-500/10 rounded-lg">
                <i data-lucide="calendar" class="w-6 h-6 text-blue-400"></i>
              </div>
            </div>
          </div>

          <div class="card membership-card">
            <div class="flex items-center justify-between">
              <div>
                <p class="card-title"><i data-lucide="trending-up"></i><span>Avg. Transaction</span></p>
                <p class="card-value">₱<?php echo $total_transactions > 0 ? number_format($total_revenue / $total_transactions, 2) : '0.00'; ?></p>
                <p class="text-xs text-gray-400">Per transaction</p>
              </div>
              <div class="p-3 bg-blue-500/10 rounded-lg">
                <i data-lucide="trending-up" class="w-6 h-6 text-blue-400"></i>
              </div>
            </div>
          </div>
        </div>

        <!-- Membership Breakdown -->
        <?php if (!empty($membership_breakdown)): ?>
        <div class="card mb-8">
          <h3 class="card-title"><i data-lucide="users"></i><span>Membership Revenue Breakdown</span></h3>
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <?php foreach($membership_breakdown as $plan): ?>
              <div class="bg-blue-500/10 border border-blue-500/20 rounded-lg p-4">
                <div class="flex items-center justify-between mb-2">
                  <span class="text-blue-400 font-semibold capitalize"><?php echo $plan['plan_type']; ?></span>
                  <i data-lucide="user" class="w-4 h-4 text-blue-400"></i>
                </div>
                <p class="text-2xl font-bold text-white">₱<?php echo number_format($plan['total_amount'], 2); ?></p>
                <p class="text-xs text-gray-400">
                  <?php echo $plan['transaction_count']; ?> payments • 
                  Avg: ₱<?php echo number_format($plan['average_amount'], 2); ?>
                </p>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
          <div class="card">
            <h3 class="card-title"><i data-lucide="trending-up"></i><span>Revenue Trend</span></h3>
            <div class="chart-container">
              <canvas id="revenueChart"></canvas>
              <div id="revenueChartFallback" class="chart-fallback hidden">
                <div>
                  <i data-lucide="bar-chart-3" class="w-12 h-12 mx-auto"></i>
                  <p>Loading chart data...</p>
                </div>
              </div>
            </div>
          </div>
          <div class="card">
            <h3 class="card-title"><i data-lucide="pie-chart"></i><span>Revenue by Category</span></h3>
            <div class="chart-container">
              <canvas id="categoryChart"></canvas>
              <div id="categoryChartFallback" class="chart-fallback hidden">
                <div>
                  <i data-lucide="pie-chart" class="w-12 h-12 mx-auto"></i>
                  <p>Loading category data...</p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Category Breakdown Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
          <?php 
          if ($revenue_stats_result) {
              $revenue_stats_result->data_seek(0);
              while($stat = $revenue_stats_result->fetch_assoc()): 
                if ($stat['total_amount'] > 0):
          ?>
            <div class="card" style="border-left-color: <?php echo $stat['category_color']; ?>">
              <div class="flex items-center justify-between">
                <div>
                  <p class="card-title" style="color: <?php echo $stat['category_color']; ?>">
                    <i data-lucide="circle" style="color: <?php echo $stat['category_color']; ?>"></i>
                    <span><?php echo htmlspecialchars($stat['category_name']); ?></span>
                  </p>
                  <p class="card-value">₱<?php echo number_format($stat['total_amount'] ?? 0, 2); ?></p>
                  <p class="text-xs text-gray-400">
                    <?php echo $stat['transaction_count'] ?? 0; ?> transactions • 
                    Avg: ₱<?php echo number_format($stat['average_amount'] ?? 0, 2); ?>
                  </p>
                </div>
              </div>
            </div>
          <?php 
                endif;
              endwhile; 
          }
          ?>
        </div>

        <!-- Revenue Entries Table -->
        <div class="card">
          <h2 class="text-lg font-semibold text-yellow-400 mb-4 flex items-center gap-2">
            <i data-lucide="list"></i>
            Revenue Entries (<?php echo $revenue_result ? $revenue_result->num_rows : 0; ?>)
          </h2>
          
          <?php if ($revenue_result && $revenue_result->num_rows > 0): ?>
            <div class="overflow-x-auto">
              <table class="w-full text-sm text-left text-gray-400">
                <thead class="text-xs text-gray-400 uppercase bg-gray-800">
                  <tr>
                    <th class="px-4 py-3">Date</th>
                    <th class="px-4 py-3">Category</th>
                    <th class="px-4 py-3">Description</th>
                    <th class="px-4 py-3">Payment Method</th>
                    <th class="px-4 py-3 text-right">Amount</th>
                    <th class="px-4 py-3">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php while($entry = $revenue_result->fetch_assoc()): ?>
                  <tr class="border-b border-gray-700 hover:bg-gray-800">
                    <td class="px-4 py-3 whitespace-nowrap"><?php echo date('M j, Y', strtotime($entry['revenue_date'])); ?></td>
                    <td class="px-4 py-3">
                      <span class="badge" style="background: <?php echo $entry['category_color']; ?>20; color: <?php echo $entry['category_color']; ?>">
                        <?php echo htmlspecialchars($entry['category_name']); ?>
                      </span>
                    </td>
                    <td class="px-4 py-3"><?php echo htmlspecialchars($entry['description']); ?></td>
                    <td class="px-4 py-3">
                      <span class="capitalize"><?php echo htmlspecialchars($entry['payment_method']); ?></span>
                    </td>
                    <td class="px-4 py-3 text-right font-semibold text-yellow-400 whitespace-nowrap">
                      ₱<?php echo number_format($entry['amount'], 2); ?>
                    </td>
                    <td class="px-4 py-3">
                      <div class="flex gap-2">
                        <button onclick="openEditRevenueModal(<?php echo htmlspecialchars(json_encode($entry)); ?>)" class="text-blue-400 hover:text-blue-300 transition-colors" title="Edit">
                          <i data-lucide="edit" class="w-4 h-4"></i>
                        </button>
                        <button onclick="openDeleteRevenueModal(<?php echo $entry['id']; ?>)" class="text-red-400 hover:text-red-300 transition-colors" title="Delete">
                          <i data-lucide="trash-2" class="w-4 h-4"></i>
                        </button>
                      </div>
                    </td>
                  </tr>
                  <?php endwhile; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="empty-state">
              <i data-lucide="dollar-sign" class="w-12 h-12 mx-auto"></i>
              <p>No revenue entries found</p>
              <p class="text-sm mt-2">Add your first revenue entry using the button above</p>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Expenses Tab -->
      <div id="expenses-tab" class="tab-content <?php echo $tab == 'expenses' ? 'active' : ''; ?>">
        <!-- Expenses Statistics -->
        <div class="stats-grid mb-8">
          <div class="card expense-card">
            <div class="flex items-center justify-between">
              <div>
                <p class="card-title"><i data-lucide="trending-down"></i><span>Total Expenses</span></p>
                <p class="card-value">₱<?php echo number_format($total_expenses, 2); ?></p>
                <p class="text-xs text-gray-400">All categories</p>
              </div>
              <div class="p-3 bg-red-500/10 rounded-lg">
                <i data-lucide="trending-down" class="w-6 h-6 text-red-400"></i>
              </div>
            </div>
          </div>

          <div class="card expense-card">
            <div class="flex items-center justify-between">
              <div>
                <p class="card-title"><i data-lucide="credit-card"></i><span>Total Transactions</span></p>
                <p class="card_value"><?php echo $total_expense_transactions; ?></p>
                <p class="text-xs text-gray-400">All payments</p>
              </div>
              <div class="p-3 bg-red-500/10 rounded-lg">
                <i data-lucide="credit-card" class="w-6 h-6 text-red-400"></i>
              </div>
            </div>
          </div>

          <div class="card membership-card">
            <div class="flex items-center justify-between">
              <div>
                <p class="card-title"><i data-lucide="calendar"></i><span>Date Range</span></p>
                <p class="card-value text-sm"><?php echo date('M j', strtotime($start_date)); ?> - <?php echo date('M j', strtotime($end_date)); ?></p>
                <p class="text-xs text-gray-400">Reporting period</p>
              </div>
              <div class="p-3 bg-blue-500/10 rounded-lg">
                <i data-lucide="calendar" class="w-6 h-6 text-blue-400"></i>
              </div>
            </div>
          </div>

          <div class="card membership-card">
            <div class="flex items-center justify-between">
              <div>
                <p class="card-title"><i data-lucide="trending-up"></i><span>Avg. Transaction</span></p>
                <p class="card-value">₱<?php echo $total_expense_transactions > 0 ? number_format($total_expenses / $total_expense_transactions, 2) : '0.00'; ?></p>
                <p class="text-xs text-gray-400">Per transaction</p>
              </div>
              <div class="p-3 bg-blue-500/10 rounded-lg">
                <i data-lucide="trending-up" class="w-6 h-6 text-blue-400"></i>
              </div>
            </div>
          </div>
        </div>

        <!-- Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
          <div class="card">
            <h3 class="card-title"><i data-lucide="trending-down"></i><span>Expenses Trend</span></h3>
            <div class="chart-container">
              <canvas id="expensesChart"></canvas>
              <div id="expensesChartFallback" class="chart-fallback hidden">
                <div>
                  <i data-lucide="bar-chart-3" class="w-12 h-12 mx-auto"></i>
                  <p>Loading chart data...</p>
                </div>
              </div>
            </div>
          </div>
          <div class="card">
            <h3 class="card-title"><i data-lucide="pie-chart"></i><span>Expenses by Category</span></h3>
            <div class="chart-container">
              <canvas id="expensesCategoryChart"></canvas>
              <div id="expensesCategoryChartFallback" class="chart-fallback hidden">
                <div>
                  <i data-lucide="pie-chart" class="w-12 h-12 mx-auto"></i>
                  <p>Loading category data...</p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Category Breakdown Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
          <?php 
          if ($expense_stats_result) {
              $expense_stats_result->data_seek(0);
              while($stat = $expense_stats_result->fetch_assoc()): 
                if ($stat['total_amount'] > 0):
          ?>
            <div class="card" style="border-left-color: <?php echo $stat['category_color']; ?>">
              <div class="flex items-center justify-between">
                <div>
                  <p class="card-title" style="color: <?php echo $stat['category_color']; ?>">
                    <i data-lucide="circle" style="color: <?php echo $stat['category_color']; ?>"></i>
                    <span><?php echo htmlspecialchars($stat['category_name']); ?></span>
                  </p>
                  <p class="card-value">₱<?php echo number_format($stat['total_amount'] ?? 0, 2); ?></p>
                  <p class="text-xs text-gray-400">
                    <?php echo $stat['transaction_count'] ?? 0; ?> transactions • 
                    Avg: ₱<?php echo number_format($stat['average_amount'] ?? 0, 2); ?>
                  </p>
                </div>
              </div>
            </div>
          <?php 
                endif;
              endwhile; 
          }
          ?>
        </div>

        <!-- Expense Entries Table -->
        <div class="card">
          <h2 class="text-lg font-semibold text-red-400 mb-4 flex items-center gap-2">
            <i data-lucide="list"></i>
            Expense Entries (<?php echo $expense_result ? $expense_result->num_rows : 0; ?>)
          </h2>
          
          <?php if ($expense_result && $expense_result->num_rows > 0): ?>
            <div class="overflow-x-auto">
              <table class="w-full text-sm text-left text-gray-400">
                <thead class="text-xs text-gray-400 uppercase bg-gray-800">
                  <tr>
                    <th class="px-4 py-3">Date</th>
                    <th class="px-4 py-3">Category</th>
                    <th class="px-4 py-3">Description</th>
                    <th class="px-4 py-3">Payment Method</th>
                    <th class="px-4 py-3 text-right">Amount</th>
                    <th class="px-4 py-3">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php while($entry = $expense_result->fetch_assoc()): ?>
                  <tr class="border-b border-gray-700 hover:bg-gray-800">
                    <td class="px-4 py-3 whitespace-nowrap"><?php echo date('M j, Y', strtotime($entry['expense_date'])); ?></td>
                    <td class="px-4 py-3">
                      <span class="badge" style="background: <?php echo $entry['category_color']; ?>20; color: <?php echo $entry['category_color']; ?>">
                        <?php echo htmlspecialchars($entry['category_name']); ?>
                      </span>
                    </td>
                    <td class="px-4 py-3"><?php echo htmlspecialchars($entry['description']); ?></td>
                    <td class="px-4 py-3">
                      <span class="capitalize"><?php echo htmlspecialchars($entry['payment_method']); ?></span>
                    </td>
                    <td class="px-4 py-3 text-right font-semibold text-red-400 whitespace-nowrap">
                      ₱<?php echo number_format($entry['amount'], 2); ?>
                    </td>
                    <td class="px-4 py-3">
                      <div class="flex gap-2">
                        <button onclick="openEditExpenseModal(<?php echo htmlspecialchars(json_encode($entry)); ?>)" class="text-blue-400 hover:text-blue-300 transition-colors" title="Edit">
                          <i data-lucide="edit" class="w-4 h-4"></i>
                        </button>
                        <button onclick="openDeleteExpenseModal(<?php echo $entry['id']; ?>)" class="text-red-400 hover:text-red-300 transition-colors" title="Delete">
                          <i data-lucide="trash-2" class="w-4 h-4"></i>
                        </button>
                      </div>
                    </td>
                  </tr>
                  <?php endwhile; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="empty-state">
              <i data-lucide="trending-down" class="w-12 h-12 mx-auto"></i>
              <p>No expense entries found</p>
              <p class="text-sm mt-2">Add your first expense entry using the button above</p>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Profit & Loss Tab -->
      <div id="profit-tab" class="tab-content <?php echo $tab == 'profit' ? 'active' : ''; ?>">
        <!-- Profit & Loss Statistics -->
        <div class="stats-grid mb-8">
          <div class="card stat-card">
            <div class="flex items-center justify-between">
              <div>
                <p class="card-title"><i data-lucide="dollar-sign"></i><span>Total Revenue</span></p>
                <p class="card-value">₱<?php echo number_format($total_revenue, 2); ?></p>
                <p class="text-xs text-gray-400">All income</p>
              </div>
              <div class="p-3 bg-yellow-500/10 rounded-lg">
                <i data-lucide="dollar-sign" class="w-6 h-6 text-yellow-400"></i>
              </div>
            </div>
          </div>

          <div class="card expense-card">
            <div class="flex items-center justify-between">
              <div>
                <p class="card-title"><i data-lucide="trending-down"></i><span>Total Expenses</span></p>
                <p class="card-value">₱<?php echo number_format($total_expenses, 2); ?></p>
                <p class="text-xs text-gray-400">All costs</p>
              </div>
              <div class="p-3 bg-red-500/10 rounded-lg">
                <i data-lucide="trending-down" class="w-6 h-6 text-red-400"></i>
              </div>
            </div>
          </div>

          <div class="card profit-card">
            <div class="flex items-center justify-between">
              <div>
                <p class="card-title"><i data-lucide="bar-chart-3"></i><span>Net Profit</span></p>
                <p class="card-value <?php echo $net_profit > 0 ? 'profit-positive' : ($net_profit < 0 ? 'profit-negative' : 'profit-neutral'); ?>">
                  ₱<?php echo number_format($net_profit, 2); ?>
                </p>
                <p class="text-xs text-gray-400">Revenue - Expenses</p>
              </div>
              <div class="p-3 bg-green-500/10 rounded-lg">
                <i data-lucide="bar-chart-3" class="w-6 h-6 text-green-400"></i>
              </div>
            </div>
          </div>

          <div class="card membership-card">
            <div class="flex items-center justify-between">
              <div>
                <p class="card-title"><i data-lucide="percent"></i><span>Expense Ratio</span></p>
                <p class="card-value"><?php echo number_format($expense_ratio, 1); ?>%</p>
                <p class="text-xs text-gray-400">Expenses/Revenue</p>
              </div>
              <div class="p-3 bg-blue-500/10 rounded-lg">
                <i data-lucide="percent" class="w-6 h-6 text-blue-400"></i>
              </div>
            </div>
          </div>
        </div>

        <!-- Profit & Loss Chart -->
        <div class="card mb-8">
          <h3 class="card-title"><i data-lucide="bar-chart-3"></i><span>Profit & Loss Trend</span></h3>
          <div class="chart-container">
            <canvas id="profitChart"></canvas>
            <div id="profitChartFallback" class="chart-fallback hidden">
              <div>
                <i data-lucide="bar-chart-3" class="w-12 h-12 mx-auto"></i>
                <p>Loading profit data...</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Financial Summary -->
        <div class="card">
          <h3 class="card-title"><i data-lucide="file-text"></i><span>Financial Summary</span></h3>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <h4 class="text-yellow-400 font-semibold mb-3">Revenue Summary</h4>
              <div class="space-y-2">
                <div class="flex justify-between">
                  <span class="text-gray-400">Total Revenue:</span>
                  <span class="text-yellow-400 font-semibold">₱<?php echo number_format($total_revenue, 2); ?></span>
                </div>
                <div class="flex justify-between">
                  <span class="text-gray-400">Transactions:</span>
                  <span class="text-gray-300"><?php echo $total_transactions; ?></span>
                </div>
                <div class="flex justify-between">
                  <span class="text-gray-400">Average Transaction:</span>
                  <span class="text-gray-300">₱<?php echo $total_transactions > 0 ? number_format($total_revenue / $total_transactions, 2) : '0.00'; ?></span>
                </div>
              </div>
            </div>
            <div>
              <h4 class="text-red-400 font-semibold mb-3">Expenses Summary</h4>
              <div class="space-y-2">
                <div class="flex justify-between">
                  <span class="text-gray-400">Total Expenses:</span>
                  <span class="text-red-400 font-semibold">₱<?php echo number_format($total_expenses, 2); ?></span>
                </div>
                <div class="flex justify-between">
                  <span class="text-gray-400">Transactions:</span>
                  <span class="text-gray-300"><?php echo $total_expense_transactions; ?></span>
                </div>
                <div class="flex justify-between">
                  <span class="text-gray-400">Average Transaction:</span>
                  <span class="text-gray-300">₱<?php echo $total_expense_transactions > 0 ? number_format($total_expenses / $total_expense_transactions, 2) : '0.00'; ?></span>
                </div>
              </div>
            </div>
          </div>
          
          <div class="border-t border-gray-700 mt-4 pt-4">
            <div class="flex justify-between items-center">
              <span class="text-lg font-semibold <?php echo $net_profit > 0 ? 'profit-positive' : ($net_profit < 0 ? 'profit-negative' : 'profit-neutral'); ?>">
                Net Profit/Loss:
              </span>
              <span class="text-xl font-bold <?php echo $net_profit > 0 ? 'profit-positive' : ($net_profit < 0 ? 'profit-negative' : 'profit-neutral'); ?>">
                ₱<?php echo number_format($net_profit, 2); ?>
              </span>
            </div>
            <div class="flex justify-between mt-2">
              <span class="text-gray-400">Expense Ratio:</span>
              <span class="text-gray-300"><?php echo number_format($expense_ratio, 1); ?>%</span>
            </div>
          </div>
        </div>
      </div>

    </main>
  </div>

  <!-- Revenue Modal -->
  <div id="revenueModal" class="modal">
    <div class="modal-content">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-semibold text-yellow-400" id="revenueModalTitle">Add Revenue Entry</h3>
        <button onclick="closeRevenueModal()" class="text-gray-400 hover:text-white transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>
      
      <form method="POST" id="revenueForm">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="entry_id" id="revenue_entry_id">
        <div class="space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="form-label">Category *</label>
              <select name="category_id" id="revenue_category_id" required class="form-input">
                <option value="">Select Category</option>
                <option value="1">Product Sales</option>
                <option value="4">Service (Treadmill)</option>
              </select>
            </div>
            <div>
              <label class="form-label">Amount (₱) *</label>
              <input type="number" name="amount" id="revenue_amount" step="0.01" min="0.01" required class="form-input" placeholder="0.00">
            </div>
          </div>
          
          <div>
            <label class="form-label">Description *</label>
            <input type="text" name="description" id="revenue_description" required class="form-input" placeholder="Enter revenue description">
          </div>
          
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="form-label">Payment Method *</label>
              <select name="payment_method" id="revenue_payment_method" required class="form-input">
                <option value="cash">Cash</option>
                <option value="gcash">GCash</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="card">Card</option>
                <option value="online">Online</option>
              </select>
            </div>
            <div>
              <label class="form-label">Revenue Date *</label>
              <input type="date" name="revenue_date" id="revenue_date" required class="form-input" value="<?php echo date('Y-m-d'); ?>">
            </div>
          </div>
          
          <div>
            <label class="form-label">Reference Name (Optional)</label>
            <input type="text" name="reference_name" id="revenue_reference_name" class="form-input" placeholder="Enter reference name if applicable">
          </div>
          
          <div>
            <label class="form-label">Notes</label>
            <textarea name="notes" id="revenue_notes" rows="3" class="form-input" placeholder="Additional notes (optional)"></textarea>
          </div>
        </div>
        
        <div class="flex gap-2 mt-6">
          <button type="button" onclick="closeRevenueModal()" class="flex-1 button-sm btn-outline">Cancel</button>
          <button type="submit" name="add_revenue" id="revenueSubmitButton" class="flex-1 button-sm btn-primary">Add Revenue</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Expense Modal -->
  <div id="expenseModal" class="modal">
    <div class="modal-content">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-semibold text-red-400" id="expenseModalTitle">Add Expense Entry</h3>
        <button onclick="closeExpenseModal()" class="text-gray-400 hover:text-white transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>
      
      <form method="POST" id="expenseForm">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="expense_id" id="expense_id">
        <div class="space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="form-label">Category *</label>
              <select name="expense_category_id" id="expense_category_id" required class="form-input">
                <option value="">Select Category</option>
                <?php foreach($expense_categories as $cat): ?>
                      <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="form-label">Amount (₱) *</label>
              <input type="number" name="expense_amount" id="expense_amount" step="0.01" min="0.01" required class="form-input" placeholder="0.00">
            </div>
          </div>
          
          <div>
            <label class="form-label">Description *</label>
            <input type="text" name="expense_description" id="expense_description" required class="form-input" placeholder="Enter expense description">
          </div>
          
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="form-label">Payment Method *</label>
              <select name="expense_payment_method" id="expense_payment_method" required class="form-input">
                <option value="cash">Cash</option>
                <option value="gcash">GCash</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="card">Card</option>
                <option value="online">Online</option>
              </select>
            </div>
            <div>
              <label class="form-label">Expense Date *</label>
              <input type="date" name="expense_date" id="expense_date" required class="form-input" value="<?php echo date('Y-m-d'); ?>">
            </div>
          </div>
          
          <div>
            <label class="form-label">Notes</label>
            <textarea name="expense_notes" id="expense_notes" rows="3" class="form-input" placeholder="Additional notes (optional)"></textarea>
          </div>
        </div>
        
        <div class="flex gap-2 mt-6">
          <button type="button" onclick="closeExpenseModal()" class="flex-1 button-sm btn-outline">Cancel</button>
          <button type="submit" name="add_expense" id="expenseSubmitButton" class="flex-1 button-sm btn-danger">Add Expense</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Delete Revenue Modal -->
  <div id="deleteRevenueModal" class="modal">
    <div class="modal-content">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-semibold text-red-400">Delete Revenue Entry</h3>
        <button onclick="closeDeleteRevenueModal()" class="text-gray-400 hover:text-white transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>
      
      <p class="text-gray-300 mb-6">Are you sure you want to delete this revenue entry? This action cannot be undone.</p>
      
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="entry_id" id="delete_revenue_entry_id">
        <div class="flex gap-2">
          <button type="button" onclick="closeDeleteRevenueModal()" class="flex-1 button-sm btn-outline">Cancel</button>
          <button type="submit" name="delete_revenue" class="flex-1 button-sm btn-danger">Delete</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Delete Expense Modal -->
  <div id="deleteExpenseModal" class="modal">
    <div class="modal-content">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-semibold text-red-400">Delete Expense Entry</h3>
        <button onclick="closeDeleteExpenseModal()" class="text-gray-400 hover:text-white transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>
      
      <p class="text-gray-300 mb-6">Are you sure you want to delete this expense entry? This action cannot be undone.</p>
      
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="expense_id" id="delete_expense_id">
        <div class="flex gap-2">
          <button type="button" onclick="closeDeleteExpenseModal()" class="flex-1 button-sm btn-outline">Cancel</button>
          <button type="submit" name="delete_expense" class="flex-1 button-sm btn-danger">Delete</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Global chart variables
    let revenueChart = null;
    let categoryChart = null;
    let expensesChart = null;
    let expensesCategoryChart = null;
    let profitChart = null;

    document.addEventListener('DOMContentLoaded', function() {
        lucide.createIcons();
        
        // Initialize charts based on current tab
        initializeCharts();
        
        // Load notifications
        loadNotifications();
        
        // Dropdown functionality
        const userMenuButton = document.getElementById('userMenuButton');
        const userDropdown = document.getElementById('userDropdown');
        const notificationBell = document.getElementById('notificationBell');
        const notificationDropdown = document.getElementById('notificationDropdown');
        const exportButton = document.getElementById('exportButton');
        const exportDropdown = document.getElementById('exportDropdown');

        // User dropdown toggle
        userMenuButton.addEventListener('click', (e) => {
            e.stopPropagation();
            userDropdown.classList.toggle('show');
            notificationDropdown.classList.remove('show');
            exportDropdown.classList.remove('show');
        });

        // Notification dropdown toggle
        notificationBell.addEventListener('click', (e) => {
            e.stopPropagation();
            notificationDropdown.classList.toggle('show');
            userDropdown.classList.remove('show');
            exportDropdown.classList.remove('show');
            
            // Load notifications when dropdown is opened
            if (notificationDropdown.classList.contains('show')) {
                loadNotifications();
            }
        });

        // Export dropdown functionality
        let exportDropdownMoved = false;

        exportButton.addEventListener('click', (e) => {
            e.stopPropagation();
            e.preventDefault();
            
            // Close other dropdowns
            userDropdown.classList.remove('show');
            notificationDropdown.classList.remove('show');
            
            // Move dropdown to body if not already moved
            if (!exportDropdownMoved) {
                document.body.appendChild(exportDropdown);
                exportDropdownMoved = true;
            }
            
            // Toggle export dropdown
            const isShowing = exportDropdown.classList.contains('show');
            
            if (!isShowing) {
                // Get button position
                const rect = exportButton.getBoundingClientRect();
                
                // Position dropdown absolutely
                exportDropdown.style.position = 'fixed';
                exportDropdown.style.top = `${rect.bottom + window.scrollY + 5}px`;
                exportDropdown.style.left = `${rect.left + window.scrollX}px`;
                exportDropdown.style.zIndex = '10000';
                
                // Ensure it doesn't go off screen
                const viewportWidth = window.innerWidth;
                const dropdownWidth = exportDropdown.offsetWidth;
                
                if (rect.left + dropdownWidth > viewportWidth - 20) {
                    exportDropdown.style.left = `${viewportWidth - dropdownWidth - 20}px`;
                }
                
                exportDropdown.classList.add('show');
            } else {
                exportDropdown.classList.remove('show');
            }
        });

        // Export option handlers
        document.querySelectorAll('.export-option').forEach(option => {
            option.addEventListener('click', (e) => {
                e.preventDefault();
                const format = option.getAttribute('data-format');
                const report = option.getAttribute('data-report') || 'detailed';
                
                // Get current form data
                const formData = new FormData(document.getElementById('filterForm'));
                const params = new URLSearchParams();
                
                // Add all form data
                for (let [key, value] of formData) {
                    if (value) params.append(key, value);
                }
                
                // Add export parameters
                params.append('export', format);
                params.append('report_type', report);
                
                // Show loading state
                const originalText = exportButton.innerHTML;
                exportButton.innerHTML = '<div class="loading-spinner"></div> Exporting...';
                exportButton.disabled = true;
                
                // Open in new window for download
                setTimeout(() => {
                    window.open(`revenue_export.php?${params.toString()}`, '_blank');
                    
                    // Restore button state
                    exportButton.innerHTML = originalText;
                    exportButton.disabled = false;
                    
                    // Close dropdown
                    exportDropdown.classList.remove('show');
                }, 500);
            });
        });

        // Close dropdowns when clicking outside
        document.addEventListener('click', (e) => {
            if (!userMenuButton.contains(e.target) && !userDropdown.contains(e.target)) {
                userDropdown.classList.remove('show');
            }
            if (!notificationBell.contains(e.target) && !notificationDropdown.contains(e.target)) {
                notificationDropdown.classList.remove('show');
            }
        });

        // Sidebar toggle
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

        // Filter form submission - reinitialize charts
        document.getElementById('filterForm').addEventListener('submit', function() {
            // Charts will be reinitialized after page reload
        });

        // Date validation
        const startDate = document.getElementById('startDate');
        const endDate = document.getElementById('endDate');
        
        if (startDate && endDate) {
            startDate.addEventListener('change', function() {
                if (this.value > endDate.value) {
                    endDate.value = this.value;
                }
            });
            
            endDate.addEventListener('change', function() {
                if (this.value < startDate.value) {
                    startDate.value = this.value;
                }
            });
        }

        // Auto-populate reference name based on category selection
        document.getElementById('revenue_category_id').addEventListener('change', function() {
            const categoryId = this.value;
            const referenceName = document.getElementById('revenue_reference_name');
            
            if (categoryId === '1') {
                referenceName.placeholder = 'Enter product name...';
            } else if (categoryId === '4') {
                referenceName.placeholder = 'Treadmill service...';
            } else {
                referenceName.placeholder = 'Optional reference name';
            }
        });
    });

    // Load notifications function
    function loadNotifications() {
        const notificationList = document.getElementById('notificationList');
        const notificationBadge = document.getElementById('notificationBadge');
        const notificationCount = document.getElementById('notificationCount');
        
        notificationList.innerHTML = '<div class="p-3 text-center text-gray-500 text-sm"><div class="loading-spinner"></div> Loading notifications...</div>';
        
        fetch('revenue.php?ajax=notifications')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.notifications.length > 0) {
                        let notificationsHtml = '';
                        data.notifications.forEach(notification => {
                            const unreadClass = notification.read_status ? '' : 'unread';
                            notificationsHtml += `
                                <div class="notification-item ${unreadClass}" onclick="markNotificationAsRead(${notification.id})">
                                    <div class="notification-title">${notification.title}</div>
                                    <div class="notification-message">${notification.message}</div>
                                    <div class="notification-time">${notification.time_ago}</div>
                                </div>
                            `;
                        });
                        notificationList.innerHTML = notificationsHtml;
                    } else {
                        notificationList.innerHTML = '<div class="p-3 text-center text-gray-500 text-sm">No notifications</div>';
                    }
                    
                    // Update badge count
                    if (notificationBadge) {
                        notificationBadge.textContent = '0';
                        notificationBadge.style.display = 'none';
                    }
                    if (notificationCount) {
                        notificationCount.textContent = '0';
                    }
                } else {
                    notificationList.innerHTML = '<div class="p-3 text-center text-gray-500 text-sm">Failed to load notifications</div>';
                }
            })
            .catch(error => {
                console.error('Error loading notifications:', error);
                notificationList.innerHTML = '<div class="p-3 text-center text-gray-500 text-sm">Error loading notifications</div>';
            });
    }

    function markNotificationAsRead(notificationId) {
        // This would typically make an AJAX call to mark the notification as read
        // For now, we'll just reload the notifications
        loadNotifications();
    }

    // Destroy all charts function
    function destroyAllCharts() {
        const charts = [revenueChart, categoryChart, expensesChart, expensesCategoryChart, profitChart];
        charts.forEach(chart => {
            if (chart) {
                chart.destroy();
                chart = null;
            }
        });
    }

    // Initialize charts - IMPROVED VERSION
    function initializeCharts(forceTab = null) {
        const startDate = document.getElementById('startDate')?.value || '<?php echo $start_date; ?>';
        const endDate = document.getElementById('endDate')?.value || '<?php echo $end_date; ?>';
        const currentTab = forceTab || '<?php echo $tab; ?>';
        
        console.log('Initializing charts for tab:', currentTab, 'Date range:', startDate, 'to', endDate);
        
        // Show loading states for current tab only
        showChartFallbacks(currentTab);
        
        fetch(`revenue.php?ajax=chart_data&start_date=${startDate}&end_date=${endDate}&tab=${currentTab}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Chart data received:', data);
                if (data.success) {
                    if (currentTab === 'revenue') {
                        createRevenueChart(data.line_chart);
                        createCategoryChart(data.pie_chart);
                    } else if (currentTab === 'expenses') {
                        createExpensesChart(data.line_chart);
                        createExpensesCategoryChart(data.pie_chart);
                    } else if (currentTab === 'profit') {
                        createProfitChart(data.line_chart);
                    }
                    hideChartFallbacks(currentTab);
                } else {
                    throw new Error('Chart data not successful: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error loading chart data:', error);
                showChartErrors(currentTab, error.message);
            });
    }

    function showChartFallbacks(tab) {
        const fallbacks = {
            'revenue': ['revenueChartFallback', 'categoryChartFallback'],
            'expenses': ['expensesChartFallback', 'expensesCategoryChartFallback'],
            'profit': ['profitChartFallback']
        };
        
        if (fallbacks[tab]) {
            fallbacks[tab].forEach(fallbackId => {
                const fallback = document.getElementById(fallbackId);
                if (fallback) fallback.classList.remove('hidden');
            });
        }
    }

    function hideChartFallbacks(tab) {
        const fallbacks = {
            'revenue': ['revenueChartFallback', 'categoryChartFallback'],
            'expenses': ['expensesChartFallback', 'expensesCategoryChartFallback'],
            'profit': ['profitChartFallback']
        };
        
        if (fallbacks[tab]) {
            fallbacks[tab].forEach(fallbackId => {
                const fallback = document.getElementById(fallbackId);
                if (fallback) fallback.classList.add('hidden');
            });
        }
    }

    function showChartErrors(tab, errorMessage = '') {
        const fallbacks = {
            'revenue': ['revenueChartFallback', 'categoryChartFallback'],
            'expenses': ['expensesChartFallback', 'expensesCategoryChartFallback'],
            'profit': ['profitChartFallback']
        };
        
        if (fallbacks[tab]) {
            fallbacks[tab].forEach(fallbackId => {
                const fallback = document.getElementById(fallbackId);
                if (fallback) {
                    fallback.innerHTML = `
                        <div>
                            <i data-lucide="alert-circle" class="w-12 h-12 mx-auto text-red-400"></i>
                            <p>Failed to load chart data</p>
                            ${errorMessage ? `<p class="text-xs mt-1">${errorMessage}</p>` : ''}
                            <button onclick="initializeCharts('${tab}')" class="button-sm btn-primary mt-2">
                                <i data-lucide="refresh-cw"></i> Retry
                            </button>
                        </div>
                    `;
                    lucide.createIcons();
                    fallback.classList.remove('hidden');
                }
            });
        }
    }

    // Helper function for no data messages
    function showNoDataMessage(fallbackId, message) {
        const fallback = document.getElementById(fallbackId);
        if (fallback) {
            fallback.innerHTML = `
                <div>
                    <i data-lucide="bar-chart-3" class="w-12 h-12 mx-auto text-gray-500"></i>
                    <p class="text-gray-400">${message}</p>
                </div>
            `;
            lucide.createIcons();
            fallback.classList.remove('hidden');
        }
    }

    function createRevenueChart(chartData) {
        const ctx = document.getElementById('revenueChart');
        if (!ctx) {
            console.error('Revenue chart canvas not found');
            return;
        }
        
        // Destroy existing chart
        if (revenueChart) {
            revenueChart.destroy();
        }
        
        // Check if we have data
        if (!chartData || !chartData.labels || chartData.labels.length === 0) {
            showNoDataMessage('revenueChartFallback', 'No revenue data available for the selected period');
            return;
        }
        
        revenueChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'Revenue (₱)',
                    data: chartData.data,
                    borderColor: '#fbbf24',
                    backgroundColor: 'rgba(251, 191, 36, 0.15)',
                    fill: true,
                    tension: 0.3,
                    borderWidth: 2,
                    pointBackgroundColor: '#fbbf24',
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { 
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `Revenue: ₱${context.parsed.y.toFixed(2)}`;
                            }
                        }
                    }
                },
                scales: {
                    x: { 
                        ticks: { color: '#94a3b8' }, 
                        grid: { color: 'rgba(255, 255, 255, 0.05)' } 
                    },
                    y: { 
                        ticks: { 
                            color: '#94a3b8',
                            callback: value => '₱' + value
                        }, 
                        grid: { color: 'rgba(255, 255, 255, 0.05)' }, 
                        beginAtZero: true 
                    }
                }
            }
        });
    }

    function createCategoryChart(chartData) {
        const ctx = document.getElementById('categoryChart');
        if (!ctx) return;
        
        // Destroy existing chart
        if (categoryChart) {
            categoryChart.destroy();
        }
        
        if (chartData.labels.length > 0) {
            categoryChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: chartData.labels,
                    datasets: [{
                        data: chartData.data,
                        backgroundColor: chartData.colors,
                        borderWidth: 2,
                        borderColor: '#1a1a1a'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: { 
                                color: '#94a3b8',
                                font: { size: 11 }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = Math.round((value / total) * 100);
                                    return `${label}: ₱${value.toFixed(2)} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        } else {
            // Show no data message
            const fallback = document.getElementById('categoryChartFallback');
            if (fallback) {
                fallback.innerHTML = `
                    <div>
                        <i data-lucide="pie-chart" class="w-12 h-12 mx-auto"></i>
                        <p>No revenue data available</p>
                    </div>
                `;
                lucide.createIcons();
                fallback.classList.remove('hidden');
            }
        }
    }

    function createExpensesChart(chartData) {
        const ctx = document.getElementById('expensesChart');
        if (!ctx) return;
        
        // Destroy existing chart
        if (expensesChart) {
            expensesChart.destroy();
        }
        
        // Check if we have data
        if (!chartData || !chartData.labels || chartData.labels.length === 0) {
            showNoDataMessage('expensesChartFallback', 'No expense data available for the selected period');
            return;
        }
        
        expensesChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'Expenses (₱)',
                    data: chartData.data,
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239, 68, 68, 0.15)',
                    fill: true,
                    tension: 0.3,
                    borderWidth: 2,
                    pointBackgroundColor: '#ef4444',
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { 
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `Expenses: ₱${context.parsed.y.toFixed(2)}`;
                            }
                        }
                    }
                },
                scales: {
                    x: { 
                        ticks: { color: '#94a3b8' }, 
                        grid: { color: 'rgba(255, 255, 255, 0.05)' } 
                    },
                    y: { 
                        ticks: { 
                            color: '#94a3b8',
                            callback: value => '₱' + value
                        }, 
                        grid: { color: 'rgba(255, 255, 255, 0.05)' }, 
                        beginAtZero: true 
                    }
                }
            }
        });
    }

    function createExpensesCategoryChart(chartData) {
        const ctx = document.getElementById('expensesCategoryChart');
        if (!ctx) return;
        
        // Destroy existing chart
        if (expensesCategoryChart) {
            expensesCategoryChart.destroy();
        }
        
        if (chartData.labels.length > 0) {
            expensesCategoryChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: chartData.labels,
                    datasets: [{
                        data: chartData.data,
                        backgroundColor: chartData.colors,
                        borderWidth: 2,
                        borderColor: '#1a1a1a'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: { 
                                color: '#94a3b8',
                                font: { size: 11 }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = Math.round((value / total) * 100);
                                    return `${label}: ₱${value.toFixed(2)} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        } else {
            // Show no data message
            const fallback = document.getElementById('expensesCategoryChartFallback');
            if (fallback) {
                fallback.innerHTML = `
                    <div>
                        <i data-lucide="pie-chart" class="w-12 h-12 mx-auto"></i>
                        <p>No expense data available</p>
                    </div>
                `;
                lucide.createIcons();
                fallback.classList.remove('hidden');
            }
        }
    }

    function createProfitChart(chartData) {
        const ctx = document.getElementById('profitChart');
        if (!ctx) return;
        
        // Destroy existing chart
        if (profitChart) {
            profitChart.destroy();
        }
        
        // Check if we have data
        if (!chartData || !chartData.labels || chartData.labels.length === 0) {
            showNoDataMessage('profitChartFallback', 'No profit data available for the selected period');
            return;
        }
        
        profitChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartData.labels,
                datasets: [
                    {
                        label: 'Revenue',
                        data: chartData.revenue,
                        borderColor: '#22c55e',
                        backgroundColor: 'rgba(34, 197, 94, 0.1)',
                        fill: false,
                        tension: 0.3,
                        borderWidth: 2,
                        pointBackgroundColor: '#22c55e'
                    },
                    {
                        label: 'Expenses',
                        data: chartData.expenses,
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        fill: false,
                        tension: 0.3,
                        borderWidth: 2,
                        pointBackgroundColor: '#ef4444'
                    },
                    {
                        label: 'Profit/Loss',
                        data: chartData.profit,
                        borderColor: '#fbbf24',
                        backgroundColor: 'rgba(251, 191, 36, 0.1)',
                        fill: false,
                        tension: 0.3,
                        borderWidth: 3,
                        pointBackgroundColor: '#fbbf24',
                        borderDash: [5, 5]
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `${context.dataset.label}: ₱${context.parsed.y.toFixed(2)}`;
                            }
                        }
                    }
                },
                scales: {
                    x: { 
                        ticks: { color: '#94a3b8' }, 
                        grid: { color: 'rgba(255, 255, 255, 0.05)' } 
                    },
                    y: { 
                        ticks: { 
                            color: '#94a3b8',
                            callback: value => '₱' + value
                        }, 
                        grid: { color: 'rgba(255, 255, 255, 0.05)' } 
                    }
                }
            }
        });
    }

    // Tab switching - IMPROVED VERSION
    function switchTab(tabName) {
        // Update URL without reloading page
        const url = new URL(window.location);
        url.searchParams.set('tab', tabName);
        window.history.replaceState({}, '', url);
        
        // Hide all tab contents
        document.querySelectorAll('.tab-content').forEach(tab => {
            tab.classList.remove('active');
        });
        
        // Show selected tab content
        document.getElementById(tabName + '-tab').classList.add('active');
        
        // Update tab active states
        document.querySelectorAll('.tab').forEach(tab => {
            tab.classList.remove('active');
        });
        event.currentTarget.classList.add('active');
        
        // Update the action button based on active tab
        updateActionButton(tabName);
        
        // Update the hidden tab field in filter form
        document.querySelector('input[name="tab"]').value = tabName;
        
        // Destroy existing charts first
        destroyAllCharts();
        
        // Reinitialize charts for the new tab with a small delay to ensure DOM is ready
        setTimeout(() => {
            initializeCharts(tabName);
        }, 100);
    }

    // Function to update the action button based on active tab
    function updateActionButton(activeTab) {
        const actionButtonContainer = document.getElementById('actionButtonContainer');
        
        // Clear existing button
        actionButtonContainer.innerHTML = '';
        
        // Create new button based on active tab
        if (activeTab === 'revenue') {
            actionButtonContainer.innerHTML = `
                <button onclick="openAddRevenueModal()" class="button-sm btn-primary">
                    <i data-lucide="plus"></i> Add Revenue
                </button>
            `;
        } else if (activeTab === 'expenses') {
            actionButtonContainer.innerHTML = `
                <button onclick="openAddExpenseModal()" class="button-sm btn-danger">
                    <i data-lucide="plus"></i> Add Expense
                </button>
            `;
        }
        // For 'profit' tab, no action button is shown
        
        // Re-initialize Lucide icons for the new button
        lucide.createIcons();
    }

    // Modal functions for Revenue
    function openAddRevenueModal() {
        document.getElementById('revenueModalTitle').textContent = 'Add Revenue Entry';
        document.getElementById('revenueSubmitButton').name = 'add_revenue';
        document.getElementById('revenueSubmitButton').textContent = 'Add Revenue';
        document.getElementById('revenueForm').reset();
        document.getElementById('revenue_date').value = '<?php echo date('Y-m-d'); ?>';
        document.getElementById('revenueModal').style.display = 'block';
    }

    function openEditRevenueModal(entry) {
        document.getElementById('revenueModalTitle').textContent = 'Edit Revenue Entry';
        document.getElementById('revenueSubmitButton').name = 'update_revenue';
        document.getElementById('revenueSubmitButton').textContent = 'Update Revenue';
        
        document.getElementById('revenue_entry_id').value = entry.id;
        document.getElementById('revenue_category_id').value = entry.category_id;
        document.getElementById('revenue_amount').value = entry.amount;
        document.getElementById('revenue_description').value = entry.description;
        document.getElementById('revenue_payment_method').value = entry.payment_method;
        document.getElementById('revenue_date').value = entry.revenue_date;
        document.getElementById('revenue_reference_name').value = entry.reference_name || '';
        document.getElementById('revenue_notes').value = entry.notes || '';
        
        document.getElementById('revenueModal').style.display = 'block';
    }

    function closeRevenueModal() {
        document.getElementById('revenueModal').style.display = 'none';
    }

    function openDeleteRevenueModal(entryId) {
        document.getElementById('delete_revenue_entry_id').value = entryId;
        document.getElementById('deleteRevenueModal').style.display = 'block';
    }

    function closeDeleteRevenueModal() {
        document.getElementById('deleteRevenueModal').style.display = 'none';
    }

    // Modal functions for Expenses
    function openAddExpenseModal() {
        document.getElementById('expenseModalTitle').textContent = 'Add Expense Entry';
        document.getElementById('expenseSubmitButton').name = 'add_expense';
        document.getElementById('expenseSubmitButton').textContent = 'Add Expense';
        document.getElementById('expenseForm').reset();
        document.getElementById('expense_date').value = '<?php echo date('Y-m-d'); ?>';
        document.getElementById('expenseModal').style.display = 'block';
    }

    function openEditExpenseModal(entry) {
        document.getElementById('expenseModalTitle').textContent = 'Edit Expense Entry';
        document.getElementById('expenseSubmitButton').name = 'update_expense';
        document.getElementById('expenseSubmitButton').textContent = 'Update Expense';
        
        document.getElementById('expense_id').value = entry.id;
        document.getElementById('expense_category_id').value = entry.category_id;
        document.getElementById('expense_amount').value = entry.amount;
        document.getElementById('expense_description').value = entry.description;
        document.getElementById('expense_payment_method').value = entry.payment_method;
        document.getElementById('expense_date').value = entry.expense_date;
        document.getElementById('expense_notes').value = entry.notes || '';
        
        document.getElementById('expenseModal').style.display = 'block';
    }

    function closeExpenseModal() {
        document.getElementById('expenseModal').style.display = 'none';
    }

    function openDeleteExpenseModal(entryId) {
        document.getElementById('delete_expense_id').value = entryId;
        document.getElementById('deleteExpenseModal').style.display = 'block';
    }

    function closeDeleteExpenseModal() {
        document.getElementById('deleteExpenseModal').style.display = 'none';
    }

    // Close modals when clicking outside
    window.onclick = function(event) {
        const modals = ['revenueModal', 'expenseModal', 'deleteRevenueModal', 'deleteExpenseModal'];
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        });
    }
    
    // Auto-refresh notifications every 30 seconds
    setInterval(loadNotifications, 30000);
  </script>
</body>
</html>

<?php 
// Close database connection only - statements are already closed
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>



