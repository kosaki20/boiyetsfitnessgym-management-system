<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'trainer') {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';
require_once 'chat_functions.php';
$unread_count = getUnreadCount($_SESSION['user_id'], $conn);

// Pagination settings
$records_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$member_type_filter = isset($_GET['member_type']) ? trim($_GET['member_type']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Build WHERE clause for filters
$where_conditions = [];
$query_params = [];

if (!empty($search)) {
    $where_conditions[] = "(full_name LIKE ? OR contact_number LIKE ? OR email LIKE ?)";
    $search_term = "%$search%";
    $query_params = array_merge($query_params, [$search_term, $search_term, $search_term]);
}

if (!empty($member_type_filter)) {
    $where_conditions[] = "member_type = ?";
    $query_params[] = $member_type_filter;
}

if (!empty($status_filter)) {
    $today = date('Y-m-d');
    switch($status_filter) {
        case 'active':
            $where_conditions[] = "status = 'active' AND expiry_date >= ?";
            $query_params[] = $today;
            break;
        case 'expiring':
            $where_conditions[] = "status = 'active' AND expiry_date >= ? AND expiry_date <= DATE_ADD(?, INTERVAL 7 DAY)";
            $query_params[] = $today;
            $query_params[] = $today;
            break;
        case 'expired':
            $where_conditions[] = "(status = 'expired' OR expiry_date < ?)";
            $query_params[] = $today;
            break;
    }
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(' AND ', $where_conditions);
}

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM members $where_clause";
$count_stmt = $conn->prepare($count_sql);
if (!empty($query_params)) {
    $count_stmt->bind_param(str_repeat('s', count($query_params)), ...$query_params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_records = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get members with pagination and filters
$members_sql = "SELECT m.*, u.email, u.username 
                FROM members m 
                LEFT JOIN users u ON m.user_id = u.id 
                $where_clause 
                ORDER BY m.full_name 
                LIMIT ? OFFSET ?";

$members_stmt = $conn->prepare($members_sql);
$query_params_with_pagination = $query_params;
$query_params_with_pagination[] = $records_per_page;
$query_params_with_pagination[] = $offset;

if (!empty($query_params_with_pagination)) {
    $param_types = str_repeat('s', count($query_params)) . 'ii';
    $members_stmt->bind_param($param_types, ...$query_params_with_pagination);
} else {
    $members_stmt->bind_param('ii', $records_per_page, $offset);
}

$members_stmt->execute();
$members_result = $members_stmt->get_result();
$allMembers = [];

while ($row = $members_result->fetch_assoc()) {
    // Calculate days left
    $expiry = new DateTime($row['expiry_date']);
    $today = new DateTime();
    $daysLeft = $today->diff($expiry)->days;
    if ($today > $expiry) {
        $daysLeft = -$daysLeft;
    }
    $row['days_left'] = $daysLeft;
    $allMembers[] = $row;
}

// Handle member view request
$view_member = null;
if (isset($_GET['view_member_id'])) {
    $member_id = (int)$_GET['view_member_id'];
    $view_sql = "SELECT m.*, u.email, u.username, u.client_type 
                 FROM members m 
                 LEFT JOIN users u ON m.user_id = u.id 
                 WHERE m.id = ?";
    $view_stmt = $conn->prepare($view_sql);
    $view_stmt->bind_param("i", $member_id);
    $view_stmt->execute();
    $view_result = $view_stmt->get_result();
    $view_member = $view_result->fetch_assoc();
    
    if ($view_member) {
        // Calculate days left for viewed member
        $expiry = new DateTime($view_member['expiry_date']);
        $today = new DateTime();
        $daysLeft = $today->diff($expiry)->days;
        if ($today > $expiry) {
            $daysLeft = -$daysLeft;
        }
        $view_member['days_left'] = $daysLeft;
    }
}
?>

<?php
// Include Header and dynamically include Sidebar based on role
require_once 'includes/trainer_header.php';
require_once 'includes/trainer_sidebar.php';
?>

    <main class="flex-1 p-6 overflow-auto">
      <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-yellow-400 flex items-center gap-2">
          <i data-lucide="users"></i>
          All Members
        </h1>
        <div class="flex gap-3">
          <a href="member_registration.php?type=walk-in" class="btn btn-primary">
            <i data-lucide="user-plus"></i> Add Walk-in
          </a>
          <a href="member_registration.php?type=client" class="btn btn-success">
            <i data-lucide="user-check"></i> Add Client
          </a>
        </div>
      </div>

      <!-- Statistics -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="stat-card">
          <div class="text-3xl font-bold text-yellow-400 mb-2"><?php echo $total_records; ?></div>
          <div class="text-gray-400">Total Members</div>
        </div>
        
        <div class="stat-card">
          <div class="text-3xl font-bold text-yellow-400 mb-2">
            <?php 
              $activeCount = array_filter($allMembers, function($member) { 
                return $member['days_left'] > 7; 
              });
              echo count($activeCount);
            ?>
          </div>
          <div class="text-gray-400">Active Members</div>
        </div>
        
        <div class="stat-card">
          <div class="text-3xl font-bold text-yellow-400 mb-2">
            <?php 
              $expiringCount = array_filter($allMembers, function($member) { 
                return $member['days_left'] > 0 && $member['days_left'] <= 7; 
              });
              echo count($expiringCount);
            ?>
          </div>
          <div class="text-gray-400">Expiring Soon</div>
        </div>
        
        <div class="stat-card">
          <div class="text-3xl font-bold text-yellow-400 mb-2">
            <?php 
              $expiredCount = array_filter($allMembers, function($member) { 
                return $member['days_left'] <= 0; 
              });
              echo count($expiredCount);
            ?>
          </div>
          <div class="text-gray-400">Expired</div>
        </div>
      </div>

      <!-- Search and Filter Section -->
      <div class="card mb-6">
        <h2 class="text-lg font-semibold text-yellow-400 mb-4 flex items-center gap-2">
          <i data-lucide="search"></i>
          Search & Filter Members
        </h2>
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div>
            <label class="form-label">Search</label>
            <input type="text" name="search" class="form-input" placeholder="Name, contact, or email..." value="<?php echo htmlspecialchars($search); ?>">
          </div>
          <div>
            <label class="form-label">Member Type</label>
            <select name="member_type" class="form-input">
              <option value="">All Types</option>
              <option value="client" <?php echo $member_type_filter === 'client' ? 'selected' : ''; ?>>Client</option>
              <option value="walk-in" <?php echo $member_type_filter === 'walk-in' ? 'selected' : ''; ?>>Walk-in</option>
            </select>
          </div>
          <div>
            <label class="form-label">Status</label>
            <select name="status" class="form-input">
              <option value="">All Status</option>
              <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
              <option value="expiring" <?php echo $status_filter === 'expiring' ? 'selected' : ''; ?>>Expiring Soon</option>
              <option value="expired" <?php echo $status_filter === 'expired' ? 'selected' : ''; ?>>Expired</option>
            </select>
          </div>
          <div class="flex items-end gap-2">
            <button type="submit" class="btn btn-primary flex-1">
              <i data-lucide="filter"></i> Apply Filters
            </button>
            <a href="all_members.php" class="btn btn-danger">
              <i data-lucide="refresh-cw"></i> Reset
            </a>
          </div>
        </form>
      </div>

      <!-- Members Table -->
      <div class="card">
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-6">
          <h2 class="text-lg font-semibold text-yellow-400 flex items-center gap-2">
            <i data-lucide="list"></i>
            Member Directory (<?php echo $total_records; ?> total)
          </h2>
          
          <div class="text-sm text-gray-400">
            Showing <?php echo count($allMembers); ?> of <?php echo $total_records; ?> members
          </div>
        </div>
        
        <div class="table-container">
          <table>
            <thead>
              <tr>
                <th>Member Name</th>
                <th>Type</th>
                <th>Contact</th>
                <th>Membership Plan</th>
                <th>Expiry Date</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="membersTableBody">
              <?php foreach ($allMembers as $member): ?>
                <tr>
                  <td class="font-medium"><?php echo htmlspecialchars($member['full_name']); ?></td>
                  <td>
                    <span class="member-type-badge type-<?php echo $member['member_type']; ?>">
                      <?php echo ucfirst($member['member_type']); ?>
                    </span>
                  </td>
                  <td><?php echo htmlspecialchars($member['contact_number']); ?></td>
                  <td><?php echo ucfirst($member['membership_plan']); ?></td>
                  <td><?php echo date('M j, Y', strtotime($member['expiry_date'])); ?></td>
                  <td>
                    <?php if ($member['days_left'] > 7): ?>
                      <span class="status-badge status-active">Active (<?php echo $member['days_left']; ?> days)</span>
                    <?php elseif ($member['days_left'] > 0): ?>
                      <span class="status-badge status-expiring">Expiring in <?php echo $member['days_left']; ?> days</span>
                    <?php else: ?>
                      <span class="status-badge status-expired">Expired</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <button onclick="viewMember(<?php echo $member['id']; ?>)" class="btn btn-info button-sm">
                      <i data-lucide="eye"></i> View
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
              
              <?php if (empty($allMembers)): ?>
                <tr>
                  <td colspan="7" class="empty-state">
                    <i data-lucide="users" class="w-12 h-12 mx-auto"></i>
                    <p>No members found. Register a new member to get started!</p>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
          <button 
            class="pagination-btn" 
            <?php echo $page <= 1 ? 'disabled' : ''; ?>
            onclick="changePage(<?php echo $page - 1; ?>)"
          >
            <i data-lucide="chevron-left"></i> Previous
          </button>
          
          <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <button 
              class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>"
              onclick="changePage(<?php echo $i; ?>)"
            >
              <?php echo $i; ?>
            </button>
          <?php endfor; ?>
          
          <button 
            class="pagination-btn" 
            <?php echo $page >= $total_pages ? 'disabled' : ''; ?>
            onclick="changePage(<?php echo $page + 1; ?>)"
          >
            Next <i data-lucide="chevron-right"></i>
          </button>
        </div>
        <?php endif; ?>
      </div>
    </main>

  <!-- Member View Modal -->
  <?php if ($view_member): ?>
  <div id="memberModal" class="modal-overlay active">
    <div class="modal">
      <div class="modal-header">
        <h2 class="modal-title">
          <i data-lucide="user"></i>
          Member Information - <?php echo htmlspecialchars($view_member['full_name']); ?>
        </h2>
        <button class="modal-close" onclick="closeModal()">
          <i data-lucide="x" class="w-6 h-6"></i>
        </button>
      </div>

      <div class="space-y-6">
        <!-- Personal Information -->
        <div>
          <h3 class="text-yellow-400 font-semibold text-lg mb-4 flex items-center gap-2">
            <i data-lucide="user"></i>
            Personal Information
          </h3>
          <div class="info-grid">
            <div class="info-card">
              <div class="info-label">Full Name</div>
              <div class="info-value"><?php echo htmlspecialchars($view_member['full_name']); ?></div>
            </div>
            <div class="info-card">
              <div class="info-label">Age</div>
              <div class="info-value"><?php echo $view_member['age']; ?> years old</div>
            </div>
            <div class="info-card">
              <div class="info-label">Contact Number</div>
              <div class="info-value"><?php echo htmlspecialchars($view_member['contact_number']); ?></div>
            </div>
            <div class="info-card">
              <div class="info-label">Email</div>
              <div class="info-value"><?php echo htmlspecialchars($view_member['email'] ?? 'N/A'); ?></div>
            </div>
            <div class="info-card">
              <div class="info-label">Username</div>
              <div class="info-value"><?php echo htmlspecialchars($view_member['username'] ?? 'N/A'); ?></div>
            </div>
            <div class="info-card">
              <div class="info-label">Client Type</div>
              <div class="info-value"><?php echo htmlspecialchars($view_member['client_type'] ?? 'N/A'); ?></div>
            </div>
            <?php if ($view_member['member_type'] === 'client'): ?>
            <div class="info-card">
              <div class="info-label">Gender</div>
              <div class="info-value"><?php echo htmlspecialchars($view_member['gender'] ?? 'N/A'); ?></div>
            </div>
            <div class="info-card">
              <div class="info-label">Height</div>
              <div class="info-value"><?php echo $view_member['height'] ?? 'N/A'; ?> cm</div>
            </div>
            <div class="info-card">
              <div class="info-label">Weight</div>
              <div class="info-value"><?php echo $view_member['weight'] ?? 'N/A'; ?> kg</div>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Address Information -->
        <div>
          <h3 class="text-yellow-400 font-semibold text-lg mb-4 flex items-center gap-2">
            <i data-lucide="map-pin"></i>
            Address Information
          </h3>
          <div class="info-card">
            <div class="info-label">Address</div>
            <div class="info-value"><?php echo htmlspecialchars($view_member['address']); ?></div>
          </div>
        </div>

        <!-- Membership Information -->
        <div>
          <h3 class="text-yellow-400 font-semibold text-lg mb-4 flex items-center gap-2">
            <i data-lucide="id-card"></i>
            Membership Information
          </h3>
          <div class="info-grid">
            <div class="info-card">
              <div class="info-label">Member Type</div>
              <div class="info-value">
                <span class="member-type-badge type-<?php echo $view_member['member_type']; ?>">
                  <?php echo ucfirst($view_member['member_type']); ?>
                </span>
              </div>
            </div>
            <div class="info-card">
              <div class="info-label">Membership Plan</div>
              <div class="info-value"><?php echo ucfirst($view_member['membership_plan']); ?></div>
            </div>
            <div class="info-card">
              <div class="info-label">Start Date</div>
              <div class="info-value"><?php echo date('M j, Y', strtotime($view_member['start_date'])); ?></div>
            </div>
            <div class="info-card">
              <div class="info-label">Expiry Date</div>
              <div class="info-value"><?php echo date('M j, Y', strtotime($view_member['expiry_date'])); ?></div>
            </div>
            <div class="info-card">
              <div class="info-label">Status</div>
              <div class="info-value">
                <?php if ($view_member['days_left'] > 7): ?>
                  <span class="status-badge status-active">Active (<?php echo $view_member['days_left']; ?> days left)</span>
                <?php elseif ($view_member['days_left'] > 0): ?>
                  <span class="status-badge status-expiring">Expiring in <?php echo $view_member['days_left']; ?> days</span>
                <?php else: ?>
                  <span class="status-badge status-expired">Expired</span>
                <?php endif; ?>
              </div>
            </div>
            <div class="info-card">
              <div class="info-label">Registration Date</div>
              <div class="info-value"><?php echo date('M j, Y', strtotime($view_member['created_at'])); ?></div>
            </div>
          </div>
        </div>

        <?php if ($view_member['member_type'] === 'client' && !empty($view_member['fitness_goals'])): ?>
        <!-- Fitness Goals -->
        <div>
          <h3 class="text-yellow-400 font-semibold text-lg mb-4 flex items-center gap-2">
            <i data-lucide="target"></i>
            Fitness Goals
          </h3>
          <div class="info-card">
            <div class="info-label">Goals & Objectives</div>
            <div class="info-value"><?php echo htmlspecialchars($view_member['fitness_goals']); ?></div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="flex gap-3 pt-4 border-t border-gray-700">
          <button onclick="closeModal()" class="btn btn-primary flex-1">
            <i data-lucide="check"></i> Close
          </button>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <script>
    // Modal functions
    function viewMember(memberId) {
        window.location.href = `?view_member_id=${memberId}&<?php echo http_build_query($_GET); ?>`;
    }

    function closeModal() {
        // Remove the view_member_id from URL and reload
        const url = new URL(window.location);
        url.searchParams.delete('view_member_id');
        window.location.href = url.toString();
    }

    // Pagination function
    function changePage(page) {
        const url = new URL(window.location);
        url.searchParams.set('page', page);
        window.location.href = url.toString();
    }

    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModal();
        }
    });

    // Close modal when clicking outside
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal-overlay')) {
            closeModal();
        }
    });
  </script>
<?php require_once 'includes/trainer_footer.php'; ?>
<?php if(isset($conn)) { $conn->close(); } ?>



