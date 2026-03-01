<?php
/**
 * Admin Dashboard Functions
 * 
 * This file contains helper functions to extract database logic 
 * from the main admin_dashboard.php view file.
 */

/**
 * Get maintenance statistics for the dashboard
 * 
 * @param mysqli $conn Database connection
 * @return array Associative array with total, needs_maintenance, under_repair, and broken counts
 */
function getMaintenanceStats($conn) {
    $stats = [
        'total_issues' => 0,
        'needs_maintenance' => 0,
        'under_repair' => 0,
        'broken' => 0
    ];

    $sql = "SELECT 
        COUNT(*) as total_issues,
        SUM(CASE WHEN status = 'Needs Maintenance' THEN 1 ELSE 0 END) as needs_maintenance,
        SUM(CASE WHEN status = 'Under Repair' THEN 1 ELSE 0 END) as under_repair,
        SUM(CASE WHEN status = 'Broken' THEN 1 ELSE 0 END) as broken
    FROM equipment 
    WHERE status IN ('Needs Maintenance', 'Under Repair', 'Broken')";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        // Ensure values are at least 0 if null
        $stats['total_issues'] = $row['total_issues'] ?? 0;
        $stats['needs_maintenance'] = $row['needs_maintenance'] ?? 0;
        $stats['under_repair'] = $row['under_repair'] ?? 0;
        $stats['broken'] = $row['broken'] ?? 0;
    }
    $stmt->close();

    return $stats;
}

/**
 * Get recent maintenance items
 * 
 * @param mysqli $conn Database connection
 * @param int $limit Number of items to return (default 3)
 * @return mysqli_result Result set of recent maintenance items
 */
function getRecentMaintenance($conn, $limit = 3) {
    $sql = "SELECT name, status, last_updated 
            FROM equipment 
            WHERE status IN ('Needs Maintenance', 'Under Repair', 'Broken') 
            ORDER BY last_updated DESC 
            LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Handle trainer account creation
 * 
 * @param mysqli $conn Database connection
 * @param array $postData The $_POST array containing form data
 * @return string Status message (success or error)
 */
function handleTrainerCreation($conn, $postData) {
    if (!isset($postData['create_user'])) {
        return '';
    }

    $new_username = $postData['new_username'];
    $new_password = $postData['new_password'];
    $full_name = $postData['full_name'];
    $email = $postData['email'];
    
    // Check if username already exists using prepared statement
    $check_sql = "SELECT id FROM users WHERE username = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("s", $new_username);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result && $check_result->num_rows > 0) {
        $check_stmt->close();
        return "Error: Username already exists!";
    }
    $check_stmt->close();

    $hashed_password = md5($new_password);
    $sql = "INSERT INTO users (username, password, role, full_name, email) 
            VALUES (?, ?, 'trainer', ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssss", $new_username, $hashed_password, $full_name, $email);
    
    if ($stmt->execute()) {
        $stmt->close();
        // Create notification for the action
        if (function_exists('createNotification')) {
            createNotification($conn, null, 'admin', 'New Trainer Created', 
                "Trainer account created for $full_name", 'system', 'medium');
        }
        return "Trainer account created successfully!";
    }

    $error = $stmt->error;
    $stmt->close();
    return "Error creating trainer account: " . $error;
}

/**
 * Get revenue chart data for a date range
 */
function getRevenueChartData($conn, $start_date, $end_date) {
    $data = [];
    $sql = "SELECT 
                dates.date as date,
                COALESCE(SUM(re.amount), 0) + COALESCE(SUM(mp.amount), 0) as total_revenue
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
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssssss", $start_date, $end_date, $start_date, $end_date, $start_date, $start_date, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $data[$row['date']] = floatval($row['total_revenue']);
    }
    $stmt->close();
    return $data;
}

/**
 * Get revenue statistics by category (returning mysqli_result)
 */
function getRevenueCategoryStats($conn, $start_date, $end_date) {
    $sql = "SELECT 
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
              ORDER BY total_amount DESC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param("ssss", $start_date, $end_date, $start_date, $end_date);
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Get revenue category data for pie chart
 */
function getRevenueCategoryData($conn, $start_date, $end_date) {
    $labels = []; $data = []; $colors = []; $details = [];
    $result = getRevenueCategoryStats($conn, $start_date, $end_date);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if ($row['total_amount'] > 0) {
                $labels[] = $row['category_name'];
                $data[] = floatval($row['total_amount']);
                $colors[] = $row['category_color'];
                $details[] = [
                    'transactions' => $row['transaction_count'],
                    'average' => floatval($row['average_amount'])
                ];
            }
        }
    }
    return ['labels' => $labels, 'data' => $data, 'colors' => $colors, 'details' => $details];
}

