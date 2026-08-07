<?php
// ============================================================
// admin/profile.php - Admin User Profile
// Profile update, password update and profile photo upload.
//
// NOTE: this page previously read and wrote a column called
// `password`. The rest of the system uses `password_hash`,
// so changing an admin password always failed. Fixed below.
// ============================================================

require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/dropzone.php';

$title  = 'Admin Profile - ' . APP_NAME;
$userId = current_user_id();

$activeTab = get('tab', 'profile');
if (!in_array($activeTab, ['profile', 'password'], true)) {
    $activeTab = 'profile';
}

if (is_post()) {
    csrf_check();

    $action = post('action');

    // ---------- A. Update profile ----------
    if ($action === 'update_profile') {
        $name  = post('name');
        $email = post('email');

        if (v_required('name', $name, 'Full name')) {
            v_max('name', $name, 100, 'Full name');
        }
        if (v_required('email', $email, 'Email') && v_email('email', $email)) {
            v_email_unique('email', $email, $userId);
        }

        if (no_err()) {
            db_exec('UPDATE users SET name = ?, email = ? WHERE id = ?', [$name, $email, $userId]);
            flash_success('Profile updated successfully.');
            redirect('/admin/profile.php?tab=profile');
        }

        $activeTab = 'profile';

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
                revoke_reset_tokens($userId);

                // Every remembered device is dropped too. The old password
                // may be exactly how an attacker planted one of them.
                remember_forget_all((int)$userId);

                // The current browser session survives, so they are not
                // thrown out mid-task, but its id is rotated so any session
                // fixated earlier is now worthless.
                if ($userId === current_user_id()) {
                    session_regenerate_id(true);
                }

                flash_success('Password changed successfully.');
                redirect('/admin/profile.php?tab=password');
            }
        }

        $activeTab = 'password';

    // ---------- C. Upload profile photo ----------
    // This used to be a stub that only showed a fake success message.
    } elseif ($action === 'update_image') {
        if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] === UPLOAD_ERR_NO_FILE) {
            add_err('profile_photo', 'Please choose an image first.');
        } else {
            $filename = save_uploaded_image('profile_photo', DIR_UPLOAD_AVATARS, 'avatar_uid' . $userId);

            if ($filename !== null) {
                $old = db_value('SELECT profile_photo FROM users WHERE id = ?', [$userId]);
                delete_uploaded_file(DIR_UPLOAD_AVATARS, $old);

                db_exec('UPDATE users SET profile_photo = ? WHERE id = ?', [$filename, $userId]);

                flash_success('Profile photo updated successfully.');
                redirect('/admin/profile.php?tab=' . $activeTab);
            }
        }
    }
}

$user = current_user();

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container admin-container-wide">

    <?php err_summary(); ?>

    <div class="profile-layout">

        <aside class="profile-sidebar">
            <div class="profile-avatar-section">
                <form action="/admin/profile.php?tab=<?= e($activeTab) ?>" method="POST"
                      enctype="multipart/form-data" class="upload-form">
                    <?php csrf_field(); ?>
                    <?php html_hidden('action', 'update_image'); ?>

                    <?php render_dropzone('profile_photo', avatar_image($user['profile_photo'] ?? null), [
                        'shape' => 'round',
                        'hint'  => 'Drag a photo here or click to browse.',
                    ]); ?>

                    <?php html_submit('Upload Photo', ['class' => 'btn-primary btn-block mt-2']); ?>
                </form>
            </div>

            <div class="profile-nav">
                <a href="#profile" class="profile-nav-item" id="nav-profile">My Profile</a>
                <a href="#password" class="profile-nav-item" id="nav-password">Change Password</a>
            </div>
        </aside>

        <main class="profile-content">

            <div id="tab-profile" class="tab-pane">
                <h2 class="content-title">My Profile</h2>
                <p class="content-subtitle">Manage your account personal information.</p>

                <form action="/admin/profile.php?tab=profile" method="POST" class="form-standard">
                    <?php csrf_field(); ?>
                    <?php html_hidden('action', 'update_profile'); ?>

                    <?php field('role_display', 'Role', function () {
                        html_text('role_display', 'Administrator', ['readonly' => true, 'disabled' => true]);
                    }); ?>

                    <?php field('name', 'Full Name', function () use ($user) {
                        html_text('name', $user['name'], ['required' => true, 'maxlength' => 100]);
                    }, true); ?>

                    <?php field('email', 'Email Address', function () use ($user) {
                        html_email('email', $user['email'], ['required' => true, 'maxlength' => 100]);
                    }, true); ?>

                    <?php html_submit('Save Changes'); ?>
                </form>
            </div>

            <div id="tab-password" class="tab-pane">
                <h2 class="content-title">Change Password</h2>
                <p class="content-subtitle">For your account's security, do not share your password with anyone.</p>

                <form action="/admin/profile.php?tab=password" method="POST" class="form-standard">
                    <?php csrf_field(); ?>
                    <?php html_hidden('action', 'update_password'); ?>

                    <?php field('current_password', 'Current Password', function () {
                        html_password('current_password', ['required' => true]);
                    }, true); ?>

                    <?php field('new_password', 'New Password', function () {
                        html_password('new_password', ['required' => true]);
                        echo '<small class="form-hint">At least 8 characters, including a letter and a number.</small>';
                    }, true); ?>

                    <?php field('confirm_password', 'Confirm Password', function () {
                        html_password('confirm_password', ['required' => true]);
                    }, true); ?>

                    <?php html_submit('Update Password'); ?>
                </form>
            </div>

        </main>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
