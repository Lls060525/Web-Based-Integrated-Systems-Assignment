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
require_once __DIR__ . '/prg.php';
require_once __DIR__ . '/ajax.php';
require_once __DIR__ . '/paginate.php';
require_once __DIR__ . '/listing_view.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/role.php';
require_once __DIR__ . '/login_guard.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/address.php';
require_once __DIR__ . '/wishlist.php';
require_once __DIR__ . '/voucher.php';
require_once __DIR__ . '/points.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/review.php';
require_once __DIR__ . '/product_photo.php';
require_once __DIR__ . '/video.php';
require_once __DIR__ . '/image.php';
require_once __DIR__ . '/captcha.php';
require_once __DIR__ . '/remember.php';
require_once __DIR__ . '/batch.php';
require_once __DIR__ . '/qrcode.php';
require_once __DIR__ . '/spec.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/cart.php';
require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/cancellation.php';
require_once __DIR__ . '/receipt.php';
require_once __DIR__ . '/payment.php';
require_once __DIR__ . '/theme.php';

// ---------- Replay a failed form submission ----------
// Picks the validation errors and the submitted values back up after a
// redirect_back(), so a form can show what went wrong without the page
// itself being the answer to a POST. See lib/prg.php.
prg_restore();

// ---------- Remember me ----------
// Runs after the library is loaded and only when the cookie is
// actually present, so a guest browsing the catalogue costs no
// extra query.
if (isset($_COOKIE[REMEMBER_COOKIE])) {
    remember_attempt_login();
}

// ---------- Appearance preference ----------
// After remember-me, so a returning user's own stored theme is found
// rather than the cookie of whoever used the browser last.
//
// Handled here rather than per page because the switcher lives in the
// shared layout and can therefore be submitted from ANY page. A page
// that knew nothing about it would fall through to its own POST
// handler, not recognise the action, and in several cases redirect
// with an error about a form the visitor never filled in.
//
// The redirect makes it a Post/Redirect/Get like every other form here,
// so refreshing afterwards does not re-submit the change.
if (theme_handle_post()) {
    redirect();
}

// ---------- Baseline security response headers ----------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
}
