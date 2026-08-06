<?php
// ============================================================
// lib/init.php
// Single bootstrap file. EVERY page starts with:
//
//     require_once __DIR__ . '/lib/init.php';
//
// It starts the session with hardened cookie settings and loads
// the whole base library.
// ============================================================

require_once __DIR__ . '/config.php';

// ---------- Error reporting ----------
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');   // never show stack traces to users
    ini_set('log_errors', '1');
}

// ---------- Session ----------
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,  // JavaScript cannot read the session cookie
        'samesite' => 'Lax', // blocks the cross-site request part of CSRF
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

// ---------- Composer autoloader ----------
// Must come before the base library so optional packages (Dompdf,
// PHPMailer, Stripe) are visible to every page, not just checkout.
// Guarded with file_exists so the project still runs before anyone
// has run "composer install".
$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

// ---------- Base library ----------
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/login_guard.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/address.php';
require_once __DIR__ . '/wishlist.php';
require_once __DIR__ . '/voucher.php';
require_once __DIR__ . '/points.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/receipt.php';

// ---------- Baseline security response headers ----------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
}
