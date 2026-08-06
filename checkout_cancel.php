<?php
// ============================================================
// checkout_cancel.php - Stripe cancel callback
// ============================================================

require_once __DIR__ . '/lib/init.php';

require_member();

flash_error('Payment was cancelled. Your cart has been saved, so you can check out whenever you are ready.');

redirect('/cart.php');
