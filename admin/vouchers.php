<?php
// ============================================================
// admin/vouchers.php - Discount Voucher maintenance (Admin)
// ============================================================

require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/admin_rows.php';

$title = 'Voucher Management - Admin';

if (!voucher_module_ready()) {
    flash_error('Vouchers are not available yet: run database/migration_10_voucher.sql.');
    redirect('/admin/dashboard.php');
}

// ---------- Actions ----------
if (is_post()) {
    csrf_check();

    $action = post('action');
    $id     = post_int('id');

    if ($id === null) {
        flash_error('Invalid request.');

    } else {
        $voucher = db_one('SELECT id, code, used_count FROM vouchers WHERE id = ?', [$id]);

        if (!$voucher) {
            flash_error('Voucher not found.');

        } elseif ($action === 'toggle_status') {
            $status = post('status');

            if (!in_array($status, ['active', 'inactive'], true)) {
                flash_error('Invalid status.');
            } else {
                db_exec('UPDATE vouchers SET status = ?, updated_at = NOW() WHERE id = ?', [$status, $id]);
                flash_success('Voucher ' . $voucher['code'] . ' has been '
                    . ($status === 'active' ? 'enabled' : 'disabled') . '.');
            }

        } elseif ($action === 'delete') {
            // A redeemed voucher is part of order history and must not vanish.
            if ((int)$voucher['used_count'] > 0) {
                flash_error('This voucher has been redeemed and cannot be deleted. Disable it instead.');
            } else {
                db_exec('DELETE FROM vouchers WHERE id = ?', [$id]);
                flash_success('Voucher ' . $voucher['code'] . ' deleted.');
            }

        } else {
            flash_error('Invalid request.');
        }
    }

    redirect('/admin/vouchers.php');
}

// ---------- Listing ----------
$q = get('q');

$sql    = 'SELECT * FROM vouchers';
$params = [];

if ($q !== '') {
    $sql     .= ' WHERE (code LIKE ? OR description LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$sql .= ' ORDER BY id DESC';

$vouchers = db_all($sql, $params);

if (is_ajax()) {
    admin_voucher_rows($vouchers);
    exit;
}

// Headline numbers for the summary strip.
$stats = db_one(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'active'
                  AND (starts_at  IS NULL OR starts_at  <= NOW())
                  AND (expires_at IS NULL OR expires_at >= NOW())
                  AND (usage_limit IS NULL OR used_count < usage_limit)
             THEN 1 ELSE 0 END) AS live,
        COALESCE(SUM(used_count), 0) AS redemptions
     FROM vouchers"
);

$saved = (float)db_value('SELECT COALESCE(SUM(discount_amount), 0) FROM voucher_redemptions');

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Voucher Management</h2>

        <div class="admin-header-actions">
            <form action="/admin/vouchers.php" method="GET" class="admin-search-form" data-target="#voucherTableBody">
                <input type="text" name="q" value="<?= e($q) ?>"
                       placeholder="Search code or description..." class="admin-search-input">
                <?php html_submit('Search'); ?>
                <?php if ($q !== ''): ?>
                    <a href="/admin/vouchers.php" class="btn-outline">Clear</a>
                <?php endif; ?>
            </form>

            <a href="/admin/voucher_form.php" class="btn-primary">+ Add New Voucher</a>
        </div>
    </div>

    <div class="stat-grid mt-4">
        <div class="card stat-tile">
            <span class="stat-icon"><i class="fas fa-ticket"></i></span>
            <span class="stat-value"><?= (int)$stats['total'] ?></span>
            <span class="stat-label">Vouchers Created</span>
        </div>
        <div class="card stat-tile">
            <span class="stat-icon"><i class="fas fa-circle-check"></i></span>
            <span class="stat-value"><?= (int)$stats['live'] ?></span>
            <span class="stat-label">Currently Redeemable</span>
        </div>
        <div class="card stat-tile">
            <span class="stat-icon"><i class="fas fa-receipt"></i></span>
            <span class="stat-value"><?= (int)$stats['redemptions'] ?></span>
            <span class="stat-label">Times Redeemed</span>
        </div>
        <div class="card stat-tile">
            <span class="stat-icon"><i class="fas fa-hand-holding-dollar"></i></span>
            <span class="stat-value"><?= e(money($saved)) ?></span>
            <span class="stat-label">Total Discount Given</span>
        </div>
    </div>

    <div class="card mt-4">
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Discount</th>
                        <th>Min Spend</th>
                        <th>Used</th>
                        <th>Per User</th>
                        <th>Expires</th>
                        <th>State</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="voucherTableBody">
                    <?php admin_voucher_rows($vouchers); ?>
                </tbody>
            </table>
        </div>
    </div>

    <p class="muted mt-2 small-note">
        A voucher that has already been redeemed cannot be deleted, because the
        redemption is part of a customer's order history. Disable it instead.
    </p>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
