<?php
// ============================================================
// auth/register.php - Member Registration (Member Maintenance)
//
// ------------------------------------------------------------
// THE ONE LINE TO UNDERSTAND ON THIS PAGE
// ------------------------------------------------------------
//
//     password_hash($password, PASSWORD_DEFAULT)
//
// The password the customer typed is never stored. What goes into the
// database is a one-way hash: you can check a guess against it, but
// you cannot turn it back into the password. So a database that leaks
// -- a stolen backup, an injection somewhere else -- does not hand
// over anybody's account, here or on the other sites where they have
// reused that password.
//
// PASSWORD_DEFAULT rather than naming an algorithm is deliberate. It
// means "whatever PHP currently considers best" (bcrypt today, and it
// has changed before). Hard-coding an algorithm here would freeze this
// project on whatever was current in 2026. Because the algorithm can
// move, auth/login.php also calls password_needs_rehash() and quietly
// upgrades a user's stored hash the next time they sign in correctly.
//
// Note what is NOT here: no salt. bcrypt generates a random one per
// password and stores it inside the hash string, which is why two
// people with the same password get different hashes -- and why a
// precomputed table of common passwords is useless against this.
// Never write your own salting; this call already did it properly.
//
// The check itself lives in lib/auth.php and uses password_verify(),
// which compares in constant time so the comparison cannot be timed
// to work out how much of a guess was right.
//
// ------------------------------------------------------------
// WHY VALIDATION RUNS BEFORE THE PHOTO IS SAVED
// ------------------------------------------------------------
//
// save_uploaded_image() writes a file to disk. If it ran first and
// then the email turned out to be taken, an orphaned avatar would be
// left behind for every failed attempt. Guarding it with no_err()
// means nothing is written until the account is definitely going to
// be created. Same file-versus-row ordering problem as
// admin/product_photos.php, solved by not starting.
// ============================================================

require_once __DIR__ . '/../lib/init.php';
require_once __DIR__ . '/../includes/dropzone.php';
require_once __DIR__ . '/../includes/captcha_field.php';

// Already signed in? Then this page makes no sense -- registering
// while logged in would either create a second account or overwrite
// the session. require_guest() sends them to their home page instead.
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

    // && short-circuits, so the checks run in increasing cost:
    //   present?  -> well-formed?  -> not too long?  -> not taken?
    // v_email_unique() is the only one that touches the database, so it
    // is the last to be asked and is skipped entirely for a blank or
    // malformed address. There is no point querying for "not-an-email".
    if (v_required('email', $email, 'Email') && v_email('email', $email)) {
        v_max('email', $email, 100, 'Email');

        // Checked here for a helpful message, and enforced AGAIN by a
        // UNIQUE index on users.email. Both are needed: this check and
        // the INSERT are two separate statements, so two people
        // registering the same address at the same moment can both pass
        // it. The index is what actually guarantees uniqueness; this is
        // what turns a database error into a sentence a human can act
        // on. Checking without the constraint would be a race; the
        // constraint without checking would be an ugly failure.
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
