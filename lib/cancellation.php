<?php
// ============================================================
// lib/cancellation.php
// Cancelling an order is a request somebody approves.
//
// THE SHAPE
//
//   anyone with orders.request_cancel  raises a request
//   the order carries on unchanged, still holding its stock
//   somebody with orders.approve_cancel decides
//     approved -> the order is cancelled and the stock comes back
//     rejected -> nothing happens to the order; the record stays
//
// Members raise requests through order_cancel.php; staff raise them from
// the order page or the QR scanner. Both end up here, so there is one
// answer to "may this be cancelled" rather than two that drift.
//
// WHY THE STOCK MOVES ON APPROVAL AND NOT BEFORE
//
// Stock is the thing that must not be wrong. Returning it when the
// request is raised would let somebody free up inventory they are not
// entitled to free up, simply by asking; and if the request is then
// rejected, the stock has to be taken away again, which is a second
// movement to explain in the ledger. Approval is the single moment the
// order actually changes, so it is the single moment the stock does.
// ============================================================

/** True once migration_28_cancel_approval.sql has been run. */
function cancel_request_ready(): bool
{
    static $ready = null;

    if ($ready === null) {
        $ready = db_table_exists('order_cancel_requests');
    }

    return $ready;
}

// ------------------------------------------------------------
// Reading
// ------------------------------------------------------------

/**
 * The open request for an order, or null.
 *
 * Answers from the prefetch cache when there is one. The member's order
 * list asks this once per row, so without the cache a ten-order page
 * would run ten extra queries to draw ten badges.
 */
function open_cancel_request(int $orderId): ?array
{
    if (!cancel_request_ready()) {
        return null;
    }

    $cache = &cancel_request_cache();

    if (!array_key_exists($orderId, $cache)) {
        prefetch_open_cancel_requests([$orderId]);
    }

    return $cache[$orderId] ?? null;
}

/**
 * Load the open requests for several orders in ONE query.
 *
 * Call it before a loop that will ask about many orders. Skipping the
 * call is not an error, only slower -- open_cancel_request() fills the
 * cache on demand either way.
 *
 * @param int[] $orderIds
 */
function prefetch_open_cancel_requests(array $orderIds): void
{
    if (!cancel_request_ready()) {
        return;
    }

    $cache = &cancel_request_cache();
    $ids   = [];

    foreach ($orderIds as $id) {
        $id = (int)$id;

        if ($id > 0 && !array_key_exists($id, $cache)) {
            $ids[$id] = $id;
        }
    }

    if ($ids === []) {
        return;
    }

    // Seeded null first, so an order with no open request is remembered
    // as "asked and there is none" rather than re-queried every time.
    foreach ($ids as $id) {
        $cache[$id] = null;
    }

    $ids   = array_values($ids);
    $marks = implode(',', array_fill(0, count($ids), '?'));

    $rows = db_all(
        "SELECT * FROM order_cancel_requests
          WHERE status = 'pending' AND order_id IN ($marks)
          ORDER BY id ASC",
        $ids
    );

    foreach ($rows as $row) {
        $cache[(int)$row['order_id']] = $row;
    }
}

/**
 * The request-lifetime store behind the two functions above.
 *
 * Per request only. A decision made in another tab must be visible on
 * the next page load, not cached past it.
 *
 * @return array<int, array|null>
 */
function &cancel_request_cache(): array
{
    static $cache = [];

    return $cache;
}

/**
 * Drop what is cached about one order.
 *
 * Called after every write. Without it, raising a request and then
 * asking about it in the same request would get the answer from before
 * the insert -- so a page that raises and then re-renders would tell the
 * member nothing had happened.
 */
function forget_cancel_request(int $orderId): void
{
    $cache = &cancel_request_cache();

    unset($cache[$orderId]);
}

/** Every request ever raised for an order, newest first. */
function cancel_request_history(int $orderId): array
{
    if (!cancel_request_ready()) {
        return [];
    }

    return db_all(
        'SELECT r.*,
                asked.name   AS requested_by_name,
                decided.name AS decided_by_name
           FROM order_cancel_requests r
           LEFT JOIN users asked   ON asked.id   = r.requested_by
           LEFT JOIN users decided ON decided.id = r.decided_by
          WHERE r.order_id = ?
          ORDER BY r.id DESC',
        [$orderId]
    );
}

