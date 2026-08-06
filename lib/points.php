<?php
// ============================================================
// lib/points.php
// Reward points, kept as an append-only ledger.
//
// There is deliberately NO points_balance column on users.
// The balance is always SUM(points) from point_transactions:
//   - a cached column drifts the first time an update half-fails,
//     and once it has drifted nothing can say which entry was wrong
//   - the ledger is auditable: every point has a source and a time
// ============================================================

/** True once the ledger table exists (see the migration). */
function points_module_ready(): bool
{
    return db_table_exists('point_transactions');
}

// ------------------------------------------------------------
// Reading
// ------------------------------------------------------------

/** Current balance for a member. The ledger is the only source of truth. */
function points_balance(?int $userId = null): int
{
    if (!points_module_ready()) {
        return 0;
    }

    $userId ??= current_user_id();

    if ($userId === null) {
        return 0;
    }

    return (int)db_value(
        'SELECT COALESCE(SUM(points), 0) FROM point_transactions WHERE user_id = ?',
        [$userId]
    );
}

/** The member's ledger, newest first. */
function points_history(int $userId, int $limit = 100): array
{
    if (!points_module_ready()) {
        return [];
    }

    return db_all(
        'SELECT t.*, u.name AS admin_name
           FROM point_transactions t
           LEFT JOIN users u ON u.id = t.created_by
          WHERE t.user_id = ?
          ORDER BY t.id DESC
          LIMIT ' . max(1, min(500, $limit)),
        [$userId]
    );
}

/** Lifetime totals, for the summary tiles. */
function points_summary(int $userId): array
{
    if (!points_module_ready()) {
        return ['balance' => 0, 'earned' => 0, 'redeemed' => 0];
    }

    $row = db_one(
        'SELECT
            COALESCE(SUM(points), 0)                             AS balance,
            COALESCE(SUM(CASE WHEN points > 0 THEN points END), 0) AS earned,
            COALESCE(SUM(CASE WHEN points < 0 THEN -points END), 0) AS redeemed
         FROM point_transactions
         WHERE user_id = ?',
        [$userId]
    );

    return [
        'balance'  => (int)$row['balance'],
        'earned'   => (int)$row['earned'],
        'redeemed' => (int)$row['redeemed'],
    ];
}

// ------------------------------------------------------------
// Conversion
// ------------------------------------------------------------

/** Money value of a number of points. */
function points_to_money(int $points): float
{
    return round($points / POINTS_PER_RM_REDEEMED, 2);
}

/** Points earned by spending an amount, rounded down. */
function points_for_spend(float $amount): int
{
    return (int)floor($amount * POINTS_EARNED_PER_RM);
}

/**
 * The largest number of points that may be spent on an order.
 * Bounded by three things: the member's balance, the percentage cap,
 * and the order total itself. Always a whole multiple of the step.
 */
function max_redeemable_points(int $userId, float $payable): int
{
    if (!points_module_ready() || $payable <= 0) {
        return 0;
    }

    $balance = points_balance($userId);

    // The share of the order that points are allowed to cover.
    $capMoney = $payable * (POINTS_MAX_REDEEM_PERCENT / 100);
    $capPoints = (int)floor($capMoney * POINTS_PER_RM_REDEEMED);

    $max = min($balance, $capPoints);

    // Round down to a whole block so the discount is never a fraction of a sen.
    $max = (int)(floor($max / POINTS_REDEEM_STEP) * POINTS_REDEEM_STEP);

    return max(0, $max);
}

/**
 * Validate a requested redemption.
 *
 * @return array{ok: bool, reason: string, points: int, discount: float}
 */
function check_points_redemption(int $userId, int $points, float $payable): array
{
    $fail = static fn(string $why): array =>
        ['ok' => false, 'reason' => $why, 'points' => 0, 'discount' => 0.0];

    if (!points_module_ready()) {
        return $fail('Reward points are not available yet.');
    }

    if ($points <= 0) {
        return $fail('Enter how many points you would like to use.');
    }

    if ($points % POINTS_REDEEM_STEP !== 0) {
        return $fail('Points must be used in blocks of ' . POINTS_REDEEM_STEP . '.');
    }

    if ($points < POINTS_MIN_REDEEM) {
        return $fail('The smallest redemption is ' . POINTS_MIN_REDEEM . ' points.');
    }

    $balance = points_balance($userId);

    if ($points > $balance) {
        return $fail('You only have ' . number_format($balance) . ' points.');
    }

    $max = max_redeemable_points($userId, $payable);

    if ($points > $max) {
        return $fail('You can use at most ' . number_format($max) . ' points on this order '
                   . '(up to ' . POINTS_MAX_REDEEM_PERCENT . '% of the total).');
    }

    return [
        'ok'       => true,
        'reason'   => '',
        'points'   => $points,
        'discount' => points_to_money($points),
    ];
}

