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
      <form class="search" action="/search.php" method="get">
        <input type="text" name="q" placeholder="Search products...">
        <button type="submit">Search</button>
      </form>
      <nav class="nav">
        <a href="/">Home</a>
        <a href="/products.php">Products</a>
        <a href="/cart.php">Cart <span class="cart-count">0</span></a>
      </nav>
    </div>
  </header>
  <main class="content container">
