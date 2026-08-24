<?php
// ============================================================
// includes/admin_footer.php - admin layout (bottom half)
// Closes the elements opened by includes/admin_header.php
//
// The sidebar toggle is handled by jQuery in /assets/js/admin.js,
// so there is no inline JavaScript in this layout.
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
    </div><!-- /.admin-main-content -->
</div><!-- /.admin-wrapper -->

</body>
</html>
