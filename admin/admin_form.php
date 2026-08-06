<?php
// ============================================================
// admin/admin_form.php - Create / Edit an administrator
//
// Add mode : name, email, password, status, optional photo
// Edit mode: same, except the password is only changed when the
//            administrator actually types a new one.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

$id     = get_int('id');
$isEdit = $id !== null;

$account = [
    'id'            => null,
    'name'          => '',
    'email'         => '',
    'status'        => 'active',
    'profile_photo' => null,
    'created_at'    => null,
];

if ($isEdit) {
    $found = db_one(
        "SELECT id, name, email, status, profile_photo, created_at
           FROM users WHERE id = ? AND role = 'admin'",
        [$id]
    );

    if (!$found) {
        flash_error('Administrator not found.');
        redirect('/admin/admins.php');
    }
    $account = $found;
}

$isSelf = $isEdit && (int)$account['id'] === current_user_id();

if (is_post()) {
    csrf_check();

    $name     = post('name');
    $email    = post('email');
    $password = post('password');
    $confirm  = post('password_confirm');
    $status   = post('status');

    // ---------- Server-side validation ----------
    if (v_required('name', $name, 'Full name')) {
        v_max('name', $name, 100, 'Full name');
    }

    if (v_required('email', $email, 'Email') && v_email('email', $email)) {
        v_max('email', $email, 100, 'Email');
        v_email_unique('email', $email, $isEdit ? (int)$account['id'] : null);
    }

    // Password is compulsory when adding, optional when editing.
    if (!$isEdit) {
        if (v_password('password', $password)) {
            v_same('password_confirm', $confirm, $password, 'Confirm password');
        }
    } elseif ($password !== '' || $confirm !== '') {
        if (v_password('password', $password, 'New password')) {
            v_same('password_confirm', $confirm, $password, 'Confirm password');
        }
    }

    if (!in_array($status, USER_STATUSES, true)) {
        add_err('status', 'Please choose a valid account status.');
    }

    // ---------- Business rules ----------
    if ($isSelf && $status !== 'active') {
        add_err('status', 'You cannot deactivate your own account.');
    }

    if ($isEdit && !$isSelf && $account['status'] === 'active' && $status !== 'active'
        && active_admin_count() <= 1) {
        add_err('status', 'This is the last active administrator and must stay active.');
    }

    // ---------- Photo ----------
    $photo    = $account['profile_photo'];
    $newPhoto = save_uploaded_image('profile_photo', DIR_UPLOAD_AVATARS, 'avatar_admin');

    if ($newPhoto !== null) {
        $photo = $newPhoto;
    }

    if (no_err()) {
        if ($isEdit) {
            db_exec(
                'UPDATE users SET name = ?, email = ?, status = ?, profile_photo = ? WHERE id = ?',
                [$name, $email, $status, $photo, $account['id']]
            );

            if ($password !== '') {
                db_exec(
                    'UPDATE users SET password_hash = ? WHERE id = ?',
                    [password_hash($password, PASSWORD_DEFAULT), $account['id']]
                );
                revoke_reset_tokens((int)$account['id']);
            }

            if ($newPhoto !== null) {
                delete_uploaded_file(DIR_UPLOAD_AVATARS, $found['profile_photo']);
            }

            flash_success('Administrator "' . $name . '" updated successfully.');
        } else {
            db_exec(
                'INSERT INTO users (name, email, password_hash, role, status, profile_photo)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$name, $email, password_hash($password, PASSWORD_DEFAULT), 'admin', $status, $photo]
            );

            flash_success('Administrator "' . $name . '" created successfully.');
        }

        redirect('/admin/admins.php');
    }

    // Validation failed: keep any freshly uploaded photo visible in the preview.
    $account['profile_photo'] = $photo;
}

$title = ($isEdit ? 'Edit' : 'Add') . ' Administrator - Admin';

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container admin-container-narrow">
    <div class="admin-header">
        <h2><?= $isEdit ? 'Edit Administrator' : 'Add New Administrator' ?></h2>
        <a href="/admin/admins.php" class="btn-outline">&larr; Back to Admins</a>
    </div>

    <div class="card mt-4 card-padded">

        <?php err_summary(); ?>

        <?php if ($isSelf): ?>
            <div class="alert alert-info">
                You are editing your own account. The status field is locked to
                <strong>Active</strong> so you cannot lock yourself out.
            </div>
        <?php endif; ?>

        <form action="" method="POST" enctype="multipart/form-data" class="form-standard">
            <?php csrf_field(); ?>

            <div class="form-row">
                <div class="form-col">
                    <?php field('name', 'Full Name', function () use ($account) {
                        html_text('name', $account['name'], [
                            'required'  => true,
                            'maxlength' => 100,
                            'autofocus' => true,
                        ]);
                    }, true); ?>
                </div>

                <div class="form-col">
                    <?php field('email', 'Email Address', function () use ($account) {
                        html_email('email', $account['email'], ['required' => true, 'maxlength' => 100]);
                    }, true); ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-col">
                    <?php field('role_display', 'Role', function () {
                        html_text('role_display', 'Administrator', ['readonly' => true, 'disabled' => true]);
                    }); ?>
                </div>

                <div class="form-col">
                    <?php field('status', 'Account Status', function () use ($account, $isSelf) {
                        if ($isSelf) {
                            html_text('status_display', 'Active', ['readonly' => true, 'disabled' => true]);
                            html_hidden('status', 'active');
                        } else {
                            html_select('status', USER_STATUS_LABELS, $account['status']);
                        }
                    }, true); ?>
                </div>
            </div>

            <fieldset class="form-fieldset">
                <legend>
                    <?= $isEdit ? 'Reset Password' : 'Password' ?>
                </legend>

                <?php if ($isEdit): ?>
                    <p class="form-hint">
                        Leave both fields blank to keep the current password unchanged.
                    </p>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-col">
                        <?php field('password', $isEdit ? 'New Password' : 'Password', function () use ($isEdit) {
                            html_password('password', ['required' => !$isEdit, 'autocomplete' => 'new-password']);
                            echo '<small class="form-hint">At least 8 characters, including a letter and a number.</small>';
                        }, !$isEdit); ?>
                    </div>

                    <div class="form-col">
                        <?php field('password_confirm', 'Confirm Password', function () use ($isEdit) {
                            html_password('password_confirm', ['required' => !$isEdit, 'autocomplete' => 'new-password']);
                        }, !$isEdit); ?>
                    </div>
                </div>
            </fieldset>

            <?php field('profile_photo', 'Profile Photo', function () use ($account) { ?>
                <div class="image-preview-box">
                    <img src="<?= e(avatar_image($account['profile_photo'])) ?>"
                         id="adminPhotoPreview" alt="Profile preview" class="image-preview image-preview-round">
                </div>
                <?php html_file('profile_photo', ['accept' => 'image/*', 'id' => 'adminPhotoInput']); ?>
                <small class="form-hint">JPG, PNG, GIF or WEBP, maximum 2 MB. Leave blank to keep the current photo.</small>
            <?php }); ?>

            <?php if ($isEdit): ?>
                <p class="muted small-note">
                    Account created on <?= e(fmt_date($account['created_at'], 'F j, Y, g:i a')) ?>.
                </p>
            <?php endif; ?>

            <div class="form-actions text-right">
                <a href="/admin/admins.php" class="btn-outline">Cancel</a>
                <?php html_submit($isEdit ? 'Save Changes' : 'Create Administrator'); ?>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
