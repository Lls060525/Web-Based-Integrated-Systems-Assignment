<?php
// ============================================================
// api/voucher_action.php - AJAX endpoint for the voucher box
//
// Applies or removes a code. The response always carries the
// freshly recalculated totals, so the page never has to do its
// own arithmetic.
// ============================================================

require_once __DIR__ . '/../lib/init.php';

// One call replaces the role check, the POST check and the CSRF
// check that used to be copy-pasted into every endpoint.
ajax_guard_post('member');

if (!voucher_module_ready()) {
    json_out([
        'status'  => 'error',
        'message' => 'Vouchers are not set up yet. Run database/migration_10_voucher.sql.',
    ]);
}

$userId = current_user_id();

// ---------- The subtotal always comes from the database ----------
// Never from the request, or a tampered value would buy a discount.
$subtotal = (float)db_value(
    "SELECT COALESCE(SUM(p.price * c.quantity), 0)
       FROM cart c
       JOIN products p ON p.id = c.product_id
      WHERE c.user_id = ? AND p.status = 'active'",
    [$userId]
);

$action = post('action');

// ---------- Remove ----------
if ($action === 'remove') {
    clear_voucher_session();

    json_out([
        'status'   => 'success',
        'applied'  => false,
        'message'  => 'Voucher removed.',
        'subtotal' => money($subtotal),
        'discount' => money(0),
        'total'    => money($subtotal),
    ]);
}

// ---------- Apply ----------
if ($action !== 'apply') {
    json_out(['status' => 'error', 'message' => 'Invalid request.']);
}

$code = post('code');

if ($code === '') {
    json_out(['status' => 'error', 'message' => 'Please enter a voucher code.']);
}

if ($subtotal <= 0) {
    json_out(['status' => 'error', 'message' => 'Your cart is empty.']);
}

$check = check_voucher($code, $userId, $subtotal);

if (!$check['ok']) {
    json_out(['status' => 'error', 'message' => $check['reason']]);
}

apply_voucher_to_session($check['voucher']['code']);

json_out([
    'status'       => 'success',
    'applied'      => true,
    'code'         => $check['voucher']['code'],
    'summary'      => voucher_summary($check['voucher']),
    'message'      => 'Voucher ' . $check['voucher']['code'] . ' applied. You saved '
                    . money($check['discount']) . '.',
    'subtotal'     => money($subtotal),
    'discount'     => money($check['discount']),
    'total'        => money($subtotal - $check['discount']),
]);
