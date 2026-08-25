<?php
// ============================================================
// admin/photo_edit.php - Image processing for one product photo
//
// Every operation runs in PHP with GD and writes a NEW file, then
// points the gallery row at it and deletes the previous one.
//
// ------------------------------------------------------------
// WHY A NEW FILE EVERY TIME, INSTEAD OF EDITING IN PLACE
// ------------------------------------------------------------
//
// Writing over the existing file would be fewer lines and is the
// obvious first instinct. It is wrong for three reasons:
//
//   1. If GD fails halfway -- out of memory on a large photo is the
//      usual way -- an in-place write leaves a half-written file where
//      a working image used to be. The original is gone and there is
//      nothing to fall back to. Writing somewhere new means a failure
//      costs a temporary file and nothing else.
//
//   2. Browsers cache by URL. Same filename, new content, and the
//      admin keeps seeing the old picture and assumes the rotate did
//      not work. A new filename is a new URL, so the change is
//      visible immediately without cache-busting tricks.
//
//   3. Rotating is lossy. Each save re-compresses the JPEG, so
//      rotating four times through in-place edits gets you back to the
//      original orientation with visibly worse quality. Keeping the
//      FIRST version as a restore point -- which apply_photo_edit()
//      does -- means "restore original" returns the untouched upload,
//      not the accumulated damage.
//
// So the flow is: process_image() writes a new file and returns its
// name, apply_photo_edit() repoints the database row and tidies up the
// intermediate, and original_filename remembers where it started.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

require_permission('products.manage');

$photoId   = get_int('photo');
$productId = get_int('product');

// Optional-feature guard, the pattern used throughout this project: a
// missing migration produces an explanation and a redirect, never a
// fatal error about an unknown table in front of a marker.
if (!photo_gallery_ready()) {
    flash_error('The photo gallery is not available yet: run database/migration_15_product_photos.sql.');
    redirect('/admin/products.php');
}

if ($photoId === null || $productId === null) {
    flash_error('Invalid photo.');
    redirect('/admin/products.php');
}

$product = db_one('SELECT * FROM products WHERE id = ?', [$productId]);

// BOTH ids, not just the photo id.
//
// find_product_photo() filters on photo id AND product id together, so
// ?photo=88&product=3 fails when photo 88 belongs to product 12. The
// pair has to be consistent.
//
// Without it the page would happily edit photo 88 while displaying
// product 3's name and returning to product 3's gallery afterwards --
// so an admin could rotate a photo belonging to a different product
// while believing they were working on this one. It is the same
// "prove the relationship in the query" idea as the ownership check in
// order_detail.php, applied to a parent-child link instead of to a
// user.
$photo   = find_product_photo($photoId, $productId);

if (!$product || !$photo) {
    flash_error('Photo not found.');
    redirect('/admin/products.php');
}

$backUrl = '/admin/product_photos.php?id=' . $productId;

// ---------- Apply an operation ----------
if (is_post()) {
    csrf_check();

    $action = post('action');

    if ($action === 'process') {
        $operation = post('operation');

        try {
            // GD writes a new file; apply_photo_edit() then updates the
            // row, keeps the very first version as the restore point,
            // and removes only the intermediate file.
            //
            // $operation is NOT validated here. process_image() checks
            // it against image_operations() -- the same list the
            // buttons are drawn from -- and throws RuntimeException if
            // it is not one of them. One list, one check, so a new
            // operation cannot be added to the UI and forgotten in the
            // validation.
            $newName = process_image(DIR_UPLOAD_PRODUCTS, $photo['filename'], $operation);

            apply_photo_edit($photoId, $productId, $newName);

            flash_success(image_operations()[$operation]['label'] . ' applied.');

        // ---- Two catch blocks, deliberately ----
        //
        // They differ in who the message is FOR, which is the question
        // worth asking every time you write a catch.
        } catch (\RuntimeException $ex) {
            // Thrown by our own code for reasons the admin can act on:
            // unsupported operation, GD not installed, file missing.
            // The message was written to be read, so it is shown.
            flash_error($ex->getMessage());

        } catch (\Throwable $ex) {
            // Anything else -- out of memory, a corrupt JPEG, a disk
            // error. These messages contain file paths and internals,
            // which are useful in a log and are an information leak on
            // a screen. So the detail goes to error_log and the admin
            // gets a plain sentence.
            //
            // Note it catches Throwable, not Exception: a PHP Error
            // (a TypeError from GD, say) is not an Exception and would
            // otherwise escape this block and produce a blank page.
            error_log('Image processing failed: ' . $ex->getMessage());
            flash_error('The image could not be processed.');
        }

    } elseif ($action === 'restore') {
        try {
            restore_photo_original($photoId, $productId);
            flash_success('The original photo has been restored.');

        } catch (\RuntimeException $ex) {
            flash_error($ex->getMessage());
        } catch (\Throwable $ex) {
            error_log('Photo restore failed: ' . $ex->getMessage());
            flash_error('The original could not be restored.');
        }

    } else {
        flash_error('Invalid request.');
    }

    redirect('/admin/photo_edit.php?photo=' . $photoId . '&product=' . $productId);
}

