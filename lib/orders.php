<?php
// ============================================================
// lib/orders.php
// Shared order logic. Cancellation lives here rather than in a
// page so the member pages and the admin panel run exactly the
// same rules and the same stock restoration.
// ============================================================

/**
 * True once the cancellation columns exist.
 * Lets the module degrade instead of throwing a fatal error when
 * database/migration_password_reset.sql has not been run yet.
 */
function cancellation_columns_ready(): bool
{
    return db_column_exists('orders', 'cancel_reason');
}

/** True when a member is still allowed to cancel an order in this status. */
function member_can_cancel(string $status): bool
{
    return in_array($status, MEMBER_CANCELLABLE_STATUSES, true);
}

/** Why an order cannot be cancelled, for display to the member. */
function cancel_blocked_reason(string $status): string
{
    return match ($status) {
        'shipped'   => 'This order has already been shipped and can no longer be cancelled.',
        'delivered' => 'This order has already been delivered.',
        'cancelled' => 'This order is already cancelled.',
        default     => 'This order can no longer be cancelled.',
    };
}

// ------------------------------------------------------------
// Status transitions (Admin)
// ------------------------------------------------------------

/** Statuses an order may legally move to from where it is now. */
function allowed_next_statuses(string $current): array
{
    return ORDER_STATUS_TRANSITIONS[$current] ?? [];
}

/** True when moving from one status to another is permitted. */
function can_transition(string $from, string $to): bool
{
    return in_array($to, allowed_next_statuses($from), true);
}

/** True when the order has reached a state that can no longer change. */
function is_final_status(string $status): bool
{
    return in_array($status, ORDER_FINAL_STATUSES, true);
}

/** Why a transition was refused, phrased for the admin. */
function transition_blocked_reason(string $from, string $to): string
{
    if ($from === $to) {
        return 'The order is already ' . order_status_label($from) . '.';
    }
    if (is_final_status($from)) {
        return 'This order is ' . order_status_label($from)
             . ' and has reached the end of the workflow. Its status can no longer be changed.';
    }
    return 'An order that is ' . order_status_label($from)
         . ' cannot move to ' . order_status_label($to) . '. '
         . 'Allowed next steps: ' . status_options_label(allowed_next_statuses($from)) . '.';
}

/** Comma-separated labels for a list of status keys. */
function status_options_label(array $statuses): string
{
    if (count($statuses) === 0) {
        return 'none';
    }
    return implode(', ', array_map('order_status_label', $statuses));
}

/** [key => label] of the statuses an order may move to, for a <select>. */
function next_status_options(string $current): array
{
    $options = [];

    // permitted_next_statuses(), not allowed_next_statuses(): a dropdown
    // that lists moves the person will be refused for making is a trap.
    // The refusal still happens in update_order_status() -- this only
    // stops the interface from inviting it.
    foreach (permitted_next_statuses($current) as $status) {
        $options[$status] = order_status_label($status);
    }

    return $options;
}

// ------------------------------------------------------------
// Audit trail
// ------------------------------------------------------------

/** True once order_status_history exists (see the migration). */
function status_history_ready(): bool
{
    return db_table_exists('order_status_history');
}

/**
 * Record one status change.
 * Silently does nothing when the migration has not been run, so the
 * rest of the workflow keeps working either way.
 */
function log_status_change(
    int $orderId,
    ?string $from,
    string $to,
    string $actorRole,
    ?string $note = null,
    ?string $evidencePhoto = null
): void {
    if (!status_history_ready()) {
        return;
    }

    // The column arrives in migration 27. Writing it conditionally means
    // a database that has the history table but not the column records
    // the change rather than throwing on an unknown column.
    if (evidence_column_ready()) {
        db_exec(
            'INSERT INTO order_status_history
                    (order_id, from_status, to_status, changed_by, actor_role, note, evidence_photo)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$orderId, $from, $to, current_user_id(), $actorRole,
             ($note === '' ? null : $note), ($evidencePhoto === '' ? null : $evidencePhoto)]
        );
        return;
    }

    db_exec(
        'INSERT INTO order_status_history
                (order_id, from_status, to_status, changed_by, actor_role, note)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$orderId, $from, $to, current_user_id(), $actorRole, ($note === '' ? null : $note)]
    );
}

/** True once migration_27_fulfilment.sql has added the photo column. */
function evidence_column_ready(): bool
{
    static $ready = null;

    if ($ready === null) {
        $ready = status_history_ready()
              && db_column_exists('order_status_history', 'evidence_photo');
    }

    return $ready;
}

