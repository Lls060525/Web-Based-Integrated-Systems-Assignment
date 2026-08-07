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

$title = 'Scan QR Code - Admin';

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
                    <a href="#" class="btn-primary" id="qrOpenOrder">Open Full Order</a>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- jsQR is fetched by qrscan.js, which tries the self-hosted copy first
     and falls back to a CDN. Keeping it out of the markup means the page
     carries no inline script and no single hardcoded source. -->
<script src="/assets/js/qrscan.js" defer></script>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
