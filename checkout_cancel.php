<?php
// checkout_cancel.php
session_start();

if (!isset($_SESSION['user_id']) || (isset($_SESSION['role']) && $_SESSION['role'] !== 'member')) {
    header('Location: /auth/login.php');
    exit;
}

// Provide user-friendly feedback
$_SESSION['error_msg'] = "Payment was cancelled. Your cart is saved, you can complete the checkout whenever you're ready.";

header('Location: /cart.php');
exit;