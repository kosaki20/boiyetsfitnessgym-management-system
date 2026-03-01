<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BOIYETS FITNESS GYM - Trainer</title>
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
      background-attachment: fixed;
    }
    
    .sidebar { 
      flex-shrink: 0; 
      transition: all 0.3s ease;
      overflow-y: auto;
      -ms-overflow-style: none;
      scrollbar-width: none;
      background: rgba(13, 13, 13, 0.95);
      backdrop-filter: blur(10px);
      border-right: 1px solid rgba(255, 255, 255, 0.05);
    }
    
    .sidebar::-webkit-scrollbar {
      display: none;
    }
    
    .sidebar-collapsed .sidebar-item .text-sm { 
      display: none; 
    }
    
    .sidebar-collapsed .sidebar-item { 
      justify-content: center; 
      padding: 0.75rem;
    }
    
    .sidebar-collapsed .sidebar-item i { 
      margin: 0; 
    }
    
    .sidebar-collapsed .chevron {
      display: none;
    }
    
    .sidebar-item {
      position: relative;
      display: flex;
      align-items: center;
      color: #9ca3af;
      padding: 0.75rem 1rem;
      border-radius: 8px;
      cursor: pointer;
      transition: all 0.2s ease;
      margin-bottom: 0.25rem;
      text-decoration: none;
      border: 1px solid transparent;
    }
    
    .sidebar-item.active {
      color: #fbbf24;
      background: rgba(251, 191, 36, 0.1);
      border-color: rgba(251, 191, 36, 0.2);
    }
    
    .sidebar-item:hover:not(.active) { 
      background: rgba(255,255,255,0.05); 
      color: #f8fafc; 
    }
    
    .sidebar-item i:not(.chevron) { 
      width: 20px; 
      height: 20px; 
      stroke-width: 2; 
      flex-shrink: 0; 
      margin-right: 0.75rem; 
    }
    
    .chevron {
      margin-left: auto;
      width: 16px;
      height: 16px;
      transition: transform 0.3s ease;
    }
    
    .chevron.rotate {
      transform: rotate(90deg);
    }
    
    .submenu {
      display: none;
      padding-left: 2.75rem;
      margin-bottom: 0.5rem;
      animation: slideDown 0.3s ease forwards;
    }
    
    @keyframes slideDown {
      from { opacity: 0; transform: translateY(-10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    
    .submenu.open {
      display: block;
    }
    
    .submenu a {
      display: flex;
      align-items: center;
      padding: 0.5rem;
      color: #9ca3af;
      text-decoration: none;
      font-size: 0.85rem;
      border-radius: 6px;
      transition: all 0.2s ease;
      margin-bottom: 0.125rem;
    }
    
    .submenu a i {
      width: 14px;
      height: 14px;
      margin-right: 0.5rem;
    }
    
    .submenu a:hover {
      color: #fbbf24;
      background: rgba(251, 191, 36, 0.05);
    }

    .tooltip {
      position: absolute;
      left: 100%;
      top: 50%;
      transform: translateY(-50%) translateX(10px);
      background: rgba(0,0,0,0.9);
      color: #fff;
      padding: 0.3rem 0.6rem;
      border-radius: 4px;
      font-size: 0.75rem;
      white-space: nowrap;
      opacity: 0;
      pointer-events: none;
      transition: all 0.2s ease;
      z-index: 50;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
      border: 1px solid rgba(255,255,255,0.1);
    }
    
    .sidebar-collapsed .sidebar-item:hover .tooltip { 
      opacity: 1; 
      transform: translateY(-50%) translateX(5px); 
    }

    .card {
      background: rgba(26, 26, 26, 0.7);
      border-radius: 12px;
      padding: 1.25rem;
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.05);
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
      transition: all 0.3s ease;
    }
    
    .card:hover {
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
      border-color: rgba(251, 191, 36, 0.2);
    }
    
    .card-title {
      font-size: 0.95rem;
      font-weight: 600;
      color: #fbbf24;
      margin-bottom: 0.75rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
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
      cursor: pointer;
    }
    
    .button-sm:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
    }

    .chart-container {
      width: 100%;
      height: 250px;
      position: relative;
    }
    
    .topbar {
      background: rgba(13, 13, 13, 0.95);
      backdrop-filter: blur(10px);
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
      position: relative;
      z-index: 100;
    }

    .form-input, .form-select {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 8px;
      padding: 0.5rem 0.75rem;
      color: white;
      width: 100%;
      transition: all 0.2s ease;
      font-size: 0.9rem;
    }
    
    .form-input:focus, .form-select:focus {
      outline: none;
      border-color: #fbbf24;
      box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.2);
      background: rgba(255, 255, 255, 0.08);
    }

    .form-select option {
      background: #1a1a1a;
      color: white;
    }
    
    .form-label {
      display: block;
      margin-bottom: 0.35rem;
      font-size: 0.85rem;
      color: #d1d5db;
      font-weight: 500;
    }

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
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
      cursor: pointer;
      transition: background-color 0.2s ease;
    }
    
    .notification-item:hover {
      background: rgba(255, 255, 255, 0.05);
    }
    
    .notification-item:last-child {
      border-bottom: none;
    }

    .announcement-item {
      padding: 1rem;
      border-radius: 8px;
      background: rgba(255, 255, 255, 0.03);
      border-left: 4px solid #4b5563;
      margin-bottom: 0.75rem;
      transition: all 0.2s ease;
    }
    
    .announcement-item:hover {
      background: rgba(255, 255, 255, 0.05);
      transform: translateX(2px);
    }
    
    .announcement-item.urgent { border-left-color: #ef4444; }
    .announcement-item.info { border-left-color: #3b82f6; }
    .announcement-item.warning { border-left-color: #f59e0b; }
    
    .announcement-title {
      font-weight: 600;
      color: #f8fafc;
      margin-bottom: 0.25rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    
    .announcement-date {
      font-size: 0.7rem;
      color: #9ca3af;
      margin-bottom: 0.5rem;
    }
    
    .announcement-content {
      color: #cbd5e1;
      line-height: 1.4;
    }

    .priority-badge {
      font-size: 0.65rem;
      padding: 0.15rem 0.4rem;
      border-radius: 4px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    
    .priority-high { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
    .priority-medium { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
    .priority-low { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }

    /* QR Scanner Styles - Movable window */
    .qr-scanner-container {
      position: fixed;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      width: 90%;
      max-width: 320px;
      background: #1a1a1a;
      border-radius: 12px;
      padding: 1rem;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
      border: 1px solid rgba(251, 191, 36, 0.3);
      z-index: 1000;
      transition: opacity 0.3s ease;
    }

    .qr-scanner-container.hidden {
      display: none;
    }

    .qr-scanner-container.dragging {
      opacity: 0.8;
      box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.6);
      border-color: #fbbf24;
    }

    .qr-scanner-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1rem;
      padding-bottom: 0.5rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      user-select: none;
    }

    .cursor-move {
      cursor: move;
    }

    .qr-scanner-title {
      font-weight: 600;
      color: #fbbf24;
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.9rem;
    }

    .qr-scanner-title i {
      width: 16px;
      height: 16px;
    }

    .qr-scanner-status {
      font-size: 0.7rem;
      padding: 0.15rem 0.4rem;
      border-radius: 10px;
      font-weight: 500;
    }

    .qr-scanner-status.active {
      background: rgba(16, 185, 129, 0.2);
      color: #10b981;
    }

    .qr-scanner-status.disabled {
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
    }

    .qr-input {
      width: 100%;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 8px;
      padding: 0.75rem;
      color: white;
      margin-bottom: 0.75rem;
      transition: all 0.2s ease;
      font-size: 0.9rem;
    }

    .qr-input:focus {
      outline: none;
      border-color: #fbbf24;
      box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.2);
    }

    .qr-scanner-buttons {
      display: flex;
      gap: 0.5rem;
    }

    .qr-scanner-btn {
      flex: 1;
      padding: 0.5rem;
      border-radius: 8px;
      font-size: 0.8rem;
      font-weight: 500;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.4rem;
      transition: all 0.2s ease;
      border: none;
      cursor: pointer;
    }

    .qr-scanner-btn.primary {
      background: rgba(251, 191, 36, 0.2);
      color: #fbbf24;
    }

    .qr-scanner-btn.primary:hover {
      background: rgba(251, 191, 36, 0.3);
    }

    .qr-scanner-btn.secondary {
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
    }

    .qr-scanner-btn.secondary:hover {
      background: rgba(239, 68, 68, 0.3);
    }

    .qr-scanner-result {
      margin-top: 0.75rem;
      padding: 0.75rem;
      border-radius: 8px;
      background: rgba(255, 255, 255, 0.05);
      display: none;
    }

    .qr-scanner-result.success {
      display: block;
      border-left: 4px solid #10b981;
    }

    .qr-scanner-result.error {
      display: block;
      border-left: 4px solid #ef4444;
    }

    .qr-scanner-result.info {
      display: block;
      border-left: 4px solid #3b82f6;
    }

    .qr-result-title {
      font-weight: 600;
      margin-bottom: 0.25rem;
    }

    .qr-result-message {
      font-size: 0.8rem;
      color: #d1d5db;
    }

    .scanner-instructions {
      font-size: 0.75rem;
      color: #9ca3af;
      margin-top: 0.5rem;
      text-align: center;
    }

    /* Mobile responsive */
    @media (max-width: 640px) {
      .qr-scanner-container {
        position: fixed;
        bottom: 80px;
        left: 50%;
        transform: translateX(-50%);
        max-width: 90vw;
        margin: 0;
      }
      
      .qr-scanner-container.hidden {
        transform: translateX(-50%) translateY(100px);
        opacity: 0;
      }
    }

    /* Table Styles */
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
      padding: 0.75rem;
      text-align: left;
      font-weight: 600;
      font-size: 0.8rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    td {
      padding: 0.75rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
      font-size: 0.8rem;
    }
    
    tr:hover {
      background: rgba(255, 255, 255, 0.02);
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

    .empty-state {
      text-align: center;
      padding: 2rem 1rem;
      color: #6b7280;
    }
    
    .empty-state i {
      margin-bottom: 1rem;
      opacity: 0.5;
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
    
    .toast.success {
      background: #10b981;
    }
    
    .toast.error {
      background: #ef4444;
    }
    
    .toast.warning {
      background: #f59e0b;
    }
    
    .toast.info {
      background: #3b82f6;
    }

    /* Mobile optimizations */
    @media (max-width: 768px) {
      .sidebar {
        position: fixed;
        height: 100vh;
        z-index: 1000;
        transform: translateX(-100%);
        transition: transform 0.3s ease;
      }
      
      .sidebar.mobile-open {
        transform: translateX(0);
      }
      
      .sidebar-collapsed {
        transform: translateX(-100%);
      }
      
      .sidebar-item:hover .tooltip {
        display: none !important;
      }
      
      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
      }
      
      .dropdown {
        position: fixed;
        left: 50%;
        transform: translateX(-50%);
        width: 90vw;
        max-width: 400px;
      }
    }

    /* Loading states */
    .loading {
      opacity: 0.7;
      pointer-events: none;
    }
    
    .loading::after {
      content: '';
      position: absolute;
      top: 50%;
      left: 50%;
      width: 20px;
      height: 20px;
      margin: -10px 0 0 -10px;
      border: 2px solid #fbbf24;
      border-top: 2px solid transparent;
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }

    /* Compact dashboard styles */
    .compact-card {
      padding: 0.75rem;
    }
    
    .compact-table th,
    .compact-table td {
      padding: 0.5rem;
      font-size: 0.75rem;
    }
    
    .compact-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
      gap: 0.5rem;
    }
    
    .compact-value {
      font-size: 1.25rem;
    }
    
    .compact-title {
      font-size: 0.8rem;
      margin-bottom: 0.25rem;
    }
    
    .section-header {
      margin-bottom: 0.5rem;
    }
  </style>
</head>
<body class="min-h-screen flex flex-col">

  <!-- Toast Notification Container -->
  <div id="toastContainer"></div>

  <!-- Topbar -->
  <header class="topbar flex items-center justify-between px-4 py-3 shadow">
    <div class="flex items-center space-x-3">
      <button id="toggleSidebar" class="text-gray-300 hover:text-yellow-400 transition-colors p-1 rounded-lg hover:bg-white/5">
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
          <span class="absolute -top-1 -right-1 bg-red-500 text-xs rounded-full h-5 w-5 flex items-center justify-center <?php echo (isset($notification_count) && $notification_count > 0) ? '' : 'hidden'; ?>" id="notificationBadge">
            <?php echo (isset($notification_count) && $notification_count > 99) ? '99+' : ($notification_count ?? 0); ?>
          </span>
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
                  <p class="text-sm mt-1">You're all caught up!</p>
                </div>
              <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                  <div class="notification-item" data-notification-id="<?php echo $notification['id']; ?>">
                    <div class="flex items-start gap-3">
                      <div class="flex-shrink-0 mt-1">
                        <?php
                        $icon = 'bell';
                        $color = 'gray-400';
                        switch($notification['type']) {
                          case 'announcement': $icon = 'megaphone'; $color = 'yellow-400'; break;
                          case 'membership': $icon = 'id-card'; $color = 'blue-400'; break;
                          case 'message': $icon = 'message-circle'; $color = 'green-400'; break;
                          case 'system': $icon = 'settings'; $color = 'purple-400'; break;
                          case 'reminder': $icon = 'clock'; $color = 'orange-400'; break;
                        }
                        ?>
                        <i data-lucide="<?php echo $icon; ?>" class="w-4 h-4 text-<?php echo $color; ?>"></i>
                      </div>
                      <div class="flex-1 min-w-0">
                        <div class="flex justify-between items-start mb-1">
                          <p class="text-white font-medium text-sm"><?php echo htmlspecialchars($notification['title']); ?></p>
                          <span class="text-xs text-gray-400 whitespace-nowrap ml-2">
                            <?php
                            $time = strtotime($notification['created_at']);
                            $now = time();
                            $diff = $now - $time;
                            
                            if ($diff < 60) {
                              echo 'Just now';
                            } elseif ($diff < 3600) {
                              echo floor($diff / 60) . 'm ago';
                            } elseif ($diff < 86400) {
                              echo floor($diff / 3600) . 'h ago';
                            } elseif ($diff < 604800) {
                              echo floor($diff / 86400) . 'd ago';
                            } else {
                              echo date('M j, Y', $time);
                            }
                            ?>
                          </span>
                        </div>
                        <p class="text-gray-400 text-xs line-clamp-2"><?php echo htmlspecialchars($notification['message']); ?></p>
                        <?php if ($notification['priority'] === 'high'): ?>
                          <span class="inline-block mt-1 px-2 py-1 bg-red-500/20 text-red-400 text-xs rounded-full">
                            Important
                          </span>
                        <?php endif; ?>
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
          <img src="<?php echo htmlspecialchars($_SESSION['profile_picture'] ?? $user['profile_picture'] ?? 'https://i.pravatar.cc/40'); ?>" class="w-8 h-8 rounded-full border border-gray-600" id="userAvatar" />
          <span class="text-sm font-medium hidden md:inline" id="userName">
            <?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Trainer'); ?>
          </span>
          <i data-lucide="chevron-down" class="w-4 h-4"></i>
        </button>
        
        <!-- User Dropdown Menu -->
        <div id="userDropdown" class="dropdown user-dropdown hidden">
          <div class="p-4 border-b border-gray-700">
            <p class="text-white font-semibold text-sm"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Trainer'); ?></p>
            <p class="text-gray-400 text-xs capitalize"><?php echo $_SESSION['role'] ?? 'Trainer'; ?></p>
          </div>
          <div class="p-2">
            <a href="profile.php" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-300 hover:text-yellow-400 hover:bg-white/5 rounded-lg transition-colors">
              <i data-lucide="user" class="w-4 h-4"></i>
              My Profile
            </a>
            <a href="edit_profile.php" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-300 hover:text-yellow-400 hover:bg-white/5 rounded-lg transition-colors">
              <i data-lucide="edit-2" class="w-4 h-4"></i>
              Edit Profile
            </a>
            <a href="settings.php" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-300 hover:text-yellow-400 hover:bg-white/5 rounded-lg transition-colors">
              <i data-lucide="settings" class="w-4 h-4"></i>
              Settings
            </a>
            <div class="border-t border-gray-700 my-1"></div>
            <a href="logout.php" class="flex items-center gap-2 px-3 py-2 text-sm text-red-400 hover:text-red-300 hover:bg-red-400/10 rounded-lg transition-colors">
              <i data-lucide="log-out" class="w-4 h-4"></i>
              Logout
            </a>
          </div>
        </div>
      </div>
    </div>
  </header>
