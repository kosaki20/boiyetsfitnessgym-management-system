<?php
/**
 * Equipment Module Quick Deployment Script
 * Run this once to set up everything automatically
 */

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    die("❌ Admin access required for deployment. Please log in as admin first.");
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

echo "<h2>🏗️ Equipment Module Deployment</h2>";
echo "<pre style='background: #1a1a1a; color: #e2e8f0; padding: 20px; border-radius: 8px;'>";

// Check if tables already exist
$tables = ['equipment', 'equipment_logs', 'facilities'];
$existing_tables = [];

foreach ($tables as $table) {
    $check_sql = "SHOW TABLES LIKE '$table'";
    $result = $conn->query($check_sql);
    if ($result->num_rows > 0) {
        $existing_tables[] = $table;
    }
}

if (!empty($existing_tables)) {
    echo "⚠️  Existing tables found: " . implode(', ', $existing_tables) . "\n";
    echo "📋 Skipping table creation...\n";
} else {
    // Create tables
    echo "📦 Creating database tables...\n";
    
    $migration_sql = "
    -- Create equipment table
    CREATE TABLE IF NOT EXISTS `equipment` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `name` varchar(100) NOT NULL,
      `category` varchar(100) NOT NULL,
      `location` varchar(100) NOT NULL,
      `status` enum('Good','Needs Maintenance','Under Repair','Broken') DEFAULT 'Good',
      `date_added` datetime DEFAULT CURRENT_TIMESTAMP,
      `last_updated` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      `created_by` int(11) DEFAULT NULL,
      `notes` text DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `fk_equipment_created_by` (`created_by`),
      KEY `idx_equipment_status` (`status`),
      KEY `idx_equipment_category` (`category`),
      CONSTRAINT `fk_equipment_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

    -- Create equipment_logs table
    CREATE TABLE IF NOT EXISTS `equipment_logs` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `equipment_id` int(11) NOT NULL,
      `old_status` varchar(50) NOT NULL,
      `new_status` varchar(50) NOT NULL,
      `updated_by` int(11) NOT NULL,
      `note` text DEFAULT NULL,
      `date_updated` datetime DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `fk_equipment_logs_equipment` (`equipment_id`),
      KEY `fk_equipment_logs_user` (`updated_by`),
      KEY `idx_equipment_logs_date` (`date_updated`),
      CONSTRAINT `fk_equipment_logs_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE,
      CONSTRAINT `fk_equipment_logs_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

    -- Create facilities table
    CREATE TABLE IF NOT EXISTS `facilities` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `name` varchar(100) NOT NULL,
      `condition` enum('Good','Needs Maintenance','Under Repair','Closed') DEFAULT 'Good',
      `notes` text DEFAULT NULL,
      `last_updated` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      `updated_by` int(11) DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `fk_facilities_updated_by` (`updated_by`),
      KEY `idx_facilities_condition` (`condition`),
      CONSTRAINT `fk_facilities_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";

    if ($conn->multi_query($migration_sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        echo "✅ Tables created successfully!\n";
    } else {
        echo "❌ Error creating tables: " . $conn->error . "\n";
        $conn->close();
        exit();
    }
}

// Insert sample facilities data
echo "📝 Inserting sample facilities...\n";
$facilities_data = [
    ['Cardio Area', 'Good', 'Treadmills, ellipticals, and stationary bikes'],
    ['Weight Room', 'Good', 'Free weights and weight machines'],
    ['Locker Rooms', 'Good', 'Male and female locker rooms with showers'],
    ['Group Exercise Studio', 'Good', 'Yoga, aerobics, and group classes'],
    ['Reception Area', 'Good', 'Front desk and waiting area']
];

$facility_stmt = $conn->prepare("INSERT IGNORE INTO facilities (name, condition, notes) VALUES (?, ?, ?)");
$facilities_inserted = 0;

foreach ($facilities_data as $facility) {
    $facility_stmt->bind_param("sss", $facility[0], $facility[1], $facility[2]);
    if ($facility_stmt->execute()) {
        $facilities_inserted++;
    }
}
$facility_stmt->close();
echo "✅ Facilities data inserted! ($facilities_inserted facilities)\n";

// Insert sample equipment data
echo "📝 Inserting sample equipment...\n";
$equipment_data = [
    ['Treadmill #1', 'Cardio Machine', 'Cardio Area', 'Good', 'Regular maintenance done last month'],
    ['Treadmill #2', 'Cardio Machine', 'Cardio Area', 'Good', ''],
    ['Stationary Bike #1', 'Cardio Machine', 'Cardio Area', 'Good', ''],
    ['Elliptical #1', 'Cardio Machine', 'Cardio Area', 'Good', ''],
    ['Bench Press #1', 'Weight Machine', 'Weight Room', 'Good', ''],
    ['Leg Press Machine', 'Weight Machine', 'Weight Room', 'Good', ''],
    ['Dumbbell 10kg #1', 'Free Weight', 'Weight Room', 'Good', ''],
    ['Dumbbell 10kg #2', 'Free Weight', 'Weight Room', 'Good', ''],
    ['Dumbbell 20kg #1', 'Free Weight', 'Weight Room', 'Good', ''],
    ['Barbell Set #1', 'Free Weight', 'Weight Room', 'Good', ''],
    ['Yoga Mat #1', 'Accessory', 'Group Exercise Studio', 'Good', ''],
    ['Yoga Mat #2', 'Accessory', 'Group Exercise Studio', 'Good', ''],
    ['Resistance Band Set', 'Accessory', 'Group Exercise Studio', 'Good', '']
];

$equipment_stmt = $conn->prepare("INSERT IGNORE INTO equipment (name, category, location, status, notes) VALUES (?, ?, ?, ?, ?)");
$equipment_inserted = 0;

foreach ($equipment_data as $equipment) {
    $equipment_stmt->bind_param("sssss", $equipment[0], $equipment[1], $equipment[2], $equipment[3], $equipment[4]);
    if ($equipment_stmt->execute()) {
        $equipment_inserted++;
    }
}
$equipment_stmt->close();
echo "✅ Equipment data inserted! ($equipment_inserted equipment items)\n";

// Verify deployment
echo "\n🔍 Verifying deployment...\n";

$verify_tables = [
    'equipment' => 'SELECT COUNT(*) as count FROM equipment',
    'equipment_logs' => 'SELECT COUNT(*) as count FROM equipment_logs', 
    'facilities' => 'SELECT COUNT(*) as count FROM facilities'
];

foreach ($verify_tables as $table => $sql) {
    $result = $conn->query($sql);
    $count = $result->fetch_assoc()['count'];
    echo "📊 $table: $count records\n";
}

// Test data integrity
echo "\n🧪 Testing data integrity...\n";
$test_queries = [
    'Equipment with categories' => "SELECT COUNT(DISTINCT category) as categories FROM equipment",
    'Facilities with conditions' => "SELECT COUNT(DISTINCT condition) as conditions FROM facilities",
    'Total maintenance issues' => "SELECT COUNT(*) as issues FROM equipment WHERE status != 'Good'"
];

foreach ($test_queries as $test_name => $sql) {
    $result = $conn->query($sql);
    $data = $result->fetch_assoc();
    echo "✅ $test_name: " . reset($data) . "\n";
}

echo "\n🎉 Deployment completed successfully!\n";
echo "========================================\n";
echo "🚀 Equipment Module is now ready!\n";
echo "➡️  Access: equipment_monitoring.php\n";
echo "➡️  Maintenance Reports: maintenance_report.php\n";
echo "➡️  User Roles:\n";
echo "    - Admin: Full access + add equipment\n";
echo "    - Trainer: Status updates + reports\n";
echo "    - Member: View-only access\n";
echo "========================================\n";

$conn->close();
?>



