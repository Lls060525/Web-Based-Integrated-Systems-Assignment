<?php
// ============================================================
// lib/receipt.php
// E-Receipt generation and delivery.
//
// The receipt HTML is built once and reused three ways:
//   - shown in the browser as a printable page
//   - rendered to PDF by Dompdf for download and email attachment
//   - inlined into the email body
//
// Dompdf is optional. When it is not installed every entry point
// falls back to the printable page, so nothing breaks.
// ============================================================

/** True when order.receipt_no and friends exist (see the migration). */
function receipt_columns_ready(): bool
{
    return db_column_exists('orders', 'receipt_no');
}

/** True when Dompdf is installed via composer. */
function pdf_engine_available(): bool
{
    return class_exists(\Dompdf\Dompdf::class);
}

/**
 * Receipt number for an order, generating and storing one on first use.
 * Format: MU-2026-000042
 */
function receipt_number(array $order): string
{
    $fallback = RECEIPT_PREFIX . '-' . date('Y', strtotime($order['created_at']))
              . '-' . str_pad((string)$order['id'], 6, '0', STR_PAD_LEFT);

    if (!receipt_columns_ready()) {
        return $fallback;
    }

    if (!empty($order['receipt_no'])) {
        return $order['receipt_no'];
    }

    db_exec('UPDATE orders SET receipt_no = ? WHERE id = ?', [$fallback, $order['id']]);
    return $fallback;
}

/**
 * Everything the receipt template needs, in one array.
 * Ownership must already have been checked by the caller.
 */
function receipt_data(int $orderId): ?array
{
    $order = db_one(
        'SELECT o.*, u.name AS customer_name, u.email AS customer_email
           FROM orders o
           JOIN users u ON u.id = o.user_id
          WHERE o.id = ?',
        [$orderId]
    );

    if (!$order) {
        return null;
    }

    $lines    = order_lines($orderId);
    $subtotal = 0.0;

    foreach ($lines as $line) {
        $subtotal += $line['price_at_purchase'] * $line['quantity'];
    }

    return [
        'order'      => $order,
        'lines'      => $lines,
        'subtotal'   => $subtotal,
        'discount'   => (float)($order['discount_amount'] ?? 0),
        'points_off' => (float)($order['points_discount'] ?? 0),
        'total'      => (float)$order['total_amount'],
        'receipt_no' => receipt_number($order),
    ];
}

/**
 * Render the receipt template to an HTML string.
 *
 * The template is entirely self-contained with inline styling, so the
 * same output is valid in the browser, inside Dompdf and in an email
 * body. There is deliberately no "for PDF" variant to keep drift out.
 */
function render_receipt_html(array $data): string
{
    ob_start();
    $receipt = $data;   // the template reads $receipt
    include DIR_ROOT . '/includes/receipt_template.php';
    return (string)ob_get_clean();
}

/**
 * Produce the PDF bytes for a receipt, or null when Dompdf is missing.
 */
function render_receipt_pdf(array $data): ?string
{
    if (!pdf_engine_available()) {
        return null;
    }

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);   // never fetch external URLs
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml(render_receipt_html($data), 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return $dompdf->output();
}

/** Suggested download filename for a receipt. */
function receipt_filename(array $data): string
{
    return 'Receipt-' . $data['receipt_no'] . '.pdf';
}

/**
 * Write the PDF to storage/receipts/ and return the path,
 * or null when no PDF could be produced.
 */
function store_receipt_pdf(array $data): ?string
{
    $pdf = render_receipt_pdf($data);

    if ($pdf === null) {
        return null;
    }

    if (!is_dir(DIR_RECEIPTS) && !mkdir(DIR_RECEIPTS, 0755, true) && !is_dir(DIR_RECEIPTS)) {
        error_log('Cannot create receipt folder: ' . DIR_RECEIPTS);
        return null;
    }

    $path = DIR_RECEIPTS . receipt_filename($data);

    return file_put_contents($path, $pdf) === false ? null : $path;
}

/**
 * Email the receipt to the customer.
 *
 * Returns the send_mail() result with two extras:
 *   'attached' => whether a PDF went with it
 *   'link'     => the on-screen receipt URL
 */
function send_receipt_email(int $orderId): array
{
    $data = receipt_data($orderId);

    if ($data === null) {
        return ['sent' => false, 'mode' => MAIL_MODE, 'error' => 'Order not found.', 'attached' => false, 'link' => ''];
    }

    $order  = $data['order'];
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $link   = $scheme . '://' . $host . '/receipt.php?id=' . $order['id'];

    // The email body is the receipt itself, so it reads properly even
    // in a client that will not open attachments.
    $body = '<p>Hi ' . e($order['customer_name']) . ',</p>'
          . '<p>Thank you for shopping with ' . e(APP_NAME) . '. '
          . 'Your receipt for order #' . (int)$order['id'] . ' is below.</p>'
          . render_receipt_html($data)
          . '<p>You can also view it online: <a href="' . e($link) . '">' . e($link) . '</a></p>';

    $pdfPath  = store_receipt_pdf($data);
    $attached = $pdfPath !== null;

    $result = send_mail(
        $order['customer_email'],
        'Your ' . APP_NAME . ' receipt ' . $data['receipt_no'],
        $body,
        $attached ? [$pdfPath] : []
    );

    if ($result['sent'] && receipt_columns_ready()) {
        db_exec(
            'UPDATE orders
                SET receipt_sent_at = NOW(), receipt_sent_count = receipt_sent_count + 1
              WHERE id = ?',
            [$orderId]
        );
    }

    $result['attached'] = $attached;
    $result['link']     = $link;

    return $result;
}

/** True when this order's receipt may still be resent. */
function can_resend_receipt(array $order): bool
{
    if (!receipt_columns_ready()) {
        return true;
    }
    return (int)($order['receipt_sent_count'] ?? 0) < RECEIPT_MAX_SENDS;
}
