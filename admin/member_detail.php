<?php
// ============================================================
// admin/member_detail.php - Member Detail (Admin)
// ============================================================

require_once __DIR__ . '/admin_auth.php';

$id = get_int('id');

if ($id === null) {
    flash_error('Invalid member id.');
    redirect('/admin/members.php');
}

$member = db_one("SELECT * FROM users WHERE id = ? AND role = 'member'", [$id]);

if (!$member) {
    flash_error('Member not found.');
    redirect('/admin/members.php');
}

// A little context: how much has this member actually ordered?
$stats = db_one(
    "SELECT COUNT(*) AS order_count, COALESCE(SUM(total_amount), 0) AS lifetime_value
       FROM orders
      WHERE user_id = ? AND status <> 'cancelled'",
    [$id]
);

// ---------- Manual point adjustment ----------
if (is_post()) {
    csrf_check();

    if (post('action') === 'adjust_points') {
        $delta  = post_int('points');
        $reason = post('reason');

        if (!points_module_ready()) {
            flash_error('Reward points are not set up yet.');
        } elseif ($delta === null || $delta === 0) {
            flash_error('Enter a non-zero number of points.');
        } elseif ($reason === '') {
            flash_error('A reason is required for a manual adjustment.');
        } elseif (mb_strlen($reason) > 200) {
            flash_error('The reason must not exceed 200 characters.');
        } elseif ($delta < 0 && points_balance($id) + $delta < 0) {
            flash_error('That would take the balance below zero. Current balance is '
                . number_format(points_balance($id)) . '.');
        } else {
            add_point_transaction(
                $id,
                'adjust',
                $delta,
                $reason,
                null,
                current_user_id()   // who made the adjustment, for the audit trail
            );

            flash_success('Balance adjusted by ' . ($delta > 0 ? '+' : '') . number_format($delta) . ' points.');
        }
    }

    redirect('/admin/member_detail.php?id=' . $id);
}

$recentOrders = db_all(
    'SELECT id, total_amount, status, created_at
       FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 5',
    [$id]
);

