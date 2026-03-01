<?php
session_start();
if (!isset($_SESSION['username']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';

// Include Header and dynamically include Sidebar based on role
require_once 'includes/admin_header.php';
if ($_SESSION['role'] === 'admin') {
    require_once 'includes/admin_sidebar.php';
} else {
    require_once 'includes/trainer_sidebar.php';
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
        $name = $_POST['name'];
        $price = floatval($_POST['price']);
        $stock_quantity = intval($_POST['stock_quantity']);
        
        $stmt = $conn->prepare("INSERT INTO products (name, price, stock_quantity) VALUES (?, ?, ?)");
        $stmt->bind_param("sdi", $name, $price, $stock_quantity);
        
        if ($stmt->execute()) {
            $action_message = "Product added successfully!";
            createNotification($conn, null, 'admin', 'New Product Added', 
                "Product '$name' has been added to inventory", 'system', 'medium');
        } else {
            $action_message = "Error adding product: " . $conn->error;
        }
        $stmt->close();
    } elseif (isset($_POST['update_product'])) {
        $id = intval($_POST['product_id']);
        $name = $_POST['name'];
        $price = floatval($_POST['price']);
        $stock_quantity = intval($_POST['stock_quantity']);
        
        // Get old product data for comparison
        $old_stmt = $conn->prepare("SELECT name, stock_quantity FROM products WHERE id = ?");
        $old_stmt->bind_param("i", $id);
        $old_stmt->execute();
        $old_product = $old_stmt->get_result()->fetch_assoc();
        $old_stmt->close();
        
        $stmt = $conn->prepare("UPDATE products SET name = ?, price = ?, stock_quantity = ? WHERE id = ?");
        $stmt->bind_param("sdii", $name, $price, $stock_quantity, $id);
        
        if ($stmt->execute()) {
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
        $stmt->close();
    } elseif (isset($_POST['delete_product'])) {
        $id = intval($_POST['product_id']);
        
        $name_stmt = $conn->prepare("SELECT name FROM products WHERE id = ?");
        $name_stmt->bind_param("i", $id);
        $name_stmt->execute();
        $product_name = $name_stmt->get_result()->fetch_assoc()['name'];
        $name_stmt->close();
        
        $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $action_message = "Product deleted successfully!";
            createNotification($conn, null, 'admin', 'Product Deleted', 
                "Product '$product_name' has been removed from inventory", 'system', 'medium');
        } else {
            $action_message = "Error deleting product: " . $conn->error;
        }
        $stmt->close();
    } elseif (isset($_POST['sell_product'])) {
        $product_id = intval($_POST['product_id']);
        $quantity = intval($_POST['quantity']);
        $payment_method = $_POST['payment_method'];
        $member_id = !empty($_POST['member_id']) ? intval($_POST['member_id']) : NULL;
        
        // Get product details
        $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $product_result = $stmt->get_result();
        
        if ($product_result->num_rows > 0) {
            $product = $product_result->fetch_assoc();
            
            // Check if enough stock is available
            if ($product['stock_quantity'] >= $quantity) {
                $total_amount = $product['price'] * $quantity;
                
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    // Update product stock
                    $new_stock = $product['stock_quantity'] - $quantity;
                    $update_stmt = $conn->prepare("UPDATE products SET stock_quantity = ? WHERE id = ?");
                    $update_stmt->bind_param("ii", $new_stock, $product_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                    
                    // Create sale record
                    $items = json_encode([[
                        'id' => $product_id,
                        'name' => $product['name'],
                        'price' => $product['price'],
                        'quantity' => $quantity
                    ]]);
                    
                    $sold_by = $_SESSION['user_id'];
                    $sale_stmt = $conn->prepare("INSERT INTO sales (items, total_amount, payment_method, member_id, sold_by) VALUES (?, ?, ?, ?, ?)");
                    $sale_stmt->bind_param("sdiii", $items, $total_amount, $payment_method, $member_id, $sold_by);
                    $sale_stmt->execute();
                    $sale_id = $conn->insert_id;
                    $sale_stmt->close();
                    
                    // Create revenue entry
                    $description = "Sale of $quantity x {$product['name']}";
                    $reference_name = $member_id ? "Member Purchase" : "Walk-in Purchase";
                    $reference_type = $member_id ? "member" : "walkin";
                    
                    $revenue_sql = "INSERT INTO revenue_entries (category_id, amount, description, payment_method, 
                                  reference_type, reference_id, reference_name, revenue_date, recorded_by) 
                                  VALUES (1, ?, ?, ?, ?, ?, ?, CURDATE(), ?)";
                    
                    $rev_stmt = $conn->prepare($revenue_sql);
                    $rev_stmt->bind_param("dsssisi", $total_amount, $description, $payment_method, 
                                    $reference_type, $member_id, $reference_name, $_SESSION['user_id']);
                    $rev_stmt->execute();
                    $revenue_entry_id = $conn->insert_id;
                    $rev_stmt->close();
                    
                    // Update sale with revenue entry reference
                    $link_stmt = $conn->prepare("UPDATE sales SET revenue_entry_id = ? WHERE id = ?");
                    $link_stmt->bind_param("ii", $revenue_entry_id, $sale_id);
                    $link_stmt->execute();
                    $link_stmt->close();
                    
                    $conn->commit();
                    
                    $action_message = "Product sold successfully! ₱" . number_format($total_amount, 2) . " revenue recorded.";
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
        $stmt->close();
    }
}

// Fetch all products
$products_stmt = $conn->prepare("SELECT * FROM products ORDER BY created_at DESC");
$products_stmt->execute();
$products_result = $products_stmt->get_result();

$low_stock_stmt = $conn->prepare("SELECT * FROM products WHERE stock_quantity < 10 ORDER BY stock_quantity ASC");
$low_stock_stmt->execute();
$low_stock_products = $low_stock_stmt->get_result();

// Fetch active members for dropdown
$members_stmt = $conn->prepare("SELECT id, full_name FROM members WHERE status = 'active' ORDER BY full_name");
$members_stmt->execute();
$members_result = $members_stmt->get_result();

// Calculate statistics
$total_products = $products_result->num_rows;

$total_value_stmt = $conn->prepare("SELECT SUM(price * stock_quantity) as total_value FROM products");
$total_value_stmt->execute();
$total_value = $total_value_stmt->get_result()->fetch_assoc()['total_value'] ?? 0;
$total_value_stmt->close();

$out_stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE stock_quantity = 0");
$out_stmt->execute();
$out_of_stock_count = $out_stmt->get_result()->fetch_assoc()['count'];
$out_stmt->close();

$low_stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE stock_quantity < 10 AND stock_quantity > 0");
$low_stmt->execute();
$low_stock_count = $low_stmt->get_result()->fetch_assoc()['count'];
$low_stmt->close();

// Calculate today's sales
$today_stmt = $conn->prepare("
    SELECT COALESCE(SUM(total_amount), 0) as today_sales 
    FROM sales 
    WHERE DATE(sold_at) = CURDATE()
");
$today_stmt->execute();
$today_sales = $today_stmt->get_result()->fetch_assoc()['today_sales'];
$today_stmt->close();

// Check for low stock notifications
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
        $check_sql = "SELECT id FROM notifications 
                    WHERE title LIKE '%Low Stock%' 
                    AND message LIKE ? 
                    AND read_status = 0
                    AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)";
        $check_stmt = $conn->prepare($check_sql);
        $message_pattern = "%" . $item['name'] . "%";
        $check_stmt->bind_param("s", $message_pattern);
        $check_stmt->execute();
        $existing_notif = $check_stmt->get_result();
        
        if ($existing_notif->num_rows == 0) {
            createNotification($conn, null, 'admin', 'Low Stock Alert', 
                "Product '{$item['name']}' is running low (only {$item['stock_quantity']} left)", 'system', 'medium');
        }
        $check_stmt->close();
    }
    
    // Check for out of stock items
    $out_of_stock_items = $conn->query("SELECT name FROM products WHERE stock_quantity = 0");
    
    while ($item = $out_of_stock_items->fetch_assoc()) {
        $check_sql = "SELECT id FROM notifications 
                    WHERE title LIKE '%Out of Stock%' 
                    AND message LIKE ? 
                    AND read_status = 0
                    AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)";
        $check_stmt = $conn->prepare($check_sql);
        $message_pattern = "%" . $item['name'] . "%";
        $check_stmt->bind_param("s", $message_pattern);
        $check_stmt->execute();
        $existing_notif = $check_stmt->get_result();
        
        if ($existing_notif->num_rows == 0) {
            createNotification($conn, null, 'admin', 'Product Out of Stock', 
                "Product '{$item['name']}' is out of stock", 'system', 'high');
        }
        $check_stmt->close();
    }
    // This variable was not defined, assuming it was meant to be $out_of_stock_items
    // If $out_of_stock_items was a mysqli_result object, it doesn't have a close() method.
    // If it was a statement, it should be $out_of_stock_items->close();
    // For now, commenting it out to avoid error, as $out_of_stock_items is a result set.
    // $out_of_stock_items_stmt->close(); 
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
    /* Custom styles for products page */
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

    /* Main content adjustment */
    main {
      margin-left: 240px; /* Match sidebar width */
      transition: margin-left 0.3s ease;
    }
    
    .sidebar-collapsed + main {
      margin-left: 64px; /* Match collapsed sidebar width */
    }
  </style>
</head>
<body class="min-h-screen">

  <!-- Toast Notification Container -->
  <div id="toastContainer"></div>

<?php
require_once 'includes/admin_header.php';
if ($_SESSION['role'] === 'admin') {
    require_once 'includes/admin_sidebar.php';
} else {
    require_once 'includes/trainer_sidebar.php';
}
?>

  <div class="flex">
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