$dimensions = image_dimensions(DIR_UPLOAD_PRODUCTS, $photo['filename']);
$caps       = image_capabilities();
$isEdited   = photo_restore_ready() && photo_is_edited($photo);
$origExists = $isEdited && is_file(DIR_UPLOAD_PRODUCTS . basename($photo['original_filename']));

$title = 'Edit Photo - Admin';

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container admin-container-wide">
    <div class="admin-header">
        <h2>Edit Photo</h2>
        <a href="<?= e($backUrl) ?>" class="btn-outline">&larr; Back to Gallery</a>
    </div>

    <?php if (!image_processing_ready()): ?>
        <?php $gd = gd_diagnosis(); ?>
        <div class="alert alert-error mt-4">
            <strong>The PHP GD extension is not enabled</strong>, so images cannot be
            processed on the server.
            <br><br>
            <?php if ($gd['ini_file'] !== null): ?>
                <?php /* The path is read from the running process, so it is
                         Apache's php.ini by definition -- not whichever one
                         happens to be easiest to find on the disk. */ ?>
                Open <code><?= e($gd['ini_file']) ?></code>, find the line
                <code>;extension=gd</code>, remove the leading semicolon, save,
                then <strong>fully stop and start</strong> Apache from the XAMPP
                control panel.
                <br><br>
                <span class="small-note">
                    PHP <?= e($gd['php_version']) ?> (<?= e($gd['sapi']) ?>),
                    extension_dir <code><?= e($gd['ext_dir'] ?? 'not set') ?></code><?php
                        if ($gd['ext_dir'] !== null && $gd['ext_files'] === []): ?>
                        &mdash; no GD library file found there, so this build may not ship one<?php
                        endif; ?>.
                </span>
            <?php else: ?>
                PHP is running without a php.ini at all. Copy
                <code>php.ini-development</code> to <code>php.ini</code> in your PHP
                folder, enable GD in it, then restart Apache.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="editor-layout mt-4">

        <!-- Preview -->
        <div class="card card-padded editor-stage">
            <?php if ($isEdited && $origExists): ?>
                <div class="editor-compare">
                    <figure>
                        <img src="<?= e(product_image($photo['original_filename'])) ?>"
                             alt="" class="editor-image">
                        <figcaption class="muted small-note">Original</figcaption>
                    </figure>
                    <figure>
                        <img src="<?= e(product_image($photo['filename'])) ?>"
                             alt="" class="editor-image">
                        <figcaption class="small-note editor-current-cap">
                            <strong>Current</strong>
                            <?php if (!empty($photo['edited_at'])): ?>
                                &middot; edited <?= e(fmt_datetime($photo['edited_at'])) ?>
                            <?php endif; ?>
                        </figcaption>
                    </figure>
                </div>
            <?php else: ?>
                <img src="<?= e(product_image($photo['filename'])) ?>" alt="" class="editor-image">
            <?php endif; ?>

            <div class="editor-meta muted small-note">
                <?= e($product['name']) ?>
                <?php if ($dimensions): ?>
                    &middot; <?= $dimensions['width'] ?> &times; <?= $dimensions['height'] ?> px
                <?php endif; ?>
                <?php if ((int)$photo['is_primary'] === 1): ?>
                    &middot; <span class="badge badge-success">Cover</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Operations -->
        <aside class="editor-side">
            <div class="card card-padded">
                <h3>Adjust</h3>
                <p class="muted small-note">
                    Each change is processed by PHP with GD and saved back to disk,
                    so it applies everywhere the photo is used.
                </p>

                <div class="editor-ops">
                    <?php foreach (image_operations() as $key => $op): ?>
                        <form action="/admin/photo_edit.php?photo=<?= (int)$photoId ?>&amp;product=<?= (int)$productId ?>"
                              method="POST" class="editor-op-form">
                            <?php csrf_field(); ?>
                            <?php html_hidden('action', 'process'); ?>
                            <?php html_hidden('operation', $key); ?>

                            <button type="submit" class="btn-outline editor-op"
                                    <?= image_processing_ready() ? '' : 'disabled' ?>>
                                <i class="fas <?= e($op['icon']) ?>"></i>
                                <span><?= e($op['label']) ?></span>
                            </button>
                        </form>
                    <?php endforeach; ?>
                </div>

                <p class="muted small-note mt-4">
                    <i class="fas fa-circle-info"></i>
                    Every edit writes a new file. That is deliberate: reusing the same
                    name would leave the browser showing its cached copy, so the change
                    would look like it had failed.
                </p>
            </div>

            <!-- Restore -->
            <div class="card card-padded mt-4 restore-card">
                <h3><i class="fas fa-clock-rotate-left"></i> Original</h3>

                <?php if (!photo_restore_ready()): ?>
                    <p class="muted small-note">
                        Restoring originals needs
                        <code>database/migration_17_photo_original.sql</code>.
                    </p>

                <?php elseif (!$isEdited): ?>
                    <p class="muted small-note">
                        This photo has not been edited. The file on the server is exactly
                        as it was uploaded, so there is nothing to undo yet.
                    </p>

                <?php elseif (!$origExists): ?>
                    <div class="alert alert-error">
                        The original file is recorded as
                        <code><?= e($photo['original_filename']) ?></code>
                        but is missing from the server, so it cannot be restored.
                    </div>

                <?php else: ?>
                    <p class="muted small-note">
                        The photo as it was first uploaded is still on the server.
                        Restoring discards every edit made since and cannot be undone.
                    </p>

                    <form action="/admin/photo_edit.php?photo=<?= (int)$photoId ?>&amp;product=<?= (int)$productId ?>"
                          method="POST"
                          data-confirm="Restore the original photo?&#10;&#10;All edits will be discarded.">
                        <?php csrf_field(); ?>
                        <?php html_hidden('action', 'restore'); ?>
                        <?php html_submit('Restore Original', ['class' => 'btn-outline btn-danger btn-block']); ?>
                    </form>
                <?php endif; ?>
            </div>

            <!-- What this PHP build can actually do -->
            <div class="card card-padded mt-4">
                <h3>GD Support</h3>

                <?php if (count($caps) === 0): ?>
                    <p class="muted small-note">GD is not loaded.</p>
                <?php else: ?>
                    <ul class="check-list">
                        <?php
                        $rows = [
                            'JPEG'      => $caps['jpeg'],
                            'PNG'       => $caps['png'],
                            'GIF'       => $caps['gif'],
                            'WEBP'      => $caps['webp'],
                            'Rotate'    => $caps['rotate'],
                            'Flip'      => $caps['flip'],
                            'Filters'   => $caps['filter'],
                            'EXIF read' => $caps['exif'],
                        ];
                        ?>
                        <?php foreach ($rows as $label => $ok): ?>
                            <li class="check-item <?= $ok ? 'is-ok' : 'is-warn' ?>">
                                <span class="check-icon">
                                    <i class="fas <?= $ok ? 'fa-check' : 'fa-minus' ?>"></i>
                                </span>
                                <div><strong><?= e($label) ?></strong></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <p class="muted small-note mt-2">
                        GD version <?= e($caps['version']) ?>.
                        Uploaded JPEGs are rotated upright automatically using their
                        EXIF orientation tag<?= $caps['exif'] ? '' : ' (unavailable in this build)' ?>.
                    </p>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