/**
 * Get expense chart data for a date range
 */
function getExpenseChartData($conn, $start_date, $end_date) {
    $data = [];
    $sql = "SELECT 
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
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssss", $start_date, $end_date, $start_date, $start_date, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $data[$row['date']] = floatval($row['daily_expenses']);
    }
    $stmt->close();
    return $data;
}

/**
 * Get expense statistics by category (returning mysqli_result)
 */
function getExpenseCategoryStats($conn, $start_date, $end_date) {
    $sql = "SELECT 
                ec.name as category_name,
                ec.color as category_color,
                COALESCE(SUM(e.amount), 0) as total_amount,
                COUNT(e.id) as transaction_count,
                COALESCE(AVG(e.amount), 0) as average_amount
              FROM expense_categories ec
              LEFT JOIN expenses e ON ec.id = e.category_id AND e.expense_date BETWEEN ? AND ?
              GROUP BY ec.id, ec.name, ec.color
              ORDER BY total_amount DESC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Get expense category data for pie chart
 */
function getExpenseCategoryData($conn, $start_date, $end_date) {
    $labels = []; $data = []; $colors = []; $details = [];
    $result = getExpenseCategoryStats($conn, $start_date, $end_date);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if ($row['total_amount'] > 0) {
                $labels[] = $row['category_name'];
                $data[] = floatval($row['total_amount']);
                $colors[] = $row['category_color'];
                $details[] = [
                    'transactions' => $row['transaction_count'],
                    'average' => floatval($row['average_amount'])
                ];
            }
        }
    }
    return ['labels' => $labels, 'data' => $data, 'colors' => $colors, 'details' => $details];
}

/**
 * Get profit chart data for a date range
 */
function getProfitChartData($conn, $start_date, $end_date) {
    $data = [];
    $sql = "SELECT 
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
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssss", $start_date, $start_date, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $data[$row['date']] = [
            'revenue' => floatval($row['revenue']),
            'expenses' => floatval($row['expenses']),
            'profit' => floatval($row['profit'])
        ];
    }
    $stmt->close();
    return $data;
}

/**
 * Handle Revenue-related actions (Add, Update, Delete)
 */
function handleRevenueAction($conn, $postData, $user_id) {
    if (isset($postData['add_revenue'])) {
        $category_id = (int)$postData['category_id'];
        $amount = filter_var($postData['amount'], FILTER_VALIDATE_FLOAT);
        $description = trim($postData['description']);
        $payment_method = $postData['payment_method'];
        $reference_id = !empty($postData['reference_id']) ? (int)$postData['reference_id'] : NULL;
        $reference_name = !empty($postData['reference_name']) ? trim($postData['reference_name']) : NULL;
        $revenue_date = $postData['revenue_date'];
        $notes = !empty($postData['notes']) ? trim($postData['notes']) : NULL;

        if ($amount === false || $amount <= 0 || empty($description) || empty($category_id)) {
            return ["error" => "Please fill in all required fields with valid data."];
        }

        $sql = "INSERT INTO revenue_entries (category_id, amount, description, payment_method, reference_id, reference_name, revenue_date, recorded_by, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("idssisiss", $category_id, $amount, $description, $payment_method, $reference_id, $reference_name, $revenue_date, $user_id, $notes);
        
        $success = $stmt->execute();
        $stmt->close();
        return $success ? ["success" => "Revenue entry added successfully!"] : ["error" => "Error adding revenue entry."];
    }
    
    if (isset($postData['update_revenue'])) {
        $id = (int)$postData['entry_id'];
        $category_id = (int)$postData['category_id'];
        $amount = filter_var($postData['amount'], FILTER_VALIDATE_FLOAT);
        $description = trim($postData['description']);
        $payment_method = $postData['payment_method'];
        $reference_id = !empty($postData['reference_id']) ? (int)$postData['reference_id'] : NULL;
        $reference_name = !empty($postData['reference_name']) ? trim($postData['reference_name']) : NULL;
        $revenue_date = $postData['revenue_date'];
        $notes = !empty($postData['notes']) ? trim($postData['notes']) : NULL;

        if ($amount === false || $amount <= 0 || empty($description)) return ["error" => "Invalid data."];

        $sql = "UPDATE revenue_entries SET category_id=?, amount=?, description=?, payment_method=?, reference_id=?, reference_name=?, revenue_date=?, notes=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("idssisisi", $category_id, $amount, $description, $payment_method, $reference_id, $reference_name, $revenue_date, $notes, $id);
        $success = $stmt->execute();
        $stmt->close();
        return $success ? ["success" => "Revenue entry updated successfully!"] : ["error" => "Error updating revenue entry."];
    }
    
    if (isset($postData['delete_revenue'])) {
        $id = (int)$postData['entry_id'];
        $sql = "DELETE FROM revenue_entries WHERE id = ? AND recorded_by = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $id, $user_id);
        $success = $stmt->execute();
        $stmt->close();
        return $success ? ["success" => "Revenue entry deleted successfully!"] : ["error" => "Error deleting revenue entry."];
    }
    return null;
}

