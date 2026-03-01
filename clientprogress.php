<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'trainer') {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';
// Include notification functions
require_once 'notification_functions.php';

// ADD THIS SECTION FOR CHAT FUNCTIONALITY
require_once 'chat_functions.php';
$unread_count = getUnreadCount($_SESSION['user_id'], $conn);
$trainer_user_id = $_SESSION['user_id'];

// Update notification count using the new function
$notification_count = getUnreadNotificationCount($conn, $trainer_user_id);
$notifications = getTrainerNotifications($conn, $trainer_user_id);

// Function to get all clients
function getClients($conn) {
    $clients = [];
    $sql = "SELECT * FROM members WHERE member_type = 'client' ORDER BY full_name";
    $result = $conn->query($sql);
    
    while ($row = $result->fetch_assoc()) {
        $clients[] = $row;
    }
    
    return $clients;
}

// Function to get client progress history
function getClientProgressHistory($conn, $memberId) {
    $progressHistory = [];
    $sql = "SELECT * FROM client_progress WHERE member_id = ? ORDER BY progress_date ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $memberId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $progressHistory[] = $row;
    }
    
    return $progressHistory;
}

// Process progress tracking form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['track_progress'])) {
    $member_id = $_POST['member_id'];
    $weight = $_POST['weight'];
    $notes = $_POST['notes'];
    
    // Get client name for notification
    $client_sql = "SELECT full_name FROM members WHERE id = ?";
    $client_stmt = $conn->prepare($client_sql);
    $client_stmt->bind_param("i", $member_id);
    $client_stmt->execute();
    $client_result = $client_stmt->get_result();
    $client = $client_result->fetch_assoc();
    $client_name = $client['full_name'] ?? 'Unknown Client';
    
    $sql = "INSERT INTO client_progress (member_id, weight, notes, progress_date) 
            VALUES (?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ids", $member_id, $weight, $notes);
    
    if ($stmt->execute()) {
        $success_message = "Progress tracked successfully!";
        
        // Create notification for the trainer
        createNotification(
            $conn,
            $trainer_user_id,
            'trainer',
            'Progress Recorded',
            'Progress has been recorded for client ' . $client_name,
            'system',
            'medium'
        );
        
        // Refresh notifications
        $notification_count = getUnreadNotificationCount($conn, $trainer_user_id);
        $notifications = getTrainerNotifications($conn, $trainer_user_id);
    } else {
        $error_message = "Error tracking progress: " . $conn->error;
    }
}

$clients = getClients($conn);
?>

