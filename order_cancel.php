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
//
// cancel_request_blocker() rather than member_can_cancel(): it answers
// the newer question -- may a REQUEST be raised -- which also covers
// "you already asked and it is still waiting".
$blocker = cancel_request_ready()
    ? cancel_request_blocker($order)
    : (member_can_cancel($order['status']) ? null : cancel_blocked_reason($order['status']));

if ($blocker !== null) {
    flash_error($blocker);
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
        // Asking, not cancelling. The order carries on exactly as it was
        // -- still holding its stock -- until an administrator decides.
        // Returning the stock now would let anyone free up inventory
        // simply by asking, and it would have to be taken away again if
        // the request were refused.
        $result = request_cancellation($order, 'member', $reason, $note);

        if ($result['ok']) {
            flash_success($result['message']);
            redirect('/order_detail.php?id=' . $orderId);
        }

        add_err('cancel_reason', $result['message']);
    }
    // Validation failed. Answer with a redirect rather than a page, so
    // the browser's history entry is a GET and F5 cannot resubmit.
    // The errors and what was typed are carried across the redirect.
    redirect_back();
}

$title = 'Request cancellation - Order #' . $order['id'] . ' - ' . APP_NAME;

include __DIR__ . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="/orders.php">My Orders</a> &gt;
    <a href="/order_detail.php?id=<?= (int)$order['id'] ?>">Order #<?= (int)$order['id'] ?></a> &gt;
    <span>Cancel</span>
</nav>

<div class="cancel-page">

    <div class="page-title-row">
        <h2 class="page-title">Request Cancellation &mdash; Order #<?= (int)$order['id'] ?></h2>
    </div>

    <?php /* The wording had to change with the behaviour. It used to say
             "this cannot be undone", which was true when the button
             cancelled the order outright and is now simply wrong -- the
             order carries on until somebody approves. Telling a customer
             their order is cancelled when it is not is worse than any
             layout problem. */ ?>
    <div class="alert alert-warning">
        <strong>This is a request, not an immediate cancellation.</strong>
        Your order stays exactly as it is until a member of our team reviews it.
        You will see the outcome on this order's page. If you have already been
        charged and the request is approved, our support team handles the refund.
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
                        <span>I understand this is a request, and that the order
                              continues until it is approved.</span>
                    </label>
                    <?php err('confirm'); ?>
                </div>

                <div class="form-actions">
                    <a href="/order_detail.php?id=<?= (int)$order['id'] ?>" class="btn-outline">
                        Keep My Order
                    </a>
                    <?php html_submit('Request Cancellation', ['class' => 'btn-primary btn-danger-solid',
                                      'data-busy' => 'Sending...']); ?>
                </div>
            </form>
        </div>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
