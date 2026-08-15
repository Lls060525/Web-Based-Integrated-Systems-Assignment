<?php
// ============================================================
// lib/cart.php
// Loading and pricing the shopping cart.
//
// This was written inline in cart.php. It moved here when the quantity
// control became AJAX, because the endpoint has to return exactly the
// numbers the page would have rendered. Two copies of the pricing loop
// would eventually disagree, and the way that shows up is the ugliest
// possible: the line total says one thing, the order summary says
// another, and neither matches what checkout charges.
// ============================================================

/**
 * Every line in a member's cart, priced, plus the totals.
 *
 * Prices are recomputed from the current options rather than read from
 * the cart row, so an admin changing a price delta is reflected before
 * checkout instead of after. Orders snapshot the result at purchase
 * time; carts deliberately do not.
 *
 * @return array{items: array<int, array>, total_price: float, total_items: int}
 */
function cart_load(int $userId): array
{
    $hasOptions = db_column_exists('cart', 'options_signature');
    $sigColumn  = $hasOptions ? 'c.options_signature' : "'' AS options_signature";

    $items = db_all(
        "SELECT c.id AS cart_id, c.quantity, $sigColumn,
                p.id AS product_id, p.name, p.price, p.image, p.stock
           FROM cart c
           JOIN products p ON p.id = c.product_id
          WHERE c.user_id = ?
          ORDER BY c.added_at DESC",
        [$userId]
    );

    $totalPrice = 0.0;
    $totalItems = 0;

    // Every line's options in one query. Without this,
    // spec_describe_signature() below would query once per chosen
    // attribute per line.
    spec_prefetch_options(array_column($items, 'product_id'));

    foreach ($items as $index => $item) {
        $chosen = spec_describe_signature((int)$item['product_id'], (string)$item['options_signature']);

        $unitPrice = (float)$item['price'] + $chosen['delta'];
        $quantity  = (int)$item['quantity'];

        $items[$index]['options_label'] = $chosen['label'];
        $items[$index]['options_valid'] = $chosen['valid'];
        $items[$index]['unit_price']    = $unitPrice;
        $items[$index]['line_total']    = round($unitPrice * $quantity, 2);

        $totalPrice += $unitPrice * $quantity;
        $totalItems += $quantity;
    }

    return [
        'items'       => $items,
        'total_price' => round($totalPrice, 2),
        'total_items' => $totalItems,
    ];
}

/**
 * Set the quantity on one cart line.
 *
 * Ownership is part of the WHERE clause on both the read and the write,
 * so one member can never touch another member's line no matter what
 * cart_id is posted.
 *
 * The quantity is clamped to available stock rather than rejected. The
 * member asked for more than we have; giving them the most we can and
 * saying so is more useful than refusing and leaving the old number.
 *
 * @return array{ok: bool, quantity: int, clamped: bool, message: string}
 */
function cart_set_quantity(int $userId, int $cartId, int $quantity): array
{
    $fail = ['ok' => false, 'quantity' => 0, 'clamped' => false, 'message' => ''];

    if ($quantity < 1) {
        return $fail + ['message' => 'Quantity must be at least 1.'];
    }

    $line = db_one(
        'SELECT c.id, c.quantity, p.stock, p.name
           FROM cart c
           JOIN products p ON p.id = c.product_id
          WHERE c.id = ? AND c.user_id = ?',
        [$cartId, $userId]
    );

    if (!$line) {
        return $fail + ['message' => 'That item is no longer in your cart.'];
    }

    $stock = (int)$line['stock'];

    if ($stock <= 0) {
        return $fail + ['message' => $line['name'] . ' is out of stock.'];
    }

    $final   = min($quantity, $stock);
    $clamped = $final < $quantity;

    db_exec(
        'UPDATE cart SET quantity = ? WHERE id = ? AND user_id = ?',
        [$final, $cartId, $userId]
    );

    return [
        'ok'       => true,
        'quantity' => $final,
        'clamped'  => $clamped,
        'message'  => $clamped
            ? 'Only ' . $stock . ' left in stock, so the quantity was reduced.'
            : '',
    ];
}
