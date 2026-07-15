<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'member') {
    echo json_encode(['status' => 'error', 'message' => 'Please log in as a Member to shop.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$pdo = new PDO("mysql:host=127.0.0.1;dbname=mobile2u;charset=utf8mb4", "root", "", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$action = $_POST['action'] ?? '';
$product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);

if ($action === 'add' && $product_id) {
    // 【适配字段】：stock_quantity 改为 stock
    $stmt = $pdo->prepare("SELECT stock FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $stock = $stmt->fetchColumn();

    if ($stock <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'This product is out of stock.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$user_id, $product_id]);
    $cart_item = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($cart_item) {
        if ($cart_item['quantity'] >= $stock) {
            echo json_encode(['status' => 'error', 'message' => 'Cannot add more. Limit reached.']);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE cart SET quantity = quantity + 1 WHERE id = ?");
        $stmt->execute([$cart_item['id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, 1)");
        $stmt->execute([$user_id, $product_id]);
    }

    $stmt = $pdo->prepare("SELECT SUM(quantity) FROM cart WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $cart_count = $stmt->fetchColumn() ?: 0;

    echo json_encode(['status' => 'success', 'message' => 'Added to cart successfully!', 'cart_count' => $cart_count]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);