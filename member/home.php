<?php
// ============================================================
// member/home.php - member landing page
// Everything on this page is read from the database.
// ============================================================

require_once __DIR__ . '/../lib/init.php';
require_once __DIR__ . '/../includes/product_card.php';

require_login();

$title = 'Home - ' . APP_NAME;
$user  = current_user();

// Newest arrivals.
$latest = db_all(
    "SELECT p.*, c.name AS category_name
       FROM products p
       LEFT JOIN categories c ON c.id = p.category_id
      WHERE p.status = 'active'
      ORDER BY p.id DESC
      LIMIT 8"
);

// Top 5 best sellers, computed from the order lines.
$topSellers = db_all(
    "SELECT p.*, c.name AS category_name, SUM(oi.quantity) AS sold
       FROM order_items oi
       JOIN products p ON p.id = oi.product_id
       JOIN orders o   ON o.id = oi.order_id
       LEFT JOIN categories c ON c.id = p.category_id
      WHERE p.status = 'active' AND o.status <> 'cancelled'
      GROUP BY p.id
      ORDER BY sold DESC
      LIMIT 5"
);

$categories = db_all(
    "SELECT c.id, c.name, COUNT(p.id) AS product_count
       FROM categories c
       LEFT JOIN products p ON p.category_id = c.id AND p.status = 'active'
      GROUP BY c.id
      ORDER BY c.name ASC"
);

include __DIR__ . '/../includes/header.php';
?>

<section class="hero">
    <h1>Welcome back, <?= e($user['name'] ?? 'there') ?>.</h1>
    <p>Discover the latest flagship devices and top-tier tech gear.</p>
    <a href="/products.php" class="btn-primary btn-lg">Browse All Products</a>
</section>

<?php if (count($categories) > 0): ?>
    <section class="category-strip">
        <h2 class="page-title">Shop by Category</h2>
        <div class="category-chips">
            <?php foreach ($categories as $c): ?>
                <a href="/products.php?category=<?= (int)$c['id'] ?>" class="category-chip">
                    <?= e($c['name']) ?>
                    <span class="chip-count"><?= (int)$c['product_count'] ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if (count($topSellers) > 0): ?>
    <section class="products">
        <h2 class="page-title">Top 5 Best Sellers</h2>
        <?php render_product_grid($topSellers); ?>
    </section>
<?php endif; ?>

<section class="products">
    <h2 class="page-title">Daily Discover</h2>
    <?php render_product_grid($latest, 'No products have been published yet.'); ?>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
