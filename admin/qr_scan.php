<?php
// ============================================================
// admin/qr_scan.php - Scan QR Code (Admin)
//
// A counter tool: the customer shows the QR code on their receipt or
// their phone, staff scan it, and the order comes up straight away.
//
// Decoding happens in the browser. The camera stream never leaves the
// machine; only the decoded text is sent to the server. That is both
// faster and less to explain in a privacy policy than uploading frames.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

require_permission('qr.scan');
require_once __DIR__ . '/../includes/status_actions.php';

$title = 'Scan QR Code - Admin';

// ---------- Act on a scanned order ----------
//
// A normal form POST rather than AJAX. The lookup stays AJAX because
// looking is cheap and instant matters at a counter; ACTING carries a
// photograph and changes the order, so it goes through the same
// Post/Redirect/Get path as every other write on the site. F5 after
// marking a parcel shipped must not offer to ship it again.
if (is_post()) {
    csrf_check();

    if (post('action') === 'advance') {
        $orderId   = post_int('order_id');
        $newStatus = post('to_status');

        if ($orderId === null) {
            flash_error('That order could not be identified. Please scan again.');
        } else {
            $result = handle_status_change($orderId, $newStatus, post('note'));

            $result['ok']
                ? flash_success($result['message'])
                : flash_error($result['message']);

            // Straight back to the same order so the new status is
            // visible without scanning the code a second time.
            redirect('/admin/qr_scan.php?order=' . $orderId);
        }
    }


    if (post('action') === 'request_cancel') {
        $target = db_one('SELECT * FROM orders WHERE id = ?', [post_int('order_id')]);

        if ($target === false || $target === null) {
            flash_error('That order no longer exists.');
        } else {
            $result = request_cancellation($target, 'admin', post('cancel_reason'), post('note'));

            $result['ok']
                ? flash_success($result['message'])
                : flash_error($result['message']);
        }

        redirect('/admin/qr_scan.php?order=' . (int)post_int('order_id'));
    }

    redirect('/admin/qr_scan.php');
}

// ---------- An order to show on load ----------
//
// Set after acting, so the result of the change is on screen. Also lets
// the page be opened straight onto an order from a link.
$scanned = null;
$openId  = get_int('order');

