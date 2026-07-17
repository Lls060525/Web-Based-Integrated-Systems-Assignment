<?php
session_start();
$title = 'Products - Mobile2U';

require_once __DIR__ . '/config/database.php'; 

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
                
                $image_path = $p['image'] === 'default-product.png' ? '/assets/images/default-product.png' : '/assets/uploads/products/' . htmlspecialchars($p['image']);
            ?>
            <div class="card">
                <a href="/product_detail.php?id=<?php echo $p['id']; ?>" style="text-decoration: none; color: inherit; display: flex; flex-direction: column; flex: 1;">
                    <div class="card-img" style="background: var(--white); padding: 10px;">
                        <img src="<?php echo $image_path; ?>" alt="<?php echo htmlspecialchars($p['name']); ?>" style="width: 100%; height: 100%; object-fit: contain;">
                    </div>
                    <div class="card-info" style="padding-bottom: 5px;">
                        <h3><?php echo htmlspecialchars($p['name']); ?></h3>
                        <p class="price">RM <?php echo number_format($p['price'], 2); ?></p>
                    </div>
                </a>
                
                <div style="padding: 0 16px 16px 16px;">
                    <?php if ($p['stock'] > 0): ?>
                        <button class="add-to-cart btn-primary" data-id="<?php echo $p['id']; ?>" style="width: 100%;">Add to Cart</button>
                    <?php else: ?>
                        <button class="btn-outline" style="width: 100%; cursor: not-allowed; text-align: center;" disabled>Out of Stock</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
  </section>

<?php include __DIR__ . '/includes/footer.php'; ?>
<!-- hgi gtbtsw-->