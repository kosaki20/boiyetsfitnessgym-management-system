<?php
session_start();
if (!isset($_SESSION['username']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit();
}

// Database connection
$servername = "localhost";
$username = "root";  // full Hostinger DB username
$password = "";           // your Hostinger DB password
$dbname = "boiyetsdb";         // full Hostinger DB name

$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $new_username = mysqli_real_escape_string($conn, $_POST['new_username']);
    $new_password = mysqli_real_escape_string($conn, $_POST['new_password']);
    $new_role = mysqli_real_escape_string($conn, $_POST['new_role']);
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    
    $sql = "INSERT INTO users (username, password, role, full_name, email) 
            VALUES ('$new_username', MD5('$new_password'), '$new_role', '$full_name', '$email')";
    
    if ($conn->query($sql)) {
        $message = "User created successfully!";
    } else {
        $message = "Error: " . $conn->error;
    }
}
?>

<!-- Simple form HTML -->
<form method="POST">
    <input type="text" name="full_name" placeholder="Full Name" required>
    <input type="email" name="email" placeholder="Email" required>
    <input type="text" name="new_username" placeholder="Username" required>
    <input type="password" name="new_password" placeholder="Password" required>
    <select name="new_role" required>
        <option value="trainer">Trainer</option>
        <option value="client">Client</option>
    </select>
    <button type="submit">Create User</button>
</form>



