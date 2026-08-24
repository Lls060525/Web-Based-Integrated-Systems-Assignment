<?php
// ============================================================
// admin/role_form.php - create or edit a role, and choose what it may do
// ============================================================

require_once __DIR__ . '/admin_auth.php';

require_permission('roles.manage');

$id     = get_int('id');
$isEdit = $id !== null;

$role = ['id' => null, 'name' => '', 'description' => '', 'is_system' => 0,
         'landing_permission' => null];

if ($isEdit) {
    $found = find_role($id);

    if ($found === null) {
        flash_error('That role no longer exists.');
        redirect('/admin/roles.php');
    }

    $role = $found;
}

// Which boxes start ticked. On a replayed form the posted set wins, so a
// failed save does not quietly discard the changes that were made.
$checked = $isEdit ? role_permission_ids((int)$role['id']) : [];

if ($_POST !== [] || old_array('permissions') !== []) {
    $posted  = is_array($_POST['permissions'] ?? null) ? $_POST['permissions'] : old_array('permissions');
    $checked = array_map('intval', array_values($posted));
}

if (is_post()) {
    csrf_check();

    $name        = post('name');
    $description = post('description');
    $landing     = post('landing_permission');
    $permissions = is_array($_POST['permissions'] ?? null) ? array_map('intval', $_POST['permissions']) : [];

    // The landing page must be one of the areas this role can actually
    // open, judged against what is being SAVED rather than what was
    // saved before. Ticking off an area and leaving it as the landing
    // page in the same submit would otherwise store a choice that is
    // already invalid.
    //
    // Not a hard error: an empty value means "work it out automatically",
    // which is exactly the right outcome here.
    if ($landing !== '' && !array_key_exists($landing, areas_for_permission_ids($permissions))) {
        $landing = '';
    }

    // ---------- Validation ----------
    if (v_required('name', $name, 'Role name')) {
        v_max('name', $name, 60, 'Role name');

        $dupSql    = 'SELECT id FROM roles WHERE name = ?';
        $dupParams = [$name];

        if ($isEdit) {
            $dupSql     .= ' AND id <> ?';
            $dupParams[] = $role['id'];
        }

        if (db_one($dupSql, $dupParams)) {
            add_err('name', 'Another role already uses this name.');
        }
    }

    v_max('description', $description, 255, 'Description');

    // ---------- The two ways to lock everybody out ----------
    if ($isEdit && (int)$role['is_system'] === 1) {
        // Super Admin exists so there is always something holding every
        // permission. Letting it be trimmed removes the one guaranteed
        // way back into the panel.
        if (count($permissions) < count(permission_labels())) {
            add_err('permissions', 'A system role must keep every permission. '
                                 . 'Create a separate role if you need a limited one.');
        }
    } elseif ($isEdit) {
        $lockout = role_save_lockout_reason((int)$role['id'], $permissions);

        if ($lockout !== null) {
            add_err('permissions', $lockout);
        }
    }

    if (no_err()) {
        $savedId = save_role(
            $isEdit ? (int)$role['id'] : null,
            $name,
            $description,
            $permissions,
            $landing !== '' ? $landing : null
        );

        flash_success($isEdit
            ? 'Role "' . $name . '" was updated.'
            : 'Role "' . $name . '" was created.');

        redirect('/admin/role_form.php?id=' . $savedId);
    }

    // Validation failed. Answer with a redirect rather than a page, so
    // the browser's history entry is a GET and F5 cannot resubmit.
    redirect_back();
}

