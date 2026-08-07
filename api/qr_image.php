<?php
// ============================================================
// api/qr_image.php - serves a QR code as an image
//
// This endpoint deliberately does NOT encode arbitrary text from the
// query string. It takes a type and a record id and builds the payload
// itself.
//
// An endpoint that renders ?text=<anything> is an open QR generator:
// somebody could point it at a phishing site and hand out codes that
// come from this domain, which is exactly the sort of endorsement a QR
// code implies. Restricting it to two known types removes that.
// ============================================================

require_once __DIR__ . '/../lib/init.php';

$type   = get('type', 'product');
$id     = get_int('id');
$format = get('format', 'svg') === 'png' ? 'png' : 'svg';
$size   = max(80, min(600, (int)get('size', (string)QR_SIZE)));

if ($id === null || $id <= 0) {
    http_response_code(400);
    exit;
}

$payload = null;

switch ($type) {
    case 'order':
        // A receipt code is only shown to the person it belongs to, or
        // to an admin. Otherwise this endpoint would mint valid signed
        // tokens for any order on request, which is precisely what the
        // signature exists to prevent.
        if (!is_logged_in()) {
            http_response_code(403);
            exit;
        }

        $order = is_admin()
            ? db_one('SELECT id FROM orders WHERE id = ?', [$id])
            : db_one('SELECT id FROM orders WHERE id = ? AND user_id = ?', [$id, current_user_id()]);

        if (!$order) {
            http_response_code(404);
            exit;
        }

        $payload = order_verify_url($id);
        break;

    case 'product':
        $product = db_one("SELECT id FROM products WHERE id = ? AND status = 'active'", [$id]);

        if (!$product) {
            http_response_code(404);
            exit;
        }

        $payload = product_qr_url($id);
        break;

    default:
        http_response_code(400);
        exit;
}

// Same input always gives the same image, and the payload is signed, so
// this is safe to cache in the browser. Private, because an order code
// must not sit in a shared proxy cache.
header('Cache-Control: private, max-age=3600');

if ($format === 'png') {
    $png = qr_png($payload, $size);

    if ($png !== null) {
        header('Content-Type: image/png');
        header('Content-Length: ' . strlen($png));
        echo $png;
        exit;
    }
}

$svg = qr_svg($payload, $size);

header('Content-Type: image/svg+xml; charset=UTF-8');

if ($svg !== null) {
    echo $svg;
    exit;
}

// A visible explanation beats a broken image icon: the reason ends up
// on the page instead of only in the error log.
$status = qr_status();
$reason = $status['driver'] === 'none'
    ? 'composer require endroid/qr-code'
    : 'QR generation failed';

echo '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '">'
   . '<rect width="100%" height="100%" fill="#f8d7da"/>'
   . '<text x="50%" y="46%" text-anchor="middle" font-family="sans-serif" '
   . 'font-size="12" fill="#721c24">QR unavailable</text>'
   . '<text x="50%" y="58%" text-anchor="middle" font-family="sans-serif" '
   . 'font-size="9" fill="#721c24">' . e($reason) . '</text>'
   . '</svg>';
