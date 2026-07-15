<?php
if (!defined('ROOT_DIR')) define('ROOT_DIR', __DIR__ . '/../');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo isset($title) ? $title : 'Online Shop'; ?></title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="/assets/js/main.js" defer></script>
</head>
<body>
    <header class="topbar">
        <div class="container topbar-inner">
            <div class="logo"><a href="/">Mobile2U</a></div>

            <?php if (!isset($is_auth_page) || !$is_auth_page): ?>
                <form class="search" action="/search.php" method="get">
                    <input type="text" name="q" placeholder="Search products...">
                    <button type="submit">Search</button>
                </form>
            <?php else: ?>
                <div style="flex: 1;"></div>
            <?php endif; ?>

            <nav class="nav">

                <?php if (!isset($is_auth_page) || !$is_auth_page): ?>
                    <a href="/products.php">Products</a>
                    <a href="/cart.php">Cart <span class="cart-count">0</span></a>
                    <a href="/dashboard/profile.php">Profile</a>
                    <a href="/">Home</a>

                    <?php if (isset($_SESSION['user_id'])): ?>
                        <a href="/authorization/logout.php" class="logout-link">Log Out</a>
                    <?php endif; ?>
                <?php endif; ?>

            </nav>
        </div>
    </header>
  <main class="content container">
