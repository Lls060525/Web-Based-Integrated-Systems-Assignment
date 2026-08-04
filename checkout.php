<?php
// checkout.php
session_start();
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database.php';

// 1. Authentication Check
if (!isset($_SESSION['user_id']) || (isset($_SESSION['role']) && $_SESSION['role'] !== 'member')) {
    header('Location: /auth/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// 2. Initialize Stripe Secret Key (Highly recommend moving to .env for production)
\Stripe\Stripe::setApiKey('api_key_here'); // Replace with your actual Stripe secret key

// 3. Retrieve cart data
$stmt = $pdo->prepare("
    SELECT c.quantity, p.id AS product_id, p.name, p.price, p.stock 
    FROM cart c 
    JOIN products p ON c.product_id = p.id 
    WHERE c.user_id = ?
");
$stmt->execute([$user_id]);
$cart_items = $stmt->fetchAll();

if (empty($cart_items)) {
    $_SESSION['error_msg'] = "Your cart is empty.";
    header('Location: /cart.php');
    exit;
}

// 4. Build Stripe Line Items and perform pre-checkout stock validation
$line_items = [];
foreach ($cart_items as $item) {
    if ($item['quantity'] > $item['stock']) {
        $_SESSION['error_msg'] = "Insufficient stock for " . htmlspecialchars($item['name']) . ". Only {$item['stock']} left.";
        header('Location: /cart.php');
        exit;
    }

    $line_items[] = [
        'price_data' => [
            'currency' => 'myr',
            'product_data' => [
                'name' => $item['name'],
            ],
            // Stripe API price unit is in cents (sen), RM 1.00 = 100 sen
            'unit_amount' => (int)round($item['price'] * 100), 
        ],
        'quantity' => $item['quantity'],
    ];
}

// 5. Dynamically construct the domain URL for callbacks
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$domain_url = $protocol . '://' . $_SERVER['HTTP_HOST'];

// 6. Create Stripe Checkout Session
try {
    $checkout_session = \Stripe\Checkout\Session::create([
        'payment_method_types' => ['card', 'fpx', 'grabpay'],
        'line_items' => $line_items,
        'mode' => 'payment',
        'shipping_address_collection' => [
            'allowed_countries' => ['MY'], 
        ],
        'success_url' => $domain_url . '/checkout_success.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $domain_url . '/checkout_cancel.php',
    ]);

    // Redirect to Stripe hosted checkout page
    header("HTTP/1.1 303 See Other");
    header("Location: " . $checkout_session->url);
    exit;

} catch (\Exception $e) {
    error_log("Stripe Checkout Error: " . $e->getMessage());
    $_SESSION['error_msg'] = "Failed to initialize payment gateway. Please ensure your API keys are correct.";
    header('Location: /cart.php');
    exit;
}