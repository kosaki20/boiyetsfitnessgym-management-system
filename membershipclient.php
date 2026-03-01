<?php
session_start();
if (!isset($_SESSION['username']) || $_SESSION['role'] != 'client') {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';
// Initialize variables
$client = [];
$membership = [];
$unread_count = 0;
$notification_count = 0;
$notifications = [];
$logged_in_user_id = $_SESSION['user_id'] ?? 0;

function getClientDetails($conn, $user_id) {
    $sql = "SELECT m.* FROM members m 
            INNER JOIN users u ON m.user_id = u.id 
            WHERE u.id = ? AND m.member_type = 'client'";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc() ?? [];
}

function getClientMembershipStatus($conn, $user_id) {
    $sql = "SELECT m.* FROM members m 
            INNER JOIN users u ON m.user_id = u.id 
            WHERE u.id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc() ?? [];
}

// Get client and membership data
$client = getClientDetails($conn, $logged_in_user_id);
$membership = getClientMembershipStatus($conn, $logged_in_user_id);

// Calculate days until expiry
if ($membership && isset($membership['expiry_date']) && $membership['expiry_date']) {
    $expiry = new DateTime($membership['expiry_date']);
    $today = new DateTime();
    $daysLeft = $today->diff($expiry)->days;
    if ($today > $expiry) {
        $daysLeft = -$daysLeft;
    }
    $membership['days_left'] = $daysLeft;
} else {
    $membership['days_left'] = 0;
    $membership['status'] = 'inactive';
    $membership['expiry_date'] = null;
    $membership['membership_plan'] = 'none';
}

// Get trainer assignment
$trainer_id = null;
$trainer_name = null;
if ($logged_in_user_id) {
    $trainer_sql = "SELECT u.id, u.full_name 
                   FROM trainer_client_assignments tca 
                   INNER JOIN users u ON tca.trainer_user_id = u.id 
                   WHERE tca.client_user_id = ? AND tca.status = 'active' 
                   LIMIT 1";
    $stmt = $conn->prepare($trainer_sql);
    if ($stmt) {
        $stmt->bind_param("i", $logged_in_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $trainer_id = $row['id'];
            $trainer_name = $row['full_name'];
        }
        $stmt->close();
    }
}

// Membership plans with pricing
$membership_plans = [
    'daily' => ['name' => 'Per Visit', 'price' => 40, 'duration' => '1 day'],
    'weekly' => ['name' => 'Weekly', 'price' => 160, 'duration' => '7 days'],
    'halfmonth' => ['name' => 'Half Month', 'price' => 250, 'duration' => '15 days'],
    'monthly' => ['name' => 'Monthly', 'price' => 400, 'duration' => '30 days']
];

// Process renewal request if form is submitted
$renewal_success = false;
$success_message = "";
$success_details = [];
$error_message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_renewal'])) {
    $plan_type = $_POST['plan_type'] ?? '';
    $payment_method = $_POST['payment_method'] ?? '';
    
    // Validate input
    if (empty($plan_type)) {
        $error_message = "Please select a membership plan.";
    } elseif (!array_key_exists($plan_type, $membership_plans)) {
        $error_message = "Invalid membership plan selected.";
    } elseif (empty($payment_method)) {
        $error_message = "Please select a payment method.";
    } elseif (!$trainer_id) {
        $error_message = "No assigned trainer found. Please contact administration.";
    } elseif (!$membership || !isset($membership['id'])) {
        $error_message = "Member record not found. Please contact administration.";
    } else {
        $plan = $membership_plans[$plan_type];
        
        // Insert renewal request
        $insert_sql = "INSERT INTO membership_renewal_requests 
                      (member_id, member_name, trainer_id, plan_type, amount, payment_method, status) 
                      VALUES (?, ?, ?, ?, ?, ?, 'pending')";
        
        $stmt = $conn->prepare($insert_sql);
        if ($stmt) {
            $member_name = $client['full_name'] ?? $_SESSION['username'];
            $stmt->bind_param("isisds", 
                $membership['id'], 
                $member_name,
                $trainer_id,
                $plan_type,
                $plan['price'],
                $payment_method
            );
            
            if ($stmt->execute()) {
                $renewal_request_id = $stmt->insert_id;
                
                // Create notification for trainer
                $notification_sql = "INSERT INTO notifications 
                                    (user_id, role, title, message, type, priority) 
                                    VALUES (?, 'trainer', 'Membership Renewal Request', 
                                    'Client {$member_name} requested {$plan['name']} renewal via {$payment_method}', 
                                    'membership', 'high')";
                $notif_stmt = $conn->prepare($notification_sql);
                if ($notif_stmt) {
                    $notif_stmt->bind_param("i", $trainer_id);
                    $notif_stmt->execute();
                    $notif_stmt->close();
                }
                
                $renewal_success = true;
                $success_message = "Renewal request submitted successfully! Your trainer {$trainer_name} will review and process your request.";
                $success_details = [
                    'plan_type' => $plan['name'],
                    'amount' => $plan['price'],
                    'payment_method' => $payment_method,
                    'trainer' => $trainer_name
                ];
                
                if ($payment_method === 'gcash') {
                    $success_message .= " Please send payment to GCash number 0917 123 4567 and wait for verification.";
                }
            } else {
                $error_message = "Error submitting renewal request: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error_message = "Database error: " . $conn->error;
        }
    }
}

// Get pending renewal requests
$pending_requests = [];
if ($membership && isset($membership['id'])) {
    $requests_sql = "SELECT * FROM membership_renewal_requests 
                    WHERE member_id = ? AND status IN ('pending', 'paid') 
                    ORDER BY created_at DESC";
    $stmt = $conn->prepare($requests_sql);
    if ($stmt) {
        $stmt->bind_param("i", $membership['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $pending_requests[] = $row;
        }
        $stmt->close();
    }
}

// Get unread messages count
$unread_sql = "SELECT COUNT(*) as unread_count FROM chat_messages 
               WHERE receiver_id = ? AND is_read = 0";
$unread_stmt = $conn->prepare($unread_sql);
if ($unread_stmt) {
    $unread_stmt->bind_param("i", $logged_in_user_id);
    $unread_stmt->execute();
    $unread_result = $unread_stmt->get_result();
    $unread_data = $unread_result->fetch_assoc();
    $unread_count = $unread_data['unread_count'] ?? 0;
    $unread_stmt->close();
}

// Get notifications count
$notification_sql = "SELECT COUNT(*) as notification_count FROM notifications 
                    WHERE (user_id = ? OR role = 'client') AND read_status = 0";
$notification_stmt = $conn->prepare($notification_sql);
if ($notification_stmt) {
    $notification_stmt->bind_param("i", $logged_in_user_id);
    $notification_stmt->execute();
    $notification_result = $notification_stmt->get_result();
    $notification_data = $notification_result->fetch_assoc();
    $notification_count = $notification_data['notification_count'] ?? 0;
    $notification_stmt->close();
}
?>
<?php 
$page_title = 'Membership';
require_once 'includes/client_header.php'; 
?>
<style>
    .membership-card {
        background: rgba(26, 26, 26, 0.8);
        border-radius: 12px;
        padding: 1.5rem;
        color: #fbbf24;
        border-left: 6px solid #fbbf24;
    }
    
    .plan-card {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 12px;
        padding: 2rem;
        border: 2px solid transparent;
        transition: all 0.3s ease;
        cursor: pointer;
    }
    
    .plan-card:hover {
        border-color: #fbbf24;
        transform: translateY(-5px);
    }
    
    .plan-card.selected {
        border-color: #fbbf24;
        background: rgba(251, 191, 36, 0.1);
    }
    
    .status-badge {
        padding: 0.5rem 1rem;
        border-radius: 20px;
        font-size: 0.875rem;
        font-weight: 500;
    }
    
    .status-active {
        background: rgba(16, 185, 129, 0.2);
        color: #10b981;
        border: 1px solid rgba(16, 185, 129, 0.3);
    }
    
    .status-expired {
        background: rgba(239, 68, 68, 0.2);
        color: #ef4444;
        border: 1px solid rgba(239, 68, 68, 0.3);
    }
    
    .warning-banner {
        background: rgba(245, 158, 11, 0.2);
        border: 1px solid rgba(245, 158, 11, 0.3);
        border-radius: 8px;
        padding: 1rem;
    }
    
    .danger-banner {
        background: rgba(239, 68, 68, 0.2);
        border: 1px solid rgba(239, 68, 68, 0.3);
        border-radius: 8px;
        padding: 1rem;
    }

    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.8);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        padding: 1rem;
    }
    
    .modal-content {
        background: #1a1a1a;
        border-radius: 12px;
        border: 1px solid rgba(251, 191, 36, 0.3);
        padding: 1.5rem;
        width: 100%;
        max-width: 400px;
        position: relative;
    }
    
    .plan-option {
        border: 2px solid #374151;
        border-radius: 8px;
        padding: 1rem;
        cursor: pointer;
        transition: all 0.2s ease;
        margin-bottom: 0.5rem;
    }
    
    .plan-option:hover {
        border-color: #fbbf24;
    }
    
    .plan-option.selected {
        border-color: #fbbf24;
        background: rgba(251, 191, 36, 0.1);
    }

    .success-modal {
        background: rgba(26, 26, 26, 0.95);
        border: 2px solid #10b981;
    }

    .gcash-info {
        background: rgba(0, 150, 136, 0.1);
        border: 1px solid rgba(0, 150, 136, 0.3);
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
    }
    
    .gcash-details {
        background: rgba(0, 150, 136, 0.05);
        border-radius: 8px;
        padding: 1.5rem;
        text-align: center;
    }
    
    .gcash-number {
        font-size: 1.5rem;
        font-weight: bold;
        color: #00796b;
        margin: 1rem 0;
    }
    
    .qr-code-container {
        max-width: 200px;
        margin: 0 auto;
        padding: 1rem;
        background: white;
        border-radius: 8px;
    }
    
    .upload-area {
        border: 2px dashed #d1d5db;
        border-radius: 8px;
        padding: 2rem;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .upload-area:hover {
        border-color: #fbbf24;
        background: rgba(251, 191, 36, 0.05);
    }
    
    .upload-area.dragover {
        border-color: #00796b;
        background: rgba(0, 150, 136, 0.1);
    }
    
    .request-status {
        padding: 0.5rem 1rem;
        border-radius: 20px;
        font-size: 0.875rem;
        font-weight: 500;
    }
    
    .status-pending {
        background: rgba(245, 158, 11, 0.2);
        color: #f59e0b;
    }
    
    .status-paid {
        background: rgba(59, 130, 246, 0.2);
        color: #3b82f6;
    }
    
    .status-approved {
        background: rgba(16, 185, 129, 0.2);
        color: #10b981;
    }
    
    .status-rejected {
        background: rgba(239, 68, 68, 0.2);
        color: #ef4444;
    }
</style>

  <!-- Main Content -->
  <main id="mainContent" class="main-content flex-1 p-4 space-y-6 overflow-auto">
    <!-- Error Message -->
    <?php if (isset($error_message) && !empty($error_message)): ?>
      <div class="bg-red-500/20 border border-red-500/30 rounded-lg p-4 mb-6">
        <div class="flex items-center gap-3">
          <i data-lucide="alert-circle" class="w-6 h-6 text-red-400"></i>
          <div>
            <h3 class="font-semibold text-red-400">Error!</h3>
            <p class="text-red-300 text-sm"><?php echo htmlspecialchars($error_message); ?></p>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Success Message -->
    <?php if (isset($renewal_success) && $renewal_success): ?>
      <div class="bg-green-500/20 border border-green-500/30 rounded-lg p-4 mb-6">
        <div class="flex items-center gap-3">
          <i data-lucide="check-circle" class="w-6 h-6 text-green-400"></i>
          <div>
            <h3 class="font-semibold text-green-400">Success!</h3>
            <p class="text-green-300 text-sm"><?php echo htmlspecialchars($success_message); ?></p>
            <?php if (!empty($success_details)): ?>
              <div class="mt-2 text-xs text-green-200">
                <p>Plan: <?php echo htmlspecialchars($success_details['plan_type']); ?></p>
                <p>Amount: ₱<?php echo number_format($success_details['amount']); ?></p>
                <p>Payment Method: <?php echo htmlspecialchars($success_details['payment_method']); ?></p>
                <p>Assigned Trainer: <?php echo htmlspecialchars($success_details['trainer']); ?></p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="flex justify-between items-center mb-6">
      <div class="flex items-center space-x-3">
        <a href="client_dashboard.php" class="text-gray-300 hover:text-yellow-400 transition-colors p-2 rounded-lg hover:bg-white/5">
          <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <h2 class="text-2xl font-bold text-yellow-400 flex items-center gap-3">
          <i data-lucide="id-card" class="w-8 h-8"></i>
          My Membership
        </h2>
      </div>
      <div class="text-right">
        <div class="text-sm text-gray-400">Member Since</div>
        <div class="font-semibold">
          <?php echo $membership && isset($membership['start_date']) ? date('F j, Y', strtotime($membership['start_date'])) : 'Not available'; ?>
        </div>
      </div>
    </div>

    <!-- Current Membership Status -->
    <div class="membership-card">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between">
        <div>
          <h3 class="text-2xl font-bold mb-4">Current Membership</h3>
          <div class="space-y-3">
            <div class="flex items-center gap-3">
              <span class="text-lg">Plan:</span>
              <span class="font-bold text-xl">
                <?php 
                if ($membership && isset($membership['membership_plan']) && isset($membership_plans[$membership['membership_plan']])) {
                  echo $membership_plans[$membership['membership_plan']]['name'];
                } else {
                  echo 'No active plan';
                }
                ?>
              </span>
            </div>
            <div class="flex items-center gap-3">
              <span class="text-lg">Status:</span>
              <span class="font-bold text-xl <?php echo $membership && $membership['status'] === 'active' ? 'text-green-300' : 'text-red-300'; ?>">
                <?php echo $membership ? ucfirst($membership['status']) : 'Inactive'; ?>
              </span>
            </div>
            <div class="flex items-center gap-3">
              <span class="text-lg">Expiry:</span>
              <span class="font-bold text-xl">
                <?php echo $membership && isset($membership['expiry_date']) ? date('F j, Y', strtotime($membership['expiry_date'])) : 'Not set'; ?>
              </span>
            </div>
            <?php if ($trainer_name): ?>
            <div class="flex items-center gap-3">
              <span class="text-lg">Trainer:</span>
              <span class="font-bold text-xl text-yellow-300"><?php echo htmlspecialchars($trainer_name); ?></span>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="mt-4 md:mt-0 text-center md:text-right">
          <div class="text-4xl font-bold mb-2 
            <?php 
            if ($membership && $membership['days_left'] > 7) echo 'text-green-300';
            elseif ($membership && $membership['days_left'] > 0) echo 'text-yellow-300';
            else echo 'text-red-300';
            ?>">
            <?php echo $membership && isset($membership['days_left']) ? ($membership['days_left'] > 0 ? $membership['days_left'] : 'Expired') : '0'; ?>
          </div>
          <div class="text-lg">Days <?php echo $membership && $membership['days_left'] > 0 ? 'Remaining' : 'Expired'; ?></div>
        </div>
      </div>
      
      <?php if ($membership && $membership['days_left'] <= 7 && $membership['days_left'] > 0): ?>
        <div class="warning-banner mt-4">
          <div class="flex items-center gap-2">
            <i data-lucide="alert-triangle" class="w-5 h-5 text-yellow-300"></i>
            <span class="font-semibold text-yellow-300">Your membership expires soon!</span>
          </div>
          <p class="text-yellow-200 mt-1">Request renewal now to continue uninterrupted access to all gym facilities.</p>
        </div>
      <?php elseif ($membership && $membership['days_left'] <= 0): ?>
        <div class="danger-banner mt-4">
          <div class="flex items-center gap-2">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-300"></i>
            <span class="font-semibold text-red-300">Your membership has expired!</span>
          </div>
          <p class="text-red-200 mt-1">Request renewal immediately to regain access to gym facilities.</p>
        </div>
      <?php endif; ?>
    </div>

    <!-- Pending Renewal Requests -->
    <?php if (!empty($pending_requests)): ?>
    <div class="card">
      <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
        <i data-lucide="clock" class="w-5 h-5"></i>
        Pending Renewal Requests
      </h3>
      
      <div class="space-y-4">
        <?php foreach ($pending_requests as $request): ?>
          <div class="bg-gray-800 rounded-lg p-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between">
              <div>
                <div class="flex items-center gap-3 mb-2">
                  <span class="font-semibold text-white">
                    <?php echo $membership_plans[$request['plan_type']]['name']; ?> Plan
                  </span>
                  <span class="request-status status-<?php echo $request['status']; ?>">
                    <?php echo ucfirst($request['status']); ?>
                  </span>
                </div>
                <div class="text-sm text-gray-400">
                  Amount: ₱<?php echo number_format($request['amount']); ?> • 
                  Payment: <?php echo ucfirst($request['payment_method']); ?> • 
                  Requested: <?php echo date('M j, Y g:i A', strtotime($request['created_at'])); ?>
                </div>
              </div>
              <div class="mt-2 md:mt-0">
                <?php if ($request['status'] === 'pending'): ?>
                  <span class="text-yellow-300 text-sm">Waiting for trainer approval</span>
                <?php elseif ($request['status'] === 'paid'): ?>
                  <span class="text-blue-300 text-sm">Payment verified - Processing</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Request Renewal Section -->
    <div class="card">
      <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
        <i data-lucide="credit-card" class="w-5 h-5"></i>
        Request Membership Renewal
      </h3>
      
      <?php if (!$trainer_id): ?>
        <div class="bg-red-500/20 border border-red-500/30 rounded-lg p-4">
          <div class="flex items-center gap-3">
            <i data-lucide="alert-triangle" class="w-6 h-6 text-red-400"></i>
            <div>
              <h3 class="font-semibold text-red-400">No Trainer Assigned</h3>
              <p class="text-red-300 text-sm">You don't have an assigned trainer. Please contact the gym administration to get assigned to a trainer.</p>
            </div>
          </div>
        </div>
      <?php else: ?>
      
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6" id="planSelection">
        <?php foreach ($membership_plans as $plan_key => $plan): ?>
          <div class="plan-card <?php echo $plan_key === 'monthly' ? 'popular' : ''; ?>" data-plan="<?php echo $plan_key; ?>">
            <?php if ($plan_key === 'monthly'): ?>
              <div class="bg-yellow-500 text-white text-sm font-bold px-3 py-1 rounded-full inline-block mb-4">
                Most Popular
              </div>
            <?php endif; ?>
            
            <h4 class="text-xl font-bold text-white mb-2"><?php echo htmlspecialchars($plan['name']); ?></h4>
            <div class="text-3xl font-bold text-yellow-400 mb-2">₱<?php echo number_format($plan['price']); ?></div>
            <div class="text-gray-300 mb-4"><?php echo htmlspecialchars($plan['duration']); ?></div>
            
            <button class="w-full bg-yellow-500 hover:bg-yellow-600 text-white py-2 px-4 rounded-lg transition-colors font-semibold flex items-center justify-center gap-2 min-h-[44px] select-plan-btn">
              <i data-lucide="shopping-cart" class="w-4 h-4"></i>
              Select Plan
            </button>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Renewal Request Form (Hidden by default) -->
      <div id="renewalForm" class="mt-6 p-6 bg-gray-800 rounded-lg hidden">
        <h4 class="text-lg font-bold text-yellow-400 mb-4">Submit Renewal Request</h4>
        <form id="requestForm" method="POST" enctype="multipart/form-data">
          <input type="hidden" name="request_renewal" value="1">
          <input type="hidden" name="plan_type" id="selectedPlan">
          
          <div class="mb-4">
            <label class="block text-gray-300 text-sm font-medium mb-2">Selected Plan</label>
            <div id="selectedPlanDisplay" class="p-3 bg-gray-700 rounded-lg text-white font-semibold"></div>
          </div>
          
          <div class="mb-4">
            <label class="block text-gray-300 text-sm font-medium mb-2">Payment Method</label>
            <select name="payment_method" id="paymentMethod" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white" required onchange="toggleGCashFields()">
              <option value="">Select Payment Method</option>
              <option value="cash">Cash (Pay at Counter)</option>
              <option value="gcash">GCash (Online Payment)</option>
            </select>
          </div>
          
          <!-- GCash Payment Fields (Hidden by default) -->
          <div id="gcashFields" class="hidden">
            <div class="gcash-info">
                <div class="flex items-center gap-2 mb-3">
                    <i data-lucide="smartphone" class="w-5 h-5 text-green-400"></i>
                    <h5 class="font-semibold text-green-400">GCash Payment Instructions</h5>
                </div>
                <p class="text-sm text-gray-300 mb-3">Send your payment to the following GCash number:</p>
                
                <div class="gcash-details">
                    <div class="text-sm text-gray-400">Send payment to:</div>
                    <div class="gcash-number">0917 123 4567</div>
                    <div class="text-sm text-gray-400 mb-3">(BOIYETS FITNESS GYM)</div>
                    <p class="text-sm text-gray-300">Your trainer will verify the payment once received.</p>
                </div>
            </div>
          </div>
          
          <div class="mb-4">
            <div class="bg-blue-500/20 border border-blue-500/30 rounded-lg p-3">
              <div class="flex items-center gap-2">
                <i data-lucide="info" class="w-4 h-4 text-blue-400"></i>
                <span class="text-blue-300 text-sm">Your renewal request will be sent to your trainer <?php echo htmlspecialchars($trainer_name); ?> for approval.</span>
              </div>
            </div>
          </div>
          
          <div class="flex space-x-3">
            <button type="button" id="cancelRequest" class="flex-1 bg-gray-600 text-white py-3 rounded-lg font-semibold hover:bg-gray-700 transition-colors">
              Cancel
            </button>
            <button type="submit" class="flex-1 bg-yellow-500 text-black py-3 rounded-lg font-semibold hover:bg-yellow-600 transition-colors">
              Submit Request
            </button>
          </div>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <!-- Membership History -->
    <div class="card">
      <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
        <i data-lucide="history" class="w-5 h-5"></i>
        Membership History
      </h3>
      
      <div class="space-y-4">
        <?php if ($membership && isset($membership['start_date'])): ?>
          <div class="flex flex-col md:flex-row md:items-center md:justify-between p-4 bg-gray-800 rounded-lg">
            <div>
              <div class="font-semibold text-white text-lg">
                <?php 
                if (isset($membership_plans[$membership['membership_plan']])) {
                  echo $membership_plans[$membership['membership_plan']]['name'] . ' Plan';
                } else {
                  echo 'Membership Plan';
                }
                ?>
              </div>
              <div class="text-gray-400">
                Started: <?php echo date('M j, Y', strtotime($membership['start_date'])); ?>
                <?php if (isset($membership['expiry_date'])): ?>
                  • Expires: <?php echo date('M j, Y', strtotime($membership['expiry_date'])); ?>
                <?php endif; ?>
              </div>
            </div>
            <div class="mt-2 md:mt-0">
              <span class="status-badge <?php echo $membership['status'] === 'active' ? 'status-active' : 'status-expired'; ?>">
                <?php echo ucfirst($membership['status']); ?>
              </span>
            </div>
          </div>
        <?php endif; ?>
        
        <!-- Additional history entries would go here -->
        <div class="text-center py-8 text-gray-500">
          <i data-lucide="receipt" class="w-16 h-16 mx-auto mb-4"></i>
          <p class="text-lg">Your membership history will appear here</p>
        </div>
      </div>
    </div>
  </main>
</div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Renewal functionality
      let selectedPlan = null;
      let selectedPlanName = null;
      let selectedPlanPrice = null;

      // Plan selection
      document.querySelectorAll('.select-plan-btn').forEach(button => {
        button.addEventListener('click', function() {
          const planCard = this.closest('.plan-card');
          const planType = planCard.dataset.plan;
          const planName = planCard.querySelector('h4').textContent;
          const planPrice = planCard.querySelector('.text-3xl').textContent;

          // Remove selected class from all cards
          document.querySelectorAll('.plan-card').forEach(card => {
            card.classList.remove('selected');
          });

          // Add selected class to clicked card
          planCard.classList.add('selected');

          // Store selected plan info
          selectedPlan = planType;
          selectedPlanName = planName;
          selectedPlanPrice = planPrice;

          // Show renewal form
          document.getElementById('selectedPlan').value = selectedPlan;
          document.getElementById('selectedPlanDisplay').textContent = `${selectedPlanName} - ${selectedPlanPrice}`;
          document.getElementById('renewalForm').classList.remove('hidden');

          // Reset GCash fields
          document.getElementById('paymentMethod').value = '';
          document.getElementById('gcashFields').classList.add('hidden');
          
          // Scroll to renewal form
          document.getElementById('renewalForm').scrollIntoView({ behavior: 'smooth' });
        });
      });

      // Cancel request
      document.getElementById('cancelRequest').addEventListener('click', function() {
        document.getElementById('renewalForm').classList.add('hidden');
        document.querySelectorAll('.plan-card').forEach(card => {
          card.classList.remove('selected');
        });
        selectedPlan = null;
      });

      // Form submission
      const requestForm = document.getElementById('requestForm');
      if (requestForm) {
          requestForm.addEventListener('submit', function(e) {
              const paymentMethod = document.querySelector('select[name="payment_method"]').value;
              if (!paymentMethod) {
                  e.preventDefault();
                  alert('Please select a payment method.');
                  return;
              }
              
              // Show loading state
              const submitBtn = this.querySelector('button[type="submit"]');
              submitBtn.innerHTML = '<i data-lucide="loader" class="w-5 h-5 animate-spin mx-auto"></i> Submitting...';
              submitBtn.disabled = true;
              lucide.createIcons();
          });
      }
    });

    function toggleGCashFields() {
      const paymentMethod = document.getElementById('paymentMethod').value;
      const gcashFields = document.getElementById('gcashFields');
      
      if (paymentMethod === 'gcash') {
        gcashFields.classList.remove('hidden');
      } else {
        gcashFields.classList.add('hidden');
      }
    }

    // AJAX function to process renewal directly (for instant renewals)
    function processInstantRenewal(memberId, planType, paymentMethod) {
      const formData = new FormData();
      formData.append('member_id', memberId);
      formData.append('plan_type', planType);
      formData.append('payment_method', paymentMethod);
      
      return fetch('renew_membership.php', {
          method: 'POST',
          body: formData
      })
      .then(response => response.json())
      .then(data => {
          if (data.success) {
              return data;
          } else {
              throw new Error(data.message);
          }
      });
    }
  </script>
<?php require_once 'includes/client_footer.php'; ?>



