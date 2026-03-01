<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'trainer') {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';
require_once 'chat_functions.php';
require_once 'notification_functions.php';

$unread_count = getUnreadCount($_SESSION['user_id'], $conn);

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle equipment status updates
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_equipment_status'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = "Security token invalid. Please try again.";
        header("Location: trainer_equipment_monitoring.php");
        exit();
    }

    $equipment_id = (int)$_POST['equipment_id'];
    $new_status = $_POST['status'];
    $note = trim($_POST['note']);
    $updated_by = $_SESSION['user_id'];

    // Get current status
    $current_sql = "SELECT status FROM equipment WHERE id = ?";
    $current_stmt = $conn->prepare($current_sql);
    
    if (!$current_stmt) {
        $_SESSION['error'] = "SQL prepare failed: " . $conn->error;
        header("Location: trainer_equipment_monitoring.php");
        exit();
    }
    
    $current_stmt->bind_param("i", $equipment_id);
    
    if (!$current_stmt->execute()) {
        $_SESSION['error'] = "Error fetching current equipment status: " . $current_stmt->error;
        header("Location: trainer_equipment_monitoring.php");
        exit();
    }
    
    $current_result = $current_stmt->get_result();
    
    if ($current_result->num_rows > 0) {
        $current_data = $current_result->fetch_assoc();
        $old_status = $current_data['status'];

        // Update equipment status
        $update_sql = "UPDATE equipment SET status = ?, last_updated = NOW() WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        
        if (!$update_stmt) {
            $_SESSION['error'] = "SQL prepare failed: " . $conn->error;
            header("Location: trainer_equipment_monitoring.php");
            exit();
        }
        
        $update_stmt->bind_param("si", $new_status, $equipment_id);
        
        if ($update_stmt->execute()) {
            // Log the change
            $log_sql = "INSERT INTO equipment_logs (equipment_id, old_status, new_status, updated_by, note) VALUES (?, ?, ?, ?, ?)";
            $log_stmt = $conn->prepare($log_sql);
            
            if (!$log_stmt) {
                $_SESSION['error'] = "Log SQL prepare failed: " . $conn->error;
                header("Location: trainer_equipment_monitoring.php");
                exit();
            }
            
            $log_stmt->bind_param("issis", $equipment_id, $old_status, $new_status, $updated_by, $note);
            
            if ($log_stmt->execute()) {
                $_SESSION['success'] = "Equipment status updated successfully!";
            } else {
                $_SESSION['error'] = "Status updated but failed to log: " . $log_stmt->error;
            }
            $log_stmt->close();
        } else {
            $_SESSION['error'] = "Error updating equipment status: " . $update_stmt->error;
        }
        $update_stmt->close();
    } else {
        $_SESSION['error'] = "Equipment not found!";
    }
    $current_stmt->close();
    header("Location: trainer_equipment_monitoring.php");
    exit();
}

// Handle facility status updates
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_facility_status'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = "Security token invalid. Please try again.";
        header("Location: trainer_equipment_monitoring.php");
        exit();
    }

    $facility_id = (int)$_POST['facility_id'];
    $new_condition = $_POST['condition'];
    $notes = trim($_POST['notes']);
    $updated_by = $_SESSION['user_id'];

    $update_sql = "UPDATE facilities SET facility_condition = ?, notes = ?, last_updated = NOW(), updated_by = ? WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    
    if (!$update_stmt) {
        $_SESSION['error'] = "SQL prepare failed: " . $conn->error;
        header("Location: trainer_equipment_monitoring.php");
        exit();
    }
    
    $update_stmt->bind_param("ssii", $new_condition, $notes, $updated_by, $facility_id);
    
    if ($update_stmt->execute()) {
        $_SESSION['success'] = "Facility condition updated successfully!";
    } else {
        $_SESSION['error'] = "Error updating facility condition: " . $update_stmt->error;
    }
    $update_stmt->close();
    header("Location: trainer_equipment_monitoring.php");
    exit();
}

// Get filter parameters
$tab = $_GET['tab'] ?? 'equipment';
$status_filter = $_GET['status'] ?? '';
$category_filter = $_GET['category'] ?? '';
$location_filter = $_GET['location'] ?? '';
$search = $_GET['search'] ?? '';

