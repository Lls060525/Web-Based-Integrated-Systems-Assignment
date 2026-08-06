<?php
// ============================================================
// cart.php - Shopping Cart (Member)
// ============================================================

require_once __DIR__ . '/lib/init.php';

require_member();

$title  = 'My Cart - ' . APP_NAME;
$userId = current_user_id();

// ---------- Handle updates (PRG pattern) ----------
if (is_post()) {
    csrf_check();

    $action = post('action');

    if ($action === 'update_cart') {
        $quantities = $_POST['quantities'] ?? [];

        if (is_array($quantities)) {
            foreach ($quantities as $cartId => $qty) {
                $cartId = filter_var($cartId, FILTER_VALIDATE_INT);
                $qty    = filter_var($qty, FILTER_VALIDATE_INT);

                if ($cartId === false || $qty === false || $qty < 1) {
                    continue;
                }

                // Ownership check is part of the WHERE clause, so one member
                // can never touch another member's cart line.
                $stock = db_value(
                    'SELECT p.stock
                       FROM cart c JOIN products p ON p.id = c.product_id
                      WHERE c.id = ? AND c.user_id = ?',
                    [$cartId, $userId]
                );

                if ($stock !== false) {
                    db_exec(
                        'UPDATE cart SET quantity = ? WHERE id = ? AND user_id = ?',
                        [min($qty, (int)$stock), $cartId, $userId]
                    );
                }
            }
        }

        flash_success('Cart updated.');

    } elseif ($action === 'remove_item') {
        $cartId = post_int('cart_id');

        if ($cartId !== null) {
            db_exec('DELETE FROM cart WHERE id = ? AND user_id = ?', [$cartId, $userId]);
            flash_success('Item removed from your cart.');
        }
    }

    redirect('/cart.php');
}

// ---------- Load the cart ----------
$items = db_all(
    'SELECT c.id AS cart_id, c.quantity,
            p.id AS product_id, p.name, p.price, p.image, p.stock
       FROM cart c
       JOIN products p ON p.id = c.product_id
      WHERE c.user_id = ?
      ORDER BY c.added_at DESC',
    [$userId]
);

$totalPrice = 0;
$totalItems = 0;
foreach ($items as $item) {
    $totalPrice += $item['price'] * $item['quantity'];
    $totalItems += $item['quantity'];
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-title-row">
    <h2 class="page-title">Shopping Cart</h2>
</div>

<?php if (count($items) === 0): ?>

    <div class="card empty-state-box">
        <h3 class="empty-state-title">Your cart is currently empty.</h3>
        <p>Explore our latest flagship devices and gear.</p>
        <a href="/products.php" class="btn-primary shop-now-btn">Shop Now</a>
    </div>

<?php else: ?>

    <div class="cart-layout section-margin-top">

        <div class="cart-items-section">
            <form action="/cart.php" method="POST" id="updateCartForm">
                <?php csrf_field(); ?>
                <?php html_hidden('action', 'update_cart'); ?>

                <div class="card">
                    <table class="cart-table">
                        <thead>
                            <tr>
                                <th colspan="2">Product</th>
                                <th>Unit Price</th>
                                <th>Quantity</th>
                                <th>Subtotal</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <?php $subtotal = $item['price'] * $item['quantity']; ?>
                            <tr>
                                <td class="cell-thumb">
                                    <img src="<?= e(product_image($item['image'])) ?>" alt="<?= e($item['name']) ?>" class="cart-thumb">
                                </td>
                                <td>
                                    <strong><?= e($item['name']) ?></strong>
                                    <?php if ($item['quantity'] > $item['stock']): ?>
                                        <br><span class="err">Only <?= (int)$item['stock'] ?> left in stock.</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e(money($item['price'])) ?></td>
                                <td>
                                    <input type="number"
                                           name="quantities[<?= (int)$item['cart_id'] ?>]"
                                           value="<?= (int)$item['quantity'] ?>"
                                           min="1" max="<?= (int)$item['stock'] ?>"
                                           class="qty-input update-qty-trigger">
                                </td>
                                <td class="cell-price"><?= e(money($subtotal)) ?></td>
                                <td>
                                    <button type="button"
                                            class="btn-outline btn-sm btn-danger js-submit-form"
                                            data-target="deleteForm_<?= (int)$item['cart_id'] ?>"
                                            data-confirm="Remove this item from your cart?">Remove</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>

            <?php foreach ($items as $item): ?>
                <form action="/cart.php" method="POST" id="deleteForm_<?= (int)$item['cart_id'] ?>" class="hidden-form">
                    <?php csrf_field(); ?>
                    <?php html_hidden('action', 'remove_item'); ?>
                    <?php html_hidden('cart_id', $item['cart_id']); ?>
                </form>
            <?php endforeach; ?>
        </div>

        <aside class="cart-summary-section">
            <div class="card cart-summary">
                <h3>Order Summary</h3>

                <div class="summary-row">
                    <span>Total Items:</span>
                    <strong><?= $totalItems ?></strong>
                </div>
                <div class="summary-row summary-total">
                    <span>Total Price:</span>
                    <strong class="price"><?= e(money($totalPrice)) ?></strong>
                </div>

                <?php $shipTo = default_address($userId); ?>
                <?php if ($shipTo !== null): ?>
                    <div class="summary-ship-to">
                        <span class="muted small-note">Shipping to</span>
                        <div><strong><?= e($shipTo['recipient_name']) ?></strong></div>
                        <div class="muted small-note"><?= e(format_address_short($shipTo)) ?></div>
                        <a href="/member/addresses.php" class="small-note">Change</a>
                    </div>
                <?php endif; ?>

                <a href="/checkout.php" class="btn-primary btn-block btn-lg">
                    Proceed to Checkout
                </a>

                <p class="muted small-note mt-2 text-center">
                    <i class="fas fa-ticket"></i> Have a voucher? Apply it at checkout.
                </p>
            </div>
        </aside>

    </div>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
