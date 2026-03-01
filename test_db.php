<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "boiyetsdb";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
} else {
    echo "Database connected successfully!";
    
    // Test a simple query
    $result = $conn->query("SELECT 1");
    if ($result) {
        echo "<br>Query test passed!";
    } else {
        echo "<br>Query test failed: " . $conn->error;
    }
}
$conn->close();
?>



