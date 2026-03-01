<?php
// Reset all user passwords to "password" and create sample accounts
// Run this file once, then delete it

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "boiyetsdb";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// MD5 hash of "password"
$new_password = md5("password");

// Update all user passwords
echo "<h2>Resetting passwords...</h2>";
$sql = "UPDATE users SET password = '$new_password'";
if ($conn->query($sql) === TRUE) {
    echo "All passwords reset to 'password'.<br>";
    echo "Affected rows: " . $conn->affected_rows . "<br><br>";
} else {
    echo "Error resetting passwords: " . $conn->error . "<br><br>";
}

// Create sample accounts
echo "<h2>Creating sample accounts...</h2>";

// Check if admin exists
$check_admin = "SELECT id FROM users WHERE username = 'admin' LIMIT 1";
$admin_result = $conn->query($check_admin);

if ($admin_result->num_rows == 0) {
    $sql_admin = "INSERT INTO users (username, password, role, full_name, email) VALUES ('admin', '$new_password', 'admin', 'Administrator', 'admin@boiyetsgym.com')";
    $conn->query($sql_admin);
    echo "Created admin account (username: admin, password: password)<br>";
} else {
    echo "Admin account already exists<br>";
}

// Check if trainer exists
$check_trainer = "SELECT id FROM users WHERE username = 'trainer' LIMIT 1";
$trainer_result = $conn->query($check_trainer);

if ($trainer_result->num_rows == 0) {
    $sql_trainer = "INSERT INTO users (username, password, role, full_name, email) VALUES ('trainer', '$new_password', 'trainer', 'John Trainer', 'trainer@boiyetsgym.com')";
    $conn->query($sql_trainer);
    echo "Created trainer account (username: trainer, password: password)<br>";
} else {
    echo "Trainer account already exists<br>";
}

// Check if client exists
$check_client = "SELECT id FROM users WHERE username = 'client' LIMIT 1";
$client_result = $conn->query($check_client);

if ($client_result->num_rows == 0) {
    $sql_client = "INSERT INTO users (username, password, role, full_name, email) VALUES ('client', '$new_password', 'client', 'Jane Client', 'client@boiyetsgym.com')";
    $conn->query($sql_client);
    echo "Created client account (username: client, password: password)<br>";
} else {
    echo "Client account already exists<br>";
}

echo "<br><h2>Done!</h2>";
echo "<p>You can now login with any of these accounts:</p>";
echo "<ul>";
echo "<li><b>Admin:</b> username: admin, password: password</li>";
echo "<li><b>Trainer:</b> username: trainer, password: password</li>";
echo "<li><b>Client:</b> username: client, password: password</li>";
echo "</ul>";

$conn->close();
?>
