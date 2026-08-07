<?php
// ============================================================
// api/cart_action.php - AJAX endpoint for the shopping cart
// Always responds with JSON.
// ============================================================

require_once __DIR__ . '/../lib/init.php';

// One call replaces the role check, the POST check and the CSRF
// check that used to be copy-pasted into every endpoint.
ajax_guard_post('member');

$userId    = current_user_id();
$action    = post('action');
$productId = post_int('product_id');

if ($action !== 'add' || $productId === null) {
    json_out(['status' => 'error', 'message' => 'Invalid request.']);
}

// ---------- Stock check ----------
$product = db_one(
    "SELECT id, name, stock FROM products WHERE id = ? AND status = 'active'",
    [$productId]
);

if (!$product) {
    json_out(['status' => 'error', 'message' => 'This product is no longer available.']);
}

if ((int)$product['stock'] <= 0) {
    json_out(['status' => 'error', 'message' => 'This product is out of stock.']);
}

// ---------- Customer-selected specs ----------
// Never trusts what was posted: every choice is re-checked against the
// options the product actually offers, and a product that offers a
// choice will not go into the cart without one.
$posted    = is_array($_POST['options'] ?? null) ? $_POST['options'] : [];
$selection = spec_validate_selection($productId, $posted);

if (!$selection['ok']) {
    json_out(['status' => 'error', 'message' => $selection['error']]);
}

$signature = $selection['signature'];

// ---------- Add or increment ----------
// The signature is part of the line identity. Black 256GB and White
// 256GB are different things and must not merge into one row.
$hasOptions = db_column_exists('cart', 'options_signature');

$line = $hasOptions
    ? db_one('SELECT id, quantity FROM cart
               WHERE user_id = ? AND product_id = ? AND options_signature = ?',
             [$userId, $productId, $signature])
    : db_one('SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ?',
             [$userId, $productId]);

if ($line) {
    if ((int)$line['quantity'] >= (int)$product['stock']) {
        json_out(['status' => 'error', 'message' => 'You already have every unit we have in stock.']);
    }
    db_exec('UPDATE cart SET quantity = quantity + 1 WHERE id = ?', [$line['id']]);
} elseif ($hasOptions) {
    db_exec('INSERT INTO cart (user_id, product_id, quantity, options_signature) VALUES (?, ?, 1, ?)',
            [$userId, $productId, $signature]);
} else {
    db_exec('INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, 1)', [$userId, $productId]);
}

$cartCount = (int)db_value('SELECT COALESCE(SUM(quantity), 0) FROM cart WHERE user_id = ?', [$userId]);

$addedName = $product['name'] . ($selection['label'] !== '' ? ' (' . $selection['label'] . ')' : '');

json_out([
    'status'     => 'success',
    'message'    => $addedName . ' added to your cart.',
    'cart_count' => $cartCount,
]);
