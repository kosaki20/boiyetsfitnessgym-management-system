<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'trainer') {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';
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

// Function to get all attendance records
function getAttendanceRecords($conn, $dateFilter = null) {
    $records = [];
    
    $sql = "SELECT a.*, m.full_name, m.member_type 
            FROM attendance a 
            JOIN members m ON a.member_id = m.id";
    
    if ($dateFilter) {
        $sql .= " WHERE DATE(a.check_in) = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $dateFilter);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $sql .= " ORDER BY a.check_in DESC LIMIT 100";
        $result = $conn->query($sql);
    }
    
    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }
    
    return $records;
}

// Handle manual attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_attendance'])) {
    $memberId = $_POST['member_id'];
    
    // Validate member exists
    $stmt = $conn->prepare("SELECT id, full_name FROM members WHERE id = ?");
    $stmt->bind_param("i", $memberId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $member = $result->fetch_assoc();
        
        // Check if already checked in today
        $today = date('Y-m-d');
        $checkStmt = $conn->prepare("SELECT id FROM attendance WHERE member_id = ? AND DATE(check_in) = ?");
        $checkStmt->bind_param("is", $memberId, $today);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows > 0) {
            $message = "Member is already checked in today";
            $messageType = "error";
        } else {
            // Check in
            $insertStmt = $conn->prepare("INSERT INTO attendance (member_id, check_in) VALUES (?, NOW())");
            $insertStmt->bind_param("i", $memberId);
            if ($insertStmt->execute()) {
                $message = "Successfully checked in " . $member['full_name'];
                $messageType = "success";
            } else {
                $message = "Error checking in member";
                $messageType = "error";
            }
        }
    } else {
        $message = "Member not found";
        $messageType = "error";
    }
}

// Get all members for dropdown
$membersResult = $conn->query("SELECT id, full_name FROM members ORDER BY full_name");

// Get attendance records
$dateFilter = $_GET['date'] ?? null;
$attendanceRecords = getAttendanceRecords($conn, $dateFilter);

$conn->close();
?>

<?php require_once 'includes/trainer_header.php'; ?>
<?php require_once 'includes/trainer_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 p-6 overflow-auto">
      <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-yellow-400 flex items-center gap-2">
          <i data-lucide="calendar"></i>
          Attendance Logs
        </h1>
        <div class="flex gap-3">
          <!-- Date Filter -->
          <form method="GET" class="flex gap-2">
            <input type="date" name="date" value="<?php echo $dateFilter ?: date('Y-m-d'); ?>" class="form-input" style="width: auto;">
            <button type="submit" class="btn btn-primary">
              <i data-lucide="filter"></i> Filter
            </button>
            <?php if ($dateFilter): ?>
              <a href="attendance_logs.php" class="btn btn-danger">
                <i data-lucide="x"></i> Clear
              </a>
            <?php endif; ?>
          </form>
        </div>
      </div>

      <!-- Manual Attendance Form -->
      <div class="card mb-6">
        <h2 class="text-lg font-semibold text-yellow-400 mb-4 flex items-center gap-2">
          <i data-lucide="user-plus"></i>
          Manual Check In
        </h2>
        
        <?php if (isset($message)): ?>
          <div class="alert alert-<?php echo $messageType; ?>">
            <i data-lucide="<?php echo $messageType === 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-5 h-5 mr-2"></i>
            <?php echo $message; ?>
          </div>
        <?php endif; ?>
        
        <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label class="form-label">Select Member</label>
            <select name="member_id" class="form-select" required>
              <option value="">Choose a member...</option>
              <?php while ($member = $membersResult->fetch_assoc()): ?>
                <option value="<?php echo $member['id']; ?>"><?php echo htmlspecialchars($member['full_name']); ?></option>
              <?php endwhile; ?>
            </select>
          </div>
          
          <div class="flex items-end">
            <button type="submit" name="manual_attendance" class="btn btn-success w-full">
              <i data-lucide="log-in"></i> Check In Member
            </button>
          </div>
          
          <div class="flex items-end">
            <button type="button" onclick="openQRScanner()" class="btn btn-primary w-full">
              <i data-lucide="scan"></i> Use QR Scanner
            </button>
          </div>
        </form>
      </div>

      <!-- Attendance Records -->
      <div class="card">
        <h2 class="text-lg font-semibold text-yellow-400 mb-4 flex items-center gap-2">
          <i data-lucide="list"></i>
          Attendance Records <?php echo $dateFilter ? "for " . date('M j, Y', strtotime($dateFilter)) : "(Last 100 Records)"; ?>
        </h2>
        
        <div class="table-container">
          <table>
            <thead>
              <tr>
                <th>Member Name</th>
                <th>Type</th>
                <th>Check In Time</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($attendanceRecords)): ?>
                <tr>
                  <td colspan="4" class="empty-state">
                    <i data-lucide="calendar-x" class="w-12 h-12 mx-auto"></i>
                    <p>No attendance records found</p>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($attendanceRecords as $record): ?>
                  <tr>
                    <td class="font-medium"><?php echo htmlspecialchars($record['full_name']); ?></td>
                    <td>
                      <span class="px-2 py-1 rounded-full text-xs <?php echo $record['member_type'] === 'client' ? 'bg-blue-500/20 text-blue-400' : 'bg-green-500/20 text-green-400'; ?>">
                        <?php echo ucfirst($record['member_type']); ?>
                      </span>
                    </td>
                    <td><?php echo date('g:i A', strtotime($record['check_in'])); ?></td>
                    <td><?php echo date('M j, Y', strtotime($record['check_in'])); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        
        <?php if (!empty($attendanceRecords)): ?>
          <div class="mt-4 text-sm text-gray-500 text-center">
            Showing <?php echo count($attendanceRecords); ?> records
            <?php if (!$dateFilter): ?>
              (most recent 100)
            <?php endif; ?>
          </div>
        <?php endif; ?>
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
    // Sidebar functionality
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize icons
        lucide.createIcons();
        
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

        // Initialize QR Scanner
        setupQRScanner();
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
                
                // Auto-refresh the page to show new attendance record
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
                
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

    function setLoadingState(button, isLoading) {
        if (isLoading) {
            button.disabled = true;
            button.style.opacity = '0.7';
        } else {
            button.disabled = false;
            button.style.opacity = '1';
        }
    }

    function showToast(message, type = 'info', duration = 3000) {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <div class="flex items-center gap-2">
                <i data-lucide="${getToastIcon(type)}" class="w-4 h-4"></i>
                <span>${message}</span>
            </div>
        `;
        
        document.body.appendChild(toast);
        
        // Trigger animation
        setTimeout(() => toast.classList.add('show'), 100);
        
        // Remove toast after duration
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, duration);
        
        lucide.createIcons();
    }

    function getToastIcon(type) {
        switch(type) {
            case 'success': return 'check-circle';
            case 'error': return 'alert-circle';
            case 'warning': return 'alert-triangle';
            default: return 'info';
        }
    }

    // Global function to open QR scanner
    function openQRScanner() {
        const qrScanner = document.getElementById('qrScanner');
        const qrInput = document.getElementById('qrInput');
        
        qrScanner.classList.remove('hidden');
        if (qrScannerActive) {
            setTimeout(() => qrInput.focus(), 100);
        }
    }

    // Auto-refresh every 30 seconds to show latest attendance
    setInterval(() => {
      window.location.reload();
    }, 30000);
  </script>
<?php require_once 'includes/trainer_footer.php'; ?>
<?php if(isset($conn)) { $conn->close(); } ?>
