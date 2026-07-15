<?php
session_start();

require_once __DIR__ . '/config/database.php';

// 获取并验证 URL 中的商品 ID
$product_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$product_id) {
    header('Location: /products.php'); // 如果没有 ID，踢回列表页
    exit;
}

// 关联查询商品信息（如果有 category，顺便查出来）
$sql = "SELECT p.*, c.name AS category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        WHERE p.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$product_id]);
$product = $stmt->fetch();

if (!$product) {
    die("Product not found.");
}

$title = htmlspecialchars($product['name']) . ' - Mobile2U';
include __DIR__ . '/includes/header.php';

$image_path = $product['image'] === 'default-product.png' ? '/assets/images/default-product.png' : '/assets/images/' . htmlspecialchars($product['image']);
?>

<div class="container mt-4">
    <nav style="margin-bottom: 20px; font-size: 14px; color: var(--text-muted);">
        <a href="/" style="color: var(--text-muted); text-decoration: none;">Home</a> > 
        <a href="/products.php" style="color: var(--text-muted); text-decoration: none;">Products</a> > 
        <span style="color: var(--text-main); font-weight: 500;"><?php echo htmlspecialchars($product['name']); ?></span>
    </nav>

    <div class="product-detail-layout">
        <div class="product-detail-img-wrapper card">
            <img src="<?php echo $image_path; ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="product-detail-img">
        </div>

        <div class="product-detail-info">
            <h1 style="margin-top: 0; font-size: 28px;"><?php echo htmlspecialchars($product['name']); ?></h1>
            
            <?php if ($product['category_name']): ?>
                <span class="badge" style="margin-bottom: 15px; display: inline-block;"><?php echo htmlspecialchars($product['category_name']); ?></span>
            <?php endif; ?>

            <div class="price" style="font-size: 32px; margin: 15px 0;">
                RM <?php echo number_format($product['price'], 2); ?>
            </div>

            <div style="background: var(--white); padding: 20px; border-radius: 8px; border: 1px solid var(--border); margin-bottom: 25px;">
                <h3 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid var(--border); padding-bottom: 10px;">Product Description</h3>
                <p style="color: var(--text-muted); line-height: 1.6; white-space: pre-wrap;"><?php echo htmlspecialchars($product['description']); ?></p>
            </div>

            <div style="display: flex; align-items: center; gap: 20px;">
                <div style="font-size: 14px;">
                    <span style="color: var(--text-muted);">Availability: </span>
                    <?php if ($product['stock'] > 10): ?>
                        <strong style="color: #1e8e3e;">In Stock (<?php echo $product['stock']; ?>)</strong>
                    <?php elseif ($product['stock'] > 0): ?>
                        <strong style="color: #d93025;">Low Stock (Only <?php echo $product['stock']; ?> left)</strong>
                    <?php else: ?>
                        <strong style="color: var(--text-muted);">Out of Stock</strong>
                    <?php endif; ?>
                </div>
            </div>

            <div style="margin-top: 20px; width: 100%; max-width: 300px;">
                <?php if ($product['stock'] > 0): ?>
                    <button class="add-to-cart btn-primary" data-id="<?php echo $product['id']; ?>" style="width: 100%; padding: 15px; font-size: 16px;">
                        Add to Cart
                    </button>
                <?php else: ?>
                    <button class="btn-outline" style="width: 100%; padding: 15px; font-size: 16px; cursor: not-allowed; text-align: center;" disabled>
                        Out of Stock
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>