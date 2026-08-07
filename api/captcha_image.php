<?php
// ============================================================
// api/captcha_image.php - serves the challenge image
//
// A separate endpoint rather than a data: URI, so the refresh button
// can request a new one without reloading the whole form.
// ============================================================

require_once __DIR__ . '/../lib/init.php';

$formKey = preg_replace('/[^a-z_]/', '', strtolower(get('form', 'default')));

if ($formKey === '') {
    $formKey = 'default';
}

// A challenge must never be cached, or the browser would show an old
// image while the session already holds a different answer.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$png = captcha_build_image($formKey);

if ($png === null) {
    // Draw a placeholder rather than a broken image icon, so the reason
    // is visible on the page instead of only in the console.
    header('Content-Type: image/svg+xml');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="60">'
       . '<rect width="200" height="60" fill="#f8d7da"/>'
       . '<text x="100" y="27" text-anchor="middle" font-family="sans-serif" '
       . 'font-size="11" fill="#721c24">CAPTCHA unavailable</text>'
       . '<text x="100" y="43" text-anchor="middle" font-family="sans-serif" '
       . 'font-size="9" fill="#721c24">composer require gregwar/captcha</text>'
       . '</svg>';
    exit;
}

header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
echo $png;
