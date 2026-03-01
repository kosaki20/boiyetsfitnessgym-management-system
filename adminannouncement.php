<?php
session_start();
if (!isset($_SESSION['username']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';
require_once 'includes/admin_functions.php';
require_once 'chat_functions.php';
$unread_count = getUnreadCount($_SESSION['user_id'], $conn);

// Handle form submissions
$action_result = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $result = handleAnnouncementAction($conn, $_POST, $_SESSION['username']);
    if ($result) {
        $type = isset($result['success']) ? 'success' : 'error';
        $msg = $result['success'] ?? $result['error'];
        $action_result = "<div class='alert alert-$type'>$msg</div>";
    }
}

// Fetch all announcements
$announcements_result = getAnnouncements($conn);

$conn->close();
?>
<?php
// Include Header and Sidebar
require_once 'includes/admin_header.php';
require_once 'includes/admin_sidebar.php';
?>

    <!-- Main Content -->
    <main class="flex-1 p-4 space-y-4 overflow-auto">
      <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-yellow-400 flex items-center gap-2">
          <i data-lucide="megaphone"></i>
          Announcement Management
        </h1>
        <div class="text-sm text-gray-400">
          Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?>
        </div>
      </div>

      <?php echo $action_result; ?>

      <!-- Create Announcement Form -->
      <div class="card">
        <h2 class="text-lg font-semibold text-yellow-400 mb-4 flex items-center gap-2">
          <i data-lucide="plus"></i>
          Create New Announcement
        </h2>
        <form method="POST" class="space-y-4">
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div>
              <label class="form-label">Title</label>
              <input type="text" name="announcement_title" class="form-input" required placeholder="Enter announcement title">
            </div>
            
            <div>
              <label class="form-label">Target Audience</label>
              <select name="target_audience" class="form-select" required>
                <option value="all">All Users</option>
                <option value="clients">Clients Only</option>
                <option value="trainers">Trainers Only</option>
              </select>
            </div>
          </div>
          
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div>
              <label class="form-label">Priority Level</label>
              <select name="priority" class="form-select" required>
                <option value="low">Low</option>
                <option value="medium" selected>Medium</option>
                <option value="high">High</option>
              </select>
            </div>
            
            <div>
              <label class="form-label">Expiry Date (Optional)</label>
              <input type="date" name="expiry_date" class="form-input" min="<?php echo date('Y-m-d'); ?>">
              <p class="text-xs text-gray-400 mt-1">Leave empty if announcement should not expire</p>
            </div>
          </div>
          
          <div>
            <label class="form-label">Content</label>
            <textarea name="announcement_content" class="form-textarea" required placeholder="Enter announcement content"></textarea>
          </div>
          
          <div class="flex justify-end">
            <button type="submit" name="create_announcement" class="button-sm bg-yellow-600 hover:bg-yellow-700 text-white flex items-center gap-2">
              <i data-lucide="plus" class="w-4 h-4"></i> Create Announcement
            </button>
          </div>
        </form>
      </div>

      <!-- Announcements List -->
      <div class="card">
        <div class="flex justify-between items-center mb-6">
          <h2 class="text-lg font-semibold text-yellow-400 flex items-center gap-2">
            <i data-lucide="list"></i>
            All Announcements
          </h2>
          <span class="text-gray-400"><?php echo $announcements_result->num_rows; ?> announcement(s)</span>
        </div>
        
        <div class="space-y-4">
          <?php if ($announcements_result->num_rows > 0): ?>
            <?php while($announcement = $announcements_result->fetch_assoc()): ?>
            <div class="bg-gray-800 rounded-lg p-4 <?php echo 'priority-' . $announcement['priority']; ?> hover:bg-gray-750 transition-colors">
              <div class="flex justify-between items-start mb-3">
                <div>
                  <h3 class="font-semibold text-lg text-white"><?php echo htmlspecialchars($announcement['title']); ?></h3>
                  <div class="flex items-center gap-2 mt-1">
                    <span class="badge <?php echo 'badge-' . $announcement['priority']; ?>">
                      <?php echo ucfirst($announcement['priority']); ?> Priority
                    </span>
                    <span class="badge badge-audience">
                      <?php echo ucfirst($announcement['target_audience']); ?>
                    </span>
                    <?php if ($announcement['expiry_date']): ?>
                      <span class="text-xs text-gray-400">
                        Expires: <?php echo date('M j, Y', strtotime($announcement['expiry_date'])); ?>
                      </span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="flex items-center gap-2">
                  <button onclick="openEditModal(<?php echo $announcement['id']; ?>, '<?php echo addslashes($announcement['title']); ?>', '<?php echo addslashes($announcement['content']); ?>', '<?php echo $announcement['priority']; ?>', '<?php echo $announcement['target_audience']; ?>', '<?php echo $announcement['expiry_date']; ?>')" 
                          class="button-sm btn-success">
                    <i data-lucide="edit" class="w-4 h-4"></i> Edit
                  </button>
                  <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this announcement?');">
                    <input type="hidden" name="announcement_id" value="<?php echo $announcement['id']; ?>">
                    <button type="submit" name="delete_announcement" class="button-sm btn-danger">
                      <i data-lucide="trash-2" class="w-4 h-4"></i> Delete
                    </button>
                  </form>
                </div>
              </div>
              
              <p class="text-gray-300 mb-3 whitespace-pre-wrap"><?php echo nl2br(htmlspecialchars($announcement['content'])); ?></p>
              
              <div class="flex justify-between items-center text-sm text-gray-400">
                <span>Posted by <?php echo htmlspecialchars($announcement['created_by']); ?></span>
                <span><?php echo date('M j, Y g:i A', strtotime($announcement['created_at'])); ?></span>
              </div>
            </div>
            <?php endwhile; ?>
          <?php else: ?>
            <div class="empty-state">
              <i data-lucide="megaphone" class="w-12 h-12 mx-auto"></i>
              <p>No announcements found. Create your first announcement above.</p>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </main>
  </div>

  <!-- Edit Announcement Modal -->
  <div id="editModal" class="modal">
    <div class="modal-content">
      <div class="flex justify-between items-center mb-6">
        <h3 class="text-xl font-semibold text-yellow-400">Edit Announcement</h3>
        <button type="button" class="text-gray-400 hover:text-white text-2xl" onclick="closeEditModal()">
          <i data-lucide="x" class="w-6 h-6"></i>
        </button>
      </div>
      
      <form method="POST" id="editForm">
        <input type="hidden" name="announcement_id" id="edit_announcement_id">
        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
          <div>
            <label class="form-label">Title</label>
            <input type="text" id="edit_title" name="edit_title" class="form-input" required>
          </div>
          
          <div>
            <label class="form-label">Target Audience</label>
            <select id="edit_target_audience" name="edit_target_audience" class="form-select" required>
              <option value="all">All Users</option>
              <option value="clients">Clients Only</option>
              <option value="trainers">Trainers Only</option>
            </select>
          </div>
        </div>
        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
          <div>
            <label class="form-label">Priority Level</label>
            <select id="edit_priority" name="edit_priority" class="form-select" required>
              <option value="low">Low</option>
              <option value="medium">Medium</option>
              <option value="high">High</option>
            </select>
          </div>
          
          <div>
            <label class="form-label">Expiry Date (Optional)</label>
            <input type="date" id="edit_expiry_date" name="edit_expiry_date" class="form-input">
            <p class="text-xs text-gray-400 mt-1">Leave empty if announcement should not expire</p>
          </div>
        </div>
        
        <div class="mb-6">
          <label class="form-label">Content</label>
          <textarea id="edit_content" name="edit_content" class="form-textarea" required></textarea>
        </div>
        
        <div class="flex justify-end gap-3">
          <button type="button" class="button-sm btn-primary" onclick="closeEditModal()">Cancel</button>
          <button type="submit" name="update_announcement" class="button-sm bg-yellow-600 hover:bg-yellow-700 text-white">
            Update Announcement
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Set minimum date for expiry date fields to today
        const today = new Date().toISOString().split('T')[0];
        document.querySelector('input[name="expiry_date"]').min = today;
        document.getElementById('edit_expiry_date').min = today;
    });

    // Edit Modal Functions
    function openEditModal(id, title, content, priority, audience, expiryDate) {
      document.getElementById('edit_announcement_id').value = id;
      document.getElementById('edit_title').value = title;
      document.getElementById('edit_content').value = content;
      document.getElementById('edit_priority').value = priority;
      document.getElementById('edit_target_audience').value = audience;
      document.getElementById('edit_expiry_date').value = expiryDate;
      
      document.getElementById('editModal').style.display = 'block';
    }
    
    function closeEditModal() {
      document.getElementById('editModal').style.display = 'none';
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
      const modal = document.getElementById('editModal');
      if (event.target === modal) {
        closeEditModal();
      }
    }
  </script>
<?php require_once 'includes/admin_footer.php'; ?>



