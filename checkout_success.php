<?php
// checkout_success.php
session_start();
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database.php';

// 1. Authentication and parameter validation
if (!isset($_SESSION['user_id']) || (isset($_SESSION['role']) && $_SESSION['role'] !== 'member')) {
    header('Location: /auth/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$session_id = filter_input(INPUT_GET, 'session_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

if (!$session_id) {
    header('Location: /cart.php');
    exit;
}

\Stripe\Stripe::setApiKey('api_key_here'); // Replace with your actual Stripe secret key

try {
    // 2. Verify checkout session payment status from Stripe to prevent spoofing
    $session = \Stripe\Checkout\Session::retrieve($session_id);
    if ($session->payment_status !== 'paid') {
        throw new Exception("Payment not verified as paid by Stripe.");
    }

    // Format shipping address collected by Stripe
    $shipping = $session->shipping_details->address;
    $shipping_address = trim("{$shipping->line1} {$shipping->line2}\n{$shipping->postal_code} {$shipping->city}, {$shipping->state}, {$shipping->country}");

    // ==========================================
    // Begin Database Transaction 
    // Ensure atomic operations for orders, stock, and cart
    // ==========================================
    $pdo->beginTransaction();

    // 3. Lock user's cart data (Pessimistic lock with FOR UPDATE to prevent race conditions)
    $stmt = $pdo->prepare("
        SELECT c.product_id, c.quantity, p.price, p.stock 
        FROM cart c 
        JOIN products p ON c.product_id = p.id 
        WHERE c.user_id = ? FOR UPDATE
    ");
    $stmt->execute([$user_id]);
    $cart_items = $stmt->fetchAll();

    if (empty($cart_items)) {
        // Cart is empty. User might have refreshed the success page. Rollback and redirect.
        $pdo->rollBack();
        header('Location: /orders.php');
        exit;
    }

    $total_amount = 0;
    foreach ($cart_items as $item) {
        $total_amount += ($item['price'] * $item['quantity']);
    }

    // 4. Generate main order record
    $stmt = $pdo->prepare("INSERT INTO orders (user_id, total_amount, status, shipping_address) VALUES (?, ?, 'Pending', ?)");
    $stmt->execute([$user_id, $total_amount, $shipping_address]);
    $order_id = $pdo->lastInsertId();

    // 5. Deduct stock and generate order items
    $insert_item_stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price_at_purchase) VALUES (?, ?, ?, ?)");
    $update_stock_stmt = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");

    foreach ($cart_items as $item) {
        // Record price at the time of purchase (price snapshot)
        $insert_item_stmt->execute([$order_id, $item['product_id'], $item['quantity'], $item['price']]);
        
        // Optimistic locking for stock reduction. If rowCount is 0, stock was depleted concurrently.
        $update_stock_stmt->execute([$item['quantity'], $item['product_id'], $item['quantity']]);
        if ($update_stock_stmt->rowCount() === 0) {
            throw new Exception("Race condition detected: Insufficient stock for Product ID {$item['product_id']} during finalization.");
        }
    }

    // 6. Clear the user's cart
    $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
    $stmt->execute([$user_id]);

    // 7. Commit transaction
    $pdo->commit();

    $_SESSION['success_msg'] = "Payment successful! Your Order #{$order_id} has been securely placed.";
    header('Location: /orders.php');
    exit;

} catch (\Throwable $e) {
    // 1. Rollback the database transaction if an error occurs
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // 2. Log the exact error
    error_log("Order Processing Failed: " . $e->getMessage());
    
    // 3. TEMPORARY DEBUGGING: Output the exact error message and line number to the screen
    $_SESSION['error_msg'] = "DEBUG ERROR: " . $e->getMessage() . " (Line: " . $e->getLine() . ")";
    
    // 4. Redirect back to cart to show the error
    header('Location: /cart.php');
    exit;
}