<?php
// ============================================================
// api/wishlist_action.php - AJAX endpoint for the wishlist heart
// Always responds with JSON.
// ============================================================

require_once __DIR__ . '/../lib/init.php';

// One call replaces the role check, the POST check and the CSRF
// check that used to be copy-pasted into every endpoint.
ajax_guard_post('member');

if (!wishlist_module_ready()) {
    json_out([
        'status'  => 'error',
        'message' => 'Wishlist is not set up yet. Run database/migration_09_wishlist.sql.',
    ]);
}

$userId    = current_user_id();
$productId = post_int('product_id');

if (post('action') !== 'toggle' || $productId === null) {
    json_out(['status' => 'error', 'message' => 'Invalid request.']);
}

// The product must exist and still be on sale.
$product = db_one("SELECT id, name FROM products WHERE id = ? AND status = 'active'", [$productId]);

if (!$product) {
    json_out(['status' => 'error', 'message' => 'This product is no longer available.']);
}

$result = toggle_wishlist($userId, $productId);

json_out([
    'status'         => 'success',
    'added'          => $result['added'],
    'wishlist_count' => $result['count'],
    'message'        => $result['added']
        ? $product['name'] . ' added to your wishlist.'
        : $product['name'] . ' removed from your wishlist.',
]);
