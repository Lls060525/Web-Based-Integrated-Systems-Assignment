<?php
// ============================================================
// checkout_success.php - Stripe success callback.
// Verifies the payment with Stripe, then creates the order
// inside a transaction so stock and cart stay consistent.
// ============================================================

require_once __DIR__ . '/lib/init.php';

require_member();

$userId    = current_user_id();
$sessionId = get('session_id');

if ($sessionId === '') {
    redirect('/cart.php');
}

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

try {
    // ---------- 1. Verify with Stripe, never trust the query string ----------
    $session = \Stripe\Checkout\Session::retrieve($sessionId);

    if ($session->payment_status !== 'paid') {
        throw new RuntimeException('Payment was not completed.');
    }

    // The session must belong to the member who is logged in.
    if ((string)$session->client_reference_id !== (string)$userId) {
        throw new RuntimeException('This payment session does not belong to your account.');
    }

    // ---------- 2. Never create the same order twice ----------
    // The column arrives with database/migration_password_reset.sql.
    // Until then the duplicate guard is simply skipped.
    $hasSessionColumn = db_column_exists('orders', 'stripe_session_id');

    if ($hasSessionColumn) {
        $existing = db_value('SELECT id FROM orders WHERE stripe_session_id = ?', [$sessionId]);
        if ($existing) {
            flash_success('Your order #' . $existing . ' is already confirmed.');
            redirect('/orders.php');
        }
    }

    // ---------- Shipping address ----------
    // The address comes from the member's own address book, carried
    // across in Stripe metadata. We re-check ownership here rather
    // than trusting the value that came back over the wire.
    $addressId = isset($session->metadata->address_id)
        ? (int)$session->metadata->address_id
        : null;

    $address     = $addressId === null ? null : find_user_address($addressId, $userId);
    $shipping    = $address ? format_address($address) : 'No shipping address recorded';
    $hasAddressId = db_column_exists('orders', 'shipping_address_id');

    // ---------- How it was paid ----------
    // Asked BEFORE the transaction opens, deliberately.
    //
    // This is a second call to Stripe, over the network, and the
    // transaction below holds FOR UPDATE locks on product rows. Waiting
    // on a remote server while holding row locks is how a slow third
    // party turns into failed checkouts for everybody else, so the
    // network round trip happens while nothing is locked.
    //
    // It is a separate call rather than an expansion of the retrieve at
    // the top of this file because that one decides whether the payment
    // is real and belongs to this member. That check must stay as small
    // and as certain as possible; this is decoration, and both of these
    // functions swallow their own failures and return null. The worst
    // outcome is a receipt with no "Paid with" line.
    $paidWith = null;
    $expandedSession = stripe_session_with_payment($sessionId);

    if ($expandedSession !== null) {
        $paidWith = payment_details_from_session($expandedSession);
    }

    $hasPaymentCols = payment_method_ready();

    // ---------- 3. Create the order atomically ----------
    db()->beginTransaction();

    // FOR UPDATE locks the rows so two tabs cannot both spend the same stock.
    $sigColumn = db_column_exists('cart', 'options_signature')
        ? 'c.options_signature' : "'' AS options_signature";

    $items = db_all(
        "SELECT c.product_id, c.quantity, $sigColumn, p.price, p.stock
           FROM cart c
           JOIN products p ON p.id = c.product_id
          WHERE c.user_id = ?
          FOR UPDATE",
        [$userId]
    );

    if (count($items) === 0) {
        db()->rollBack();
        flash_success('Your order has already been recorded.');
        redirect('/orders.php');
    }

    $subtotal = 0.0;

    // One query for every line's options, rather than one per attribute
    // per line. This runs inside the order transaction, so keeping the
    // round trips down keeps the lock window short.
    spec_prefetch_options(array_column($items, 'product_id'));

    foreach ($items as $index => $item) {
        // Priced once, here, and then written into order_items. From this
        // point the chosen options are a snapshot: renaming or deleting an
        // option later must not rewrite what the customer actually bought.
        $chosen = spec_describe_signature((int)$item['product_id'], (string)$item['options_signature']);

        $items[$index]['unit_price']   = (float)$item['price'] + $chosen['delta'];
        $items[$index]['options_text'] = $chosen['label'];

        $subtotal += $items[$index]['unit_price'] * $item['quantity'];
    }

    // ---------- Voucher ----------
    // Revalidated here, not trusted from the session or from Stripe.
    // Between the payment page and this callback someone else may have
    // taken the last remaining use, so the checks run again.
    $voucherCode = $session->metadata->voucher_code ?? '';
    $applied     = $voucherCode === '' ? null : check_voucher($voucherCode, $userId, $subtotal);
    $voucher     = ($applied !== null && $applied['ok']) ? $applied['voucher'] : null;
    $discount    = $voucher !== null ? $applied['discount'] : 0.0;
    $afterVoucher = round($subtotal - $discount, 2);

    // ---------- Reward points ----------
    // Revalidated, like everything else. The balance may have changed
    // while the member was on Stripe's page.
    $wantPoints     = (int)($session->metadata->redeem_points ?? 0);
    $pointsCheck    = $wantPoints > 0
        ? check_points_redemption($userId, $wantPoints, $afterVoucher)
        : null;
    $usePoints      = ($pointsCheck !== null && $pointsCheck['ok']) ? $pointsCheck['points'] : 0;
    $pointsDiscount = $usePoints > 0 ? $pointsCheck['discount'] : 0.0;

    $total = round($afterVoucher - $pointsDiscount, 2);

    $hasVoucherCols = db_column_exists('orders', 'discount_amount');
    $hasPointsCols  = db_column_exists('orders', 'points_redeemed');

    // Build the INSERT from whichever optional columns exist, so the
    // checkout keeps working whether or not every migration has been run.
    $columns = ['user_id', 'total_amount', 'status', 'shipping_address'];
    $values  = [$userId, $total, 'pending', $shipping];

    if ($hasSessionColumn) {
        $columns[] = 'stripe_session_id';
        $values[]  = $sessionId;
    }

    if ($hasAddressId && $address !== null) {
        $columns[] = 'shipping_address_id';
        $values[]  = $address['id'];
    }

    // Written with the order rather than UPDATEd after it, so the row is
    // complete the first time it exists. The receipt email is sent after
    // the commit and reads the order back -- an UPDATE afterwards would
    // race with it and email a receipt missing the line it just gained.
    if ($hasPaymentCols && $paidWith !== null) {
        $columns[] = 'payment_method';
        $values[]  = $paidWith['method'];

        $columns[] = 'payment_detail';
        $values[]  = $paidWith['detail'];
    }

    if ($hasPointsCols) {
        $columns[] = 'points_redeemed';
        $values[]  = $usePoints;

        $columns[] = 'points_discount';
        $values[]  = $pointsDiscount;
    }

    if ($hasVoucherCols) {
        $columns[] = 'subtotal';
        $values[]  = $subtotal;

        $columns[] = 'discount_amount';
        $values[]  = $discount;

        if ($voucher !== null) {
            $columns[] = 'voucher_id';
            $values[]  = $voucher['id'];

            // Text snapshot, so a renamed or deleted voucher does not
            // rewrite what the receipt says was used.
            $columns[] = 'voucher_code';
            $values[]  = $voucher['code'];
        }
    }

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));

    db_exec(
        'INSERT INTO orders (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')',
        $values
    );

    $orderId = db_last_id();

    $snapshotOptions = db_column_exists('order_items', 'options_text');

    foreach ($items as $item) {
        // price_at_purchase carries the option deltas, exactly as the
        // customer was charged, and options_text records which options
        // those were. Both are snapshots for the same reason.
        if ($snapshotOptions) {
            db_exec(
                'INSERT INTO order_items (order_id, product_id, quantity, price_at_purchase, options_text)
                 VALUES (?, ?, ?, ?, ?)',
                [$orderId, $item['product_id'], $item['quantity'],
                 $item['unit_price'], $item['options_text'] !== '' ? $item['options_text'] : null]
            );
        } else {
            db_exec(
                'INSERT INTO order_items (order_id, product_id, quantity, price_at_purchase)
                 VALUES (?, ?, ?, ?)',
                [$orderId, $item['product_id'], $item['quantity'], $item['unit_price']]
            );
        }

        // deduct_stock() does the conditional UPDATE and writes the
        // movement ledger entry. It throws if another order took the
        // last units first, which rolls this whole transaction back.
        deduct_stock((int)$item['product_id'], (int)$item['quantity'], (int)$orderId);
    }

    // Claim the voucher inside the same transaction as the order, so a
    // usage limit hit rolls the whole thing back rather than leaving a
    // discounted order behind.
    if ($voucher !== null && $hasVoucherCols) {
        redeem_voucher((int)$voucher['id'], $userId, (int)$orderId, $discount);
    }

    // Spend the points inside the same transaction. If the balance no
    // longer covers it, the exception rolls the whole order back rather
    // than letting someone spend points they do not have.
    if ($usePoints > 0) {
        redeem_points($userId, $usePoints, (int)$orderId, $pointsDiscount);
    }

    // Award points on what was actually paid, not on the pre-discount
    // subtotal, so a discount cannot be farmed for extra points.
    $earned = award_points_for_order($userId, (int)$orderId, $total);

    if ($earned > 0 && $hasPointsCols) {
        db_exec('UPDATE orders SET points_earned = ? WHERE id = ?', [$earned, $orderId]);
    }

    db_exec('DELETE FROM cart WHERE user_id = ?', [$userId]);

    log_status_change((int)$orderId, null, 'pending', 'member', 'Order placed and paid.');

    db()->commit();

    clear_voucher_session();
    clear_points_session();

    // ---------- 4. E-Receipt ----------
    // Sent after the commit so a mail problem can never roll back a
    // paid order. Failure is logged and the member can resend later.
    try {
        send_receipt_email((int)$orderId);
    } catch (\Throwable $mailError) {
        error_log('Receipt email failed for order ' . $orderId . ': ' . $mailError->getMessage());
    }

    $successMessage = 'Payment successful. Your order #' . $orderId
                    . ' has been placed and your receipt has been sent.';

    if ($earned > 0) {
        $successMessage .= ' You earned ' . number_format($earned) . ' reward points.';
    }

    flash_success($successMessage);
    redirect('/orders.php');

} catch (\Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }

    error_log('Order processing failed: ' . $e->getMessage());

    // The visitor gets a safe message; the detail stays in the error log.
    flash_error('We could not finalise your order. No stock was deducted. Please contact support if you were charged.');
    redirect('/cart.php');
}
