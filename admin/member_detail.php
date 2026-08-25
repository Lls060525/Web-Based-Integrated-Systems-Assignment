<?php
// ============================================================
// admin/member_detail.php - Member Detail (Admin)
//
// One member, everything about them: profile, order history summary,
// reward point balance, and a manual point adjustment tool.
//
// ------------------------------------------------------------
// THE FOUR-LINE OPENING EVERY DETAIL PAGE SHARES
// ------------------------------------------------------------
//
// Any page that shows "the thing with this id" has to answer four
// questions before it can draw anything, and in this order:
//
//   1. May the viewer open this page at all?   require_permission()
//   2. Is an id present, and is it a number?   get_int()
//   3. Does that row exist?                    db_one()
//   4. Is it the KIND of row this page is for? the role = 'member' test
//
// Skipping any one of them is a real bug, and question 4 is the one
// that gets forgotten. Look at the WHERE clause below: it is not just
// "WHERE id = ?". Without "AND role = 'member'", passing the id of an
// administrator would render an admin account inside the member
// screen -- complete with a point-adjustment form and a Block button
// that were never meant to point at staff. Filtering by TYPE in the
// query, rather than fetching first and checking after, means the
// wrong kind of row is never in memory to be mishandled.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

// Not is_admin(). A permission, so a role like Order Support can be
// given order screens without also being handed member records.
require_permission('members.manage');

// get_int() returns null for a missing id AND for "abc" or "7; DROP".
// The page therefore never has to consider a non-numeric id.
$id = get_int('id');

if ($id === null) {
    flash_error('Invalid member id.');
    redirect('/admin/members.php');
}

// role = 'member' is load-bearing -- see the note at the top.
$member = db_one("SELECT * FROM users WHERE id = ? AND role = 'member'", [$id]);

if (!$member) {
    // Deliberately the same message whether the id does not exist or
    // belongs to an administrator. Saying "that is an admin, not a
    // member" would confirm which ids are staff accounts to anyone
    // who can reach this page.
    flash_error('Member not found.');
    redirect('/admin/members.php');
}

// A little context: how much has this member actually ordered?
//
// Two decisions in one small query:
//
//   COALESCE(SUM(...), 0) -- SUM over no rows returns NULL, not 0. A
//   brand new member would otherwise show a blank lifetime value
//   rather than RM 0.00. COALESCE turns "no rows" into a real number.
//
//   status <> 'cancelled' -- a cancelled order was refunded, so
//   counting it would overstate both what they bought and what they
//   are worth. This is a business rule living in a WHERE clause, which
//   is exactly where it belongs.
$stats = db_one(
    "SELECT COUNT(*) AS order_count, COALESCE(SUM(total_amount), 0) AS lifetime_value
       FROM orders
      WHERE user_id = ? AND status <> 'cancelled'",
    [$id]
);

// ---------- Manual point adjustment ----------
//
// Staff granting or removing reward points by hand -- goodwill after a
// complaint, or correcting a mistake.
//
// Points are money-adjacent, so this is written as a LEDGER, not as a
// balance field. Nothing here does "UPDATE users SET points = ...".
// Instead add_point_transaction() appends a row recording the change,
// its reason, and who made it, and points_balance() sums those rows.
//
// The difference matters. With a single balance column, an adjustment
// overwrites the evidence of itself: you can see someone has 500
// points but never how they got there, and two staff adjusting at the
// same moment silently lose one of the two changes. With a ledger,
// every change is a row that can be read back months later, and
// concurrent adjustments are two appends rather than one lost update.
// It is the same reason accountants do not use pencils.
if (is_post()) {
    csrf_check();

    if (post('action') === 'adjust_points') {
        $delta  = post_int('points');   // may be negative; that is the point
        $reason = post('reason');

        // The guards run in cheapest-first order, and each one returns
        // a message aimed at what the person can actually do about it.
        if (!points_module_ready()) {
            // The migration has not been run. Degrade with an
            // explanation instead of a fatal error about a missing
            // table -- the same pattern used across this project for
            // optional features.
            flash_error('Reward points are not set up yet.');

        } elseif ($delta === null || $delta === 0) {
            // Zero is rejected as well as blank: an adjustment of
            // nothing would write an audit row that says nothing.
            flash_error('Enter a non-zero number of points.');

        } elseif ($reason === '') {
            // The whole value of the ledger is that a future reader can
            // tell WHY. An adjustment with no reason is a number nobody
            // will be able to defend later, so it is refused outright.
            flash_error('A reason is required for a manual adjustment.');

        } elseif (mb_strlen($reason) > 200) {
            // mb_strlen, not strlen. strlen counts BYTES, so a reason
            // written in Chinese would be cut off at roughly 66
            // characters -- and worse, a limit that disagrees with the
            // column would throw rather than explain.
            flash_error('The reason must not exceed 200 characters.');

        } elseif ($delta < 0 && points_balance($id) + $delta < 0) {
            // A negative balance is not a state the rest of the system
            // knows how to handle -- checkout would offer to redeem
            // points that do not exist. Checked here, at the only place
            // that can create one.
            flash_error('That would take the balance below zero. Current balance is '
                . number_format(points_balance($id)) . '.');

        } else {
            add_point_transaction(
                $id,
                'adjust',           // the ledger entry type, next to 'earn' and 'redeem'
                $delta,
                $reason,
                null,               // no order attached: this is a manual entry
                current_user_id()   // who made the adjustment, for the audit trail
            );

            flash_success('Balance adjusted by ' . ($delta > 0 ? '+' : '') . number_format($delta) . ' points.');
        }
    }

    // Post/Redirect/Get, and unconditional -- it runs whether the
    // adjustment succeeded or failed.
    //
    // The result is carried in a flash message, which survives exactly
    // one redirect, so the outcome is still shown. What is NOT carried
    // is the POST itself: after this line the browser's current page is
    // a GET, so refreshing cannot award the same points a second time.
    // On a page that hands out something of value, that is the
    // difference between a refresh and a fraud.
    redirect('/admin/member_detail.php?id=' . $id);
}

// The five most recent orders, for context beside the profile.
//
// LIMIT 5 with ORDER BY created_at DESC -- the ORDER BY is what makes
// the limit meaningful. Without it the database may return any five
// rows it finds first, which would look like a list of recent orders
// while being nothing of the kind.
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
                                    <td>
                                <?php if_can_open('/admin/order_detail.php', function () use ($o) {
                                    admin_link('/admin/order_detail.php?id=' . (int)$o['id'], 'View',
                                               ['class' => 'btn-outline btn-sm']);
                                }); ?>
                            </td>
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
