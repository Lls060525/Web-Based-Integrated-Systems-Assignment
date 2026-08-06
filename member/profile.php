<?php
// ============================================================
// member/profile.php - User Profile module
// Profile update, password update and profile photo upload.
// ============================================================

require_once __DIR__ . '/../lib/init.php';

require_login();

$title  = 'My Profile - ' . APP_NAME;
$userId = current_user_id();

if (is_post()) {
    csrf_check();

    $action = post('action');

    // ---------- A. Update profile ----------
    if ($action === 'update_profile') {
        $name  = post('name');
        $email = post('email');

        if (v_required('name', $name, 'Name')) {
            v_max('name', $name, 100, 'Name');
        }
        if (v_required('email', $email, 'Email') && v_email('email', $email)) {
            v_email_unique('email', $email, $userId);
        }

        if (no_err()) {
            db_exec('UPDATE users SET name = ?, email = ? WHERE id = ?', [$name, $email, $userId]);
            flash_success('Profile updated successfully.');
            redirect('/member/profile.php');
        }

    // ---------- B. Update password ----------
    } elseif ($action === 'update_password') {
        $current = post('current_password');
        $new     = post('new_password');
        $confirm = post('confirm_password');

        v_required('current_password', $current, 'Current password');

        if (v_password('new_password', $new, 'New password')) {
            v_same('confirm_password', $confirm, $new, 'Confirm password');
        }

        if (no_err()) {
            $hash = db_value('SELECT password_hash FROM users WHERE id = ?', [$userId]);

            if (!password_verify($current, (string)$hash)) {
                add_err('current_password', 'Your current password is incorrect.');
            } elseif ($current === $new) {
                add_err('new_password', 'The new password must be different from the current one.');
            } else {
                db_exec(
                    'UPDATE users SET password_hash = ? WHERE id = ?',
                    [password_hash($new, PASSWORD_DEFAULT), $userId]
                );

                // A password change should invalidate any pending reset link.
                revoke_reset_tokens($userId);

                flash_success('Password changed successfully.');
                redirect('/member/profile.php');
            }
        }

    // ---------- C. Upload profile photo ----------
    } elseif ($action === 'upload_photo') {
        if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] === UPLOAD_ERR_NO_FILE) {
            add_err('profile_photo', 'Please choose an image first.');
        } else {
            $filename = save_uploaded_image('profile_photo', DIR_UPLOAD_AVATARS, 'avatar_uid' . $userId);

            if ($filename !== null) {
                $old = db_value('SELECT profile_photo FROM users WHERE id = ?', [$userId]);
                delete_uploaded_file(DIR_UPLOAD_AVATARS, $old);

                db_exec('UPDATE users SET profile_photo = ? WHERE id = ?', [$filename, $userId]);

                flash_success('Profile photo updated successfully.');
                redirect('/member/profile.php');
            }
        }
    }
}

$user   = current_user();
$avatar = avatar_image($user['profile_photo'] ?? null);

include __DIR__ . '/../includes/header.php';
?>

<div class="profile-container">

    <?php err_summary(); ?>

    <div class="profile-layout">

        <aside class="profile-sidebar">
            <div class="profile-avatar-section">
                <img src="<?= e($avatar) ?>" alt="Profile photo" id="avatarPreview" class="avatar-img">

                <form action="/member/profile.php" method="POST" enctype="multipart/form-data" class="upload-form">
                    <?php csrf_field(); ?>
                    <?php html_hidden('action', 'upload_photo'); ?>

                    <label class="btn-outline" for="photoInput">Select Image</label>
                    <input type="file" id="photoInput" name="profile_photo" accept="image/*" class="visually-hidden">
                    <?php html_submit('Upload', ['class' => 'btn-primary btn-sm mt-2', 'id' => 'uploadBtn', 'style' => 'display:none;']); ?>
                    <?php err('profile_photo'); ?>
                </form>
            </div>

            <nav class="profile-nav">
                <a href="#profile-info" class="active">My Profile</a>
                <a href="#profile-password">Change Password</a>
                <a href="/member/addresses.php">My Addresses</a>
                <a href="/member/wishlist.php">My Wishlist</a>
                <a href="/member/points.php">Reward Points</a>
                <a href="/orders.php">My Orders</a>
            </nav>
        </aside>

        <main class="profile-content">

            <div class="card" id="profile-info">
                <div class="card-header">
                    <h2>My Profile</h2>
                    <p>Manage and protect your account.</p>
                </div>
                <div class="card-body">
                    <form action="/member/profile.php" method="POST" class="form-standard">
                        <?php csrf_field(); ?>
                        <?php html_hidden('action', 'update_profile'); ?>

                        <?php field('name', 'Name', function () use ($user) {
                            html_text('name', $user['name'], [
                                'required'      => true,
                                'maxlength'     => 100,
                                'id'            => 'profileName',
                                'data-original' => $user['name'],
                            ]);
                        }, true); ?>

                        <?php field('email', 'Email Address', function () use ($user) {
                            html_email('email', $user['email'], [
                                'required'      => true,
                                'maxlength'     => 100,
                                'id'            => 'profileEmail',
                                'data-original' => $user['email'],
                            ]);
                        }, true); ?>

                        <?php field('role', 'Role', function () use ($user) {
                            html_text('role', ucfirst($user['role']), ['readonly' => true, 'disabled' => true]);
                        }); ?>

                        <div class="form-actions text-right">
                            <?php html_submit('Save Changes', ['id' => 'saveProfileBtn', 'style' => 'display:none;']); ?>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card mt-4" id="profile-password">
                <div class="card-header">
                    <h2>Change Password</h2>
                    <p>For your account's security, do not share your password with anyone.</p>
                </div>
                <div class="card-body">
                    <form action="/member/profile.php" method="POST" class="form-standard">
                        <?php csrf_field(); ?>
                        <?php html_hidden('action', 'update_password'); ?>

                        <?php field('current_password', 'Current Password', function () {
                            html_password('current_password', ['required' => true]);
                        }, true); ?>

                        <?php field('new_password', 'New Password', function () {
                            html_password('new_password', ['required' => true]);
                            echo '<small class="form-hint">At least 8 characters, including a letter and a number.</small>';
                        }, true); ?>

                        <?php field('confirm_password', 'Confirm New Password', function () {
                            html_password('confirm_password', ['required' => true]);
                        }, true); ?>

                        <?php html_submit('Update Password'); ?>
                    </form>
                </div>
            </div>

        </main>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
