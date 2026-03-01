<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'trainer')) {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';
require_once 'chat_functions.php';

// Initialize unread count after chat functions are included and connection is established
$unread_count = getUnreadCount($_SESSION['user_id'], $conn);

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$category_filter = $_GET['category'] ?? '';
$export = $_GET['export'] ?? '';

// Build query for equipment needing attention
$where_conditions = ["e.status IN ('Needs Maintenance', 'Under Repair', 'Broken')"];
$params = [];
$types = "";

if ($status_filter) {
    $where_conditions[] = "e.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($category_filter) {
    $where_conditions[] = "e.category = ?";
    $params[] = $category_filter;
    $types .= "s";
}

$where_sql = implode(" AND ", $where_conditions);
// Get maintenance equipment - SIMPLIFIED
$maintenance_sql = "SELECT e.*, u.username as updated_by_name,
                    el.note as last_note, el.date_updated as last_log_date
                    FROM equipment e 
                    LEFT JOIN users u ON e.created_by = u.id 
                    LEFT JOIN equipment_logs el ON e.id = el.equipment_id 
                    WHERE $where_sql 
                    ORDER BY e.last_updated DESC, e.name ASC";

$maintenance_stmt = $conn->prepare($maintenance_sql);
if ($maintenance_stmt) {
    if (!empty($params)) {
        $maintenance_stmt->bind_param($types, ...$params);
    }
    $maintenance_stmt->execute();
    $maintenance_result = $maintenance_stmt->get_result();
} else {
    $maintenance_result = false;
}


// Get facilities needing attention - FIXED: using correct column name 'facility_condition'
$facilities_sql = "SELECT f.*, u.username as updated_by_name 
                   FROM facilities f 
                   LEFT JOIN users u ON f.updated_by = u.id 
                   WHERE f.facility_condition IN ('Needs Maintenance', 'Under Repair', 'Closed')
                   ORDER BY f.name ASC";
$facilities_stmt = $conn->prepare($facilities_sql);
$facilities_stmt->execute();
$facilities_result = $facilities_stmt->get_result();

// Get unique categories for filter
$cat_stmt = $conn->prepare("SELECT DISTINCT category FROM equipment ORDER BY category");
$cat_stmt->execute();
$categories_result = $cat_stmt->get_result();

// Get maintenance statistics
$stats_sql = "SELECT 
                COUNT(*) as total_issues,
                SUM(CASE WHEN status = 'Needs Maintenance' THEN 1 ELSE 0 END) as needs_maintenance,
                SUM(CASE WHEN status = 'Under Repair' THEN 1 ELSE 0 END) as under_repair,
                SUM(CASE WHEN status = 'Broken' THEN 1 ELSE 0 END) as broken
              FROM equipment 
              WHERE status IN ('Needs Maintenance', 'Under Repair', 'Broken')";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();

// Handle export
if ($export == 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="maintenance_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Equipment Name', 'Category', 'Location', 'Status', 'Last Updated', 'Note']);
    
    // Re-execute the main equipment query for export
    // The $maintenance_stmt already has the correct query and parameters bound.
    // We just need to reset and re-fetch.
    $maintenance_stmt->execute();
    $export_result = $maintenance_stmt->get_result();
    
    while($row = $export_result->fetch_assoc()) {
        fputcsv($output, [
            $row['name'],
            $row['category'],
            $row['location'],
            $row['status'],
            $row['last_updated'],
            $row['notes'] ?: 'No notes'
        ]);
    }
    fclose($output);
    exit();
}

$username = $_SESSION['username'] ?? 'User';
$role = $_SESSION['role'] ?? 'trainer';
?>

<?php
// Include Header and dynamically include Sidebar based on role
require_once 'includes/admin_header.php';
if ($_SESSION['role'] === 'admin') {
    require_once 'includes/admin_sidebar.php';
} else {
    require_once 'includes/trainer_sidebar.php';
}
?>

  <div class="flex">
    <!-- Main Content -->
    <main class="flex-1 p-4 space-y-4 overflow-auto">
      <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-yellow-400 flex items-center gap-2">
          <i data-lucide="clipboard-list"></i>
          Maintenance Report
        </h1>
        <div class="text-sm text-gray-400">
          Welcome back, <?php echo htmlspecialchars($username); ?>
        </div>
      </div>

      <!-- Maintenance Statistics -->
      <div class="stats-grid mb-8">
        <div class="card expense-card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="alert-triangle"></i><span>Total Issues</span></p>
              <p class="card-value"><?php echo $stats['total_issues']; ?></p>
              <p class="text-xs text-gray-400">All maintenance items</p>
            </div>
            <div class="p-3 bg-red-500/10 rounded-lg">
              <i data-lucide="alert-triangle" class="w-6 h-6 text-red-400"></i>
            </div>
          </div>
        </div>

        <div class="card expense-card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="tool"></i><span>Needs Maintenance</span></p>
              <p class="card-value"><?php echo $stats['needs_maintenance']; ?></p>
              <p class="text-xs text-gray-400">Requires attention</p>
            </div>
            <div class="p-3 bg-red-500/10 rounded-lg">
              <i data-lucide="tool" class="w-6 h-6 text-red-400"></i>
            </div>
          </div>
        </div>

        <div class="card membership-card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="wrench"></i><span>Under Repair</span></p>
              <p class="card-value"><?php echo $stats['under_repair']; ?></p>
              <p class="text-xs text-gray-400">Being fixed</p>
            </div>
            <div class="p-3 bg-blue-500/10 rounded-lg">
              <i data-lucide="wrench" class="w-6 h-6 text-blue-400"></i>
            </div>
          </div>
        </div>

        <div class="card stat-card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="x-circle"></i><span>Broken</span></p>
              <p class="card-value"><?php echo $stats['broken']; ?></p>
              <p class="text-xs text-gray-400">Out of service</p>
            </div>
            <div class="p-3 bg-yellow-500/10 rounded-lg">
              <i data-lucide="x-circle" class="w-6 h-6 text-yellow-400"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Action Bar -->
      <div class="card">
        <div class="flex flex-wrap gap-2 justify-between mb-4">
          <div class="flex gap-2">
            <!-- Export Dropdown -->
            <div class="export-container">
              <button id="exportButton" class="button-sm btn-outline">
                <i data-lucide="download"></i> Export Report
                <i data-lucide="chevron-down" class="w-4 h-4"></i>
              </button>
              <div id="exportDropdown" class="export-dropdown">
                <div class="dropdown-header">
                  <h3>Export Options</h3>
                  <p>Choose export format</p>
                </div>
                <a href="?export=csv&status=<?php echo $status_filter; ?>&category=<?php echo $category_filter; ?>" class="export-option">
                  <i data-lucide="file-spreadsheet"></i> Export as CSV
                </a>
              </div>
            </div>
          </div>
          <div class="flex gap-2">
            <!-- Quick Status Filters -->
            <a href="?status=" class="button-sm <?php echo empty($status_filter) ? 'btn-active' : 'btn-outline'; ?>">
              All Issues
            </a>
            <a href="?status=Needs Maintenance" class="button-sm <?php echo $status_filter == 'Needs Maintenance' ? 'btn-active' : 'btn-outline'; ?>">
              Maintenance
            </a>
            <a href="?status=Under Repair" class="button-sm <?php echo $status_filter == 'Under Repair' ? 'btn-active' : 'btn-outline'; ?>">
              Under Repair
            </a>
            <a href="?status=Broken" class="button-sm <?php echo $status_filter == 'Broken' ? 'btn-active' : 'btn-outline'; ?>">
              Broken
            </a>
          </div>
        </div>

        <!-- Filters -->
        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4" id="filterForm">
          <div>
            <label class="form-label">Category</label>
            <select name="category" class="form-input">
              <option value="">All Categories</option>
              <?php 
              $categories_result->data_seek(0); // Reset pointer
              while($cat = $categories_result->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($cat['category']); ?>" <?php echo $category_filter == $cat['category'] ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($cat['category']); ?>
                </option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="flex items-end">
            <button type="submit" class="button-sm btn-primary w-full">
              <i data-lucide="filter"></i> Apply Filters
            </button>
          </div>
         <div class="flex items-end">
  <a href="maintenance_report.php" class="button-sm btn-outline w-full">
    <i data-lucide="refresh-cw"></i> Clear Filters
  </a>
</div>
        </form>
      </div>

<!-- Section Header -->
<div class="flex items-center gap-2 mb-4">
  <div class="h-0.5 flex-1 bg-gray-700"></div>
  <span class="text-sm font-semibold text-yellow-400 px-4">EQUIPMENT MAINTENANCE</span>
  <div class="h-0.5 flex-1 bg-gray-700"></div>
</div>
      <!-- Equipment Maintenance Section -->
      <div class="card">
        <h2 class="text-lg font-semibold text-yellow-400 mb-4 flex items-center gap-2">
          <i data-lucide="dumbbell"></i>
          Equipment Needing Attention (<?php echo $maintenance_result ? $maintenance_result->num_rows : 0; ?>)
        </h2>
        
        <?php if ($maintenance_result && $maintenance_result->num_rows > 0): ?>
          <div class="overflow-x-auto">
            <table class="w-full text-sm text-left text-gray-400">
              <thead class="text-xs text-gray-400 uppercase bg-gray-800">
                <tr>
                  <th class="px-4 py-3">Equipment</th>
                  <th class="px-4 py-3">Category</th>
                  <th class="px-4 py-3">Location</th>
                  <th class="px-4 py-3">Status</th>
                  <th class="px-4 py-3">Priority</th> <!-- ADD THIS COLUMN -->
                  <th class="px-4 py-3">Last Updated</th>
                  <th class="px-4 py-3">Issue Details</th>
                  <th class="px-4 py-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php while($equipment = $maintenance_result->fetch_assoc()): ?>
                <tr class="border-b border-gray-700 hover:bg-gray-800">
                  <td class="px-4 py-3">
                    <div class="font-medium text-white"><?php echo htmlspecialchars($equipment['name']); ?></div>
                    <?php if (!empty($equipment['notes'])): ?>
                      <div class="text-xs text-gray-400 mt-1"><?php echo htmlspecialchars($equipment['notes']); ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="px-4 py-3"><?php echo htmlspecialchars($equipment['category']); ?></td>
                  <td class="px-4 py-3"><?php echo htmlspecialchars($equipment['location']); ?></td>
                  <td class="px-4 py-3">
  <?php
    $status_class = '';
    switch($equipment['status']) {
      case 'Needs Maintenance': $status_class = 'badge-needs-maintenance'; break;
      case 'Under Repair': $status_class = 'badge-under-repair'; break;
      case 'Broken': $status_class = 'badge-broken'; break;
    }
  ?>
  <span class="badge <?php echo $status_class; ?>">
    <span class="status-indicator status-<?php echo strtolower(str_replace(' ', '-', $equipment['status'])); ?>"></span>
    <?php echo htmlspecialchars($equipment['status']); ?>
  </span>
</td>

<!-- ADD PRIORITY COLUMN HERE -->
<td class="px-4 py-3">
  <?php
    $priority = '';
    $priority_class = '';
    if ($equipment['status'] == 'Broken') {
      $priority = 'High';
      $priority_class = 'badge-broken';
    } elseif ($equipment['status'] == 'Under Repair') {
      $priority = 'Medium';
      $priority_class = 'badge-under-repair';
    } else {
      $priority = 'Low';
      $priority_class = 'badge-needs-maintenance';
    }
  ?>
  <span class="badge <?php echo $priority_class; ?>">
    <?php echo $priority; ?>
  </span>
</td>
<!-- END PRIORITY COLUMN -->

<td class="px-4 py-3 whitespace-nowrap">
  <?php echo date('M j, Y g:i A', strtotime($equipment['last_updated'])); ?>
  <?php if (!empty($equipment['updated_by_name'])): ?>
    <div class="text-xs text-gray-400">by <?php echo htmlspecialchars($equipment['updated_by_name']); ?></div>
  <?php endif; ?>
</td>
                  <td class="px-4 py-3">
                    <?php 
                      $issue_note = !empty($equipment['last_note']) ? $equipment['last_note'] : $equipment['notes'];
                      echo $issue_note ? htmlspecialchars($issue_note) : '<span class="text-gray-500">No details</span>';
                    ?>
                  </td>
                  <td class="px-4 py-3">
                    <a href="equipment_monitoring.php?tab=equipment" class="text-blue-400 hover:text-blue-300 transition-colors" title="Update Status">
                      <i data-lucide="edit" class="w-4 h-4"></i>
                    </a>
                  </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="empty-state">
            <i data-lucide="check-circle" class="w-12 h-12 mx-auto text-green-400"></i>
            <p>No maintenance issues found!</p>
            <p class="text-sm mt-2">All equipment is in good condition</p>
          </div>
        <?php endif; ?>
      </div>
<!-- Section Header -->
<div class="flex items-center gap-2 mb-4 mt-8">
  <div class="h-0.5 flex-1 bg-gray-700"></div>
  <span class="text-sm font-semibold text-yellow-400 px-4">FACILITY MAINTENANCE</span>
  <div class="h-0.5 flex-1 bg-gray-700"></div>
</div>
      <!-- Facilities Maintenance Section -->
      <div class="card">
        <h2 class="text-lg font-semibold text-yellow-400 mb-4 flex items-center gap-2">
          <i data-lucide="building"></i>
          Facilities Needing Attention (<?php echo $facilities_result ? $facilities_result->num_rows : 0; ?>)
        </h2>
        
        <?php if ($facilities_result && $facilities_result->num_rows > 0): ?>
          <div class="overflow-x-auto">
            <table class="w-full text-sm text-left text-gray-400">
              <thead class="text-xs text-gray-400 uppercase bg-gray-800">
                <tr>
                  <th class="px-4 py-3">Facility</th>
                  <th class="px-4 py-3">Condition</th>
                  <th class="px-4 py-3">Issue Details</th>
                  <th class="px-4 py-3">Last Updated</th>
                  <th class="px-4 py-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php while($facility = $facilities_result->fetch_assoc()): ?>
                <tr class="border-b border-gray-700 hover:bg-gray-800">
                  <td class="px-4 py-3 font-medium text-white"><?php echo htmlspecialchars($facility['name']); ?></td>
                 <td class="px-4 py-3">
    <?php
        $condition_class = '';
        switch($facility['facility_condition']) {  // ← Changed to 'facility_condition'
          case 'Needs Maintenance': $condition_class = 'badge-needs-maintenance'; break;
          case 'Under Repair': $condition_class = 'badge-under-repair'; break;
          case 'Closed': $condition_class = 'badge-closed'; break;
          case 'Good': $condition_class = 'badge-good'; break;  // Added for completeness
        }
    ?>
    <span class="badge <?php echo $condition_class; ?>">
        <span class="status-indicator status-<?php echo strtolower(str_replace(' ', '-', $facility['facility_condition'])); ?>"></span>
        <?php echo htmlspecialchars($facility['facility_condition']); ?>
    </span>
</td>
                  <td class="px-4 py-3">
                    <?php echo !empty($facility['notes']) ? htmlspecialchars($facility['notes']) : '<span class="text-gray-500">No details</span>'; ?>
                  </td>
                  <td class="px-4 py-3 whitespace-nowrap">
                    <?php echo date('M j, Y g:i A', strtotime($facility['last_updated'])); ?>
                    <?php if (!empty($facility['updated_by_name'])): ?>
                      <div class="text-xs text-gray-400">by <?php echo htmlspecialchars($facility['updated_by_name']); ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="px-4 py-3">
                    <a href="equipment_monitoring.php?tab=facilities" class="text-blue-400 hover:text-blue-300 transition-colors" title="Update Condition">
                      <i data-lucide="edit" class="w-4 h-4"></i>
                    </a>
                  </td>
                </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
       <?php else: ?>
  <div class="empty-state">
    <i data-lucide="check-circle" class="w-12 h-12 mx-auto text-green-400"></i>
    <p>All facilities are operational!</p>
    <p class="text-sm mt-2">No maintenance issues reported</p>
  </div>
<?php endif; ?>
      </div>

    </main>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Export dropdown functionality
        const exportButton = document.getElementById('exportButton');
        const exportDropdown = document.getElementById('exportDropdown');

        if (exportButton && exportDropdown) {
            exportButton.addEventListener('click', (e) => {
                e.stopPropagation();
                exportDropdown.classList.toggle('show');
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', (e) => {
                if (!exportButton.contains(e.target) && !exportDropdown.contains(e.target)) {
                    exportDropdown.classList.remove('show');
                }
            });
        }
    });
  </script>

<?php require_once 'includes/admin_footer.php'; ?>

<?php 
// Close statements and connection properly
if (isset($maintenance_stmt)) $maintenance_stmt->close();
if (isset($maintenance_result)) $maintenance_result->free();
if (isset($facilities_result)) $facilities_result->free();
if (isset($stats_result)) $stats_result->free();
if (isset($categories_result)) $categories_result->free();
if (isset($conn)) $conn->close(); 
?>
