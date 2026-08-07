<?php
// ============================================================
// lib/qrcode.php
// Generating QR codes, and the signed payloads that go inside them.
//
// The important part of this module is not drawing squares. It is what
// gets encoded.
//
// A QR code printed on a receipt is public the moment it leaves the
// printer: anyone who can see the paper can photograph it. So the code
// must not contain a bare record id. If it did, /verify.php?order=5
// would let a stranger walk through every order in the system just by
// changing the number.
//
// Instead each code carries an HMAC signature over its payload. The
// server can confirm it issued the code, and nobody without QR_SECRET
// can mint a new one or alter an existing one.
// ============================================================

// ------------------------------------------------------------
// Which drawing library is available
// ------------------------------------------------------------

/**
 * Detect the installed QR package.
 *
 * Endroid changed its API substantially between v4 and v5 (a static
 * factory became a constructor, and the options became enums), and
 * there is no way to know from here which one composer resolved. Rather
 * than assume, this probes for what is actually loaded, the same way
 * lib/captcha.php picks its driver.
 *
 * @return string 'endroid5' | 'endroid4' | 'none'
 */
function qr_driver(): string
{
    static $driver = null;

    if ($driver !== null) {
        return $driver;
    }

    if (class_exists('\Endroid\QrCode\Writer\SvgWriter')) {
        // v5 dropped the static QrCode::create() factory.
        $driver = method_exists('\Endroid\QrCode\QrCode', 'create') ? 'endroid4' : 'endroid5';
    } else {
        $driver = 'none';
    }

    return $driver;
}

/** True when a QR image can actually be drawn. */
function qr_module_ready(): bool
{
    return qr_driver() !== 'none';
}

/** Human-readable state for the diagnostics page. */
function qr_status(): array
{
    $driver = qr_driver();

    return [
        'driver'    => $driver,
        'ready'     => $driver !== 'none',
        'gd'        => extension_loaded('gd'),
        'secret_ok' => QR_SECRET !== 'change-this-to-a-long-random-string-before-submission',
    ];
}

// ------------------------------------------------------------
// Drawing
// ------------------------------------------------------------

/**
 * Render a QR code as SVG markup.
 *
 * SVG rather than PNG is the default for anything shown in a browser:
 * it needs no GD extension, it stays sharp when a customer pinches to
 * zoom on a phone, and it prints at printer resolution instead of at
 * whatever pixel size happened to be chosen here.
 */
