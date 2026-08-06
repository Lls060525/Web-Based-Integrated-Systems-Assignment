<?php
// ============================================================
// member/addresses.php - Shipping Address book (Member)
// ============================================================

require_once __DIR__ . '/../lib/init.php';
require_once __DIR__ . '/../includes/address_parts.php';

require_member();

$title  = 'My Addresses - ' . APP_NAME;
$userId = current_user_id();

if (!address_module_ready()) {
    flash_error('Address book is not available yet: run database/migration_08_address.sql.');
    redirect('/member/profile.php');
}

// ---------- Actions ----------
if (is_post()) {
    csrf_check();

    $action    = post('action');
    $addressId = post_int('id');

    if ($addressId === null) {
        flash_error('Invalid request.');

    } elseif (!find_user_address($addressId, $userId)) {
        // Ownership check: a member can never touch someone else's address.
        flash_error('Address not found.');

    } elseif ($action === 'set_default') {
        set_default_address($addressId, $userId);
        flash_success('Default shipping address updated.');

    } elseif ($action === 'delete') {
        delete_address($addressId, $userId);
        flash_success('Address deleted. Your past orders are unaffected.');

    } else {
        flash_error('Invalid request.');
    }

    redirect('/member/addresses.php');
}

$addresses = user_addresses($userId);
$atLimit   = count($addresses) >= ADDRESS_MAX_PER_USER;

include __DIR__ . '/../includes/header.php';
?>

<nav class="breadcrumb">
    <a href="/member/profile.php">My Profile</a> &gt; <span>My Addresses</span>
</nav>

<div class="page-title-row">
    <h2 class="page-title">My Addresses</h2>

    <?php if (!$atLimit): ?>
        <a href="/member/address_form.php" class="btn-primary">+ Add New Address</a>
    <?php endif; ?>
</div>

<?php if ($atLimit): ?>
    <div class="alert alert-info">
        You have reached the limit of <?= ADDRESS_MAX_PER_USER ?> saved addresses.
        Delete one before adding another.
    </div>
<?php endif; ?>

<p class="muted small-note">
    Your default address is pre-selected at checkout. Deleting an address never
    changes an order you have already placed &mdash; each order keeps its own copy.
</p>

<div class="section-margin-top">
    <?php render_address_list($addresses, 'manage'); ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