/** Full timeline for an order, oldest first. */
function order_status_history(int $orderId): array
{
    if (!status_history_ready()) {
        return [];
    }

    return db_all(
        'SELECT h.*, u.name AS actor_name
           FROM order_status_history h
           LEFT JOIN users u ON u.id = h.changed_by
          WHERE h.order_id = ?
          ORDER BY h.id ASC',
        [$orderId]
    );
}

// ------------------------------------------------------------
// Who may make which move, and what they must show for it
// ------------------------------------------------------------

/**
 * The permission required to move an order INTO a status.
 *
 * Deliberately keyed on the destination rather than on the pair. "May
 * you mark things shipped" is the question a warehouse actually asks;
 * "may you go from processing to shipped specifically" is a distinction
 * nobody makes, and it would need twenty entries instead of four.
 *
 * A status with no entry here is governed by orders.manage alone --
 * which covers reinstating a cancelled order back to pending.
 */
function transition_permission(string $toStatus): ?string
{
    // 'cancelled' is absent on purpose. Nothing moves an order there
    // directly any more -- approving a request does it, and that carries
    // its own permission (orders.approve_cancel) in lib/cancellation.php.
    return [
        'processing' => 'orders.to_processing',
        'shipped'    => 'orders.to_shipped',
        'delivered'  => 'orders.to_delivered',
    ][$toStatus] ?? null;
}

/**
 * Transitions that cannot be recorded without a photograph.
 *
 * These are the two steps a customer may later dispute -- "it never
 * arrived", "the box was already open". Requiring the photo at the
 * moment of the claim means the proof and the claim cannot be separated
 * afterwards.
 */
function transition_needs_evidence(string $toStatus): bool
{
    return in_array($toStatus, ['shipped', 'delivered'], true);
}

/**
 * May the signed-in admin move an order into this status?
 *
 * Both halves must pass: the transition's own permission, and the
 * general right to touch orders at all. A Delivery Man holds
 * orders.to_shipped without orders.manage, so the second check is
 * written as "either" rather than "both" -- see the comment below.
 */
function can_make_transition(string $toStatus): bool
{
    $permission = transition_permission($toStatus);

    if ($permission === null) {
        // No dedicated permission for this destination, so it falls back
        // to the broad one. Reinstating a cancelled order is the only
        // case, and it is an office job rather than a warehouse one.
        return can('orders.manage');
    }

    // NOT "&& can('orders.manage')". A Delivery Man is given the single
    // transition they need and nothing else; requiring the broad
    // permission as well would mean handing them the whole order list
    // just to let them mark one parcel delivered.
    return can($permission);
}

/** The statuses this admin may move an order to from where it is now. */
function permitted_next_statuses(string $current): array
{
    return array_values(array_filter(
        allowed_next_statuses($current),
        'can_make_transition'
    ));
}

/** Why a transition was refused on permission grounds, or null. */
function transition_permission_reason(string $toStatus): ?string
{
    if (can_make_transition($toStatus)) {
        return null;
    }

    return 'Your role cannot move orders to ' . order_status_label($toStatus) . '.';
}

/**
 * Handle a status-change form submission, end to end.
 *
 * Three screens post this same shape -- the QR scanner, the order list
 * and the order detail page -- so the upload, the validation and the
 * call all live here. Each screen keeps only its own markup.
 *
 * The photo is saved BEFORE update_order_status() runs, because the
 * function needs a filename to store. If the transition is then refused,
 * the orphaned file is removed rather than left behind: a photo with no
 * history row pointing at it is litter that nothing will ever clean up.
 *
 * @param  string $fileKey the $_FILES key holding the evidence photo
 * @return array{ok: bool, message: string}
 */
