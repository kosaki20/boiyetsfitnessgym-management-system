<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'trainer') {
    header("Location: index.php");
    exit();
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "boiyetsdb";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$trainer_id = $_SESSION['user_id'];



// Get renewal requests for this trainer
$requests_sql = "SELECT mr.*, m.contact_number, m.email, m.current_status,
                 (SELECT status FROM members WHERE id = mr.member_id) as member_status
                 FROM membership_renewal_requests mr
                 LEFT JOIN members m ON mr.member_id = m.id
                 WHERE mr.trainer_id = ? 
                 ORDER BY mr.created_at DESC";
$stmt = $conn->prepare($requests_sql);
$stmt->bind_param("i", $trainer_id);
$stmt->execute();
$requests_result = $stmt->get_result();
$renewal_requests = $requests_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Membership plans for display
$membership_plans = [
    'daily' => ['name' => 'Per Visit', 'price' => 40],
    'weekly' => ['name' => 'Weekly', 'price' => 160],
    'halfmonth' => ['name' => 'Half Month', 'price' => 250],
    'monthly' => ['name' => 'Monthly', 'price' => 400]
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Renewal Requests - BOIYETS FITNESS GYM</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        
        * {
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #111 0%, #0a0a0a 100%);
            color: #e2e8f0;
            min-height: 100vh;
        }
        
        .card {
            background: rgba(26, 26, 26, 0.7);
            border-radius: 12px;
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .status-pending { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .status-paid { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }
        .status-completed { background: rgba(16, 185, 129, 0.2); color: #10b981; }
        .status-rejected { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
        }
        
        .btn-success { background: rgba(16, 185, 129, 0.2); color: #10b981; }
        .btn-success:hover { background: rgba(16, 185, 129, 0.3); }
        
        .btn-danger { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .btn-danger:hover { background: rgba(239, 68, 68, 0.3); }
        
        .btn-primary { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }
        .btn-primary:hover { background: rgba(59, 130, 246, 0.3); }
    </style>
</head>
<body class="min-h-screen">
    <div class="container mx-auto p-6">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-yellow-400 flex items-center gap-3">
                <i data-lucide="refresh-cw"></i>
                Membership Renewal Requests
            </h1>
            <a href="membership_status.php" class="btn btn-primary">
                <i data-lucide="arrow-left"></i>
                Back to Members
            </a>
        </div>

        <!-- Messages -->
        <?php if (isset($success_message)): ?>
            <div class="bg-green-500/20 border border-green-500/30 rounded-lg p-4 mb-6">
                <div class="flex items-center gap-3">
                    <i data-lucide="check-circle" class="w-6 h-6 text-green-400"></i>
                    <span class="text-green-300"><?php echo $success_message; ?></span>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="bg-red-500/20 border border-red-500/30 rounded-lg p-4 mb-6">
                <div class="flex items-center gap-3">
                    <i data-lucide="alert-circle" class="w-6 h-6 text-red-400"></i>
                    <span class="text-red-300"><?php echo $error_message; ?></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Renewal Requests Table -->
        <div class="card">
            <h2 class="text-xl font-bold text-yellow-400 mb-4">Pending Renewal Requests</h2>
            
            <?php if (empty($renewal_requests)): ?>
                <div class="text-center py-8 text-gray-500">
                    <i data-lucide="inbox" class="w-16 h-16 mx-auto mb-4 opacity-50"></i>
                    <p class="text-lg">No renewal requests found</p>
                    <p class="text-sm mt-2">Renewal requests from your clients will appear here</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-700">
                                <th class="text-left p-3 text-yellow-400">Member</th>
                                <th class="text-left p-3 text-yellow-400">Plan</th>
                                <th class="text-left p-3 text-yellow-400">Amount</th>
                                <th class="text-left p-3 text-yellow-400">Payment Method</th>
                                <th class="text-left p-3 text-yellow-400">GCash Reference</th>
                                <th class="text-left p-3 text-yellow-400">Status</th>
                                <th class="text-left p-3 text-yellow-400">Requested</th>
                                <th class="text-left p-3 text-yellow-400">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($renewal_requests as $request): ?>
                                <tr class="border-b border-gray-800 hover:bg-gray-800/50">
                                    <td class="p-3">
                                        <div class="font-semibold"><?php echo htmlspecialchars($request['member_name']); ?></div>
                                        <div class="text-sm text-gray-400"><?php echo htmlspecialchars($request['contact_number']); ?></div>
                                    </td>
                                    <td class="p-3">
                                        <?php echo $membership_plans[$request['plan_type']]['name'] ?? ucfirst($request['plan_type']); ?>
                                    </td>
                                    <td class="p-3 font-semibold">
                                        ₱<?php echo number_format($request['amount']); ?>
                                    </td>
                                    <td class="p-3">
                                        <span class="capitalize"><?php echo $request['payment_method']; ?></span>
                                    </td>
                                    <td class="p-3">
                                        <?php echo $request['gcash_reference'] ?: '—'; ?>
                                    </td>
                                    <td class="p-3">
                                        <span class="status-badge status-<?php echo $request['status']; ?>">
                                            <?php echo ucfirst($request['status']); ?>
                                        </span>
                                    </td>
                                    <td class="p-3 text-sm text-gray-400">
                                        <?php echo date('M j, Y g:i A', strtotime($request['created_at'])); ?>
                                    </td>
                                    <td class="p-3">
    <div class="flex gap-2">
        <?php if ($request['status'] === 'pending'): ?>
            <!-- Single Process Button for BOTH GCash and Cash -->
            <button onclick="processRenewal(<?php echo $request['member_id']; ?>, '<?php echo $request['plan_type']; ?>', '<?php echo $request['payment_method']; ?>')" 
                    class="btn btn-success">
                <i data-lucide="refresh-cw"></i> Process Renewal
            </button>
            
            <?php if ($request['payment_method'] === 'gcash' && $request['gcash_screenshot']): ?>
                <button onclick="viewScreenshot('<?php echo $request['gcash_screenshot']; ?>')" 
                        class="btn btn-primary">
                    <i data-lucide="image"></i> View Proof
                </button>
            <?php endif; ?>
            
        <?php elseif ($request['status'] === 'completed'): ?>
            <span class="text-green-400 text-sm">Completed</span>
        <?php elseif ($request['status'] === 'rejected'): ?>
            <span class="text-red-400 text-sm">Rejected</span>
        <?php endif; ?>
    </div>
</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>



    <!-- Screenshot Modal -->
    <div id="screenshotModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
        <div class="bg-gray-800 rounded-lg p-6 max-w-2xl w-full">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-yellow-400">GCash Screenshot</h3>
                <button onclick="closeScreenshotModal()" class="text-gray-400 hover:text-white">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            <img id="screenshotImage" src="" alt="GCash Screenshot" class="w-full h-auto rounded-lg">
        </div>
    </div>

    <script>
        lucide.createIcons();


        // Screenshot Modal Functions
        function viewScreenshot(imagePath) {
            document.getElementById('screenshotImage').src = imagePath;
            document.getElementById('screenshotModal').classList.remove('hidden');
        }

        function closeScreenshotModal() {
            document.getElementById('screenshotModal').classList.add('hidden');
        }

        // Process Renewal Function
        function processRenewal(memberId, planType, paymentMethod) {
            if (!confirm('Process this membership renewal?')) return;

            const btn = event.target;
            const originalText = btn.innerHTML;
            
            btn.innerHTML = '<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Processing...';
            btn.disabled = true;

            fetch('renew_membership.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `member_id=${memberId}&plan_type=${planType}&payment_method=${paymentMethod}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Membership renewed successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            })
            .catch(error => {
                alert('Network error. Please try again.');
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        }

        // Close modals when clicking outside
        document.addEventListener('click', function(event) {
            if (event.target.id === 'gcashModal') {
                closeGCashModal();
            }
            if (event.target.id === 'screenshotModal') {
                closeScreenshotModal();
            }
        });

        // Close modals with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeGCashModal();
                closeScreenshotModal();
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>



