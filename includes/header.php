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

        <?php /* Hamburger. Rendered on every screen but only shown by CSS
                 below 720px, so the desktop bar is untouched. */ ?>
        <?php if (!$is_auth_page): ?>
            <button type="button" class="nav-toggle" id="navToggle"
                    aria-label="Menu" aria-expanded="false" aria-controls="mainNav">
                <i class="fas fa-bars" aria-hidden="true"></i>
            </button>
        <?php endif; ?>

        <div class="logo"><a href="/"><?= e(APP_NAME) ?></a></div>

        <?php if (!$is_auth_page): ?>
            <form class="search" action="/search.php" method="get">
                <input type="text" name="q" value="<?= e(get('q')) ?>" placeholder="Search products...">
                <button type="submit">Search</button>
            </form>
        <?php else: ?>
            <div class="topbar-spacer"></div>
        <?php endif; ?>

        <?php /* The two actions a shopper reaches for most stay OUTSIDE the
                 collapsible menu, as icons, so they are always one tap away.
                 Everything else folds away behind the hamburger. */ ?>
        <?php if (!$is_auth_page && is_member()): ?>
            <div class="nav-quick">
                <a href="/member/wishlist.php" class="nav-quick-link" aria-label="Wishlist">
                    <i class="far fa-heart" aria-hidden="true"></i>
                    <span class="wishlist-count"><?= $wishlist_total ?></span>
                </a>
                <a href="/cart.php" class="nav-quick-link" aria-label="Cart">
                    <i class="fas fa-cart-shopping" aria-hidden="true"></i>
                    <span class="cart-count"><?= $cart_count ?></span>
                </a>
            </div>
        <?php endif; ?>

        <?php /* Every entry has the same shape: icon, label, and an
                 optional count badge. The bar used to mix plain text
                 (Home), icon plus number (star, heart) and text plus
                 badge (Cart), which read as three different kinds of
                 control sitting in one row. */ ?>
        <nav class="nav" id="mainNav">
            <?php if (!$is_auth_page): ?>
                <a href="/">
                    <i class="fas fa-house" aria-hidden="true"></i>
                    <span class="nav-label">Home</span>
                </a>
                <a href="/products.php">
                    <i class="fas fa-box" aria-hidden="true"></i>
                    <span class="nav-label">Products</span>
                </a>

                <?php if (is_member()): ?>
                    <a href="/member/points.php" title="Reward points">
                        <i class="fas fa-star" aria-hidden="true"></i>
                        <span class="nav-label">Points</span>
                        <span class="nav-count points-count"><?= number_format($points_total) ?></span>
                    </a>

                    <?php /* Hidden on mobile: .nav-quick in the bar shows these
                             two as icons there. Only one copy is ever visible. */ ?>
                    <a href="/member/wishlist.php" class="nav-dup">
                        <i class="far fa-heart" aria-hidden="true"></i>
                        <span class="nav-label">Wishlist</span>
                        <span class="nav-count wishlist-count"><?= $wishlist_total ?></span>
                    </a>
                    <a href="/cart.php" class="nav-dup">
                        <i class="fas fa-cart-shopping" aria-hidden="true"></i>
                        <span class="nav-label">Cart</span>
                        <span class="nav-count cart-count"><?= $cart_count ?></span>
                    </a>

                    <a href="/member/profile.php">
                        <i class="fas fa-user" aria-hidden="true"></i>
                        <span class="nav-label">Profile</span>
                    </a>
                    <a href="/orders.php">
                        <i class="fas fa-receipt" aria-hidden="true"></i>
                        <span class="nav-label">Orders</span>
                    </a>
                    <a href="/auth/logout.php" class="logout-link">
                        <i class="fas fa-right-from-bracket" aria-hidden="true"></i>
                        <span class="nav-label">Log Out</span>
                    </a>

                <?php elseif (is_admin()): ?>
                    <a href="/admin/dashboard.php">
                        <i class="fas fa-gauge-high" aria-hidden="true"></i>
                        <span class="nav-label">Admin Panel</span>
                    </a>
                    <a href="/auth/logout.php" class="logout-link">
                        <i class="fas fa-right-from-bracket" aria-hidden="true"></i>
                        <span class="nav-label">Log Out</span>
                    </a>

                <?php else: ?>
                    <a href="/auth/login.php">
                        <i class="fas fa-right-to-bracket" aria-hidden="true"></i>
                        <span class="nav-label">Login</span>
                    </a>
                    <a href="/auth/register.php" class="logout-link">
                        <i class="fas fa-user-plus" aria-hidden="true"></i>
                        <span class="nav-label">Register</span>
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="content container">
<?php flash(); ?>