function handle_status_change(int $orderId, string $newStatus, ?string $note, string $fileKey = 'evidence'): array
{
    if (!in_array($newStatus, ORDER_STATUSES, true)) {
        return ['ok' => false, 'message' => 'That is not a valid status.'];
    }

    // Checked here as well as inside update_order_status(). Not
    // redundant: this is what lets the screen say why before spending an
    // upload on a move that was never going to be allowed.
    $refusal = transition_permission_reason($newStatus);

    if ($refusal !== null) {
        return ['ok' => false, 'message' => $refusal];
    }

    $photo = null;

    if (transition_needs_evidence($newStatus)) {
        // save_uploaded_image() sniffs the real MIME type, enforces the
        // size cap, gives the file a random name and bakes in the EXIF
        // rotation that phone cameras rely on. Reused rather than
        // reimplemented so evidence photos get the same treatment as
        // every other upload on the site.
        $photo = save_uploaded_image($fileKey, DIR_UPLOAD_EVIDENCE, 'evidence', EVIDENCE_MAX_SIZE);

        if ($photo !== null) {
            // Accepted at phone size, stored at a sane one. Failure here
            // is deliberately ignored: the photograph is already saved
            // and already valid, so a missing GD extension should cost
            // disk space rather than lose the evidence.
            downscale_image(DIR_UPLOAD_EVIDENCE . $photo, EVIDENCE_MAX_EDGE);
        }

        if ($photo === null) {
            // Report the REAL reason.
            //
            // This used to return a flat "a photograph is required" no
            // matter what went wrong, which is how a 4 MB phone photo
            // being rejected for its size looked identical to no photo at
            // all. save_uploaded_image() records the specific failure via
            // add_err(); throwing that away turned a diagnosable problem
            // into a mystery that could only be solved by reading the
            // source.
            global $_err;

            $reason = $_err[$fileKey] ?? null;

            return [
                'ok'      => false,
                'message' => $reason
                    ?? ('A photograph is required to mark this order '
                        . order_status_label($newStatus) . '.'),
            ];
        }
    }

    try {
        update_order_status($orderId, $newStatus, 'admin', $note, $photo);

    } catch (\Throwable $e) {
        if ($photo !== null) {
            delete_uploaded_file(DIR_UPLOAD_EVIDENCE, $photo);
        }

        return ['ok' => false, 'message' => $e->getMessage()];
    }

    return [
        'ok'      => true,
        'message' => 'Order #' . $orderId . ' is now ' . order_status_label($newStatus) . '.',
    ];
}

/**
 * Move an order to a new status, applying every rule in one place:
 * transition check, stock movement, and the audit entry.
 *
 * @throws RuntimeException when the transition is not allowed
 * @throws Throwable        when the database work fails
 */
