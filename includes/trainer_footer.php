  <!-- Main Content Closing defined by the pages -->
  
  <!-- QR Scanner Container - MOVABLE & TOGGLEABLE -->
  <div id="qrScanner" class="qr-scanner-container hidden">
    <div class="qr-scanner-header cursor-move" id="qrScannerHeader">
      <div class="qr-scanner-title">
        <i data-lucide="scan"></i>
        <span>QR Scanner</span>
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
    // Toast notification system
    function showToast(message, type = 'success', duration = 5000) {
        const container = document.getElementById('toastContainer');
        if (!container) return;
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <div class="flex items-center gap-2">
                <i data-lucide="${getToastIcon(type)}" class="w-4 h-4"></i>
                <span>${message}</span>
            </div>
        `;
        
        container.appendChild(toast);
        
        setTimeout(() => toast.classList.add('show'), 100);
        
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

    // Loading state management
    function setLoadingState(element, isLoading) {
        if (!element) return;
        if (isLoading) {
            element.classList.add('loading');
            element.disabled = true;
        } else {
            element.classList.remove('loading');
            element.disabled = false;
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Initialize icons
        lucide.createIcons();
        
        // Mobile sidebar setup
        setupMobileSidebar();
        
        // Members submenu toggle
        const membersToggle = document.getElementById('membersToggle');
        if (membersToggle) {
            const membersSubmenu = document.getElementById('membersSubmenu');
            const membersChevron = document.getElementById('membersChevron');
            membersToggle.addEventListener('click', () => {
                membersSubmenu.classList.toggle('open');
                membersChevron.classList.toggle('rotate');
            });
        }

        // Plans submenu toggle
        const plansToggle = document.getElementById('plansToggle');
        if (plansToggle) {
            const plansSubmenu = document.getElementById('plansSubmenu');
            const plansChevron = document.getElementById('plansChevron');
            plansToggle.addEventListener('click', () => {
                plansSubmenu.classList.toggle('open');
                plansChevron.classList.toggle('rotate');
            });
        }

        // Initialize drop-downs
        setupDropdowns();

        // Initialize QR Scanner
        setupQRScanner();
    });

    function setupMobileSidebar() {
        const sidebar = document.getElementById('sidebar');
        const toggleBtn = document.getElementById('toggleSidebar');
        if (!sidebar || !toggleBtn) return;
        
        function isMobile() {
            return window.innerWidth <= 768;
        }
        
        toggleBtn.addEventListener('click', function() {
            if (isMobile()) {
                sidebar.classList.toggle('mobile-open');
            } else {
                if (sidebar.classList.contains('w-60')) {
                    sidebar.classList.remove('w-60');
                    sidebar.classList.add('w-16', 'sidebar-collapsed');
                } else {
                    sidebar.classList.remove('w-16', 'sidebar-collapsed');
                    sidebar.classList.add('w-60');
                }
            }
        });
        
        document.addEventListener('click', function(e) {
            if (isMobile() && 
                !sidebar.contains(e.target) && 
                !toggleBtn.contains(e.target) &&
                sidebar.classList.contains('mobile-open')) {
                sidebar.classList.remove('mobile-open');
            }
        });

        // Hover to open sidebar (for collapsed state)
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
        
        if (isMobile()) {
            document.querySelectorAll('.sidebar-item').forEach(item => {
                item.style.cursor = 'pointer';
            });
        }
    }

    // Dropdown functionality
    function setupDropdowns() {
        const notificationBell = document.getElementById('notificationBell');
        const notificationDropdown = document.getElementById('notificationDropdown');
        const userMenuButton = document.getElementById('userMenuButton');
        const userDropdown = document.getElementById('userDropdown');
        
        function closeAllDropdowns() {
            if (notificationDropdown) notificationDropdown.classList.add('hidden');
            if (userDropdown) userDropdown.classList.add('hidden');
        }
        
        if (notificationBell && notificationDropdown) {
            notificationBell.addEventListener('click', function(e) {
                e.stopPropagation();
                const isHidden = notificationDropdown.classList.contains('hidden');
                closeAllDropdowns();
                if (isHidden) notificationDropdown.classList.remove('hidden');
            });
        }
        
        if (userMenuButton && userDropdown) {
            userMenuButton.addEventListener('click', function(e) {
                e.stopPropagation();
                const isHidden = userDropdown.classList.contains('hidden');
                closeAllDropdowns();
                if (isHidden) userDropdown.classList.remove('hidden');
            });
        }
        
        document.addEventListener('click', function(e) {
            if (notificationDropdown && notificationBell && !notificationDropdown.contains(e.target) && !notificationBell.contains(e.target) &&
                userDropdown && userMenuButton && !userDropdown.contains(e.target) && !userMenuButton.contains(e.target)) {
                closeAllDropdowns();
            }
        });
        
        document.getElementById('markAllRead')?.addEventListener('click', function(e) {
            e.stopPropagation();
            markAllNotificationsAsRead();
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAllDropdowns();
            }
        });
    }

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
                const badge = document.getElementById('notificationBadge');
                if (badge) badge.classList.add('hidden');
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

    // QR Scanner functionality - MOVABLE & TOGGLEABLE
    let qrScannerActive = true;
    let lastProcessedQR = '';
    let lastProcessedTime = 0;
    let qrProcessing = false;
    let qrCooldown = false;
    let isDragging = false;
    let dragOffset = { x: 0, y: 0 };

    function setupQRScanner() {
        const qrScanner = document.getElementById('qrScanner');
        if (!qrScanner) return; // Only setup if element exists

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
            if (e.target.closest('button')) return;
            
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

        qrInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && qrScannerActive && !qrProcessing && !qrCooldown) {
                processQRCode();
                e.preventDefault();
            }
        });
        
        processQRBtn.addEventListener('click', function() {
            if (qrScannerActive && !qrProcessing && !qrCooldown) {
                processQRCode();
            }
        });
        
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
        
        qrInput.addEventListener('input', function() {
            if (this.value === lastProcessedQR) {
                this.value = '';
            }
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !qrScanner.classList.contains('hidden')) {
                qrScanner.classList.add('hidden');
            }
        });
        
        setTimeout(() => {
            if (qrScannerActive && !qrScanner.classList.contains('hidden')) {
                qrInput.focus();
            }
        }, 1000);
    }

    function processQRCode() {
        if (qrProcessing || qrCooldown) return;
        
        const qrInput = document.getElementById('qrInput');
        const processQRBtn = document.getElementById('processQR');
        const qrCode = qrInput.value.trim();
        
        if (!qrCode) {
            showQRResult('error', 'Error', 'Please enter a QR code');
            showToast('Please enter a QR code', 'error');
            return;
        }
        
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
        
        showQRResult('info', 'Processing', 'Scanning QR code...');
        showToast('Processing QR code...', 'info', 2000);
        
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
                
                if (data.member_name) {
                    showQRResult('success', 'Member Found', `QR code belongs to: ${data.member_name}`);
                }
                
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
            
            setTimeout(() => {
                qrInput.value = '';
                const qrScanner = document.getElementById('qrScanner');
                if (qrScannerActive && !qrScanner.classList.contains('hidden')) {
                    qrInput.focus();
                }
            }, 500);
            
            setTimeout(() => {
                qrCooldown = false;
            }, 3000);
        });
    }

    function showQRResult(type, title, message) {
        const qrResult = document.getElementById('qrResult');
        if (!qrResult) return;
        qrResult.className = 'qr-scanner-result ' + type;
        qrResult.innerHTML = `
            <div class="qr-result-title">${title}</div>
            <div class="qr-result-message">${message}</div>
        `;
        qrResult.style.display = 'block';
        
        let hideTime = type === 'success' ? 4000 : 5000;
        if (title === 'Cooldown') hideTime = 3000;
        if (title === 'Processing') hideTime = 2000;
        
        setTimeout(() => {
            qrResult.style.display = 'none';
        }, hideTime);
    }

    // Global function to open QR scanner
    function openQRScanner() {
        const qrScanner = document.getElementById('qrScanner');
        const qrInput = document.getElementById('qrInput');
        if (!qrScanner || !qrInput) return;
        
        qrScanner.classList.remove('hidden');
        if (qrScannerActive) {
            setTimeout(() => qrInput.focus(), 100);
        }
    }
  </script>
</body>
</html>
