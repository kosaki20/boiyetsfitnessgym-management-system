<?php
session_start();

require_once 'includes/db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$action = $_GET['action'] ?? '';

try {
    if ($action == 'get_logs') {
        if (!isset($_GET['equipment_id'])) {
            echo json_encode(['success' => false, 'message' => 'Equipment ID required']);
            exit();
        }
        
        $equipment_id = (int)$_GET['equipment_id'];
        
        $sql = "SELECT el.*, u.username as updated_by_name, 
                       DATE_FORMAT(el.date_updated, '%M %e, %Y %l:%i %p') as formatted_date
                FROM equipment_logs el 
                JOIN users u ON el.updated_by = u.id 
                WHERE el.equipment_id = ? 
                ORDER BY el.date_updated DESC 
                LIMIT 50";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'SQL prepare failed: ' . $conn->error]);
            exit();
        }
        
        $stmt->bind_param("i", $equipment_id);
        
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'message' => 'SQL execute failed: ' . $stmt->error]);
            exit();
        }
        
        $result = $stmt->get_result();
        
        $logs = [];
        while($log = $result->fetch_assoc()) {
            $logs[] = $log;
        }
        
        echo json_encode(['success' => true, 'logs' => $logs]);
        $stmt->close();
        
    } elseif ($action == 'get_maintenance_stats') {
        // Get maintenance statistics for dashboard
        $stats_stmt = $conn->prepare(
            "SELECT
                COUNT(*) as total_issues,
                SUM(CASE WHEN status = 'Needs Maintenance' THEN 1 ELSE 0 END) as needs_maintenance,
                SUM(CASE WHEN status = 'Under Repair' THEN 1 ELSE 0 END) as under_repair,
                SUM(CASE WHEN status = 'Broken' THEN 1 ELSE 0 END) as broken
            FROM equipment
            WHERE status IN ('Needs Maintenance', 'Under Repair', 'Broken')"
        );
        $stats_stmt->execute();
        $stats = $stats_stmt->get_result()->fetch_assoc();

        // Get recent maintenance items
        $recent_stmt = $conn->prepare(
            "SELECT name, status, last_updated FROM equipment
             WHERE status IN ('Needs Maintenance', 'Under Repair', 'Broken')
             ORDER BY last_updated DESC LIMIT 5"
        );
        $recent_stmt->execute();
        $recent_result = $recent_stmt->get_result();
        $recent_items = [];
        while ($item = $recent_result->fetch_assoc()) {
            $recent_items[] = $item;
        }

        echo json_encode([
            'success'      => true,
            'stats'        => $stats,
            'recent_items' => $recent_items
        ]);
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
    }
} catch (Exception $e) {
    error_log("Equipment AJAX Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

$conn->close();
?>



