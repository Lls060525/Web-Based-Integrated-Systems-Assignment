<?php
// ============================================================
// order_detail.php - Order Detail (Member)
//
// Members can only ever reach their own orders: ownership is part
// of the WHERE clause, not an if-statement after the fact.
// ============================================================

require_once __DIR__ . '/lib/init.php';
require_once __DIR__ . '/includes/order_parts.php';

require_member();

$userId  = current_user_id();
$orderId = get_int('id');

if ($orderId === null) {
    flash_error('Invalid order number.');
    redirect('/orders.php');
}

$order = find_member_order($orderId, $userId);

if (!$order) {
    flash_error('Order not found.');
    redirect('/orders.php');
}

$lines      = order_lines($orderId);
$history    = order_status_history($orderId);
$canCancel  = member_can_cancel($order['status']);
$title      = 'Order #' . $order['id'] . ' - ' . APP_NAME;

include __DIR__ . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="/member/home.php">Home</a> &gt;
    <a href="/orders.php">My Orders</a> &gt;
    <span>Order #<?= (int)$order['id'] ?></span>
</nav>

<div class="page-title-row">
    <h2 class="page-title">Order #<?= (int)$order['id'] ?></h2>
    <span class="status-badge status-<?= e($order['status']) ?>">
        <?= e(order_status_label($order['status'])) ?>
    </span>
</div>

<?php render_cancellation_panel($order, 'member'); ?>

<div class="order-detail-layout">

    <aside class="order-detail-side">
        <div class="card card-padded">
            <h3 class="side-heading">Order Information</h3>
            <dl class="detail-list">
                <dt>Order number</dt>
                <dd>#<?= (int)$order['id'] ?></dd>

                <dt>Placed on</dt>
                <dd><?= e(fmt_datetime($order['created_at'])) ?></dd>

                <dt>Status</dt>
                <dd>
                    <span class="status-badge status-<?= e($order['status']) ?>">
                        <?= e(order_status_label($order['status'])) ?>
                    </span>
                </dd>

                <dt>Total paid</dt>
                <dd class="price"><?= e(money($order['total_amount'])) ?></dd>
            </dl>

            <h3 class="side-heading">Shipping Address</h3>
            <p class="muted"><?= nl2br(e($order['shipping_address'])) ?></p>
            <p class="muted small-note">
                This is the address as it was when you ordered. Editing it in
                <a href="/member/addresses.php">My Addresses</a> will not change this order.
            </p>

            <?php // The reference is plain arithmetic, so it is always available.
                  // Only the QR image itself depends on the library being installed. ?>
            <h3 class="side-heading">Collection Code</h3>

            <div class="qr-block">
                <?php if (qr_module_ready()): ?>
                    <img class="qr-image"
                         src="/api/qr_image.php?type=order&amp;id=<?= (int)$order['id'] ?>&amp;size=200"
                         alt="QR code for order <?= (int)$order['id'] ?>"
                         width="160" height="160">
                <?php endif; ?>

                <p class="qr-code-text"><?= e(order_short_code((int)$order['id'])) ?></p>

                <p class="muted small-note">
                    <?php if (qr_module_ready()): ?>
                        Show this at the counter, or scan it yourself to confirm the
                        receipt is genuine. The reference underneath works if the code
                        will not scan.
                    <?php else: ?>
                        Show this reference at the counter, or enter it at
                        <a href="/verify.php">/verify.php</a> to confirm the receipt is genuine.
                    <?php endif; ?>
                </p>
            </div>

            <h3 class="side-heading">Receipt</h3>
            <div class="receipt-actions">
                <a href="/receipt.php?id=<?= (int)$order['id'] ?>" class="btn-outline btn-block">
                    View Receipt
                </a>

                <?php if (pdf_engine_available()): ?>
                    <a href="/receipt.php?id=<?= (int)$order['id'] ?>&amp;mode=pdf"
                       class="btn-outline btn-block mt-2">Download PDF</a>
                <?php endif; ?>

                <?php if (can_resend_receipt($order)): ?>
                    <form action="/receipt.php?id=<?= (int)$order['id'] ?>&amp;mode=send" method="POST"
                          class="mt-2" data-confirm="Email this receipt to yourself?">
                        <?php csrf_field(); ?>
                        <?php form_nonce('receipt_send_' . (int)$order['id']); ?>
                        <?php html_submit('Email It To Me', [
                            'class'     => 'btn-outline btn-block',
                            'data-busy' => 'Sending...',
                        ]); ?>
                    </form>
                <?php endif; ?>

                <?php if (!empty($order['receipt_sent_at'])): ?>
                    <p class="muted small-note mt-2">
                        Last sent <?= e(fmt_datetime($order['receipt_sent_at'])) ?>.
                    </p>
                <?php endif; ?>
            </div>

            <?php if ($order['status'] !== 'cancelled'): ?>
                <h3 class="side-heading">Need to cancel?</h3>

                <?php if ($canCancel): ?>
                    <p class="muted small-note">
                        You can still cancel this order because it has not been shipped yet.
                    </p>
                    <a href="/order_cancel.php?id=<?= (int)$order['id'] ?>"
                       class="btn-outline btn-danger btn-block">Cancel This Order</a>
                <?php else: ?>
                    <p class="muted small-note"><?= e(cancel_blocked_reason($order['status'])) ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </aside>

    <main class="order-detail-main">
        <div class="card card-padded">
            <h3>Items in This Order</h3>
            <?php render_order_lines($lines, (float)$order['total_amount'], $order); ?>

            <?php if (review_module_ready() && in_array($order['status'], REVIEW_ELIGIBLE_ORDER_STATUSES, true)): ?>
                <div class="review-prompts">
                    <h4>Share your experience</h4>
                    <?php foreach ($lines as $line): ?>
                        <?php $written = find_user_review($userId, (int)$line['product_id']); ?>
                        <a href="/review_form.php?product=<?= (int)$line['product_id'] ?>"
                           class="review-prompt">
                            <img src="<?= e(product_image($line['image'])) ?>" alt="" class="table-thumb">
                            <span class="review-prompt-name"><?= e($line['product_name']) ?></span>
                            <span class="btn-outline btn-sm">
                                <?= $written ? 'Edit review' : 'Write a review' ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card card-padded mt-4">
            <h3>Order Progress</h3>
            <?php render_status_timeline($history, 'member'); ?>
        </div>

        <div class="form-actions mt-4">
            <a href="/orders.php" class="btn-outline">&larr; Back to My Orders</a>
            <a href="/products.php" class="btn-primary">Continue Shopping</a>
        </div>
    </main>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
