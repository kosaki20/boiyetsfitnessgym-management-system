<?php
/**
 * Centralized Database Connection
 * Include this file in any PHP file that needs database access.
 * Provides $conn as a MySQLi connection object.
 */

$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "boiyetsdb";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);

if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die(json_encode(['error' => 'Database connection failed. Please try again later.']));
}

$conn->set_charset("utf8mb4");
