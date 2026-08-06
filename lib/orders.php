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
    foreach (allowed_next_statuses($current) as $status) {
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
function log_status_change(int $orderId, ?string $from, string $to, string $actorRole, ?string $note = null): void
{
    if (!status_history_ready()) {
        return;
    }

    db_exec(
        'INSERT INTO order_status_history
                (order_id, from_status, to_status, changed_by, actor_role, note)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$orderId, $from, $to, current_user_id(), $actorRole, ($note === '' ? null : $note)]
    );
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

/**
 * Move an order to a new status, applying every rule in one place:
 * transition check, stock movement, and the audit entry.
 *
 * @throws RuntimeException when the transition is not allowed
 * @throws Throwable        when the database work fails
 */
function update_order_status(int $orderId, string $newStatus, string $actorRole, ?string $note = null): void
{
    $order = db_one('SELECT id, status FROM orders WHERE id = ?', [$orderId]);

    if (!$order) {
        throw new RuntimeException('Order not found.');
    }

    $from = $order['status'];

    if (!can_transition($from, $newStatus)) {
        throw new RuntimeException(transition_blocked_reason($from, $newStatus));
    }

    if ($newStatus === 'cancelled') {
        cancel_order($orderId, $actorRole, 'other', $note);
        return; // cancel_order writes its own history entry
    }

    if ($from === 'cancelled') {
        uncancel_order($orderId, $newStatus, $note);
        return; // uncancel_order writes its own history entry
    }

    db()->beginTransaction();
    try {
        db_exec('UPDATE orders SET status = ? WHERE id = ?', [$newStatus, $orderId]);
        log_status_change($orderId, $from, $newStatus, $actorRole, $note);
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
    return db_all(
        'SELECT oi.*, p.name AS product_name, p.image
           FROM order_items oi
           JOIN products p ON p.id = oi.product_id
          WHERE oi.order_id = ?',
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
