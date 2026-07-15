<?php
// Start the session to access session variables
session_start();

// Check if the user is logged in by looking for a specific session variable
if (!isset($_SESSION['user_id'])) {
    // User is NOT logged in, route them to the login page
    header('Location: /authorization/login.php');
    exit;
}

// User IS logged in, route them to the dashboard
header('Location: /dashboard/home.php');
exit;
