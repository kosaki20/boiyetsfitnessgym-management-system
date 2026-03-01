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
$current_trainer_id = $_SESSION['user_id'];

// Function to get workout plans
function getWorkoutPlans($conn, $trainer_id = null) {
    $workoutPlans = [];
    $sql = "SELECT wp.*, m.full_name, m.fitness_goals 
            FROM workout_plans wp 
            JOIN members m ON wp.member_id = m.id 
            WHERE m.member_type = 'client'";
    
    if ($trainer_id) {
        $sql .= " AND wp.created_by = ?";
    }
    
    $sql .= " ORDER BY wp.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if ($trainer_id) {
        $stmt->bind_param("i", $trainer_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $row['exercises'] = json_decode($row['exercises'], true) ?: [];
        $workoutPlans[] = $row;
    }
    
    return $workoutPlans;
}

// Function to get workout templates
function getWorkoutTemplates($conn, $trainer_id = null) {
    $templates = [];
    $sql = "SELECT * FROM workout_templates WHERE 1=1";
    
    if ($trainer_id) {
        $sql .= " AND created_by = ?";
    }
    
    $sql .= " ORDER BY created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if ($trainer_id) {
        $stmt->bind_param("i", $trainer_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $row['exercises'] = json_decode($row['exercises'], true) ?: [];
        $templates[] = $row;
    }
    
    return $templates;
}

// Function to get clients
function getClients($conn) {
    $clients = [];
    $sql = "SELECT * FROM members WHERE member_type = 'client' AND status = 'active' ORDER BY full_name";
    $result = $conn->query($sql);
    
    while ($row = $result->fetch_assoc()) {
        $clients[] = $row;
    }
    
    return $clients;
}

// Create workout_templates table if not exists
$createTableSQL = "CREATE TABLE IF NOT EXISTS workout_templates (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    template_name VARCHAR(100) NOT NULL,
    description TEXT,
    exercises LONGTEXT NOT NULL,
    schedule ENUM('daily','weekly','custom') DEFAULT 'weekly',
    difficulty ENUM('beginner','intermediate','advanced') DEFAULT 'beginner',
    goal ENUM('weight_loss','muscle_gain','strength','endurance','general_fitness') DEFAULT 'general_fitness',
    created_by INT(11) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if (!$conn->query($createTableSQL)) {
    die("Error creating workout_templates table: " . $conn->error);
}

// Process workout template form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_workout_template'])) {
    $template_name = $_POST['template_name'];
    $description = $_POST['description'];
    $schedule = $_POST['schedule'];
    $difficulty = $_POST['difficulty'];
    $goal = $_POST['goal'];
    
    $exercises = [];
    if (isset($_POST['exercise_names'])) {
        for ($i = 0; $i < count($_POST['exercise_names']); $i++) {
            if (!empty($_POST['exercise_names'][$i])) {
                $exercises[] = [
                    'name' => $_POST['exercise_names'][$i],
                    'sets' => $_POST['exercise_sets'][$i] ?? '3',
                    'reps' => $_POST['exercise_reps'][$i] ?? '8-12',
                    'rest' => $_POST['exercise_rest'][$i] ?? '60s',
                    'notes' => $_POST['exercise_notes'][$i] ?? ''
                ];
            }
        }
    }
    
    if (empty($exercises)) {
        $workout_error = "Please add at least one exercise to the template.";
    } else {
        $exercises_json = json_encode($exercises);
        
        $sql = "INSERT INTO workout_templates (template_name, description, exercises, schedule, difficulty, goal, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssi", $template_name, $description, $exercises_json, $schedule, $difficulty, $goal, $current_trainer_id);
        
        if ($stmt->execute()) {
            $workout_success = "Workout template created successfully!";
            header("Refresh: 2");
        } else {
            $workout_error = "Error creating workout template: " . $conn->error;
        }
    }
}

// Process template assignment to client
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_template'])) {
    $template_id = $_POST['template_id'];
    $member_id = $_POST['member_id'];
    $plan_name = $_POST['plan_name'];
    
    // Get template data
    $sql = "SELECT * FROM workout_templates WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $template_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $template = $result->fetch_assoc();
    
    if ($template) {
        $sql = "INSERT INTO workout_plans (member_id, plan_name, description, schedule, exercises, created_by) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("issssi", $member_id, $plan_name, $template['description'], $template['schedule'], $template['exercises'], $current_trainer_id);
        
        if ($stmt->execute()) {
            $workout_success = "Workout template assigned to client successfully!";
            header("Refresh: 2");
        } else {
            $workout_error = "Error assigning template: " . $conn->error;
        }
    }
}

// Process custom workout plan form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_workout_plan'])) {
    $member_id = $_POST['member_id'];
    $plan_name = $_POST['plan_name'];
    $description = $_POST['description'];
    $schedule = $_POST['schedule'];
    
    $exercises = [];
    if (isset($_POST['exercise_names'])) {
        for ($i = 0; $i < count($_POST['exercise_names']); $i++) {
            if (!empty($_POST['exercise_names'][$i])) {
                $exercises[] = [
                    'name' => $_POST['exercise_names'][$i],
                    'sets' => $_POST['exercise_sets'][$i] ?? '3',
                    'reps' => $_POST['exercise_reps'][$i] ?? '8-12',
                    'rest' => $_POST['exercise_rest'][$i] ?? '60s',
                    'notes' => $_POST['exercise_notes'][$i] ?? ''
                ];
            }
        }
    }
    
    if (empty($exercises)) {
        $workout_error = "Please add at least one exercise.";
    } else {
        $exercises_json = json_encode($exercises);
        
        $sql = "INSERT INTO workout_plans (member_id, plan_name, description, schedule, exercises, created_by) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("issssi", $member_id, $plan_name, $description, $schedule, $exercises_json, $current_trainer_id);
        
        if ($stmt->execute()) {
            $workout_success = "Workout plan created successfully!";
            header("Refresh: 2");
        } else {
            $workout_error = "Error creating workout plan: " . $conn->error;
        }
    }
}

// Delete workout plan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_workout_plan'])) {
    $plan_id = $_POST['plan_id'];
    
    $sql = "DELETE FROM workout_plans WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $plan_id);
    
    if ($stmt->execute()) {
        $workout_success = "Workout plan deleted successfully!";
        header("Refresh: 2");
    } else {
        $workout_error = "Error deleting workout plan: " . $conn->error;
    }
}

// Delete workout template
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_workout_template'])) {
    $template_id = $_POST['template_id'];
    
    $sql = "DELETE FROM workout_templates WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $template_id);
    
    if ($stmt->execute()) {
        $workout_success = "Workout template deleted successfully!";
        header("Refresh: 2");
    } else {
        $workout_error = "Error deleting workout template: " . $conn->error;
    }
}

