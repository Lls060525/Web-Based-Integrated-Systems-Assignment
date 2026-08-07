<?php
// ============================================================
// auth/reset_password.php - Password Reset, step 2 of 2
//
// The token arrives in the query string. Only its SHA-256 hash is
// stored in the database, it expires after RESET_TOKEN_TTL seconds
// and it is marked used the moment the password changes.
// ============================================================

require_once __DIR__ . '/../lib/init.php';

require_guest();

$title        = 'Reset Password - ' . APP_NAME;
$is_auth_page = true;

// The token travels in the URL on GET, and in a hidden field on POST.
$token = is_post() ? post('token') : get('token');
$reset = find_reset_token($token);

if (is_post() && $reset) {
    csrf_check();

    $password = post('password');
    $confirm  = post('password_confirm');

    if (v_password('password', $password, 'New password')) {
        v_same('password_confirm', $confirm, $password, 'Confirm password');
    }

    if (no_err()) {
        // Both statements must succeed together, or neither should.
        db()->beginTransaction();
        try {
            db_exec(
                'UPDATE users SET password_hash = ? WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]
            );
            consume_reset_token((int)$reset['id']);
            db()->commit();

            // Someone resetting a password may be recovering a hijacked
            // account, so every remembered device is revoked.
            remember_forget_all((int)$reset['user_id']);
        } catch (\Throwable $e) {
            db()->rollBack();
            error_log('Password reset failed: ' . $e->getMessage());
            add_err('password', 'Could not update your password. Please request a new link.');
        }

        if (no_err()) {
            flash_success('Your password has been reset. Please log in with your new password.');
            redirect('/auth/login.php');
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="auth-container">
    <div class="auth-card">

        <?php if (!$reset): ?>

            <h2>Link No Longer Valid</h2>
            <p class="auth-subtitle">
                This reset link is invalid, has already been used, or has expired.
                Please request a new one.
            </p>
            <div class="form-actions">
                <a href="/auth/forgot_password.php" class="btn-primary btn-block">Request a New Link</a>
            </div>
            <div class="auth-links"><a href="/auth/login.php">Back to login</a></div>

        <?php else: ?>

            <h2>Choose a New Password</h2>
            <p class="auth-subtitle">Resetting the password for <strong><?= e($reset['email']) ?></strong>.</p>

            <?php err_summary(); ?>

            <form action="/auth/reset_password.php" method="POST" class="form-standard">
                <?php csrf_field(); ?>
                <?php html_hidden('token', $token); ?>

                <?php field('password', 'New Password', function () {
                    html_password('password', ['required' => true, 'autofocus' => true]);
                    echo '<small class="form-hint">At least 8 characters, including a letter and a number.</small>';
                }, true); ?>

                <?php field('password_confirm', 'Confirm New Password', function () {
                    html_password('password_confirm', ['required' => true]);
                }, true); ?>

                <div class="form-actions">
                    <?php html_submit('Reset Password', ['class' => 'btn-primary btn-block']); ?>
                </div>
            </form>

        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