/**
 * Handle Expense-related actions (Add, Update, Delete)
 */
function handleExpenseAction($conn, $postData, $user_id) {
    if (isset($postData['add_expense'])) {
        $category_id = (int)$postData['expense_category_id'];
        $amount = filter_var($postData['expense_amount'], FILTER_VALIDATE_FLOAT);
        $description = trim($postData['expense_description']);
        $payment_method = $postData['expense_payment_method'];
        $expense_date = $postData['expense_date'];
        $notes = !empty($postData['expense_notes']) ? trim($postData['expense_notes']) : NULL;

        if ($amount === false || $amount <= 0 || empty($description) || empty($category_id)) {
            return ["error" => "Invalid data."];
        }

        $sql = "INSERT INTO expenses (category_id, amount, description, payment_method, expense_date, recorded_by, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("idsssis", $category_id, $amount, $description, $payment_method, $expense_date, $user_id, $notes);
        $success = $stmt->execute();
        $stmt->close();
        return $success ? ["success" => "Expense entry added successfully!"] : ["error" => "Error adding expense entry."];
    }
    
    if (isset($postData['update_expense'])) {
        $id = (int)$postData['expense_id'];
        $category_id = (int)$postData['expense_category_id'];
        $amount = filter_var($postData['expense_amount'], FILTER_VALIDATE_FLOAT);
        $description = trim($postData['expense_description']);
        $payment_method = $postData['expense_payment_method'];
        $expense_date = $postData['expense_date'];
        $notes = !empty($postData['expense_notes']) ? trim($postData['expense_notes']) : NULL;

        $sql = "UPDATE expenses SET category_id=?, amount=?, description=?, payment_method=?, expense_date=?, notes=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("idssssi", $category_id, $amount, $description, $payment_method, $expense_date, $notes, $id);
        $success = $stmt->execute();
        $stmt->close();
        return $success ? ["success" => "Expense entry updated successfully!"] : ["error" => "Error updating expense entry."];
    }
    
    if (isset($postData['delete_expense'])) {
        $id = (int)$postData['expense_id'];
        $sql = "DELETE FROM expenses WHERE id = ? AND recorded_by = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $id, $user_id);
        $success = $stmt->execute();
        $stmt->close();
        return $success ? ["success" => "Expense entry deleted successfully!"] : ["error" => "Error deleting expense entry."];
    }
    return null;
}

/**
 * Get all announcements
 * 
 * @param mysqli $conn Database connection
 * @return mysqli_result Result set of all announcements
 */
