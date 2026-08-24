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

                        <?php /* The customer's own copy.
                                 A delivery photo shows THEIR doorstep, so on their
                                 own order page it is theirs to look at -- it is the
                                 proof the parcel arrived and where it was left.
                                 Only 'delivered' though: the shipped photo is a
                                 warehouse bench with other people's parcels on it.
                                 delivery_photo.php enforces both of those rules
                                 again on the way out. */ ?>
                        <?php if ($audience === 'member'
                                  && $entry['to_status'] === 'delivered'
                                  && !empty($entry['evidence_photo'])): ?>
                            <p class="timeline-photo-link">
                                <a href="/delivery_photo.php?id=<?= (int)$entry['id'] ?>"
                                   class="js-photo-modal"
                                   data-caption="Delivered <?= e(fmt_datetime($entry['created_at'])) ?>">
                                    <i class="fas fa-image" aria-hidden="true"></i>
                                    View delivery photo
                                </a>
                            </p>
                        <?php endif; ?>

                        <?php /* The proof photo, admin side.
                                 Shown inline here because an administrator looking at
                                 an order is auditing it, and a thumbnail they have to
                                 click is a thumbnail they will not click. Served by
                                 admin/evidence.php behind a permission check rather
                                 than sitting at a guessable public URL. */ ?>
                        <?php if ($audience === 'admin' && !empty($entry['evidence_photo'])): ?>
                            <figure class="timeline-evidence">
                                <a href="/admin/evidence.php?id=<?= (int)$entry['id'] ?>"
                                   target="_blank" rel="noopener">
                                    <img src="/admin/evidence.php?id=<?= (int)$entry['id'] ?>"
                                         alt="Photograph taken when the order was marked
                                              <?= e(order_status_label($entry['to_status'])) ?>"
                                         loading="lazy">
                                </a>
                                <figcaption class="muted small-note">
                                    <i class="fas fa-camera" aria-hidden="true"></i>
                                    Evidence recorded at this step
                                </figcaption>
                            </figure>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php
    }

    /**
     * The lightbox the delivery-photo links open into.
     *
     * Called from includes/footer.php, NOT from the timeline that needs
     * it. That is not tidiness -- it is load-bearing.
     *
     * A position:fixed element is only fixed to the viewport while none
     * of its ancestors has a transform. .card:hover sets
     * transform: translateY(-4px), so a dialog nested inside the Order
     * Progress card became positioned relative to that card instead: it
     * shifted 4px whenever the pointer entered, which moved it out from
     * under the pointer, which dropped the hover, which moved it back.
     * A flicker loop at whatever rate the browser could repaint.
     *
     * Emitted once however many links there are -- a modal per entry
     * would put five identical dialogs in the page and give them all the
     * same id.
     *
     * The image has no src until a link is clicked. Setting it up front
     * would make the browser fetch every delivery photo on page load, to
     * show something nobody has asked for yet.
     */
    function render_photo_modal(): void
    {
        static $rendered = false;

        if ($rendered) {
            return;
        }

        $rendered = true;
        ?>
        <div class="photo-modal" id="photoModal" hidden aria-hidden="true"
             role="dialog" aria-modal="true" aria-labelledby="photoModalTitle">
            <div class="photo-dialog" role="document">
                <div class="photo-head">
                    <h3 id="photoModalTitle">Delivery photo</h3>
                    <button type="button" class="photo-close js-photo-close" aria-label="Close">
                        &times;
                    </button>
                </div>

                <div class="photo-body">
                    <img id="photoModalImage" alt="Photograph taken when this order was delivered">
                    <p class="photo-caption muted small-note" id="photoModalCaption"></p>
                </div>

                <div class="photo-foot">
                    <a href="#" class="btn-outline" id="photoModalOpen" target="_blank" rel="noopener">
                        Open full size
                    </a>
                    <button type="button" class="btn-primary js-photo-close">Close</button>
                </div>
            </div>
        </div>
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