<?php require_once 'includes/trainer_header.php'; ?>
<?php require_once 'includes/trainer_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 p-6 overflow-auto">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-yellow-400 flex items-center gap-2">
                <i data-lucide="activity"></i>
                Client Progress Tracking
            </h1>
        </div>

        <?php if (isset($success_message)): ?>
            <div class="alert alert-success">
                <i data-lucide="check-circle" class="w-5 h-5 mr-2"></i>
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-error">
                <i data-lucide="alert-circle" class="w-5 h-5 mr-2"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="stat-card">
                <div class="text-3xl font-bold text-yellow-400 mb-2"><?php echo count($clients); ?></div>
                <div class="text-gray-400">Total Clients</div>
            </div>
            
            <div class="stat-card">
                <div class="text-3xl font-bold text-yellow-400 mb-2">
                    <?php
                    $totalProgress = 0;
                    foreach ($clients as $client) {
                        $progressHistory = getClientProgressHistory($conn, $client['id']);
                        $totalProgress += count($progressHistory);
                    }
                    echo $totalProgress;
                    ?>
                </div>
                <div class="text-gray-400">Progress Records</div>
            </div>
            
            <div class="stat-card">
                <div class="text-3xl font-bold text-yellow-400 mb-2">
                    <?php
                    $clientsWithProgress = 0;
                    foreach ($clients as $client) {
                        $progressHistory = getClientProgressHistory($conn, $client['id']);
                        if (!empty($progressHistory)) {
                            $clientsWithProgress++;
                        }
                    }
                    echo $clientsWithProgress;
                    ?>
                </div>
                <div class="text-gray-400">Clients with Progress</div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Track Progress Form -->
            <div class="lg:col-span-1">
                <div class="card">
                    <h2 class="text-lg font-semibold text-yellow-400 mb-4 flex items-center gap-2">
                        <i data-lucide="plus"></i>
                        Track Progress
                    </h2>
                    
                    <form method="POST">
                        <div class="space-y-4">
                            <div>
                                <label class="form-label">Select Client</label>
                                <select name="member_id" class="form-select" required>
                                    <option value="">Choose a client</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?php echo $client['id']; ?>">
                                            <?php echo htmlspecialchars($client['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label class="form-label">Weight (kg)</label>
                                <input type="number" name="weight" class="form-input" step="0.1" required>
                            </div>
                            
                            <div>
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-input" rows="3" placeholder="Additional notes..."></textarea>
                            </div>
                            
                            <button type="submit" name="track_progress" class="w-full btn btn-success">
                                <i data-lucide="save"></i> Save Progress
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Client Progress List & Charts -->
            <div class="lg:col-span-2">
                <div class="card">
                    <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-6">
                        <h2 class="text-lg font-semibold text-yellow-400 flex items-center gap-2">
                            <i data-lucide="trending-up"></i>
                            Progress Overview
                        </h2>
                        
                        <!-- Client Selector for Charts -->
                        <select id="clientSelector" class="form-select" style="width: auto;">
                            <option value="">Select a client to view chart</option>
                            <?php foreach ($clients as $client): ?>
                                <?php $progressHistory = getClientProgressHistory($conn, $client['id']); ?>
                                <?php if (!empty($progressHistory)): ?>
                                    <option value="<?php echo $client['id']; ?>">
                                        <?php echo htmlspecialchars($client['full_name']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Chart Container -->
                    <div id="chartContainer" class="chart-container mb-6" style="display: none;">
                        <canvas id="progressChart"></canvas>
                    </div>

                    <!-- Progress History -->
                    <div class="space-y-4">
                        <?php foreach ($clients as $client): ?>
                            <?php $progressHistory = getClientProgressHistory($conn, $client['id']); ?>
                            <?php if (!empty($progressHistory)): ?>
                                <div class="client-progress" data-client-id="<?php echo $client['id']; ?>">
                                    <h3 class="text-md font-semibold text-yellow-400 mb-3">
                                        <?php echo htmlspecialchars($client['full_name']); ?>
                                        <span class="text-sm text-gray-400 ml-2">(<?php echo count($progressHistory); ?> records)</span>
                                    </h3>
                                    
                                    <div class="table-container">
                                        <table>
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Weight</th>
                                                    <th>Notes</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($progressHistory as $progress): ?>
                                                    <tr>
                                                        <td><?php echo date('M j, Y', strtotime($progress['progress_date'])); ?></td>
                                                        <td class="font-semibold"><?php echo $progress['weight']; ?> kg</td>
                                                        <td class="text-gray-400">
                                                            <?php if ($progress['notes']): ?>
                                                                <?php echo htmlspecialchars($progress['notes']); ?>
                                                            <?php else: ?>
                                                                <span class="text-gray-500">-</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        
                        <?php
                        $hasProgress = false;
                        foreach ($clients as $client) {
                            $progressHistory = getClientProgressHistory($conn, $client['id']);
                            if (!empty($progressHistory)) {
                                $hasProgress = true;
                                break;
                            }
                        }
                        ?>
                        
                        <?php if (!$hasProgress): ?>
                            <div class="empty-state">
                                <i data-lucide="activity" class="w-12 h-12 mx-auto"></i>
                                <p>No progress records found. Start tracking client progress above.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
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
                // Hide notification badge
                document.getElementById('notificationBadge').classList.add('hidden');
                // Refresh the page to update notifications
                location.reload();
            }
        })
        .catch(error => console.error('Error:', error));
    }

    // Client progress data storage
    const clientProgressData = {};
    
    <?php foreach ($clients as $client): ?>
        <?php $progressHistory = getClientProgressHistory($conn, $client['id']); ?>
        <?php if (!empty($progressHistory)): ?>
            clientProgressData[<?php echo $client['id']; ?>] = {
                labels: [<?php echo implode(', ', array_map(function($progress) {
                    return "'" . date('M j', strtotime($progress['progress_date'])) . "'";
                }, $progressHistory)); ?>],
                weights: [<?php echo implode(', ', array_map(function($progress) {
                    return $progress['weight'];
                }, $progressHistory)); ?>]
            };
        <?php endif; ?>
    <?php endforeach; ?>

    let progressChart = null;

    // Client selector change event
    document.getElementById('clientSelector').addEventListener('change', function() {
        const clientId = this.value;
        const chartContainer = document.getElementById('chartContainer');
        
        if (clientId && clientProgressData[clientId]) {
            chartContainer.style.display = 'block';
            updateChart(clientId);
        } else {
            chartContainer.style.display = 'none';
            if (progressChart) {
                progressChart.destroy();
                progressChart = null;
            }
        }
    });

    function updateChart(clientId) {
        const data = clientProgressData[clientId];
        const ctx = document.getElementById('progressChart').getContext('2d');
        
        // Destroy existing chart
        if (progressChart) {
            progressChart.destroy();
        }
        
        // Create new chart
        progressChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Weight Progress (kg)',
                    data: data.weights,
                    borderColor: '#fbbf24',
                    backgroundColor: 'rgba(251, 191, 36, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#fbbf24',
                    pointBorderColor: '#000',
                    pointBorderWidth: 2,
                    pointRadius: 6,
                    pointHoverRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: {
                            color: '#e2e8f0',
                            font: {
                                size: 14
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(26, 26, 26, 0.9)',
                        titleColor: '#fbbf24',
                        bodyColor: '#e2e8f0',
                        borderColor: '#fbbf24',
                        borderWidth: 1
                    }
                },
                scales: {
                    x: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            color: '#9ca3af'
                        }
                    },
                    y: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            color: '#9ca3af',
                            callback: function(value) {
                                return value + ' kg';
                            }
                        },
                        beginAtZero: false
                    }
                }
            }
        });
    }
  </script>
<?php require_once 'includes/trainer_footer.php'; ?>
<?php if(isset($conn)) { $conn->close(); } ?>
