<?php
// ... rest of your code
session_start();
if (!isset($_SESSION['username']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';
require_once 'includes/admin_functions.php';
require_once 'chat_functions.php';

$unread_count = getUnreadCount($_SESSION['user_id'], $conn);

// Handle user actions
$action_message = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['delete_user'])) {
        $user_id = intval($_POST['user_id']);
        if (deleteUser($conn, $user_id)) {
            $action_message = "User deleted successfully!";
        } else {
            $action_message = "Error deleting user: " . $conn->error;
        }
    }
}

// Fetch all users
$users_result = getAllUsers($conn);

// Get role counts
$role_counts = getUserRoleCounts($conn);
$admin_count = $role_counts['admin'];
$trainer_count = $role_counts['trainer'];
$client_count = $role_counts['client'];

$admin_count = $role_counts['admin'];
$trainer_count = $role_counts['trainer'];
$client_count = $role_counts['client'];

?>
<style>
    /* Page-specific styles */
    .role-badge {
      padding: 0.25rem 0.75rem;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 500;
    }
    
    .role-admin {
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
    }
    
    .role-trainer {
      background: rgba(59, 130, 246, 0.2);
      color: #3b82f6;
    }
    
    .role-client {
      background: rgba(16, 185, 129, 0.2);
      color: #10b981;
    }
</style>

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
          <i data-lucide="users"></i>
          Manage Users
        </h1>
        <div class="text-sm text-gray-400">
          Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?>
        </div>
      </div>

      <?php if (!empty($action_message)): ?>
        <div class="alert alert-<?php echo strpos($action_message, 'Error') !== false ? 'error' : 'success'; ?>">
          <i data-lucide="<?php echo strpos($action_message, 'Error') !== false ? 'alert-circle' : 'check-circle'; ?>" class="w-5 h-5 mr-2"></i>
          <?php echo htmlspecialchars($action_message); ?>
        </div>
      <?php endif; ?>

      <!-- Quick Stats -->
      <div class="stats-grid mb-8">
        <div class="card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="shield"></i><span>Total Admins</span></p>
              <p class="card-value"><?php echo $admin_count; ?></p>
            </div>
            <div class="p-3 bg-red-500/10 rounded-lg">
              <i data-lucide="shield" class="w-6 h-6 text-red-400"></i>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="dumbbell"></i><span>Total Trainers</span></p>
              <p class="card-value"><?php echo $trainer_count; ?></p>
            </div>
            <div class="p-3 bg-blue-500/10 rounded-lg">
              <i data-lucide="dumbbell" class="w-6 h-6 text-blue-400"></i>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="user"></i><span>Total Clients</span></p>
              <p class="card-value"><?php echo $client_count; ?></p>
            </div>
            <div class="p-3 bg-green-500/10 rounded-lg">
              <i data-lucide="user" class="w-6 h-6 text-green-400"></i>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="flex items-center justify-between">
            <div>
              <p class="card-title"><i data-lucide="users"></i><span>Total Users</span></p>
              <p class="card-value"><?php echo $users_result->num_rows; ?></p>
            </div>
            <div class="p-3 bg-yellow-500/10 rounded-lg">
              <i data-lucide="users" class="w-6 h-6 text-yellow-400"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Users Table Card -->
      <div class="card">
        <div class="flex justify-between items-center mb-6">
          <h2 class="text-lg font-semibold text-yellow-400 flex items-center gap-2">
            <i data-lucide="list"></i>
            All Users
          </h2>
          <div class="flex gap-3">
            <div class="relative">
              <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
              <input type="text" id="searchUsers" placeholder="Search users..." class="form-input pl-10 pr-4 py-2" style="width: 250px;">
            </div>
            <a href="admin_dashboard.php" class="button-sm bg-yellow-600 hover:bg-yellow-700 text-white flex items-center gap-2">
              <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to Dashboard
            </a>
          </div>
        </div>

        <div class="table-container">
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Full Name</th>
                <th>Username</th>
                <th>Email</th>
                <th>Role</th>
                <th>Joined Date</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($users_result->num_rows > 0): ?>
                <?php while($user = $users_result->fetch_assoc()): ?>
                <tr>
                  <td class="font-medium"><?php echo $user['id']; ?></td>
                  <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                  <td><?php echo htmlspecialchars($user['username']); ?></td>
                  <td><?php echo htmlspecialchars($user['email']); ?></td>
                  <td>
                    <span class="role-badge role-<?php echo $user['role']; ?>">
                      <?php echo ucfirst($user['role']); ?>
                    </span>
                  </td>
                  <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                  <td>
                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this user? This action cannot be undone.')">
                      <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                      <button type="submit" name="delete_user" class="button-sm btn-danger flex items-center gap-1">
                        <i data-lucide="trash-2" class="w-3 h-3"></i> Delete
                      </button>
                    </form>
                  </td>
                </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr>
                  <td colspan="7" class="empty-state">
                    <i data-lucide="users" class="w-12 h-12 mx-auto"></i>
                    <p>No users found in the system.</p>
                    <a href="admin_dashboard.php" class="button-sm bg-yellow-600 hover:bg-yellow-700 text-white mt-3 flex items-center gap-1 mx-auto w-fit">
                      <i data-lucide="user-plus" class="w-3 h-3"></i> Create First User
                    </a>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="flex justify-between items-center mt-6 pt-4 border-t border-gray-700">
          <span class="text-sm text-gray-400">Showing <?php echo $users_result->num_rows; ?> users</span>
          <div class="flex gap-2">
            <button class="button-sm btn-primary disabled:opacity-50" disabled>
              <i data-lucide="chevron-left" class="w-4 h-4"></i> Previous
            </button>
            <button class="button-sm btn-primary disabled:opacity-50" disabled>
              Next <i data-lucide="chevron-right" class="w-4 h-4"></i>
            </button>
          </div>
        </div>
      </div>
    </main>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
        lucide.createIcons();
        
        // Search functionality
        document.getElementById('searchUsers').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
    });
  </script>

<?php require_once 'includes/admin_footer.php'; ?>



