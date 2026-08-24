<?php
// ============================================================
// admin/admins.php - Admin Maintenance (Admin)
//
// Administrators live in the same `users` table with role = 'admin'.
//
// DELETE IS A SOFT DELETE. `orders.user_id` has a foreign key to
// `users`, so removing a row would either fail or orphan order
// history. Setting status = 'deleted' keeps every existing record
// intact while blocking the account from logging in, and it can be
// restored later.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

require_permission('admins.manage');
require_once __DIR__ . '/../includes/admin_rows.php';

$title = 'Admin Maintenance - Admin';

// ---------- Actions ----------
if (is_post()) {
    csrf_check();

    $action = post('action');
    $id     = post_int('id');

    if ($id === null) {
        flash_error('Invalid request.');

    } elseif ($id === current_user_id()) {
        // Nobody may lock themselves out or delete their own account here.
        flash_error('You cannot change your own account from this page. Use Profile instead.');

    } else {
        $target = db_one("SELECT id, name, status FROM users WHERE id = ? AND role = 'admin'", [$id]);

        if (!$target) {
            flash_error('Administrator not found.');

        } elseif ($action === 'set_status') {
            $status = post('status');

            if (!in_array($status, ['active', 'banned'], true)) {
                flash_error('Invalid status.');
            } elseif ($status !== 'active' && $target['status'] === 'active' && active_admin_count() <= 1) {
                flash_error('This is the last active administrator. Promote or create another one first.');
            } else {
                db_exec('UPDATE users SET status = ? WHERE id = ?', [$status, $id]);
                flash_success($target['name'] . ' has been '
                    . ($status === 'banned' ? 'blocked' : 'reactivated') . '.');
            }

        } elseif ($action === 'delete') {
            if ($target['status'] === 'active' && active_admin_count() <= 1) {
                flash_error('This is the last active administrator and cannot be deleted.');
            } else {
                // Soft delete - see the note at the top of this file.
                db_exec("UPDATE users SET status = 'deleted' WHERE id = ?", [$id]);
                revoke_reset_tokens($id);
                flash_success($target['name'] . ' has been deleted. The account can be restored later.');
            }

        } else {
            flash_error('Invalid request.');
        }
    }

    redirect('/admin/admins.php');
}

// ---------- Listing ----------
$q      = get('q');
$status = get('status');

// The role name is joined in when the module is installed, and stubbed
// out as NULL when it is not, so the row renderer needs no branch of its
// own. LEFT JOIN because role_id is allowed to be NULL -- an account
// with no role must still appear in this list.
$roleJoin   = role_module_ready() ? 'LEFT JOIN roles r ON r.id = u.role_id' : '';
$roleSelect = role_module_ready() ? 'r.name AS role_name' : 'NULL AS role_name';

$sql    = "SELECT u.id, u.name, u.email, u.status, u.profile_photo, u.created_at, $roleSelect
             FROM users u
             $roleJoin
            WHERE u.role = 'admin'";
$params = [];

if ($q !== '') {
    $sql     .= ' AND (u.name LIKE ? OR u.email LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

if (in_array($status, USER_STATUSES, true)) {
    $sql     .= ' AND u.status = ?';
    $params[] = $status;
} else {
    // By default hide deleted accounts; the filter can bring them back.
    $sql .= " AND u.status <> 'deleted'";
}

$sql .= ' ORDER BY u.id ASC';

$admins = db_all($sql, $params);

if (is_ajax()) {
    admin_admin_rows($admins);
    exit;
}

$activeCount = active_admin_count();

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Admin Maintenance</h2>

        <div class="admin-header-actions">
            <form action="/admin/admins.php" method="GET" class="filter-form-inline">
                <?php html_hidden('q', $q); ?>
                <?php html_select('status', USER_STATUS_LABELS, $status,
                                  ['class' => 'form-control form-control-sm js-auto-submit'],
                                  'Active + Blocked'); ?>
                <noscript><?php html_submit('Filter', ['class' => 'btn-outline btn-sm']); ?></noscript>
            </form>

            <form action="/admin/admins.php" method="GET" class="admin-search-form" data-target="#adminTableBody">
                <?php if (in_array($status, USER_STATUSES, true)) { html_hidden('status', $status); } ?>
                <input type="text" name="q" value="<?= e($q) ?>"
                       placeholder="Search by name or email..." class="admin-search-input">
                <?php html_submit('Search'); ?>
                <?php if ($q !== '' || $status !== ''): ?>
                    <a href="/admin/admins.php" class="btn-outline">Clear</a>
                <?php endif; ?>
            </form>

            <a href="/admin/admin_form.php" class="btn-primary">+ Add New Admin</a>
        </div>
    </div>

    <?php if ($activeCount <= 1): ?>
        <div class="alert alert-info mt-4">
            There is only <strong>one active administrator</strong>. The system will refuse to block or
            delete the last one, so create a second account before making changes.
        </div>
    <?php endif; ?>

    <div class="card mt-4">
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Photo</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Joined Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="adminTableBody">
                    <?php admin_admin_rows($admins); ?>
                </tbody>
            </table>
        </div>
    </div>

    <p class="muted mt-2 small-note">
        Deleting an administrator deactivates the account rather than erasing the row,
        so orders and records created by that person stay intact. Use the status filter
        to find and restore deleted accounts.
    </p>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