function getAnnouncements($conn) {
    $stmt = $conn->prepare("SELECT * FROM announcements ORDER BY created_at DESC");
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Handle Announcement-related actions (Add, Update, Delete)
 * 
 * @param mysqli $conn Database connection
 * @param array $postData The $_POST array containing form data
 * @param string $username The current user's username
 * @return array Associative array with 'success' or 'error' message
 */
function handleAnnouncementAction($conn, $postData, $username) {
    if (isset($postData['create_announcement'])) {
        $title = $postData['announcement_title'];
        $content = $postData['announcement_content'];
        $priority = $postData['priority'];
        $target_audience = $postData['target_audience'];
        $expiry_date = !empty($postData['expiry_date']) ? $postData['expiry_date'] : null;
        
        $sql = "INSERT INTO announcements (title, content, created_by, priority, target_audience, expiry_date) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssss", $title, $content, $username, $priority, $target_audience, $expiry_date);
        
        $success = $stmt->execute();
        $stmt->close();
        return $success ? ["success" => "Announcement created successfully!"] : ["error" => "Error creating announcement."];
    }
    
    if (isset($postData['delete_announcement'])) {
        $id = (int)$postData['announcement_id'];
        $sql = "DELETE FROM announcements WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $success = $stmt->execute();
        $stmt->close();
        return $success ? ["success" => "Announcement deleted successfully!"] : ["error" => "Error deleting announcement."];
    }
    
    if (isset($postData['update_announcement'])) {
        $id = (int)$postData['announcement_id'];
        $title = $postData['edit_title'];
        $content = $postData['edit_content'];
        $priority = $postData['edit_priority'];
        $target_audience = $postData['edit_target_audience'];
        $expiry_date = !empty($postData['edit_expiry_date']) ? $postData['edit_expiry_date'] : null;
        
        $sql = "UPDATE announcements SET 
                title = ?, 
                content = ?, 
                priority = ?, 
                target_audience = ?, 
                expiry_date = ?
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssi", $title, $content, $priority, $target_audience, $expiry_date, $id);
        
        $success = $stmt->execute();
        $stmt->close();
        return $success ? ["success" => "Announcement updated successfully!"] : ["error" => "Error updating announcement."];
    }
    return null;
}

/**
 * Get overall dashboard statistics
 * 
 * @param mysqli $conn Database connection
 * @return array Associative array of various dashboard metrics
 */
