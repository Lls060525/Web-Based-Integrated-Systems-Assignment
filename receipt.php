<?php
// ============================================================
// receipt.php - E-Receipt
//
// One entry point, three modes:
//   ?id=N            printable HTML page
//   ?id=N&mode=pdf   PDF download (falls back to the page)
//   ?id=N&mode=send  email the receipt, then redirect back
//
// Access: the member who owns the order, or any admin.
// ============================================================

require_once __DIR__ . '/lib/init.php';

require_login();

$orderId = get_int('id');
$mode    = get('mode');

if ($orderId === null) {
    flash_error('Invalid order number.');
    redirect('/orders.php');
}

// ---------- Authorization ----------
// An admin may view any receipt; a member only their own.
$order = is_admin()
    ? db_one('SELECT * FROM orders WHERE id = ?', [$orderId])
    : find_member_order($orderId, current_user_id());

if (!$order) {
    flash_error('Order not found.');
    redirect(is_admin() ? '/admin/orders.php' : '/orders.php');
}

$backUrl = is_admin()
    ? '/admin/order_detail.php?id=' . $orderId
    : '/order_detail.php?id=' . $orderId;

// ---------- Mode: email the receipt ----------
if ($mode === 'send') {
    // State-changing, so it must arrive as a verified POST.
    if (!is_post()) {
        redirect($backUrl);
    }
    csrf_check();

    if (!can_resend_receipt($order)) {
        flash_error('This receipt has already been sent the maximum number of times.');
        redirect($backUrl);
    }

    // RECEIPT_MAX_SENDS is a lifetime cap, not a double-click guard: two
    // fast clicks both pass it and both burn a send. The one-use nonce is
    // what makes one click send one email.
    if (!form_nonce_valid('receipt_send_' . $orderId)) {
        flash_error('That receipt was already sent. Reload the page if you want to send it again.');
        redirect($backUrl);
    }

    $wait = action_cooldown('receipt_send', RECEIPT_RESEND_COOLDOWN);

    if ($wait > 0) {
        flash_error('Please wait ' . $wait . ' more second' . ($wait === 1 ? '' : 's')
                  . ' before sending another receipt.');
        redirect($backUrl);
    }

    action_touch('receipt_send');

    $result = send_receipt_email($orderId);

    if (!$result['sent']) {
        error_log('Receipt email failed: ' . ($result['error'] ?? 'unknown'));
        flash_error('We could not send the receipt just now. Please try again.');

    } elseif ($result['mode'] === 'dev') {
        flash_success(
            'Receipt generated. Mail delivery is in development mode, so it was written to '
            . 'storage/mail.log instead of being sent'
            . ($result['attached'] ? ', with the PDF saved to storage/receipts/.' : '. Install Dompdf to attach a PDF.')
        );
    } else {
        flash_success('Receipt emailed successfully'
            . ($result['attached'] ? ' with the PDF attached.' : '.'));
    }

    redirect($backUrl);
}

$data = receipt_data($orderId);

if ($data === null) {
    flash_error('Could not build the receipt.');
    redirect($backUrl);
}

// ---------- Mode: PDF download ----------
if ($mode === 'pdf') {
    $pdf = render_receipt_pdf($data);

    if ($pdf !== null) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . receipt_filename($data) . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $pdf;
        exit;
    }

    // Dompdf is not installed - fall through to the printable page.
    $pdfUnavailable = true;
}

// ---------- Mode: printable page ----------
$title = 'Receipt ' . $data['receipt_no'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="/assets/js/main.js" defer></script>
    <style>
        body { background: #f5f5f5; margin: 0; padding: 24px 16px; font-family: 'Segoe UI', Roboto, Arial, sans-serif; }
        .receipt-sheet {
            background: #fff;
            max-width: 760px;
            margin: 0 auto;
            padding: 40px;
            border-radius: 6px;
            box-shadow: 0 1px 6px rgba(0,0,0,.08);
        }
        .receipt-toolbar {
            max-width: 760px;
            margin: 0 auto 16px;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .receipt-toolbar form { margin: 0; }
        .receipt-toolbar .spacer { flex: 1; }
        .rbtn {
            display: inline-block;
            padding: 9px 16px;
            border: 1px solid #e6e9ef;
            border-radius: 4px;
            background: #fff;
            color: #222;
            font-size: 14px;
            font-family: inherit;
            text-decoration: none;
            cursor: pointer;
        }
        .rbtn:hover { background: #f7f8fa; }
        .rbtn-primary { background: #ee4d2d; border-color: #ee4d2d; color: #fff; }
        .rbtn-primary:hover { background: #d73211; }
        .rnote {
            max-width: 760px;
            margin: 0 auto 16px;
            padding: 12px 14px;
            border-radius: 4px;
            font-size: 13px;
            background: #eaf2fd;
            color: #0b4a8f;
            border: 1px solid #c2d9f5;
        }

        /* Printing must produce the sheet alone, nothing else. */
        @media print {
            body { background: #fff; padding: 0; }
            .receipt-toolbar, .rnote { display: none !important; }
            .receipt-sheet { box-shadow: none; max-width: none; padding: 0; border-radius: 0; }
            @page { margin: 16mm; }
        }
    </style>
</head>
<body>

<div class="receipt-toolbar">
    <a href="<?= e($backUrl) ?>" class="rbtn">&larr; Back to order</a>

    <span class="spacer"></span>

    <button type="button" class="rbtn js-print">Print</button>

    <?php if (pdf_engine_available()): ?>
        <a href="/receipt.php?id=<?= (int)$orderId ?>&amp;mode=pdf" class="rbtn">Download PDF</a>
    <?php endif; ?>

    <?php if (can_resend_receipt($order)): ?>
        <form action="/receipt.php?id=<?= (int)$orderId ?>&amp;mode=send" method="POST"
              data-confirm="Email this receipt to the customer?">
            <?php csrf_field(); ?>
            <button type="submit" class="rbtn rbtn-primary">Email Receipt</button>
        </form>
    <?php endif; ?>
</div>

<?php if (!empty($pdfUnavailable)): ?>
    <div class="rnote">
        <strong>PDF export is not installed.</strong>
        Run <code>composer require dompdf/dompdf</code> in the project folder to enable it.
        In the meantime use <strong>Print</strong> and choose &ldquo;Save as PDF&rdquo;.
    </div>
<?php endif; ?>

<?php if (!empty($order['receipt_sent_at'])): ?>
    <div class="rnote">
        Last emailed on <?= e(fmt_datetime($order['receipt_sent_at'])) ?>
        (<?= (int)($order['receipt_sent_count'] ?? 0) ?> time<?= (int)($order['receipt_sent_count'] ?? 0) === 1 ? '' : 's' ?>).
    </div>
<?php endif; ?>

<div class="receipt-sheet">
    <?= render_receipt_html($data) ?>
</div>

</body>
</html>
