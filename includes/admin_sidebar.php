<!-- Sidebar -->
<aside id="sidebar" class="sidebar w-60 bg-[#0d0d0d] h-screen p-3 space-y-2 flex flex-col overflow-y-auto border-r border-gray-800">
  <nav class="space-y-1 flex-1">
    <a href="admin_dashboard.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php' ? 'active' : ''; ?>">
      <div class="flex items-center">
        <i data-lucide="home"></i>
        <span class="text-sm font-medium">Dashboard</span>
      </div>
      <span class="tooltip">Dashboard</span>
    </a>

    <a href="view_users.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'view_users.php' ? 'active' : ''; ?>">
      <div class="flex items-center">
        <i data-lucide="users"></i>
        <span class="text-sm font-medium">View All Users</span>
      </div>
      <span class="tooltip">View All Users</span>
    </a>

    <a href="revenue.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'revenue.php' ? 'active' : ''; ?>">
      <div class="flex items-center">
        <i data-lucide="dollar-sign"></i>
        <span class="text-sm font-medium">Revenue Tracking</span>
      </div>
      <span class="tooltip">Revenue Tracking</span>
    </a>

    <a href="products.php" class="sidebar-item <?php echo strpos(basename($_SERVER['PHP_SELF']), 'product') !== false ? 'active' : ''; ?>">
      <div class="flex items-center">
        <i data-lucide="package"></i>
        <span class="text-sm font-medium">Products & Inventory</span>
      </div>
      <span class="tooltip">Products & Inventory</span>
    </a>

    <a href="adminannouncement.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'adminannouncement.php' ? 'active' : ''; ?>">
      <div class="flex items-center">
        <i data-lucide="megaphone"></i>
        <span class="text-sm font-medium">Announcements</span>
      </div>
      <span class="tooltip">Announcements</span>
    </a>

    <a href="equipment_monitoring.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'equipment_monitoring.php' ? 'active' : ''; ?>">
      <div class="flex items-center">
        <i data-lucide="wrench"></i>
        <span class="text-sm font-medium">Equipment Monitoring</span>
      </div>
      <span class="tooltip">Equipment Monitoring</span>
    </a>

    <a href="maintenance_report.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'maintenance_report.php' ? 'active' : ''; ?>">
      <div class="flex items-center">
        <i data-lucide="alert-triangle"></i>
        <span class="text-sm font-medium">Maintenance Report</span>
      </div>
      <span class="tooltip">Maintenance Report</span>
    </a>

    <a href="feedbacksadmin.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'feedbacksadmin.php' ? 'active' : ''; ?>">
      <div class="flex items-center">
        <i data-lucide="message-square"></i>
        <span class="text-sm font-medium">Feedback & Reports</span>
      </div>
      <span class="tooltip">Feedback & Reports</span>
    </a>

    <div class="pt-4 border-t border-gray-800 mt-auto">
      <a href="logout.php" class="sidebar-item">
        <div class="flex items-center">
          <i data-lucide="log-out"></i>
          <span class="text-sm font-medium">Logout</span>
        </div>
        <span class="tooltip">Logout</span>
      </a>
    </div>
  </nav>
</aside>