if ($openId !== null) {
    $scanned = db_one(
        'SELECT o.*, u.name AS customer_name, u.email AS customer_email
           FROM orders o
           JOIN users u ON u.id = o.user_id
          WHERE o.id = ?',
        [$openId]
    ) ?: null;
}

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Scan QR Code</h2>
    </div>

    <div class="qr-scan-layout">

        <div class="card card-padded">
            <h3 class="section-heading">Camera</h3>

            <div class="qr-stage">
                <video id="qrVideo" class="qr-video" playsinline muted hidden></video>
                <canvas id="qrCanvas" class="qr-canvas" hidden></canvas>

                <div id="qrPlaceholder" class="qr-placeholder">
                    <i class="fas fa-camera"></i>
                    <p>The camera preview appears here.</p>
                </div>

                <div id="qrReticle" class="qr-reticle" hidden></div>
            </div>

            <p id="qrStatus" class="qr-status muted small-note">Camera is off.</p>

            <div class="qr-controls">
                <?php html_button('Start Camera', ['id' => 'qrStart', 'class' => 'btn-primary']); ?>
                <?php html_button('Stop', ['id' => 'qrStop', 'class' => 'btn-outline', 'hidden' => true]); ?>
                <?php html_button('Switch Camera', ['id' => 'qrSwitch', 'class' => 'btn-outline', 'hidden' => true]); ?>
            </div>

            <div id="qrUnsupported" class="alert alert-warning" hidden></div>

            <hr class="qr-divider">

            <h3 class="section-heading">Or type the reference</h3>
            <p class="muted small-note">
                Printed under the QR code. Use this when the camera is unavailable
                or the code is too damaged to scan.
            </p>

            <form id="qrManualForm" class="form-standard" autocomplete="off">
                <div class="form-group">
                    <?php html_text('manual_code', '', [
                        'id'             => 'qrManualCode',
                        'placeholder'    => 'M2U-001A-61B7E3',
                        'autocapitalize' => 'characters',
                        'spellcheck'     => 'false',
                        'class'          => 'qr-manual-input',
                        'aria-label'     => 'Receipt reference',
                    ]); ?>
                </div>

                <div class="form-actions">
                    <?php html_submit('Look Up', ['class' => 'btn-outline']); ?>
                </div>
            </form>
        </div>

        <div class="card card-padded">
            <h3 class="section-heading">Result</h3>

            <div id="qrEmpty" class="qr-empty">
                <i class="fas fa-qrcode"></i>
                <p>Scan a receipt code to see the order here.</p>
            </div>

            <div id="qrError" class="alert alert-error" hidden></div>

            <div id="qrResult" class="qr-result" hidden>
                <div class="qr-result-head">
                    <div>
                        <span class="qr-result-receipt" id="qrReceiptNo"></span>
                        <span class="badge" id="qrStatusBadge"></span>
                    </div>
                    <span class="qr-result-total" id="qrTotal"></span>
                </div>

                <dl class="qr-result-details">
                    <dt>Customer</dt><dd id="qrCustomer"></dd>
                    <dt>Email</dt><dd id="qrEmail"></dd>
                    <dt>Placed</dt><dd id="qrPlaced"></dd>
                    <dt>Reference</dt><dd><code id="qrShortCode"></code></dd>
                    <dt>Read from</dt><dd id="qrSource"></dd>
                </dl>

                <h4 class="qr-items-title">Items (<span id="qrItemCount">0</span>)</h4>
                <ul class="qr-items" id="qrItems"></ul>

                <div class="form-actions">
                    <?php /* Only offered to somebody who can open the order page.
                             A Delivery Man has qr.scan without orders.manage. */ ?>
                    <?php if (can_open('/admin/order_detail.php')): ?>
                        <a href="#" class="btn-primary" id="qrOpenOrder">Open Full Order</a>
                    <?php endif; ?>

                    <?php /* Reloads the page onto this order, which renders the
                             action panel server-side. The panel needs the order's
                             CURRENT status to decide what to offer, and the
                             scanner only has what the lookup returned -- asking
                             the server is the version that cannot go stale. */ ?>
                    <a href="#" class="btn-outline" id="qrActOnOrder">Update Status</a>
                </div>
            </div>
        </div>

        <?php if ($scanned !== null): ?>
            <?php /* Rendered from the database, not from the scan. Landing here
                     after a status change means the panel reflects what was just
                     saved rather than what was on screen a moment ago. */ ?>
            <div class="card card-padded qr-act-card">
                <div class="qr-act-head">
                    <div>
                        <span class="qr-result-receipt"><?= e(receipt_number($scanned)) ?></span>
                        <span class="badge status-badge status-<?= e($scanned['status']) ?>">
                            <?= e(order_status_label($scanned['status'])) ?>
                        </span>
                    </div>
                    <span class="qr-result-total"><?= e(money($scanned['total_amount'])) ?></span>
                </div>

                <dl class="info-list">
                    <?php detail_row('Customer', $scanned['customer_name']); ?>
                    <?php detail_row('Reference', order_short_code((int)$scanned['id']), true); ?>
                    <?php detail_row('Placed', fmt_datetime($scanned['created_at'])); ?>
                </dl>

                <?php render_status_actions($scanned, '/admin/qr_scan.php'); ?>
            </div>
        <?php endif; ?>

    </div>
</div>

<!-- jsQR is fetched by qrscan.js, which tries the self-hosted copy first
     and falls back to a CDN. Keeping it out of the markup means the page
     carries no inline script and no single hardcoded source. -->
<script src="/assets/js/qrscan.js" defer></script>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
