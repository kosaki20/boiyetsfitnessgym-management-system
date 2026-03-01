<?php
session_start();
if (!isset($_SESSION['username']) || $_SESSION['role'] != 'client') {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';
// ADD THIS SECTION FOR CHAT FUNCTIONALITY
require_once 'chat_functions.php';
$unread_count = getUnreadCount($_SESSION['user_id'], $conn);
$logged_in_user_id = $_SESSION['user_id'];

// Add mobile-specific caching headers if needed
if (strpos($_SERVER['HTTP_USER_AGENT'], 'Mobile') !== false) {
    header("Cache-Control: max-age=300"); // 5 minutes for mobile
}

function getClientDetails($conn, $user_id) {
    $sql = "SELECT m.* FROM members m 
            INNER JOIN users u ON m.user_id = u.id 
            WHERE u.id = ? AND m.member_type = 'client'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function getClientFeedback($conn, $user_id) {
    $feedback = [];
    $sql = "SELECT f.* FROM feedback f 
            WHERE f.user_id = ? ORDER BY f.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $feedback[] = $row;
    }
    
    return $feedback;
}

// Handle feedback submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_feedback'])) {
    $subject = trim($_POST['subject']);
    $category = trim($_POST['category']);
    $message = trim($_POST['message']);
    $rating = isset($_POST['rating']) ? intval($_POST['rating']) : NULL;
    $urgent = isset($_POST['urgent']) ? 1 : 0;
    
    $insert_sql = "INSERT INTO feedback (user_id, user_role, subject, category, message, rating, urgent, status, created_at) 
                   VALUES (?, 'client', ?, ?, ?, ?, ?, 'pending', NOW())";
    $stmt = $conn->prepare($insert_sql);
    $stmt->bind_param("isssii", $logged_in_user_id, $subject, $category, $message, $rating, $urgent);
    
    if ($stmt->execute()) {
        $success_message = "Feedback submitted successfully! We'll review it soon.";
        // Clear form
        $_POST = array();
    } else {
        $error_message = "Error submitting feedback: " . $conn->error;
    }
}

$client = getClientDetails($conn, $logged_in_user_id);
$feedback_history = getClientFeedback($conn, $logged_in_user_id);
?>
<?php 
$page_title = 'Send Feedback';
require_once 'includes/client_header.php'; 
?>
<style>
    .feedback-item {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
        border-left: 4px solid #fbbf24;
    }
    
    .form-input {
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        padding: 0.75rem;
        color: white;
        width: 100%;
        transition: all 0.2s ease;
        min-height: 44px;
    }
    
    .form-input:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.2);
    }
    
    .form-label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 500;
        color: #d1d5db;
    }
    
    .form-select {
        background: rgba(255, 255, 255, 0.05) !important;
        border: 1px solid rgba(255, 255, 255, 0.1) !important;
        border-radius: 8px;
        padding: 0.75rem;
        color: white !important;
        width: 100%;
        transition: all 0.2s ease;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23fbbf24' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") !important;
        background-repeat: no-repeat !important;
        background-position: right 0.75rem center !important;
        background-size: 16px !important;
        min-height: 44px;
    }
    
    .form-select:focus {
        outline: none;
        border-color: #fbbf24 !important;
        box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.2) !important;
    }
    
    .star-rating {
        display: flex;
        gap: 0.5rem;
    }
    
    .star {
        cursor: pointer;
        color: #6b7280;
        transition: color 0.2s ease;
        min-width: 32px;
        min-height: 32px;
    }
    
    .star:hover, .star.active {
        color: #fbbf24;
    }
    
    .status-badge {
        padding: 0.5rem 1rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 500;
    }
    
    .status-pending {
        background: rgba(245, 158, 11, 0.2);
        color: #f59e0b;
        border: 1px solid rgba(245, 158, 11, 0.3);
    }
    
    .status-reviewed {
        background: rgba(59, 130, 246, 0.2);
        color: #3b82f6;
        border: 1px solid rgba(59, 130, 246, 0.3);
    }
    
    .status-resolved {
        background: rgba(16, 185, 129, 0.2);
        color: #10b981;
        border: 1px solid rgba(16, 185, 129, 0.3);
    }
    
    .urgent-badge {
        background: rgba(239, 68, 68, 0.2);
        color: #ef4444;
        border: 1px solid rgba(239, 68, 68, 0.3);
        padding: 0.25rem 0.5rem;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 500;
    }
