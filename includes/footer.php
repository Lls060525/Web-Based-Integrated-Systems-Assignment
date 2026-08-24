<?php
// ============================================================
// includes/footer.php - storefront layout (bottom half)
// Closes the <main> opened by includes/header.php
// ============================================================
?>
</main>

<?php
// The webcam dialog is shared by every dropzone on the page.
// render_webcam_modal() prints itself once and then no-ops.
require_once __DIR__ . '/webcam.php';
render_webcam_modal();

// The delivery-photo lightbox, for the same reason and in the same place.
//
// It MUST be emitted here rather than beside the link that opens it. A
// position:fixed overlay stops being fixed to the viewport if any
// ancestor has a transform, and .card:hover sets one -- which turned the
// dialog into something that jittered whenever the pointer crossed the
// card it was nested in.
require_once __DIR__ . '/order_parts.php';
render_photo_modal();
?>

<footer class="site-footer">
    <div class="container">
        <?php
            // The flagship store's details, so contact information appears
            // on every page without being typed into the layout twice.
            $footerStore = store_module_ready() ? primary_store() : null;
        ?>

        <?php if ($footerStore !== null): ?>
            <div class="footer-store">
                <div>
                    <strong><?= e($footerStore['name']) ?></strong>
                    <div class="muted small-note"><?= e(store_address_one_line($footerStore)) ?></div>
                </div>

                <div class="footer-store-links">
                    <?php if (!empty($footerStore['phone'])): ?>
                        <a href="tel:<?= e(preg_replace('~[^0-9+]~', '', $footerStore['phone'])) ?>">
                            <i class="fas fa-phone"></i> <?= e($footerStore['phone']) ?>
                        </a>
                    <?php endif; ?>

                    <a href="/stores.php"><i class="fas fa-location-dot"></i> All stores</a>
                </div>
            </div>
        <?php endif; ?>

        <p>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. BMIT2013 Web-Based Integrated Systems assignment project.</p>
    </div>
</footer>

</body>
</html>
