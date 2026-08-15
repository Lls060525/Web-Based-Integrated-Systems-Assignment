<?php
// ============================================================
// api/cart_update.php - change the quantity on one cart line
// Always responds with JSON.
//
// Exists to stop the cart jumping. The quantity box used to submit the
// whole form, which meant a full page load for a single digit: the
// scroll position reset, a "Cart updated." banner appeared at the top
// and pushed everything down, and the table re-laid itself out. Three
// separate movements for one click.
//
// The response carries every number the page displays, so the browser
// can patch them in place rather than guess or recalculate. Prices are
// never computed in JavaScript -- a total the client worked out for
// itself is a total that can disagree with checkout.
// ============================================================

require_once __DIR__ . '/../lib/init.php';

ajax_guard_post('member');

$userId   = current_user_id();
$cartId   = post_int('cart_id');
$quantity = post_int('quantity');

if ($cartId === null || $quantity === null) {
    json_error('Invalid request.');
}

$result = cart_set_quantity($userId, $cartId, $quantity);

if (!$result['ok']) {
    json_error($result['message']);
}

// Reloaded rather than adjusted arithmetically. The line that changed is
// not necessarily the only thing that moved -- an option's price delta
// may have been edited by an admin since the page was drawn -- and this
// is the same function that renders the page, so the two cannot drift.
$cart = cart_load($userId);

$line = null;

foreach ($cart['items'] as $item) {
    if ((int)$item['cart_id'] === $cartId) {
        $line = $item;
        break;
    }
}

if ($line === null) {
    json_error('That item is no longer in your cart.');
}

json_ok([
    'cart_id'     => $cartId,
    'quantity'    => (int)$line['quantity'],
    'clamped'     => $result['clamped'],
    'message'     => $result['message'],

    // Formatted server-side so the currency symbol, the thousands
    // separator and the rounding are identical to every other price on
    // the site.
    'line_total'  => money($line['line_total']),
    'total_price' => money($cart['total_price']),
    'total_items' => $cart['total_items'],

    // Drives the badge in the header.
    'cart_count'  => $cart['total_items'],
]);
