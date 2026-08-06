<?php
// ============================================================
// product_detail.php - single product page
// ============================================================

require_once __DIR__ . '/lib/init.php';
require_once __DIR__ . '/includes/product_card.php';

$productId = get_int('id');

if ($productId === null) {
    flash_error('That product could not be found.');
    redirect('/products.php');
}

$product = db_one(
    "SELECT p.*, c.name AS category_name
       FROM products p
       LEFT JOIN categories c ON c.id = p.category_id
      WHERE p.id = ? AND p.status = 'active'",
    [$productId]
);

if (!$product) {
    flash_error('That product is no longer available.');
    redirect('/products.php');
}

// Four more items from the same category.
$related = db_all(
    "SELECT p.*, c.name AS category_name
       FROM products p
       LEFT JOIN categories c ON c.id = p.category_id
      WHERE p.status = 'active' AND p.category_id = ? AND p.id <> ?
      ORDER BY RAND() LIMIT 4",
    [$product['category_id'], $product['id']]
);

$title = $product['name'] . ' - ' . APP_NAME;
$stock = (int)$product['stock'];

include __DIR__ . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="/">Home</a> &gt;
    <a href="/products.php">Products</a> &gt;
    <span><?= e($product['name']) ?></span>
</nav>

<div class="product-detail-layout">

    <div class="product-detail-img-wrapper card">
        <img src="<?= e(product_image($product['image'])) ?>"
             alt="<?= e($product['name']) ?>" class="product-detail-img">
    </div>

    <div class="product-detail-info">
        <h1 class="product-detail-title"><?= e($product['name']) ?></h1>

        <?php if ($product['category_name']): ?>
            <a href="/products.php?category=<?= (int)$product['category_id'] ?>" class="badge">
                <?= e($product['category_name']) ?>
            </a>
        <?php endif; ?>

        <div class="price price-lg"><?= e(money($product['price'])) ?></div>

        <div class="product-description card">
            <h3>Product Description</h3>
            <p><?= nl2br(e($product['description'])) ?></p>
        </div>

        <div class="stock-line">
            <span class="muted">Availability:</span>
            <strong class="<?= e(stock_class($product)) ?>"><?= e(stock_message($product)) ?></strong>
        </div>

        <div class="product-detail-actions">
            <?php if ($stock > 0): ?>
                <button type="button" class="add-to-cart btn-primary btn-lg btn-block"
                        data-id="<?= (int)$product['id'] ?>">Add to Cart</button>
            <?php else: ?>
                <button type="button" class="btn-outline btn-lg btn-block" disabled>Out of Stock</button>
            <?php endif; ?>

            <?php if (is_member()): ?>
                <?php $saved = is_wishlisted((int)$product['id']); ?>
                <button type="button"
                        class="btn-outline btn-lg btn-block mt-2 js-wishlist wishlist-inline <?= $saved ? 'is-saved' : '' ?>"
                        data-id="<?= (int)$product['id'] ?>"
                        aria-pressed="<?= $saved ? 'true' : 'false' ?>">
                    <i class="<?= $saved ? 'fas' : 'far' ?> fa-heart"></i>
                    <span class="wishlist-label"><?= $saved ? 'Saved to Wishlist' : 'Save to Wishlist' ?></span>
                </button>
            <?php else: ?>
                <a href="/auth/login.php" class="btn-outline btn-lg btn-block mt-2">
                    <i class="far fa-heart"></i> Log in to save this
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (count($related) > 0): ?>
    <section class="related-products">
        <h2 class="page-title">You May Also Like</h2>
        <?php render_product_grid($related); ?>
    </section>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
