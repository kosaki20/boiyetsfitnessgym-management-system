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

function getClientProgress($conn, $user_id) {
    $progress = [];
    $sql = "SELECT cp.* FROM client_progress cp 
            INNER JOIN members m ON cp.member_id = m.id 
            INNER JOIN users u ON m.user_id = u.id 
            WHERE u.id = ? ORDER BY cp.progress_date ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $progress[] = $row;
    }
    
    return $progress;
}

// Handle progress submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_progress'])) {
    $weight = floatval($_POST['weight']);
    $notes = trim($_POST['notes']);
    $progress_date = $_POST['progress_date'];
    
    // Get member_id from user_id
    $member_sql = "SELECT id FROM members WHERE user_id = ?";
    $stmt = $conn->prepare($member_sql);
    $stmt->bind_param("i", $logged_in_user_id);
    $stmt->execute();
    $member_result = $stmt->get_result();
    $member = $member_result->fetch_assoc();
    $member_id = $member['id'];
    
    $insert_sql = "INSERT INTO client_progress (member_id, weight, notes, progress_date) 
                   VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($insert_sql);
    $stmt->bind_param("idss", $member_id, $weight, $notes, $progress_date);
    
    if ($stmt->execute()) {
        $success_message = "Progress recorded successfully!";
        // Refresh progress data
        $progress = getClientProgress($conn, $logged_in_user_id);
    } else {
        $error_message = "Error recording progress: " . $conn->error;
    }
}

$client = getClientDetails($conn, $logged_in_user_id);
$progress = getClientProgress($conn, $logged_in_user_id);

// Calculate progress statistics
$total_entries = count($progress);
$latest_weight = $total_entries > 0 ? end($progress)['weight'] : null;
$starting_weight = $total_entries > 0 ? $progress[0]['weight'] : null;
$weight_change = $latest_weight && $starting_weight ? $latest_weight - $starting_weight : 0;
$weight_change_percentage = $starting_weight ? round(($weight_change / $starting_weight) * 100, 1) : 0;

// Prepare data for charts
$chart_labels = [];
$chart_weights = [];

