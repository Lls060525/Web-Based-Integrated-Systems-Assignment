<?php
// Start the session to access session variables
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    // User is NOT logged in, route them to the login page
    header('Location: /authorization/login.php');
    exit;
}

// User IS logged in, route them to their respective packages
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    header('Location: /admin/members.php');
} else {
    header('Location: /member/home.php');
}
exit;