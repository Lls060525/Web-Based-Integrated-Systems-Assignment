<?php
// ============================================================
// admin/cancellations.php - decide cancellation requests
//
// The queue is oldest first. A cancellation left waiting is a customer
// left waiting, so it should drain in the order people joined it rather
// than showing whatever arrived last.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

require_permission('orders.approve_cancel');

$title = 'Cancellation Requests - Admin';

if (is_post()) {
    csrf_check();

    $requestId = post_int('request_id');
    $action    = post('action');

    if ($requestId === null) {
        flash_error('That request could not be identified.');

    } elseif ($action === 'approve') {
        $result = approve_cancellation($requestId, post('decision_note'));

        $result['ok']
            ? flash_success($result['message'])
            : flash_error($result['message']);

    } elseif ($action === 'reject') {
        $result = reject_cancellation($requestId, post('decision_note'));

        $result['ok']
            ? flash_success($result['message'])
            : flash_error($result['message']);
    }

    redirect('/admin/cancellations.php');
}

$total    = pending_cancel_count();
$pager    = paginate($total, 20);
$requests = pending_cancel_requests(pager_limit($pager));

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Cancellation Requests</h2>
    </div>

    <?php if (!cancel_request_ready()): ?>
        <div class="alert alert-error mt-4">
            <strong>The approval workflow is not installed.</strong>
            Run <code>database/migration_28_cancel_approval.sql</code> in phpMyAdmin.
        </div>
    <?php else: ?>

    <p class="muted small-note mt-2">
        Nobody cancels an order directly any more &mdash; they ask, and it waits here.
        The order carries on as normal, still holding its stock, until you decide.
        <strong>Approving returns the stock; rejecting changes nothing.</strong>
    </p>

    <?php if ($requests === []): ?>
        <div class="card empty-state-box mt-4">
            <h3 class="empty-state-title">Nothing waiting</h3>
            <p>Cancellation requests appear here as soon as somebody raises one.</p>
        </div>
    <?php else: ?>

        <?php foreach ($requests as $r): ?>
            <?php
                $orderId = (int)$r['order_id'];

                // The order may have moved since the request was raised --
                // approving something already delivered has to be refused,
                // and the queue should say so before the click rather than
                // after it.
                $tooLate = $r['order_status'] === 'delivered'
                        || $r['order_status'] === 'cancelled';
            ?>

            <div class="card card-padded mt-4 cancel-request<?= $tooLate ? ' is-stale' : '' ?>">

                <div class="cancel-request-head">
                    <div>
                        <h3 class="section-heading">
                            <?php admin_link('/admin/order_detail.php?id=' . $orderId,
                                             'Order #' . $orderId); ?>
                        </h3>
                        <p class="muted small-note">
                            <?= e($r['customer_name']) ?>
                            &middot; <?= e($r['customer_email']) ?>
                            &middot; placed <?= e(fmt_date($r['order_placed'])) ?>
                        </p>
                    </div>

                    <div class="cancel-request-figures">
                        <span class="cancel-request-total"><?= e(money($r['total_amount'])) ?></span>
                        <span class="status-badge status-<?= e($r['order_status']) ?>">
                            <?= e(order_status_label($r['order_status'])) ?>
                        </span>
                    </div>
                </div>

                <dl class="info-list">
                    <?php detail_row('Reason', CANCEL_REASONS[$r['reason']] ?? ($r['reason'] ?: 'Not given')); ?>

                    <?php if (!empty($r['note'])): ?>
                        <?php detail_row('Details', $r['note']); ?>
                    <?php endif; ?>

                    <?php detail_row(
                        'Asked by',
                        ($r['requested_by_name'] ?: 'a deleted account')
                            . ' (' . $r['requested_role'] . ')'
                            . ' on ' . fmt_datetime($r['created_at'])
                    ); ?>

                    <?php if ($r['status_at_request'] !== $r['order_status']): ?>
                        <?php /* The order moved while the request sat here. Worth
                                 saying out loud -- it is the difference between
                                 "cancel this unpacked order" and "cancel this
                                 order that is now on a van". */ ?>
                        <?php detail_row(
                            'Moved since',
                            'Was ' . order_status_label($r['status_at_request'])
                                   . ' when asked, now ' . order_status_label($r['order_status'])
                        ); ?>
                    <?php endif; ?>
                </dl>

                <?php if ($tooLate): ?>
                    <div class="alert alert-warning">
                        This order is now <?= e(order_status_label($r['order_status'])) ?>,
                        so it can no longer be cancelled. Reject the request with a note
                        explaining why.
                    </div>
                <?php endif; ?>

                <form action="/admin/cancellations.php" method="POST" class="form-standard">
                    <?php csrf_field(); ?>
                    <?php html_hidden('request_id', $r['id']); ?>

                    <div class="form-group">
                        <label for="note_<?= (int)$r['id'] ?>">
                            Decision note
                            <small class="form-hint">
                                Required when rejecting &mdash; the person who asked
                                should be told why.
                            </small>
                        </label>
                        <input type="text" name="decision_note" id="note_<?= (int)$r['id'] ?>"
                               maxlength="500" class="form-control"
                               placeholder="e.g. already dispatched, or refund issued">
                    </div>

                    <div class="cancel-request-actions">
                        <?php /* Two buttons, one form. Both post the same note; the
                                 name/value on the button says which was pressed. */ ?>
                        <button type="submit" name="action" value="reject"
                                class="btn-outline"
                                data-confirm="Reject the cancellation request for order #<?= $orderId ?>? The order will be untouched.">
                            Reject
                        </button>

                        <?php if (!$tooLate): ?>
                            <button type="submit" name="action" value="approve"
                                    class="btn-primary btn-danger-solid"
                                    data-confirm="Cancel order #<?= $orderId ?> and return its stock? This cannot be undone from here.">
                                Approve &amp; Cancel Order
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>

        <?php render_pager($pager); ?>

    <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
