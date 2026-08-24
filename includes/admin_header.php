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
    'role_form.php'     => 'roles.php',
    'voucher_form.php'  => 'vouchers.php',
    'member_detail.php' => 'members.php',
    'category_form.php' => 'categories.php',
    'product_form.php'   => 'products.php',
    'batch_price.php'    => 'batch_import.php',
    'spec_form.php'      => 'specs.php',
    'store_form.php'     => 'stores.php',
    'product_specs.php'  => 'products.php',
    'product_options.php'=> 'products.php',
    'batch_delete.php'   => 'batch_import.php',
    'product_photos.php' => 'products.php',
    'photo_edit.php'     => 'products.php',
    'order_detail.php'  => 'orders.php',
    default             => $layout_page,
};

// The sidebar is built from admin_areas() in lib/role.php, which is the
// same list the "page after login" dropdown and admin_landing_url() use.
// Keeping one list means the menu, the landing page and the permission
// checks cannot describe three different versions of the panel.
//
// Hiding a link is presentation, not security -- the page itself calls
// require_permission() and refuses regardless of how it was reached.
// Filtering here exists so the panel does not advertise doors that are
// locked.
$layout_nav = [];

foreach (permitted_admin_areas() as $permission => $area) {
    $layout_nav[basename($area['url'])] = $area;
}

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
    <script src="/assets/js/dropzone.js" defer></script>
    <script src="/assets/js/webcam.js" defer></script>
</head>
<body>

<div class="admin-wrapper">
    <aside class="admin-sidebar" id="sidebar">
        <?php /* Not a hardcoded /admin/dashboard.php. The logo is "go home",
                 and home depends on the role -- a Delivery Man clicking it
                 was being sent to a page their role cannot open. */ ?>
        <a href="<?= e(admin_landing_url()) ?>" class="sidebar-brand"><?= e(APP_NAME) ?></a>
        <nav class="sidebar-nav">
            <?php foreach ($layout_nav as $file => $item): ?>
                <a href="/admin/<?= e($file) ?>"
                   class="nav-link<?= $layout_page === $file ? ' active' : '' ?>">
                    <i class="fas <?= e($item['icon']) ?>"></i>
                    <span class="nav-label"><?= e($item['label']) ?></span>

                    <?php /* A queue nobody looks at is a queue that fills up, so
                             the count is on the menu rather than behind a click.
                             Only drawn when there is something waiting -- a
                             permanent "0" trains people to ignore it. */ ?>
                    <?php if ($file === 'cancellations.php'): ?>
                        <?php $waiting = pending_cancel_count(); ?>
                        <?php if ($waiting > 0): ?>
                            <span class="nav-count" title="<?= (int)$waiting ?> waiting for a decision">
                                <?= (int)$waiting ?>
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>

            <?php if ($layout_nav === []): ?>
                <?php /* A role with no permissions. Better to explain the empty
                         column than to leave the reader wondering whether the
                         page is broken. */ ?>
                <p class="sidebar-empty">
                    Your role has no areas assigned yet.
                    <a href="/admin/profile.php">Open your profile</a>
                    or ask a Super Admin for access.
                </p>
            <?php endif; ?>
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
