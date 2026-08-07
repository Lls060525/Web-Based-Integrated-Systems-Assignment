<?php
// ============================================================
// admin/members.php - Member Listing + Basic Searching (Admin)
// ============================================================

require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/admin_rows.php';

$title = 'Member Management - Admin';

// ---------- Block / unblock a member account ----------
if (is_post()) {
    csrf_check();

    if (post('action') === 'set_status') {
        $id     = post_int('id');
        $status = post('status');

        if ($id === null || !in_array($status, ['active', 'banned'], true)) {
            flash_error('Invalid request.');
        } elseif ($id === current_user_id()) {
            flash_error('You cannot change the status of your own account.');
        } else {
            $affected = db_exec(
                "UPDATE users SET status = ? WHERE id = ? AND role = 'member'",
                [$status, $id]
            );

            if ($affected === 0) {
                flash_error('Member not found.');
            } else {
                // Blocking must take effect immediately, including on any
                // device that would otherwise sign straight back in.
                if ($status === 'banned') {
                    remember_forget_all((int)$id);
                }

                flash_success('Account has been ' . ($status === 'banned' ? 'blocked' : 'unblocked') . '.');
            }
        }
    }

    redirect('/admin/members.php');
}

// ---------- Listing ----------
$q      = get('q');
$status = get('status');

$sql    = "SELECT id, name, email, status, profile_photo, created_at FROM users WHERE role = 'member'";
$params = [];

if ($q !== '') {
    $sql     .= ' AND (name LIKE ? OR email LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

if (in_array($status, USER_STATUSES, true)) {
    $sql     .= ' AND status = ?';
    $params[] = $status;
}

$sql .= ' ORDER BY id ASC';

$members = db_all($sql, $params);

// The AJAX search only needs the table rows.
if (is_ajax()) {
    admin_member_rows($members);
    exit;
}

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Member Management</h2>

        <div class="admin-header-actions">
            <form action="/admin/members.php" method="GET" class="filter-form-inline">
                <?php html_hidden('q', $q); ?>
                <?php html_select('status', USER_STATUS_LABELS, $status,
                                  ['class' => 'form-control form-control-sm js-auto-submit'], 'All statuses'); ?>
                <noscript><?php html_submit('Filter', ['class' => 'btn-outline btn-sm']); ?></noscript>
            </form>

            <form action="/admin/members.php" method="GET" class="admin-search-form" data-target="#memberTableBody">
                <?php if (in_array($status, USER_STATUSES, true)) { html_hidden('status', $status); } ?>
                <input type="text" name="q" value="<?= e($q) ?>"
                       placeholder="Search by name or email..." class="admin-search-input">
                <?php html_submit('Search'); ?>
                <?php if ($q !== '' || $status !== ''): ?>
                    <a href="/admin/members.php" class="btn-outline">Clear</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Photo</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Joined Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="memberTableBody">
                    <?php admin_member_rows($members); ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
