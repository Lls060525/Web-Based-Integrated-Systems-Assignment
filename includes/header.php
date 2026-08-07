<?php
// ============================================================
// includes/header.php - storefront layout (top half)
// Closed by includes/footer.php
// ============================================================

require_once __DIR__ . '/../lib/init.php';

$title       = $title ?? APP_NAME . ' - Online Shop';
$is_auth_page = $is_auth_page ?? false;

// Live cart badge for members (single shared connection - no new PDO here).
$cart_count     = 0;
$wishlist_total = 0;
$points_total   = 0;

if (is_member()) {
    $cart_count     = (int)db_value('SELECT COALESCE(SUM(quantity), 0) FROM cart WHERE user_id = ?', [current_user_id()]);
    $wishlist_total = wishlist_count();
    $points_total   = points_balance();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <link rel="stylesheet" href="/assets/css/style.css">
    <!-- Font Awesome: icon font library only, not a CSS framework -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">
    <!-- jQuery (small external library - permitted by the assignment brief) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="/assets/js/main.js" defer></script>
    <script src="/assets/js/ajax.js" defer></script>
    <script src="/assets/js/dropzone.js" defer></script>
    <script src="/assets/js/webcam.js" defer></script>
</head>
<body>
<header class="topbar">
    <div class="container topbar-inner">
        <div class="logo"><a href="/"><?= e(APP_NAME) ?></a></div>

        <?php if (!$is_auth_page): ?>
            <form class="search" action="/search.php" method="get">
                <input type="text" name="q" value="<?= e(get('q')) ?>" placeholder="Search products...">
                <button type="submit">Search</button>
            </form>
        <?php else: ?>
            <div class="topbar-spacer"></div>
        <?php endif; ?>

        <nav class="nav">
            <?php if (!$is_auth_page): ?>
                <a href="/">Home</a>
                <a href="/products.php">Products</a>

                <?php if (is_member()): ?>
                    <a href="/member/points.php" title="Reward points">
                        <i class="fas fa-star"></i>
                        <span class="points-count"><?= number_format($points_total) ?></span>
                    </a>
                    <a href="/member/wishlist.php">
                        <i class="far fa-heart"></i>
                        <span class="wishlist-count"><?= $wishlist_total ?></span>
                    </a>
                    <a href="/cart.php">Cart <span class="cart-count"><?= $cart_count ?></span></a>
                    <a href="/member/profile.php">Profile</a>
                    <a href="/orders.php">Orders</a>
                    <a href="/auth/logout.php" class="logout-link">Log Out</a>
                <?php elseif (is_admin()): ?>
                    <a href="/admin/dashboard.php">Admin Panel</a>
                    <a href="/auth/logout.php" class="logout-link">Log Out</a>
                <?php else: ?>
                    <a href="/auth/login.php">Login</a>
                    <a href="/auth/register.php">Register</a>
                <?php endif; ?>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="content container">
<?php flash(); ?>
