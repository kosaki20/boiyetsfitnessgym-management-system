<?php
// debug_test.php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Test database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "boiyetsdb";

$conn = new mysqli($servername, $username, $password, $dbname);

echo "<h3>Database Test:</h3>";
if ($conn->connect_error) {
    echo "❌ Database connection failed: " . $conn->connect_error;
} else {
    echo "✅ Database connected successfully!<br>";
    
    // Test basic query
    $result = $conn->query("SELECT COUNT(*) as count FROM users");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "✅ Users table accessible. Total users: " . $row['count'] . "<br>";
    } else {
        echo "❌ Users table query failed: " . $conn->error . "<br>";
    }
}

// Test QR code library
echo "<h3>QR Code Library Test:</h3>";
if (file_exists('phpqrcode/qrlib.php')) {
    echo "✅ QR Code library found<br>";
    
    // Test simple QR generation
    require_once 'phpqrcode/qrlib.php';
    
    $test_file = 'qrcodes/debug_test.png';
    if (!is_dir('qrcodes')) {
        mkdir('qrcodes', 0755, true);
    }
    
    QRcode::png('TEST_DEBUG', $test_file, QR_ECLEVEL_L, 8);
    
    if (file_exists($test_file)) {
        echo "✅ QR code generation successful!<br>";
        echo "<img src='$test_file' alt='Test QR'><br>";
    } else {
        echo "❌ QR code file not created<br>";
    }
} else {
    echo "❌ QR Code library NOT found at: phpqrcode/qrlib.php<br>";
}

// Test directory permissions
echo "<h3>Directory Permissions:</h3>";
$dirs = ['qrcodes', 'chat_uploads', 'profile_pictures'];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        if (mkdir($dir, 0755, true)) {
            echo "✅ Created directory: $dir<br>";
        } else {
            echo "❌ Failed to create directory: $dir<br>";
        }
    } else {
        if (is_writable($dir)) {
            echo "✅ Directory writable: $dir<br>";
        } else {
            echo "❌ Directory NOT writable: $dir<br>";
        }
    }
}

$conn->close();
?>



