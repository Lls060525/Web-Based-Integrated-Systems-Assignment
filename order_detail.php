<?php
// ============================================================
// order_detail.php - Order Detail (Member)
//
// Members can only ever reach their own orders: ownership is part
// of the WHERE clause, not an if-statement after the fact.
//
// ------------------------------------------------------------
// THE MOST IMPORTANT LINE ON THIS PAGE
// ------------------------------------------------------------
//
//     $order = find_member_order($orderId, $userId);
//
// Two arguments, and the second one is the security of the whole page.
// Look at the function in lib/orders.php:
//
//     SELECT * FROM orders WHERE id = ? AND user_id = ?
//
// Ownership is in the QUERY. An order belonging to somebody else is
// not fetched at all, so it cannot be leaked by a later branch that
// forgot to check.
//
// The tempting alternative is worse in a way that is easy to miss:
//
//     $order = find_order($orderId);            // fetch anything
//     if ($order['user_id'] !== $userId) { … }  // check afterwards
//
// That version works, right up until somebody adds a feature above the
// check -- a page title, a "recently viewed" record, a log line -- and
// now another customer's data has been used before anyone asked whose
// it was. This is the class of bug called Insecure Direct Object
// Reference, and it is found by typing a different number in the URL.
// Putting ownership in the WHERE clause makes that whole class of
// mistake impossible rather than merely absent today.
//
// The same shape appears in delivery_photo.php and receipt.php: the
// thing being protected is never loaded unless it belongs to you.
// ============================================================

require_once __DIR__ . '/lib/init.php';

// Shared render helpers for the timeline and the cancellation panel,
// used by this page and by admin/order_detail.php so the two views of
// one order cannot drift apart.
require_once __DIR__ . '/includes/order_parts.php';

// Members only. An administrator visiting this URL is redirected --
// they have admin/order_detail.php, which shows the staff view.
require_member();

$userId  = current_user_id();
$orderId = get_int('id');

if ($orderId === null) {
    flash_error('Invalid order number.');
    redirect('/orders.php');
}

// Ownership is enforced inside this call -- see the note above.
$order = find_member_order($orderId, $userId);

if (!$order) {
    // "Not found" covers both "no such order" and "not yours", on
    // purpose. Distinguishing them would turn this page into a way to
    // discover which order numbers exist.
    flash_error('Order not found.');
    redirect('/orders.php');
}

// Everything below is safe to load unconditionally: reaching this line
// already proves the order belongs to the signed-in member.
$lines      = order_lines($orderId);          // the purchased items
$history    = order_status_history($orderId); // the timeline entries

// Whether to offer the Cancel button. The real rule lives in
// lib/orders.php; asking it here rather than testing the status
// inline means the button and the server-side refusal can never
// disagree about what is cancellable.
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

                <?php $paidWith = payment_method_label($order); ?>
                <?php if ($paidWith !== null): ?>
                    <dt>Paid with</dt>
                    <dd>
                        <i class="fas <?= e(payment_method_icon((string)$order['payment_method'])) ?>"
                           aria-hidden="true"></i>
                        <?= e($paidWith) ?>
                    </dd>
                <?php endif; ?>
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
                <?php $openRequest = open_cancel_request((int)$order['id']); ?>
                <?php $lastRequest = cancel_request_history((int)$order['id'])[0] ?? null; ?>

                <h3 class="side-heading">Need to cancel?</h3>

                <?php if ($openRequest !== null): ?>
                    <?php /* The most important state to show clearly. Somebody
                             who has asked and hears nothing will ask again, or
                             assume it is done and be surprised by a parcel. */ ?>
                    <div class="alert alert-warning">
                        <strong>Cancellation requested.</strong>
                        Sent <?= e(fmt_datetime($openRequest['created_at'])) ?>.
                        We are reviewing it &mdash; your order continues as normal
                        until a decision is made.
                    </div>

                <?php elseif ($lastRequest !== null && $lastRequest['status'] === 'rejected'): ?>
                    <div class="alert alert-error">
                        <strong>Your cancellation request was not approved.</strong>
                        <?php if (!empty($lastRequest['decision_note'])): ?>
                            <div class="mt-2"><?= nl2br(e($lastRequest['decision_note'])) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($canCancel): ?>
                        <a href="/order_cancel.php?id=<?= (int)$order['id'] ?>"
                           class="btn-outline btn-danger btn-block">Ask Again</a>
                    <?php endif; ?>

                <?php elseif ($canCancel): ?>
                    <p class="muted small-note">
                        This order has not shipped yet, so you can ask us to cancel it.
                        A member of our team reviews every request.
                    </p>
                    <a href="/order_cancel.php?id=<?= (int)$order['id'] ?>"
                       class="btn-outline btn-danger btn-block">Request Cancellation</a>

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
