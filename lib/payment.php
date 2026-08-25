<?php
// ============================================================
// lib/payment.php
// Naming the way an order was paid.
//
// checkout.php offers card, FPX and GrabPay. Stripe knows which one
// was used; this turns its machine codes into the words a Malaysian
// customer would recognise on their own bank statement:
//
//     fpx  + maybank2u  ->  "FPX (Maybank2u)"
//     card + visa/4242  ->  "Visa ****4242"
//     grabpay           ->  "GrabPay"
//
// WHY THE LOOKUP CANNOT BE ALLOWED TO FAIL LOUDLY
//
// It runs immediately after a customer has been charged. If asking
// Stripe a cosmetic follow-up question threw, and that exception
// escaped, checkout_success.php would roll back an order that has
// genuinely been paid for -- turning a decorative feature into lost
// money and a support ticket. So every function here swallows its own
// failures and returns null, and a null simply means the receipt omits
// a line. Nothing about the order depends on the answer.
// ============================================================

/** True once migration_30_payment_method.sql has been run. */
function payment_method_ready(): bool
{
    static $ready = null;

    if ($ready === null) {
        $ready = db_column_exists('orders', 'payment_method');
    }

    return $ready;
}

/**
 * Malaysian banks that FPX supports, as Stripe names them.
 *
 * Stripe returns a lowercase code; nobody wants to read "maybank2u"
 * on a receipt as "maybank2u". A code missing from this list is
 * title-cased as a fallback rather than dropped, so a bank Stripe adds
 * later still produces something sensible without a code change.
 *
 * @return array<string, string>
 */
function fpx_bank_names(): array
{
    // All 25 codes Stripe documents for fpx.bank, checked against
    // https://docs.stripe.com/api/payment_methods/object (fpx.bank enum).
    return [
        'affin_bank'         => 'Affin Bank',
        'agrobank'           => 'Agrobank',
        'alliance_bank'      => 'Alliance Bank',
        'ambank'             => 'AmBank',
        'bank_islam'         => 'Bank Islam',
        'bank_muamalat'      => 'Bank Muamalat',
        'bank_of_china'      => 'Bank of China',
        'bank_rakyat'        => 'Bank Rakyat',
        'bnp_paribas'        => 'BNP Paribas',
        'bsn'                => 'Bank Simpanan Nasional',
        'cimb'               => 'CIMB Clicks',
        'citibank'           => 'Citibank',
        'deutsche_bank'      => 'Deutsche Bank',
        'hong_leong_bank'    => 'Hong Leong Bank',
        'hsbc'               => 'HSBC Bank',
        'kfh'                => 'Kuwait Finance House',
        'maybank2e'          => 'Maybank2E',
        'maybank2u'          => 'Maybank2u',
        'mbsb_bank'          => 'MBSB Bank',
        'ocbc'               => 'OCBC Bank',
        'pb_enterprise'      => 'Public Bank Enterprise',
        'public_bank'        => 'Public Bank',
        'rhb'                => 'RHB Bank',
        'standard_chartered' => 'Standard Chartered',
        'uob'                => 'UOB Bank',
    ];
}

/** The families Stripe may report, in words. */
function payment_family_label(string $method): string
{
    return match ($method) {
        'card'    => 'Card',
        'fpx'     => 'FPX Online Banking',
        'grabpay' => 'GrabPay',
        'paynow'  => 'PayNow',
        ''        => 'Payment',
        // Anything new arrives readable rather than raw.
        default   => ucwords(str_replace('_', ' ', $method)),
    };
}

/**
 * Read the method off a completed Stripe Checkout session.
 *
 * The session must have been retrieved WITH the payment method
 * expanded -- see stripe_session_with_payment() below. Stripe returns
 * only an id otherwise, and reading ->type off an id string yields
 * nothing.
 *
 * @return array{method: string, detail: string|null}|null
 */
