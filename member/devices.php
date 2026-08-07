<?php
// ============================================================
// member/devices.php - Remembered devices (Member)
//
// Lets a member see where they are still signed in and cut any of
// them off, which is the part most "remember me" features forget.
// ============================================================

require_once __DIR__ . '/../lib/init.php';

require_login();

$title  = 'Signed-in Devices - ' . APP_NAME;
$userId = (int)current_user_id();

if (!remember_ready()) {
    flash_error('This feature is not available yet: run database/migration_18_remember.sql.');
    redirect('/member/profile.php');
}

if (is_post()) {
    csrf_check();

    $action = post('action');

    if ($action === 'revoke') {
        $tokenId = post_int('id');

        if ($tokenId !== null && remember_forget_device($tokenId, $userId)) {
            flash_success('That device has been signed out.');
        } else {
            flash_error('Device not found.');
        }

    } elseif ($action === 'revoke_all') {
        $removed = remember_forget_all($userId);
        flash_success($removed . ' device(s) signed out. You will need to sign in again next time.');

    } else {
        flash_error('Invalid request.');
    }

    redirect('/member/devices.php');
}

$devices = remember_devices($userId);

include __DIR__ . '/../includes/header.php';
?>

<nav class="breadcrumb">
    <a href="/member/profile.php">My Profile</a> &gt; <span>Signed-in Devices</span>
</nav>

<div class="page-title-row">
    <h2 class="page-title">Signed-in Devices</h2>

    <?php if (count($devices) > 0): ?>
        <form action="/member/devices.php" method="POST" class="inline-form"
              data-confirm="Sign out of every device, including this one?">
            <?php csrf_field(); ?>
            <?php html_hidden('action', 'revoke_all'); ?>
            <?php html_submit('Sign Out Everywhere', ['class' => 'btn-outline btn-danger']); ?>
        </form>
    <?php endif; ?>
</div>

<p class="muted small-note">
    These are the devices where you chose <strong>Keep me signed in</strong>.
    Each one holds a token that is replaced every time it is used, so a copied
    cookie stops working as soon as you visit again. Revoke anything you do not
    recognise.
</p>

<?php if (session_is_remembered()): ?>
    <div class="alert alert-info mt-4">
        <i class="fas fa-circle-info"></i>
        You are signed in on this device from a remembered cookie rather than by
        typing your password.
    </div>
<?php endif; ?>

<div class="section-margin-top">
    <?php if (count($devices) === 0): ?>
        <div class="card empty-state-box">
            <h3 class="empty-state-title">No remembered devices.</h3>
            <p>Tick <strong>Keep me signed in</strong> when you next sign in and it will appear here.</p>
        </div>
    <?php else: ?>
        <?php foreach ($devices as $d): ?>
            <?php $isCurrent = remember_is_current_device($d); ?>

            <div class="card card-padded device-card <?= $isCurrent ? 'is-current' : '' ?>">
                <div class="device-main">
                    <span class="device-icon"><i class="fas fa-laptop"></i></span>

                    <div class="device-info">
                        <strong>
                            <?= e(describe_user_agent($d['user_agent'])) ?>
                            <?php if ($isCurrent): ?>
                                <span class="badge badge-success">This device</span>
                            <?php endif; ?>
                        </strong>

                        <div class="muted small-note">
                            IP <code><?= e($d['ip_address'] ?: 'unknown') ?></code>
                            &middot; added <?= e(fmt_datetime($d['created_at'])) ?>
                            <?php if (!empty($d['last_used_at'])): ?>
                                &middot; last used <?= e(fmt_datetime($d['last_used_at'])) ?>
                            <?php endif; ?>
                        </div>

                        <div class="muted small-note">
                            Expires <?= e(fmt_datetime($d['expires_at'])) ?>
                        </div>
                    </div>
                </div>

                <form action="/member/devices.php" method="POST" class="inline-form"
                      data-confirm="<?= $isCurrent
                            ? 'Sign out this device? You will need to sign in again.'
                            : 'Sign out ' . e(describe_user_agent($d['user_agent'])) . '?' ?>">
                    <?php csrf_field(); ?>
                    <?php html_hidden('action', 'revoke'); ?>
                    <?php html_hidden('id', $d['id']); ?>
                    <?php html_submit('Sign Out', ['class' => 'btn-outline btn-sm btn-danger']); ?>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
