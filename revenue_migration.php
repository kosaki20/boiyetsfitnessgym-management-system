<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit();
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "boiyetsdb";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "<!DOCTYPE html>
<html>
<head>
    <title>Revenue System Migration</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .success { color: #22c55e; padding: 10px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 4px; margin: 10px 0; }
        .error { color: #ef4444; padding: 10px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 4px; margin: 10px 0; }
        .info { color: #3b82f6; padding: 10px; background: #eff6ff; border: 1px solid #dbeafe; border-radius: 4px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>Revenue System Database Migration</h1>";

// Create revenue_categories table
$sql_categories = "CREATE TABLE IF NOT EXISTS revenue_categories (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    color VARCHAR(7) DEFAULT '#3b82f6',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->query($sql_categories) === TRUE) {
    echo "<div class='success'>✓ Table revenue_categories created successfully</div>";
} else {
    echo "<div class='error'>✗ Error creating revenue_categories: " . $conn->error . "</div>";
}

// Create revenue_entries table
$sql_entries = "CREATE TABLE IF NOT EXISTS revenue_entries (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    category_id INT(11) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    description TEXT NOT NULL,
    payment_method ENUM('cash', 'gcash', 'bank_transfer', 'card', 'online') DEFAULT 'cash',
    reference_type ENUM('member', 'walkin', 'product', 'service', 'other') DEFAULT 'other',
    reference_id INT(11) DEFAULT NULL,
    reference_name VARCHAR(255) DEFAULT NULL,
    revenue_date DATE NOT NULL,
    recorded_by INT(11) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES revenue_categories(id),
    FOREIGN KEY (recorded_by) REFERENCES users(id)
)";

if ($conn->query($sql_entries) === TRUE) {
    echo "<div class='success'>✓ Table revenue_entries created successfully</div>";
} else {
    echo "<div class='error'>✗ Error creating revenue_entries: " . $conn->error . "</div>";
}

// Insert default categories
$default_categories = [
    ['Product Sales', 'Revenue from product and supplement sales', '#10b981'],
    ['Membership Fees', 'Revenue from membership subscriptions', '#3b82f6'],
    ['Personal Training', 'Revenue from personal training sessions', '#f59e0b'],
    ['Gym Facility & Equipment', 'Revenue from equipment rentals and facility usage', '#ef4444'],
    ['Services', 'Other services like locker rentals, consultations', '#8b5cf6'],
    ['Other Income', 'Miscellaneous revenue sources', '#6b7280']
];

$categories_inserted = 0;
foreach ($default_categories as $category) {
    $check_sql = "SELECT id FROM revenue_categories WHERE name = ?";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("s", $category[0]);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        $insert_sql = "INSERT INTO revenue_categories (name, description, color) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($insert_sql);
        $stmt->bind_param("sss", $category[0], $category[1], $category[2]);
        if ($stmt->execute()) {
            $categories_inserted++;
            echo "<div class='info'>✓ Inserted category: " . $category[0] . "</div>";
        }
    }
}

// Migrate existing sales data to revenue_entries
$migrate_sales_sql = "INSERT INTO revenue_entries (category_id, amount, description, payment_method, reference_type, reference_name, revenue_date, recorded_by, notes)
                      SELECT 
                          1 as category_id,  -- Product Sales
                          s.total_amount as amount,
                          CONCAT('Sale #', s.id) as description,
                          s.payment_method,
                          'product' as reference_type,
                          'Sales Transaction' as reference_name,
                          DATE(s.sold_at) as revenue_date,
                          1 as recorded_by,  -- Admin user
                          CONCAT('Migrated from sales table - Items: ', s.items) as notes
                      FROM sales s
                      WHERE NOT EXISTS (SELECT 1 FROM revenue_entries re WHERE re.description = CONCAT('Sale #', s.id))";

if ($conn->query($migrate_sales_sql) === TRUE) {
    $affected_rows = $conn->affected_rows;
    if ($affected_rows > 0) {
        echo "<div class='success'>✓ Migrated $affected_rows sales records to revenue system</div>";
    } else {
        echo "<div class='info'>✓ Sales data already migrated or no sales to migrate</div>";
    }
} else {
    echo "<div class='error'>✗ Error migrating sales data: " . $conn->error . "</div>";
}

// Migrate existing membership payments
$migrate_memberships_sql = "INSERT INTO revenue_entries (category_id, amount, description, payment_method, reference_type, reference_name, revenue_date, recorded_by, notes)
                           SELECT 
                               2 as category_id,  -- Membership Fees
                               mp.amount as amount,
                               CONCAT(mp.member_name, ' - ', mp.plan_type, ' membership') as description,
                               mp.payment_method,
                               'member' as reference_type,
                               mp.member_name as reference_name,
                               mp.payment_date as revenue_date,
                               1 as recorded_by,  -- Admin user
                               CONCAT('Migrated from membership payments - Transaction: ', mp.transaction_id) as notes
                           FROM membership_payments mp
                           WHERE NOT EXISTS (SELECT 1 FROM revenue_entries re WHERE re.description = CONCAT(mp.member_name, ' - ', mp.plan_type, ' membership'))";

if ($conn->query($migrate_memberships_sql) === TRUE) {
    $affected_rows = $conn->affected_rows;
    if ($affected_rows > 0) {
        echo "<div class='success'>✓ Migrated $affected_rows membership payments to revenue system</div>";
    } else {
        echo "<div class='info'>✓ Membership payments already migrated or none to migrate</div>";
    }
} else {
    echo "<div class='error'>✗ Error migrating membership payments: " . $conn->error . "</div>";
}

echo "<div class='success' style='margin-top: 20px;'><strong>Migration completed successfully!</strong></div>";
echo "<p><a href='revenue.php' style='color: #3b82f6; text-decoration: none; font-weight: bold;'>→ Go to Revenue Management System</a></p>";

echo "</div></body></html>";

$conn->close();
?>