$title      = ($isEdit ? 'Edit' : 'Add') . ' Role - Admin';
$catalogue  = permission_catalogue();
$totalPerms = count(permission_labels());

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container admin-container-wide">
    <div class="admin-header">
        <h2><?= $isEdit ? 'Edit Role' : 'Add New Role' ?></h2>
        <a href="/admin/roles.php" class="btn-outline">&larr; Back to Roles</a>
    </div>

    <?php if (!role_module_ready()): ?>
        <div class="alert alert-error mt-4">
            <strong>The roles module is not installed.</strong>
            Run <code>database/migration_25_roles.sql</code> first.
        </div>
    <?php else: ?>

    <?php err_summary(); ?>

    <?php if ($isEdit && (int)$role['is_system'] === 1): ?>
        <div class="alert alert-warning mt-4">
            <strong>This is a system role.</strong>
            Its name and description can be changed, but it must keep every
            permission &mdash; it is the guaranteed way back into the panel if a
            limited role is misconfigured.
        </div>
    <?php endif; ?>

    <form action="" method="POST" class="form-standard mt-4">
        <?php csrf_field(); ?>

        <div class="card card-padded">
            <?php field('name', 'Role Name', function () use ($role) {
                html_text('name', $role['name'], [
                    'required'    => true,
                    'maxlength'   => 60,
                    'autofocus'   => true,
                    'placeholder' => 'e.g. Stock Clerk',
                ]);
            }, true); ?>

            <?php field('description', 'Description', function () use ($role) {
                html_text('description', (string)($role['description'] ?? ''), [
                    'maxlength'   => 255,
                    'placeholder' => 'What is this role for? Shown on the roles list.',
                ]);
            }); ?>
        </div>

        <div class="card card-padded mt-4">
            <div class="perm-head">
                <h3 class="section-heading">Permissions</h3>
                <p class="muted small-note">
                    Each permission opens one area of the admin panel. Anything left
                    unticked is hidden from the sidebar <em>and</em> refused if the
                    address is typed directly.
                </p>

                <?php /* Convenience only. The server does not care which button was
                         pressed -- it reads the checkboxes. */ ?>
                <div class="perm-bulk">
                    <button type="button" class="btn-outline btn-sm js-perm-all">Select all</button>
                    <button type="button" class="btn-outline btn-sm js-perm-none">Clear all</button>
                    <span class="muted small-note perm-count"
                          data-total="<?= (int)$totalPerms ?>"></span>
                </div>
            </div>

            <?php if (has_err('permissions')): ?>
                <div class="alert alert-error mt-2"><?php err('permissions'); ?></div>
            <?php endif; ?>

            <div class="perm-grid">
                <?php foreach ($catalogue as $area => $rows): ?>
                    <fieldset class="perm-area-box">
                        <legend><?= e($area) ?></legend>

                        <?php foreach ($rows as $row): ?>
                            <?php $pid = (int)$row['id']; ?>
                            <label class="check-label perm-item" for="perm_<?= $pid ?>">
                                <input type="checkbox"
                                       name="permissions[]"
                                       id="perm_<?= $pid ?>"
                                       value="<?= $pid ?>"
                                       class="js-perm"
                                       <?= in_array($pid, $checked, true) ? 'checked' : '' ?>>
                                <span>
                                    <?= e($row['label']) ?>
                                    <?php if (!empty($row['description'])): ?>
                                        <small class="form-hint"><?= e($row['description']) ?></small>
                                    <?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (role_landing_ready()): ?>
            <?php
                // Offered from what is currently ticked, not from what is
                // saved, so the list agrees with the checkboxes above even
                // before the form is submitted.
                $landingAreas = areas_for_permission_ids($checked);

                $landingOptions = [];
                foreach ($landingAreas as $permission => $area) {
                    $landingOptions[$permission] = $area['label'];
                }
            ?>

            <div class="card card-padded mt-4">
                <h3 class="section-heading">Page after login</h3>
                <p class="muted small-note">
                    Where someone with this role arrives when they sign in. Only the
                    areas ticked above are offered, so a role can never land on a page
                    it is not allowed to open.
                </p>

                <?php if ($landingOptions === []): ?>
                    <p class="muted mt-2">
                        Tick at least one permission above and this list will fill in.
                        Until then, anyone with this role lands on their own profile.
                    </p>
                <?php else: ?>
                    <?php field('landing_permission', 'Show this page first', function () use ($role, $landingOptions) {
                        html_select(
                            'landing_permission',
                            $landingOptions,
                            (string)($role['landing_permission'] ?? ''),
                            [],
                            'Automatic - the first area they can open'
                        );
                        echo '<small class="form-hint">'
                           . 'Leave on Automatic unless this role has one obvious job. '
                           . 'A delivery driver who only scans QR codes should land on '
                           . 'Scan QR, not on a menu.</small>';
                    }); ?>
                <?php endif; ?>

                <p class="muted small-note mt-2">
                    <i class="fas fa-circle-info"></i>
                    If the chosen area is later untick&#101;d, the setting is ignored and
                    the role falls back to Automatic rather than failing.
                </p>
            </div>
        <?php endif; ?>

        <div class="form-actions text-right mt-4">
            <a href="/admin/roles.php" class="btn-outline">Cancel</a>
            <?php html_submit($isEdit ? 'Save Changes' : 'Create Role'); ?>
        </div>
    </form>

    <?php if ($isEdit): ?>
        <?php $granted = role_permissions_grouped((int)$role['id']); ?>

        <div class="card card-padded mt-4">
            <h3 class="section-heading">What this role can do right now</h3>
            <p class="muted small-note">
                Read from the database, not from the boxes above &mdash; so it shows
                what is actually saved rather than what is currently ticked.
            </p>

            <?php if ($granted === []): ?>
                <p class="muted mt-2">
                    Nothing. An account with this role can sign in and open its own
                    profile, and nothing else.
                </p>
            <?php else: ?>
                <dl class="info-list role-granted">
                    <?php foreach ($granted as $area => $rows): ?>
                        <?php detail_row($area, implode(', ', array_column($rows, 'label'))); ?>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>

            <?php
                $holders = db_all(
                    "SELECT id, name, email, status FROM users
                      WHERE role_id = ? AND role = 'admin' ORDER BY name ASC",
                    [$role['id']]
                );
            ?>

            <h3 class="section-heading mt-4">Accounts with this role</h3>

            <?php if ($holders === []): ?>
                <p class="muted">No accounts carry this role yet.</p>
            <?php else: ?>
                <ul class="role-holders">
                    <?php foreach ($holders as $holder): ?>
                        <li>
                            <?php /* Managing roles does not imply managing the
                                     accounts that hold them. */ ?>
                            <?php admin_link('/admin/admin_form.php?id=' . (int)$holder['id'], $holder['name']); ?>
                            <span class="muted small-note"><?= e($holder['email']) ?></span>
                            <?php if ($holder['status'] !== 'active'): ?>
                                <span class="badge badge-warning"><?= e(user_status_label($holder['status'])) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
