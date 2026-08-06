<?php
// ============================================================
// auth/login.php - Login (Security module)
//
// Includes temporary blocking after LOGIN_MAX_ATTEMPTS failures.
// The lock is checked BEFORE the password is verified, so a locked
// account cannot be probed even with the correct password.
// ============================================================

require_once __DIR__ . '/../lib/init.php';

require_guest();          // already signed in? go straight to your area

$title        = 'Login - ' . APP_NAME;
$is_auth_page = true;

$lock = ['locked' => false, 'remaining_attempts' => LOGIN_MAX_ATTEMPTS, 'seconds_left' => 0, 'message' => ''];

if (is_post()) {
    csrf_check();

    $email    = post('email');
    $password = post('password');

    // ---- Server-side validation ----
    if (v_required('email', $email, 'Email')) {
        v_email('email', $email);
    }
    v_required('password', $password, 'Password');

    if (no_err()) {
        $lock = login_lock_status($email);

        if ($lock['locked']) {
            // Refuse before touching the password. A locked account must
            // not be testable even by someone holding the right one.
            add_err('email', $lock['message']);

            // The blocked attempt is still recorded, otherwise hammering
            // a locked account would be free and invisible.
            record_login_attempt($email, false);

        } else {
            $user = db_one(
                'SELECT id, name, password_hash, role, status FROM users WHERE email = ?',
                [$email]
            );

            $passwordOk = $user && password_verify($password, $user['password_hash']);

            if (!$passwordOk) {
                record_login_attempt($email, false);
                $lock = login_lock_status($email);

                // Deliberately vague about which half was wrong.
                $message = 'Invalid email or password.';

                if ($lock['locked']) {
                    $message = $lock['message'];
                } elseif ($lock['remaining_attempts'] <= LOGIN_MAX_ATTEMPTS - 1) {
                    $message .= ' You have ' . $lock['remaining_attempts']
                              . ' attempt' . ($lock['remaining_attempts'] === 1 ? '' : 's')
                              . ' left before this account is temporarily locked.';
                }

                add_err('password', $message);

            } elseif ($user['status'] === 'banned') {
                record_login_attempt($email, false);
                add_err('email', 'This account has been suspended. Please contact support.');

            } elseif ($user['status'] !== 'active') {
                // A deleted account must be indistinguishable from a wrong password.
                record_login_attempt($email, false);
                add_err('password', 'Invalid email or password.');

            } else {
                // ---- Success ----
                // Recording the success is what resets the counter: the
                // failure query only looks at attempts made after it.
                record_login_attempt($email, true);

                // Transparently upgrade the hash if PHP's default algorithm changed.
                if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                    db_exec(
                        'UPDATE users SET password_hash = ? WHERE id = ?',
                        [password_hash($password, PASSWORD_DEFAULT), $user['id']]
                    );
                }

                login_user($user);
                flash_success('Welcome back, ' . $user['name'] . '.');
                redirect(home_url_for_role($user['role']));
            }
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="auth-container">
    <div class="auth-card">
        <h2>Welcome Back</h2>
        <p class="auth-subtitle">Sign in to continue shopping.</p>

        <?php if ($lock['locked']): ?>
            <div class="alert alert-error lockout-panel"
                 data-seconds="<?= (int)$lock['seconds_left'] ?>">
                <h4 class="lockout-title">
                    <i class="fas fa-lock"></i> Temporarily Locked
                </h4>
                <p class="lockout-text"><?= e($lock['message']) ?></p>
                <p class="lockout-timer">
                    Unlocks in <strong id="lockCountdown"><?= e(format_countdown((int)$lock['seconds_left'])) ?></strong>
                </p>
                <p class="small-note">
                    Forgotten your password?
                    <a href="/auth/forgot_password.php">Reset it instead of guessing</a> &mdash;
                    a reset works even while the account is locked.
                </p>
            </div>
        <?php else: ?>
            <?php err_summary(); ?>
        <?php endif; ?>

        <form action="/auth/login.php" method="POST" class="form-standard" id="loginForm">
            <?php csrf_field(); ?>

            <?php field('email', 'Email Address', function () {
                html_email('email', '', ['required' => true, 'autofocus' => true, 'maxlength' => 100]);
            }, true); ?>

            <?php field('password', 'Password', function () {
                html_password('password', ['required' => true]);
            }, true); ?>

            <div class="form-actions">
                <?php html_submit('Log In', [
                    'class' => 'btn-primary btn-block',
                    'id'    => 'loginSubmit',
                ]); ?>
            </div>
        </form>

        <div class="auth-links">
            <a href="/auth/forgot_password.php">Forgot your password?</a>
        </div>
        <div class="auth-links">
            Don't have an account? <a href="/auth/register.php">Sign up here</a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
