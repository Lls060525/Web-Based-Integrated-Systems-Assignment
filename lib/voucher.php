<?php
// ============================================================
// lib/voucher.php
// Discount voucher validation, calculation and redemption.
//
// GOLDEN RULE: the discount is never taken from the browser.
// The applied code lives in the session, but the amount is
// recalculated from the database every single time it is shown
// or charged. A tampered form field can change nothing.
// ============================================================

/** True once the voucher tables exist (see the migration). */
function voucher_module_ready(): bool
{
    return db_table_exists('vouchers');
}

/** Look a voucher up by code. Codes are stored and compared uppercase. */
function find_voucher(string $code): ?array
{
    if (!voucher_module_ready() || $code === '') {
        return null;
    }

    return db_one('SELECT * FROM vouchers WHERE code = ?', [strtoupper(trim($code))]) ?: null;
}

/** How many times this member has already redeemed this voucher. */
function voucher_uses_by_user(int $voucherId, int $userId): int
{
    if (!voucher_module_ready()) {
        return 0;
    }

    return (int)db_value(
        'SELECT COUNT(*) FROM voucher_redemptions WHERE voucher_id = ? AND user_id = ?',
        [$voucherId, $userId]
    );
}

/**
 * Check whether a voucher may be used right now, by this member,
 * on a basket of this size.
 *
 * @return array{ok: bool, reason: string, voucher: ?array, discount: float}
 */
function check_voucher(string $code, int $userId, float $subtotal): array
{
    $fail = static fn(string $why): array =>
        ['ok' => false, 'reason' => $why, 'voucher' => null, 'discount' => 0.0];

    if (!voucher_module_ready()) {
        return $fail('Vouchers are not available yet.');
    }

    $v = find_voucher($code);

    if (!$v) {
        return $fail('That voucher code was not recognised.');
    }

    if ($v['status'] !== 'active') {
        return $fail('This voucher is no longer active.');
    }

    $now = time();

    if (!empty($v['starts_at']) && strtotime($v['starts_at']) > $now) {
        return $fail('This voucher is not valid yet. It starts on ' . fmt_date($v['starts_at']) . '.');
    }

    if (!empty($v['expires_at']) && strtotime($v['expires_at']) < $now) {
        return $fail('This voucher expired on ' . fmt_date($v['expires_at']) . '.');
    }

    if ($v['usage_limit'] !== null && (int)$v['used_count'] >= (int)$v['usage_limit']) {
        return $fail('This voucher has been fully redeemed.');
    }

    if ((int)$v['per_user_limit'] > 0
        && voucher_uses_by_user((int)$v['id'], $userId) >= (int)$v['per_user_limit']) {
        return $fail('You have already used this voucher the maximum number of times.');
    }

    if ($subtotal < (float)$v['min_spend']) {
        return $fail('Spend at least ' . money($v['min_spend']) . ' to use this voucher. '
                   . 'Your subtotal is ' . money($subtotal) . '.');
    }

    $discount = calculate_discount($v, $subtotal);

    if ($discount <= 0) {
        return $fail('This voucher does not reduce the price of your current basket.');
    }

    return ['ok' => true, 'reason' => '', 'voucher' => $v, 'discount' => $discount];
}

/**
 * Work out the money off, given the voucher and a subtotal.
 * Rounded to sen, and never more than the subtotal itself.
 */
function calculate_discount(array $voucher, float $subtotal): float
{
    if ($voucher['type'] === 'percent') {
        $discount = $subtotal * ((float)$voucher['value'] / 100);

        if ($voucher['max_discount'] !== null) {
            $discount = min($discount, (float)$voucher['max_discount']);
        }
    } else {
        $discount = (float)$voucher['value'];
    }

    // Never discount below zero, and never more than the basket is worth.
    return round(min($discount, $subtotal), 2);
}

/** Short human description, e.g. "10% off (max RM 100.00)". */
function voucher_summary(array $v): string
{
    if ($v['type'] === 'percent') {
        $text = rtrim(rtrim(number_format((float)$v['value'], 2), '0'), '.') . '% off';

        if ($v['max_discount'] !== null) {
            $text .= ' (max ' . money($v['max_discount']) . ')';
        }
    } else {
        $text = money($v['value']) . ' off';
    }

    if ((float)$v['min_spend'] > 0) {
        $text .= ', min spend ' . money($v['min_spend']);
    }

    return $text;
}

