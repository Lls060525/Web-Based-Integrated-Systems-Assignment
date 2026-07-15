<?php
// /auth/logout.php
session_start();

// Unset all of the session variables
$_SESSION = [];

// Destroy the session completely
session_destroy();

// Redirect back to the login page
header('Location: /auth/login.php');
exit;