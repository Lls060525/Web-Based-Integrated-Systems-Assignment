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
?>
    </div><!-- /.admin-main-content -->
</div><!-- /.admin-wrapper -->

</body>
</html>