foreach ($progress as $entry) {
    $chart_labels[] = date('M j', strtotime($entry['progress_date']));
    $chart_weights[] = $entry['weight'];
}
?>
<?php 
$page_title = 'My Progress';
require_once 'includes/client_header.php'; 
?>
<style>
    .progress-item {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
        border-left: 4px solid #fbbf24;
    }
    
    .chart-container {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 12px;
        padding: 1.5rem;
        height: 300px;
    }
    
    .stats-card {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 8px;
        padding: 1.5rem;
        text-align: center;
        transition: all 0.2s ease;
    }
    
    .stats-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    }
    
    .tab-button {
        background: transparent;
        color: #9ca3af;
        padding: 0.75rem 1.5rem;
        border: none;
        border-bottom: 2px solid transparent;
        cursor: pointer;
        transition: all 0.2s ease;
        font-weight: 500;
        min-height: 44px;
    }
    
    .tab-button.active {
        color: #fbbf24;
        border-bottom-color: #fbbf24;
    }
    
    .tab-content {
        display: none;
    }
    
    .tab-content.active {
        display: block;
    }
    
    .measurement-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
        margin-bottom: 1rem;
    }
    
    .measurement-item {
        background: rgba(255, 255, 255, 0.03);
        border-radius: 8px;
        padding: 1rem;
        text-align: center;
    }

    .form-input {
        background: rgba(255, 255, 255, 0.05) !important;
        border: 1px solid rgba(255, 255, 255, 0.1) !important;
        border-radius: 8px !important;
        padding: 0.75rem !important;
        color: white !important;
        width: 100% !important;
        transition: all 0.2s ease !important;
        min-height: 44px !important;
    }
    
    .form-label {
        display: block;
        margin-bottom: 0.25rem;
        font-size: 0.8rem;
        color: #d1d5db;
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
            <i data-lucide="activity" class="w-8 h-8"></i>
            Weight Progress Tracking
          </h2>
        </div>
        <span class="bg-green-500 text-white px-4 py-2 rounded-full text-sm font-semibold">Records: <?php echo $total_entries; ?></span>
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

      <!-- Progress Overview Stats -->
      <?php if ($total_entries > 0): ?>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
        <div class="stats-card">
          <div class="text-3xl font-bold text-yellow-400 mb-2"><?php echo $latest_weight; ?> kg</div>
          <div class="text-gray-400">Current Weight</div>
          <div class="text-sm text-gray-500 mt-2">Latest measurement</div>
        </div>
        
        <div class="stats-card">
          <div class="text-3xl font-bold <?php echo $weight_change < 0 ? 'text-green-400' : ($weight_change > 0 ? 'text-red-400' : 'text-gray-400'); ?> mb-2">
            <?php echo ($weight_change > 0 ? '+' : '') . number_format($weight_change, 1); ?> kg
          </div>
          <div class="text-gray-400">Total Change</div>
          <div class="text-sm text-gray-500 mt-2">Since you started</div>
        </div>
        
        <div class="stats-card">
          <div class="text-3xl font-bold text-blue-400 mb-2"><?php echo $total_entries; ?></div>
          <div class="text-gray-400">Total Entries</div>
          <div class="text-sm text-gray-500 mt-2">Records tracked</div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Tabs Navigation -->
      <div class="border-b border-gray-700 mb-6">
        <div class="flex space-x-4 overflow-x-auto">
          <button class="tab-button active" data-tab="charts">
            <i data-lucide="trending-up" class="w-4 h-4 mr-2"></i>
            Progress Chart
          </button>
          <button class="tab-button" data-tab="add-entry">
            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
            Add Weight Entry
          </button>
          <button class="tab-button" data-tab="history">
            <i data-lucide="history" class="w-4 h-4 mr-2"></i>
            Weight History
          </button>
        </div>
      </div>

      <!-- Charts Tab -->
      <div class="tab-content active" id="charts">
        <?php if ($total_entries > 1): ?>
        <div class="card">
          <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
            <i data-lucide="scale" class="w-5 h-5"></i>
            Weight Progress
          </h3>
          <div class="chart-container">
            <canvas id="weightChart"></canvas>
          </div>
        </div>
        <?php else: ?>
        <div class="card text-center py-12">
          <i data-lucide="trending-up" class="w-16 h-16 text-gray-600 mx-auto mb-4"></i>
          <h3 class="text-2xl font-semibold text-gray-400 mb-4">Not Enough Data Yet</h3>
          <p class="text-gray-500 text-lg mb-6">Add at least 2 weight entries to see progress chart.</p>
        </div>
        <?php endif; ?>
      </div>

      <!-- Add Entry Tab -->
      <div class="tab-content" id="add-entry">
        <div class="card">
          <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
            <i data-lucide="plus" class="w-5 h-5"></i>
            Add Weight Entry
          </h3>
          
          <form method="POST" class="space-y-6">
            <div>
              <label class="form-label">Date</label>
              <input type="date" name="progress_date" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            
            <div>
              <label class="form-label">Weight (kg) *</label>
              <input type="number" name="weight" step="0.1" class="form-input" placeholder="70.5" required>
            </div>
            
            <div>
              <label class="form-label">Notes & Observations</label>
              <textarea name="notes" class="form-input" rows="3" placeholder="How are you feeling? Any challenges or achievements? Changes in energy levels, etc."></textarea>
            </div>
            
            <button type="submit" name="add_progress" class="w-full bg-yellow-500 hover:bg-yellow-600 text-white py-3 px-4 rounded-lg transition-colors font-semibold flex items-center justify-center gap-2">
              <i data-lucide="save" class="w-4 h-4"></i>
              Save Weight Entry
            </button>
          </form>
        </div>
      </div>

      <!-- History Tab -->
      <div class="tab-content" id="history">
        <div class="card">
          <h3 class="text-xl font-bold text-yellow-400 mb-4 flex items-center gap-2">
            <i data-lucide="history" class="w-5 h-5"></i>
            Weight History
          </h3>
          
          <div class="space-y-4">
            <?php foreach (array_reverse($progress) as $index => $entry): ?>
              <div class="progress-item">
                <div class="flex justify-between items-start mb-4">
                  <h4 class="font-semibold text-white text-lg"><?php echo date('F j, Y', strtotime($entry['progress_date'])); ?></h4>
                  <span class="bg-blue-500 text-white px-2 py-1 rounded text-xs font-semibold">
                    <?php echo $total_entries - $index; ?><?php echo $total_entries - $index == 1 ? 'st' : ($total_entries - $index == 2 ? 'nd' : ($total_entries - $index == 3 ? 'rd' : 'th')); ?> Entry
                  </span>
                </div>
                
                <div class="measurement-grid">
                  <div class="measurement-item">
                    <div class="text-2xl font-bold text-yellow-400"><?php echo $entry['weight']; ?> kg</div>
                    <div class="text-sm text-gray-400">Weight</div>
                  </div>
                </div>
                
                <?php 
                // Calculate changes from previous entry
                $current_index = array_search($entry, $progress);
                if ($current_index > 0) {
                  $previous_entry = $progress[$current_index - 1];
                  $weight_change = $entry['weight'] - $previous_entry['weight'];
                  $change_class = $weight_change < 0 ? 'progress-positive' : ($weight_change > 0 ? 'progress-negative' : 'progress-neutral');
                  $change_icon = $weight_change < 0 ? 'trending-down' : ($weight_change > 0 ? 'trending-up' : 'minus');
                ?>
                  <div class="mt-3 p-3 bg-gray-800 rounded-lg">
                    <div class="flex items-center gap-2 text-sm font-semibold <?php echo str_replace('progress-', 'text-', $change_class); ?>">
                      <i data-lucide="<?php echo $change_icon; ?>" class="w-4 h-4"></i>
                      Weight Change: <?php echo ($weight_change > 0 ? '+' : '') . number_format($weight_change, 1); ?> kg
                    </div>
                  </div>
                <?php } ?>
                
                <?php if (!empty($entry['notes'])): ?>
                  <div class="mt-3 p-3 bg-gray-800 rounded-lg">
                    <p class="text-sm text-gray-300"><?php echo htmlspecialchars($entry['notes']); ?></p>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
            
            <?php if (empty($progress)): ?>
              <div class="text-center py-8 text-gray-500">
                <i data-lucide="activity" class="w-16 h-16 mx-auto mb-4"></i>
                <p class="text-lg">No weight entries yet.</p>
                <p class="text-sm">Start tracking your fitness journey by adding your first weight entry!</p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </main>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Tab functionality
      const tabButtons = document.querySelectorAll('.tab-button');
      const tabContents = document.querySelectorAll('.tab-content');
      
      tabButtons.forEach(button => {
        button.addEventListener('click', () => {
          const tabId = button.getAttribute('data-tab');
          
          // Update active button
          tabButtons.forEach(btn => btn.classList.remove('active'));
          button.classList.add('active');
          
          // Update active content
          tabContents.forEach(content => {
            if (content.id === tabId) {
              content.classList.add('active');
            } else {
              content.classList.remove('active');
            }
          });
        });
      });
    });