</style>

    <!-- Main Content -->
    <main id="mainContent" class="main-content flex-1 p-4 space-y-6 overflow-auto">
      <div class="flex justify-between items-center mb-6">
        <div class="flex items-center space-x-3">
          <a href="client_dashboard.php" class="text-gray-300 hover:text-yellow-400 transition-colors p-2 rounded-lg hover:bg-white/5">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
          </a>
          <h2 class="text-2xl font-bold text-yellow-400 flex items-center gap-3">
            <i data-lucide="message-square" class="w-8 h-8"></i>
            Send Feedback
          </h2>
        </div>
        <span class="bg-green-500 text-white px-4 py-2 rounded-full text-sm font-semibold">Submitted: <?php echo count($feedback_history); ?></span>
      </div>

      <!-- Success/Error Messages -->
      <?php if (isset($success_message)): ?>
        <div class="bg-green-500/20 border border-green-500/30 text-green-300 px-4 py-3 rounded-lg">
          <?php echo htmlspecialchars($success_message); ?>
        </div>
      <?php endif; ?>
      
      <?php if (isset($error_message)): ?>
        <div class="bg-red-500/20 border border-red-500/30 text-red-300 px-4 py-3 rounded-lg">
          <?php echo htmlspecialchars($error_message); ?>
        </div>
      <?php endif; ?>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Feedback Form -->
        <div class="card">
          <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
            <i data-lucide="send" class="w-5 h-5"></i>
            Send Your Feedback
          </h3>

          <form method="POST" class="space-y-4">
            <div>
              <label class="form-label">Subject</label>
              <input type="text" name="subject" class="form-input" placeholder="What is your feedback about?" 
                     value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>" required>
            </div>
            
            <div>
              <label class="form-label">Category</label>
              <select name="category" class="form-select" required>
                <option value="">Select a category</option>
                <option value="workout" <?php echo ($_POST['category'] ?? '') == 'workout' ? 'selected' : ''; ?>>Workout Plan</option>
                <option value="nutrition" <?php echo ($_POST['category'] ?? '') == 'nutrition' ? 'selected' : ''; ?>>Nutrition Plan</option>
                <option value="trainer" <?php echo ($_POST['category'] ?? '') == 'trainer' ? 'selected' : ''; ?>>Trainer Feedback</option>
                <option value="facility" <?php echo ($_POST['category'] ?? '') == 'facility' ? 'selected' : ''; ?>>Gym Facility</option>
                <option value="equipment" <?php echo ($_POST['category'] ?? '') == 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                <option value="service" <?php echo ($_POST['category'] ?? '') == 'service' ? 'selected' : ''; ?>>Customer Service</option>
                <option value="other" <?php echo ($_POST['category'] ?? '') == 'other' ? 'selected' : ''; ?>>Other</option>
              </select>
            </div>
            
            <div>
              <label class="form-label">Rating (Optional)</label>
              <div class="star-rating" id="starRating">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                  <i data-lucide="star" class="w-8 h-8 star" data-rating="<?php echo $i; ?>"></i>
                <?php endfor; ?>
              </div>
              <input type="hidden" name="rating" id="selectedRating" value="<?php echo $_POST['rating'] ?? ''; ?>">
            </div>
            
            <div>
              <label class="form-label">Your Message</label>
              <textarea name="message" class="form-input" rows="6" placeholder="Please provide detailed feedback about your experience..." required><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
            </div>
            
            <div class="flex items-center gap-3">
              <input type="checkbox" name="urgent" id="urgent" class="rounded bg-gray-800 border-gray-700 w-5 h-5" 
                     <?php echo isset($_POST['urgent']) ? 'checked' : ''; ?>>
              <label for="urgent" class="text-sm text-gray-300">
                Mark as urgent (Requires immediate attention)
              </label>
            </div>
            
            <button type="submit" name="submit_feedback" class="w-full bg-yellow-500 hover:bg-yellow-600 text-white py-3 px-4 rounded-lg transition-colors font-semibold flex items-center justify-center gap-2 min-h-[44px]">
              <i data-lucide="send" class="w-4 h-4"></i>
              Submit Feedback
            </button>
          </form>
        </div>

        <!-- Feedback History -->
        <div class="card">
          <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
            <i data-lucide="history" class="w-5 h-5"></i>
            Feedback History
          </h3>
          
          <div class="space-y-4">
            <?php foreach ($feedback_history as $feedback): ?>
              <div class="feedback-item">
                <div class="flex flex-col md:flex-row md:items-start md:justify-between mb-3">
                  <div class="mb-2 md:mb-0">
                    <div class="font-semibold text-white">
                      <?php 
                      $categories = [
                        'workout' => 'Workout Plan',
                        'nutrition' => 'Nutrition Plan', 
                        'trainer' => 'Trainer Feedback',
                        'facility' => 'Gym Facility',
                        'equipment' => 'Equipment',
                        'service' => 'Customer Service',
                        'other' => 'Other'
                      ];
                      echo $categories[$feedback['category']] ?? 'General Feedback';
                      ?>
                    </div>
                    <div class="text-sm text-gray-400">
                      <?php echo date('F j, Y g:i A', strtotime($feedback['created_at'])); ?>
                    </div>
                  </div>
                  <div class="flex items-center gap-2">
                    <?php if ($feedback['rating']): ?>
                      <div class="flex items-center gap-1">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                          <i data-lucide="star" class="w-4 h-4 <?php echo $i <= $feedback['rating'] ? 'text-yellow-400 fill-yellow-400' : 'text-gray-600'; ?>"></i>
                        <?php endfor; ?>
                      </div>
                    <?php endif; ?>
                    <?php if ($feedback['urgent']): ?>
                      <span class="urgent-badge">Urgent</span>
                    <?php endif; ?>
                  </div>
                </div>
                
                <?php if (!empty($feedback['subject'])): ?>
                  <div class="font-medium text-white mb-2"><?php echo htmlspecialchars($feedback['subject']); ?></div>
                <?php endif; ?>
                
                <div class="text-gray-300 mb-3">
                  <?php echo nl2br(htmlspecialchars($feedback['message'])); ?>
                </div>
                
                <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                  <span class="status-badge status-<?php echo $feedback['status'] ?? 'pending'; ?>">
                    <?php 
                    $statuses = [
                      'pending' => 'Under Review',
                      'reviewed' => 'Being Addressed',
                      'resolved' => 'Resolved'
                    ];
                    echo $statuses[$feedback['status']] ?? 'Submitted';
                    ?>
                  </span>
                  <?php if (isset($feedback['admin_notes'])): ?>
                    <span class="text-xs text-gray-400">
                      Admin Response: <?php echo htmlspecialchars($feedback['admin_notes']); ?>
                    </span>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
            
            <?php if (empty($feedback_history)): ?>
              <div class="text-center py-8 text-gray-500">
                <i data-lucide="message-square" class="w-16 h-16 mx-auto mb-4"></i>
                <p class="text-lg">No feedback submitted yet.</p>
                <p class="text-sm">Share your thoughts with us!</p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Help Section -->
      <div class="card">
        <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
          <i data-lucide="help-circle" class="w-5 h-5"></i>
          Need Help?
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="text-center p-4 bg-gray-800 rounded-lg">
            <i data-lucide="phone" class="w-8 h-8 text-yellow-400 mx-auto mb-2"></i>
            <div class="font-semibold">Call Us</div>
            <div class="text-sm text-gray-400">0936-129-2735</div>
          </div>
          <div class="text-center p-4 bg-gray-800 rounded-lg">
            <i data-lucide="facebook" class="w-8 h-8 text-yellow-400 mx-auto mb-2"></i>
            <div class="font-semibold">Facebook</div>
            <a href="https://www.facebook.com/profile.php?id=61565470524306" target="_blank" class="text-sm text-gray-400 hover:text-yellow-400 transition-colors">
              Boiyets Fitness Gym
            </a>
          </div>
          <div class="text-center p-4 bg-gray-800 rounded-lg">
            <i data-lucide="map-pin" class="w-8 h-8 text-yellow-400 mx-auto mb-2"></i>
            <div class="font-semibold">Visit Us</div>
            <div class="text-sm text-gray-400">Sta. Romana Subd. Abar 1st, San Jose City, Nueva Ecija</div>
          </div>
        </div>
      </div>
    </main>
  </div>


  <script>
  document.addEventListener('DOMContentLoaded', function() {
    // Star rating functionality
    const stars = document.querySelectorAll('.star');
    const selectedRating = document.getElementById('selectedRating');
    
    if (stars.length > 0 && selectedRating) {
        stars.forEach(star => {
          star.addEventListener('click', function() {
            const rating = this.getAttribute('data-rating');
            selectedRating.value = rating;
            
            // Update star display
            stars.forEach((s, index) => {
              if (index < rating) {
                s.classList.add('active', 'fill-yellow-400');
                s.style.color = '#fbbf24';
              } else {
                s.classList.remove('active', 'fill-yellow-400');
                s.style.color = '#6b7280';
              }
            });
          });
          
          star.addEventListener('mouseover', function() {
            const rating = this.getAttribute('data-rating');
            stars.forEach((s, index) => {
              if (index < rating) {
                s.style.color = '#fbbf24';
              }
            });
          });
          
          star.addEventListener('mouseout', function() {
            const currentRating = selectedRating.value;
            stars.forEach((s, index) => {
              if (!currentRating || index >= currentRating) {
                s.style.color = '#6b7280';
              }
            });
          });
        });
        
        // Initialize stars if there's a previous rating
        if (selectedRating.value) {
          stars.forEach((star, index) => {
            if (index < selectedRating.value) {
              star.classList.add('active', 'fill-yellow-400');
              star.style.color = '#fbbf24';
            }
          });
        }
    }
  });
  </script>
<?php require_once 'includes/client_footer.php'; ?>



