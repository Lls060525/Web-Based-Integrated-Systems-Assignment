<?php
// ============================================================
// includes/admin_header.php - admin layout (top half)
// Closed by includes/admin_footer.php
// ============================================================

require_once __DIR__ . '/../lib/init.php';

$title = $title ?? 'Admin Panel - ' . APP_NAME;

// Always read the live record so a profile update shows up immediately.
// These are prefixed with layout_ so a page can use $admin/$user for its own
// data without the layout silently overwriting it.
$layout_user   = current_user();
$layout_name   = $layout_user['name'] ?? 'Admin';
$layout_avatar = avatar_image($layout_user['profile_photo'] ?? null);

// Highlight the sidebar entry for the page we are on.
// Form pages highlight the listing they belong to.
$layout_page = basename($_SERVER['SCRIPT_NAME']);
$layout_page = match ($layout_page) {
    'admin_form.php'    => 'admins.php',
    'voucher_form.php'  => 'vouchers.php',
    'member_detail.php' => 'members.php',
    'category_form.php' => 'categories.php',
    'product_form.php'  => 'products.php',
    'order_detail.php'  => 'orders.php',
    default             => $layout_page,
};

$layout_nav = [
    'dashboard.php' => ['label' => 'Dashboard',  'icon' => 'fa-chart-line'],
    'members.php'   => ['label' => 'Members',    'icon' => 'fa-users'],
    'admins.php'    => ['label' => 'Admins',     'icon' => 'fa-user-shield'],
    'categories.php'=> ['label' => 'Categories', 'icon' => 'fa-tags'],
    'products.php'  => ['label' => 'Products',   'icon' => 'fa-box'],
    'stock.php'     => ['label' => 'Stock',      'icon' => 'fa-boxes-stacked'],
    'orders.php'    => ['label' => 'Orders',     'icon' => 'fa-shopping-cart'],
    'vouchers.php'  => ['label' => 'Vouchers',   'icon' => 'fa-ticket'],
    'login_attempts.php' => ['label' => 'Login Security', 'icon' => 'fa-shield-halved'],
    'mail_test.php' => ['label' => 'Mail & PDF',  'icon' => 'fa-envelope-circle-check'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?></title>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">

    <!-- Font Awesome: icon font library only, not a CSS framework -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/admin_sidebar.css">
    <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">

    <!-- jQuery must load here so every admin page can use jQuery event handling -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="/assets/js/main.js" defer></script>
    <script src="/assets/js/admin.js" defer></script>
</head>
<body>

<div class="admin-wrapper">
    <aside class="admin-sidebar" id="sidebar">
        <a href="/admin/dashboard.php" class="sidebar-brand"><?= e(APP_NAME) ?></a>
        <nav class="sidebar-nav">
            <?php foreach ($layout_nav as $file => $item): ?>
                <a href="/admin/<?= e($file) ?>"
                   class="nav-link<?= $layout_page === $file ? ' active' : '' ?>">
                    <i class="fas <?= e($item['icon']) ?>"></i>
                    <span class="nav-label"><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <div class="admin-main-content">
        <header class="admin-topbar">
            <button type="button" class="toggle-btn" id="sidebarToggle" aria-label="Toggle sidebar">
                <i class="fas fa-bars"></i>
            </button>

            <div class="topbar-right">
                <a href="/admin/profile.php" class="topbar-profile">
                    <img src="<?= e($layout_avatar) ?>" alt="Profile photo" class="topbar-avatar">
                    <span class="topbar-name"><?= e($layout_name) ?></span>
                </a>

                <span class="topbar-divider"></span>

                <a href="/auth/logout.php" class="topbar-logout" title="Log Out">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </header>

        <main class="admin-main">
        <?php flash(); ?>
