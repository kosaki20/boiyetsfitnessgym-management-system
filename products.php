<?php
session_start();
if (!isset($_SESSION['username']) || $_SESSION['role'] != 'admin') {
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

// Get user data with profile picture
$user_id = $_SESSION['user_id'];
$sql = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Ensure profile picture is set in session
if (!isset($_SESSION['profile_picture']) && isset($user['profile_picture'])) {
    $_SESSION['profile_picture'] = $user['profile_picture'];
}

// ADD THIS SECTION FOR CHAT FUNCTIONALITY
require_once 'chat_functions.php';
$unread_count = getUnreadCount($_SESSION['user_id'], $conn);

// Include notification functions
require_once 'notification_functions.php';
$notification_count = getUnreadNotificationCount($conn, $user_id);
$notifications = getAdminNotifications($conn);

// Handle product actions
$action_message = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['add_product'])) {
        $name = mysqli_real_escape_string($conn, $_POST['name']);
        $price = floatval($_POST['price']);
        $stock_quantity = intval($_POST['stock_quantity']);
        
        if ($conn->query("INSERT INTO products (name, price, stock_quantity) VALUES ('$name', '$price', '$stock_quantity')")) {
            $action_message = "Product added successfully!";
            
            // Create notification for new product
            createNotification($conn, null, 'admin', 'New Product Added', 
                "Product '$name' has been added to inventory", 'system', 'medium');
        } else {
            $action_message = "Error adding product: " . $conn->error;
        }
    } elseif (isset($_POST['update_product'])) {
        $id = intval($_POST['product_id']);
        $name = mysqli_real_escape_string($conn, $_POST['name']);
        $price = floatval($_POST['price']);
        $stock_quantity = intval($_POST['stock_quantity']);
        
        // Get old product data for comparison
        $old_product = $conn->query("SELECT name, stock_quantity FROM products WHERE id=$id")->fetch_assoc();
        
        if ($conn->query("UPDATE products SET name='$name', price='$price', stock_quantity='$stock_quantity' WHERE id=$id")) {
            $action_message = "Product updated successfully!";
            
            // Check if stock became low or out of stock after update
            if ($old_product['stock_quantity'] > 0 && $stock_quantity == 0) {
                createNotification($conn, null, 'admin', 'Product Out of Stock', 
                    "Product '$name' is now out of stock", 'system', 'high');
            } elseif ($old_product['stock_quantity'] >= 10 && $stock_quantity < 10 && $stock_quantity > 0) {
                createNotification($conn, null, 'admin', 'Low Stock Alert', 
                    "Product '$name' is running low (only $stock_quantity left)", 'system', 'medium');
            }
        } else {
            $action_message = "Error updating product: " . $conn->error;
        }
    } elseif (isset($_POST['delete_product'])) {
        $id = intval($_POST['product_id']);
        $product_name = $conn->query("SELECT name FROM products WHERE id=$id")->fetch_assoc()['name'];
        
        if ($conn->query("DELETE FROM products WHERE id=$id")) {
            $action_message = "Product deleted successfully!";
            
            // Create notification for deleted product
            createNotification($conn, null, 'admin', 'Product Deleted', 
                "Product '$product_name' has been removed from inventory", 'system', 'medium');
        } else {
            $action_message = "Error deleting product: " . $conn->error;
        }
    } elseif (isset($_POST['sell_product'])) {
        $product_id = intval($_POST['product_id']);
        $quantity = intval($_POST['quantity']);
        $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method']);
        $member_id = !empty($_POST['member_id']) ? intval($_POST['member_id']) : NULL;
        
        // Get product details
        $product_query = $conn->query("SELECT * FROM products WHERE id = $product_id");
        if ($product_query->num_rows > 0) {
            $product = $product_query->fetch_assoc();
            
            // Check if enough stock is available
            if ($product['stock_quantity'] >= $quantity) {
                $total_amount = $product['price'] * $quantity;
                
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    // Update product stock
                    $new_stock = $product['stock_quantity'] - $quantity;
                    $conn->query("UPDATE products SET stock_quantity = $new_stock WHERE id = $product_id");
                    
                    // Create sale record
                    $items = json_encode([[
                        'id' => $product_id,
                        'name' => $product['name'],
                        'price' => $product['price'],
                        'quantity' => $quantity
                    ]]);
                    
                    $sold_by = $_SESSION['user_id'];
                    $conn->query("INSERT INTO sales (items, total_amount, payment_method, member_id, sold_by) 
                                 VALUES ('$items', $total_amount, '$payment_method', $member_id, $sold_by)");
                    
                    // Create revenue entry
                    $description = "Sale of $quantity x {$product['name']}";
                    $reference_name = $member_id ? "Member Purchase" : "Walk-in Purchase";
                    $reference_type = $member_id ? "member" : "walkin";
                    
                    $revenue_sql = "INSERT INTO revenue_entries (category_id, amount, description, payment_method, 
                                  reference_type, reference_id, reference_name, revenue_date, recorded_by) 
                                  VALUES (1, ?, ?, ?, ?, ?, ?, CURDATE(), ?)";
                    
                    $stmt = $conn->prepare($revenue_sql);
                    $stmt->bind_param("dsssisi", $total_amount, $description, $payment_method, 
                                    $reference_type, $member_id, $reference_name, $_SESSION['user_id']);
                    $stmt->execute();
                    
                    // Get the revenue entry ID for linking
                    $revenue_entry_id = $conn->insert_id;
                    
                    // Update sale with revenue entry reference
                    $sale_id = $conn->insert_id;
                    $conn->query("UPDATE sales SET revenue_entry_id = $revenue_entry_id WHERE id = $sale_id");
                    
                    $conn->commit();
                    
                    $action_message = "Product sold successfully! ₱" . number_format($total_amount, 2) . " revenue recorded.";
                    
                    // Create notification
                    createNotification($conn, null, 'admin', 'Product Sold', 
                        "Sold $quantity x {$product['name']} for ₱" . number_format($total_amount, 2), 'system', 'medium');
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $action_message = "Error processing sale: " . $e->getMessage();
                }
            } else {
                $action_message = "Insufficient stock! Only {$product['stock_quantity']} items available.";
            }
        } else {
            $action_message = "Product not found!";
        }
    }
}

