<?php
// ============================================================
// includes/receipt_template.php
//
// One template, three destinations: the printable page, the PDF
// produced by Dompdf, and the HTML email body.
//
// Styling is deliberately INLINE here rather than in style.css.
// Dompdf supports only a subset of CSS and email clients strip
// <style> blocks entirely, so inline attributes are the only thing
// all three render the same way. This is the one place in the
// project where inline style is the correct engineering choice.
//
// Expects: $receipt = ['order', 'lines', 'subtotal', 'total', 'receipt_no']
// ============================================================

$order  = $receipt['order'];
$lines  = $receipt['lines'];
$isPaid = $order['status'] !== 'cancelled';

$ink    = '#222222';
$muted  = '#6b7280';
$rule   = '#dddddd';
$accent = '#ee4d2d';

// A data: URI, not a URL to /api/qr_image.php.
//
// Dompdf runs with isRemoteEnabled = false, so it will not fetch an
// image over HTTP -- and it should not be allowed to, because that
// setting is what stops a crafted receipt from making the server
// request arbitrary URLs. The image therefore has to travel inside the
// document. The same markup then works in an email client, which also
// blocks remote images by default.
$qrImage     = qr_data_uri(order_verify_url((int)$order['id']), 300);
$qrShortCode = order_short_code((int)$order['id']);
?>
<div style="font-family: DejaVu Sans, Arial, Helvetica, sans-serif; color: <?= $ink ?>; font-size: 12px; line-height: 1.5; max-width: 700px; margin: 0 auto;">

    <!-- Header -->
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
        <tr>
            <td style="vertical-align: top;">
                <div style="font-size: 22px; font-weight: bold; color: <?= $accent ?>;"><?= e(APP_NAME) ?></div>
                <div style="color: <?= $muted ?>; font-size: 11px; margin-top: 4px;">
                    <?= nl2br(e(COMPANY_ADDRESS)) ?><br>
                    <?= e(COMPANY_EMAIL) ?> &middot; <?= e(COMPANY_PHONE) ?><br>
                    Reg. No. <?= e(COMPANY_REG_NO) ?>
                </div>
            </td>
            <td style="vertical-align: top; text-align: right;">
                <div style="font-size: 18px; font-weight: bold; letter-spacing: 1px;">
                    <?= $isPaid ? 'RECEIPT' : 'CANCELLED' ?>
                </div>
                <div style="color: <?= $muted ?>; font-size: 11px; margin-top: 6px;">
                    <strong>No.</strong> <?= e($receipt['receipt_no']) ?><br>
                    <strong>Order</strong> #<?= (int)$order['id'] ?><br>
                    <strong>Date</strong> <?= e(fmt_datetime($order['created_at'])) ?>
                </div>
            </td>
        </tr>
    </table>

    <?php if (!$isPaid): ?>
        <div style="border: 1px solid #f5c6c0; background: #fdecea; color: #8a1608; padding: 10px 12px; margin-bottom: 20px; font-size: 11px;">
            <strong>This order was cancelled<?= !empty($order['cancelled_at']) ? ' on ' . e(fmt_datetime($order['cancelled_at'])) : '' ?>.</strong>
            This document is kept for your records only.
        </div>
    <?php endif; ?>

    <!-- Parties -->
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
        <tr>
            <td style="width: 50%; vertical-align: top; padding-right: 16px;">
                <div style="color: <?= $muted ?>; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px;">Billed to</div>
                <div><strong><?= e($order['customer_name']) ?></strong></div>
                <div style="color: <?= $muted ?>;"><?= e($order['customer_email']) ?></div>
            </td>
            <td style="width: 50%; vertical-align: top;">
                <div style="color: <?= $muted ?>; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px;">Shipped to</div>
                <div style="color: <?= $muted ?>;"><?= nl2br(e($order['shipping_address'])) ?></div>
            </td>
        </tr>
    </table>

    <!-- Line items -->
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px;">
        <thead>
            <tr>
                <th style="text-align: left;   padding: 8px 6px; border-bottom: 2px solid <?= $ink ?>; font-size: 10px; text-transform: uppercase; letter-spacing: .5px;">Description</th>
                <th style="text-align: right;  padding: 8px 6px; border-bottom: 2px solid <?= $ink ?>; font-size: 10px; text-transform: uppercase; letter-spacing: .5px;">Unit Price</th>
                <th style="text-align: center; padding: 8px 6px; border-bottom: 2px solid <?= $ink ?>; font-size: 10px; text-transform: uppercase; letter-spacing: .5px;">Qty</th>
                <th style="text-align: right;  padding: 8px 6px; border-bottom: 2px solid <?= $ink ?>; font-size: 10px; text-transform: uppercase; letter-spacing: .5px;">Amount</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($lines as $line): ?>
            <?php $amount = $line['price_at_purchase'] * $line['quantity']; ?>
            <tr>
                <td style="padding: 9px 6px; border-bottom: 1px solid <?= $rule ?>;">
                    <?= e($line['product_name']) ?>
                    <?php if (!empty($line['options_text'])): ?>
                        <div style="color: <?= $muted ?>; font-size: 10px;"><?= e($line['options_text']) ?></div>
                    <?php endif; ?>
                </td>
                <td style="padding: 9px 6px; border-bottom: 1px solid <?= $rule ?>; text-align: right;"><?= e(money($line['price_at_purchase'])) ?></td>
                <td style="padding: 9px 6px; border-bottom: 1px solid <?= $rule ?>; text-align: center;"><?= (int)$line['quantity'] ?></td>
                <td style="padding: 9px 6px; border-bottom: 1px solid <?= $rule ?>; text-align: right;"><?= e(money($amount)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Totals -->
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 28px;">
        <tr>
            <td style="width: 60%;"></td>
            <td style="width: 40%;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 5px 6px; color: <?= $muted ?>;">Subtotal</td>
                        <td style="padding: 5px 6px; text-align: right;"><?= e(money($receipt['subtotal'])) ?></td>
                    </tr>
                    <?php if (!empty($receipt['discount']) && $receipt['discount'] > 0): ?>
                        <tr>
                            <td style="padding: 5px 6px; color: <?= $muted ?>;">
                                Voucher
                                <?php if (!empty($order['voucher_code'])): ?>
                                    (<?= e($order['voucher_code']) ?>)
                                <?php endif; ?>
                            </td>
                            <td style="padding: 5px 6px; text-align: right; color: #1e8e3e;">
                                &minus; <?= e(money($receipt['discount'])) ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php if (!empty($receipt['points_off']) && $receipt['points_off'] > 0): ?>
                        <tr>
                            <td style="padding: 5px 6px; color: <?= $muted ?>;">
                                Reward points
                                <?php if (!empty($order['points_redeemed'])): ?>
                                    (<?= number_format((int)$order['points_redeemed']) ?> pts)
                                <?php endif; ?>
                            </td>
                            <td style="padding: 5px 6px; text-align: right; color: #1e8e3e;">
                                &minus; <?= e(money($receipt['points_off'])) ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td style="padding: 5px 6px; color: <?= $muted ?>;">Shipping</td>
                        <td style="padding: 5px 6px; text-align: right;">Free</td>
                    </tr>
                    <tr>
                        <td style="padding: 10px 6px; border-top: 2px solid <?= $ink ?>; font-weight: bold; font-size: 14px;">Total</td>
                        <td style="padding: 10px 6px; border-top: 2px solid <?= $ink ?>; font-weight: bold; font-size: 14px; text-align: right; color: <?= $accent ?>;">
                            <?= e(money($receipt['total'])) ?>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- Verification -->
    <table style="width: 100%; border-collapse: collapse; margin: 22px 0 6px;">
        <tr>
            <?php if ($qrImage !== null): ?>
                <td style="width: 96px; vertical-align: top; padding-right: 14px;">
                    <img src="<?= $qrImage ?>" alt="Receipt verification code" width="90" height="90"
                         style="display: block; border: 1px solid <?= $rule ?>;">
                </td>
            <?php endif; ?>
            <td style="vertical-align: top; font-size: 10px; color: <?= $muted ?>;">
                <p style="margin: 0 0 4px; font-weight: bold; color: <?= $ink ?>; font-size: 11px;">
                    Verify this receipt
                </p>
                <?php if ($qrImage !== null): ?>
                    <p style="margin: 0 0 4px;">
                        Scan the code, or go to <strong><?= e(base_url()) ?>/verify.php</strong>
                        and enter the reference below.
                    </p>
                <?php else: ?>
                    <p style="margin: 0 0 4px;">
                        Go to <strong><?= e(base_url()) ?>/verify.php</strong> and enter this reference.
                    </p>
                <?php endif; ?>
                <p style="margin: 0; font-family: DejaVu Sans Mono, Courier New, monospace; font-size: 13px; letter-spacing: 1px; color: <?= $ink ?>;">
                    <?= e($qrShortCode) ?>
                </p>
            </td>
        </tr>
    </table>

    <!-- Footer -->
    <div style="border-top: 1px solid <?= $rule ?>; padding-top: 12px; color: <?= $muted ?>; font-size: 10px; text-align: center;">
        <?php if ($isPaid): ?>
            <p style="margin: 0 0 4px;">Thank you for shopping with <?= e(APP_NAME) ?>.</p>
        <?php endif; ?>
        <?php if (!empty($order['points_earned']) && (int)$order['points_earned'] > 0): ?>
            <p style="margin: 0 0 4px;">
                You earned <strong><?= number_format((int)$order['points_earned']) ?></strong>
                reward points on this order.
            </p>
        <?php endif; ?>
        <p style="margin: 0 0 4px;">
            This is a computer generated receipt. No signature is required.
        </p>
        <p style="margin: 0;">
            Questions about this receipt? Contact <?= e(COMPANY_EMAIL) ?> quoting <?= e($receipt['receipt_no']) ?>.
        </p>
    </div>
</div>
