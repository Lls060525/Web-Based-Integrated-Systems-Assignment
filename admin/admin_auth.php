<?php
// admin_auth.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. 拦截未登录用户
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: /login.php');
    exit;
}

// 2. 核心防御：拦截企图通过修改 URL 强行进入的 Normal User (Member)
if ($_SESSION['role'] !== 'admin') {
    // 遇到越权访问，标准的做法是抛出 403 状态码，并踢回他们的主页
    header('HTTP/1.1 403 Forbidden');
    header('Location: /member/home.php?error=unauthorized');
    exit; // 绝对终止脚本，确保下方任何涉及数据库和机密 HTML 的代码都不会被渲染
}

require_once __DIR__ . '/../config/database.php';
?>