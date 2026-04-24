<?php
session_start();
require_once 'db_config.php';

// Log the logout activity if user was logged in
if (isset($_SESSION['reg_number'])) {
    logActivity($conn, $_SESSION['reg_number'], 'Logout', 'User logged out successfully');
}

// Clear all session variables
$_SESSION = array();

// Destroy the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: login.php?message=You have been logged out successfully");
exit();
?>
