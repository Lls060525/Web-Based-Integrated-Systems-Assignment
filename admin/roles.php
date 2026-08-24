<?php
// ============================================================
// admin/roles.php - Manage Roles (listing + delete)
//
// Creating and editing live in role_form.php. This page lists what
// exists, shows at a glance what each role can reach, and handles
// deletion.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

require_permission('roles.manage');

$title = 'Roles - Admin';

// ---------- Delete ----------
if (is_post()) {
    csrf_check();

    if (post('action') === 'delete') {
        $role = find_role(post_int('id'));

        if ($role === null) {
            flash_error('That role no longer exists.');
        } else {
            // Re-checked here, not just hidden in the UI. The button being
            // absent is a courtesy; this is the actual rule.
            $blocker = role_delete_blocker($role);

            if ($blocker !== null) {
                flash_error($blocker);
            } else {
                db_exec('DELETE FROM roles WHERE id = ?', [$role['id']]);
                flash_success('Role "' . $role['name'] . '" was deleted.');
            }
        }
    }

    redirect('/admin/roles.php');
}

$roles = all_roles();

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Roles</h2>

        <div class="admin-header-actions">
            <a href="/admin/role_form.php" class="btn-primary">+ Add New Role</a>
        </div>
    </div>

    <?php if (!role_module_ready()): ?>
        <div class="alert alert-error mt-4">
            <strong>The roles module is not installed.</strong>
            Run <code>database/migration_25_roles.sql</code> in phpMyAdmin, then reload
            this page. Until then every administrator keeps full access, exactly as
            before.
        </div>
    <?php else: ?>

    <p class="muted small-note mt-2">
        A role is a named set of permissions. Every administrator account carries one
        role, and that role decides which pages they can open &mdash; both in the
        sidebar and by typing the address directly.
    </p>

    <div class="card mt-4">
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Role</th>
                        <th>Permissions</th>
                        <th>Lands on</th>
                        <th>Accounts</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($roles) === 0): ?>
                    <tr><td colspan="5" class="table-empty">No roles defined yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($roles as $role): ?>
                        <?php
                            $blocker = role_delete_blocker($role);
                            $grouped = role_permissions_grouped((int)$role['id']);
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($role['name']) ?></strong>
                                <?php if ((int)$role['is_system'] === 1): ?>
                                    <span class="badge badge-warning">System</span>
                                <?php endif; ?>

                                <?php if (!empty($role['description'])): ?>
                                    <div class="muted small-note"><?= e($role['description']) ?></div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($grouped === []): ?>
                                    <span class="muted">No permissions &mdash; this role can open nothing.</span>
                                <?php else: ?>
                                    <?php /* Grouped by area so the reader sees "everything in
                                             Catalogue" rather than a flat wall of chips. */ ?>
                                    <div class="perm-chips">
                                        <?php foreach ($grouped as $area => $rows): ?>
                                            <span class="perm-area"><?= e($area) ?>:</span>
                                            <?php foreach ($rows as $row): ?>
                                                <span class="perm-chip"><?= e($row['label']) ?></span>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php
                                    // Resolved the same way login does, so what is
                                    // shown here is what actually happens -- including
                                    // the fall back to Automatic when the chosen area
                                    // has since been unticked.
                                    $landingCode  = $role['landing_permission'] ?? null;
                                    $roleAreas    = areas_for_permission_ids(role_permission_ids((int)$role['id']));
                                    $landingValid = $landingCode !== null && isset($roleAreas[$landingCode]);
                                ?>

                                <?php if ($landingValid): ?>
                                    <?= e($roleAreas[$landingCode]['label']) ?>
                                <?php elseif ($roleAreas !== []): ?>
                                    <span class="muted">Automatic</span>
                                    <div class="muted small-note">
                                        &rarr; <?= e(reset($roleAreas)['label']) ?>
                                    </div>
                                <?php else: ?>
                                    <span class="muted">Own profile only</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php $count = (int)$role['account_count']; ?>
                                <?= $count ?> account<?= $count === 1 ? '' : 's' ?>
                            </td>

                            <td class="text-right">
                                <div class="row-actions">
                                    <a href="/admin/role_form.php?id=<?= (int)$role['id'] ?>"
                                       class="btn-outline btn-sm">Edit</a>

                                    <?php if ($blocker === null): ?>
                                        <button type="button"
                                                class="btn-outline btn-sm btn-danger js-submit-form"
                                                data-target="deleteRole_<?= (int)$role['id'] ?>"
                                                data-confirm="Delete the role &quot;<?= e($role['name']) ?>&quot;?">
                                            Delete
                                        </button>
                                    <?php else: ?>
                                        <span class="muted small-note" title="<?= e($blocker) ?>">
                                            <i class="fas fa-lock"></i> Locked
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php render_pager(paginate(count($roles), 50)); ?>
    </div>

    <?php /* Forms live outside the table: a <form> cannot be nested in a
             row without the browser hoisting it out of the table body. */ ?>
    <?php foreach ($roles as $role): ?>
        <?php if (role_delete_blocker($role) === null): ?>
            <form action="/admin/roles.php" method="POST"
                  id="deleteRole_<?= (int)$role['id'] ?>" class="hidden-form">
                <?php csrf_field(); ?>
                <?php html_hidden('action', 'delete'); ?>
                <?php html_hidden('id', $role['id']); ?>
            </form>
        <?php endif; ?>
    <?php endforeach; ?>

    <p class="muted small-note mt-2">
        A role in use cannot be deleted, and neither can a system role. Move the
        accounts to another role first &mdash; deleting a role that people still hold
        would silently strip their access with nothing on screen to explain why.
    </p>

    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