$title = 'Member Detail - ' . $member['name'];

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Member Detail</h2>
        <a href="/admin/members.php" class="btn-outline">&larr; Back to List</a>
    </div>

    <div class="profile-layout mt-4">

        <aside class="profile-sidebar">
            <div class="profile-avatar-section">
                <img src="<?= e(avatar_image($member['profile_photo'])) ?>" alt="" class="avatar-img">
                <h3><?= e($member['name']) ?></h3>
                <span class="badge">Member</span>
                <?php
                    $badge = match ($member['status']) {
                        'active' => 'badge-success',
                        'banned' => 'badge-danger',
                        default  => 'badge-warning',
                    };
                ?>
                <span class="badge <?= $badge ?>"><?= e(user_status_label($member['status'])) ?></span>

                <?php if ($member['status'] !== 'deleted'): ?>
                    <?php $willBan = $member['status'] === 'active'; ?>
                    <form action="/admin/members.php" method="POST" class="mt-2"
                          data-confirm="<?= $willBan
                                ? 'Block ' . e($member['name']) . '? They will not be able to log in.'
                                : 'Unblock ' . e($member['name']) . '?' ?>">
                        <?php csrf_field(); ?>
                        <?php html_hidden('action', 'set_status'); ?>
                        <?php html_hidden('id', $member['id']); ?>
                        <?php html_hidden('status', $willBan ? 'banned' : 'active'); ?>
                        <?php html_submit($willBan ? 'Block Account' : 'Unblock Account', [
                            'class' => 'btn-outline btn-sm btn-block ' . ($willBan ? 'btn-danger' : 'btn-success'),
                        ]); ?>
                    </form>
                <?php endif; ?>
            </div>

            <div class="card stat-card">
                <div class="stat-row">
                    <span class="muted">Orders placed</span>
                    <strong><?= (int)$stats['order_count'] ?></strong>
                </div>
                <div class="stat-row">
                    <span class="muted">Lifetime value</span>
                    <strong class="price"><?= e(money($stats['lifetime_value'])) ?></strong>
                </div>
                <?php if (points_module_ready()): ?>
                    <div class="stat-row">
                        <span class="muted">Reward points</span>
                        <strong><?= number_format(points_balance($id)) ?></strong>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (points_module_ready()): ?>
                <div class="card card-padded mt-4">
                    <h3 class="side-heading">Adjust Points</h3>
                    <p class="muted small-note">
                        Goodwill credits or corrections. Every adjustment is recorded
                        against your admin account.
                    </p>

                    <form action="/admin/member_detail.php?id=<?= (int)$id ?>" method="POST" class="form-standard">
                        <?php csrf_field(); ?>
                        <?php html_hidden('action', 'adjust_points'); ?>

                        <?php field('points', 'Points', function () {
                            html_number('points', '', ['required' => true, 'placeholder' => 'e.g. 500 or -200']);
                            echo '<small class="form-hint">Use a negative number to deduct.</small>';
                        }, true); ?>

                        <?php field('reason', 'Reason', function () {
                            html_text('reason', '', [
                                'required'    => true,
                                'maxlength'   => 200,
                                'placeholder' => 'Goodwill credit for delayed delivery',
                            ]);
                        }, true); ?>

                        <?php html_submit('Apply Adjustment', ['class' => 'btn-primary btn-block']); ?>
                    </form>
                </div>
            <?php endif; ?>
        </aside>

        <main class="profile-content">

            <div class="card">
                <div class="card-header">
                    <h2>Account Information</h2>
                    <p>System data for this member.</p>
                </div>
                <?php /* A definition list, not a form. These are values to
                         read and copy, and nothing here is editable -- see
                         detail_row() in lib/helpers.php for why the disabled
                         inputs this replaced were the wrong control. */ ?>
                <dl class="card-body info-list">
                    <?php detail_row('User ID', '#' . $member['id'], true); ?>
                    <?php detail_row('Name', $member['name']); ?>
                    <?php detail_row('Email Address', $member['email'], true); ?>
                    <?php detail_row('Account Status', user_status_label($member['status'])); ?>
                    <?php detail_row('Account Created On', fmt_date($member['created_at'], 'F j, Y, g:i a')); ?>
                </dl>
            </div>

            <?php if (points_module_ready()): ?>
                <?php $ledger = points_history($id, 20); ?>
                <div class="card mt-4">
                    <div class="card-header">
                        <h2>Points Ledger</h2>
                        <p class="muted small-note">
                            Balance is the sum of these entries. There is no cached total to drift.
                        </p>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="admin-table">
                            <thead>
                                <tr><th>Date</th><th>Activity</th><th>Type</th><th class="text-right">Points</th><th class="text-right">Balance</th></tr>
                            </thead>
                            <tbody>
                            <?php if (count($ledger) === 0): ?>
                                <tr><td colspan="5" class="table-empty">No point activity.</td></tr>
                            <?php else: ?>
                                <?php foreach ($ledger as $t): ?>
                                    <?php $positive = (int)$t['points'] > 0; ?>
                                    <tr>
                                        <td><?= e(fmt_datetime($t['created_at'])) ?></td>
                                        <td>
                                            <?= e($t['description']) ?>
                                            <?php if (!empty($t['admin_name'])): ?>
                                                <br><small class="muted">by <?= e($t['admin_name']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?= $positive ? 'badge-success' : 'badge-warning' ?>">
                                                <?= e(point_type_label($t['type'])) ?>
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            <strong class="<?= $positive ? 'points-plus' : 'points-minus' ?>">
                                                <?= $positive ? '+' : '' ?><?= number_format((int)$t['points']) ?>
                                            </strong>
                                        </td>
                                        <td class="text-right muted"><?= number_format((int)$t['balance_after']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card mt-4">
                <div class="card-header">
                    <h2>Recent Orders</h2>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Order</th><th>Date</th><th>Total</th><th>Status</th><th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (count($recentOrders) === 0): ?>
                            <tr><td colspan="5" class="table-empty">This member has not ordered yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentOrders as $o): ?>
                                <tr>
                                    <td><strong>#<?= (int)$o['id'] ?></strong></td>
                                    <td><?= e(fmt_datetime($o['created_at'])) ?></td>
                                    <td><?= e(money($o['total_amount'])) ?></td>
                                    <td><span class="status-badge status-<?= e($o['status']) ?>"><?= e(order_status_label($o['status'])) ?></span></td>
                                    <td><a href="/admin/order_detail.php?id=<?= (int)$o['id'] ?>" class="btn-outline btn-sm">View</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