<?php if ($total_entries > 1): ?>
    // Initialize Weight Chart
    const weightChart = new Chart(
      document.getElementById('weightChart'),
      {
        type: 'line',
        data: {
          labels: <?php echo json_encode($chart_labels); ?>,
          datasets: [{
            label: 'Weight (kg)',
            data: <?php echo json_encode($chart_weights); ?>,
            borderColor: '#fbbf24',
            backgroundColor: 'rgba(251, 191, 36, 0.1)',
            borderWidth: 3,
            fill: true,
            tension: 0.4,
            pointBackgroundColor: '#fbbf24',
            pointBorderColor: '#ffffff',
            pointBorderWidth: 2,
            pointRadius: 5,
            pointHoverRadius: 7
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              labels: {
                color: '#e2e8f0',
                font: {
                  size: 14
                }
              }
            },
            tooltip: {
              backgroundColor: 'rgba(26, 26, 26, 0.9)',
              titleColor: '#fbbf24',
              bodyColor: '#e2e8f0',
              borderColor: '#fbbf24',
              borderWidth: 1
            }
          },
          scales: {
            x: {
              grid: {
                color: 'rgba(255, 255, 255, 0.1)'
              },
              ticks: {
                color: '#9ca3af'
              }
            },
            y: {
              grid: {
                color: 'rgba(255, 255, 255, 0.1)'
              },
              ticks: {
                color: '#9ca3af',
                callback: function(value) {
                  return value + ' kg';
                }
              }
            }
          }
        }
      }
    );
    <?php endif; ?>
  </script>
<?php require_once 'includes/client_footer.php'; ?>