function getDashboardStats($conn) {
    $stats = [
        'total_users' => 0,
        'total_products' => 0,
        'total_feedback' => 0,
        'revenue_today' => 0,
        'total_members' => 0,
        'attendance_today' => 0,
        'expiring_members' => 0
    ];

    // Fetch simple counts using prepared statements for best practice
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE role != 'admin'");
    $stmt->execute();
    $stats['total_users'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM products");
    $stmt->execute();
    $stats['total_products'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM feedback");
    $stmt->execute();
    $stats['total_feedback'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();

    $stmt = $conn->prepare("SELECT SUM(total) as total_revenue FROM sales WHERE DATE(sold_at) = CURDATE()");
    $stmt->execute();
    $stats['revenue_today'] = $stmt->get_result()->fetch_assoc()['total_revenue'] ?? 0;
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM members");
    $stmt->execute();
    $stats['total_members'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(DISTINCT member_id) as total FROM attendance WHERE DATE(check_in) = CURDATE()");
    $stmt->execute();
    $stats['attendance_today'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();

    // Get expiring memberships (within 3 days)
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM members 
        WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY) 
        AND status = 'active'
    ");
    $stmt->execute();
    $stats['expiring_members'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    return $stats;
}

/**
 * Get all users ordered by creation date
 * 
 * @param mysqli $conn Database connection
 * @return mysqli_result Result set of all users
 */
function getAllUsers($conn) {
    $stmt = $conn->prepare("SELECT * FROM users ORDER BY created_at DESC");
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Get counts of users by role
 * 
 * @param mysqli $conn Database connection
 * @return array Associative array with counts for admin, trainer, and client roles
 */
function getUserRoleCounts($conn) {
    $counts = ['admin' => 0, 'trainer' => 0, 'client' => 0];
    
    $sql = "SELECT role, COUNT(*) as count FROM users GROUP BY role";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $counts[$row['role']] = (int)$row['count'];
        }
    }
    $stmt->close();
    
    return $counts;
}

/**
 * Delete a user by ID
 * 
 * @param mysqli $conn Database connection
 * @param int $user_id ID of the user to delete
 * @return bool True if deleted successfully, false otherwise
 */
function deleteUser($conn, $user_id) {
    if ($user_id <= 0) return false;
    
    $sql = "DELETE FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $success = $stmt->execute();
    $stmt->close();
    
    return $success;
}

/**
 * Get revenue entries with filtering
 */
function getRevenueEntries($conn, $start_date, $end_date, $category = '', $payment = '', $search = '') {
    $where = ["re.revenue_date BETWEEN ? AND ?"];
    $params = [$start_date, $end_date];
    $types = "ss";

    if ($category) {
        $where[] = "re.category_id = ?";
        $params[] = $category;
        $types .= "i";
    }
    if ($payment) {
        $where[] = "re.payment_method = ?";
        $params[] = $payment;
        $types .= "s";
    }
    if ($search) {
        $where[] = "(re.description LIKE ? OR re.reference_name LIKE ? OR rc.name LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "sss";
    }

    $where_sql = implode(" AND ", $where);
    $sql = "SELECT re.*, rc.name as category_name, rc.color as category_color, u.username as recorded_by_name
            FROM revenue_entries re
            JOIN revenue_categories rc ON re.category_id = rc.id
            JOIN users u ON re.recorded_by = u.id
            WHERE $where_sql
            ORDER BY re.revenue_date DESC, re.created_at DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Get expense entries with filtering
 */
function getExpenseEntries($conn, $start_date, $end_date, $category = '', $payment = '', $search = '') {
    $where = ["e.expense_date BETWEEN ? AND ?"];
    $params = [$start_date, $end_date];
    $types = "ss";

    if ($category) {
        $where[] = "e.category_id = ?";
        $params[] = $category;
        $types .= "i";
    }
    if ($payment) {
        $where[] = "e.payment_method = ?";
        $params[] = $payment;
        $types .= "s";
    }
    if ($search) {
        $where[] = "(e.description LIKE ? OR ec.name LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "ss";
    }

    $where_sql = implode(" AND ", $where);
    $sql = "SELECT e.*, ec.name as category_name, ec.color as category_color, u.username as recorded_by_name
            FROM expenses e
            JOIN expense_categories ec ON e.category_id = ec.id
            JOIN users u ON e.recorded_by = u.id
            WHERE $where_sql
            ORDER BY e.expense_date DESC, e.created_at DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Get financial summary (total revenue, expenses, transactions)
 */
function getFinancialSummary($conn, $start_date, $end_date) {
    $summary = [
        'total_revenue' => 0,
        'total_transactions' => 0,
        'total_expenses' => 0,
        'total_expense_transactions' => 0
    ];

    // Revenue stats (entries + membership payments)
    $rev_sql = "SELECT 
                    'revenue_entries' as source,
                    COUNT(id) as counts,
                    COALESCE(SUM(amount), 0) as total
                FROM revenue_entries 
                WHERE revenue_date BETWEEN ? AND ?
                UNION ALL
                SELECT 
                    'membership_payments' as source,
                    COUNT(id) as counts,
                    COALESCE(SUM(amount), 0) as total
                FROM membership_payments 
                WHERE payment_date BETWEEN ? AND ? AND status = 'completed'";
    
    $stmt = $conn->prepare($rev_sql);
    $stmt->bind_param("ssss", $start_date, $end_date, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $summary['total_revenue'] += $row['total'];
        $summary['total_transactions'] += $row['counts'];
    }
    $stmt->close();

    // Expense stats
    $exp_sql = "SELECT COUNT(id) as counts, COALESCE(SUM(amount), 0) as total
                FROM expenses WHERE expense_date BETWEEN ? AND ?";
    $stmt = $conn->prepare($exp_sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $summary['total_expenses'] = $row['total'] ?? 0;
    $summary['total_expense_transactions'] = $row['counts'] ?? 0;
    $stmt->close();

    return $summary;
}

/**
 * Get membership breakdown by plan type
 */
function getMembershipBreakdown($conn, $start_date, $end_date) {
    $sql = "SELECT plan_type, COUNT(id) as transaction_count, 
                   COALESCE(SUM(amount), 0) as total_amount,
                   COALESCE(AVG(amount), 0) as average_amount
            FROM membership_payments 
            WHERE payment_date BETWEEN ? AND ? AND status = 'completed'
            GROUP BY plan_type ORDER BY total_amount DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Helper function for time ago
 */
if (!function_exists('time_ago')) {
    function time_ago($datetime) {
        $time = strtotime($datetime);
        $now = time();
        $diff = $now - $time;
        
        if ($diff < 60) return 'just now';
        
        $units = [
            31536000 => 'year',
            2592000 => 'month',
            604800 => 'week',
            86400 => 'day',
            3600 => 'hour',
            60 => 'minute'
        ];
        
        foreach ($units as $unit => $text) {
            if ($diff < $unit) continue;
            $numberOfUnits = floor($diff / $unit);
            return $numberOfUnits . ' ' . $text . (($numberOfUnits > 1) ? 's' : '') . ' ago';
        }
        return date('M j, Y', $time);
    }
}
?>
