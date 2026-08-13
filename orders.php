<?php
// ============================================================
// orders.php - Order History (Member)
//
// Cancellation itself lives on /order_cancel.php so the member
// gets a proper confirmation screen and can state a reason.
// ============================================================

require_once __DIR__ . '/lib/init.php';
require_once __DIR__ . '/includes/order_parts.php';

require_member();

$title  = 'My Orders - ' . APP_NAME;
$userId = current_user_id();

// ---------- Optional status filter ----------
$statusFilter = get('status');

$sql    = 'SELECT * FROM orders WHERE user_id = ?';
$params = [$userId];

if (in_array($statusFilter, ORDER_STATUSES, true)) {
    $sql     .= ' AND status = ?';
    $params[] = $statusFilter;
}

$sql .= ' ORDER BY created_at DESC';

$orders = db_all($sql, $params);

// ---------- Load every line in one query, then group ----------
$orderItems = [];
if (count($orders) > 0) {
    $orderIds     = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

    $rows = db_all(
        "SELECT oi.order_id, oi.quantity, oi.price_at_purchase, p.name, p.image
           FROM order_items oi
           JOIN products p ON p.id = oi.product_id
          WHERE oi.order_id IN ($placeholders)",
        $orderIds
    );

    foreach ($rows as $row) {
        $orderItems[$row['order_id']][] = $row;
    }
}

include __DIR__ . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="/member/profile.php">My Profile</a> &gt; <span>My Orders</span>
</nav>

<div class="page-title-row">
    <h2 class="page-title">My Orders</h2>

    <form action="/orders.php" method="GET" class="filter-form-inline">
        <?php html_select('status', ORDER_STATUS_LABELS, $statusFilter,
                          ['class' => 'form-control form-control-sm js-auto-submit'],
                          'All orders'); ?>
        <noscript><?php html_submit('Filter', ['class' => 'btn-outline btn-sm']); ?></noscript>
    </form>
</div>

<?php if (count($orders) === 0): ?>

    <div class="card empty-state-box">
        <img src="/assets/images/default-product.png" alt="" class="empty-state-img">
        <h3 class="empty-state-title">
            <?= $statusFilter !== '' ? 'No orders with that status.' : "You haven't placed any orders yet." ?>
        </h3>
        <p>Explore our latest flagship devices and gear.</p>
        <a href="/products.php" class="btn-primary shop-now-btn">Shop Now</a>
    </div>

<?php else: ?>

    <div class="section-margin-top">
    <?php foreach ($orders as $order): ?>
        <?php $canCancel = member_can_cancel($order['status']); ?>

        <div class="card order-card">

            <div class="order-card-head">
                <div>
                    <a href="/order_detail.php?id=<?= (int)$order['id'] ?>" class="order-number">
                        Order #<?= (int)$order['id'] ?>
                    </a>
                    <span class="muted">Placed on <?= e(fmt_datetime($order['created_at'])) ?></span>
                </div>
                <span class="status-badge status-<?= e($order['status']) ?>">
                    <?= e(order_status_label($order['status'])) ?>
                </span>
            </div>

            <?php if ($order['status'] === 'cancelled'): ?>
                <div class="order-cancel-strip">
                    <i class="fas fa-ban"></i>
                    Cancelled<?= !empty($order['cancelled_at']) ? ' on ' . e(fmt_datetime($order['cancelled_at'])) : '' ?>
                    &mdash; <?= e(cancel_reason_label($order['cancel_reason'] ?? null)) ?>
                </div>
            <?php endif; ?>

            <div class="order-card-body">
                <?php foreach ($orderItems[$order['id']] ?? [] as $item): ?>
                    <div class="order-line">
                        <img src="<?= e(product_image($item['image'])) ?>" alt="<?= e($item['name']) ?>" class="order-thumb">
                        <div class="order-line-info">
                            <strong><?= e($item['name']) ?></strong>
                            <div class="muted">Qty: <?= (int)$item['quantity'] ?></div>
                        </div>
                        <div class="price"><?= e(money($item['price_at_purchase'])) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="order-card-foot">
                <div class="muted shipping-block">
                    <strong>Shipping to:</strong><br>
                    <?= nl2br(e($order['shipping_address'])) ?>
                </div>

                <?php // The buttons and the total are separate rows.
                      // They used to share one non-wrapping flex row, so on a
                      // narrow screen the total was pushed off the right edge
                      // and clipped -- the one number on the card that matters. ?>
                <div class="order-card-side">
                    <div class="order-card-amount">
                        <?php if ((float)($order['discount_amount'] ?? 0) > 0): ?>
                            <span class="badge badge-success saved-badge">
                                Saved <?= e(money($order['discount_amount'])) ?>
                            </span>
                        <?php endif; ?>

                        <span class="order-total-label">Order Total:</span>
                        <strong class="price price-lg"><?= e(money($order['total_amount'])) ?></strong>
                    </div>

                    <div class="order-card-actions">
                        <a href="/order_detail.php?id=<?= (int)$order['id'] ?>" class="btn-outline btn-sm">
                            View Detail
                        </a>

                        <a href="/receipt.php?id=<?= (int)$order['id'] ?>" class="btn-outline btn-sm">
                            Receipt
                        </a>

                        <?php if ($canCancel): ?>
                            <a href="/order_cancel.php?id=<?= (int)$order['id'] ?>"
                               class="btn-outline btn-sm btn-danger">Cancel Order</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>

    <?php endforeach; ?>
    </div>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
