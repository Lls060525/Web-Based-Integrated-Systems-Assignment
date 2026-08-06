<?php
// ============================================================
// api/cart_action.php - AJAX endpoint for the shopping cart
// Always responds with JSON.
// ============================================================

require_once __DIR__ . '/../lib/init.php';

header('Content-Type: application/json; charset=utf-8');

/** Send a JSON response and stop. */
function json_out(array $payload): void
{
    echo json_encode($payload);
    exit;
}

// ---------- Authorization ----------
if (!is_member()) {
    json_out([
        'status'   => 'error',
        'message'  => 'Please log in as a member to shop.',
        'redirect' => '/auth/login.php',
    ]);
}

// ---------- CSRF ----------
// main.js sends the token from the <meta name="csrf-token"> tag.
if (!is_post() || !csrf_valid()) {
    json_out(['status' => 'error', 'message' => 'Invalid request. Please refresh the page.']);
}

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

// ---------- Add or increment ----------
$line = db_one('SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ?', [$userId, $productId]);

if ($line) {
    if ((int)$line['quantity'] >= (int)$product['stock']) {
        json_out(['status' => 'error', 'message' => 'You already have every unit we have in stock.']);
    }
    db_exec('UPDATE cart SET quantity = quantity + 1 WHERE id = ?', [$line['id']]);
} else {
    db_exec('INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, 1)', [$userId, $productId]);
}

$cartCount = (int)db_value('SELECT COALESCE(SUM(quantity), 0) FROM cart WHERE user_id = ?', [$userId]);

json_out([
    'status'     => 'success',
    'message'    => $product['name'] . ' added to your cart.',
    'cart_count' => $cartCount,
]);