// Build equipment query with filters
$equipment_where_conditions = ["1=1"];
$equipment_params = [];
$equipment_types = "";

if ($status_filter) {
    $equipment_where_conditions[] = "e.status = ?";
    $equipment_params[] = $status_filter;
    $equipment_types .= "s";
}

if ($category_filter) {
    $equipment_where_conditions[] = "e.category = ?";
    $equipment_params[] = $category_filter;
    $equipment_types .= "s";
}

if ($location_filter) {
    $equipment_where_conditions[] = "e.location = ?";
    $equipment_params[] = $location_filter;
    $equipment_types .= "s";
}

if ($search) {
    $equipment_where_conditions[] = "(e.name LIKE ? OR e.notes LIKE ?)";
    $equipment_params[] = "%$search%";
    $equipment_params[] = "%$search%";
    $equipment_types .= "ss";
}

$equipment_where_sql = implode(" AND ", $equipment_where_conditions);

// Get equipment data
$equipment_sql = "SELECT e.*, u.username as created_by_name 
                  FROM equipment e 
                  LEFT JOIN users u ON e.created_by = u.id 
                  WHERE $equipment_where_sql 
                  ORDER BY e.name ASC";

$equipment_stmt = $conn->prepare($equipment_sql);
if ($equipment_stmt && !empty($equipment_params)) {
    $equipment_stmt->bind_param($equipment_types, ...$equipment_params);
    $equipment_stmt->execute();
    $equipment_result = $equipment_stmt->get_result();
} else {
    $equipment_result = $conn->query($equipment_sql);
}

// Get facilities data
$facilities_sql = "SELECT f.*, u.username as updated_by_name 
                   FROM facilities f 
                   LEFT JOIN users u ON f.updated_by = u.id 
                   ORDER BY f.name ASC";
$facilities_result = $conn->query($facilities_sql);

// Get equipment statistics
$stats_sql = "SELECT COUNT(*) as total FROM equipment";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get unique categories and locations for filters
$categories_result = $conn->query("SELECT DISTINCT category FROM equipment ORDER BY category");
$locations_result = $conn->query("SELECT DISTINCT location FROM equipment ORDER BY location");

$username = $_SESSION['username'] ?? 'Trainer';
$role = $_SESSION['role'] ?? 'trainer';
?>

