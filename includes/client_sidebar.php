<!-- Desktop Sidebar - Only for desktop -->
<aside id="desktopSidebar" class="desktop-sidebar sidebar w-60 bg-[#0d0d0d] p-3 space-y-2 flex flex-col border-r border-gray-800" aria-label="Main navigation">
  <nav class="space-y-1 flex-1">
    <a href="client_dashboard.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'client_dashboard.php' ? 'active' : ''; ?>">
      <div class="flex items-center">
        <i data-lucide="home"></i>
        <span class="text-sm font-medium">Dashboard</span>
      </div>
      <span class="tooltip">Dashboard</span>
    </a>

    <!-- Workout Plans Section - Available for all clients -->
    <div>
      <div id="workoutToggle" class="sidebar-item <?php echo in_array(basename($_SERVER['PHP_SELF']), ['workoutplansclient.php', 'workoutprogress.php']) ? 'active' : ''; ?>">
        <div class="flex items-center">
          <i data-lucide="dumbbell"></i>
          <span class="text-sm font-medium">Workout Plans</span>
        </div>
        <i id="workoutChevron" data-lucide="chevron-right" class="chevron <?php echo in_array(basename($_SERVER['PHP_SELF']), ['workoutplansclient.php', 'workoutprogress.php']) ? 'rotate' : ''; ?>"></i>
        <span class="tooltip">Workout Plans</span>
      </div>
      <div id="workoutSubmenu" class="submenu space-y-1 <?php echo in_array(basename($_SERVER['PHP_SELF']), ['workoutplansclient.php', 'workoutprogress.php']) ? 'open' : ''; ?>">
        <a href="workoutplansclient.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'workoutplansclient.php' ? 'text-yellow-400 bg-white/5' : ''; ?>"><i data-lucide="list"></i> My Workout Plans</a>
        <a href="workoutprogress.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'workoutprogress.php' ? 'text-yellow-400 bg-white/5' : ''; ?>"><i data-lucide="activity"></i> Workout Progress</a>
      </div>
    </div>

    <!-- Nutrition Plans Section - Available for all clients -->
    <div>
      <div id="nutritionToggle" class="sidebar-item <?php echo in_array(basename($_SERVER['PHP_SELF']), ['nutritionplansclient.php', 'nutritiontracking.php']) ? 'active' : ''; ?>">
        <div class="flex items-center">
          <i data-lucide="utensils"></i>
          <span class="text-sm font-medium">Nutrition Plans</span>
        </div>
        <i id="nutritionChevron" data-lucide="chevron-right" class="chevron <?php echo in_array(basename($_SERVER['PHP_SELF']), ['nutritionplansclient.php', 'nutritiontracking.php']) ? 'rotate' : ''; ?>"></i>
        <span class="tooltip">Nutrition Plans</span>
      </div>
      <div id="nutritionSubmenu" class="submenu space-y-1 <?php echo in_array(basename($_SERVER['PHP_SELF']), ['nutritionplansclient.php', 'nutritiontracking.php']) ? 'open' : ''; ?>">
        <a href="nutritionplansclient.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'nutritionplansclient.php' ? 'text-yellow-400 bg-white/5' : ''; ?>"><i data-lucide="list"></i> My Meal Plans</a>
        <a href="nutritiontracking.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'nutritiontracking.php' ? 'text-yellow-400 bg-white/5' : ''; ?>"><i data-lucide="chart-bar"></i> Nutrition Tracking</a>
      </div>
    </div>

    <!-- Progress Tracking - Available for all clients -->
    <a href="myprogressclient.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'myprogressclient.php' ? 'active' : ''; ?>">
      <div class="flex items-center">
        <i data-lucide="activity"></i>
        <span class="text-sm font-medium">My Progress</span>
      </div>
      <span class="tooltip">My Progress</span>
    </a>

    <!-- Attendance - Available for all -->
    <a href="attendanceclient.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'attendanceclient.php' ? 'active' : ''; ?>">
      <div class="flex items-center">
        <i data-lucide="calendar"></i>
        <span class="text-sm font-medium">Attendance</span>
      </div>
      <span class="tooltip">Attendance</span>
    </a>

    <!-- Membership - Available for all -->
    <a href="membershipclient.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'membershipclient.php' ? 'active' : ''; ?>">
      <div class="flex items-center">
        <i data-lucide="id-card"></i>
        <span class="text-sm font-medium">Membership</span>
      </div>
      <span class="tooltip">Membership</span>
    </a>

    <!-- Feedbacks - Available for all -->
    <a href="feedbacksclient.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) == 'feedbacksclient.php' ? 'active' : ''; ?>">
      <div class="flex items-center">
        <i data-lucide="message-square"></i>
        <span class="text-sm font-medium">Send Feedback</span>
      </div>
      <span class="tooltip">Send Feedback</span>
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
