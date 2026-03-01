<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'trainer') {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';
require_once 'chat_functions.php';
require_once 'notification_functions.php';

$unread_count = getUnreadCount($_SESSION['user_id'], $conn);
$trainer_user_id = $_SESSION['user_id'];

// Get notifications for the current trainer
$notifications = getTrainerNotifications($conn, $trainer_user_id);
$notification_count = getUnreadNotificationCount($conn, $trainer_user_id);

// Include QR Code library
require_once 'phpqrcode/qrlib.php';

// Handle QR code actions
$message = '';
$message_type = '';

// Check for stored messages from redirect
if (isset($_SESSION['qr_management_message'])) {
    $message = $_SESSION['qr_management_message'];
    $message_type = $_SESSION['qr_management_message_type'];
    unset($_SESSION['qr_management_message']);
    unset($_SESSION['qr_management_message_type']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['generate_qr'])) {
        $member_id = $_POST['member_id'];
        
        // Get member details
        $stmt = $conn->prepare("SELECT m.*, u.username FROM members m JOIN users u ON m.user_id = u.id WHERE m.id = ?");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        
        if ($member) {
            // Create QR code directory if it doesn't exist
            $qr_dir = 'qrcodes';
            if (!is_dir($qr_dir)) {
                mkdir($qr_dir, 0755, true);
            }
            
            // Generate unique filename
            $filename = "client_" . $member['user_id'] . "_" . time() . ".png";
            $filepath = $qr_dir . '/' . $filename;
            
            // FIXED: QR code content - Use simple member ID only
            $qr_content = "CLIENT_" . $member['id']; // Standardized format
            
            // Generate QR code
            QRcode::png($qr_content, $filepath, QR_ECLEVEL_L, 10);
            
            // Update database with QR code path
            $update_stmt = $conn->prepare("UPDATE members SET qr_code_path = ? WHERE id = ?");
            $update_stmt->bind_param("si", $filepath, $member_id);
            
            if ($update_stmt->execute()) {
                $message = "QR code generated successfully for " . $member['full_name'];
                $message_type = 'success';
            } else {
                $message = "Error updating QR code path: " . $conn->error;
                $message_type = 'error';
            }
        } else {
            $message = "Member not found!";
            $message_type = 'error';
        }
    }
    
    if (isset($_POST['delete_qr'])) {
        $member_id = $_POST['member_id'];
        
        // Get current QR code path
        $stmt = $conn->prepare("SELECT qr_code_path FROM members WHERE id = ?");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result && $result['qr_code_path']) {
            // Delete the file
            if (file_exists($result['qr_code_path'])) {
                unlink($result['qr_code_path']);
            }
            
            // Update database
            $update_stmt = $conn->prepare("UPDATE members SET qr_code_path = NULL WHERE id = ?");
            if ($update_stmt->execute()) {
                $message = "QR code deleted successfully!";
                $message_type = 'success';
            } else {
                $message = "Error deleting QR code from database!";
                $message_type = 'error';
            }
        } else {
            $message = "No QR code found to delete!";
            $message_type = 'error';
        }
    }
    
    if (isset($_POST['regenerate_qr'])) {
        $member_id = $_POST['member_id'];
        
        // First delete existing QR code
        $stmt = $conn->prepare("SELECT qr_code_path FROM members WHERE id = ?");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result && $result['qr_code_path'] && file_exists($result['qr_code_path'])) {
            unlink($result['qr_code_path']);
        }
        
        // Then generate new one
        $stmt = $conn->prepare("SELECT m.*, u.username FROM members m JOIN users u ON m.user_id = u.id WHERE m.id = ?");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        
        if ($member) {
            $qr_dir = 'qrcodes';
            if (!is_dir($qr_dir)) {
                mkdir($qr_dir, 0755, true);
            }
            
            $filename = "client_" . $member['user_id'] . "_" . time() . ".png";
            $filepath = $qr_dir . '/' . $filename;
            
            // FIXED: QR code content - Use simple member ID only
            $qr_content = "CLIENT_" . $member['id']; // Standardized format
            
            QRcode::png($qr_content, $filepath, QR_ECLEVEL_L, 10);
            
            $update_stmt = $conn->prepare("UPDATE members SET qr_code_path = ? WHERE id = ?");
            $update_stmt->bind_param("si", $filepath, $member_id);
            
            if ($update_stmt->execute()) {
                $message = "QR code regenerated successfully for " . $member['full_name'];
                $message_type = 'success';
            } else {
                $message = "Error regenerating QR code!";
                $message_type = 'error';
            }
        }
    }
    
    // After processing any form action, redirect back to prevent form resubmission
    if (isset($_POST['generate_qr']) || isset($_POST['delete_qr']) || isset($_POST['regenerate_qr'])) {
        // Store message in session for display after redirect
        if ($message && $message_type) {
            $_SESSION['qr_management_message'] = $message;
            $_SESSION['qr_management_message_type'] = $message_type;
        }
        
        // Redirect to prevent form resubmission
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Get all clients with their QR code status
$clients_query = "
    SELECT m.id, m.full_name, m.membership_plan, m.expiry_date, m.status, m.qr_code_path, 
           u.username, u.email, m.created_at 
    FROM members m 
    JOIN users u ON m.user_id = u.id 
    WHERE m.member_type = 'client' 
    ORDER BY m.created_at DESC
";
$clients_result = $conn->query($clients_query);

$conn->close();
?>

<?php require_once 'includes/trainer_header.php'; ?>
<?php require_once 'includes/trainer_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 p-6 overflow-auto">
      <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-yellow-400 flex items-center gap-2">
          <i data-lucide="qrcode"></i>
          QR Code Management
        </h1>
        <div class="flex gap-2">
          <button onclick="openQRScanner()" class="btn btn-primary">
            <i data-lucide="scan"></i> Test QR Scanner
          </button>
        </div>
      </div>

      <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
          <i data-lucide="<?php echo $message_type === 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-5 h-5 mr-2"></i>
          <?php echo $message; ?>
        </div>
      <?php endif; ?>

      <!-- Statistics -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="stat-card">
          <div class="text-3xl font-bold text-yellow-400 mb-2">
            <?php echo $clients_result->num_rows; ?>
          </div>
          <div class="text-gray-400">Total Clients</div>
        </div>
        
        <div class="stat-card">
          <div class="text-3xl font-bold text-yellow-400 mb-2">
            <?php
            $qr_count = 0;
            if ($clients_result->num_rows > 0) {
              $clients_result->data_seek(0);
              while ($client = $clients_result->fetch_assoc()) {
                if ($client['qr_code_path'] && file_exists($client['qr_code_path'])) {
                  $qr_count++;
                }
              }
              $clients_result->data_seek(0); // Reset pointer
            }
            echo $qr_count;
            ?>
          </div>
          <div class="text-gray-400">QR Codes Generated</div>
        </div>
        
        <div class="stat-card">
          <div class="text-3xl font-bold text-yellow-400 mb-2">
            <?php echo $clients_result->num_rows - $qr_count; ?>
          </div>
          <div class="text-gray-400">Pending QR Codes</div>
        </div>
        
        <div class="stat-card">
          <div class="text-3xl font-bold text-yellow-400 mb-2">
            <?php
            $active_count = 0;
            if ($clients_result->num_rows > 0) {
              $clients_result->data_seek(0);
              while ($client = $clients_result->fetch_assoc()) {
                if ($client['status'] == 'active') {
                  $active_count++;
                }
              }
              $clients_result->data_seek(0); // Reset pointer
            }
            echo $active_count;
            ?>
          </div>
          <div class="text-gray-400">Active Members</div>
        </div>
      </div>

      <!-- Clients List -->
      <div class="card">
        <div class="flex justify-between items-center mb-6">
          <h2 class="text-lg font-semibold text-yellow-400 flex items-center gap-2">
            <i data-lucide="users"></i>
            Client QR Code Management
          </h2>
          
          <div class="flex gap-2">
            <button onclick="generateAllQRCodes()" class="btn btn-success">
              <i data-lucide="download"></i> Generate All Missing QR Codes
            </button>
          </div>
        </div>
        
        <div class="table-container">
          <table>
            <thead>
              <tr>
                <th>Client Information</th>
                <th>Membership</th>
                <th>QR Code Status</th>
                <th>QR Code Preview</th>
                <th class="text-center">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($clients_result->num_rows > 0): ?>
                <?php while ($client = $clients_result->fetch_assoc()): ?>
                  <tr>
                    <td>
                      <div class="font-medium text-white"><?php echo htmlspecialchars($client['full_name']); ?></div>
                      <div class="text-sm text-gray-400">@<?php echo htmlspecialchars($client['username']); ?></div>
                      <div class="text-sm text-gray-400"><?php echo htmlspecialchars($client['email']); ?></div>
                      <div class="text-xs text-yellow-400 mt-1">Member ID: <?php echo $client['id']; ?></div>
                    </td>
                    <td>
                      <span class="plan-badge"><?php echo ucfirst($client['membership_plan']); ?></span>
                      <div class="mt-2">
                        <span class="status-<?php echo $client['status']; ?>">
                          <?php echo ucfirst($client['status']); ?>
                        </span>
                      </div>
                      <div class="text-xs text-gray-400 mt-1">
                        Expires: <?php echo date('M j, Y', strtotime($client['expiry_date'])); ?>
                      </div>
                    </td>
                    <td>
                      <?php if ($client['qr_code_path'] && file_exists($client['qr_code_path'])): ?>
                        <span class="qr-status qr-available">
                          <i data-lucide="check-circle" class="w-3 h-3 inline mr-1"></i>
                          Generated
                        </span>
                        <div class="text-xs text-gray-400 mt-1">
                          Contains: Member ID <?php echo $client['id']; ?>
                        </div>
                      <?php else: ?>
                        <span class="qr-status qr-missing">
                          <i data-lucide="x-circle" class="w-3 h-3 inline mr-1"></i>
                          Not Generated
                        </span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($client['qr_code_path'] && file_exists($client['qr_code_path'])): ?>
                        <img src="<?php echo $client['qr_code_path']; ?>" 
                             alt="QR Code" 
                             class="qr-preview mx-auto">
                        <div class="text-center mt-2">
                          <a href="<?php echo $client['qr_code_path']; ?>" 
                             download 
                             class="text-blue-400 hover:text-blue-300 text-sm flex items-center justify-center gap-1">
                            <i data-lucide="download" class="w-3 h-3"></i>
                            Download
                          </a>
                        </div>
                      <?php else: ?>
                        <div class="text-gray-500 text-center">
                          <i data-lucide="qrcode" class="w-8 h-8 mx-auto mb-1"></i>
                          <div class="text-xs">No QR Code</div>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="flex flex-col gap-2">
                        <?php if (!$client['qr_code_path'] || !file_exists($client['qr_code_path'])): ?>
                          <form method="POST" class="inline">
                            <input type="hidden" name="member_id" value="<?php echo $client['id']; ?>">
                            <button type="submit" name="generate_qr" class="btn btn-primary w-full">
                              <i data-lucide="plus" class="w-4 h-4"></i>
                              Generate QR
                            </button>
                          </form>
                        <?php else: ?>
                          <form method="POST" class="inline">
                            <input type="hidden" name="member_id" value="<?php echo $client['id']; ?>">
                            <button type="submit" name="regenerate_qr" class="btn btn-success w-full">
                              <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                              Regenerate
                            </button>
                          </form>
                          
                          <form method="POST" class="inline" onsubmit="return confirmDelete(this);">
                            <input type="hidden" name="member_id" value="<?php echo $client['id']; ?>">
                            <button type="submit" name="delete_qr" class="btn btn-danger w-full">
                              <i data-lucide="trash-2" class="w-4 h-4"></i>
                              Delete
                            </button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr>
                  <td colspan="5" class="empty-state">
                    <i data-lucide="users" class="w-16 h-16 mx-auto mb-4"></i>
                    <p>No clients found.</p>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
          QR Code Management
        </h1>
        <div class="flex gap-2">
          <button onclick="openQRScanner()" class="btn btn-primary">
            <i data-lucide="scan"></i> Test QR Scanner
          </button>
        </div>
      </div>

      <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
          <i data-lucide="<?php echo $message_type === 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-5 h-5 mr-2"></i>
          <?php echo $message; ?>
        </div>
      <?php endif; ?>

      <!-- Statistics -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="stat-card">
          <div class="text-3xl font-bold text-yellow-400 mb-2">
            <?php echo $clients_result->num_rows; ?>
          </div>
          <div class="text-gray-400">Total Clients</div>
        </div>
        
        <div class="stat-card">
          <div class="text-3xl font-bold text-yellow-400 mb-2">
            <?php
            $qr_count = 0;
            if ($clients_result->num_rows > 0) {
              $clients_result->data_seek(0);
              while ($client = $clients_result->fetch_assoc()) {
                if ($client['qr_code_path'] && file_exists($client['qr_code_path'])) {
                  $qr_count++;
                }
              }
              $clients_result->data_seek(0); // Reset pointer
            }
            echo $qr_count;
            ?>
          </div>
          <div class="text-gray-400">QR Codes Generated</div>
        </div>
        
        <div class="stat-card">
          <div class="text-3xl font-bold text-yellow-400 mb-2">
            <?php echo $clients_result->num_rows - $qr_count; ?>
          </div>
          <div class="text-gray-400">Pending QR Codes</div>
        </div>
        
        <div class="stat-card">
          <div class="text-3xl font-bold text-yellow-400 mb-2">
            <?php
            $active_count = 0;
            if ($clients_result->num_rows > 0) {
              $clients_result->data_seek(0);
              while ($client = $clients_result->fetch_assoc()) {
                if ($client['status'] == 'active') {
                  $active_count++;
                }
              }
              $clients_result->data_seek(0); // Reset pointer
            }
            echo $active_count;
            ?>
          </div>
          <div class="text-gray-400">Active Members</div>
        </div>
      </div>

      <!-- Clients List -->
      <div class="card">
        <div class="flex justify-between items-center mb-6">
          <h2 class="text-lg font-semibold text-yellow-400 flex items-center gap-2">
            <i data-lucide="users"></i>
            Client QR Code Management
          </h2>
          
          <div class="flex gap-2">
            <button onclick="generateAllQRCodes()" class="btn btn-success">
              <i data-lucide="download"></i> Generate All Missing QR Codes
            </button>
          </div>
        </div>
        
        <div class="table-container">
          <table>
            <thead>
              <tr>
                <th>Client Information</th>
                <th>Membership</th>
                <th>QR Code Status</th>
                <th>QR Code Preview</th>
                <th class="text-center">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($clients_result->num_rows > 0): ?>
                <?php while ($client = $clients_result->fetch_assoc()): ?>
                  <tr>
                    <td>
                      <div class="font-medium text-white"><?php echo htmlspecialchars($client['full_name']); ?></div>
                      <div class="text-sm text-gray-400">@<?php echo htmlspecialchars($client['username']); ?></div>
                      <div class="text-sm text-gray-400"><?php echo htmlspecialchars($client['email']); ?></div>
                      <div class="text-xs text-yellow-400 mt-1">Member ID: <?php echo $client['id']; ?></div>
                    </td>
                    <td>
                      <span class="plan-badge"><?php echo ucfirst($client['membership_plan']); ?></span>
                      <div class="mt-2">
                        <span class="status-<?php echo $client['status']; ?>">
                          <?php echo ucfirst($client['status']); ?>
                        </span>
                      </div>
                      <div class="text-xs text-gray-400 mt-1">
                        Expires: <?php echo date('M j, Y', strtotime($client['expiry_date'])); ?>
                      </div>
                    </td>
                    <td>
                      <?php if ($client['qr_code_path'] && file_exists($client['qr_code_path'])): ?>
                        <span class="qr-status qr-available">
                          <i data-lucide="check-circle" class="w-3 h-3 inline mr-1"></i>
                          Generated
                        </span>
                        <div class="text-xs text-gray-400 mt-1">
                          Contains: Member ID <?php echo $client['id']; ?>
                        </div>
                      <?php else: ?>
                        <span class="qr-status qr-missing">
                          <i data-lucide="x-circle" class="w-3 h-3 inline mr-1"></i>
                          Not Generated
                        </span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($client['qr_code_path'] && file_exists($client['qr_code_path'])): ?>
                        <img src="<?php echo $client['qr_code_path']; ?>" 
                             alt="QR Code" 
                             class="qr-preview mx-auto">
                        <div class="text-center mt-2">
                          <a href="<?php echo $client['qr_code_path']; ?>" 
                             download 
                             class="text-blue-400 hover:text-blue-300 text-sm flex items-center justify-center gap-1">
                            <i data-lucide="download" class="w-3 h-3"></i>
                            Download
                          </a>
                        </div>
                      <?php else: ?>
                        <div class="text-gray-500 text-center">
                          <i data-lucide="qrcode" class="w-8 h-8 mx-auto mb-1"></i>
                          <div class="text-xs">No QR Code</div>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="flex flex-col gap-2">
                        <?php if (!$client['qr_code_path'] || !file_exists($client['qr_code_path'])): ?>
                          <form method="POST" class="inline">
                            <input type="hidden" name="member_id" value="<?php echo $client['id']; ?>">
                            <button type="submit" name="generate_qr" class="btn btn-primary w-full">
                              <i data-lucide="plus" class="w-4 h-4"></i>
                              Generate QR
                            </button>
                          </form>
                        <?php else: ?>
                          <form method="POST" class="inline">
                            <input type="hidden" name="member_id" value="<?php echo $client['id']; ?>">
                            <button type="submit" name="regenerate_qr" class="btn btn-success w-full">
                              <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                              Regenerate
                            </button>
                          </form>
                          
                          <form method="POST" class="inline" onsubmit="return confirmDelete(this);">
                            <input type="hidden" name="member_id" value="<?php echo $client['id']; ?>">
                            <button type="submit" name="delete_qr" class="btn btn-danger w-full">
                              <i data-lucide="trash-2" class="w-4 h-4"></i>
                              Delete
                            </button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr>
                  <td colspan="5" class="empty-state">
                    <i data-lucide="users" class="w-16 h-16 mx-auto mb-4"></i>
                    <p>No clients found.</p>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>

  <script>
    // Sidebar and Header functionalities are now handled by trainer_header.php and trainer_footer.php
    
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize icons
        lucide.createIcons();
        
        // Enhanced form handling
        setupFormHandling();
    });

    // Enhanced form handling with loading states
    function setupFormHandling() {
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i data-lucide="loader" class="animate-spin w-4 h-4"></i> Processing...';
                    lucide.createIcons();
                }
            });
        });
    }

    // Enhanced delete confirmation
    function confirmDelete(form) {
        const memberName = form.closest('tr').querySelector('.font-medium').textContent;
        return confirm(`Are you sure you want to delete the QR code for ${memberName}? This action cannot be undone.`);
    }

    function generateAllQRCodes() {
        if (confirm('This will generate QR codes for all clients who don\'t have one. Continue?')) {
            // Get all clients without QR codes
            const rows = document.querySelectorAll('tbody tr');
            let pendingGenerations = 0;
            
            rows.forEach(row => {
                const qrStatus = row.querySelector('.qr-status');
                if (qrStatus && qrStatus.classList.contains('qr-missing')) {
                    const generateForm = row.querySelector('form button[name="generate_qr"]');
                    if (generateForm) {
                        pendingGenerations++;
                        generateForm.click();
                    }
                }
            });
            
            if (pendingGenerations === 0) {
                alert('All clients already have QR codes!');
            } else {
                // Show loading message
                const messageDiv = document.createElement('div');
                messageDiv.className = 'alert alert-success';
                messageDiv.innerHTML = '<i data-lucide="loader" class="w-5 h-5 mr-2"></i>Generating ' + pendingGenerations + ' QR codes... Please wait.';
                document.querySelector('.flex-1').insertBefore(messageDiv, document.querySelector('.card'));
                
                // Reload page after a delay to show results
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            }
        }
    }
  </script>
<?php require_once 'includes/trainer_footer.php'; ?>
<?php if(isset($conn)) { $conn->close(); } ?>
