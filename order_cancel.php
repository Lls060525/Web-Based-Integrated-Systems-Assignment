<?php
// ============================================================
// order_cancel.php - Order Cancellation (Member)
//
// A dedicated confirmation page rather than a bare JavaScript
// confirm(), so the member sees exactly what they are cancelling
// and every rule is enforced on the server.
//
// Rules:
//   - the order must belong to the logged-in member
//   - the status must be one of MEMBER_CANCELLABLE_STATUSES
//   - a reason must be chosen; "Other" also requires a note
//   - stock is restored inside the same transaction
// ============================================================

require_once __DIR__ . '/lib/init.php';
require_once __DIR__ . '/includes/order_parts.php';

require_member();

$userId  = current_user_id();
$orderId = is_post() ? post_int('order_id') : get_int('id');

if ($orderId === null) {
    flash_error('Invalid order number.');
    redirect('/orders.php');
}

$order = find_member_order($orderId, $userId);

if (!$order) {
    flash_error('Order not found.');
    redirect('/orders.php');
}

// Re-checked on GET and on POST, so a stale tab cannot slip through.
if (!member_can_cancel($order['status'])) {
    flash_error(cancel_blocked_reason($order['status']));
    redirect('/order_detail.php?id=' . $orderId);
}

$lines = order_lines($orderId);

if (is_post()) {
    csrf_check();

    $reason = post('cancel_reason');
    $note   = post('cancel_note');

    // ---------- Server-side validation ----------
    if ($reason === '') {
        add_err('cancel_reason', 'Please tell us why you are cancelling.');
    } elseif (!array_key_exists($reason, CANCEL_REASONS)) {
        add_err('cancel_reason', 'Please choose a reason from the list.');
    } elseif ($reason === CANCEL_REASON_REQUIRING_NOTE && $note === '') {
        add_err('cancel_note', 'Please describe your reason.');
    }

    v_max('cancel_note', $note, 500, 'Note');

    if (post('confirm') !== 'yes') {
        add_err('confirm', 'Please tick the confirmation box to continue.');
    }

    if (no_err()) {
        try {
            cancel_order($orderId, 'member', $reason, $note);

            flash_success(
                'Order #' . $orderId . ' has been cancelled. '
                . 'All items have been returned to stock.'
            );
            redirect('/order_detail.php?id=' . $orderId);

        } catch (\Throwable $e) {
            error_log('Member order cancellation failed: ' . $e->getMessage());
            add_err('cancel_reason', 'We could not cancel the order just now. Please try again.');
        }
    }
    // Validation failed. Answer with a redirect rather than a page, so
    // the browser's history entry is a GET and F5 cannot resubmit.
    // The errors and what was typed are carried across the redirect.
    redirect_back();
}

$title = 'Cancel Order #' . $order['id'] . ' - ' . APP_NAME;

include __DIR__ . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="/orders.php">My Orders</a> &gt;
    <a href="/order_detail.php?id=<?= (int)$order['id'] ?>">Order #<?= (int)$order['id'] ?></a> &gt;
    <span>Cancel</span>
</nav>

<div class="cancel-page">

    <div class="page-title-row">
        <h2 class="page-title">Cancel Order #<?= (int)$order['id'] ?></h2>
    </div>

    <div class="alert alert-warning">
        <strong>This cannot be undone.</strong>
        Cancelling returns every item to stock and closes the order.
        If you have already been charged, our support team will handle the refund.
    </div>

    <?php err_summary(); ?>

    <div class="cancel-layout">

        <div class="card card-padded">
            <h3>You are cancelling</h3>

            <dl class="detail-list">
                <dt>Placed on</dt>
                <dd><?= e(fmt_datetime($order['created_at'])) ?></dd>

                <dt>Current status</dt>
                <dd>
                    <span class="status-badge status-<?= e($order['status']) ?>">
                        <?= e(order_status_label($order['status'])) ?>
                    </span>
                </dd>
            </dl>

            <?php render_order_lines($lines, (float)$order['total_amount'], $order); ?>
        </div>

        <div class="card card-padded">
            <h3>Tell us why</h3>

            <form action="/order_cancel.php" method="POST" class="form-standard">
                <?php csrf_field(); ?>
                <?php html_hidden('order_id', $order['id']); ?>

                <?php field('cancel_reason', 'Reason for cancelling', function () {
                    html_select('cancel_reason', CANCEL_REASONS, '',
                                ['required' => true, 'id' => 'cancelReason'],
                                '-- Please select --');
                }, true); ?>

                <?php field('cancel_note', 'Anything else you would like to add?', function () {
                    html_textarea('cancel_note', '', [
                        'rows'      => 4,
                        'maxlength' => 500,
                        'id'        => 'cancelNote',
                        'placeholder' => 'Optional, unless you selected "Other reason".',
                    ]);
                }); ?>

                <div class="form-group form-check">
                    <label for="confirm" class="check-label">
                        <input type="checkbox" name="confirm" id="confirm" value="yes"
                               <?= post('confirm') === 'yes' ? 'checked' : '' ?>>
                        <span>I understand this order will be cancelled permanently.</span>
                    </label>
                    <?php err('confirm'); ?>
                </div>

                <div class="form-actions">
                    <a href="/order_detail.php?id=<?= (int)$order['id'] ?>" class="btn-outline">
                        Keep My Order
                    </a>
                    <?php html_submit('Cancel This Order', ['class' => 'btn-primary btn-danger-solid',
                                      'data-busy' => 'Cancelling...']); ?>
                </div>
            </form>
        </div>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
