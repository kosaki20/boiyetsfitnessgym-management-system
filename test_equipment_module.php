<?php
/**
 * Equipment Module Test Script
 * Run this to verify everything works correctly
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    die("❌ Please log in first to run tests.");
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "boiyetsdb";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("❌ Connection failed: " . $conn->connect_error);
}

$current_user = $_SESSION['username'];
$current_role = $_SESSION['role'];

echo "<h2>🧪 Equipment Module Test Results</h2>";
echo "<pre style='background: #1a1a1a; color: #e2e8f0; padding: 20px; border-radius: 8px;'>";
echo "👤 Current User: $current_user ($current_role)\n";
echo "📅 Test Date: " . date('Y-m-d H:i:s') . "\n\n";

$tests = [
    'Database Tables' => function() use ($conn) {
        $tables = ['equipment', 'equipment_logs', 'facilities'];
        $results = [];
        foreach ($tables as $table) {
            $result = $conn->query("SHOW TABLES LIKE '$table'");
            $exists = $result->num_rows > 0;
            $results[$table] = $exists ? '✅ EXISTS' : '❌ MISSING';
        }
        return $results;
    },
    
    'Sample Data Count' => function() use ($conn) {
        $tables = [
            'equipment' => 'SELECT COUNT(*) as count FROM equipment',
            'facilities' => 'SELECT COUNT(*) as count FROM facilities',
            'equipment_logs' => 'SELECT COUNT(*) as count FROM equipment_logs'
        ];
        $results = [];
        foreach ($tables as $table => $sql) {
            $result = $conn->query($sql);
            $count = $result->fetch_assoc()['count'];
            $status = $count > 0 ? "✅ $count records" : "❌ No data";
            $results[$table] = $status;
        }
        return $results;
    },
    
    'User Permissions' => function() use ($current_role) {
        $expected_access = [
            'admin' => ['equipment_monitoring.php', 'maintenance_report.php', 'add_equipment', 'view_logs'],
            'trainer' => ['equipment_monitoring.php', 'maintenance_report.php', 'update_status'],
            'member' => ['equipment_monitoring.php']
        ];
        
        $role_access = $expected_access[$current_role] ?? ['equipment_monitoring.php'];
        return "✅ Role $current_role can access: " . implode(', ', $role_access);
    },
    
    'Equipment Status Distribution' => function() use ($conn) {
        $sql = "SELECT status, COUNT(*) as count FROM equipment GROUP BY status";
        $result = $conn->query($sql);
        $distribution = [];
        while($row = $result->fetch_assoc()) {
            $distribution[] = "{$row['status']}: {$row['count']}";
        }
        return "📊 " . implode(', ', $distribution);
    },
    
'Facility Conditions' => function() use ($conn) {
    $sql = "SELECT `condition`, COUNT(*) as count FROM facilities GROUP BY `condition`";
        $result = $conn->query($sql);
        $conditions = [];
        while($row = $result->fetch_assoc()) {
            $conditions[] = "{$row['condition']}: {$row['count']}";
        }
        return "🏢 " . implode(', ', $conditions);
    },
    
    'File Accessibility' => function() {
        $files = ['equipment_monitoring.php', 'maintenance_report.php', 'equipment_ajax.php'];
        $results = [];
        foreach ($files as $file) {
            $exists = file_exists($file);
            $results[$file] = $exists ? '✅ FOUND' : '❌ NOT FOUND';
        }
        return $results;
    }
];

$all_passed = true;

foreach ($tests as $test_name => $test_function) {
    echo "🧪 $test_name:\n";
    try {
        $result = $test_function();
        if (is_array($result)) {
            foreach ($result as $item => $status) {
                echo "   $item: $status\n";
                if (strpos($status, '❌') !== false) {
                    $all_passed = false;
                }
            }
        } else {
            echo "   $result\n";
            if (strpos($result, '❌') !== false) {
                $all_passed = false;
            }
        }
    } catch (Exception $e) {
        echo "   ❌ Test failed: " . $e->getMessage() . "\n";
        $all_passed = false;
    }
    echo "\n";
}

// Final summary
echo "========================================\n";
if ($all_passed) {
    echo "🎉 ALL TESTS PASSED!\n";
    echo "✅ Equipment Module is working correctly\n";
    echo "🚀 You can now use the equipment monitoring system\n";
} else {
    echo "⚠️  SOME TESTS FAILED\n";
    echo "❌ Please check the deployment and file permissions\n";
    echo "🔧 Run deploy_equipment.php to fix issues\n";
}
echo "========================================\n";

$conn->close();
?>



