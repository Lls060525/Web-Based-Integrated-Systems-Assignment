<?php
// ============================================================
// member/points.php - Reward Points (Member)
// Balance plus the full ledger, so nothing is unexplained.
// ============================================================

require_once __DIR__ . '/../lib/init.php';

require_member();

$title  = 'My Reward Points - ' . APP_NAME;
$userId = current_user_id();

if (!points_module_ready()) {
    flash_error('Reward points are not available yet: run database/migration_12_points.sql.');
    redirect('/member/profile.php');
}

$summary = points_summary($userId);
$history = points_history($userId, 100);

include __DIR__ . '/../includes/header.php';
?>

<nav class="breadcrumb">
    <a href="/member/profile.php">My Profile</a> &gt; <span>Reward Points</span>
</nav>

<div class="page-title-row">
    <h2 class="page-title">Reward Points</h2>
</div>

<div class="points-hero card">
    <div class="points-hero-main">
        <span class="points-hero-label">Available balance</span>
        <span class="points-hero-value">
            <i class="fas fa-star"></i>
            <?= number_format($summary['balance']) ?>
        </span>
        <span class="points-hero-worth">
            worth <?= e(money(points_to_money($summary['balance']))) ?> at checkout
        </span>
    </div>

    <div class="points-hero-side">
        <div class="stat-row">
            <span class="muted">Lifetime earned</span>
            <strong><?= number_format($summary['earned']) ?></strong>
        </div>
        <div class="stat-row">
            <span class="muted">Lifetime redeemed</span>
            <strong><?= number_format($summary['redeemed']) ?></strong>
        </div>
    </div>
</div>

<div class="card card-padded mt-4">
    <h3>How it works</h3>
    <ul class="rules-list">
        <li>Earn <strong><?= POINTS_EARNED_PER_RM ?> point</strong> for every RM 1 you actually pay.</li>
        <li><strong><?= number_format(POINTS_PER_RM_REDEEMED) ?> points</strong> are worth RM 1.00 off your next order.</li>
        <li>Redeem in blocks of <?= number_format(POINTS_REDEEM_STEP) ?>, minimum <?= number_format(POINTS_MIN_REDEEM) ?>.</li>
        <li>Points can pay for up to <strong><?= POINTS_MAX_REDEEM_PERCENT ?>%</strong> of an order.</li>
        <li>Cancel an order and any points it earned are removed, while points you spent come back.</li>
    </ul>
</div>

<div class="card mt-4">
    <div class="card-header card-padded">
        <h3>Points History</h3>
        <p class="muted small-note">
            Every point is accounted for. Your balance is the sum of this list.
        </p>
    </div>

    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Activity</th>
                    <th>Type</th>
                    <th class="text-right">Points</th>
                    <th class="text-right">Balance</th>
                </tr>
            </thead>
            <tbody>
            <?php if (count($history) === 0): ?>
                <tr><td colspan="5" class="table-empty">No point activity yet.</td></tr>
            <?php else: ?>
                <?php foreach ($history as $t): ?>
                    <?php $positive = (int)$t['points'] > 0; ?>
                    <tr>
                        <td><?= e(fmt_datetime($t['created_at'])) ?></td>
                        <td>
                            <?= e($t['description']) ?>
                            <?php if (!empty($t['order_id'])): ?>
                                <a href="/order_detail.php?id=<?= (int)$t['order_id'] ?>" class="small-note">View order</a>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
