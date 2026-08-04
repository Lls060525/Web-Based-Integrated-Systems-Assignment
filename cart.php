<?php
session_start();
$title = 'My Cart - Mobile2U';

// 1. Authorization validation
if (!isset($_SESSION['user_id']) || (isset($_SESSION['role']) && $_SESSION['role'] !== 'member')) {
    header('Location: /auth/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$pdo = new PDO("mysql:host=127.0.0.1;dbname=mobile2u;charset=utf8mb4", "root", "", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// 2. Form submission handling (PRG Pattern)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_cart') {
        $quantities = $_POST['quantities'] ?? [];
        foreach ($quantities as $cart_id => $qty) {
            $qty = (int)$qty;
            if ($qty > 0) {
                $stmt = $pdo->prepare("SELECT p.stock FROM cart c JOIN products p ON c.product_id = p.id WHERE c.id = ? AND c.user_id = ?");
                $stmt->execute([$cart_id, $user_id]);
                $stock = $stmt->fetchColumn();

                if ($stock !== false) {
                    $final_qty = min($qty, $stock);
                    $update_stmt = $pdo->prepare("UPDATE cart SET quantity = ? WHERE id = ? AND user_id = ?");
                    $update_stmt->execute([$final_qty, $cart_id, $user_id]);
                }
            }
        }
        header("Location: /cart.php");
        exit;
    } elseif ($action === 'remove_item') {
        $cart_id = filter_input(INPUT_POST, 'cart_id', FILTER_VALIDATE_INT);
        if ($cart_id) {
            $stmt = $pdo->prepare("DELETE FROM cart WHERE id = ? AND user_id = ?");
            $stmt->execute([$cart_id, $user_id]);
        }
        header("Location: /cart.php");
        exit;
    }
}

// 3. Fetch cart data
$stmt = $pdo->prepare("
    SELECT c.id AS cart_id, c.quantity,
           p.id AS product_id, p.name, p.price, p.image, p.stock 
    FROM cart c 
    JOIN products p ON c.product_id = p.id 
    WHERE c.user_id = ? 
    ORDER BY c.added_at DESC
");
$stmt->execute([$user_id]);
$cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_price = 0;
$total_items = 0;

include __DIR__ . '/includes/header.php';
?>

<div class="container mt-4">
    <!-- Error Feedback Component -->
    <?php if (isset($_SESSION['error_msg'])): ?>
        <div style="background-color: #ffe6e6; color: #d93025; padding: 15px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #f5c6cb;">
            <strong>Error:</strong> <?php echo htmlspecialchars($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?>
        </div>
    <?php endif; ?>

    <div class="admin-header">
        <h2>Shopping Cart</h2>
    </div>

    <?php if (empty($cart_items)): ?>
        <div class="card mt-4" style="text-align: center; padding: 60px 20px;">
            <h3 style="color: var(--text-muted);">Your cart is currently empty.</h3>
            <p>Explore our latest flagship devices and gear.</p>
            <a href="/products.php" class="btn-primary" style="display: inline-block; margin-top: 15px; text-decoration: none;">Shop Now</a>
        </div>
    <?php else: ?>
        <div class="cart-layout mt-4">
            <main class="cart-items-section">
                <form action="/cart.php" method="POST" id="updateCartForm">
                    <input type="hidden" name="action" value="update_cart">
                    
                    <div class="card">
                        <table class="admin-table cart-table">
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
                                <?php foreach ($cart_items as $item): ?>
                                    <?php 
                                        $subtotal = $item['price'] * $item['quantity'];
                                        $total_price += $subtotal;
                                        $total_items += $item['quantity'];
                                        $image_path = $item['image'] === 'default-product.png' ? '/assets/images/default-product.png' : '/assets/uploads/products/' . htmlspecialchars($item['image']);
                                        
                                        $qty_warning = '';
                                        if ($item['quantity'] > $item['stock']) {
                                            $qty_warning = '<br><span style="color:red; font-size:12px;">Only ' . $item['stock'] . ' left in stock!</span>';
                                        }
                                    ?>
                                    <tr>
                                        <td width="80">
                                            <img src="<?php echo $image_path; ?>" alt="Product" style="width: 60px; height: 60px; object-fit: contain; background: #f9f9f9; border-radius: 4px;">
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                            <?php echo $qty_warning; ?>
                                        </td>
                                        <td>RM <?php echo number_format($item['price'], 2); ?></td>
                                        <td>
                                            <input type="number" name="quantities[<?php echo $item['cart_id']; ?>]"
                                                   value="<?php echo $item['quantity']; ?>"
                                                   min="1" max="<?php echo $item['stock']; ?>"
                                                   class="qty-input" onchange="document.getElementById('updateCartForm').submit();">
                                        </td>
                                        <td style="color: var(--primary); font-weight: bold;">
                                            RM <?php echo number_format($subtotal, 2); ?>
                                        </td>
                                        <td>
                                            <button type="button" class="btn-outline btn-sm" style="color: red; border-color: red;"
                                                    onclick="document.getElementById('deleteForm_<?php echo $item['cart_id']; ?>').submit();">
                                                Remove
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>

                <?php foreach ($cart_items as $item): ?>
                    <form action="/cart.php" method="POST" id="deleteForm_<?php echo $item['cart_id']; ?>" style="display:none;">
                        <input type="hidden" name="action" value="remove_item">
                        <input type="hidden" name="cart_id" value="<?php echo $item['cart_id']; ?>">
                    </form>
                <?php endforeach; ?>
            </main>

            <aside class="cart-summary-section">
                <div class="card" style="padding: 24px;">
                    <h3 style="margin-top: 0; border-bottom: 1px solid var(--border); padding-bottom: 15px;">Order Summary</h3>
                    
                    <div style="display: flex; justify-content: space-between; margin: 15px 0;">
                        <span>Total Items:</span>
                        <strong><?php echo $total_items; ?></strong>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; margin: 15px 0; font-size: 18px;">
                        <span>Total Price:</span>
                        <strong style="color: var(--primary);">RM <?php echo number_format($total_price, 2); ?></strong>
                    </div>

                    <form action="/checkout.php" method="POST">
                        <button type="submit" class="btn-primary" style="width: 100%; margin-top: 10px; font-size: 16px; padding: 15px 0;">
                            Proceed to Checkout
                        </button>
                    </form>
                </div>
            </aside>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>