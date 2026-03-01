  <!-- Main Content Closing -->
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

        // Toggle user form (Specific to Admin Dashboard, but harmless here if null check is added)
        const toggleFormBtn = document.getElementById('toggleUserForm');
        if(toggleFormBtn) {
            toggleFormBtn.addEventListener('click', function() {
                const form = document.getElementById('userForm');
                const button = this;
                
                if (form.style.display === 'none') {
                    form.style.display = 'block';
                    button.innerHTML = '<i data-lucide="eye"></i> Hide Form';
                } else {
                    form.style.display = 'none';
                    button.innerHTML = '<i data-lucide="eye"></i> Show Form';
                }
                lucide.createIcons();
            });
        }

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
  </script>
</body>
</html>