function payment_details_from_session(object $session): ?array
{
    try {
        $intent = $session->payment_intent ?? null;

        // Not expanded, or no intent at all (a zero-value order).
        if (!is_object($intent)) {
            return null;
        }

        $pm = $intent->payment_method ?? null;

        if (!is_object($pm) || empty($pm->type)) {
            return null;
        }

        $type = (string)$pm->type;

        return [
            'method' => $type,
            'detail' => payment_instrument_detail($pm, $type),
        ];

    } catch (\Throwable $e) {
        // Cosmetic. See the note at the top of this file.
        error_log('Could not read payment method from session: ' . $e->getMessage());

        return null;
    }
}

/**
 * The human-readable instrument, per family.
 *
 * Returns null when the family carries no useful detail -- GrabPay is
 * just GrabPay, and "GrabPay (GrabPay)" would be silly.
 */
function payment_instrument_detail(object $pm, string $type): ?string
{
    if ($type === 'fpx') {
        $code = (string)($pm->fpx->bank ?? '');

        if ($code === '') {
            return null;
        }

        return fpx_bank_names()[$code] ?? ucwords(str_replace('_', ' ', $code));
    }

    if ($type === 'card') {
        $brand = (string)($pm->card->brand ?? '');
        $last4 = (string)($pm->card->last4 ?? '');

        $brand = $brand === '' ? '' : ucfirst($brand);

        if ($brand === '' && $last4 === '') {
            return null;
        }

        // Four asterisks, not the real number: this string is stored,
        // emailed and printed, and a receipt is not a place for a card
        // number. Stripe never sends us the full one anyway.
        return trim($brand . ($last4 !== '' ? ' ****' . $last4 : ''));
    }

    return null;
}

/**
 * Retrieve a Checkout session with enough expanded to see the method.
 *
 * Separate from the plain retrieve in checkout_success.php so that an
 * expansion Stripe refuses cannot stop the order being confirmed: the
 * caller keeps its own unexpanded session for the checks that matter,
 * and this is asked separately for the decoration.
 */
function stripe_session_with_payment(string $sessionId): ?object
{
    try {
        return \Stripe\Checkout\Session::retrieve([
            'id'     => $sessionId,
            'expand' => ['payment_intent.payment_method'],
        ]);

    } catch (\Throwable $e) {
        error_log('Could not expand payment method for session ' . $sessionId . ': ' . $e->getMessage());

        return null;
    }
}

/**
 * Store the method against an order.
 *
 * Silent when the migration has not been run, matching how every other
 * optional column in this project behaves.
 */
function record_order_payment(int $orderId, ?array $payment): void
{
    if ($payment === null || !payment_method_ready()) {
        return;
    }

    db_exec(
        'UPDATE orders SET payment_method = ?, payment_detail = ? WHERE id = ?',
        [$payment['method'], $payment['detail'], $orderId]
    );
}

/**
 * The one-line description for a receipt or an order page.
 *
 * Returns null when nothing was recorded, which is true of every order
 * placed before this feature existed. The templates test for null and
 * leave the row out entirely -- an empty "Paid with:" label reads like
 * a bug, whereas no row reads like nothing happened, which is correct.
 */
function payment_method_label(array $order): ?string
{
    $method = trim((string)($order['payment_method'] ?? ''));

    if ($method === '') {
        return null;
    }

    $family = payment_family_label($method);
    $detail = trim((string)($order['payment_detail'] ?? ''));

    if ($detail === '') {
        return $family;
    }

    // "Visa ****4242" already names its family; "FPX (Maybank2u)" needs
    // both halves because the bank alone does not say it was FPX.
    if ($method === 'card') {
        return $detail;
    }

    return $family . ' (' . $detail . ')';
}

/** A Font Awesome icon for the family, for the order pages. */
function payment_method_icon(string $method): string
{
    return match ($method) {
        'card'    => 'fa-credit-card',
        'fpx'     => 'fa-building-columns',
        'grabpay' => 'fa-wallet',
        default   => 'fa-receipt',
    };
}
