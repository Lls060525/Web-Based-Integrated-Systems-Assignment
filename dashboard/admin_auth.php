<?php
// admin_auth.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 【临时调试用】模拟 Admin 登录。等你的 Login 模块写好后，请将这段删除！
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 2; // 假设 2 号是管理员
    $_SESSION['role'] = 'admin';
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
    header('Location: /dashboard/home.php?error=unauthorized');
    exit; // 绝对终止脚本，确保下方任何涉及数据库和机密 HTML 的代码都不会被渲染
}

// 数据库连接 (建议以后抽离成独立的 db.php)
$host = '127.0.0.1';
$db   = 'web_assignment';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
$pdo = new PDO($dsn, $user, $pass, $options);
?>