/** How many requests are waiting for a decision. Drives the queue badge. */
function pending_cancel_count(): int
{
    if (!cancel_request_ready()) {
        return 0;
    }

    return (int)db_value("SELECT COUNT(*) FROM order_cancel_requests WHERE status = 'pending'");
}

/**
 * The approval queue, oldest first.
 *
 * Oldest first on purpose: a cancellation left sitting is a customer
 * waiting for an answer, so the queue should drain in the order people
 * joined it rather than showing the newest at the top.
 */
function pending_cancel_requests(string $limitClause = ''): array
{
    if (!cancel_request_ready()) {
        return [];
    }

    return db_all(
        "SELECT r.*,
                o.status       AS order_status,
                o.total_amount,
                o.created_at   AS order_placed,
                u.name         AS customer_name,
                u.email        AS customer_email,
                asked.name     AS requested_by_name
           FROM order_cancel_requests r
           JOIN orders o        ON o.id = r.order_id
           JOIN users  u        ON u.id = o.user_id
           LEFT JOIN users asked ON asked.id = r.requested_by
          WHERE r.status = 'pending'
          ORDER BY r.created_at ASC" . $limitClause
    );
}

// ------------------------------------------------------------
// Rules
// ------------------------------------------------------------

/**
 * May the signed-in STAFF member ask for this order to be cancelled?
 *
 * The rule is: you may only stop an order that is currently yours to
 * move forward.
 *
 * That falls out of the transitions a role already holds, so it needs no
 * separate table and it cannot drift:
 *
 *   Vendor holds orders.to_processing, which runs FROM pending
 *     -> a Vendor may cancel a PENDING order, and nothing else
 *
 *   Delivery Man holds orders.to_shipped (from processing) and
 *   orders.to_delivered (from shipped)
 *     -> a driver may cancel a PROCESSING or SHIPPED order
 *
 * A Vendor looking at an order that has already moved to processing is
 * looking at somebody else's work. Offering them a Cancel button there
 * invites them to overrule a colleague on a parcel they have not seen.
 *
 * Anyone who can APPROVE cancellations is exempt: refusing to let them
 * raise one would be theatre, since they could approve whatever they
 * raised anyway. That is the office, and the office is not queue-bound.
 */
function can_request_cancellation(array $order): bool
{
    if (!can('orders.request_cancel')) {
        return false;
    }

    if (can('orders.approve_cancel')) {
        return true;
    }

    // Is this order at a step this role could move forward? If there is
    // nothing here for them to do, it is not their turn.
    return permitted_next_statuses((string)$order['status']) !== [];
}

/**
 * Why this order cannot be asked to cancel, or null when it can.
 *
 * A reason rather than a boolean so every screen can explain itself
 * with the same words. Deliberately does NOT include the "is it your
 * turn" test -- that one has no explanation worth printing, because the
 * answer is simply that the button is not there.
 */
function cancel_request_blocker(array $order): ?string
{
    $status = (string)$order['status'];

    if ($status === 'cancelled') {
        return 'This order is already cancelled.';
    }

    if ($status === 'delivered') {
        return 'This order has been delivered. It is too late to cancel it '
             . '-- a return would be the next step instead.';
    }

    if (open_cancel_request((int)$order['id']) !== null) {
        return 'A cancellation request for this order is already waiting for approval.';
    }

    return null;
}

// ------------------------------------------------------------
// Writing
// ------------------------------------------------------------

/**
 * Raise a request. Does NOT change the order.
 *
 * @param  string $role 'member' or 'admin' -- who is asking, not who they are
 * @return array{ok: bool, message: string}
 */
function request_cancellation(array $order, string $role, ?string $reason, ?string $note): array
{
    if (!cancel_request_ready()) {
        return ['ok' => false, 'message' => 'Cancellation requests are not available yet.'];
    }

    // Staff must be at a step that is theirs to move forward. Checked
    // here and not only in the panel, because a hidden button is a
    // courtesy and this is the rule -- the form can be posted directly.
    //
    // Members are exempt: order_cancel.php has already established that
    // the order is theirs and is still cancellable, and a customer has no
    // role in the fulfilment queue to take a turn in.
    if ($role !== 'member' && !can_request_cancellation($order)) {
        return [
            'ok'      => false,
            'message' => 'You do not have permission to act on this order.',
        ];
    }

    $blocker = cancel_request_blocker($order);

    if ($blocker !== null) {
        return ['ok' => false, 'message' => $blocker];
    }

    try {
        db_exec(
            'INSERT INTO order_cancel_requests
                    (order_id, requested_by, requested_role, status_at_request, reason, note)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                (int)$order['id'],
                current_user_id(),
                $role,
                $order['status'],
                ($reason === '' ? null : $reason),
                ($note === '' ? null : $note),
            ]
        );

    } catch (\Throwable $e) {
        // The unique index on open_order_id is what actually stops two
        // people raising a request at the same instant -- the check above
        // passes for both of them. Landing here means the other one won.
        return [
            'ok'      => false,
            'message' => 'A cancellation request for this order already exists.',
        ];
    }

    forget_cancel_request((int)$order['id']);

    return [
        'ok'      => true,
        'message' => 'Cancellation requested. An administrator will review it '
                   . 'and the order continues as normal until they do.',
    ];
}

