<?php
// ============================================================
// auth/forgot_password.php - Password Reset, step 1 of 2
//
// The user enters their email; we issue a single-use token and
// deliver the reset link. Delivery is controlled by MAIL_MODE
// in lib/config.php:
//   'dev'  -> the link is shown on this page (no SMTP needed)
//   'prod' -> the link is emailed through SMTP with PHPMailer
// ============================================================

require_once __DIR__ . '/../lib/init.php';
require_once __DIR__ . '/../includes/captcha_field.php';

require_guest();

$title        = 'Forgot Password - ' . APP_NAME;
$is_auth_page = true;

$sent     = false;   // show the confirmation panel instead of the form
$dev_link = '';      // populated in dev mode only

if (is_post()) {
    csrf_check();

    $email = post('email');

    if (v_required('email', $email, 'Email')) {
        v_email('email', $email);
    }

    // Without this, the reset form is a way to have the site send mail
    // to any address repeatedly.
    captcha_verify('forgot');

    if (no_err() && !form_nonce_valid('forgot')) {
        add_err('email', 'That request was already sent. The form has been reset; '
                       . 'press Send again if you need another link.');
    }

    // Without a floor here the form is a way to have the site mail any
    // address repeatedly. The CAPTCHA raises the cost per attempt; this
    // caps the rate regardless.
    $wait = action_cooldown('password_reset', MAIL_TEST_COOLDOWN);

    if (no_err() && $wait > 0) {
        add_err('email', 'A reset link was just requested. Please wait ' . $wait
                       . ' more second' . ($wait === 1 ? '' : 's') . ' before trying again.');
    }

    if (no_err() && !reset_module_ready()) {
        add_err('email', 'Password reset is not available yet: run database/migration_password_reset.sql.');
    }

    if (no_err()) {
        $user = db_one('SELECT id, name, email FROM users WHERE email = ?', [$email]);

        // Always report success. Telling an attacker "no such account"
        // would turn this page into an account-enumeration tool.
        $sent = true;
        action_touch('password_reset');
        captcha_clear('forgot');

        if ($user) {
            $token  = create_reset_token((int)$user['id']);
            $result = send_reset_link($user['email'], $user['name'], $token);

            if ($result['mode'] === 'dev') {
                $dev_link = $result['link'];
            } elseif (!$result['sent']) {
                error_log('Reset mail failed: ' . $result['error']);
            }
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="auth-container">
    <div class="auth-card">

        <?php if ($sent): ?>

            <h2>Check Your Email</h2>
            <p class="auth-subtitle">
                If an account exists for that address, we have sent a link to reset the password.
                The link expires in <?= (int)(RESET_TOKEN_TTL / 60) ?> minutes and works only once.
            </p>

            <?php if ($dev_link !== ''): ?>
                <div class="alert alert-info">
                    <strong>Development mode.</strong>
                    Mail delivery is switched off, so the reset link is shown here:
                    <p class="reset-link-box">
                        <a href="<?= e($dev_link) ?>"><?= e($dev_link) ?></a>
                    </p>
                    <small>
                        Set <code>MAIL_MODE</code> to <code>'prod'</code> in
                        <code>lib/config.php</code> to send this by email instead.
                    </small>
                </div>
            <?php endif; ?>

            <div class="auth-links"><a href="/auth/login.php">Back to login</a></div>

        <?php else: ?>

            <h2>Forgot Your Password?</h2>
            <p class="auth-subtitle">
                Enter the email address on your account and we will send you a link to set a new password.
            </p>

            <?php err_summary(); ?>

            <form action="/auth/forgot_password.php" method="POST" class="form-standard">
                <?php csrf_field(); ?>
                <?php form_nonce('forgot'); ?>

                <?php field('email', 'Email Address', function () {
                    html_email('email', '', ['required' => true, 'autofocus' => true, 'maxlength' => 100]);
                }, true); ?>

                <?php render_captcha_field('forgot'); ?>

                <div class="form-actions">
                    <?php html_submit('Send Reset Link', [
                        'class'     => 'btn-primary btn-block',
                        'data-busy' => 'Sending...',
                    ]); ?>
                </div>
            </form>

            <div class="auth-links">
                Remembered it? <a href="/auth/login.php">Back to login</a>
            </div>

        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
