<?php
session_start();

function checkAuth($required_role = null) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit();
    }
    
    if ($required_role && $_SESSION['role'] !== $required_role) {
        header("Location: unauthorized.php");
        exit();
    }
    
    return true;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getUserRole() {
    return $_SESSION['role'] ?? null;
}

function getUserId() {
    return $_SESSION['user_id'] ?? null;
}
?>



