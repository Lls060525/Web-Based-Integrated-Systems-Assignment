<?php
// ============================================================
// member/wishlist.php - Favorites / Wishlist (Member)
// ============================================================

require_once __DIR__ . '/../lib/init.php';
require_once __DIR__ . '/../includes/product_card.php';

require_member();

$title  = 'My Wishlist - ' . APP_NAME;
$userId = current_user_id();

if (!wishlist_module_ready()) {
    flash_error('Wishlist is not available yet: run database/migration_09_wishlist.sql.');
    redirect('/member/profile.php');
}

// ---------- Actions ----------
if (is_post()) {
    csrf_check();

    $action = post('action');

    if ($action === 'remove') {
        $productId = post_int('product_id');

        if ($productId !== null) {
            remove_from_wishlist($userId, $productId);
            flash_success('Removed from your wishlist.');
        }

    } elseif ($action === 'clear') {
        $removed = clear_wishlist($userId);
        flash_success($removed . ' item' . ($removed === 1 ? '' : 's') . ' removed from your wishlist.');

    } elseif ($action === 'move_to_cart') {
        // Move everything that is actually buyable into the cart,
        // and report honestly on anything that was skipped.
        $items   = wishlist_items($userId);
        $moved   = 0;
        $skipped = 0;

        foreach ($items as $item) {
            if ($item['status'] !== 'active' || (int)$item['stock'] <= 0) {
                $skipped++;
                continue;
            }

            $line = db_one(
                'SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ?',
                [$userId, $item['id']]
            );

            if ($line) {
                if ((int)$line['quantity'] >= (int)$item['stock']) {
                    $skipped++;
                    continue;
                }
                db_exec('UPDATE cart SET quantity = quantity + 1 WHERE id = ?', [$line['id']]);
            } else {
                db_exec(
                    'INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, 1)',
                    [$userId, $item['id']]
                );
            }

            remove_from_wishlist($userId, (int)$item['id']);
            $moved++;
        }

        if ($moved > 0) {
            flash_success($moved . ' item' . ($moved === 1 ? '' : 's') . ' moved to your cart.'
                . ($skipped > 0 ? ' ' . $skipped . ' could not be moved (out of stock or unavailable).' : ''));
        } else {
            flash_error('Nothing could be moved. The saved items are out of stock or no longer available.');
        }
    }

    redirect('/member/wishlist.php');
}

$items = wishlist_items($userId);

// Anything still buyable? Controls whether "Move all to cart" is offered.
$buyable = 0;
foreach ($items as $item) {
    if ($item['status'] === 'active' && (int)$item['stock'] > 0) {
        $buyable++;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<nav class="breadcrumb">
    <a href="/member/profile.php">My Profile</a> &gt; <span>My Wishlist</span>
</nav>

<div class="page-title-row">
    <h2 class="page-title">
        My Wishlist
        <span class="muted title-count"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?></span>
    </h2>

    <?php if (count($items) > 0): ?>
        <div class="row-actions">
            <?php if ($buyable > 0): ?>
                <form action="/member/wishlist.php" method="POST" class="inline-form"
                      data-confirm="Move all available items to your cart?">
                    <?php csrf_field(); ?>
                    <?php html_hidden('action', 'move_to_cart'); ?>
                    <?php html_submit('Move All to Cart', ['class' => 'btn-outline']); ?>
                </form>
            <?php endif; ?>

            <form action="/member/wishlist.php" method="POST" class="inline-form"
                  data-confirm="Remove every item from your wishlist? This cannot be undone.">
                <?php csrf_field(); ?>
                <?php html_hidden('action', 'clear'); ?>
                <?php html_submit('Clear Wishlist', ['class' => 'btn-outline btn-danger']); ?>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php if (count($items) === 0): ?>

    <div class="card empty-state-box">
        <h3 class="empty-state-title">Your wishlist is empty.</h3>
        <p>Tap the heart on any product to save it for later.</p>
        <a href="/products.php" class="btn-primary shop-now-btn">Browse Products</a>
    </div>

<?php else: ?>

    <div class="grid section-margin-top">
        <?php foreach ($items as $item): ?>
            <?php
                $inStock     = $item['status'] === 'active' && (int)$item['stock'] > 0;
                $unavailable = $item['status'] !== 'active';
            ?>
            <div class="card product-card <?= $unavailable ? 'is-unavailable' : '' ?>">
                <a href="/product_detail.php?id=<?= (int)$item['id'] ?>" class="product-card-link">
                    <div class="card-img">
                        <img src="<?= e(product_image($item['image'])) ?>" alt="<?= e($item['name']) ?>">
                    </div>
                    <div class="card-info">
                        <h3><?= e($item['name']) ?></h3>
                        <?php if (!empty($item['category_name'])): ?>
                            <span class="badge"><?= e($item['category_name']) ?></span>
                        <?php endif; ?>
                        <div class="price"><?= e(money($item['price'])) ?></div>

                        <?php if ($unavailable): ?>
                            <span class="badge badge-danger">No longer available</span>
                        <?php elseif (stock_state($item) === 'out'): ?>
                            <span class="badge badge-danger">Out of stock</span>
                        <?php elseif (stock_state($item) === 'low'): ?>
                            <span class="badge badge-warning">Only <?= (int)$item['stock'] ?> left</span>
                        <?php endif; ?>

                        <div class="muted small-note wishlist-added">
                            Saved <?= e(fmt_date($item['added_at'])) ?>
                        </div>
                    </div>
                </a>

                <div class="card-actions wishlist-card-actions">
                    <?php if ($inStock): ?>
                        <button type="button" class="add-to-cart btn-primary btn-block"
                                data-id="<?= (int)$item['id'] ?>">Add to Cart</button>
                    <?php else: ?>
                        <button type="button" class="btn-outline btn-block" disabled>Unavailable</button>
                    <?php endif; ?>

                    <form action="/member/wishlist.php" method="POST" class="mt-2">
                        <?php csrf_field(); ?>
                        <?php html_hidden('action', 'remove'); ?>
                        <?php html_hidden('product_id', $item['id']); ?>
                        <?php html_submit('Remove', ['class' => 'btn-outline btn-sm btn-block']); ?>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
