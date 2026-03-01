<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BOIYETS FITNESS GYM - <?php echo $page_title ?? 'Client Dashboard'; ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    
    * {
      font-family: 'Inter', sans-serif;
    }
    
    body {
      background: linear-gradient(135deg, #111 0%, #0a0a0a 100%);
      color: #e2e8f0;
      margin: 0;
      padding: 0;
    }
    
    /* Mobile Sidebar Styles */
    .mobile-sidebar {
      transform: translateX(-100%);
      transition: transform 0.3s ease-in-out;
      z-index: 50;
      display: none;
      will-change: transform;
    }
    
    .mobile-sidebar.open {
      transform: translateX(0);
    }
    
    .overlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.5);
      z-index: 40;
    }
    
    .overlay.active {
      display: block;
    }
    
    .sidebar { 
      flex-shrink: 0; 
      transition: all 0.3s ease;
      overflow-y: auto;
      -ms-overflow-style: none;
      scrollbar-width: none;
      height: calc(100vh - 64px);
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
    
    .sidebar-collapsed .sidebar-item .text-sm,
    .sidebar-collapsed .submenu { 
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
      justify-content: space-between;
      color: #9ca3af;
      padding: 0.6rem 0.8rem;
      border-radius: 8px;
      cursor: pointer;
      transition: all 0.2s ease;
      margin-bottom: 0.25rem;
      min-height: 44px;
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
    
    @media (min-width: 769px) {
      .sidebar-item:hover { 
        background: rgba(255,255,255,0.05); 
        color: #fbbf24; 
      }
      
      .submenu a:hover { 
        color: #fbbf24; 
        background: rgba(255,255,255,0.05); 
      }
    }
    
    .sidebar-item i { 
      width: 18px; 
      height: 18px; 
      stroke-width: 1.75; 
      flex-shrink: 0; 
      margin-right: 0.75rem; 
    }

    .submenu {
      margin-left: 2.2rem;
      max-height: 0;
      opacity: 0;
      overflow: hidden;
      transition: max-height 0.3s ease, opacity 0.3s ease, margin-top 0.3s ease;
    }
    
    .submenu.open { 
      max-height: 500px; 
      opacity: 1; 
      margin-top: 0.25rem; 
    }
    
    .submenu a {
      display: flex;
      align-items: center;
      padding: 0.4rem 0.6rem;
      font-size: 0.8rem;
      color: #9ca3af;
      border-radius: 6px;
      transition: all 0.2s ease;
      min-height: 44px;
    }
    
    .submenu a i { 
      width: 14px; 
      height: 14px; 
      margin-right: 0.5rem; 
    }

    .chevron { 
      transition: transform 0.3s ease; 
    }
    
    .chevron.rotate { 
      transform: rotate(90deg); 
    }

    .card {
      background: rgba(26, 26, 26, 0.7);
      border-radius: 12px;
      padding: 1rem;
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.05);
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
      transition: all 0.2s ease;
      will-change: transform;
      position: relative;
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
    
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 0.75rem;
    }
    
    .topbar {
      background: rgba(13, 13, 13, 0.95);
      backdrop-filter: blur(10px);
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      position: sticky;
      top: 0;
      z-index: 30;
      height: 64px;
    }
    
    .main-content {
      flex: 1;
      overflow-y: auto;
      height: calc(100vh - 64px);
    }
    
    /* Mobile Navigation */
    .mobile-nav {
      display: none;
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      background: rgba(13, 13, 13, 0.95);
      backdrop-filter: blur(10px);
      border-top: 1px solid rgba(255, 255, 255, 0.08);
      z-index: 30;
    }
    
    .mobile-nav-item {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 0.5rem;
      color: #9ca3af;
      transition: all 0.2s ease;
      min-height: 44px;
    }
    
    .mobile-nav-item.active {
      color: #fbbf24;
    }
    
    .mobile-nav-item i {
      width: 20px;
      height: 20px;
      margin-bottom: 0.25rem;
    }
    
    .mobile-nav-label {
      font-size: 0.7rem;
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
      .desktop-sidebar {
        display: none !important;
      }
      
      .mobile-sidebar {
        display: block;
      }
      
      .mobile-nav {
        display: flex;
      }
      
      .main-content {
        padding-bottom: 80px;
        height: calc(100vh - 144px);
      }
      
      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 0.5rem;
      }
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
    
    .toast.success { background: #10b981; }
    .toast.error { background: #ef4444; }
    .toast.warning { background: #f59e0b; }
    .toast.info { background: #3b82f6; }

    /* Shared form element styles */
    .form-input, .form-select, .form-textarea {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 8px;
      padding: 0.5rem 0.75rem;
      color: white;
      width: 100%;
      transition: all 0.2s ease;
    }

    .form-input:focus, .form-select:focus, .form-textarea:focus {
      outline: none;
      border-color: #fbbf24;
      box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.2);
    }

    .form-select {
      padding-right: 2rem;
      appearance: none;
      -webkit-appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23fbbf24' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 0.6rem center;
      background-size: 16px;
    }

    .form-select option {
      background: #1a1a1a;
      color: white;
    }

    .form-textarea {
      min-height: 100px;
      resize: vertical;
    }

    .form-label {
      display: block;
      margin-bottom: 0.25rem;
      font-size: 0.8rem;
      color: #d1d5db;
    }
  </style>
</head>
<body class="min-h-screen js-enabled">
  <!-- Toast Notification Container -->
  <div id="toastContainer"></div>

  <!-- Mobile Overlay -->
  <div class="overlay" id="mobileOverlay"></div>

  <!-- Topbar -->
  <header class="topbar flex items-center justify-between px-4 py-3 shadow">
    <div class="flex items-center space-x-3">
      <button id="toggleSidebar" class="text-gray-300 hover:text-yellow-400 transition-colors p-1 rounded-lg hover:bg-white/5" aria-label="Toggle sidebar">
        <i data-lucide="menu" class="w-5 h-5"></i>
      </button>
      <h1 class="text-lg font-bold text-yellow-400">BOIYETS FITNESS GYM</h1>
    </div>
    <div class="flex items-center space-x-3">
      <!-- Chat Button -->
      <a href="chat.php" class="text-gray-300 hover:text-blue-400 transition-colors p-2 rounded-lg hover:bg-white/5 relative">
        <i data-lucide="message-circle"></i>
        <?php if (isset($unread_count) && $unread_count > 0): ?>
          <span class="absolute -top-1 -right-1 bg-red-500 text-xs rounded-full h-5 w-5 flex items-center justify-center" id="chatBadge">
            <?php echo $unread_count; ?>
          </span>
        <?php endif; ?>
      </a>

      <!-- Notification Bell -->
      <div class="dropdown-container">
        <button id="notificationBell" class="text-gray-300 hover:text-yellow-400 transition-colors p-2 rounded-lg hover:bg-white/5 relative">
          <i data-lucide="bell" class="w-5 h-5"></i>
          <?php if (isset($notification_count)): ?>
          <span class="absolute -top-1 -right-1 bg-red-500 text-xs rounded-full h-5 w-5 flex items-center justify-center <?php echo $notification_count > 0 ? '' : 'hidden'; ?>" id="notificationBadge">
            <?php echo $notification_count > 99 ? '99+' : $notification_count; ?>
          </span>
          <?php endif; ?>
        </button>
        
        <!-- Notification Dropdown -->
        <div id="notificationDropdown" class="dropdown notification-dropdown hidden">
          <div class="p-4 border-b border-gray-700">
            <div class="flex justify-between items-center">
              <h3 class="text-yellow-400 font-semibold">Notifications</h3>
              <?php if (isset($notification_count) && $notification_count > 0): ?>
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
                </div>
              <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                  <div class="notification-item" data-notification-id="<?php echo $notification['id']; ?>">
                    <div class="flex items-start gap-3">
                      <div class="flex-shrink-0 mt-1">
                        <i data-lucide="bell" class="w-4 h-4 text-yellow-400"></i>
                      </div>
                      <div class="flex-1 min-w-0">
                        <p class="text-white font-medium text-sm"><?php echo htmlspecialchars($notification['title']); ?></p>
                        <p class="text-gray-400 text-xs"><?php echo htmlspecialchars($notification['message']); ?></p>
                        <span class="text-[10px] text-gray-500"><?php echo date('M j, Y h:i A', strtotime($notification['created_at'])); ?></span>
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
          <img src="<?php echo htmlspecialchars($_SESSION['profile_picture'] ?? 'https://i.pravatar.cc/40'); ?>" class="w-8 h-8 rounded-full border border-gray-600" id="userAvatar" />
          <span class="text-sm font-medium hidden md:inline" id="userName">
            <?php echo htmlspecialchars($_SESSION['username']); ?>
          </span>
          <i data-lucide="chevron-down" class="w-4 h-4"></i>
        </button>
        
        <!-- User Dropdown Menu -->
        <div id="userDropdown" class="dropdown user-dropdown hidden">
          <div class="p-4 border-b border-gray-700">
            <p class="text-white font-semibold text-sm"><?php echo htmlspecialchars($_SESSION['username']); ?></p>
            <p class="text-gray-400 text-xs capitalize"><?php echo $_SESSION['role']; ?></p>
          </div>
          <div class="p-2">
            <a href="profile.php" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-300 hover:text-yellow-400 hover:bg-white/5 rounded-lg transition-colors">
              <i data-lucide="user" class="w-4 h-4"></i> My Profile
            </a>
            <a href="logout.php" class="flex items-center gap-2 px-3 py-2 text-sm text-red-400 hover:text-red-300 hover:bg-red-400/10 rounded-lg transition-colors">
              <i data-lucide="log-out" class="w-4 h-4"></i> Logout
            </a>
          </div>
        </div>
      </div>
    </div>
  </header>

  <!-- Main Layout Container -->
  <div class="flex h-[calc(100vh-64px)]">
    <?php require_once __DIR__ . '/client_sidebar.php'; ?>