function update_order_status(
    int $orderId,
    string $newStatus,
    string $actorRole,
    ?string $note = null,
    ?string $evidencePhoto = null
): void {
    $order = db_one('SELECT id, status FROM orders WHERE id = ?', [$orderId]);

    if (!$order) {
        throw new RuntimeException('Order not found.');
    }

    $from = $order['status'];

    if (!can_transition($from, $newStatus)) {
        throw new RuntimeException(transition_blocked_reason($from, $newStatus));
    }

    // ---- Is this person allowed to make THIS move? ----
    //
    // Checked here rather than on each page. Three screens change order
    // status -- the QR scanner, the order list and the order detail page
    // -- and a rule enforced in three places is a rule enforced in two
    // places as soon as somebody adds a fourth.
    //
    // Skipped for a system actor: checkout marks an order paid without
    // anybody being signed in as an administrator.
    if ($actorRole !== 'system') {
        $refusal = transition_permission_reason($newStatus);

        if ($refusal !== null) {
            throw new RuntimeException($refusal);
        }
    }

    // ---- Proof, where proof is required ----
    //
    // Refused rather than recorded-without, because a shipped order with
    // no photograph is exactly the record that is useless in a dispute,
    // and it would be indistinguishable from one taken before the rule
    // existed.
    if ($actorRole !== 'system'
        && transition_needs_evidence($newStatus)
        && ($evidencePhoto === null || $evidencePhoto === '')) {

        throw new RuntimeException(
            'A photograph is required before an order can be marked '
            . order_status_label($newStatus) . '.'
        );
    }

    // There is deliberately no "if ($newStatus === 'cancelled')" branch
    // here any more.
    //
    // It became unreachable when 'cancelled' left ORDER_STATUS_TRANSITIONS
    // -- can_transition() above refuses it for every starting status --
    // but unreachable is not the same as harmless. A live-looking call to
    // cancel_order() sitting in the middle of update_order_status() is an
    // invitation to "fix" the transition map later and quietly restore a
    // route that skips the whole approval workflow.
    //
    // Cancelling is approve_cancellation() in lib/cancellation.php, and
    // nowhere else.

    if ($from === 'cancelled') {
        uncancel_order($orderId, $newStatus, $note);
        return; // uncancel_order writes its own history entry
    }

    db()->beginTransaction();
    try {
        db_exec('UPDATE orders SET status = ? WHERE id = ?', [$newStatus, $orderId]);
        log_status_change($orderId, $from, $newStatus, $actorRole, $note, $evidencePhoto);
        db()->commit();
    } catch (\Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

/** Load an order that belongs to a specific member, or null. */
function find_member_order(int $orderId, int $userId): ?array
{
    return db_one('SELECT * FROM orders WHERE id = ? AND user_id = ?', [$orderId, $userId]) ?: null;
}

/** Every line of an order, joined to the product for name and photo. */
function order_lines(int $orderId): array
{
    // options_text only exists once migration_20 has been run.
    $optionsColumn = db_column_exists('order_items', 'options_text')
        ? 'oi.options_text' : "NULL AS options_text";

    return db_all(
        "SELECT oi.*, $optionsColumn, p.name AS product_name, p.image
           FROM order_items oi
           JOIN products p ON p.id = oi.product_id
          WHERE oi.order_id = ?",
        [$orderId]
    );
}

/**
 * Put every unit from an order back into stock.
 * The actual write lives in lib/stock.php so it is recorded in the
 * movement ledger like every other stock change.
 */
function restore_order_stock(int $orderId): void
{
    $lines = db_all('SELECT product_id, quantity FROM order_items WHERE order_id = ?', [$orderId]);

    foreach ($lines as $line) {
        return_stock((int)$line['product_id'], (int)$line['quantity'], $orderId);
    }
}

/**
 * Take an order's units back out of stock, e.g. when un-cancelling.
 * deduct_stock() throws when there is not enough left, which rolls the
 * surrounding transaction back.
 */
function deduct_order_stock(int $orderId): void
{
    $lines = db_all('SELECT product_id, quantity FROM order_items WHERE order_id = ?', [$orderId]);

    foreach ($lines as $line) {
        deduct_stock((int)$line['product_id'], (int)$line['quantity'], $orderId);
    }
}

/**
 * Cancel an order and return its stock, in one transaction.
 *
 * @param string      $by     'member' or 'admin'
 * @param string|null $reason a key from CANCEL_REASONS
 * @param string|null $note   optional free text from the member
 *
 * @throws Throwable when the transaction fails; the caller reports it
 */
function cancel_order(int $orderId, string $by, ?string $reason = null, ?string $note = null): void
{
    $from = db_value('SELECT status FROM orders WHERE id = ?', [$orderId]) ?: null;

    db()->beginTransaction();

    try {
        restore_order_stock($orderId);

        if (cancellation_columns_ready()) {
            db_exec(
                "UPDATE orders
                    SET status = 'cancelled',
                        cancel_reason = ?,
                        cancel_note = ?,
                        cancelled_at = NOW(),
                        cancelled_by = ?
                  WHERE id = ?",
                [$reason, ($note === '' ? null : $note), $by, $orderId]
            );
        } else {
            db_exec("UPDATE orders SET status = 'cancelled' WHERE id = ?", [$orderId]);
        }

        // A cancelled order should not consume the customer's voucher,
        // nor keep the points it earned, nor swallow the points it spent.
        release_voucher($orderId);
        reverse_order_points($orderId);

        log_status_change(
            $orderId,
            $from,
            'cancelled',
            $by,
            trim(cancel_reason_label($reason) . ($note ? ' - ' . $note : ''))
        );

        db()->commit();

    } catch (\Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

/** Reverse a cancellation, taking the stock back out again. */
function uncancel_order(int $orderId, string $newStatus, ?string $note = null): void
{
    db()->beginTransaction();

    try {
        deduct_order_stock($orderId);

        if (cancellation_columns_ready()) {
            db_exec(
                'UPDATE orders
                    SET status = ?, cancel_reason = NULL, cancel_note = NULL,
                        cancelled_at = NULL, cancelled_by = NULL
                  WHERE id = ?',
                [$newStatus, $orderId]
            );
        } else {
            db_exec('UPDATE orders SET status = ? WHERE id = ?', [$newStatus, $orderId]);
        }

        log_status_change($orderId, 'cancelled', $newStatus, 'admin',
                          $note ?: 'Order reinstated; stock deducted again.');

        db()->commit();

    } catch (\Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

/** Human-readable cancellation reason for an order row. */
function cancel_reason_label(?string $reason): string
{
    if ($reason === null || $reason === '') {
        return 'No reason given';
    }
    return CANCEL_REASONS[$reason] ?? $reason;
}
