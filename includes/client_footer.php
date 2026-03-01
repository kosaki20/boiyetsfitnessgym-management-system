    </main>
  </div>

  <!-- Mobile Overlay -->
  <div class="overlay" id="mobileOverlay"></div>

  <!-- Mobile Navigation Bar - Fixed at bottom -->
  <nav class="mobile-nav flex items-center justify-around h-16 bg-[#0d0d0d] border-t border-gray-800 md:hidden">
    <a href="client_dashboard.php" class="mobile-nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'client_dashboard.php' ? 'active' : ''; ?>">
      <i data-lucide="home"></i>
      <span class="mobile-nav-label">Home</span>
    </a>
    <a href="workoutplansclient.php" class="mobile-nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'workoutplansclient.php' ? 'active' : ''; ?>">
      <i data-lucide="dumbbell"></i>
      <span class="mobile-nav-label">Workouts</span>
    </a>
    <a href="nutritionplansclient.php" class="mobile-nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'nutritionplansclient.php' ? 'active' : ''; ?>">
      <i data-lucide="utensils"></i>
      <span class="mobile-nav-label">Meals</span>
    </a>
    <a href="attendanceclient.php" class="mobile-nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'attendanceclient.php' ? 'active' : ''; ?>">
      <i data-lucide="calendar"></i>
      <span class="mobile-nav-label">Check-in</span>
    </a>
  </nav>

  <!-- Mobile Sidebar - Slide-out -->
  <aside id="mobileSidebar" class="mobile-sidebar fixed inset-y-0 left-0 w-64 bg-[#0d0d0d] shadow-2xl z-50 transform -translateX-full transition-transform duration-300 md:hidden">
    <div class="p-4 flex justify-between items-center border-b border-gray-800">
      <h2 class="text-yellow-400 font-bold">Menu</h2>
      <button onclick="closeMobileSidebar()" class="text-gray-400">
        <i data-lucide="x"></i>
      </button>
    </div>
    <div class="p-4 space-y-2">
      <a href="client_dashboard.php" class="flex items-center gap-3 p-3 text-gray-300 rounded hover:bg-white/5">
        <i data-lucide="home" class="w-5 h-5"></i> Dashboard
      </a>
      <a href="profile.php" class="flex items-center gap-3 p-3 text-gray-300 rounded hover:bg-white/5">
        <i data-lucide="user" class="w-5 h-5"></i> My Profile
      </a>
      <a href="workoutplansclient.php" class="flex items-center gap-3 p-3 text-gray-300 rounded hover:bg-white/5">
        <i data-lucide="dumbbell" class="w-5 h-5"></i> Workout Plans
      </a>
      <a href="nutritionplansclient.php" class="flex items-center gap-3 p-3 text-gray-300 rounded hover:bg-white/5">
        <i data-lucide="utensils" class="w-5 h-5"></i> Nutrition Plans
      </a>
      <a href="attendanceclient.php" class="flex items-center gap-3 p-3 text-gray-300 rounded hover:bg-white/5">
        <i data-lucide="calendar" class="w-5 h-5"></i> Attendance
      </a>
      <a href="membershipclient.php" class="flex items-center gap-3 p-3 text-gray-300 rounded hover:bg-white/5">
        <i data-lucide="id-card" class="w-5 h-5"></i> Membership
      </a>
      <div class="border-t border-gray-800 my-4"></div>
      <a href="logout.php" class="flex items-center gap-3 p-3 text-red-400 rounded hover:bg-red-400/10">
        <i data-lucide="log-out" class="w-5 h-5"></i> Logout
      </a>
    </div>
  </aside>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      lucide.createIcons();

      // Sidebar Toggle Logic
      const toggleSidebarBtn = document.getElementById('toggleSidebar');
      const desktopSidebar = document.getElementById('desktopSidebar');
      const mobileSidebar = document.getElementById('mobileSidebar');
      const mobileOverlay = document.getElementById('mobileOverlay');

      if (toggleSidebarBtn) {
        toggleSidebarBtn.addEventListener('click', function() {
          if (window.innerWidth > 768) {
            // Desktop toggle: Toggle collapse
            if (desktopSidebar.classList.contains('w-60')) {
                desktopSidebar.classList.remove('w-60');
                desktopSidebar.classList.add('w-16', 'sidebar-collapsed');
            } else {
                desktopSidebar.classList.remove('w-16', 'sidebar-collapsed');
                desktopSidebar.classList.add('w-60');
            }
          } else {
            // Mobile toggle: Open side menu
            mobileSidebar.classList.add('open');
            mobileSidebar.style.transform = 'translateX(0)';
            mobileOverlay.classList.add('active');
            document.body.style.overflow = 'hidden';
          }
        });
      }

      // Submenu Toggles (Desktop)
      const setupSubmenu = (toggleId, submenuId, chevronId) => {
        const toggle = document.getElementById(toggleId);
        const submenu = document.getElementById(submenuId);
        const chevron = document.getElementById(chevronId);
        if (toggle && submenu && chevron) {
          toggle.addEventListener('click', () => {
            submenu.classList.toggle('open');
            chevron.classList.toggle('rotate');
          });
        }
      };

      setupSubmenu('workoutToggle', 'workoutSubmenu', 'workoutChevron');
      setupSubmenu('nutritionToggle', 'nutritionSubmenu', 'nutritionChevron');

      // Dropdown Functionality
      setupDropdowns();
      
      // Update active nav state
      updateMobileNavActive();
    });

    function setupDropdowns() {
      const bell = document.getElementById('notificationBell');
      const bellMenu = document.getElementById('notificationDropdown');
      const user = document.getElementById('userMenuButton');
      const userMenu = document.getElementById('userDropdown');

      const closeAll = () => {
        if (bellMenu) bellMenu.classList.add('hidden');
        if (userMenu) userMenu.classList.add('hidden');
      };

      if (bell && bellMenu) {
        bell.addEventListener('click', (e) => {
          e.stopPropagation();
          const isHidden = bellMenu.classList.contains('hidden');
          closeAll();
          if (isHidden) bellMenu.classList.remove('hidden');
        });
      }

      if (user && userMenu) {
        user.addEventListener('click', (e) => {
          e.stopPropagation();
          const isHidden = userMenu.classList.contains('hidden');
          closeAll();
          if (isHidden) userMenu.classList.remove('hidden');
        });
      }

      document.addEventListener('click', closeAll);
      
      const markRead = document.getElementById('markAllRead');
      if (markRead) {
        markRead.addEventListener('click', (e) => {
          e.stopPropagation();
          markAllNotificationsAsRead();
        });
      }
    }

    function markAllNotificationsAsRead() {
      fetch('notification_ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=mark_all_read&user_role=client'
      })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          const badge = document.getElementById('notificationBadge');
          if (badge) badge.classList.add('hidden');
          location.reload();
        }
      })
      .catch(e => console.error('Error:', e));
    }

    function closeMobileSidebar() {
      const menu = document.getElementById('mobileSidebar');
      const overlay = document.getElementById('mobileOverlay');
      if (menu) {
        menu.classList.remove('open');
        menu.style.transform = 'translateX(-100%)';
      }
      if (overlay) overlay.classList.remove('active');
      document.body.style.overflow = '';
    }

    function updateMobileNavActive() {
      const page = window.location.pathname.split('/').pop() || 'client_dashboard.php';
      document.querySelectorAll('.mobile-nav-item').forEach(item => {
        if (item.getAttribute('href') === page) {
          item.classList.add('active');
        } else {
          item.classList.remove('active');
        }
      });
    }

    function showToast(message, type = 'success', duration = 5000) {
        const container = document.getElementById('toastContainer');
        if (!container) return;
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <div class="flex items-center gap-2">
                <i data-lucide="info" class="w-4 h-4"></i>
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
    
    // Auto-click overlay to close mobile menu
    const overlay = document.getElementById('mobileOverlay');
    if (overlay) overlay.addEventListener('click', closeMobileSidebar);
  </script>
</body>
</html>
<?php if (isset($conn)) { $conn->close(); } ?>
