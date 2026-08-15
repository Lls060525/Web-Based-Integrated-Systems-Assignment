<?php
// ============================================================
// auth/login.php - Login (Security module)
//
// Includes temporary blocking after LOGIN_MAX_ATTEMPTS failures.
// The lock is checked BEFORE the password is verified, so a locked
// account cannot be probed even with the correct password.
// ============================================================

require_once __DIR__ . '/../lib/init.php';
require_once __DIR__ . '/../includes/captcha_field.php';

require_guest();          // already signed in? go straight to your area

$title        = 'Login - ' . APP_NAME;
$is_auth_page = true;

$lock = ['locked' => false, 'remaining_attempts' => LOGIN_MAX_ATTEMPTS, 'seconds_left' => 0, 'message' => ''];

/**
 * A CAPTCHA only appears once this address has already failed.
 *
 * Showing one to everybody punishes the honest majority for the sake of
 * the rare bot. Tying it to the failure counter that already exists
 * means a member who types their password correctly never sees one,
 * while a script working through a list meets one immediately.
 */
$needCaptcha = captcha_ready()
    && failed_attempts_for_email(is_post() ? post('email') : '') >= CAPTCHA_ON_LOGIN_AFTER;

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

        // Verified before the password so a bot cannot use response
        // timing to tell a real address from an invented one.
        if ($needCaptcha) {
            captcha_verify('login');
        }

        if (!no_err()) {
            // The CAPTCHA failed; record the attempt and stop here.
            record_login_attempt($email, false);

        } elseif ($lock['locked']) {
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
                    // This failure was the one that crossed the threshold.
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

                captcha_clear('login');
                unset($_SESSION[LOGIN_PENDING_EMAIL]);
                login_user($user);
                $_SESSION['auth_via'] = 'password';

                // Only issue the long-lived cookie when it was asked for.
                if (post('remember') === '1') {
                    remember_issue((int)$user['id']);
                }

                flash_success('Welcome back, ' . $user['name'] . '.');
                redirect(home_url_for_role($user['role']));
            }
        }
    }
    // Remember which address this page is currently about.
    //
    // The CAPTCHA requirement is derived from that address's failure
    // count, and the redirect below only carries the submitted values
    // for ONE request. Without this, a plain refresh would leave the
    // email box empty, the requirement would evaluate to false, and the
    // CAPTCHA would disappear -- while the POST handler, which computes
    // it from the address actually submitted, would still demand one.
    // The member would then be asked to solve a CAPTCHA that was never
    // drawn, and every attempt would count as another failure.
    if ($email !== '') {
        $_SESSION[LOGIN_PENDING_EMAIL] = $email;
    }

    // Validation failed. Answer with a redirect rather than a page, so
    // the browser's history entry is a GET and F5 cannot resubmit.
    // The errors and what was typed are carried across the redirect.
    redirect_back();
}

// ---------- What this page is about ----------
// post('email') answers on the request straight after a failed submit,
// because the parked copy is still there. On any refresh after that it
// is empty, so the session-remembered address takes over. Everything the
// page shows -- the CAPTCHA, the lockout countdown, the email box --
// hangs off this one value, so all three stay consistent across as many
// refreshes as the member cares to make.
$pendingEmail = post('email');

if ($pendingEmail === '') {
    $pendingEmail = (string)($_SESSION[LOGIN_PENDING_EMAIL] ?? '');
}

$needCaptcha = captcha_ready()
    && failed_attempts_for_email($pendingEmail) >= CAPTCHA_ON_LOGIN_AFTER;

// Recomputed here rather than relied on from the POST branch, which no
// longer renders anything. This is also why the countdown survives a
// refresh now, where before it was drawn once and lost.
if ($pendingEmail !== '') {
    $lock = login_lock_status($pendingEmail);
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

            <?php field('email', 'Email Address', function () use ($pendingEmail) {
                html_email('email', $pendingEmail, ['required' => true, 'autofocus' => true, 'maxlength' => 100]);
            }, true); ?>

            <?php field('password', 'Password', function () {
                html_password('password', ['required' => true]);
            }, true); ?>

            <?php if ($needCaptcha): ?>
                <p class="muted small-note captcha-reason">
                    <i class="fas fa-shield-halved"></i>
                    A sign-in for this address has already failed, so please confirm
                    you are not a robot.
                </p>
                <?php render_captcha_field('login'); ?>
            <?php endif; ?>

            <?php if (remember_ready()): ?>
                <div class="form-group form-check">
                    <label for="remember" class="check-label">
                        <input type="checkbox" name="remember" id="remember" value="1"
                               <?= post('remember') === '1' ? 'checked' : '' ?>>
                        <span>
                            Keep me signed in for <?= REMEMBER_DAYS ?> days
                            <small class="form-hint">
                                Do not tick this on a shared or public computer.
                            </small>
                        </span>
                    </label>
                </div>
            <?php endif; ?>

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
