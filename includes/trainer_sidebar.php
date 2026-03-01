<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<aside id="sidebar" class="sidebar w-60 bg-[#0d0d0d] h-screen p-3 space-y-2 flex flex-col overflow-y-auto border-r border-gray-800">
  <nav class="space-y-1 flex-1">
    <a href="trainer_dashboard.php" class="sidebar-item <?php echo $current_page == 'trainer_dashboard.php' ? 'active' : ''; ?>">
      <div class="flex items-center">
        <i data-lucide="home"></i>
        <span class="text-sm font-medium">Dashboard</span>
      </div>
      <span class="tooltip">Dashboard</span>
    </a>

    <!-- Members Section -->
    <div>
      <div id="membersToggle" class="sidebar-item <?php echo in_array($current_page, ['member_registration.php', 'membership_status.php', 'all_members.php']) ? 'active' : ''; ?>">
        <div class="flex items-center">
          <i data-lucide="users"></i>
          <span class="text-sm font-medium">Members</span>
        </div>
        <i id="membersChevron" data-lucide="chevron-right" class="chevron <?php echo in_array($current_page, ['member_registration.php', 'membership_status.php', 'all_members.php']) ? 'rotate' : ''; ?>"></i>
        <span class="tooltip">Members</span>
      </div>
      <div id="membersSubmenu" class="submenu <?php echo in_array($current_page, ['member_registration.php', 'membership_status.php', 'all_members.php']) ? 'open' : ''; ?> space-y-1">
        <a href="member_registration.php" class="<?php echo $current_page == 'member_registration.php' ? 'active text-yellow-400' : ''; ?>"><i data-lucide="user-plus"></i> Member Registration</a>
        <a href="membership_status.php" class="<?php echo $current_page == 'membership_status.php' ? 'active text-yellow-400' : ''; ?>"><i data-lucide="id-card"></i> Membership Status</a>
        <a href="all_members.php" class="<?php echo $current_page == 'all_members.php' ? 'active text-yellow-400' : ''; ?>"><i data-lucide="list"></i> All Members</a>
      </div>
    </div>

    <!-- Client Progress -->
    <a href="clientprogress.php" class="sidebar-item <?php echo $current_page == 'clientprogress.php' ? 'active' : ''; ?>">
      <div class="flex items-center">
        <i data-lucide="activity"></i>
        <span class="text-sm font-medium">Client Progress</span>
      </div>
      <span class="tooltip">Client Progress</span>
    </a>

    <!-- Counter Sales -->
    <a href="countersales.php" class="sidebar-item <?php echo $current_page == 'countersales.php' ? 'active' : ''; ?>">
      <div class="flex items-center">
        <i data-lucide="package"></i>
        <span class="text-sm font-medium">Counter Sales</span>
      </div>
      <span class="tooltip">Counter Sales</span>
    </a>

    <!-- Equipment Monitoring -->
    <a href="trainer_equipment_monitoring.php" class="sidebar-item <?php echo $current_page == 'trainer_equipment_monitoring.php' ? 'active' : ''; ?>">
      <div class="flex items-center">
        <i data-lucide="wrench"></i>
        <span class="text-sm font-medium">Equipment Monitoring</span>
      </div>
      <span class="tooltip">Equipment Monitoring</span>
    </a>

    <!-- Maintenance Report -->
    <a href="trainer_maintenance_report.php" class="sidebar-item <?php echo $current_page == 'trainer_maintenance_report.php' ? 'active' : ''; ?>">
      <div class="flex items-center">
        <i data-lucide="alert-triangle"></i>
        <span class="text-sm font-medium">Maintenance Report</span>
      </div>
      <span class="tooltip">Maintenance Report</span>
    </a>

    <!-- Feedbacks -->
    <a href="feedbackstrainer.php" class="sidebar-item <?php echo $current_page == 'feedbackstrainer.php' ? 'active' : ''; ?>">
      <div class="flex items-center">
        <i data-lucide="message-square"></i>
        <span class="text-sm font-medium">Feedbacks</span>
      </div>
      <span class="tooltip">Feedbacks</span>
    </a>

    <!-- Attendance Logs -->
    <a href="attendance_logs.php" class="sidebar-item <?php echo $current_page == 'attendance_logs.php' ? 'active' : ''; ?>">
      <div class="flex items-center">
        <i data-lucide="calendar"></i>
        <span class="text-sm font-medium">Attendance Logs</span>
      </div>
      <span class="tooltip">Attendance Logs</span>
    </a>

    <!-- QR Code Management -->
    <a href="trainermanageqrcodes.php" class="sidebar-item <?php echo $current_page == 'trainermanageqrcodes.php' ? 'active' : ''; ?>">
      <div class="flex items-center">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M4 4H8V8H4V4ZM10 4H14V8H10V4ZM16 4H20V8H16V4ZM4 10H8V14H4V10ZM10 10H14V14H10V10ZM16 10H20V14H16V10ZM4 16H8V20H4V16ZM10 16H14V20H10V16ZM16 16H20V20H16V16Z" 
                fill="currentColor"/>
          <rect x="2" y="2" width="8" height="8" rx="1.5" stroke="currentColor" stroke-width="1.5" fill="none"/>
          <rect x="14" y="2" width="8" height="8" rx="1.5" stroke="currentColor" stroke-width="1.5" fill="none"/>
          <rect x="2" y="14" width="8" height="8" rx="1.5" stroke="currentColor" stroke-width="1.5" fill="none"/>
        </svg>
        <span class="text-sm font-medium">QR Code Management</span>
      </div>
      <span class="tooltip">Manage Client QR Codes</span>
    </a>

    <!-- Training Plans Section -->
    <div>
      <div id="plansToggle" class="sidebar-item <?php echo in_array($current_page, ['trainerworkout.php', 'trainermealplan.php']) ? 'active' : ''; ?>">
        <div class="flex items-center">
          <i data-lucide="clipboard-list"></i>
          <span class="text-sm font-medium">Training Plans</span>
        </div>
        <i id="plansChevron" data-lucide="chevron-right" class="chevron <?php echo in_array($current_page, ['trainerworkout.php', 'trainermealplan.php']) ? 'rotate' : ''; ?>"></i>
        <span class="tooltip">Training Plans</span>
      </div>
      <div id="plansSubmenu" class="submenu <?php echo in_array($current_page, ['trainerworkout.php', 'trainermealplan.php']) ? 'open' : ''; ?> space-y-1">
        <a href="trainerworkout.php" class="<?php echo $current_page == 'trainerworkout.php' ? 'active text-yellow-400' : ''; ?>"><i data-lucide="dumbbell"></i> Workout Plans</a>
        <a href="trainermealplan.php" class="<?php echo $current_page == 'trainermealplan.php' ? 'active text-yellow-400' : ''; ?>"><i data-lucide="utensils"></i> Meal Plans</a>
      </div>
    </div>

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
