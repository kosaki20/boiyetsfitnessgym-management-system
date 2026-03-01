<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'trainer') {
    header("Location: index.php");
    exit();
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "boiyetsdb";

$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ADD THIS SECTION FOR REVENUE INTEGRATION
function setupRevenueTables($conn) {
    // Check if revenue_categories table exists
    $check_table = $conn->query("SHOW TABLES LIKE 'revenue_categories'");
    if ($check_table->num_rows == 0) {
        // Create revenue_categories table
        $conn->query("CREATE TABLE revenue_categories (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            color VARCHAR(7) DEFAULT '#3b82f6',
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Insert default categories
        $categories = [
            ['Product Sales', 'Revenue from product and supplement sales', '#10b981'],
            ['Membership Fees', 'Revenue from membership subscriptions', '#3b82f6'],
            ['Personal Training', 'Revenue from personal training sessions', '#f59e0b'],
            ['Gym Facility & Equipment', 'Revenue from equipment rentals and facility usage', '#ef4444'],
            ['Services', 'Other services like locker rentals, consultations', '#8b5cf6'],
            ['Other Income', 'Miscellaneous revenue sources', '#6b7280']
        ];
        
        foreach ($categories as $cat) {
            $stmt = $conn->prepare("INSERT INTO revenue_categories (name, description, color) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $cat[0], $cat[1], $cat[2]);
            $stmt->execute();
        }
        
        // Create revenue_entries table
        $conn->query("CREATE TABLE revenue_entries (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            category_id INT(11) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            description TEXT NOT NULL,
            payment_method ENUM('cash', 'gcash', 'bank_transfer', 'card', 'online') DEFAULT 'cash',
            reference_id INT(11) DEFAULT NULL,
            reference_name VARCHAR(255) DEFAULT NULL,
            revenue_date DATE NOT NULL,
            recorded_by INT(11) NOT NULL,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES revenue_categories(id),
            FOREIGN KEY (recorded_by) REFERENCES users(id)
        )");
    }
    
    // Check if revenue_entries has sale_id column
    $check_column = $conn->query("SHOW COLUMNS FROM revenue_entries LIKE 'sale_id'");
    if ($check_column->num_rows == 0) {
        $conn->query("ALTER TABLE revenue_entries ADD COLUMN sale_id INT NULL AFTER reference_id");
    }
}

// Setup revenue tables
setupRevenueTables($conn);

// SIMPLIFIED VERSION - Better approach
function createRevenueEntry($conn, $sale_id, $total_amount, $payment_method, $items, $recorded_by) {
    $category_id = 1; // Product Sales category
    
    // Create description from items
    $item_names = [];
    foreach ($items as $item) {
        $item_names[] = $item['name'] . " (x" . $item['quantity'] . ")";
    }
    $description = "Counter Sale #" . $sale_id . " - " . implode(", ", $item_names);
    
    $sql = "INSERT INTO revenue_entries (
                category_id, 
                amount, 
                description, 
                payment_method, 
                reference_id, 
                reference_name, 
                revenue_date, 
                recorded_by,
                sale_id,
                notes
            ) VALUES (?, ?, ?, ?, ?, 'Counter Sale', CURDATE(), ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $notes = "Auto-recorded from counter sale";
    
    $stmt->bind_param("idssiiis", 
        $category_id, 
        $total_amount, 
        $description, 
        $payment_method, 
        $sale_id,        // reference_id
        $recorded_by,    // recorded_by
        $sale_id,        // sale_id  
        $notes           // notes
    );
    
    if ($stmt->execute()) {
        return $conn->insert_id;
    } else {
        error_log("Error creating revenue entry: " . $stmt->error);
        return false;
    }
}

// ADD THIS SECTION FOR CHAT FUNCTIONALITY
require_once 'chat_functions.php';
$unread_count = getUnreadCount($_SESSION['user_id'], $conn);
$trainer_user_id = $_SESSION['user_id'];

// Function to get trainer notifications
function getTrainerNotifications($conn, $trainer_user_id) {
    $notifications = [];
    
    $sql = "SELECT * FROM notifications 
            WHERE (user_id = ? OR user_id IS NULL OR role = 'trainer') 
            AND (read_status = 0 OR read_status IS NULL)
            ORDER BY created_at DESC 
            LIMIT 10";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $trainer_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    
    return $notifications;
}

// Get notifications for the current trainer
$notifications = getTrainerNotifications($conn, $trainer_user_id);
$notification_count = count($notifications);

// Function to safely setup sales table
function setupSalesTable($conn) {
    // First check if table exists and has correct structure
    $table_check = $conn->query("SHOW TABLES LIKE 'sales'");
    if ($table_check->num_rows > 0) {
        // Check if the table has the required columns
        $columns_result = $conn->query("SHOW COLUMNS FROM sales");
        $required_columns = ['items', 'total_amount', 'payment_method', 'sale_date'];
        $existing_columns = [];
        
        while ($col = $columns_result->fetch_assoc()) {
            $existing_columns[] = $col['Field'];
        }
        
        // Check if all required columns exist
        $missing_columns = array_diff($required_columns, $existing_columns);
        
        if (empty($missing_columns)) {
            return true; // Table exists with correct structure
        } else {
            // Table exists but missing columns - we'll alter it
            foreach ($missing_columns as $column) {
                switch($column) {
                    case 'items':
                        $conn->query("ALTER TABLE sales ADD COLUMN items TEXT NOT NULL AFTER id");
                        break;
                    case 'total_amount':
                        $conn->query("ALTER TABLE sales ADD COLUMN total_amount DECIMAL(10,2) NOT NULL AFTER items");
                        break;
                    case 'payment_method':
                        $conn->query("ALTER TABLE sales ADD COLUMN payment_method VARCHAR(50) NOT NULL AFTER total_amount");
                        break;
                    case 'sale_date':
                        $conn->query("ALTER TABLE sales ADD COLUMN sale_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER payment_method");
                        break;
                }
            }
            return true;
        }
    } else {
        // Create new table
        $sql = "CREATE TABLE sales (
            id INT AUTO_INCREMENT PRIMARY KEY,
            items TEXT NOT NULL,
            total_amount DECIMAL(10,2) NOT NULL,
            payment_method VARCHAR(50) NOT NULL,
            sale_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        
        return $conn->query($sql);
    }
}

// Function to generate receipt HTML for modal
function generateReceiptHTML($sale) {
    if (!$sale) return null;
    
    $items = json_decode($sale['items'], true);
    
    $receipt = "
    <div class='receipt-modal-content' id='receipt-{$sale['id']}'>
        <div class='text-center mb-6'>
            <h3 class='text-yellow-400 font-bold text-xl'>BOIYETS FITNESS GYM</h3>
            <p class='text-gray-400'>Sales Receipt</p>
        </div>
        
        <div class='border-b border-gray-700 pb-4 mb-4'>
            <div class='grid grid-cols-2 gap-4 text-sm'>
                <div>
                    <span class='text-gray-400 block'>Receipt #:</span>
                    <span class='text-white font-semibold'>{$sale['id']}</span>
                </div>
                <div>
                    <span class='text-gray-400 block'>Date:</span>
                    <span class='text-white'>".date('M j, Y g:i A', strtotime($sale['sale_date']))."</span>
                </div>
                <div class='col-span-2'>
                    <span class='text-gray-400 block'>Payment Method:</span>
                    <span class='text-white capitalize font-semibold'>{$sale['payment_method']}</span>
                </div>
            </div>
        </div>
        
        <div class='mb-6'>
            <div class='flex justify-between font-semibold text-gray-400 text-sm border-b border-gray-700 pb-2 mb-3'>
                <span class='flex-1'>ITEM</span>
                <span class='w-20 text-center'>QTY</span>
                <span class='w-24 text-right'>PRICE</span>
                <span class='w-24 text-right'>TOTAL</span>
            </div>
            <div class='space-y-3 max-h-60 overflow-y-auto'>";
    
    foreach ($items as $item) {
        $subtotal = $item['price'] * $item['quantity'];
        $receipt .= "
                <div class='flex justify-between items-center py-2 border-b border-gray-800'>
                    <div class='flex-1'>
                        <span class='text-white block font-medium'>".htmlspecialchars($item['name'])."</span>
                    </div>
                    <span class='w-20 text-center text-gray-300'>{$item['quantity']}</span>
                    <span class='w-24 text-right text-gray-300'>₱".number_format($item['price'], 2)."</span>
                    <span class='w-24 text-right text-white font-semibold'>₱".number_format($subtotal, 2)."</span>
                </div>";
    }
    
    $receipt .= "
            </div>
        </div>
        
        <div class='border-t border-gray-700 pt-4'>
            <div class='flex justify-between text-xl font-bold'>
                <span class='text-yellow-400'>TOTAL:</span>
                <span class='text-yellow-400'>₱".number_format($sale['total_amount'], 2)."</span>
            </div>
        </div>
        
        <div class='text-center mt-8 text-gray-400 text-sm'>
            <p>Thank you for your purchase!</p>
            <p class='mt-1'>Bring this receipt for any inquiries</p>
        </div>
        
        <div class='flex space-x-3 mt-6'>
            <button onclick='downloadPDFReceipt({$sale['id']})' class='btn btn-primary flex-1'>
                <i data-lucide='download'></i> Download PDF
            </button>
            <button onclick='printReceipt({$sale['id']})' class='btn btn-primary flex-1'>
                <i data-lucide='printer'></i> Print
            </button>
            <button onclick='closeReceiptModal()' class='btn btn-danger flex-1'>
                <i data-lucide='x'></i> Close
            </button>
        </div>
    </div>";
    
    return $receipt;
}

// AJAX endpoint for fetching receipt data
if (isset($_GET['action']) && $_GET['action'] == 'get_receipt' && isset($_GET['sale_id'])) {
    $sale_id = intval($_GET['sale_id']);
    
    $sql = "SELECT * FROM sales WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $sale_id);
    $stmt->execute();
    $sale = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($sale) {
        echo generateReceiptHTML($sale);
    } else {
        echo '<div class="text-center p-8 text-gray-400">Receipt not found</div>';
    }
    exit();
}

// Setup the sales table
setupSalesTable($conn);

// Function to get products
function getProducts($conn) {
    $products = [];
    $sql = "SELECT * FROM products WHERE stock_quantity > 0 ORDER BY name";
    $result = $conn->query($sql);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
    }
    
    return $products;
}

// Function to get sales history
function getSalesHistory($conn) {
    $sales = [];
    
    $sql = "SELECT s.*, re.id as revenue_id 
            FROM sales s 
            LEFT JOIN revenue_entries re ON s.id = re.sale_id 
            ORDER BY s.sale_date DESC 
            LIMIT 20";
    $result = $conn->query($sql);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Safely decode items
            if (isset($row['items']) && is_string($row['items'])) {
                $items_data = json_decode($row['items'], true);
                $row['items'] = is_array($items_data) ? $items_data : [];
            } else {
                $row['items'] = [];
            }
            
            $sales[] = $row;
        }
    }
    
    return $sales;
}