/**
 * Approve a request: cancel the order and return the stock.
 *
 * The whole thing is one transaction. A half-applied cancellation --
 * stock returned but the order still processing, or the reverse -- is
 * the worst possible outcome, because nothing on screen would reveal it.
 *
 * @return array{ok: bool, message: string}
 */
function approve_cancellation(int $requestId, ?string $decisionNote): array
{
    if (!can('orders.approve_cancel')) {
        return ['ok' => false, 'message' => 'Your role cannot approve cancellations.'];
    }

    $request = db_one(
        "SELECT * FROM order_cancel_requests WHERE id = ? AND status = 'pending'",
        [$requestId]
    );

    if (!$request) {
        return ['ok' => false, 'message' => 'That request has already been decided.'];
    }

    $orderId = (int)$request['order_id'];
    $order   = db_one('SELECT id, status FROM orders WHERE id = ?', [$orderId]);

    if (!$order) {
        return ['ok' => false, 'message' => 'That order no longer exists.'];
    }

    // Re-checked at the moment of approval, not at the moment of asking.
    // An order can be delivered while its cancellation sits in the queue,
    // and approving it then would cancel something already handed over.
    if ($order['status'] === 'delivered') {
        return [
            'ok'      => false,
            'message' => 'This order was delivered while the request was waiting. '
                       . 'It can no longer be cancelled -- reject the request instead.',
        ];
    }

    if ($order['status'] === 'cancelled') {
        return ['ok' => false, 'message' => 'That order is already cancelled.'];
    }

    db()->beginTransaction();

    try {
        // cancel_order() writes its own history entry and returns the
        // stock. Reused rather than reimplemented so an approved
        // cancellation is indistinguishable from the old direct one in
        // the audit trail.
        cancel_order($orderId, 'admin', $request['reason'], $request['note']);

        db_exec(
            "UPDATE order_cancel_requests
                SET status = 'approved', decided_by = ?, decided_at = NOW(), decision_note = ?
              WHERE id = ?",
            [current_user_id(), ($decisionNote === '' ? null : $decisionNote), $requestId]
        );

        db()->commit();

    } catch (\Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        error_log('Cancellation approval failed: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Could not cancel the order. Please try again.'];
    }

    forget_cancel_request($orderId);

    return ['ok' => true, 'message' => 'Order #' . $orderId . ' has been cancelled and its stock returned.'];
}

/**
 * Reject a request. The order is not touched at all.
 *
 * A note is required here but optional on approval, and that asymmetry is
 * deliberate: approving does what was asked, refusing does not, and the
 * person who asked deserves to know why.
 *
 * @return array{ok: bool, message: string}
 */
function reject_cancellation(int $requestId, string $decisionNote): array
{
    if (!can('orders.approve_cancel')) {
        return ['ok' => false, 'message' => 'Your role cannot decide cancellations.'];
    }

    if (trim($decisionNote) === '') {
        return ['ok' => false, 'message' => 'Please say why the request is being rejected.'];
    }

    $affected = db_exec(
        "UPDATE order_cancel_requests
            SET status = 'rejected', decided_by = ?, decided_at = NOW(), decision_note = ?
          WHERE id = ? AND status = 'pending'",
        [current_user_id(), trim($decisionNote), $requestId]
    );

    if ($affected === 0) {
        return ['ok' => false, 'message' => 'That request has already been decided.'];
    }

    $orderId = (int)db_value('SELECT order_id FROM order_cancel_requests WHERE id = ?', [$requestId]);
    forget_cancel_request($orderId);

    return ['ok' => true, 'message' => 'The cancellation request was rejected. The order is unchanged.'];
}
