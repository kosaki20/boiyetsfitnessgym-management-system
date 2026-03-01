<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'trainer') {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';
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

<?php require_once 'includes/trainer_header.php'; ?>
<?php require_once 'includes/trainer_sidebar.php'; ?>

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
<?php require_once 'includes/trainer_footer.php'; ?>
<?php if(isset($conn)) { $conn->close(); } ?>