$clients = getClients($conn);
$workoutPlans = getWorkoutPlans($conn, $current_trainer_id);
$workoutTemplates = getWorkoutTemplates($conn, $current_trainer_id);
?>

<?php require_once 'includes/trainer_header.php'; ?>
<?php require_once 'includes/trainer_sidebar.php'; ?>
          <h1 class="text-3xl font-bold text-yellow-400 flex items-center gap-3">
            <i data-lucide="dumbbell"></i>
            Workout Plans & Templates
          </h1>
          <p class="text-gray-400 mt-2">Create templates and assign personalized workout plans to clients</p>
        </div>
        <div class="flex gap-3">
          <a href="trainermealplan.php" class="btn btn-primary">
            <i data-lucide="utensils"></i> Meal Plans
          </a>
        </div>
      </div>

      <?php if (isset($workout_success)): ?>
        <div class="alert alert-success">
          <i data-lucide="check-circle" class="w-5 h-5 mr-2"></i>
          <?php echo $workout_success; ?>
        </div>
      <?php endif; ?>

      <?php if (isset($workout_error)): ?>
        <div class="alert alert-error">
          <i data-lucide="alert-circle" class="w-5 h-5 mr-2"></i>
          <?php echo $workout_error; ?>
        </div>
      <?php endif; ?>

      <!-- Quick Actions -->
      <div class="quick-actions mb-8">
        <div class="action-card" onclick="showTab('templates-tab')">
          <i data-lucide="layout-template" class="w-8 h-8 mx-auto"></i>
          <h3 class="font-semibold text-white mb-1">Create Template</h3>
          <p class="text-gray-400 text-sm">Design reusable workout splits</p>
        </div>
        
        <div class="action-card" onclick="showTab('assign-tab')">
          <i data-lucide="user-check" class="w-8 h-8 mx-auto"></i>
          <h3 class="font-semibold text-white mb-1">Assign to Client</h3>
          <p class="text-gray-400 text-sm">Use templates for clients</p>
        </div>
        
        <div class="action-card" onclick="showTab('custom-tab')">
          <i data-lucide="plus" class="w-8 h-8 mx-auto"></i>
          <h3 class="font-semibold text-white mb-1">Custom Plan</h3>
          <p class="text-gray-400 text-sm">Create unique workout plan</p>
        </div>
        
        <div class="action-card" onclick="window.open('https://www.youtube.com/@BoiyetsFitnessGym', '_blank')">
          <i data-lucide="youtube" class="w-8 h-8 mx-auto text-red-500"></i>
          <h3 class="font-semibold text-white mb-1">youtube</h3>
          <p class="text-gray-400 text-sm">Upload workout tutorials</p>
        </div>
      </div>

      <!-- Statistics Overview -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="stat-card">
          <div class="text-2xl font-bold text-yellow-400 mb-2"><?php echo count($workoutPlans); ?></div>
          <div class="text-gray-400 text-sm">Active Plans</div>
        </div>
        <div class="stat-card">
          <div class="text-2xl font-bold text-purple-400 mb-2"><?php echo count($workoutTemplates); ?></div>
          <div class="text-gray-400 text-sm">Templates</div>
        </div>
        <div class="stat-card">
          <div class="text-2xl font-bold text-green-400 mb-2"><?php echo count($clients); ?></div>
          <div class="text-gray-400 text-sm">Active Clients</div>
        </div>
        <div class="stat-card">
          <div class="text-2xl font-bold text-blue-400 mb-2">
            <?php echo count(array_unique(array_column($workoutPlans, 'member_id'))); ?>
          </div>
          <div class="text-gray-400 text-sm">Clients with Plans</div>
        </div>
      </div>

      <!-- Tabs Navigation -->
      <div class="tabs">
        <button class="tab active" onclick="showTab('templates-tab')">
          <i data-lucide="layout-template" class="w-4 h-4 mr-2"></i>
          Workout Templates
        </button>
        <button class="tab" onclick="showTab('assign-tab')">
          <i data-lucide="user-check" class="w-4 h-4 mr-2"></i>
          Assign to Clients
        </button>
        <button class="tab" onclick="showTab('custom-tab')">
          <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
          Custom Plans
        </button>
        <button class="tab" onclick="showTab('plans-tab')">
          <i data-lucide="list" class="w-4 h-4 mr-2"></i>
          All Plans (<?php echo count($workoutPlans); ?>)
        </button>
      </div>

      <!-- Workout Templates Tab -->
      <div id="templates-tab" class="tab-content active">
        <div class="card">
          <div class="section-header">
            <h2 class="section-title">
              <i data-lucide="layout-template"></i>
              Create Workout Template
            </h2>
            <span class="text-gray-400"><?php echo count($workoutTemplates); ?> templates created</span>
          </div>
          
          <form method="POST" id="templateForm">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
              <!-- Template Info -->
              <div class="space-y-6">
                <div>
                  <label class="form-label">Template Name *</label>
                  <input type="text" name="template_name" class="form-input" placeholder="e.g., Beginner Full Body Split" required>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                  <div>
                    <label class="form-label">Difficulty Level</label>
                    <select name="difficulty" class="form-select" required>
                      <option value="beginner">Beginner</option>
                      <option value="intermediate">Intermediate</option>
                      <option value="advanced">Advanced</option>
                    </select>
                  </div>
                  <div>
                    <label class="form-label">Primary Goal</label>
                    <select name="goal" class="form-select" required>
                      <option value="weight_loss">Weight Loss</option>
                      <option value="muscle_gain">Muscle Gain</option>
                      <option value="strength">Strength</option>
                      <option value="endurance">Endurance</option>
                      <option value="general_fitness">General Fitness</option>
                    </select>
                  </div>
                </div>
                
                <div>
                  <label class="form-label">Schedule</label>
                  <select name="schedule" class="form-select" required>
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="custom">Custom</option>
                  </select>
                </div>
                
                <div>
                  <label class="form-label">Template Description</label>
                  <textarea name="description" class="form-input" rows="4" placeholder="Describe this workout template, its focus areas, and when to use it..."></textarea>
                </div>
              </div>

              <!-- Exercises Section -->
              <div>
                <div class="flex justify-between items-center mb-4">
                  <label class="form-label">Exercises *</label>
                  <span class="text-sm text-gray-400" id="template-exercise-count">1 exercise added</span>
                </div>
                
                <div id="template-exercises-container" class="space-y-4 max-h-96 overflow-y-auto pr-2">
                  <div class="exercise-item">
                    <div class="flex justify-between items-center mb-3">
                      <h4 class="font-semibold text-white">Exercise #1</h4>
                      <button type="button" onclick="this.parentElement.parentElement.remove(); updateTemplateExerciseCount()" class="text-red-400 hover:text-red-300">
                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                      </button>
                    </div>
                    <div class="space-y-3">
                      <div>
                        <label class="form-label text-xs">Exercise Name *</label>
                        <input type="text" name="exercise_names[]" class="form-input" placeholder="e.g., Bench Press" required>
                      </div>
                      <div class="grid grid-cols-3 gap-3">
                        <div>
                          <label class="form-label text-xs">Sets *</label>
                          <input type="text" name="exercise_sets[]" class="form-input" placeholder="3" required>
                        </div>
                        <div>
                          <label class="form-label text-xs">Reps *</label>
                          <input type="text" name="exercise_reps[]" class="form-input" placeholder="8-12" required>
                        </div>
                        <div>
                          <label class="form-label text-xs">Rest *</label>
                          <input type="text" name="exercise_rest[]" class="form-input" placeholder="60s" required>
                        </div>
                      </div>
                      <div>
                        <label class="form-label text-xs">Technique Notes</label>
                        <textarea name="exercise_notes[]" class="form-input" placeholder="Form tips, variations, progression..." rows="2"></textarea>
                      </div>
                    </div>
                  </div>
                </div>
                
                <button type="button" onclick="addTemplateExercise()" class="btn btn-primary w-full mt-4">
                  <i data-lucide="plus"></i> Add Another Exercise
                </button>
                
                <button type="submit" name="save_workout_template" class="btn btn-purple w-full mt-4">
                  <i data-lucide="save"></i> Save Workout Template
                </button>
              </div>
            </div>
          </form>
        </div>

        <!-- Templates List -->
        <?php if (!empty($workoutTemplates)): ?>
          <div class="card mt-8">
            <div class="section-header">
              <h2 class="section-title">
                <i data-lucide="library"></i>
                My Workout Templates
              </h2>
              <span class="text-gray-400"><?php echo count($workoutTemplates); ?> templates</span>
            </div>
            
            <div class="space-y-4">
              <?php foreach ($workoutTemplates as $template): ?>
                <div class="template-card">
                  <div class="flex justify-between items-start mb-4">
                    <div class="flex-1">
                      <div class="flex items-center gap-3 mb-2">
                        <h3 class="font-semibold text-white text-xl"><?php echo htmlspecialchars($template['template_name']); ?></h3>
                        <span class="badge badge-purple"><?php echo ucfirst($template['difficulty']); ?></span>
                        <span class="badge badge-blue"><?php echo ucfirst(str_replace('_', ' ', $template['goal'])); ?></span>
                        <span class="badge badge-yellow"><?php echo ucfirst($template['schedule']); ?></span>
                      </div>
                      <?php if ($template['description']): ?>
                        <p class="text-gray-300 text-sm mb-3"><?php echo htmlspecialchars($template['description']); ?></p>
                      <?php endif; ?>
                      <div class="text-sm text-gray-400">
                        Created: <?php echo date('M j, Y', strtotime($template['created_at'])); ?>
                      </div>
                    </div>
                    <div class="flex gap-2">
                      <button onclick="assignTemplate(<?php echo $template['id']; ?>, '<?php echo htmlspecialchars($template['template_name']); ?>')" class="btn btn-success">
                        <i data-lucide="user-check"></i> Assign
                      </button>
                      <form method="POST" onsubmit="return confirm('Are you sure you want to delete this template?')">
                        <input type="hidden" name="template_id" value="<?php echo $template['id']; ?>">
                        <button type="submit" name="delete_workout_template" class="btn btn-danger">
                          <i data-lucide="trash-2"></i>
                        </button>
                      </form>
                    </div>
                  </div>
                  
                  <div class="table-container">
                    <table>
                      <thead>
                        <tr>
                          <th>Exercise</th>
                          <th>Sets</th>
                          <th>Reps</th>
                          <th>Rest</th>
                          <th>Notes</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($template['exercises'] as $index => $exercise): ?>
                          <tr>
                            <td class="font-medium">
                              <span class="badge badge-green mr-2"><?php echo $index + 1; ?></span>
                              <?php echo htmlspecialchars($exercise['name']); ?>
                            </td>
                            <td><?php echo isset($exercise['sets']) ? $exercise['sets'] : 'N/A'; ?></td>
                            <td><?php echo isset($exercise['reps']) ? $exercise['reps'] : 'N/A'; ?></td>
                            <td><?php echo isset($exercise['rest']) ? $exercise['rest'] : 'N/A'; ?></td>
                            <td class="text-gray-400 text-sm">
                              <?php if (isset($exercise['notes']) && $exercise['notes']): ?>
                                <?php echo htmlspecialchars($exercise['notes']); ?>
                              <?php else: ?>
                                <span class="text-gray-500">-</span>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Assign Template Tab -->
      <div id="assign-tab" class="tab-content">
        <div class="card">
          <div class="section-header">
            <h2 class="section-title">
              <i data-lucide="user-check"></i>
              Assign Template to Client
            </h2>
          </div>
          
          <form method="POST" id="assignTemplateForm">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
              <div class="space-y-6">
                <div>
                  <label class="form-label">Select Template *</label>
                  <select name="template_id" class="form-select" required onchange="updateTemplatePreview(this)">
                    <option value="">Choose a template...</option>
                    <?php foreach ($workoutTemplates as $template): ?>
                      <option value="<?php echo $template['id']; ?>" data-exercises='<?php echo json_encode($template['exercises']); ?>'>
                        <?php echo htmlspecialchars($template['template_name']); ?> (<?php echo ucfirst($template['difficulty']); ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                
                <div>
                  <label class="form-label">Select Client *</label>
                  <select name="member_id" class="form-select" required onchange="updateClientGoals(this)">
                    <option value="">Choose a client...</option>
                    <?php foreach ($clients as $client): ?>
                      <option value="<?php echo $client['id']; ?>" data-goals="<?php echo htmlspecialchars($client['fitness_goals']); ?>">
                        <?php echo htmlspecialchars($client['full_name']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div id="assign-client-goals" class="text-sm text-yellow-400 mt-2 hidden">
                    <i data-lucide="target" class="w-4 h-4 inline"></i>
                    <strong>Goals:</strong> <span id="assign-goals-text"></span>
                  </div>
                </div>
                
                <div>
                  <label class="form-label">Plan Name *</label>
                  <input type="text" name="plan_name" class="form-input" placeholder="e.g., Personalized Strength Program" required>
                </div>
                
                <button type="submit" name="assign_template" class="btn btn-success w-full">
                  <i data-lucide="user-check"></i> Assign Template to Client
                </button>
              </div>
              
              <div>
                <h3 class="font-semibold text-white mb-4">Template Preview</h3>
                <div id="template-preview" class="bg-gray-800/50 rounded-lg p-4 min-h-200">
                  <p class="text-gray-400 text-center">Select a template to preview exercises</p>
                </div>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- Custom Plan Tab -->
      <div id="custom-tab" class="tab-content">
        <div class="card">
          <div class="section-header">
            <h2 class="section-title">
              <i data-lucide="plus"></i>
              Create Custom Workout Plan
            </h2>
          </div>
          
          <form method="POST" id="workoutForm">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
              <!-- Client Selection & Basic Info -->
              <div class="space-y-6">
                <div>
                  <label class="form-label">Select Client *</label>
                  <select name="member_id" class="form-select" required onchange="updateClientGoals(this)">
                    <option value="">Choose a client...</option>
                    <?php foreach ($clients as $client): ?>
                      <option value="<?php echo $client['id']; ?>" data-goals="<?php echo htmlspecialchars($client['fitness_goals']); ?>">
                        <?php echo htmlspecialchars($client['full_name']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div id="custom-client-goals" class="text-sm text-yellow-400 mt-2 hidden">
                    <i data-lucide="target" class="w-4 h-4 inline"></i>
                    <strong>Goals:</strong> <span id="custom-goals-text"></span>
                  </div>
                </div>
                
                <div>
                  <label class="form-label">Plan Name *</label>
                  <input type="text" name="plan_name" class="form-input" placeholder="e.g., Beginner Strength Program" required>
                </div>
                
                <div>
                  <label class="form-label">Schedule *</label>
                  <select name="schedule" class="form-select" required>
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="custom">Custom</option>
                  </select>
                </div>
                
                <div>
                  <label class="form-label">Description</label>
                  <textarea name="description" class="form-input" rows="4" placeholder="Describe the workout plan objectives, focus areas, and any special instructions..."></textarea>
                </div>
              </div>

              <!-- Exercises Section -->
              <div>
                <div class="flex justify-between items-center mb-4">
                  <label class="form-label">Exercises *</label>
                  <span class="text-sm text-gray-400" id="exercise-count">1 exercise added</span>
                </div>
                
                <div id="exercises-container" class="space-y-4 max-h-96 overflow-y-auto pr-2">
                  <div class="exercise-item">
                    <div class="flex justify-between items-center mb-3">
                      <h4 class="font-semibold text-white">Exercise #1</h4>
                      <button type="button" onclick="this.parentElement.parentElement.remove(); updateExerciseCount()" class="text-red-400 hover:text-red-300">
                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                      </button>
                    </div>
                    <div class="space-y-3">
                      <div>
                        <label class="form-label text-xs">Exercise Name *</label>
                        <input type="text" name="exercise_names[]" class="form-input" placeholder="e.g., Bench Press" required>
                      </div>
                      <div class="grid grid-cols-3 gap-3">
                        <div>
                          <label class="form-label text-xs">Sets *</label>
                          <input type="text" name="exercise_sets[]" class="form-input" placeholder="3" required>
                        </div>
                        <div>
                          <label class="form-label text-xs">Reps *</label>
                          <input type="text" name="exercise_reps[]" class="form-input" placeholder="8-12" required>
                        </div>
                        <div>
                          <label class="form-label text-xs">Rest *</label>
                          <input type="text" name="exercise_rest[]" class="form-input" placeholder="60s" required>
                        </div>
                      </div>
                      <div>
                        <label class="form-label text-xs">Technique Notes</label>
                        <textarea name="exercise_notes[]" class="form-input" placeholder="Form tips, variations, progression..." rows="2"></textarea>
                      </div>
                    </div>
                  </div>
                </div>
                
                <button type="button" onclick="addExercise()" class="btn btn-primary w-full mt-4">
                  <i data-lucide="plus"></i> Add Another Exercise
                </button>
                
                <button type="submit" name="save_workout_plan" class="btn btn-success w-full mt-4">
                  <i data-lucide="save"></i> Create Workout Plan
                </button>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- All Plans Tab -->
      <div id="plans-tab" class="tab-content">
        <div class="card">
          <div class="section-header">
            <h2 class="section-title">
              <i data-lucide="list"></i>
              All Client Workout Plans
            </h2>
            <span class="text-gray-400"><?php echo count($workoutPlans); ?> active plans</span>
          </div>
          
          <div class="space-y-6">
            <?php if (!empty($workoutPlans)): ?>
              <?php foreach ($workoutPlans as $plan): ?>
                <div class="plan-card">
                  <div class="flex justify-between items-start mb-4">
                    <div class="flex-1">
                      <div class="flex items-center gap-3 mb-2">
                        <h3 class="font-semibold text-white text-xl"><?php echo htmlspecialchars($plan['plan_name']); ?></h3>
                        <span class="badge badge-blue"><?php echo ucfirst($plan['schedule']); ?></span>
                      </div>
                      <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                        <div>
                          <span class="text-gray-400">Client:</span>
                          <span class="text-white font-medium"><?php echo htmlspecialchars($plan['full_name']); ?></span>
                        </div>
                        <div>
                          <span class="text-gray-400">Goals:</span>
                          <span class="text-yellow-400"><?php echo htmlspecialchars($plan['fitness_goals']); ?></span>
                        </div>
                        <div>
                          <span class="text-gray-400">Created:</span>
                          <span class="text-gray-300"><?php echo date('M j, Y', strtotime($plan['created_at'])); ?></span>
                        </div>
                      </div>
                    </div>
                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this workout plan?')">
                      <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                      <button type="submit" name="delete_workout_plan" class="btn btn-danger">
                        <i data-lucide="trash-2"></i> Delete
                      </button>
                    </form>
                  </div>
                  
                  <?php if ($plan['description']): ?>
                    <p class="text-gray-300 mb-4 text-sm bg-gray-800/50 p-3 rounded-lg"><?php echo htmlspecialchars($plan['description']); ?></p>
                  <?php endif; ?>
                  
                  <div class="table-container">
                    <table>
                      <thead>
                        <tr>
                          <th>Exercise</th>
                          <th>Sets</th>
                          <th>Reps</th>
                          <th>Rest</th>
                          <th>Notes</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($plan['exercises'] as $index => $exercise): ?>
                          <tr>
                            <td class="font-medium">
                              <span class="badge badge-green mr-2"><?php echo $index + 1; ?></span>
                              <?php echo htmlspecialchars($exercise['name']); ?>
                            </td>
                            <td><?php echo isset($exercise['sets']) ? $exercise['sets'] : 'N/A'; ?></td>
                            <td><?php echo isset($exercise['reps']) ? $exercise['reps'] : 'N/A'; ?></td>
                            <td><?php echo isset($exercise['rest']) ? $exercise['rest'] : 'N/A'; ?></td>
                            <td class="text-gray-400 text-sm">
                              <?php if (isset($exercise['notes']) && $exercise['notes']): ?>
                                <?php echo htmlspecialchars($exercise['notes']); ?>
                              <?php else: ?>
                                <span class="text-gray-500">-</span>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="empty-state">
                <i data-lucide="dumbbell" class="w-16 h-16 mx-auto mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-400 mb-2">No Workout Plans Yet</h3>
                <p class="text-gray-500">Create your first workout plan or assign a template to get started!</p>
                <div class="flex gap-3 justify-center mt-4">
                  <button onclick="showTab('templates-tab')" class="btn btn-purple">
                    <i data-lucide="layout-template"></i> Create Template
                  </button>
                  <button onclick="showTab('custom-tab')" class="btn btn-primary">
                    <i data-lucide="plus"></i> Create Plan
                  </button>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </main>
  </div>

  <!-- Assign Template Modal -->
  <div id="assignModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-gray-800 rounded-lg p-6 w-full max-w-md">
      <h3 class="text-xl font-bold text-yellow-400 mb-4">Assign Template</h3>
      <form method="POST" id="quickAssignForm">
        <input type="hidden" name="template_id" id="modal_template_id">
        <div class="space-y-4">
          <div>
            <label class="form-label">Plan Name</label>
            <input type="text" name="plan_name" id="modal_plan_name" class="form-input" required>
          </div>
          <div>
            <label class="form-label">Select Client</label>
            <select name="member_id" class="form-select" required>
              <option value="">Choose a client...</option>
              <?php foreach ($clients as $client): ?>
                <option value="<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['full_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="flex gap-3 mt-6">
          <button type="button" onclick="closeAssignModal()" class="btn btn-danger flex-1">Cancel</button>
          <button type="submit" name="assign_template" class="btn btn-success flex-1">Assign</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // QR Scanner functionality - MOVABLE & TOGGLEABLE VERSION
    let qrScannerActive = true;
    let lastProcessedQR = '';
    let lastProcessedTime = 0;
    let qrProcessing = false;
    let qrCooldown = false;
    let isDragging = false;
    let dragOffset = { x: 0, y: 0 };

    function setupQRScanner() {
        const qrScanner = document.getElementById('qrScanner');
        const qrScannerHeader = document.getElementById('qrScannerHeader');
        const qrInput = document.getElementById('qrInput');
        const processQRBtn = document.getElementById('processQR');
        const toggleScannerBtn = document.getElementById('toggleScanner');
        const toggleQRScannerBtn = document.getElementById('toggleQRScannerBtn');
        const closeQRScannerBtn = document.getElementById('closeQRScanner');
        const qrScannerStatus = document.getElementById('qrScannerStatus');

        // Toggle QR scanner visibility
        toggleQRScannerBtn.addEventListener('click', function() {
            qrScanner.classList.toggle('hidden');
            if (!qrScanner.classList.contains('hidden') && qrScannerActive) {
                setTimeout(() => qrInput.focus(), 100);
            }
        });

        // Close QR scanner
        closeQRScannerBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            qrScanner.classList.add('hidden');
        });

        // Drag and drop functionality
        qrScannerHeader.addEventListener('mousedown', startDrag);
        qrScannerHeader.addEventListener('touchstart', function(e) {
            startDrag(e.touches[0]);
        });

        document.addEventListener('mousemove', drag);
        document.addEventListener('touchmove', function(e) {
            drag(e.touches[0]);
            e.preventDefault();
        }, { passive: false });

        document.addEventListener('mouseup', stopDrag);
        document.addEventListener('touchend', stopDrag);

        function startDrag(e) {
            if (e.target.closest('button')) return; // Don't drag if clicking buttons
            
            isDragging = true;
            qrScanner.classList.add('dragging');
            
            const rect = qrScanner.getBoundingClientRect();
            dragOffset.x = e.clientX - rect.left;
            dragOffset.y = e.clientY - rect.top;
            
            document.body.classList.add('cursor-grabbing');
        }

        function drag(e) {
            if (!isDragging) return;
            
            const x = e.clientX - dragOffset.x;
            const y = e.clientY - dragOffset.y;
            
            // Keep within viewport bounds
            const maxX = window.innerWidth - qrScanner.offsetWidth;
            const maxY = window.innerHeight - qrScanner.offsetHeight;
            
            const boundedX = Math.max(0, Math.min(x, maxX));
            const boundedY = Math.max(0, Math.min(y, maxY));
            
            qrScanner.style.left = boundedX + 'px';
            qrScanner.style.top = boundedY + 'px';
            qrScanner.style.right = 'auto';
            qrScanner.style.bottom = 'auto';
            qrScanner.style.transform = 'none';
        }

        function stopDrag() {
            if (!isDragging) return;
            
            isDragging = false;
            qrScanner.classList.remove('dragging');
            document.body.classList.remove('cursor-grabbing');
        }

        // Process QR code when Enter is pressed
        qrInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && qrScannerActive && !qrProcessing && !qrCooldown) {
                processQRCode();
                e.preventDefault();
            }
        });
        
        // Process QR code when button is clicked
        processQRBtn.addEventListener('click', function() {
            if (qrScannerActive && !qrProcessing && !qrCooldown) {
                processQRCode();
            }
        });
        
        // Toggle scanner on/off
        toggleScannerBtn.addEventListener('click', function() {
            qrScannerActive = !qrScannerActive;
            
            if (qrScannerActive) {
                qrScannerStatus.textContent = 'Active';
                qrScannerStatus.classList.remove('disabled');
                qrScannerStatus.classList.add('active');
                toggleScannerBtn.innerHTML = '<i data-lucide="power"></i> Disable';
                qrInput.disabled = false;
                qrInput.placeholder = 'Scan QR code or enter code manually...';
                processQRBtn.disabled = false;
                if (!qrScanner.classList.contains('hidden')) {
                    qrInput.focus();
                }
                showToast('QR scanner enabled', 'success', 2000);
            } else {
                qrScannerStatus.textContent = 'Disabled';
                qrScannerStatus.classList.remove('active');
                qrScannerStatus.classList.add('disabled');
                toggleScannerBtn.innerHTML = '<i data-lucide="power"></i> Enable';
                qrInput.disabled = true;
                qrInput.placeholder = 'Scanner disabled';
                processQRBtn.disabled = true;
                showToast('QR scanner disabled', 'warning', 2000);
            }
            
            lucide.createIcons();
        });
        
        // Smart focus management
        document.addEventListener('click', function(e) {
            if (qrScannerActive && 
                !qrScanner.classList.contains('hidden') &&
                !e.target.closest('form') && 
                !e.target.closest('select') && 
                !e.target.closest('button') &&
                e.target !== qrInput) {
                setTimeout(() => {
                    if (document.activeElement.tagName !== 'INPUT' && 
                        document.activeElement.tagName !== 'TEXTAREA' &&
                        document.activeElement.tagName !== 'SELECT') {
                        qrInput.focus();
                    }
                }, 100);
            }
        });
        
        // Clear input after successful processing
        qrInput.addEventListener('input', function() {
            if (this.value === lastProcessedQR) {
                this.value = '';
            }
        });
        
        // Close scanner with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !qrScanner.classList.contains('hidden')) {
                qrScanner.classList.add('hidden');
            }
        });
        
        // Initial focus
        setTimeout(() => {
            if (qrScannerActive && !qrScanner.classList.contains('hidden')) {
                qrInput.focus();
            }
        }, 1000);
    }

    function processQRCode() {
        if (qrProcessing || qrCooldown) return;
        
        const qrInput = document.getElementById('qrInput');
        const qrResult = document.getElementById('qrResult');
        const processQRBtn = document.getElementById('processQR');
        const qrCode = qrInput.value.trim();
        
        if (!qrCode) {
            showQRResult('error', 'Error', 'Please enter a QR code');
            showToast('Please enter a QR code', 'error');
            return;
        }
        
        // Prevent processing the same QR code twice in quick succession
        const currentTime = Date.now();
        if (qrCode === lastProcessedQR && (currentTime - lastProcessedTime) < 3000) {
            const timeLeft = Math.ceil((3000 - (currentTime - lastProcessedTime)) / 1000);
            showQRResult('error', 'Cooldown', `Please wait ${timeLeft} seconds before scanning this QR code again`);
            showToast(`Please wait ${timeLeft} seconds before rescanning`, 'warning');
            qrInput.value = '';
            qrInput.focus();
            return;
        }
        
        qrProcessing = true;
        qrCooldown = true;
        setLoadingState(processQRBtn, true);
        processQRBtn.innerHTML = '<i data-lucide="loader" class="animate-spin"></i> Processing';
        lucide.createIcons();
        
        // Show processing message
        showQRResult('info', 'Processing', 'Scanning QR code...');
        showToast('Processing QR code...', 'info', 2000);
        
        // Make AJAX call to process the QR code
        fetch('process_qr.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'qr_code=' + encodeURIComponent(qrCode)
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                showQRResult('success', 'Success', data.message);
                showToast(data.message, 'success');
                lastProcessedQR = qrCode;
                lastProcessedTime = Date.now();
                
                // Update attendance count immediately
                const currentCount = parseInt(document.getElementById('attendanceCount')?.textContent || '0');
                if (document.getElementById('attendanceCount')) {
                    document.getElementById('attendanceCount').textContent = currentCount + 1;
                }
                
                // Trigger custom event for other components
                window.dispatchEvent(new CustomEvent('qrScanSuccess', { 
                    detail: { message: data.message, qrCode: qrCode } 
                }));
                
            } else {
                showQRResult('error', 'Error', data.message || 'Unknown error occurred');
                showToast(data.message || 'Unknown error occurred', 'error');
                lastProcessedQR = qrCode;
                lastProcessedTime = Date.now();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showQRResult('error', 'Network Error', 'Failed to process QR code. Please try again.');
            showToast('Network error occurred', 'error');
            lastProcessedQR = qrCode;
            lastProcessedTime = Date.now();
        })
        .finally(() => {
            qrProcessing = false;
            setLoadingState(processQRBtn, false);
            processQRBtn.innerHTML = '<i data-lucide="check"></i> Process';
            lucide.createIcons();
            
            // Clear input and refocus after processing
            setTimeout(() => {
                qrInput.value = '';
                const qrScanner = document.getElementById('qrScanner');
                if (qrScannerActive && !qrScanner.classList.contains('hidden')) {
                    qrInput.focus();
                }
            }, 500);
            
            // Enable scanning again after 3 seconds
            setTimeout(() => {
                qrCooldown = false;
            }, 3000);
        });
    }

    function showQRResult(type, title, message) {
        const qrResult = document.getElementById('qrResult');
        qrResult.className = 'qr-scanner-result ' + type;
        qrResult.innerHTML = `
            <div class="qr-result-title">${title}</div>
            <div class="qr-result-message">${message}</div>
        `;
        qrResult.style.display = 'block';
        
        // Auto-hide result after appropriate time
        let hideTime = type === 'success' ? 4000 : 5000;
        if (title === 'Cooldown') hideTime = 3000;
        if (title === 'Processing') hideTime = 2000;
        
        setTimeout(() => {
            qrResult.style.display = 'none';
        }, hideTime);
    }

    function setLoadingState(button, loading) {
        if (loading) {
            button.disabled = true;
            button.style.opacity = '0.7';
        } else {
            button.disabled = false;
            button.style.opacity = '1';
        }
    }

    function showToast(message, type = 'info', duration = 3000) {
        // Simple toast notification implementation
        const toast = document.createElement('div');
        toast.className = `fixed top-4 right-4 p-4 rounded-lg text-white z-50 transform transition-transform duration-300 ${
            type === 'success' ? 'bg-green-600' : 
            type === 'error' ? 'bg-red-600' : 
            type === 'warning' ? 'bg-yellow-600' : 'bg-blue-600'
        }`;
        toast.textContent = message;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.remove();
        }, duration);
    }

    // Global function to open QR scanner
    function openQRScanner() {
        const qrScanner = document.getElementById('qrScanner');
        const qrInput = document.getElementById('qrInput');
        
        qrScanner.classList.remove('hidden');
        if (qrScannerActive) {
            setTimeout(() => qrInput.focus(), 100);
        }
    }

    // Tab functionality
    function showTab(tabId) {
      // Hide all tabs
      document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
      });
      document.querySelectorAll('.tab').forEach(tab => {
        tab.classList.remove('active');
      });
      
      // Show selected tab
      document.getElementById(tabId).classList.add('active');
      event.currentTarget.classList.add('active');
    }

    // Sidebar functionality
    document.addEventListener('DOMContentLoaded', function() {
        lucide.createIcons();
        
        document.getElementById('toggleSidebar').addEventListener('click', () => {
            const sidebar = document.getElementById('sidebar');
            if (sidebar.classList.contains('w-60')) {
                sidebar.classList.remove('w-60');
                sidebar.classList.add('w-16', 'sidebar-collapsed');
            } else {
                sidebar.classList.remove('w-16', 'sidebar-collapsed');
                sidebar.classList.add('w-60');
            }
        });

        // Submenu toggles
        const membersToggle = document.getElementById('membersToggle');
        const membersSubmenu = document.getElementById('membersSubmenu');
        const membersChevron = document.getElementById('membersChevron');
        
        membersToggle.addEventListener('click', () => {
            membersSubmenu.classList.toggle('open');
            membersChevron.classList.toggle('rotate');
        });

        const plansToggle = document.getElementById('plansToggle');
        const plansSubmenu = document.getElementById('plansSubmenu');
        const plansChevron = document.getElementById('plansChevron');
        
        plansToggle.addEventListener('click', () => {
            plansSubmenu.classList.toggle('open');
            plansChevron.classList.toggle('rotate');
        });
        
        // Hover to open sidebar
        const sidebar = document.getElementById('sidebar');
        sidebar.addEventListener('mouseenter', () => {
            if (sidebar.classList.contains('sidebar-collapsed')) {
                sidebar.classList.remove('w-16', 'sidebar-collapsed');
                sidebar.classList.add('w-60');
            }
        });
        
        sidebar.addEventListener('mouseleave', () => {
            if (!sidebar.classList.contains('sidebar-collapsed') && window.innerWidth > 768) {
                sidebar.classList.remove('w-60');
                sidebar.classList.add('w-16', 'sidebar-collapsed');
            }
        });

        // Dropdown functionality
        const notificationBell = document.getElementById('notificationBell');
        const notificationDropdown = document.getElementById('notificationDropdown');
        const userMenuButton = document.getElementById('userMenuButton');
        const userDropdown = document.getElementById('userDropdown');
        
        // Close all dropdowns
        function closeAllDropdowns() {
            notificationDropdown.classList.add('hidden');
            userDropdown.classList.add('hidden');
        }
        
        // Toggle notification dropdown
        notificationBell.addEventListener('click', function(e) {
            e.stopPropagation();
            const isHidden = notificationDropdown.classList.contains('hidden');
            
            closeAllDropdowns();
            
            if (isHidden) {
                notificationDropdown.classList.remove('hidden');
                loadNotifications();
            }
        });
        
        // Toggle user dropdown
        userMenuButton.addEventListener('click', function(e) {
            e.stopPropagation();
            const isHidden = userDropdown.classList.contains('hidden');
            
            closeAllDropdowns();
            
            if (isHidden) {
                userDropdown.classList.remove('hidden');
            }
        });
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!notificationDropdown.contains(e.target) && !notificationBell.contains(e.target) &&
                !userDropdown.contains(e.target) && !userMenuButton.contains(e.target)) {
                closeAllDropdowns();
            }
        });
        
        // Mark all as read
        document.getElementById('markAllRead')?.addEventListener('click', function(e) {
            e.stopPropagation();
            document.getElementById('notificationBadge').classList.add('hidden');
        });
        
        // Load notifications
        loadNotifications();
        setInterval(loadNotifications, 30000);
        
        // Close dropdowns when pressing Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAllDropdowns();
            }
        });

        updateExerciseCount();
        updateTemplateExerciseCount();
        
        // Initialize QR Scanner
        setupQRScanner();
    });

    function loadNotifications() {
        // Simulate loading notifications
        const notifications = [
            {
                title: "New Client Registration",
                message: "John Doe has registered as a new client",
                type: "membership",
                time: new Date(Date.now() - 5 * 60 * 1000).toISOString(),
                priority: "medium"
            },
            {
                title: "Workout Plan Completed",
                message: "Sarah Wilson has completed her weekly workout plan",
                type: "announcement",
                time: new Date(Date.now() - 2 * 60 * 60 * 1000).toISOString(),
                priority: "low"
            }
        ];
        
        updateNotificationBadge(notifications.length);
        updateNotificationList(notifications);
    }
    
    function updateNotificationBadge(count) {
        const badge = document.getElementById('notificationBadge');
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    }
    
    function updateNotificationList(notifications) {
        const notificationList = document.getElementById('notificationList');
        
        if (notifications.length === 0) {
            notificationList.innerHTML = `
                <div class="text-center py-8 text-gray-500">
                    <i data-lucide="bell-off" class="w-12 h-12 mx-auto mb-3 opacity-50"></i>
                    <p>No notifications</p>
                    <p class="text-sm mt-1">You're all caught up!</p>
                </div>
            `;
            lucide.createIcons();
            return;
        }
        
        notificationList.innerHTML = notifications.map(notification => `
            <div class="p-3 border-b border-gray-700 last:border-b-0 hover:bg-white/5 transition-colors cursor-pointer">
                <div class="flex items-start gap-3">
                    <div class="flex-shrink-0 mt-1">
                        ${getNotificationIcon(notification.type)}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex justify-between items-start mb-1">
                            <p class="text-white font-medium text-sm truncate">${notification.title}</p>
                            <span class="text-xs text-gray-400 whitespace-nowrap ml-2">
                                ${formatTime(notification.time)}
                            </span>
                        </div>
                        <p class="text-gray-400 text-xs line-clamp-2">${notification.message}</p>
                        ${notification.priority === 'high' ? `
                            <span class="inline-block mt-1 px-2 py-1 bg-red-500/20 text-red-400 text-xs rounded-full">
                                Important
                            </span>
                        ` : ''}
                    </div>
                </div>
            </div>
        `).join('');
        
        lucide.createIcons();
    }
    
    function getNotificationIcon(type) {
        const icons = {
            'announcement': '<i data-lucide="megaphone" class="w-4 h-4 text-yellow-400"></i>',
            'membership': '<i data-lucide="id-card" class="w-4 h-4 text-blue-400"></i>',
            'message': '<i data-lucide="message-circle" class="w-4 h-4 text-green-400"></i>'
        };
        return icons[type] || '<i data-lucide="bell" class="w-4 h-4 text-gray-400"></i>';
    }
    
    function formatTime(timeString) {
        const time = new Date(timeString);
        const now = new Date();
        const diffMs = now - time;
        const diffMins = Math.floor(diffMs / (1000 * 60));
        const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
        const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
        
        if (diffMins < 1) return 'Just now';
        if (diffMins < 60) return `${diffMins}m ago`;
        if (diffHours < 24) return `${diffHours}h ago`;
        if (diffDays < 7) return `${diffDays}d ago`;
        return time.toLocaleDateString();
    }

    function updateClientGoals(select) {
        const selectedOption = select.options[select.selectedIndex];
        const goals = selectedOption.getAttribute('data-goals');
        const context = select.closest('.tab-content').id;
        
        let goalsElement, goalsText;
        if (context === 'assign-tab') {
            goalsElement = document.getElementById('assign-client-goals');
            goalsText = document.getElementById('assign-goals-text');
        } else if (context === 'custom-tab') {
            goalsElement = document.getElementById('custom-client-goals');
            goalsText = document.getElementById('custom-goals-text');
        }
        
        if (goals && select.value) {
            goalsElement.classList.remove('hidden');
            goalsText.textContent = goals;
        } else {
            goalsElement.classList.add('hidden');
        }
    }
    
    function updateExerciseCount() {
        const count = document.querySelectorAll('#exercises-container .exercise-item').length;
        document.getElementById('exercise-count').textContent = count + ' exercise' + (count !== 1 ? 's' : '') + ' added';
    }
    
    function updateTemplateExerciseCount() {
        const count = document.querySelectorAll('#template-exercises-container .exercise-item').length;
        document.getElementById('template-exercise-count').textContent = count + ' exercise' + (count !== 1 ? 's' : '') + ' added';
    }
    
    function addExercise() {
        const container = document.getElementById('exercises-container');
        const exerciseCount = container.children.length + 1;
        
        const newExercise = document.createElement('div');
        newExercise.className = 'exercise-item';
        newExercise.innerHTML = `
            <div class="flex justify-between items-center mb-3">
                <h4 class="font-semibold text-white">Exercise #${exerciseCount}</h4>
                <button type="button" onclick="this.parentElement.parentElement.remove(); updateExerciseCount()" class="text-red-400 hover:text-red-300">
                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                </button>
            </div>
            <div class="space-y-3">
                <div>
                    <label class="form-label text-xs">Exercise Name *</label>
                    <input type="text" name="exercise_names[]" class="form-input" placeholder="e.g., Bench Press" required>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="form-label text-xs">Sets *</label>
                        <input type="text" name="exercise_sets[]" class="form-input" placeholder="3" required>
                    </div>
                    <div>
                        <label class="form-label text-xs">Reps *</label>
                        <input type="text" name="exercise_reps[]" class="form-input" placeholder="8-12" required>
                    </div>
                    <div>
                        <label class="form-label text-xs">Rest *</label>
                        <input type="text" name="exercise_rest[]" class="form-input" placeholder="60s" required>
                    </div>
                </div>
                <div>
                    <label class="form-label text-xs">Technique Notes</label>
                    <textarea name="exercise_notes[]" class="form-input" placeholder="Form tips, variations, progression..." rows="2"></textarea>
                </div>
            </div>
        `;
        container.appendChild(newExercise);
        updateExerciseCount();
        lucide.createIcons();
    }
    
    function addTemplateExercise() {
        const container = document.getElementById('template-exercises-container');
        const exerciseCount = container.children.length + 1;
        
        const newExercise = document.createElement('div');
        newExercise.className = 'exercise-item';
        newExercise.innerHTML = `
            <div class="flex justify-between items-center mb-3">
                <h4 class="font-semibold text-white">Exercise #${exerciseCount}</h4>
                <button type="button" onclick="this.parentElement.parentElement.remove(); updateTemplateExerciseCount()" class="text-red-400 hover:text-red-300">
                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                </button>
            </div>
            <div class="space-y-3">
                <div>
                    <label class="form-label text-xs">Exercise Name *</label>
                    <input type="text" name="exercise_names[]" class="form-input" placeholder="e.g., Bench Press" required>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="form-label text-xs">Sets *</label>
                        <input type="text" name="exercise_sets[]" class="form-input" placeholder="3" required>
                    </div>
                    <div>
                        <label class="form-label text-xs">Reps *</label>
                        <input type="text" name="exercise_reps[]" class="form-input" placeholder="8-12" required>
                    </div>
                    <div>
                        <label class="form-label text-xs">Rest *</label>
                        <input type="text" name="exercise_rest[]" class="form-input" placeholder="60s" required>
                    </div>
                </div>
                <div>
                    <label class="form-label text-xs">Technique Notes</label>
                    <textarea name="exercise_notes[]" class="form-input" placeholder="Form tips, variations, progression..." rows="2"></textarea>
                </div>
            </div>
        `;
        container.appendChild(newExercise);
        updateTemplateExerciseCount();
        lucide.createIcons();
    }
    
    function updateTemplatePreview(select) {
        const selectedOption = select.options[select.selectedIndex];
        const exercisesJson = selectedOption.getAttribute('data-exercises');
        const preview = document.getElementById('template-preview');
        
        if (exercisesJson && select.value) {
            const exercises = JSON.parse(exercisesJson);
            let html = '<div class="space-y-3">';
            exercises.forEach((exercise, index) => {
                html += `
                    <div class="bg-gray-700/50 rounded-lg p-3">
                        <div class="font-semibold text-white">${index + 1}. ${exercise.name}</div>
                        <div class="text-sm text-gray-300 grid grid-cols-3 gap-2 mt-2">
                            <div>Sets: ${exercise.sets || 'N/A'}</div>
                            <div>Reps: ${exercise.reps || 'N/A'}</div>
                            <div>Rest: ${exercise.rest || 'N/A'}</div>
                        </div>
                        ${exercise.notes ? `<div class="text-xs text-gray-400 mt-1">Notes: ${exercise.notes}</div>` : ''}
                    </div>
                `;
            });
            html += '</div>';
            preview.innerHTML = html;
        } else {
            preview.innerHTML = '<p class="text-gray-400 text-center">Select a template to preview exercises</p>';
        }
    }
    
    function assignTemplate(templateId, templateName) {
        document.getElementById('modal_template_id').value = templateId;
        document.getElementById('modal_plan_name').value = templateName + ' - Customized';
        document.getElementById('assignModal').classList.remove('hidden');
    }
    
    function closeAssignModal() {
        document.getElementById('assignModal').classList.add('hidden');
    }
    
    // Close modal when clicking outside
    document.getElementById('assignModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeAssignModal();
        }
    });
    
    // Form validation
    document.getElementById('workoutForm').addEventListener('submit', function(e) {
        const exercises = document.querySelectorAll('#exercises-container input[name="exercise_names[]"]');
        let hasExercises = false;
        exercises.forEach(input => {
            if (input.value.trim() !== '') hasExercises = true;
        });
        
        if (!hasExercises) {
            e.preventDefault();
            alert('Please add at least one exercise to the workout plan.');
        }
    });
    
    document.getElementById('templateForm').addEventListener('submit', function(e) {
        const exercises = document.querySelectorAll('#template-exercises-container input[name="exercise_names[]"]');
        let hasExercises = false;
        exercises.forEach(input => {
            if (input.value.trim() !== '') hasExercises = true;
        });
        
        if (!hasExercises) {
            e.preventDefault();
            alert('Please add at least one exercise to the template.');
        }
    });
  </script>
<?php require_once 'includes/trainer_footer.php'; ?>
<?php if(isset($conn)) { $conn->close(); } ?>
