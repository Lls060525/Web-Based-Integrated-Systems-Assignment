<?php
// ============================================================
// api/wishlist_action.php - AJAX endpoint for the wishlist heart
// Always responds with JSON.
// ============================================================

require_once __DIR__ . '/../lib/init.php';

header('Content-Type: application/json; charset=utf-8');

/** Send a JSON response and stop. */
function wishlist_json(array $payload): void
{
    echo json_encode($payload);
    exit;
}

// ---------- Authorization ----------
if (!is_member()) {
    wishlist_json([
        'status'   => 'error',
        'message'  => 'Please log in as a member to save favourites.',
        'redirect' => '/auth/login.php',
    ]);
}

// ---------- CSRF ----------
if (!is_post() || !csrf_valid()) {
    wishlist_json(['status' => 'error', 'message' => 'Invalid request. Please refresh the page.']);
}

if (!wishlist_module_ready()) {
    wishlist_json([
        'status'  => 'error',
        'message' => 'Wishlist is not set up yet. Run database/migration_09_wishlist.sql.',
    ]);
}

$userId    = current_user_id();
$productId = post_int('product_id');

if (post('action') !== 'toggle' || $productId === null) {
    wishlist_json(['status' => 'error', 'message' => 'Invalid request.']);
}

// The product must exist and still be on sale.
$product = db_one("SELECT id, name FROM products WHERE id = ? AND status = 'active'", [$productId]);

if (!$product) {
    wishlist_json(['status' => 'error', 'message' => 'This product is no longer available.']);
}

$result = toggle_wishlist($userId, $productId);

wishlist_json([
    'status'         => 'success',
    'added'          => $result['added'],
    'wishlist_count' => $result['count'],
    'message'        => $result['added']
        ? $product['name'] . ' added to your wishlist.'
        : $product['name'] . ' removed from your wishlist.',
]);