function qr_svg(string $data, int $size = QR_SIZE): ?string
{
    if (!qr_module_ready()) {
        return null;
    }

    try {
        $writer = new \Endroid\QrCode\Writer\SvgWriter();

        if (qr_driver() === 'endroid5') {
            $qr = new \Endroid\QrCode\QrCode(
                data: $data,
                size: $size,
                margin: QR_MARGIN
            );
        } else {
            $qr = \Endroid\QrCode\QrCode::create($data);
            $qr->setSize($size);
            $qr->setMargin(QR_MARGIN);
        }

        return $writer->write($qr)->getString();

    } catch (\Throwable $e) {
        error_log('QR SVG failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Render a QR code as PNG bytes.
 *
 * Needed for the PDF receipt: Dompdf's SVG support is partial, so the
 * one place that cannot use SVG is the one place that matters most.
 * Requires the GD extension.
 */
function qr_png(string $data, int $size = QR_SIZE): ?string
{
    if (!qr_module_ready() || !extension_loaded('gd')) {
        return null;
    }

    try {
        $writer = new \Endroid\QrCode\Writer\PngWriter();

        if (qr_driver() === 'endroid5') {
            $qr = new \Endroid\QrCode\QrCode(data: $data, size: $size, margin: QR_MARGIN);
        } else {
            $qr = \Endroid\QrCode\QrCode::create($data);
            $qr->setSize($size);
            $qr->setMargin(QR_MARGIN);
        }

        return $writer->write($qr)->getString();

    } catch (\Throwable $e) {
        error_log('QR PNG failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * A data: URI for embedding straight into HTML.
 *
 * The PDF path uses this because Dompdf runs with isRemoteEnabled=false
 * -- it will not fetch /api/qr_image.php over HTTP, and it should not
 * be allowed to, so the image has to travel inside the document.
 */
function qr_data_uri(string $data, int $size = QR_SIZE): ?string
{
    $png = qr_png($data, $size);

    if ($png === null) {
        return null;
    }

    return 'data:image/png;base64,' . base64_encode($png);
}

// ------------------------------------------------------------
// Signed payloads
// ------------------------------------------------------------

/** HMAC over a payload, truncated to 128 bits. */
function qr_sign(string $payload): string
{
    return substr(hash_hmac('sha256', $payload, QR_SECRET), 0, 32);
}

/** Attach a signature to a payload. */
function qr_make_token(string $payload): string
{
    return $payload . '.' . qr_sign($payload);
}

/**
 * Validate a token and return its payload, or null.
 *
 * hash_equals is a constant-time comparison, so an attacker cannot
 * discover the correct signature one character at a time by measuring
 * how long the rejection takes.
 */
function qr_read_token(string $token): ?string
{
    $cut = strrpos($token, '.');

    if ($cut === false || $cut === 0) {
        return null;
    }

    $payload   = substr($token, 0, $cut);
    $signature = substr($token, $cut + 1);

    if (!hash_equals(qr_sign($payload), $signature)) {
        return null;
    }

    return $payload;
}

// ------------------------------------------------------------
// Order codes
// ------------------------------------------------------------

/** The token encoded in an order's QR code. */
function order_qr_token(int $orderId): string
{
    return qr_make_token('o' . $orderId);
}

/** Absolute URL a scanner lands on. */
function order_verify_url(int $orderId): string
{
    return base_url() . '/verify.php?t=' . urlencode(order_qr_token($orderId));
}

/** Recover an order id from a scanned token, or null. */
function order_id_from_token(string $token): ?int
{
    $payload = qr_read_token($token);

    if ($payload === null || $payload === '' || $payload[0] !== 'o') {
        return null;
    }

    $id = substr($payload, 1);

    return ctype_digit($id) ? (int)$id : null;
}

// ------------------------------------------------------------
// Short code: the fallback for when scanning is not possible
// ------------------------------------------------------------

/** Encode an integer in the confusion-free alphabet, left padded. */
function qr_base32(int $number, int $length): string
{
    $alphabet = QR_ALPHABET;
    $base     = strlen($alphabet);
    $out      = '';

    do {
        $out    = $alphabet[$number % $base] . $out;
        $number = intdiv($number, $base);
    } while ($number > 0);

    return str_pad($out, $length, '0', STR_PAD_LEFT);
}

/** Decode a string produced by qr_base32(), or null if it has a stray character. */
function qr_base32_decode(string $text): ?int
{
    $alphabet = QR_ALPHABET;
    $base     = strlen($alphabet);
    $value    = 0;

    if ($text === '') {
        return null;
    }

    foreach (str_split($text) as $char) {
        $index = strpos($alphabet, $char);

        if ($index === false) {
            return null;
        }

        $value = $value * $base + $index;
    }

    return $value;
}

/**
 * The code printed under the QR, for example M2U-004K-QW7T2H.
 *
 * A camera is not always an option: the phone is flat, the screen is
 * cracked, the receipt got wet. Every real counter system has a code
 * you can read out, so this one does too.
 *
 * The first block is the order id, the second is part of the signature.
 * That means a code can be checked by arithmetic instead of by scanning
 * the whole orders table looking for a match.
 */
function order_short_code(int $orderId): string
{
    // qr_sign() returns hex, so uppercasing it yields 0-9 and A-F only,
    // every one of which is in QR_ALPHABET. No further mapping needed.
    $body  = qr_base32($orderId, 4);
    $check = strtoupper(substr(qr_sign('o' . $orderId), 0, 6));

    return 'M2U-' . $body . '-' . $check;
}

/**
 * Turn a typed short code back into an order id.
 *
 * Punctuation and case are ignored, because somebody reading a code off
 * paper will type it however it looks to them.
 */
function order_id_from_short_code(string $code): ?int
{
    $clean = strtoupper(preg_replace('~[^A-Za-z0-9]~', '', $code));

    if (str_starts_with($clean, 'M2U')) {
        $clean = substr($clean, 3);
    }

    if (strlen($clean) !== 10) {
        return null;
    }

    $orderId = qr_base32_decode(substr($clean, 0, 4));

    if ($orderId === null || $orderId <= 0) {
        return null;
    }

    // Recomputed rather than looked up, and compared in constant time.
    if (!hash_equals(substr(order_short_code($orderId), -6), substr($clean, 4))) {
        return null;
    }

    return $orderId;
}

// ------------------------------------------------------------
// Product codes
// ------------------------------------------------------------

/**
 * Product codes point at a public catalogue page, so they carry no
 * signature: there is nothing to protect and a shorter payload makes a
 * less dense code, which scans more reliably from a printed shelf label.
 */
function product_qr_url(int $productId): string
{
    return base_url() . '/product_detail.php?id=' . $productId;
}

/**
 * What the public verification page is allowed to reveal.
 *
 * Deliberately narrow. Whoever is holding the receipt is not
 * necessarily the customer, so this confirms the document is genuine
 * without handing over the delivery address, the email address or the
 * full name.
 */
function order_verification_summary(int $orderId): ?array
{
    // receipt_no only exists once the e-receipt migration has been run,
    // so it is selected conditionally rather than assumed.
    $receiptColumn = receipt_columns_ready() ? 'o.receipt_no' : 'NULL AS receipt_no';

    $order = db_one(
        "SELECT o.id, o.total_amount, o.status, o.created_at, $receiptColumn,
                u.name AS customer_name
           FROM orders o
           JOIN users u ON u.id = o.user_id
          WHERE o.id = ?",
        [$orderId]
    );

    if (!$order) {
        return null;
    }

    $itemCount = (int)db_value(
        'SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = ?', [$orderId]
    );

    // "Tan Wen Fai" becomes "Tan W. F." -- enough for staff to match
    // against an ID card, not enough to be worth harvesting.
    $parts  = preg_split('~\s+~', trim((string)$order['customer_name']));
    $masked = array_shift($parts) ?: '';

    foreach ($parts as $part) {
        $masked .= ' ' . mb_substr($part, 0, 1) . '.';
    }

    return [
        'id'         => (int)$order['id'],
        'receipt_no' => $order['receipt_no'] ?: receipt_number($order),
        'status'     => $order['status'],
        'total'      => (float)$order['total_amount'],
        'created_at' => $order['created_at'],
        'items'      => $itemCount,
        'customer'   => $masked,
        'short_code' => order_short_code((int)$order['id']),
    ];
}