// Process sale
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_sale'])) {
    $items = json_decode($_POST['sale_items'], true);
    $total_amount = $_POST['total_amount'];
    $payment_method = $_POST['payment_method'];
    $recorded_by = $_SESSION['user_id'];
    
    // Validate that items is an array
    if (!is_array($items) || empty($items)) {
        $error_message = "Invalid cart items or cart is empty";
    } else {
        // Validate items and stock
        $valid = true;
        foreach ($items as $item) {
            if (!isset($item['id']) || !isset($item['quantity']) || !isset($item['price'])) {
                $valid = false;
                $error_message = "Invalid item data";
                break;
            }
            
            $check_sql = "SELECT stock_quantity, name FROM products WHERE id = ?";
            $stmt = $conn->prepare($check_sql);
            $stmt->bind_param("i", $item['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $product = $result->fetch_assoc();
            $stmt->close();
            
            if (!$product) {
                $valid = false;
                $error_message = "Product not found";
                break;
            }
            
            if ($product['stock_quantity'] < $item['quantity']) {
                $valid = false;
                $error_message = "Insufficient stock for " . $product['name'];
                break;
            }
        }
        
        if ($valid) {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Insert sale
                $sql = "INSERT INTO sales (items, total_amount, payment_method) VALUES (?, ?, ?)";
                $stmt = $conn->prepare($sql);
                
                if ($stmt) {
                    $items_json = json_encode($items);
                    $stmt->bind_param("sds", $items_json, $total_amount, $payment_method);
                    
                    if ($stmt->execute()) {
                        $sale_id = $conn->insert_id;
                        
                        // Update product stock
                        foreach ($items as $item) {
                            $update_sql = "UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?";
                            $update_stmt = $conn->prepare($update_sql);
                            $update_stmt->bind_param("ii", $item['quantity'], $item['id']);
                            $update_stmt->execute();
                            $update_stmt->close();
                        }
                        
                        // Create revenue entry
                        $revenue_id = createRevenueEntry($conn, $sale_id, $total_amount, $payment_method, $items, $recorded_by);
                        
                        if ($revenue_id) {
                            $conn->commit();
                            $success_message = "Sale completed successfully! Sale ID: #" . $sale_id . " | Revenue Entry: #" . $revenue_id;
                        } else {
                            $conn->rollback();
                            $error_message = "Error creating revenue entry";
                        }
                        
                        // Store sale ID for modal display
                        $_SESSION['last_sale_id'] = $sale_id;
                        
                        // Clear the cart after successful sale
                        echo "<script>cart = []; updateCartDisplay();</script>";
                    } else {
                        $conn->rollback();
                        $error_message = "Error completing sale: " . $conn->error;
                    }
                    $stmt->close();
                } else {
                    $conn->rollback();
                    $error_message = "Error preparing statement: " . $conn->error;
                }
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Transaction failed: " . $e->getMessage();
            }
        }
    }
}

