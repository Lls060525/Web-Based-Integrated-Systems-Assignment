<?php
// admin_auth.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// if user is not login set to login page
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: /login.php');
    exit;
}

//block any user that has no role of an admin that attempt to go into admin
if ($_SESSION['role'] !== 'admin') {
    // 遇到越权访问，标准的做法是抛出 403 状态码，并踢回他们的主页
    header('HTTP/1.1 403 Forbidden');
    header('Location: /member/home.php?error=unauthorized');
    exit; 
}

require_once __DIR__ . '/../config/database.php';
?>