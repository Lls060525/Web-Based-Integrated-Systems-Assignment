<?php
// ============================================================
// admin/admin_form.php - Create / Edit an administrator
//
// Add mode : name, email, password, status, optional photo
// Edit mode: same, except the password is only changed when the
//            administrator actually types a new one.
//
// ------------------------------------------------------------
// THE INTERESTING PART: YOU CAN LOCK EVERYONE OUT FROM HERE
// ------------------------------------------------------------
//
// This is the only screen in the project that can destroy access to
// itself. Three ordinary-looking actions each end with nobody able to
// administer the shop again:
//
//   deactivate your own account          -> logged out, cannot return
//   deactivate the last active admin     -> nobody can sign in at all
//   remove the last role holding
//     admins.manage or roles.manage      -> everyone still signs in,
//                                           and nobody can fix it
//
// The third is the nasty one, because nothing appears broken. Every
// account works; the Manage Admins page has simply become
// unreachable, and there is no way back through the interface. The
// recovery is an UPDATE in phpMyAdmin -- which is not a recovery for
// anyone who does not have that access.
//
// So all three are refused, and each refusal is written as a
// validation error rather than a crash, because the administrator has
// not done anything stupid. They have done something reasonable that
// happens to be irreversible.
//
// ------------------------------------------------------------
// A CHECK ABOUT THE EDITED ACCOUNT, NOT THE EDITOR
// ------------------------------------------------------------
//
// Worth noticing in role_assignment_lockout_reason() below: it asks
// about the account BEING EDITED, not about whoever is doing the
// editing. Demoting a colleague removes exactly the same permission
// from the system as demoting yourself. A guard that only protected
// the current user would feel safe and would not be.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

require_permission('admins.manage');
require_once __DIR__ . '/../includes/dropzone.php';

$id     = get_int('id');
$isEdit = $id !== null;

$account = [
    'id'            => null,
    'name'          => '',
    'email'         => '',
    'status'        => 'active',
    'role_id'       => null,
    'profile_photo' => null,
    'created_at'    => null,
];

if ($isEdit) {
    $roleColumn = role_module_ready() ? 'role_id' : 'NULL AS role_id';

    $found = db_one(
        "SELECT id, name, email, status, $roleColumn, profile_photo, created_at
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
    //
    // Lockout guard 1: your own account.
    // Deactivating yourself takes effect on the next request, so the
    // page you would land on is the login screen, refusing you.
    if ($isSelf && $status !== 'active') {
        add_err('status', 'You cannot deactivate your own account.');
    }

    // Lockout guard 2: the last active administrator.
    //
    // Read the four conditions -- each removes a case where the check
    // would be wrong rather than merely unnecessary:
    //
    //   $isEdit                    adding a new admin cannot reduce the count
    //   !$isSelf                   guard 1 already covers yourself, and a
    //                              second message about the same field
    //                              would be confusing
    //   status === 'active'        they were already inactive, so this
    //                              save does not change the count
    //   $status !== 'active'       we are actually deactivating
    //
    // active_admin_count() is asked LAST because it is the only one
    // that queries the database. && short-circuits, so on a normal save
    // the query never runs.
    if ($isEdit && !$isSelf && $account['status'] === 'active' && $status !== 'active'
        && active_admin_count() <= 1) {
        add_err('status', 'This is the last active administrator and must stay active.');
    }

    // ---------- Photo ----------
    // ---------- Role ----------
    $roleId = null;

    if (role_module_ready()) {
        $roleId  = post('role_id') === '' ? null : post_int('role_id');
        $options = role_options();

        if ($roleId !== null && !array_key_exists($roleId, $options)) {
            add_err('role_id', 'Please choose a role from the list.');
            $roleId = null;
        }

        // Somebody removing their own last route back to the role screen.
        // Checked for the account being edited, not for whoever is doing
        // the editing -- demoting a colleague can lock everyone out just
        // as easily as demoting yourself.
        if ($isEdit) {
            $lockout = role_assignment_lockout_reason((int)$account['id'], $roleId);

            if ($lockout !== null) {
                add_err('role_id', $lockout);
            }
        }
    }

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

            if (role_module_ready()) {
                db_exec('UPDATE users SET role_id = ? WHERE id = ?', [$roleId, $account['id']]);
            }

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
            if (role_module_ready()) {
                db_exec(
                    'INSERT INTO users (name, email, password_hash, role, status, role_id, profile_photo)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$name, $email, password_hash($password, PASSWORD_DEFAULT),
                     'admin', $status, $roleId, $photo]
                );
            } else {
                db_exec(
                    'INSERT INTO users (name, email, password_hash, role, status, profile_photo)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$name, $email, password_hash($password, PASSWORD_DEFAULT), 'admin', $status, $photo]
                );
            }

            flash_success('Administrator "' . $name . '" created successfully.');
        }

        redirect('/admin/admins.php');
    }

    // Validation failed: keep any freshly uploaded photo visible in the preview.
    $account['profile_photo'] = $photo;
    // Validation failed. Answer with a redirect rather than a page, so
    // the browser's history entry is a GET and F5 cannot resubmit.
    // The errors and what was typed are carried across the redirect.
    redirect_back();
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
                    <?php if (role_module_ready()): ?>
                        <?php field('role_id', 'Role', function () use ($account) {
                            html_select(
                                'role_id',
                                role_options(),
                                (string)($account['role_id'] ?? ''),
                                [],
                                'No role - can sign in, nothing else'
                            );
                            echo '<small class="form-hint">'
                               . 'Decides which admin pages this account can open. '
                               . (can_open('/admin/roles.php')
                                     ? '<a href="/admin/roles.php">Manage roles</a>'
                                     : '') . '</small>';
                        }); ?>
                    <?php else: ?>
                        <?php field('role_display', 'Role', function () {
                            html_text('role_display', 'Administrator', ['readonly' => true, 'disabled' => true]);
                        }); ?>
                    <?php endif; ?>
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

            <?php field('profile_photo', 'Profile Photo', function () use ($account) {
                render_dropzone('profile_photo', avatar_image($account['profile_photo']), [
                    'shape' => 'round',
                    'hint'  => 'JPG, PNG, GIF or WEBP, maximum '
                             . (UPLOAD_MAX_SIZE / 1024 / 1024) . ' MB. '
                             . 'Leave empty to keep the current photo.',
                ]);
            }); ?>

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