$products = getProducts($conn);
$salesHistory = getSalesHistory($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Counter Sales - BOIYETS FITNESS GYM</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <!-- html2pdf.js CDN -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
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
        
        /* FIXED DROPDOWN STYLES - Matching trainer_dashboard.php */
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
        
        .form-select:focus-visible {
            outline: none;
        }
        
        @-moz-document url-prefix() {
            .form-select {
                color: white !important;
                text-shadow: 0 0 0 white;
            }
            
            .form-select option {
                background: #1a1a1a !important;
                color: white !important;
            }
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
            background: rgba(26, 26, 26, 0.7);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }
        
        .product-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }
        
        .product-card:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateY(-2px);
        }
        
        .sale-item {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            border-left: 4px solid #fbbf24;
        }
        
        .quantity-btn {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            background: rgba(251, 191, 36, 0.2);
            color: #fbbf24;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
        }
        
        .quantity-btn:hover {
            background: rgba(251, 191, 36, 0.3);
        }
        
        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .badge-green {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
        }
        
        .badge-yellow {
            background: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
        }
        
        .badge-blue {
            background: rgba(59, 130, 246, 0.2);
            color: #3b82f6;
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
        
        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .modal-content {
            background: rgba(26, 26, 26, 0.95);
            border-radius: 16px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            border: 2px solid #fbbf24;
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }
        
        .modal-overlay.active .modal-content {
            transform: scale(1);
        }
        
        .receipt-modal-content {
            color: white;
        }
        
        /* QR Scanner Styles */
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

        @media print {
            body * {
                visibility: hidden;
            }
            .receipt-modal-content, .receipt-modal-content * {
                visibility: visible;
            }
            .receipt-modal-content {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                border: none;
                box-shadow: none;
                background: white;
                color: black;
            }
            .btn {
                display: none !important;
            }
        }
    </style>
</head>
<body class="min-h-screen">

  <!-- Receipt Modal -->
  <div id="receiptModal" class="modal-overlay">
    <div class="modal-content">
      <div id="receiptContent" class="receipt-modal-content">
        <!-- Receipt content will be loaded here via AJAX -->
      </div>
    </div>
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
        <a href="countersales.php" class="sidebar-item active">
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
          <div id="plansToggle" class="sidebar-item">
            <div class="flex items-center">
              <i data-lucide="clipboard-list"></i>
              <span class="text-sm font-medium">Training Plans</span>
            </div>
            <i id="plansChevron" data-lucide="chevron-right" class="chevron"></i>
            <span class="tooltip">Training Plans</span>
          </div>
          <div id="plansSubmenu" class="submenu space-y-1">
            <a href="trainerworkout.php"><i data-lucide="dumbbell"></i> Workout Plans</a>
            <a href="trainermealplan.php"><i data-lucide="utensils"></i> Meal Plans</a>
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
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-yellow-400 flex items-center gap-2">
                <i data-lucide="shopping-cart"></i>
                Counter Sales
            </h1>
            <div class="text-sm text-gray-400">
                Connected to Revenue System
            </div>
        </div>

        <?php if (isset($success_message)): ?>
            <div class="alert alert-success">
                <i data-lucide="check-circle" class="w-5 h-5 mr-2"></i>
                <?php echo $success_message; ?>
                <button onclick="viewReceipt(<?php echo $_SESSION['last_sale_id'] ?? 0; ?>)" class="btn btn-primary ml-4">
                    <i data-lucide="receipt"></i> View Receipt
                </button>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-error">
                <i data-lucide="alert-circle" class="w-5 h-5 mr-2"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="stat-card">
                <div class="text-3xl font-bold text-yellow-400 mb-2">
                    <?php 
                    $total_products = $conn->query("SELECT COUNT(*) as count FROM products WHERE stock_quantity > 0")->fetch_assoc()['count'];
                    echo $total_products;
                    ?>
                </div>
                <div class="text-gray-400">Available Products</div>
            </div>
            <div class="stat-card">
                <div class="text-3xl font-bold text-yellow-400 mb-2">
                    <?php 
                    $total_sales = $conn->query("SELECT COUNT(*) as count FROM sales")->fetch_assoc()['count'];
                    echo $total_sales;
                    ?>
                </div>
                <div class="text-gray-400">Total Sales</div>
            </div>
            <div class="stat-card">
                <div class="text-3xl font-bold text-yellow-400 mb-2">
                    <?php 
                    $revenue = $conn->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM sales")->fetch_assoc()['total'];
                    echo '₱' . number_format($revenue, 2);
                    ?>
                </div>
                <div class="text-gray-400">Total Revenue</div>
            </div>
            <div class="stat-card">
                <div class="text-3xl font-bold text-yellow-400 mb-2">
                    <?php 
                    $revenue_entries = $conn->query("SELECT COUNT(*) as count FROM revenue_entries WHERE category_id = 1")->fetch_assoc()['count'];
                    echo $revenue_entries;
                    ?>
                </div>
                <div class="text-gray-400">Revenue Entries</div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Products Section -->
            <div class="lg:col-span-2">
                <div class="card">
                    <h2 class="text-lg font-semibold text-yellow-400 mb-4 flex items-center gap-2">
                        <i data-lucide="package"></i>
                        Available Products
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php if (empty($products)): ?>
                            <div class="col-span-2 empty-state">
                                <i data-lucide="package-x" class="w-12 h-12 mx-auto"></i>
                                <p>No products available</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($products as $product): ?>
                                <div class="product-card">
                                    <div class="flex justify-between items-start mb-3">
                                        <div>
                                            <h3 class="font-semibold text-white text-lg mb-1"><?php echo htmlspecialchars($product['name']); ?></h3>
                                            <p class="text-yellow-400 font-bold text-xl">₱<?php echo number_format($product['price'], 2); ?></p>
                                        </div>
                                        <span class="badge badge-green">Stock: <?php echo $product['stock_quantity']; ?></span>
                                    </div>
                                    <button onclick="addToCart(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['name']); ?>', <?php echo $product['price']; ?>)" 
                                            class="btn btn-primary w-full">
                                        <i data-lucide="plus"></i> Add to Cart
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Shopping Cart -->
                <div class="card mt-6">
                    <h2 class="text-lg font-semibold text-yellow-400 mb-4 flex items-center gap-2">
                        <i data-lucide="shopping-cart"></i>
                        Shopping Cart
                    </h2>
                    
                    <div id="cartItems" class="mb-4">
                        <div class="empty-state">
                            <i data-lucide="shopping-cart" class="w-12 h-12 mx-auto"></i>
                            <p>No items in cart</p>
                        </div>
                    </div>
                    
                    <div class="border-t border-gray-700 pt-4">
                        <div class="flex justify-between items-center mb-4">
                            <span class="text-lg font-semibold">Total Amount:</span>
                            <span id="cartTotal" class="text-2xl font-bold text-yellow-400">₱0.00</span>
                        </div>
                        
                        <form method="POST" id="saleForm">
                            <input type="hidden" name="sale_items" id="saleItemsInput">
                            <input type="hidden" name="total_amount" id="totalAmountInput">
                            
                            <div class="mb-4">
                                <label class="form-label">Payment Method</label>
                                <select name="payment_method" class="form-select" required>
                                    <option value="cash">Cash</option>
                                    <option value="gcash">GCash</option>
                                </select>
                            </div>
                            
                            <button type="submit" name="complete_sale" class="btn btn-success w-full">
                                <i data-lucide="credit-card"></i> Complete Sale
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Sales History -->
            <div>
                <div class="card">
                    <h2 class="text-lg font-semibold text-yellow-400 mb-4 flex items-center gap-2">
                        <i data-lucide="history"></i>
                        Recent Sales
                    </h2>
                    
                    <div class="space-y-4">
                        <?php if (empty($salesHistory)): ?>
                            <div class="empty-state">
                                <i data-lucide="receipt" class="w-12 h-12 mx-auto"></i>
                                <p>No sales history yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($salesHistory as $sale): ?>
                                <div class="sale-item">
                                    <div class="flex justify-between items-start mb-2">
                                        <div>
                                            <h3 class="font-semibold text-white">Sale #<?php echo htmlspecialchars($sale['id']); ?></h3>
                                            <p class="text-yellow-400 font-bold">₱<?php echo number_format($sale['total_amount'], 2); ?></p>
                                        </div>
                                        <div class="flex flex-col items-end gap-1">
                                            <span class="badge badge-yellow">
                                                <?php 
                                                if (isset($sale['sale_date'])) {
                                                    echo date('M j, g:i A', strtotime($sale['sale_date']));
                                                } else {
                                                    echo 'Recent';
                                                }
                                                ?>
                                            </span>
                                            <?php if ($sale['revenue_id']): ?>
                                                <span class="badge badge-blue text-xs">Revenue #<?php echo $sale['revenue_id']; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <p class="text-sm text-gray-400">Items: <?php echo count($sale['items']); ?></p>
                                    <p class="text-sm text-gray-400 capitalize">Payment: <?php echo htmlspecialchars($sale['payment_method']); ?></p>
                                    <button onclick="viewReceipt(<?php echo $sale['id']; ?>)" class="btn btn-primary w-full mt-2">
                                        <i data-lucide="receipt"></i> View Receipt
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
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
                
                // Update attendance count if element exists
                const attendanceCount = document.getElementById('attendanceCount');
                if (attendanceCount) {
                    const currentCount = parseInt(attendanceCount.textContent || '0');
                    attendanceCount.textContent = currentCount + 1;
                }
                
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

    // Helper functions
    function setLoadingState(button, isLoading) {
        button.disabled = isLoading;
        button.style.opacity = isLoading ? 0.7 : 1;
    }

    function showToast(message, type = 'info', duration = 3000) {
        // Simple toast implementation
        const toast = document.createElement('div');
        toast.textContent = message;
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : type === 'warning' ? '#f59e0b' : '#3b82f6'};
            color: white;
            padding: 12px 16px;
            border-radius: 8px;
            z-index: 1000;
            animation: slideIn 0.3s ease;
        `;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.remove();
        }, duration);
    }

    // Add CSS animation for toast
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    `;
    document.head.appendChild(style);

    // Global function to open QR scanner
    function openQRScanner() {
        const qrScanner = document.getElementById('qrScanner');
        const qrInput = document.getElementById('qrInput');
        
        qrScanner.classList.remove('hidden');
        if (qrScannerActive) {
            setTimeout(() => qrInput.focus(), 100);
        }
    }

    // Sidebar functionality
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize icons
        lucide.createIcons();
        
        // Setup QR Scanner
        setupQRScanner();
        
        // Sidebar toggle functionality
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

        // Members submenu toggle
        const membersToggle = document.getElementById('membersToggle');
        const membersSubmenu = document.getElementById('membersSubmenu');
        const membersChevron = document.getElementById('membersChevron');
        
        membersToggle.addEventListener('click', () => {
            membersSubmenu.classList.toggle('open');
            membersChevron.classList.toggle('rotate');
        });

        // Plans submenu toggle
        const plansToggle = document.getElementById('plansToggle');
        const plansSubmenu = document.getElementById('plansSubmenu');
        const plansChevron = document.getElementById('plansChevron');
        
        plansToggle.addEventListener('click', () => {
            plansSubmenu.classList.toggle('open');
            plansChevron.classList.toggle('rotate');
        });
        
        // Hover to open sidebar (for collapsed state)
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
        setupDropdowns();

        // Auto-show receipt modal if there's a successful sale
        <?php if (isset($_SESSION['last_sale_id'])): ?>
        setTimeout(() => {
            viewReceipt(<?php echo $_SESSION['last_sale_id']; ?>);
            <?php unset($_SESSION['last_sale_id']); ?>
        }, 500);
        <?php endif; ?>
    });

    // Dropdown functionality
    function setupDropdowns() {
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
        
        // Mark all as read
        document.getElementById('markAllRead')?.addEventListener('click', function(e) {
            e.stopPropagation();
            // In a real app, you'd make an API call to mark notifications as read
            document.getElementById('notificationBadge').classList.add('hidden');
            // You could also update the notification list to remove the "new" indicators
        });
        
        // Close dropdowns when pressing Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAllDropdowns();
            }
        });
    }

    let cart = [];
    
    function addToCart(productId, productName, price) {
        const existingItem = cart.find(item => item.id === productId);
        
        if (existingItem) {
            existingItem.quantity += 1;
        } else {
            cart.push({
                id: productId,
                name: productName,
                price: price,
                quantity: 1
            });
        }
        
        updateCartDisplay();
    }
    
    function updateCartDisplay() {
        const cartItems = document.getElementById('cartItems');
        const cartTotal = document.getElementById('cartTotal');
        const saleItemsInput = document.getElementById('saleItemsInput');
        const totalAmountInput = document.getElementById('totalAmountInput');
        
        if (cart.length === 0) {
            cartItems.innerHTML = `
                <div class="empty-state">
                    <i data-lucide="shopping-cart" class="w-12 h-12 mx-auto"></i>
                    <p>No items in cart</p>
                </div>
            `;
            cartTotal.textContent = '₱0.00';
            saleItemsInput.value = '';
            totalAmountInput.value = '0';
            return;
        }
        
        let total = 0;
        let itemsHtml = '';
        
        cart.forEach((item, index) => {
            const itemTotal = item.price * item.quantity;
            total += itemTotal;
            
            itemsHtml += `
                <div class="sale-item">
                    <div class="flex-1">
                        <h4 class="font-semibold text-white">${item.name}</h4>
                        <p class="text-yellow-400">₱${item.price.toFixed(2)} × ${item.quantity}</p>
                        <p class="text-sm text-gray-400">Subtotal: ₱${itemTotal.toFixed(2)}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button class="quantity-btn" onclick="updateQuantity(${index}, -1)">
                            <i data-lucide="minus" class="w-4 h-4"></i>
                        </button>
                        <span class="mx-2 font-semibold">${item.quantity}</span>
                        <button class="quantity-btn" onclick="updateQuantity(${index}, 1)">
                            <i data-lucide="plus" class="w-4 h-4"></i>
                        </button>
                        <button class="quantity-btn bg-red-600 hover:bg-red-700 ml-2" onclick="removeFromCart(${index})">
                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>
            `;
        });
        
        cartItems.innerHTML = itemsHtml;
        cartTotal.textContent = `₱${total.toFixed(2)}`;
        saleItemsInput.value = JSON.stringify(cart);
        totalAmountInput.value = total.toFixed(2);
        
        lucide.createIcons();
    }
    
    function updateQuantity(index, change) {
        cart[index].quantity += change;
        
        if (cart[index].quantity <= 0) {
            cart.splice(index, 1);
        }
        
        updateCartDisplay();
    }
    
    function removeFromCart(index) {
        cart.splice(index, 1);
        updateCartDisplay();
    }
    
    // Modal Functions
    function showReceiptModal() {
        const modal = document.getElementById('receiptModal');
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function closeReceiptModal() {
        const modal = document.getElementById('receiptModal');
        modal.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
    
    // Close modal when clicking outside
    document.getElementById('receiptModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeReceiptModal();
        }
    });
    
    // AJAX function to fetch and display receipt
    function viewReceipt(saleId) {
        if (!saleId || saleId === 0) {
            alert('Invalid receipt ID');
            return;
        }
        
        showReceiptModal();
        
        const receiptContent = document.getElementById('receiptContent');
        receiptContent.innerHTML = `
            <div class="text-center p-8">
                <i data-lucide="loader" class="w-8 h-8 animate-spin mx-auto text-yellow-400"></i>
                <p class="mt-4 text-gray-400">Loading receipt...</p>
            </div>
        `;
        lucide.createIcons();
        
        // Fetch receipt via AJAX
        fetch(`countersales.php?action=get_receipt&sale_id=${saleId}`)
            .then(response => response.text())
            .then(html => {
                receiptContent.innerHTML = html;
                lucide.createIcons();
            })
            .catch(error => {
                console.error('Error fetching receipt:', error);
                receiptContent.innerHTML = `
                    <div class="text-center p-8 text-gray-400">
                        <i data-lucide="alert-circle" class="w-12 h-12 mx-auto mb-4"></i>
                        <p>Error loading receipt</p>
                    </div>
                `;
                lucide.createIcons();
            });
    }
    
    // PDF Receipt Functions
    function downloadPDFReceipt(receiptId) {
        const element = document.getElementById(`receipt-${receiptId}`);
        
        const options = {
            margin: 10,
            filename: `boiyets_gym_receipt_${receiptId}.pdf`,
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { 
                scale: 2,
                useCORS: true,
                logging: false
            },
            jsPDF: { 
                unit: 'mm', 
                format: 'a4', 
                orientation: 'portrait' 
            }
        };
        
        // Show loading state
        const button = event.target;
        const originalText = button.innerHTML;
        button.innerHTML = '<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Generating PDF...';
        button.disabled = true;
        
        html2pdf().set(options).from(element).save().then(() => {
            // Restore button state
            button.innerHTML = originalText;
            button.disabled = false;
            lucide.createIcons();
        });
    }
    
    function printReceipt(receiptId) {
        const element = document.getElementById(`receipt-${receiptId}`);
        
        const options = {
            margin: 10,
            filename: `boiyets_gym_receipt_${receiptId}.pdf`,
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { 
                scale: 2,
                useCORS: true,
                logging: false
            },
            jsPDF: { 
                unit: 'mm', 
                format: 'a4', 
                orientation: 'portrait' 
            }
        };
        
        // Show loading state
        const button = event.target;
        const originalText = button.innerHTML;
        button.innerHTML = '<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Preparing print...';
        button.disabled = true;
        
        html2pdf().set(options).from(element).toPdf().get('pdf').then(function(pdf) {
            window.open(pdf.output('bloburl'), '_blank');
            
            // Restore button state
            button.innerHTML = originalText;
            button.disabled = false;
            lucide.createIcons();
        });
    }
    
    // Prevent form resubmission on page refresh
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
  </script>
</body>
</html>
<?php $conn->close(); ?>



