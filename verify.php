<?php
// ============================================================
// verify.php - where a scanned receipt QR code lands
//
// Public on purpose: the point of the code is that anybody holding the
// receipt can confirm it is genuine without needing an account.
//
// Because it is public, it shows the minimum that makes the check
// meaningful. Whoever is holding the paper is not necessarily the
// customer, so there is no address, no email and no full name here.
// ============================================================

require_once __DIR__ . '/lib/init.php';

$title = 'Verify Receipt - ' . APP_NAME;

$token   = get('t');
$typed   = post('code', get('code'));
$summary = null;
$notice  = '';

if (is_post()) {
    csrf_check();
    $typed = post('code');
}

$orderId = null;

if ($token !== '') {
    $orderId = order_id_from_token($token);

    if ($orderId === null) {
        $notice = 'That code could not be verified. It may have been damaged, '
                . 'retyped by hand, or it did not come from ' . APP_NAME . '.';
    }

} elseif (trim((string)$typed) !== '') {
    $orderId = order_id_from_short_code($typed);

    if ($orderId === null) {
        $notice = 'That reference is not valid. Check every character &mdash; '
                . 'the code looks like M2U-001A-61B7E3.';
    }
}

if ($orderId !== null) {
    $summary = order_verification_summary($orderId);

    if ($summary === null) {
        $notice = 'This receipt refers to an order that no longer exists.';
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="verify-page">
    <div class="verify-card">

        <?php if ($summary !== null): ?>

            <?php $cancelled = $summary['status'] === 'cancelled'; ?>

            <div class="verify-badge <?= $cancelled ? 'is-warning' : 'is-valid' ?>">
                <i class="fas <?= $cancelled ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
            </div>

            <h2 class="verify-title">
                <?= $cancelled ? 'Genuine, but cancelled' : 'Genuine receipt' ?>
            </h2>

            <p class="verify-subtitle">
                This receipt was issued by <?= e(APP_NAME) ?>.
                <?php if ($cancelled): ?>
                    The order behind it has since been cancelled.
                <?php endif; ?>
            </p>

            <dl class="verify-details">
                <dt>Receipt number</dt>
                <dd><?= e($summary['receipt_no']) ?></dd>

                <dt>Reference</dt>
                <dd><code><?= e($summary['short_code']) ?></code></dd>

                <dt>Issued</dt>
                <dd><?= e(fmt_datetime($summary['created_at'])) ?></dd>

                <dt>Customer</dt>
                <dd><?= e($summary['customer']) ?></dd>

                <dt>Items</dt>
                <dd><?= (int)$summary['items'] ?></dd>

                <dt>Total</dt>
                <dd><strong><?= e(money($summary['total'])) ?></strong></dd>

                <dt>Status</dt>
                <dd>
                    <span class="badge badge-<?= $cancelled ? 'danger' : 'success' ?>">
                        <?= e(order_status_label($summary['status'])) ?>
                    </span>
                </dd>
            </dl>

            <p class="muted small-note verify-privacy">
                <i class="fas fa-lock"></i>
                Only these details are shown. The delivery address and contact
                details are never published here, because anyone holding the
                receipt can reach this page.
            </p>

            <?php if (is_logged_in() && (is_admin() || (int)$summary['id'] > 0)): ?>
                <div class="verify-actions">
                    <?php if (is_admin()): ?>
                        <a href="/admin/order_detail.php?id=<?= (int)$summary['id'] ?>" class="btn-primary">
                            Open Full Order
                        </a>
                    <?php else: ?>
                        <a href="/orders.php" class="btn-outline">My Orders</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php else: ?>

            <div class="verify-badge <?= $notice !== '' ? 'is-invalid' : 'is-neutral' ?>">
                <i class="fas <?= $notice !== '' ? 'fa-circle-xmark' : 'fa-qrcode' ?>"></i>
            </div>

            <h2 class="verify-title">
                <?= $notice !== '' ? 'Could not verify' : 'Verify a receipt' ?>
            </h2>

            <?php if ($notice !== ''): ?>
                <p class="verify-subtitle"><?= $notice ?></p>
            <?php else: ?>
                <p class="verify-subtitle">
                    Scan the QR code on your receipt, or type the reference printed
                    underneath it.
                </p>
            <?php endif; ?>

            <form action="/verify.php" method="POST" class="form-standard verify-form">
                <?php csrf_field(); ?>

                <?php field('code', 'Receipt reference', function () use ($typed) {
                    html_text('code', (string)$typed, [
                        'placeholder'    => 'M2U-001A-61B7E3',
                        'autocomplete'   => 'off',
                        'autocapitalize' => 'characters',
                        'spellcheck'     => 'false',
                        'class'          => 'verify-input',
                    ]);
                }); ?>

                <div class="form-actions">
                    <?php html_submit('Verify', ['class' => 'btn-primary btn-block']); ?>
                </div>
            </form>

        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
