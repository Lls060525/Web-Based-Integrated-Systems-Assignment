<?php
// ============================================================
// api/points_action.php - AJAX endpoint for point redemption
//
// Returns the recalculated totals, so the page never does its own
// arithmetic and a tampered request cannot buy a bigger discount.
// ============================================================

require_once __DIR__ . '/../lib/init.php';

header('Content-Type: application/json; charset=utf-8');

function points_json(array $payload): void
{
    echo json_encode($payload);
    exit;
}

if (!is_member()) {
    points_json([
        'status'   => 'error',
        'message'  => 'Please log in as a member to use reward points.',
        'redirect' => '/auth/login.php',
    ]);
}

if (!is_post() || !csrf_valid()) {
    points_json(['status' => 'error', 'message' => 'Invalid request. Please refresh the page.']);
}

if (!points_module_ready()) {
    points_json([
        'status'  => 'error',
        'message' => 'Reward points are not set up yet. Run database/migration_12_points.sql.',
    ]);
}

$userId = current_user_id();

// ---------- Every figure is recomputed server side ----------
$subtotal = (float)db_value(
    "SELECT COALESCE(SUM(p.price * c.quantity), 0)
       FROM cart c
       JOIN products p ON p.id = c.product_id
      WHERE c.user_id = ? AND p.status = 'active'",
    [$userId]
);

// A voucher, if any, is applied before points.
$voucher         = active_voucher($userId, $subtotal);
$voucherDiscount = $voucher['discount'] ?? 0.0;
$afterVoucher    = round($subtotal - $voucherDiscount, 2);

$action = post('action');

/** Build the standard totals payload. */
function points_totals(float $subtotal, float $voucherDiscount, float $pointsDiscount): array
{
    return [
        'subtotal'        => money($subtotal),
        'voucher_discount'=> money($voucherDiscount),
        'points_discount' => money($pointsDiscount),
        'total'           => money(round($subtotal - $voucherDiscount - $pointsDiscount, 2)),
    ];
}

// ---------- Remove ----------
if ($action === 'remove') {
    clear_points_session();

    points_json(array_merge([
        'status'    => 'success',
        'applied'   => false,
        'message'   => 'Reward points removed.',
        'balance'   => number_format(points_balance($userId)),
        'max_points'=> max_redeemable_points($userId, $afterVoucher),
    ], points_totals($subtotal, $voucherDiscount, 0.0)));
}

// ---------- Apply ----------
if ($action !== 'apply') {
    points_json(['status' => 'error', 'message' => 'Invalid request.']);
}

if ($subtotal <= 0) {
    points_json(['status' => 'error', 'message' => 'Your cart is empty.']);
}

$points = post_int('points') ?? 0;
$check  = check_points_redemption($userId, $points, $afterVoucher);

if (!$check['ok']) {
    points_json(['status' => 'error', 'message' => $check['reason']]);
}

apply_points_to_session($check['points']);

points_json(array_merge([
    'status'     => 'success',
    'applied'    => true,
    'points'     => $check['points'],
    'message'    => number_format($check['points']) . ' points applied. You saved '
                  . money($check['discount']) . '.',
    'balance'    => number_format(points_balance($userId)),
    'max_points' => max_redeemable_points($userId, $afterVoucher),
], points_totals($subtotal, $voucherDiscount, $check['discount'])));