// ------------------------------------------------------------
// Session handling
// ------------------------------------------------------------
// Only the number of points is remembered. The money value and every
// limit are recalculated from the database on each request.

function apply_points_to_session(int $points): void
{
    $_SESSION['redeem_points'] = $points;
}

function clear_points_session(): void
{
    unset($_SESSION['redeem_points']);
}

function session_redeem_points(): int
{
    return (int)($_SESSION['redeem_points'] ?? 0);
}

/**
 * The redemption currently attached to the basket, revalidated.
 * Returns null (and forgets it) when it no longer fits, for example
 * because the basket shrank or a voucher already reduced the total.
 *
 * @return array{points: int, discount: float}|null
 */
function active_points_redemption(int $userId, float $payable): ?array
{
    $points = session_redeem_points();

    if ($points <= 0) {
        return null;
    }

    $check = check_points_redemption($userId, $points, $payable);

    if (!$check['ok']) {
        return null;
    }

    return ['points' => $check['points'], 'discount' => $check['discount']];
}

// ------------------------------------------------------------
// Writing
// ------------------------------------------------------------

/**
 * Append one entry to the ledger.
 *
 * Must run inside a transaction when it is part of a larger operation.
 * `balance_after` is a readability snapshot only; nothing calculates
 * from it, so a stale value can never corrupt a balance.
 *
 * @throws PDOException when UNIQUE(order_id, type) rejects a duplicate
 */
function add_point_transaction(
    int $userId,
    string $type,
    int $points,
    string $description,
    ?int $orderId = null,
    ?int $createdBy = null
): void {
    if (!points_module_ready()) {
        return;
    }

    $balanceAfter = points_balance($userId) + $points;

    db_exec(
        'INSERT INTO point_transactions
                (user_id, order_id, type, points, balance_after, description, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$userId, $orderId, $type, $points, $balanceAfter, $description, $createdBy]
    );
}

/**
 * Spend points on an order.
 *
 * The balance is re-read here rather than trusted from the caller,
 * so a stale session or a slow second tab cannot overspend.
 *
 * @throws RuntimeException when the balance no longer covers it
 */
function redeem_points(int $userId, int $points, int $orderId, float $discount): void
{
    if ($points <= 0) {
        return;
    }

    if (points_balance($userId) < $points) {
        throw new RuntimeException('Your point balance changed and no longer covers this redemption.');
    }

    add_point_transaction(
        $userId,
        'redeem',
        -$points,
        'Redeemed on order #' . $orderId . ' (' . money($discount) . ' off)',
        $orderId
    );
}

/** Award points for an order. The UNIQUE key makes this safe to retry. */
function award_points_for_order(int $userId, int $orderId, float $amountPaid): int
{
    if (!points_module_ready()) {
        return 0;
    }

    $points = points_for_spend($amountPaid);

    if ($points <= 0) {
        return 0;
    }

    try {
        add_point_transaction(
            $userId,
            'earn',
            $points,
            'Earned from order #' . $orderId,
            $orderId
        );
    } catch (\PDOException $e) {
        // UNIQUE(order_id, type) rejected it: already awarded. Not an error.
        return 0;
    }

    return $points;
}

/**
 * Undo an order's points when it is cancelled:
 * take back what was earned, and give back what was spent.
 */
function reverse_order_points(int $orderId): void
{
    if (!points_module_ready()) {
        return;
    }

    $rows = db_all(
        "SELECT user_id, type, points FROM point_transactions
          WHERE order_id = ? AND type IN ('earn','redeem')",
        [$orderId]
    );

    foreach ($rows as $row) {
        $type = $row['type'] === 'earn' ? 'reverse' : 'refund';

        $description = $row['type'] === 'earn'
            ? 'Points removed, order #' . $orderId . ' was cancelled'
            : 'Points returned, order #' . $orderId . ' was cancelled';

        try {
            add_point_transaction(
                (int)$row['user_id'],
                $type,
                -(int)$row['points'],   // mirror image of the original entry
                $description,
                $orderId
            );
        } catch (\PDOException $e) {
            // Already reversed. The UNIQUE key did its job.
            continue;
        }
    }
}

/** Labels and styling for the ledger view. */
function point_type_label(string $type): string
{
    return match ($type) {
        'earn'    => 'Earned',
        'redeem'  => 'Redeemed',
        'refund'  => 'Refunded',
        'reverse' => 'Reversed',
        'adjust'  => 'Adjustment',
        default   => ucfirst($type),
    };
}
