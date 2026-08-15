<?php
// ============================================================
// admin/order_detail.php - Order Detail (Admin)
// ============================================================

require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/order_parts.php';

$orderId = get_int('id');

if ($orderId === null) {
    flash_error('Invalid order id.');
    redirect('/admin/orders.php');
}

$order = db_one(
    'SELECT o.*, u.id AS customer_id, u.name AS customer_name,
            u.email AS customer_email, u.profile_photo
       FROM orders o
       JOIN users u ON u.id = o.user_id
      WHERE o.id = ?',
    [$orderId]
);

if (!$order) {
    flash_error('Order not found.');
    redirect('/admin/orders.php');
}

// ---------- Status update, handled here so we stay on this page ----------
if (is_post()) {
    csrf_check();

    if (post('action') === 'update_status') {
        $newStatus = post('status');
        $note      = post('status_note');

        if ($newStatus === '' || !in_array($newStatus, ORDER_STATUSES, true)) {
            add_err('status', 'Please choose a status.');
        } elseif (mb_strlen($note) > 500) {
            add_err('status_note', 'The note must not exceed 500 characters.');
        } else {
            try {
                update_order_status($orderId, $newStatus, 'admin', $note);
                flash_success('Order #' . $orderId . ' is now ' . order_status_label($newStatus) . '.');
                redirect('/admin/order_detail.php?id=' . $orderId);

            } catch (\RuntimeException $ex) {
                add_err('status', $ex->getMessage());

            } catch (\Throwable $ex) {
                error_log('Order status update failed: ' . $ex->getMessage());
                add_err('status', 'Could not update the order. Please try again.');
            }
        }
    }

    // Re-read so the page reflects whatever actually happened.
    $order = db_one(
        'SELECT o.*, u.id AS customer_id, u.name AS customer_name,
                u.email AS customer_email, u.profile_photo
           FROM orders o JOIN users u ON u.id = o.user_id
          WHERE o.id = ?',
        [$orderId]
    );
    // Validation failed. Answer with a redirect rather than a page, so
    // the browser's history entry is a GET and F5 cannot resubmit.
    // The errors and what was typed are carried across the redirect.
    redirect_back();
}

$items       = order_lines($orderId);
$history     = order_status_history($orderId);
$nextOptions = next_status_options($order['status']);

$title = 'Order #' . $order['id'] . ' - Admin';

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container admin-container-wide">
    <div class="admin-header">
        <h2>Order Details #<?= (int)$order['id'] ?></h2>
        <a href="/admin/orders.php" class="btn-outline">&larr; Back to Orders</a>
    </div>

    <?php err_summary(); ?>
    <?php render_cancellation_panel($order, 'admin'); ?>

    <div class="profile-layout mt-4">

        <aside class="profile-sidebar">
            <div class="card card-padded">
                <h3 class="side-heading">Customer</h3>
                <p>
                    <strong><?= e($order['customer_name']) ?></strong><br>
                    <a href="mailto:<?= e($order['customer_email']) ?>"><?= e($order['customer_email']) ?></a><br>
                    <a href="/admin/member_detail.php?id=<?= (int)$order['customer_id'] ?>">View member profile</a>
                </p>

                <h3 class="side-heading">Placed On</h3>
                <p class="muted"><?= e(fmt_datetime($order['created_at'])) ?></p>

                <h3 class="side-heading">Shipping Address</h3>
                <p class="muted"><?= nl2br(e($order['shipping_address'])) ?></p>

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
                              class="mt-2" data-confirm="Email this receipt to the customer?">
                            <?php csrf_field(); ?>
                            <?php html_submit('Email To Customer', ['class' => 'btn-outline btn-block']); ?>
                        </form>
                    <?php endif; ?>

                    <p class="muted small-note mt-2">
                        <?php if (!empty($order['receipt_sent_at'])): ?>
                            Sent <?= (int)($order['receipt_sent_count'] ?? 0) ?> time(s),
                            last on <?= e(fmt_datetime($order['receipt_sent_at'])) ?>.
                        <?php else: ?>
                            Not sent yet.
                        <?php endif; ?>
                    </p>
                </div>

                <h3 class="side-heading">Order Status</h3>

                <p>
                    <span class="status-badge status-<?= e($order['status']) ?>">
                        <?= e(order_status_label($order['status'])) ?>
                    </span>
                </p>

                <?php if (count($nextOptions) === 0): ?>
                    <p class="muted small-note">
                        This order is <?= e(order_status_label($order['status'])) ?> and has reached
                        the end of the workflow. Its status can no longer be changed.
                    </p>
                <?php else: ?>
                    <form action="/admin/order_detail.php?id=<?= (int)$order['id'] ?>"
                          method="POST" class="form-standard">
                        <?php csrf_field(); ?>
                        <?php html_hidden('action', 'update_status'); ?>
                        <?php html_hidden('order_id', $order['id']); ?>

                        <?php field('status', 'Move this order to', function () use ($nextOptions) {
                            html_select('status', $nextOptions, '', ['required' => true],
                                        '-- Select new status --');
                        }, true); ?>

                        <?php field('status_note', 'Internal note (optional)', function () {
                            html_textarea('status_note', '', [
                                'rows'        => 3,
                                'maxlength'   => 500,
                                'placeholder' => 'e.g. courier tracking number, or why it was cancelled',
                            ]);
                        }); ?>

                        <?php html_submit('Update Status', ['class' => 'btn-primary btn-block']); ?>
                    </form>

                    <p class="muted small-note mt-2">
                        Allowed next steps:
                        <?= e(status_options_label(allowed_next_statuses($order['status']))) ?>.
                    </p>
                <?php endif; ?>
            </div>
        </aside>

        <main class="profile-content">
            <div class="card card-padded">
                <h3>Purchased Items</h3>

                <?php render_order_lines($items, (float)$order['total_amount'], $order); ?>
            </div>

            <div class="card card-padded mt-4">
                <h3>Status History</h3>
                <p class="muted small-note">Every status change, who made it and when.</p>
                <?php render_status_timeline($history, 'admin'); ?>
            </div>
        </main>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
