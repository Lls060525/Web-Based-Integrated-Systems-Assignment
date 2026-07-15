<?php
session_start();
$title = 'Products - Mobile2U';

require_once __DIR__ . '/config/database.php'; // 如果文件在根目录

$sql = "SELECT * FROM products ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$products = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

  <section class="products mt-4">
    <div class="admin-header">
        <h2>Latest Flagship Devices</h2>
    </div>
    
    <div class="grid">
        <?php foreach ($products as $p): ?>
            <?php 
                // 【适配字段】：product_image 改为 image
                $image_path = $p['image'] === 'default-product.png' ? '/assets/images/default-product.png' : '/assets/uploads/products/' . htmlspecialchars($p['image']);
            ?>
            <div class="card">
                <div class="card-img" style="background: var(--white); padding: 10px;">
                    <img src="<?php echo $image_path; ?>" alt="<?php echo htmlspecialchars($p['name']); ?>" style="width: 100%; height: 100%; object-fit: contain;">
                </div>
                <div class="card-info">
                    <h3><?php echo htmlspecialchars($p['name']); ?></h3>
                    <p class="price">RM <?php echo number_format($p['price'], 2); ?></p>
                    
                    <?php if ($p['stock'] > 0): ?>
                        <button class="add-to-cart btn-primary" data-id="<?php echo $p['id']; ?>" style="margin-top: auto;">Add to Cart</button>
                    <?php else: ?>
                        <button class="btn-outline" style="margin-top: auto; cursor: not-allowed; text-align: center;" disabled>Out of Stock</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
  </section>

<?php include __DIR__ . '/includes/footer.php'; ?>