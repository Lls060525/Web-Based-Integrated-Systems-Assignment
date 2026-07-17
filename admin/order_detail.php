<?php
require_once __DIR__ . '/admin_auth.php';

$order_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$order_id) die("Invalid Order ID.");


$stmt = $pdo->prepare("
    SELECT o.*, u.name as customer_name, u.email as customer_email, u.profile_photo 
    FROM orders o JOIN users u ON o.user_id = u.id 
    WHERE o.id = ?
");
$stmt->execute([$order_id]);
$order = $stmt->fetch();
if (!$order) die("Order not found.");


$item_stmt = $pdo->prepare("
    SELECT oi.*, p.name as product_name, p.image 
    FROM order_items oi 
    JOIN products p ON oi.product_id = p.id 
    WHERE oi.order_id = ?
");
$item_stmt->execute([$order_id]);
$items = $item_stmt->fetchAll();

$title = 'Order #' . $order['id'] . ' - Admin';
include __DIR__ . '/../includes/header.php';
$status_class = strtolower($order['status']);
?>

<div class="admin-container" style="max-width: 900px;">
    <div class="admin-header">
        <h2>Order Details #<?php echo $order['id']; ?></h2>
        <a href="orders.php" class="btn-outline">&larr; Back to Orders</a>
    </div>

    <div class="profile-layout mt-4">
        <aside class="profile-sidebar" style="width: 300px;">
            <div class="card" style="padding: 20px; box-shadow: none; border: 1px solid var(--border);">
                <h3 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid var(--border); padding-bottom: 10px;">Customer</h3>
                <p><strong><?php echo htmlspecialchars($order['customer_name']); ?></strong><br>
                <a href="mailto:<?php echo htmlspecialchars($order['customer_email']); ?>"><?php echo htmlspecialchars($order['customer_email']); ?></a></p>
                
                <h3 style="margin-top: 25px; font-size: 16px; border-bottom: 1px solid var(--border); padding-bottom: 10px;">Shipping Address</h3>
                <p style="line-height: 1.5; color: var(--text-muted);"><?php echo nl2br(htmlspecialchars($order['shipping_address'])); ?></p>
                
                <h3 style="margin-top: 25px; font-size: 16px; border-bottom: 1px solid var(--border); padding-bottom: 10px;">Order Status</h3>
                <span class="status-badge status-<?php echo $status_class; ?>" style="font-size: 14px; padding: 6px 12px;"><?php echo $order['status']; ?></span>
            </div>
        </aside>

        <main class="profile-content">
            <div class="card" style="padding: 20px;">
                <h3 style="margin-top: 0; font-size: 18px; margin-bottom: 20px;">Purchased Items</h3>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th colspan="2">Product</th>
                            <th>Unit Price</th>
                            <th>Qty</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <?php 
                            $image_path = $item['image'] === 'default-product.png' ? '/assets/images/default-product.png' : '/assets/uploads/products/' . htmlspecialchars($item['image']);
                            $subtotal = $item['price_at_purchase'] * $item['quantity'];
                            ?>
                            <tr>
                                <td width="60">
                                    <img src="<?php echo $image_path; ?>" alt="Product" style="width: 50px; height: 50px; object-fit: contain; background: #f9f9f9; border-radius: 4px;">
                                </td>
                                <td><strong><?php echo htmlspecialchars($item['product_name']); ?></strong></td>
                                <td>RM <?php echo number_format($item['price_at_purchase'], 2); ?></td>
                                <td>x <?php echo $item['quantity']; ?></td>
                                <td><strong>RM <?php echo number_format($subtotal, 2); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div style="text-align: right; margin-top: 20px; font-size: 18px; padding-top: 20px; border-top: 1px solid var(--border);">
                    Total Amount Paid: <strong style="color: var(--primary); font-size: 24px; margin-left: 15px;">RM <?php echo number_format($order['total_amount'], 2); ?></strong>
                </div>
            </div>
        </main>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>