// ------------------------------------------------------------
// Session handling
// ------------------------------------------------------------
// Only the CODE is remembered. The discount is recalculated from
// the database on every request, so a stale session can never
// produce a stale price.

function apply_voucher_to_session(string $code): void
{
    $_SESSION['voucher_code'] = strtoupper(trim($code));
}

function clear_voucher_session(): void
{
    unset($_SESSION['voucher_code']);
}

function session_voucher_code(): string
{
    return $_SESSION['voucher_code'] ?? '';
}

/**
 * The voucher currently applied to this member's basket, revalidated.
 * Returns null (and quietly forgets the code) when it no longer applies,
 * for example because the basket dropped below the minimum spend.
 *
 * @return array{voucher: array, discount: float}|null
 */
function active_voucher(int $userId, float $subtotal): ?array
{
    $code = session_voucher_code();

    if ($code === '') {
        return null;
    }

    $check = check_voucher($code, $userId, $subtotal);

    if (!$check['ok']) {
        return null;
    }

    return ['voucher' => $check['voucher'], 'discount' => $check['discount']];
}

// ------------------------------------------------------------
// Redemption
// ------------------------------------------------------------

/**
 * Claim one use of a voucher.
 *
 * The UPDATE carries the limit check in its WHERE clause, so two
 * members checking out at the same moment cannot both take the last
 * remaining use. Zero affected rows means we lost the race.
 *
 * MUST be called inside the same transaction that creates the order.
 *
 * @throws RuntimeException when the voucher is no longer claimable
 */
function redeem_voucher(int $voucherId, int $userId, int $orderId, float $discount): void
{
    $claimed = db_exec(
        'UPDATE vouchers
            SET used_count = used_count + 1
          WHERE id = ?
            AND status = \'active\'
            AND (usage_limit IS NULL OR used_count < usage_limit)',
        [$voucherId]
    );

    if ($claimed === 0) {
        throw new RuntimeException('This voucher was fully redeemed while you were checking out.');
    }

    db_exec(
        'INSERT INTO voucher_redemptions (voucher_id, user_id, order_id, discount_amount)
         VALUES (?, ?, ?, ?)',
        [$voucherId, $userId, $orderId, $discount]
    );
}

/** Give a use back, e.g. when an order is cancelled. */
function release_voucher(int $orderId): void
{
    if (!voucher_module_ready()) {
        return;
    }

    $row = db_one('SELECT voucher_id FROM voucher_redemptions WHERE order_id = ?', [$orderId]);

    if (!$row) {
        return;
    }

    db_exec(
        'UPDATE vouchers SET used_count = GREATEST(used_count - 1, 0) WHERE id = ?',
        [$row['voucher_id']]
    );

    db_exec('DELETE FROM voucher_redemptions WHERE order_id = ?', [$orderId]);
}

/** Vouchers a member could still use, for the "available" list. */
function available_vouchers(int $userId): array
{
    if (!voucher_module_ready()) {
        return [];
    }

    // The per-user check is a correlated subquery in the WHERE clause
    // rather than a HAVING on a select alias: HAVING without GROUP BY
    // is rejected under ONLY_FULL_GROUP_BY, which MySQL 8 enables by
    // default, so the alias form would break on a stricter server.
    return db_all(
        "SELECT v.*
           FROM vouchers v
          WHERE v.status = 'active'
            AND (v.starts_at  IS NULL OR v.starts_at  <= NOW())
            AND (v.expires_at IS NULL OR v.expires_at >= NOW())
            AND (v.usage_limit IS NULL OR v.used_count < v.usage_limit)
            AND (
                  v.per_user_limit = 0
                  OR (SELECT COUNT(*) FROM voucher_redemptions r
                       WHERE r.voucher_id = v.id AND r.user_id = ?) < v.per_user_limit
                )
          ORDER BY v.min_spend ASC, v.id DESC",
        [$userId]
    );
}
