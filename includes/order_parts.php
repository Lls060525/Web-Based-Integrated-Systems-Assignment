<?php
// ============================================================
// includes/order_parts.php
// Reusable order display blocks, shared by the member order
// history, the member order detail page and the admin panel.
// ============================================================

if (!function_exists('render_order_lines')) {

    /**
     * The purchased items of an order as a table.
     * Pass the whole order row to also show the voucher discount.
     */
    function render_order_lines(array $lines, float $total, ?array $order = null): void
    {
        $discount      = (float)($order['discount_amount'] ?? 0);
        $pointsOff     = (float)($order['points_discount'] ?? 0);
        $pointsUsed    = (int)($order['points_redeemed'] ?? 0);
        $pointsEarned  = (int)($order['points_earned'] ?? 0);
        $subtotal      = (float)($order['subtotal'] ?? 0) ?: $total + $discount + $pointsOff;
        ?>
        <table class="admin-table order-lines-table">
            <thead>
                <tr>
                    <th colspan="2">Product</th>
                    <th>Unit Price</th>
                    <th>Qty</th>
                    <th>Subtotal</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $line): ?>
                <?php $subtotal = $line['price_at_purchase'] * $line['quantity']; ?>
                <tr>
                    <td class="cell-thumb">
                        <img src="<?= e(product_image($line['image'])) ?>" alt="" class="table-thumb">
                    </td>
                    <td class="cell-product">
                        <strong><?= e($line['product_name']) ?></strong>
                        <?php if (!empty($line['options_text'])): ?>
                            <div class="cart-options"><?= e($line['options_text']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Unit price"><?= e(money($line['price_at_purchase'])) ?></td>
                    <td data-label="Quantity">&times; <?= (int)$line['quantity'] ?></td>
                    <td data-label="Subtotal"><strong><?= e(money($subtotal)) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="order-totals">
            <?php if ($discount > 0 || $pointsOff > 0): ?>
                <div class="order-total-row">
                    <span>Subtotal</span>
                    <strong><?= e(money($subtotal)) ?></strong>
                </div>
                <div class="order-total-row discount-row">
                    <span>
                        Voucher discount
                        <?php if (!empty($order['voucher_code'])): ?>
                            <code class="voucher-code"><?= e($order['voucher_code']) ?></code>
                        <?php endif; ?>
                    </span>
                    <strong class="discount-value">&minus; <?= e(money($discount)) ?></strong>
                </div>
            <?php endif; ?>

            <?php if ($pointsOff > 0): ?>
                <div class="order-total-row discount-row">
                    <span>
                        Reward points
                        <span class="muted small-note">(<?= number_format($pointsUsed) ?> used)</span>
                    </span>
                    <strong class="discount-value">&minus; <?= e(money($pointsOff)) ?></strong>
                </div>
            <?php endif; ?>

            <div class="order-total-row grand-total">
                <span>Order Total</span>
                <strong class="price price-lg"><?= e(money($total)) ?></strong>
            </div>

            <?php if ($pointsEarned > 0): ?>
                <div class="order-total-row points-earned-row">
                    <span class="muted"><i class="fas fa-star"></i> Points earned</span>
                    <strong class="points-plus">+<?= number_format($pointsEarned) ?></strong>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * The audit trail of an order as a vertical timeline.
     * $audience is 'member' or 'admin'; members do not see staff names.
     */
    function render_status_timeline(array $history, string $audience = 'admin'): void
    {
        if (count($history) === 0) {
            echo '<p class="muted">No status changes have been recorded for this order.</p>';
            return;
        }
        ?>
        <ol class="status-timeline">
            <?php foreach ($history as $entry): ?>
                <li class="timeline-item timeline-<?= e($entry['to_status']) ?>">
                    <span class="timeline-dot"></span>

                    <div class="timeline-body">
                        <div class="timeline-head">
                            <strong><?= e(order_status_label($entry['to_status'])) ?></strong>
                            <?php if (!empty($entry['from_status'])): ?>
                                <span class="muted small-note">
                                    from <?= e(order_status_label($entry['from_status'])) ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="timeline-meta muted small-note">
                            <?= e(fmt_datetime($entry['created_at'])) ?>
                            <?php if ($audience === 'admin'): ?>
                                &middot;
                                <?php if (!empty($entry['actor_name'])): ?>
                                    <?= e($entry['actor_name']) ?> (<?= e($entry['actor_role']) ?>)
                                <?php else: ?>
                                    <?= e(ucfirst($entry['actor_role'])) ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($entry['note'])): ?>
                            <div class="timeline-note"><?= nl2br(e($entry['note'])) ?></div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php
    }

    /**
     * The cancellation panel shown on a cancelled order.
     * $audience is 'member' or 'admin' and only changes the wording.
     */
    function render_cancellation_panel(array $order, string $audience = 'member'): void
    {
        if ($order['status'] !== 'cancelled') {
            return;
        }

        // The columns only exist after the migration has been run.
        $reason = $order['cancel_reason'] ?? null;
        $note   = $order['cancel_note']   ?? null;
        $when   = $order['cancelled_at']  ?? null;
        $by     = $order['cancelled_by']  ?? null;
        ?>
        <div class="alert alert-cancelled">
            <h4 class="cancel-panel-title">
                <i class="fas fa-ban"></i> Order Cancelled
            </h4>

            <dl class="cancel-details">
                <?php if ($when): ?>
                    <dt>Cancelled on</dt>
                    <dd><?= e(fmt_datetime($when)) ?></dd>
                <?php endif; ?>

                <?php if ($by): ?>
                    <dt>Cancelled by</dt>
                    <dd>
                        <?php if ($by === 'member'): ?>
                            <?= $audience === 'admin' ? 'The customer' : 'You' ?>
                        <?php else: ?>
                            <?= $audience === 'admin' ? 'Store staff' : 'Our team' ?>
                        <?php endif; ?>
                    </dd>
                <?php endif; ?>

                <dt>Reason</dt>
                <dd><?= e(cancel_reason_label($reason)) ?></dd>

                <?php if (!empty($note)): ?>
                    <dt>Note</dt>
                    <dd><?= nl2br(e($note)) ?></dd>
                <?php endif; ?>
            </dl>

            <p class="cancel-panel-foot muted">
                All items from this order have been returned to stock.
                <?php if ($audience === 'member'): ?>
                    If you were charged, the refund is handled by our support team.
                <?php endif; ?>
            </p>
        </div>
        <?php
    }
}
