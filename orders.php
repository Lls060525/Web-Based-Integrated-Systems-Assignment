<?php
session_start();


if (!isset($_SESSION['user_id']) || (isset($_SESSION['role']) && $_SESSION['role'] !== 'member')) {
    header('Location: /auth/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$title = 'My Orders - Mobile2U';

require_once __DIR__ . '/config/database.php';


$stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$orders = $stmt->fetchAll();

$order_items = [];
if (count($orders) > 0) {
    $order_ids = array_column($orders, 'id');
    
    $in_clause = implode(',', array_fill(0, count($order_ids), '?'));

    $item_stmt = $pdo->prepare("
        SELECT oi.order_id, oi.quantity, oi.price_at_purchase, p.name, p.image 
        FROM order_items oi 
        JOIN products p ON oi.product_id = p.id 
        WHERE oi.order_id IN ($in_clause)
    ");
    $item_stmt->execute($order_ids);
    $fetched_items = $item_stmt->fetchAll();

    foreach ($fetched_items as $item) {
        $order_items[$item['order_id']][] = $item;
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="container mt-4" style="max-width: 900px;">
    <nav style="margin-bottom: 20px; font-size: 14px; color: var(--text-muted);">
        <a href="/member/profile.php" style="color: var(--text-muted); text-decoration: none;">My Profile</a> > 
        <span style="color: var(--text-main); font-weight: 500;">My Orders</span>
    </nav>

    <div class="admin-header">
        <h2>My Orders</h2>
    </div>

    <?php if (empty($orders)): ?>
        <div class="card mt-4" style="text-align: center; padding: 60px 20px;">
            <img src="/assets/images/default-product.png" alt="No Orders" style="width: 100px; opacity: 0.3; margin-bottom: 15px;">
            <h3 style="color: var(--text-muted); margin-top: 0;">You haven't placed any orders yet.</h3>
            <p>Explore our latest flagship devices and gear.</p>
            <a href="/products.php" class="btn-primary" style="display: inline-block; margin-top: 15px; text-decoration: none;">Shop Now</a>
        </div>
    <?php else: ?>
        <div style="margin-top: 20px;">
            <?php foreach ($orders as $order): ?>
                <?php $status_class = strtolower($order['status']); ?>
                <div class="card" style="margin-bottom: 25px; border-radius: 8px; overflow: hidden; border: 1px solid var(--border); box-shadow: none;">
                    
                    <div style="background: #f8f9fa; padding: 15px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <strong style="font-size: 16px;">Order #<?php echo $order['id']; ?></strong>
                            <span style="color: var(--text-muted); font-size: 14px; margin-left: 10px;">
                                Placed on <?php echo date('d M Y, H:i', strtotime($order['created_at'])); ?>
                            </span>
                        </div>
                        <div>
                            <span class="status-badge status-<?php echo $status_class; ?>"><?php echo $order['status']; ?></span>
                        </div>
                    </div>

                    <div style="padding: 0 20px;">
                        <?php if (isset($order_items[$order['id']])): ?>
                            <?php foreach ($order_items[$order['id']] as $item): ?>
                                <?php 
                                    $image_path = $item['image'] === 'default-product.png' ? '/assets/images/default-product.png' : '/assets/uploads/products/' . htmlspecialchars($item['image']);
                                ?>
                                <div style="display: flex; align-items: center; padding: 15px 0; border-bottom: 1px dashed #eaeaea;">
                                    <img src="<?php echo $image_path; ?>" alt="Product" style="width: 60px; height: 60px; object-fit: contain; background: #f9f9f9; border-radius: 4px; border: 1px solid var(--border);">
                                    <div style="margin-left: 15px; flex: 1;">
                                        <strong style="font-size: 15px;"><?php echo htmlspecialchars($item['name']); ?></strong>
                                        <div style="color: var(--text-muted); font-size: 14px; margin-top: 4px;">Qty: <?php echo $item['quantity']; ?></div>
                                    </div>
                                    <div style="font-weight: bold; color: var(--primary);">
                                        RM <?php echo number_format($item['price_at_purchase'], 2); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div style="padding: 15px 20px; background: #fff; display: flex; justify-content: space-between; align-items: center;">
                        <div style="font-size: 13px; color: var(--text-muted); max-width: 60%;">
                            <strong>Shipping to:</strong><br>
                            <?php echo nl2br(htmlspecialchars($order['shipping_address'])); ?>
                        </div>
                        <div style="text-align: right; font-size: 16px;">
                            Order Total: <strong style="color: var(--primary); font-size: 20px; margin-left: 10px;">RM <?php echo number_format($order['total_amount'], 2); ?></strong>
                        </div>
                    </div>
                    
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>