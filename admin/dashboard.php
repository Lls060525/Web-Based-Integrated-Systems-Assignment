<?php
// ============================================================
// admin/dashboard.php - Admin landing page
//
// Every number here is read from the database. The bar chart is
// hand-coded with CSS rather than pulled from a chart library.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

$title = 'Dashboard - Admin';

// ---------- Headline figures ----------
$stats = [
    'members'  => (int)db_value("SELECT COUNT(*) FROM users WHERE role = 'member' AND status <> 'deleted'"),
    'admins'   => active_admin_count(),
    'products' => (int)db_value("SELECT COUNT(*) FROM products WHERE status = 'active'"),
    'orders'   => (int)db_value("SELECT COUNT(*) FROM orders WHERE status <> 'cancelled'"),
    'revenue'  => (float)db_value("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status <> 'cancelled'"),
];

// ---------- Orders grouped by status ----------
$byStatus = [];
foreach (ORDER_STATUSES as $status) {
    $byStatus[$status] = 0;
}
foreach (db_all('SELECT status, COUNT(*) AS total FROM orders GROUP BY status') as $row) {
    if (array_key_exists($row['status'], $byStatus)) {
        $byStatus[$row['status']] = (int)$row['total'];
    }
}
$maxStatus = max(1, max($byStatus));

// ---------- Revenue for the last 6 months ----------
$monthly = db_all(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym,
            DATE_FORMAT(created_at, '%b %Y')  AS label,
            SUM(total_amount)                 AS revenue
       FROM orders
      WHERE status <> 'cancelled'
        AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
      GROUP BY ym, label
      ORDER BY ym ASC"
);
$maxRevenue = 0.0;
foreach ($monthly as $m) {
    $maxRevenue = max($maxRevenue, (float)$m['revenue']);
}
$maxRevenue = max($maxRevenue, 1.0);

// ---------- Top 5 selling products ----------
$topProducts = db_all(
    "SELECT p.id, p.name, p.image, SUM(oi.quantity) AS sold,
            SUM(oi.quantity * oi.price_at_purchase) AS revenue
       FROM order_items oi
       JOIN orders o   ON o.id = oi.order_id
       JOIN products p ON p.id = oi.product_id
      WHERE o.status <> 'cancelled'
      GROUP BY p.id
      ORDER BY sold DESC
      LIMIT 5"
);

// ---------- Low stock alert ----------
// Uses each product's own reorder_level, not a hardcoded number.
$lowStock = low_stock_products(8);

// ---------- Latest orders ----------
$recentOrders = db_all(
    'SELECT o.id, o.total_amount, o.status, o.created_at, u.name AS customer_name
       FROM orders o JOIN users u ON u.id = o.user_id
      ORDER BY o.created_at DESC LIMIT 6'
);

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Dashboard</h2>
        <span class="muted"><?= e(fmt_date(date('Y-m-d'), 'l, j F Y')) ?></span>
    </div>

    <!-- Headline figures -->
    <div class="stat-grid">
        <a href="/admin/members.php" class="card stat-tile">
            <span class="stat-icon"><i class="fas fa-users"></i></span>
            <span class="stat-value"><?= $stats['members'] ?></span>
            <span class="stat-label">Registered Members</span>
        </a>
        <a href="/admin/admins.php" class="card stat-tile">
            <span class="stat-icon"><i class="fas fa-user-shield"></i></span>
            <span class="stat-value"><?= $stats['admins'] ?></span>
            <span class="stat-label">Active Administrators</span>
        </a>
        <a href="/admin/stock.php" class="card stat-tile">
            <span class="stat-icon"><i class="fas fa-box"></i></span>
            <span class="stat-value"><?= $stats['products'] ?></span>
            <span class="stat-label">Active Products</span>
        </a>
        <a href="/admin/orders.php" class="card stat-tile">
            <span class="stat-icon"><i class="fas fa-shopping-cart"></i></span>
            <span class="stat-value"><?= $stats['orders'] ?></span>
            <span class="stat-label">Orders Placed</span>
        </a>
        <div class="card stat-tile">
            <span class="stat-icon"><i class="fas fa-coins"></i></span>
            <span class="stat-value"><?= e(money($stats['revenue'])) ?></span>
            <span class="stat-label">Total Revenue</span>
        </div>
    </div>

    <div class="dashboard-grid mt-4">

        <!-- Revenue chart (hand-coded CSS bars) -->
        <div class="card card-padded">
            <h3>Revenue, Last 6 Months</h3>

            <?php if (count($monthly) === 0): ?>
                <p class="muted">No orders recorded yet.</p>
            <?php else: ?>
                <div class="bar-chart">
                    <?php foreach ($monthly as $m): ?>
                        <?php $height = round(((float)$m['revenue'] / $maxRevenue) * 100); ?>
                        <div class="bar-col">
                            <span class="bar-value"><?= e(money($m['revenue'])) ?></span>
                            <div class="bar" style="height: <?= $height ?>%"></div>
                            <span class="bar-label"><?= e($m['label']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Orders by status -->
        <div class="card card-padded">
            <h3>Orders by Status</h3>
            <ul class="hbar-list">
                <?php foreach ($byStatus as $status => $count): ?>
                    <li>
                        <span class="hbar-label"><?= e($status) ?></span>
                        <span class="hbar-track">
                            <span class="hbar-fill status-<?= e(strtolower($status)) ?>"
                                  style="width: <?= round(($count / $maxStatus) * 100) ?>%"></span>
                        </span>
                        <span class="hbar-count"><?= $count ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Top sellers -->
        <div class="card card-padded">
            <h3>Top 5 Selling Products</h3>
            <table class="admin-table">
                <thead><tr><th></th><th>Product</th><th>Sold</th><th>Revenue</th></tr></thead>
                <tbody>
                <?php if (count($topProducts) === 0): ?>
                    <tr><td colspan="4" class="table-empty">No sales yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($topProducts as $p): ?>
                        <tr>
                            <td class="cell-thumb"><img src="<?= e(product_image($p['image'])) ?>" alt="" class="table-thumb"></td>
                            <td><a href="/admin/product_form.php?id=<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a></td>
                            <td><strong><?= (int)$p['sold'] ?></strong></td>
                            <td><?= e(money($p['revenue'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Low stock alert -->
        <div class="card card-padded">
            <h3>
                Low Stock Alert
                <span class="muted">(at or below each product's reorder level)</span>
            </h3>
            <?php if (count($lowStock) === 0): ?>
                <p class="muted">Every active product is comfortably in stock.</p>
            <?php else: ?>
                <ul class="alert-list">
                    <?php foreach ($lowStock as $p): ?>
                        <li>
                            <a href="/admin/product_form.php?id=<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a>
                            <span class="badge <?= (int)$p['stock'] === 0 ? 'badge-danger' : 'badge-warning' ?>">
                                <?= (int)$p['stock'] ?> left
                                <?php if (reorder_level_ready()): ?>
                                    / reorder at <?= reorder_level($p) ?>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

    </div>

    <!-- Latest orders -->
    <div class="card mt-4">
        <div class="card-header card-padded">
            <h3>Latest Orders</h3>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr><th>Order</th><th>Customer</th><th>Date</th><th>Total</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php if (count($recentOrders) === 0): ?>
                    <tr><td colspan="6" class="table-empty">No orders yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($recentOrders as $o): ?>
                        <tr>
                            <td><strong>#<?= (int)$o['id'] ?></strong></td>
                            <td><?= e($o['customer_name']) ?></td>
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

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