// Fetch all products
$products_result = $conn->query("SELECT * FROM products ORDER BY created_at DESC");
$low_stock_products = $conn->query("SELECT * FROM products WHERE stock_quantity < 10 ORDER BY stock_quantity ASC");

// Fetch active members for dropdown
$members_result = $conn->query("SELECT id, full_name FROM members WHERE status = 'active' ORDER BY full_name");

// Calculate statistics
$total_products = $products_result->num_rows;
$total_value_result = $conn->query("SELECT SUM(price * stock_quantity) as total_value FROM products");
$total_value = $total_value_result->fetch_assoc()['total_value'] ?? 0;
$out_of_stock_count = $conn->query("SELECT COUNT(*) as count FROM products WHERE stock_quantity = 0")->fetch_assoc()['count'];
$low_stock_count = $conn->query("SELECT COUNT(*) as count FROM products WHERE stock_quantity < 10 AND stock_quantity > 0")->fetch_assoc()['count'];

// Calculate today's sales
$today_sales_result = $conn->query("
    SELECT COALESCE(SUM(total_amount), 0) as today_sales 
    FROM sales 
    WHERE DATE(sold_at) = CURDATE()
");
$today_sales = $today_sales_result->fetch_assoc()['today_sales'];

// Check for low stock notifications that need to be created
checkLowStockNotifications($conn);

$conn->close();

// Function to check and create low stock notifications
function checkLowStockNotifications($conn) {
    $low_stock_items = $conn->query("
        SELECT name, stock_quantity 
        FROM products 
        WHERE stock_quantity < 10 AND stock_quantity > 0
    ");
    
    while ($item = $low_stock_items->fetch_assoc()) {
        // Check if notification already exists for this item
        $existing_notif = $conn->query("
            SELECT id FROM notifications 
            WHERE title LIKE '%Low Stock%' 
            AND message LIKE '%{$item['name']}%' 
            AND read_status = 0
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
        ");
        
        if ($existing_notif->num_rows == 0) {
            createNotification($conn, null, 'admin', 'Low Stock Alert', 
                "Product '{$item['name']}' is running low (only {$item['stock_quantity']} left)", 'system', 'medium');
        }
    }
    
    // Check for out of stock items
    $out_of_stock_items = $conn->query("
        SELECT name FROM products WHERE stock_quantity = 0
    ");
    
    while ($item = $out_of_stock_items->fetch_assoc()) {
        $existing_notif = $conn->query("
            SELECT id FROM notifications 
            WHERE title LIKE '%Out of Stock%' 
            AND message LIKE '%{$item['name']}%' 
            AND read_status = 0
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
        ");
        
        if ($existing_notif->num_rows == 0) {
            createNotification($conn, null, 'admin', 'Product Out of Stock', 
                "Product '{$item['name']}' is out of stock", 'system', 'high');
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BOIYETS FITNESS GYM - Products & Inventory</title>
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
      padding: 0.5rem 0.75rem;
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
      margin-bottom: 0.25rem;
      font-size: 0.8rem;
      color: #d1d5db;
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
    
    .btn-danger {
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
    }
    
    .btn-danger:hover {
      background: rgba(239, 68, 68, 0.3);
    }
    
    .btn-success {
      background: rgba(16, 185, 129, 0.2);
      color: #10b981;
    }
    
    .btn-success:hover {
      background: rgba(16, 185, 129, 0.3);
    }
    
    .btn-sell {
      background: rgba(34, 197, 94, 0.2);
      color: #22c55e;
    }
    
    .btn-sell:hover {
      background: rgba(34, 197, 94, 0.3);
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
    
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 0.75rem;
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

    .modal { 
      display: none; 
      position: fixed; 
      z-index: 1000; 
      left: 0; 
      top: 0; 
      width: 100%; 
      height: 100%; 
      background-color: rgba(0,0,0,0.8); 
    }
    
    .modal-content { 
      background: rgba(26, 26, 26, 0.95);
      margin: 5% auto; 
      padding: 2rem; 
      border-radius: 12px; 
      width: 90%; 
      max-width: 500px; 
      border: 1px solid rgba(255, 255, 255, 0.1);
      backdrop-filter: blur(10px);
    }

    .stock-out { 
      color: #ef4444; 
      font-weight: 600; 
    }
    
    .stock-low { 
      color: #f59e0b; 
      font-weight: 600; 
    }
    
    .stock-good { 
      color: #10b981; 
      font-weight: 600; 
    }

    .status-badge {
      padding: 0.25rem 0.75rem;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 500;
    }
    
    .status-out {
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
    }
    
    .status-low {
      background: rgba(245, 158, 11, 0.2);
      color: #f59e0b;
    }
    
    .status-good {
      background: rgba(16, 185, 129, 0.2);
      color: #10b981;
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

    /* Toast notification */
    .toast {
      position: fixed;
      top: 20px;
      right: 20px;
      padding: 1rem 1.5rem;
      border-radius: 8px;
      color: white;
      z-index: 1000;
      max-width: 400px;
      box-shadow: 0 10px 15px rgba(0, 0, 0, 0.3);
      transform: translateX(400px);
      transition: transform 0.3s ease;
    }
    
    .toast.show {
      transform: translateX(0);
    }
    
    .toast.success {
      background: #10b981;
    }
    
    .toast.error {
      background: #ef4444;
    }
    
    .toast.warning {
      background: #f59e0b;
    }
    
    .toast.info {
      background: #3b82f6;
    }

    /* Mobile optimizations */
    @media (max-width: 768px) {
      .sidebar {
        position: fixed;
        height: 100vh;
        z-index: 1000;
        transform: translateX(-100%);
        transition: transform 0.3s ease;
      }
      
      .sidebar.mobile-open {
        transform: translateX(0);
      }
      
      .sidebar-collapsed {
        transform: translateX(-100%);
      }
      
      .sidebar-item:hover .tooltip {
        display: none !important;
      }
      
      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
      }
      
      .dropdown {
        position: fixed;
        left: 50%;
        transform: translateX(-50%);
        width: 90vw;
        max-width: 400px;
      }
    }
  </style>
</head>
<body class="min-h-screen">

  <!-- Toast Notification Container -->
  <div id="toastContainer"></div>

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
            <a href="logout.php" class="flex items-center gap-2 px=3 py=2 text-sm text-red-400 hover:text-red-300 hover:bg-red-400/10 rounded-lg transition-colors">
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

        <a href="revenue.php" class="sidebar-item">
          <div class="flex items-center">
            <i data-lucide="dollar-sign"></i>
            <span class="text-sm font-medium">Revenue Tracking</span>
          </div>
          <span class="tooltip">Revenue Tracking</span>
        </a>

        <a href="products.php" class="sidebar-item active">
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
          <i data-lucide="package"></i>
          Products & Inventory
        </h1>
        <div class="text-sm text-gray-400">
          Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?>
        </div>
      </div>

      <?php if (!empty($action_message)): ?>
        <div class="alert alert-<?php echo strpos($action_message, 'Error') !== false ? 'error' : 'success'; ?>">
          <i data-lucide="<?php echo strpos($action_message, 'Error') !== false ? 'alert-circle' : 'check-circle'; ?>" class="w-5 h-5 mr-2"></i>
          <?php echo htmlspecialchars($action_message); ?>
        </div>
      <?php endif; ?>

      <!-- Statistics -->
      <div class="stats-grid mb-8">
        <div class="card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="package"></i><span>Total Products</span></p>
              <p class="card-value"><?php echo $total_products; ?></p>
            </div>
            <div class="p-3 bg-blue-500/10 rounded-lg">
              <i data-lucide="package" class="w-6 h-6 text-blue-400"></i>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="dollar-sign"></i><span>Inventory Value</span></p>
              <p class="card-value">₱<?php echo number_format($total_value, 2); ?></p>
            </div>
            <div class="p-3 bg-green-500/10 rounded-lg">
              <i data-lucide="dollar-sign" class="w-6 h-6 text-green-400"></i>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="trending-up"></i><span>Today's Sales</span></p>
              <p class="card-value">₱<?php echo number_format($today_sales, 2); ?></p>
            </div>
            <div class="p-3 bg-yellow-500/10 rounded-lg">
              <i data-lucide="trending-up" class="w-6 h-6 text-yellow-400"></i>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="alert-triangle"></i><span>Low Stock</span></p>
              <p class="card-value"><?php echo $low_stock_count; ?></p>
            </div>
            <div class="p-3 bg-yellow-500/10 rounded-lg">
              <i data-lucide="alert-triangle" class="w-6 h-6 text-yellow-400"></i>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="x-circle"></i><span>Out of Stock</span></p>
              <p class="card-value"><?php echo $out_of_stock_count; ?></p>
            </div>
            <div class="p-3 bg-red-500/10 rounded-lg">
              <i data-lucide="x-circle" class="w-6 h-6 text-red-400"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Low Stock Alert -->
      <?php if ($low_stock_products->num_rows > 0): ?>
      <div class="card border-l-4 border-yellow-500">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <i data-lucide="alert-triangle" class="w-6 h-6 text-yellow-400"></i>
            <div>
              <h3 class="font-semibold text-yellow-400">Low Stock Alert</h3>
              <p class="text-sm text-gray-400"><?php echo $low_stock_products->num_rows; ?> product(s) are running low on stock</p>
            </div>
          </div>
          <button onclick="scrollToLowStock()" class="button-sm bg-yellow-600 hover:bg-yellow-700 text-white flex items-center gap-2">
            <i data-lucide="arrow-down" class="w-4 h-4"></i> View Items
          </button>
        </div>
      </div>
      <?php endif; ?>

      <!-- Products Table -->
      <div class="card">
        <div class="flex justify-between items-center mb-6">
          <h2 class="text-lg font-semibold text-yellow-400 flex items-center gap-2">
            <i data-lucide="list"></i>
            All Products
          </h2>
          <div class="flex gap-3">
            <div class="relative">
              <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
              <input type="text" id="searchProducts" placeholder="Search products..." class="form-input pl-10 pr-4 py-2" style="width: 250px;">
            </div>
            <button onclick="openModal()" class="button-sm bg-yellow-600 hover:bg-yellow-700 text-white flex items-center gap-2">
              <i data-lucide="plus" class="w-4 h-4"></i> Add Product
            </button>
          </div>
        </div>

        <div class="table-container">
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Product Name</th>
                <th>Price</th>
                <th>Stock Quantity</th>
                <th>Status</th>
                <th>Added Date</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($products_result->num_rows > 0): ?>
                <?php while($product = $products_result->fetch_assoc()): ?>
                <?php
                $stock_class = '';
                $status_class = '';
                $status_text = '';
                if ($product['stock_quantity'] == 0) {
                    $stock_class = 'stock-out';
                    $status_class = 'status-out';
                    $status_text = 'Out of Stock';
                } elseif ($product['stock_quantity'] < 10) {
                    $stock_class = 'stock-low';
                    $status_class = 'status-low';
                    $status_text = 'Low Stock';
                } else {
                    $stock_class = 'stock-good';
                    $status_class = 'status-good';
                    $status_text = 'In Stock';
                }
                ?>
                <tr>
                  <td class="font-medium"><?php echo $product['id']; ?></td>
                  <td class="font-medium"><?php echo htmlspecialchars($product['name']); ?></td>
                  <td>₱<?php echo number_format($product['price'], 2); ?></td>
                  <td class="<?php echo $stock_class; ?>"><?php echo $product['stock_quantity']; ?></td>
                  <td>
                    <span class="status-badge <?php echo $status_class; ?>">
                      <?php echo $status_text; ?>
                    </span>
                  </td>
                  <td><?php echo date('M j, Y', strtotime($product['created_at'])); ?></td>
                  <td>
                    <div class="flex gap-2">
                      <button onclick="editProduct(<?php echo $product['id']; ?>, '<?php echo addslashes($product['name']); ?>', <?php echo $product['price']; ?>, <?php echo $product['stock_quantity']; ?>)" 
                              class="button-sm btn-success">
                        <i data-lucide="edit" class="w-3 h-3"></i> Edit
                      </button>
                      <button onclick="openSellModal(<?php echo $product['id']; ?>, '<?php echo addslashes($product['name']); ?>', <?php echo $product['price']; ?>, <?php echo $product['stock_quantity']; ?>)" 
                              class="button-sm btn-sell">
                        <i data-lucide="shopping-cart" class="w-3 h-3"></i> Sell
                      </button>
                      <form method="POST" onsubmit="return confirm('Are you sure you want to delete this product? This action cannot be undone.')">
                        <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                        <button type="submit" name="delete_product" class="button-sm btn-danger flex items-center gap-1">
                          <i data-lucide="trash-2" class="w-3 h-3"></i> Delete
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr>
                  <td colspan="7" class="empty-state">
                    <i data-lucide="package" class="w-12 h-12 mx-auto"></i>
                    <p>No products found in inventory.</p>
                    <button onclick="openModal()" class="button-sm bg-yellow-600 hover:bg-yellow-700 text-white mt-3 flex items-center gap-1 mx-auto w-fit">
                      <i data-lucide="plus" class="w-3 h-3"></i> Add First Product
                    </button>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>

  <!-- Add/Edit Product Modal -->
  <div id="productModal" class="modal">
    <div class="modal-content">
      <div class="flex justify-between items-center mb-6">
        <h3 id="modalTitle" class="text-lg font-semibold text-yellow-400">Add New Product</h3>
        <button onclick="closeModal()" class="text-gray-400 hover:text-white text-2xl">&times;</button>
      </div>
      <form method="POST" id="productForm" class="space-y-4">
        <input type="hidden" id="productId" name="product_id">
        
        <div>
          <label class="form-label">Product Name</label>
          <input type="text" id="productName" name="name" class="form-input" required>
        </div>
        
        <div>
          <label class="form-label">Price (₱)</label>
          <input type="number" step="0.01" min="0" id="productPrice" name="price" class="form-input" required>
        </div>
        
        <div>
          <label class="form-label">Stock Quantity</label>
          <input type="number" min="0" id="productStock" name="stock_quantity" class="form-input" required>
        </div>
        
        <div class="flex gap-3 pt-4">
          <button type="button" onclick="closeModal()" class="flex-1 button-sm btn-primary">
            Cancel
          </button>
          <button type="submit" id="submitBtn" name="add_product" class="flex-1 button-sm bg-yellow-600 hover:bg-yellow-700 text-white">
            Add Product
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Sell Product Modal -->
  <div id="sellModal" class="modal">
    <div class="modal-content">
      <div class="flex justify-between items-center mb-6">
        <h3 class="text-lg font-semibold text-green-400">Sell Product</h3>
        <button onclick="closeSellModal()" class="text-gray-400 hover:text-white text-2xl">&times;</button>
      </div>
      <form method="POST" id="sellForm" class="space-y-4">
        <input type="hidden" id="sellProductId" name="product_id">
        
        <div class="bg-gray-800 p-4 rounded-lg">
          <h4 class="font-semibold text-yellow-400 mb-2" id="sellProductName">Product Name</h4>
          <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
              <span class="text-gray-400">Price:</span>
              <span id="sellProductPrice" class="text-white ml-2">₱0.00</span>
            </div>
            <div>
              <span class="text-gray-400">Available Stock:</span>
              <span id="sellProductStock" class="text-white ml-2">0</span>
            </div>
          </div>
        </div>
        
        <div>
          <label class="form-label">Quantity *</label>
          <input type="number" id="sellQuantity" name="quantity" min="1" value="1" class="form-input" required onchange="calculateTotal()">
        </div>
        
        <div>
          <label class="form-label">Payment Method *</label>
          <select name="payment_method" class="form-input" required>
            <option value="cash">Cash</option>
            <option value="gcash">GCash</option>
            <option value="bank_transfer">Bank Transfer</option>
            <option value="card">Card</option>
            <option value="online">Online</option>
          </select>
        </div>
        
        <div>
          <label class="form-label">Member (Optional)</label>
          <select name="member_id" class="form-input">
            <option value="">Walk-in Customer</option>
            <?php
            $members_result->data_seek(0);
            while($member = $members_result->fetch_assoc()): ?>
              <option value="<?php echo $member['id']; ?>"><?php echo htmlspecialchars($member['full_name']); ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        
        <div class="bg-green-500/10 p-4 rounded-lg border border-green-500/20">
          <div class="flex justify-between items-center">
            <span class="text-green-400 font-semibold">Total Amount:</span>
            <span id="totalAmount" class="text-green-400 font-bold text-lg">₱0.00</span>
          </div>
        </div>
        
        <div class="flex gap-3 pt-4">
          <button type="button" onclick="closeSellModal()" class="flex-1 button-sm btn-primary">
            Cancel
          </button>
          <button type="submit" name="sell_product" class="flex-1 button-sm bg-green-600 hover:bg-green-700 text-white">
            <i data-lucide="shopping-cart" class="w-4 h-4"></i> Complete Sale
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Toast notification system
    function showToast(message, type = 'success', duration = 5000) {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <div class="flex items-center gap-2">
                <i data-lucide="${getToastIcon(type)}" class="w-4 h-4"></i>
                <span>${message}</span>
            </div>
        `;
        
        container.appendChild(toast);
        
        // Animate in
        setTimeout(() => toast.classList.add('show'), 100);
        
        // Remove after duration
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, duration);
        
        lucide.createIcons();
    }

    function getToastIcon(type) {
        const icons = {
            success: 'check-circle',
            error: 'x-circle',
            warning: 'alert-triangle',
            info: 'info'
        };
        return icons[type] || 'info';
    }

    let currentProductPrice = 0;

    document.addEventListener('DOMContentLoaded', function() {
        lucide.createIcons();
        
        // Sidebar toggle with hover functionality
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

        // Search functionality
        document.getElementById('searchProducts').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });

        // Enhanced notification functionality
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
                markAllNotificationsAsRead();
            });
            
            // Close dropdowns when pressing Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeAllDropdowns();
                }
            });
        }

        // AJAX function to mark all notifications as read
        function markAllNotificationsAsRead() {
            fetch('notification_ajax.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=mark_all_read'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('All notifications marked as read', 'success');
                    // Hide notification badge
                    document.getElementById('notificationBadge').classList.add('hidden');
                    // Refresh the page to update notifications
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('Failed to mark notifications as read', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Network error occurred', 'error');
            });
        }

        // Initialize dropdowns
        setupDropdowns();

        // Mobile sidebar handling
        function setupMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            const toggleBtn = document.getElementById('toggleSidebar');
            
            // Check if mobile
            function isMobile() {
                return window.innerWidth <= 768;
            }
            
            // Toggle sidebar on mobile
            toggleBtn.addEventListener('click', function() {
                if (isMobile()) {
                    sidebar.classList.toggle('mobile-open');
                }
            });
            
            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(e) {
                if (isMobile() && 
                    !sidebar.contains(e.target) && 
                    !toggleBtn.contains(e.target) &&
                    sidebar.classList.contains('mobile-open')) {
                    sidebar.classList.remove('mobile-open');
                }
            });
        }

        setupMobileSidebar();
    });

    // Modal functions
    function openModal() {
      document.getElementById('productModal').style.display = 'block';
      resetModal();
    }

    function editProduct(id, name, price, stock) {
      document.getElementById('productModal').style.display = 'block';
      document.getElementById('modalTitle').textContent = 'Edit Product';
      document.getElementById('productId').value = id;
      document.getElementById('productName').value = name;
      document.getElementById('productPrice').value = price;
      document.getElementById('productStock').value = stock;
      document.getElementById('submitBtn').name = 'update_product';
      document.getElementById('submitBtn').textContent = 'Update Product';
    }

    function resetModal() {
      document.getElementById('modalTitle').textContent = 'Add New Product';
      document.getElementById('productForm').reset();
      document.getElementById('productId').value = '';
      document.getElementById('submitBtn').name = 'add_product';
      document.getElementById('submitBtn').textContent = 'Add Product';
    }

    function closeModal() {
      document.getElementById('productModal').style.display = 'none';
    }

    function openSellModal(id, name, price, stock) {
      document.getElementById('sellModal').style.display = 'block';
      document.getElementById('sellProductId').value = id;
      document.getElementById('sellProductName').textContent = name;
      document.getElementById('sellProductPrice').textContent = '₱' + price.toFixed(2);
      document.getElementById('sellProductStock').textContent = stock;
      
      // Set max quantity to available stock
      document.getElementById('sellQuantity').max = stock;
      
      currentProductPrice = price;
      calculateTotal();
    }

    function closeSellModal() {
      document.getElementById('sellModal').style.display = 'none';
    }

    function calculateTotal() {
      const quantity = parseInt(document.getElementById('sellQuantity').value) || 0;
      const total = quantity * currentProductPrice;
      document.getElementById('totalAmount').textContent = '₱' + total.toFixed(2);
    }

    function scrollToLowStock() {
      const lowStockRow = document.querySelector('.stock-low');
      if (lowStockRow) {
        lowStockRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
      const modal = document.getElementById('productModal');
      const sellModal = document.getElementById('sellModal');
      if (event.target === modal) {
        closeModal();
      }
      if (event.target === sellModal) {
        closeSellModal();
      }
    }
  </script>
</body>
</html>



