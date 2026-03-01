with open('trainer_dashboard.php', 'r') as f:
    lines = f.readlines()

new_lines = lines[:338]
new_lines.append("<?php require_once 'includes/trainer_header.php'; ?>\n")
new_lines.append("<?php require_once 'includes/trainer_sidebar.php'; ?>\n")
new_lines.extend(lines[1391:1670])

footer_script = """
<script>
    // Auto-refresh attendance data every 2 minutes
    function setupAutoRefresh() {
        setInterval(() => {
            if (document.visibilityState === 'visible') {
                refreshAttendanceData();
            }
        }, 120000);
    }

    // Refresh only attendance data (lighter than full refresh)
    function refreshAttendanceData() {
        fetch('attendance_ajax.php?action=get_today_count')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const countEl = document.getElementById('attendanceCount');
                    if (countEl) {
                        countEl.textContent = data.count;
                        countEl.classList.add('text-green-400');
                        setTimeout(() => countEl.classList.remove('text-green-400'), 1000);
                    }
                }
            })
            .catch(error => console.error('Error refreshing attendance:', error));
    }

    // Initialize charts and functionality
    document.addEventListener('DOMContentLoaded', function() {
      // Attendance Chart with Real Data
      const ctx = document.getElementById('attendanceChart');
      if (ctx) {
        new Chart(ctx.getContext('2d'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($weeklyData['labels']); ?>,
                datasets: [{
                    label: 'Daily Check-ins',
                    data: <?php echo json_encode($weeklyData['data']); ?>,
                    borderColor: '#fbbf24',
                    backgroundColor: 'rgba(251, 191, 36, 0.15)',
                    fill: true,
                    tension: 0.4,
                    borderWidth: 3,
                    pointBackgroundColor: '#fbbf24',
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    pointBorderColor: '#0f172a',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { 
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(13, 13, 13, 0.9)',
                        titleColor: '#fbbf24',
                        bodyColor: '#e2e8f0',
                        borderColor: 'rgba(251, 191, 36, 0.2)',
                        borderWidth: 1,
                        padding: 12,
                        boxPadding: 6,
                        callbacks: {
                            title: function(context) {
                                return context[0].label + ' Attendance';
                            },
                            label: function(context) {
                                return `Check-ins: ${context.parsed.y}`;
                            }
                        }
                    }
                },
                scales: {
                    x: { 
                        ticks: { color: '#94a3b8', font: { size: 11 } }, 
                        grid: { color: 'rgba(255, 255, 255, 0.05)' } 
                    },
                    y: { 
                        ticks: { color: '#94a3b8', font: { size: 11 } }, 
                        grid: { color: 'rgba(255, 255, 255, 0.05)' }, 
                        beginAtZero: true,
                        precision: 0
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                }
            }
        });
      }

      // Prevent double form submission
      setupFormSubmissionProtection();

      // Setup refresh button
      const refreshBtn = document.getElementById('refreshData');
      if (refreshBtn) {
          refreshBtn.addEventListener('click', refreshDashboardData);
      }

      // Setup auto-refresh
      setupAutoRefresh();
    });

    // Refresh dashboard data
    function refreshDashboardData() {
        const refreshBtn = document.getElementById('refreshData');
        setLoadingState(refreshBtn, true);
        
        showToast('Refreshing data...', 'info', 2000);
        
        setTimeout(() => {
            window.location.reload();
        }, 1000);
    }

    // Prevent double form submissions
    function setupFormSubmissionProtection() {
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    setLoadingState(submitBtn, true);
                    submitBtn.innerHTML = '<i data-lucide="loader" class="animate-spin"></i> Processing...';
                    lucide.createIcons();
                    
                    setTimeout(() => {
                        setLoadingState(submitBtn, false);
                        submitBtn.innerHTML = '<i data-lucide="log-in"></i> Check In Member';
                        lucide.createIcons();
                    }, 5000);
                }
            });
        });
    }

    // Section navigation
    function showSection(sectionId) {
      document.querySelectorAll('.section').forEach(section => {
        section.classList.remove('active');
      });
      
      const targetSection = document.getElementById(sectionId);
      if (targetSection) targetSection.classList.add('active');
      
      document.querySelectorAll('.sidebar-item').forEach(item => {
        item.classList.remove('active');
      });
      
      if (sectionId === 'dashboard') {
        const dashItem = document.querySelector('.sidebar-item');
        if (dashItem) dashItem.classList.add('active');
      } else {
        const sidebarItem = document.querySelector(`.sidebar-item[onclick="showSection('${sectionId}')"]`);
        if (sidebarItem) {
          sidebarItem.classList.add('active');
        }
      }
      
      if (sectionId !== 'dashboard') {
        const membersSubmenu = document.getElementById('membersSubmenu');
        const membersChevron = document.getElementById('membersChevron');
        if (membersSubmenu) membersSubmenu.classList.remove('open');
        if (membersChevron) membersChevron.classList.remove('rotate');
      }
      
      if (window.innerWidth <= 768) {
        const sidebar = document.getElementById('sidebar');
        if (sidebar) sidebar.classList.remove('mobile-open');
      }
    }

    // Listen for QR scan success events
    window.addEventListener('qrScanSuccess', function(event) {
        setTimeout(() => {
            refreshDashboardData();
        }, 2000);
    });
</script>
<?php require_once 'includes/trainer_footer.php'; ?>
<?php $conn->close(); ?>
"""

new_lines.append(footer_script)

with open('trainer_dashboard.php', 'w') as f:
    f.writelines(new_lines)
