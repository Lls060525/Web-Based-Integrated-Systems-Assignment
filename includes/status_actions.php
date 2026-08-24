<?php
// ============================================================
// includes/status_actions.php
// The "move this order forward" panel.
//
// Shared by admin/qr_scan.php and admin/order_detail.php so the two
// cannot offer different buttons for the same order. Everything it
// draws is decided by permitted_next_statuses() in lib/orders.php,
// which is the same function update_order_status() enforces against --
// so the panel physically cannot offer a move that would be refused.
// ============================================================

if (!function_exists('render_status_actions')) {

    /**
     * @param array  $order   the row being acted on
     * @param string $action  the form's target page
     */
    function render_status_actions(array $order, string $action): void
    {
        $current   = (string)$order['status'];
        $permitted = permitted_next_statuses($current);
        $possible  = allowed_next_statuses($current);
        ?>

        <div class="status-actions">
            <h3 class="section-heading">Move this order forward</h3>

            <?php if ($possible === []): ?>
                <p class="muted">
                    This order is <strong><?= e(order_status_label($current)) ?></strong>
                    and has reached the end of the workflow.
                </p>

            <?php elseif ($permitted === []): ?>
                <?php /* Short on purpose.
                         This used to name the next step and explain that the
                         role could not take it. That reads as an invitation to
                         argue about a decision that has already been made, and
                         it tells somebody about a stage of the workflow that is
                         none of their business. "Not yours" is the whole
                         message. */ ?>
                <div class="alert alert-warning">
                    <i class="fas fa-lock"></i>
                    <strong>You do not have permission to act on this order.</strong>
                </div>

            <?php else: ?>
                <?php foreach ($permitted as $to): ?>
                    <?php $needsPhoto = transition_needs_evidence($to); ?>

                    <?php /* The confirm dialog is only for the one-click moves.
                             Attaching a photograph is already a deliberate act --
                             the driver had to open the camera, frame the parcel
                             and take the picture -- so asking "are you sure?" on
                             top of that is a click that protects nobody. It is
                             kept for a move like Processing, where the whole
                             action is a single button press that could be a
                             mis-tap on a phone. */ ?>
                    <form action="<?= e($action) ?>" method="POST"
                          class="status-action-form form-standard"
                          <?= $needsPhoto ? 'enctype="multipart/form-data"' : '' ?>
                          <?= $needsPhoto ? '' : 'data-confirm="Mark order #' . (int)$order['id']
                                                 . ' as ' . e(order_status_label($to)) . '?"' ?>>
                        <?php csrf_field(); ?>
                        <?php html_hidden('action', 'advance'); ?>
                        <?php html_hidden('order_id', $order['id']); ?>
                        <?php html_hidden('to_status', $to); ?>

                        <div class="status-action-head">
                            <span class="status-action-arrow">
                                <?= e(order_status_label($current)) ?>
                                <i class="fas fa-arrow-right"></i>
                                <strong><?= e(order_status_label($to)) ?></strong>
                            </span>
                        </div>

                        <?php if ($needsPhoto): ?>
                            <?php /* accept="image/*" plus capture tells a phone to
                                     open the camera directly, while still allowing a
                                     file to be chosen -- so a laptop with no camera,
                                     or a phone whose camera permission was refused,
                                     is not stuck. */ ?>
                            <div class="form-group evidence-field">
                                <label for="evidence_<?= e($to) ?>">
                                    Photograph <span class="req">*</span>
                                    <small class="form-hint">
                                        Required. Take a photo of the parcel
                                        <?= $to === 'delivered' ? 'at the delivery point' : 'before it leaves' ?>.
                                        This is stored against the order and cannot be
                                        changed afterwards.
                                    </small>
                                </label>
                                <input type="file"
                                       name="evidence"
                                       id="evidence_<?= e($to) ?>"
                                       accept="image/*"
                                       capture="environment"
                                       class="form-control"
                                       required>
                            </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="note_<?= e($to) ?>">
                                Note
                                <small class="form-hint">
                                    <?= $needsPhoto
                                        ? 'Optional -- the photograph is the record. Only add a note if something needs explaining.'
                                        : 'Optional' ?>
                                </small>
                            </label>
                            <input type="text" name="note" id="note_<?= e($to) ?>"
                                   maxlength="500" class="form-control"
                                   placeholder="<?= $to === 'delivered'
                                        ? 'e.g. Left with the front desk'
                                        : 'Anything worth recording' ?>">
                        </div>

                        <?php /* html_submit() escapes its label, so the icon
                                 cannot be smuggled in there -- it goes in the
                                 markup and the label stays plain text. Passing
                                 e() here as well would double-escape it. */ ?>
                        <button type="submit" class="btn-primary btn-block">
                            <?php if ($needsPhoto): ?>
                                <i class="fas fa-camera" aria-hidden="true"></i>
                            <?php endif; ?>
                            Mark as <?= e(order_status_label($to)) ?>
                        </button>
                    </form>
                <?php endforeach; ?>

            <?php endif; ?>

            <?php /* Cancellation is not in the forward map any more, so it is
                     drawn separately -- and it is a REQUEST, not a move. The
                     panel above is "what happens next"; this is "stop".
                     
                     can_request_cancellation() is the gate: a role may only
                     stop an order that is currently ITS turn to move forward.
                     A Vendor looking at an order already in processing is
                     looking at somebody else's work, so nothing is drawn at
                     all -- not a greyed-out button, not an explanation. */ ?>
            <?php $mayRequest    = can_request_cancellation($order); ?>
            <?php $cancelBlocker = cancel_request_blocker($order); ?>
            <?php $openRequest   = open_cancel_request((int)$order['id']); ?>

            <?php if ($openRequest !== null): ?>
                <div class="alert alert-warning mt-4">
                    <strong>Cancellation requested.</strong>
                    Raised <?= e(fmt_datetime($openRequest['created_at'])) ?>
                    while the order was
                    <?= e(order_status_label($openRequest['status_at_request'])) ?>.
                    <?php if (!empty($openRequest['note'])): ?>
                        <div class="mt-2"><?= nl2br(e($openRequest['note'])) ?></div>
                    <?php endif; ?>

                    <div class="mt-2">
                        <?php if (can('orders.approve_cancel')): ?>
                            <?php admin_link('/admin/cancellations.php', 'Decide it in the queue',
                                             ['class' => 'btn-outline btn-sm']); ?>
                        <?php else: ?>
                            <span class="muted small-note">
                                Waiting for someone who can approve cancellations.
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($mayRequest && $cancelBlocker === null): ?>
                <form action="<?= e($action) ?>" method="POST"
                      class="status-action-form is-cancel form-standard"
                      data-confirm="Ask for order #<?= (int)$order['id'] ?> to be cancelled?">
                    <?php csrf_field(); ?>
                    <?php html_hidden('action', 'request_cancel'); ?>
                    <?php html_hidden('order_id', $order['id']); ?>

                    <div class="status-action-head">
                        <span class="status-action-arrow">
                            <i class="fas fa-ban"></i>
                            <strong>Request cancellation</strong>
                        </span>
                    </div>

                    <p class="muted small-note">
                        This does not cancel the order. It goes to an administrator,
                        and the stock stays committed until they approve it.
                    </p>

                    <?php field('cancel_reason', 'Reason', function () {
                        html_select('cancel_reason', CANCEL_REASONS, '',
                                    ['required' => true], '-- Why? --');
                    }, true); ?>

                    <div class="form-group">
                        <label for="cancel_note_staff">Details <small class="form-hint">Optional</small></label>
                        <input type="text" name="note" id="cancel_note_staff"
                               maxlength="500" class="form-control"
                               placeholder="e.g. customer phoned, item damaged in the warehouse">
                    </div>

                    <button type="submit" class="btn-outline btn-danger btn-block">
                        Request Cancellation
                    </button>
                </form>

            <?php elseif ($mayRequest && $cancelBlocker !== null): ?>
                <?php /* It IS this role's turn, but something else stops the
                         request -- already cancelled, already delivered, or one
                         is already waiting. That has a reason worth printing. */ ?>
                <p class="muted small-note mt-4">
                    <i class="fas fa-circle-info"></i> <?= e($cancelBlocker) ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
}
