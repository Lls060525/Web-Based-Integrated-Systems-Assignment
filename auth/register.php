<?php
// ============================================================
// auth/register.php - Member Registration (Member Maintenance)
// ============================================================

require_once __DIR__ . '/../lib/init.php';
require_once __DIR__ . '/../includes/dropzone.php';
require_once __DIR__ . '/../includes/captcha_field.php';

require_guest();

$title        = 'Register - ' . APP_NAME;
$is_auth_page = true;

if (is_post()) {
    csrf_check();

    $name     = post('name');
    $email    = post('email');
    $password = post('password');
    $confirm  = post('password_confirm');

    // ---- Server-side validation ----
    if (v_required('name', $name, 'Full name')) {
        v_max('name', $name, 100, 'Full name');
    }

    if (v_required('email', $email, 'Email') && v_email('email', $email)) {
        v_max('email', $email, 100, 'Email');
        v_email_unique('email', $email);
    }

    if (v_password('password', $password)) {
        v_same('password_confirm', $confirm, $password, 'Confirm password');
    }

    // Checked with everything else so a bot gets no clue about which
    // field it got wrong from the timing or the order of the errors.
    captcha_verify('register');

    // ---- Optional profile photo on sign-up ----
    $photo = null;
    if (no_err()) {
        $photo = save_uploaded_image('profile_photo', DIR_UPLOAD_AVATARS, 'avatar_new');
    }

    if (no_err()) {
        db_exec(
            'INSERT INTO users (name, email, password_hash, role, status, profile_photo)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$name, $email, password_hash($password, PASSWORD_DEFAULT), 'member', 'active', $photo]
        );

        $user = db_one('SELECT id, role FROM users WHERE id = ?', [db_last_id()]);
        login_user($user);

        captcha_clear('register');
        flash_success('Welcome to ' . APP_NAME . ', ' . $name . '. Your account is ready.');
        redirect('/member/home.php');
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="auth-container">
    <div class="auth-card">
        <h2>Create an Account</h2>
        <p class="auth-subtitle">It only takes a moment.</p>

        <?php err_summary(); ?>

        <form action="/auth/register.php" method="POST" enctype="multipart/form-data" class="form-standard">
            <?php csrf_field(); ?>

            <?php field('name', 'Full Name', function () {
                html_text('name', '', ['required' => true, 'maxlength' => 100, 'autofocus' => true]);
            }, true); ?>

            <?php field('email', 'Email Address', function () {
                html_email('email', '', [
                    'required'  => true,
                    'maxlength' => 100,
                    'id'        => 'registerEmail',
                ]);
            }, true); ?>

            <?php field('password', 'Password', function () {
                html_password('password', ['required' => true]);
                echo '<small class="form-hint">At least 8 characters, including a letter and a number.</small>';
            }, true); ?>

            <?php field('password_confirm', 'Confirm Password', function () {
                html_password('password_confirm', ['required' => true]);
            }, true); ?>

            <?php field('profile_photo', 'Profile Photo (optional)', function () {
                render_dropzone('profile_photo', null, [
                    'shape' => 'round',
                    'hint'  => 'Optional. Drag a photo here or click to browse.',
                ]);
            }); ?>

            <?php render_captcha_field('register'); ?>

            <div class="form-actions">
                <?php html_submit('Sign Up', ['class' => 'btn-primary btn-block']); ?>
            </div>
        </form>

        <div class="auth-links">
            Already registered? <a href="/auth/login.php">Log in here</a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