<?php require_once 'includes/trainer_header.php'; ?>
<?php require_once 'includes/trainer_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 p-4 space-y-4 overflow-auto">
      <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-yellow-400 flex items-center gap-2">
          <i data-lucide="wrench"></i>
          Equipment & Facility Monitoring
        </h1>
        <div class="text-sm text-gray-400">
          Welcome back, <?php echo htmlspecialchars($username); ?>
        </div>
      </div>

      <!-- Flash Messages -->
      <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-500/20 border border-green-500 text-green-400 px-4 py-3 rounded-lg mb-4">
          <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
      <?php endif; ?>
      
      <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-500/20 border border-red-500 text-red-400 px-4 py-3 rounded-lg mb-4">
          <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
      <?php endif; ?>

      <!-- Simple Statistics -->
      <div class="stats-grid mb-8">
        <div class="card stat-card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="dumbbell"></i><span>Total Equipment</span></p>
              <p class="card-value"><?php echo $stats['total']; ?></p>
              <p class="text-xs text-gray-400">All items tracked</p>
            </div>
            <div class="p-3 bg-yellow-500/10 rounded-lg">
              <i data-lucide="dumbbell" class="w-6 h-6 text-yellow-400"></i>
            </div>
          </div>
        </div>

        <div class="card membership-card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="building"></i><span>Facility Areas</span></p>
              <p class="card-value"><?php echo $facilities_result ? $facilities_result->num_rows : 0; ?></p>
              <p class="text-xs text-gray-400">Monitored areas</p>
            </div>
            <div class="p-3 bg-blue-500/10 rounded-lg">
              <i data-lucide="building" class="w-6 h-6 text-blue-400"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Tabs -->
      <div class="card">
        <div class="tab-container">
          <div class="tab <?php echo $tab == 'equipment' ? 'active' : ''; ?>" onclick="switchTab('equipment')">
            <i data-lucide="dumbbell"></i> Equipment
          </div>
          <div class="tab <?php echo $tab == 'facilities' ? 'active' : ''; ?>" onclick="switchTab('facilities')">
            <i data-lucide="building"></i> Facilities
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-wrap gap-2 justify-between mb-4">
          <div class="flex gap-2">
            <!-- Quick action to update equipment status -->
            <a href="trainer_maintenance_report.php" class="button-sm btn-primary">
              <i data-lucide="alert-triangle"></i> View Maintenance Report
            </a>
          </div>
          <div class="flex gap-2">
            <!-- Quick Status Filters -->
            <?php if ($tab == 'equipment'): ?>
              <a href="?tab=equipment&status=" class="button-sm <?php echo empty($status_filter) ? 'btn-active' : 'btn-outline'; ?>">
                All
              </a>
              <a href="?tab=equipment&status=Good" class="button-sm <?php echo $status_filter == 'Good' ? 'btn-active' : 'btn-outline'; ?>">
                Good
              </a>
              <a href="?tab=equipment&status=Needs Maintenance" class="button-sm <?php echo $status_filter == 'Needs Maintenance' ? 'btn-active' : 'btn-outline'; ?>">
                Maintenance
              </a>
              <a href="?tab=equipment&status=Under Repair" class="button-sm <?php echo $status_filter == 'Under Repair' ? 'btn-active' : 'btn-outline'; ?>">
                Repair
              </a>
              <a href="?tab=equipment&status=Broken" class="button-sm <?php echo $status_filter == 'Broken' ? 'btn-active' : 'btn-outline'; ?>">
                Broken
              </a>
            <?php elseif ($tab == 'facilities'): ?>
              <!-- Facility condition filters -->
              <a href="?tab=facilities&status=" class="button-sm <?php echo empty($status_filter) ? 'btn-active' : 'btn-outline'; ?>">
                All
              </a>
              <a href="?tab=facilities&status=Good" class="button-sm <?php echo $status_filter == 'Good' ? 'btn-active' : 'btn-outline'; ?>">
                Good
              </a>
              <a href="?tab=facilities&status=Needs Maintenance" class="button-sm <?php echo $status_filter == 'Needs Maintenance' ? 'btn-active' : 'btn-outline'; ?>">
                Maintenance
              </a>
              <a href="?tab=facilities&status=Under Repair" class="button-sm <?php echo $status_filter == 'Under Repair' ? 'btn-active' : 'btn-outline'; ?>">
                Repair
              </a>
              <a href="?tab=facilities&status=Closed" class="button-sm <?php echo $status_filter == 'Closed' ? 'btn-active' : 'btn-outline'; ?>">
                Closed
              </a>
            <?php endif; ?>
          </div>
        </div>

        <!-- Filters -->
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4" id="filterForm">
          <input type="hidden" name="tab" value="<?php echo $tab; ?>">
          <div>
            <label class="form-label">Category</label>
            <select name="category" class="form-input">
              <option value="">All Categories</option>
              <?php 
              $categories_result->data_seek(0);
              while($cat = $categories_result->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($cat['category']); ?>" <?php echo $category_filter == $cat['category'] ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($cat['category']); ?>
                </option>
              <?php endwhile; ?>
            </select>
          </div>
          <div>
            <label class="form-label">Location</label>
            <select name="location" class="form-input">
              <option value="">All Locations</option>
              <?php 
              $locations_result->data_seek(0);
              while($loc = $locations_result->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($loc['location']); ?>" <?php echo $location_filter == $loc['location'] ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($loc['location']); ?>
                </option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="md:col-span-2">
            <label class="form-label">Search</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search equipment..." class="form-input">
          </div>
          <div class="flex items-end">
            <button type="submit" class="button-sm btn-primary w-full">
              <i data-lucide="filter"></i> Apply Filters
            </button>
          </div>
        </form>
      </div>

      <!-- Equipment Tab -->
      <div id="equipment-tab" class="tab-content <?php echo $tab == 'equipment' ? 'active' : ''; ?>">
        <!-- Equipment Table -->
        <div class="card">
          <h2 class="text-lg font-semibold text-yellow-400 mb-4 flex items-center gap-2">
            <i data-lucide="dumbbell"></i>
            Equipment Inventory (<?php echo $equipment_result ? $equipment_result->num_rows : 0; ?>)
          </h2>
          
          <?php if ($equipment_result && $equipment_result->num_rows > 0): ?>
            <div class="overflow-x-auto">
              <table class="w-full text-sm text-left text-gray-400">
                <thead class="text-xs text-gray-400 uppercase bg-gray-800">
                  <tr>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Category</th>
                    <th class="px-4 py-3">Location</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Last Updated</th>
                    <th class="px-4 py-3">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php while($equipment = $equipment_result->fetch_assoc()): ?>
                  <tr class="border-b border-gray-700 hover:bg-gray-800">
                    <td class="px-4 py-3 whitespace-nowrap">
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
                          case 'Good': $status_class = 'badge-good'; break;
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
                    <td class="px-4 py-3 whitespace-nowrap">
                      <?php echo date('M j, Y g:i A', strtotime($equipment['last_updated'])); ?>
                    </td>
                    <td class="px-4 py-3">
                      <div class="flex gap-2">
                        <button onclick="openUpdateStatusModal(<?php echo htmlspecialchars(json_encode($equipment)); ?>)" class="text-blue-400 hover:text-blue-300 transition-colors" title="Update Status">
                          <i data-lucide="edit" class="w-4 h-4"></i>
                        </button>
                      </div>
                    </td>
                  </tr>
                  <?php endwhile; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="empty-state">
              <i data-lucide="dumbbell" class="w-12 h-12 mx-auto"></i>
              <p>No equipment found</p>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Facilities Tab -->
      <div id="facilities-tab" class="tab-content <?php echo $tab == 'facilities' ? 'active' : ''; ?>">
        <!-- Facilities Table -->
        <div class="card">
          <h2 class="text-lg font-semibold text-yellow-400 mb-4 flex items-center gap-2">
            <i data-lucide="building"></i>
            Facility Areas (<?php echo $facilities_result ? $facilities_result->num_rows : 0; ?>)
          </h2>
          
          <?php if ($facilities_result && $facilities_result->num_rows > 0): ?>
            <div class="overflow-x-auto">
              <table class="w-full text-sm text-left text-gray-400">
                <thead class="text-xs text-gray-400 uppercase bg-gray-800">
                  <tr>
                    <th class="px-4 py-3">Facility Name</th>
                    <th class="px-4 py-3">Condition</th>
                    <th class="px-4 py-3">Notes</th>
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
                        switch($facility['facility_condition']) {
                          case 'Good': $condition_class = 'badge-good'; break;
                          case 'Needs Maintenance': $condition_class = 'badge-needs-maintenance'; break;
                          case 'Under Repair': $condition_class = 'badge-under-repair'; break;
                          case 'Closed': $condition_class = 'badge-closed'; break;
                        }
                      ?>
                      <span class="badge <?php echo $condition_class; ?>">
                        <span class="status-indicator status-<?php echo strtolower(str_replace(' ', '-', $facility['facility_condition'])); ?>"></span>
                        <?php echo htmlspecialchars($facility['facility_condition']); ?>
                      </span>
                    </td>
                    <td class="px-4 py-3">
                      <?php echo !empty($facility['notes']) ? htmlspecialchars($facility['notes']) : '<span class="text-gray-500">-</span>'; ?>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                      <?php echo date('M j, Y g:i A', strtotime($facility['last_updated'])); ?>
                      <?php if (!empty($facility['updated_by_name'])): ?>
                        <div class="text-xs text-gray-400">by <?php echo htmlspecialchars($facility['updated_by_name']); ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                      <button onclick="openUpdateFacilityModal(<?php echo htmlspecialchars(json_encode($facility)); ?>)" class="text-blue-400 hover:text-blue-300 transition-colors" title="Update Condition">
                        <i data-lucide="edit" class="w-4 h-4"></i>
                      </button>
                    </td>
                  </tr>
                  <?php endwhile; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="empty-state">
              <i data-lucide="building" class="w-12 h-12 mx-auto"></i>
              <p>No facilities found</p>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </main>
  </div>

  <!-- Update Equipment Status Modal -->
  <div id="updateStatusModal" class="modal">
    <div class="modal-content">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-semibold text-yellow-400">Update Equipment Status</h3>
        <button onclick="closeUpdateStatusModal()" class="text-gray-400 hover:text-white transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>
      
      <form method="POST" id="updateStatusForm">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="equipment_id" id="update_equipment_id">
        <div class="space-y-4">
          <div>
            <label class="form-label">Equipment</label>
            <input type="text" id="equipment_name_display" class="form-input" readonly>
          </div>
          
          <div>
            <label class="form-label">New Status *</label>
            <select name="status" id="new_status" required class="form-input">
              <option value="Good">Good</option>
              <option value="Needs Maintenance">Needs Maintenance</option>
              <option value="Under Repair">Under Repair</option>
              <option value="Broken">Broken</option>
            </select>
          </div>
          
          <div>
            <label class="form-label">Note</label>
            <textarea name="note" id="status_note" rows="3" class="form-input" placeholder="Describe the issue or repair details..."></textarea>
          </div>
        </div>
        
        <div class="flex gap-2 mt-6">
          <button type="button" onclick="closeUpdateStatusModal()" class="flex-1 button-sm btn-outline">Cancel</button>
          <button type="submit" name="update_equipment_status" class="flex-1 button-sm btn-primary">Update Status</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Update Facility Modal -->
  <div id="updateFacilityModal" class="modal">
    <div class="modal-content">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-semibold text-yellow-400">Update Facility Condition</h3>
        <button onclick="closeUpdateFacilityModal()" class="text-gray-400 hover:text-white transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>
      
      <form method="POST" id="updateFacilityForm">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="facility_id" id="update_facility_id">
        <div class="space-y-4">
          <div>
            <label class="form-label">Facility</label>
            <input type="text" id="facility_name_display" class="form-input" readonly>
          </div>
          
          <div>
            <label class="form-label">New Condition *</label>
            <select name="condition" id="new_condition" required class="form-input">
              <option value="Good">Good</option>
              <option value="Needs Maintenance">Needs Maintenance</option>
              <option value="Under Repair">Under Repair</option>
              <option value="Closed">Closed</option>
            </select>
          </div>
          
          <div>
            <label class="form-label">Notes</label>
            <textarea name="notes" id="facility_notes" rows="3" class="form-input" placeholder="Describe the issue or maintenance details..."></textarea>
          </div>
        </div>
        
        <div class="flex gap-2 mt-6">
          <button type="button" onclick="closeUpdateFacilityModal()" class="flex-1 button-sm btn-outline">Cancel</button>
          <button type="submit" name="update_facility_status" class="flex-1 button-sm btn-primary">Update Condition</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Modal functions
    function openUpdateStatusModal(equipment) {
        document.getElementById('update_equipment_id').value = equipment.id;
        document.getElementById('equipment_name_display').value = equipment.name;
        document.getElementById('new_status').value = equipment.status;
        document.getElementById('status_note').value = '';
        document.getElementById('updateStatusModal').style.display = 'block';
    }

    function closeUpdateStatusModal() {
        document.getElementById('updateStatusModal').style.display = 'none';
    }

    function openUpdateFacilityModal(facility) {
        document.getElementById('update_facility_id').value = facility.id;
        document.getElementById('facility_name_display').value = facility.name;
        document.getElementById('new_condition').value = facility.facility_condition;
        document.getElementById('facility_notes').value = facility.notes || '';
        document.getElementById('updateFacilityModal').style.display = 'block';
    }

    function closeUpdateFacilityModal() {
        document.getElementById('updateFacilityModal').style.display = 'none';
    }

    // Tab switching
    function switchTab(tabName) {
        // Update URL without reloading page
        const url = new URL(window.location);
        url.searchParams.set('tab', tabName);
        window.history.replaceState({}, '', url);
        
        // Hide all tab contents
        document.querySelectorAll('.tab-content').forEach(tab => {
            tab.classList.remove('active');
        });
        
        // Show selected tab content
        document.getElementById(tabName + '-tab').classList.add('active');
        
        // Update tab active states
        document.querySelectorAll('.tab').forEach(tab => {
            tab.classList.remove('active');
        });
        event.currentTarget.classList.add('active');
        
        // Update the hidden tab field in filter form
        document.querySelector('input[name="tab"]').value = tabName;
    }

    // Close modals when clicking outside
    window.onclick = function(event) {
        const modals = ['updateStatusModal', 'updateFacilityModal'];
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        });
    }
  </script>
<?php require_once 'includes/trainer_footer.php'; ?>
<?php if(isset($conn)) { $conn->close(); } ?>
