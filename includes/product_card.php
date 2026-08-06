<?php
// ============================================================
// includes/product_card.php - reusable product tile
//
// Usage:  render_product_card($product);
// Used by products.php, search.php and member/home.php so the
// catalogue markup exists in exactly one place.
// ============================================================

if (!function_exists('render_product_card')) {

    function render_product_card(array $p): void
    {
        $inStock = (int)$p['stock'] > 0;
        $saved   = is_wishlisted((int)$p['id']);
        ?>
        <div class="card product-card">
            <?php if (is_member()): ?>
                <button type="button"
                        class="wishlist-btn js-wishlist <?= $saved ? 'is-saved' : '' ?>"
                        data-id="<?= (int)$p['id'] ?>"
                        aria-pressed="<?= $saved ? 'true' : 'false' ?>"
                        title="<?= $saved ? 'Remove from wishlist' : 'Save to wishlist' ?>">
                    <i class="<?= $saved ? 'fas' : 'far' ?> fa-heart"></i>
                </button>
            <?php endif; ?>

            <a href="/product_detail.php?id=<?= (int)$p['id'] ?>" class="product-card-link">
                <div class="card-img">
                    <img src="<?= e(product_image($p['image'])) ?>" alt="<?= e($p['name']) ?>">
                </div>
                <div class="card-info">
                    <h3><?= e($p['name']) ?></h3>
                    <?php if (!empty($p['category_name'])): ?>
                        <span class="badge"><?= e($p['category_name']) ?></span>
                    <?php endif; ?>
                    <div class="price"><?= e(money($p['price'])) ?></div>

                    <?php $state = stock_state($p); ?>
                    <?php if ($state === 'out'): ?>
                        <span class="badge badge-danger">Out of stock</span>
                    <?php elseif ($state === 'low'): ?>
                        <span class="badge badge-warning">Only <?= (int)$p['stock'] ?> left</span>
                    <?php endif; ?>
                </div>
            </a>
            <div class="card-actions">
                <?php if ($inStock): ?>
                    <button type="button" class="add-to-cart btn-primary btn-block"
                            data-id="<?= (int)$p['id'] ?>">Add to Cart</button>
                <?php else: ?>
                    <button type="button" class="btn-outline btn-block" disabled>Out of Stock</button>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /** Render a whole grid of products, or an empty-state message. */
    function render_product_grid(array $products, string $emptyMessage = 'No products found.'): void
    {
        if (count($products) === 0) {
            echo '<div class="card empty-state-box"><h3 class="empty-state-title">'
               . e($emptyMessage) . '</h3></div>';
            return;
        }

        echo '<div class="grid">';
        foreach ($products as $p) {
            render_product_card($p);
        }
        echo '</div>';
    }
}
