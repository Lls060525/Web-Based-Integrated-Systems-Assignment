<?php
// ============================================================
// admin/product_photos.php - Photo gallery for one product
//
// Multiple upload, drag to reorder, set the cover, delete.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

$productId = get_int('id');

if (!photo_gallery_ready()) {
    flash_error('The photo gallery is not available yet: run database/migration_15_product_photos.sql.');
    redirect('/admin/products.php');
}

if ($productId === null) {
    flash_error('Invalid product.');
    redirect('/admin/products.php');
}

$product = db_one('SELECT * FROM products WHERE id = ?', [$productId]);

if (!$product) {
    flash_error('Product not found.');
    redirect('/admin/products.php');
}

// ---------- Actions ----------
if (is_post()) {
    csrf_check();

    $action = post('action');

    try {
        if ($action === 'upload') {
            // $_FILES['photos'] arrives as parallel arrays because the
            // input is photos[]. Normalising it into one file per
            // iteration keeps save_uploaded_image() unchanged.
            $files = $_FILES['photos'] ?? null;

            if (!$files || !is_array($files['name'])) {
                flash_error('No files were received.');
            } else {
                $uploaded = 0;
                $failed   = 0;
                $count    = count($files['name']);

                for ($i = 0; $i < $count; $i++) {
                    if ((int)$files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }

                    // Rebuild a single-file structure for this one entry.
                    $_FILES['single_photo'] = [
                        'name'     => $files['name'][$i],
                        'type'     => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'error'    => $files['error'][$i],
                        'size'     => $files['size'][$i],
                    ];

                    $filename = save_uploaded_image('single_photo', DIR_UPLOAD_PRODUCTS, 'prod');

                    if ($filename === null) {
                        $failed++;
                        continue;
                    }

                    try {
                        add_product_photo($productId, $filename);
                        $uploaded++;
                    } catch (\RuntimeException $ex) {
                        // Over the limit: remove the file we just wrote
                        // rather than leaving it orphaned on disk.
                        delete_uploaded_file(DIR_UPLOAD_PRODUCTS, $filename);
                        flash_error($ex->getMessage());
                        break;
                    }
                }

                if ($uploaded > 0) {
                    flash_success($uploaded . ' photo' . ($uploaded === 1 ? '' : 's') . ' added.'
                        . ($failed > 0 ? ' ' . $failed . ' rejected.' : ''));
                } elseif ($failed > 0) {
                    flash_error($failed . ' file(s) were rejected. Only JPG, PNG, GIF and WEBP up to '
                        . (UPLOAD_MAX_SIZE / 1024 / 1024) . ' MB are accepted.');
                }
            }

        } elseif ($action === 'set_primary') {
            $photoId = post_int('photo_id');

            if ($photoId !== null && find_product_photo($photoId, $productId)) {
                set_primary_photo($photoId, $productId);
                flash_success('Cover photo updated.');
            } else {
                flash_error('Photo not found.');
            }

        } elseif ($action === 'delete') {
            $photoId = post_int('photo_id');

            if ($photoId === null) {
                flash_error('Photo not found.');
            } else {
                delete_product_photo($photoId, $productId);
                flash_success('Photo deleted.');
            }

        } elseif ($action === 'reorder') {
            $order = post('order');
            $ids   = array_filter(array_map('intval', explode(',', $order)));

            if (count($ids) > 0) {
                reorder_product_photos($productId, $ids);
                flash_success('Photo order saved.');
            }

        } else {
            flash_error('Invalid request.');
        }

    } catch (\RuntimeException $ex) {
        flash_error($ex->getMessage());
    } catch (\Throwable $ex) {
        error_log('Photo gallery error: ' . $ex->getMessage());
        flash_error('Something went wrong. Please try again.');
    }

    redirect('/admin/product_photos.php?id=' . $productId);
}

$photos    = product_photos($productId);
$remaining = PRODUCT_MAX_PHOTOS - count($photos);

$title = 'Photos: ' . $product['name'] . ' - Admin';

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container admin-container-wide">
    <div class="admin-header">
        <h2>Product Photos</h2>
        <div class="row-actions">
            <a href="/admin/product_form.php?id=<?= (int)$productId ?>" class="btn-outline">Edit Details</a>
            <a href="/admin/products.php" class="btn-outline">&larr; Back to Products</a>
        </div>
    </div>

    <div class="card card-padded mt-4 review-product-strip">
        <img src="<?= e(product_image($product['image'])) ?>" alt="" class="table-thumb">
        <div>
            <strong><?= e($product['name']) ?></strong>
            <div class="muted small-note">
                <?= count($photos) ?> of <?= PRODUCT_MAX_PHOTOS ?> photos used.
                The cover photo is what appears in listings, the cart and receipts.
            </div>
        </div>
    </div>

    <!-- Upload -->
    <div class="card card-padded mt-4">
        <h3>Add Photos</h3>

        <?php if ($remaining <= 0): ?>
            <p class="muted small-note">
                This product already has the maximum of <?= PRODUCT_MAX_PHOTOS ?> photos.
                Delete one before adding another.
            </p>
        <?php else: ?>
            <form action="/admin/product_photos.php?id=<?= (int)$productId ?>"
                  method="POST" enctype="multipart/form-data" class="form-standard">
                <?php csrf_field(); ?>
                <?php html_hidden('action', 'upload'); ?>

                <div class="dropzone dropzone-multi" id="dz_photos"
                     data-input="dz_photos_input"
                     data-max-mb="<?= e((string)(UPLOAD_MAX_SIZE / 1024 / 1024)) ?>"
                     data-max-files="<?= (int)$remaining ?>">

                    <input type="file" id="dz_photos_input" name="photos[]"
                           accept="image/*" multiple class="dropzone-input">

                    <div class="dropzone-body dropzone-multi-body">
                        <div class="dropzone-text">
                            <strong class="dropzone-title">
                                Drag photos here, or
                                <button type="button" class="dropzone-browse">browse</button>
                            </strong>
                            <span class="dropzone-hint">
                                Up to <?= (int)$remaining ?> more.
                                JPG, PNG, GIF or WEBP, maximum
                                <?= UPLOAD_MAX_SIZE / 1024 / 1024 ?> MB each.
                            </span>
                            <span class="dropzone-error err"></span>
                        </div>
                    </div>

                    <div class="dropzone-thumbs" id="dz_photos_thumbs"></div>

                    <div class="dropzone-progress" hidden>
                        <div class="dropzone-progress-bar"></div>
                        <span class="dropzone-progress-text">0%</span>
                    </div>

                    <div class="dropzone-veil">
                        <span><i class="fas fa-hand-pointer"></i> Drop to add</span>
                    </div>
                </div>

                <div class="form-actions mt-4">
                    <?php html_submit('Upload Photos', ['class' => 'btn-primary']); ?>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <!-- Gallery -->
    <div class="card card-padded mt-4">
        <div class="section-head">
            <h3>Gallery</h3>
            <?php if (count($photos) > 1): ?>
                <span class="muted small-note">
                    <i class="fas fa-arrows-up-down-left-right"></i>
                    Drag a photo to change the order
                </span>
            <?php endif; ?>
        </div>

        <?php if (count($photos) === 0): ?>
            <p class="muted">No photos yet. Add the first one above.</p>
        <?php else: ?>
            <ul class="photo-grid" id="photoGrid">
                <?php foreach ($photos as $photo): ?>
                    <li class="photo-tile <?= (int)$photo['is_primary'] === 1 ? 'is-primary' : '' ?>"
                        data-id="<?= (int)$photo['id'] ?>" draggable="true">

                        <span class="photo-drag-handle" title="Drag to reorder">
                            <i class="fas fa-grip-vertical"></i>
                        </span>

                        <img src="<?= e(product_image($photo['filename'])) ?>" alt="">

                        <?php if ((int)$photo['is_primary'] === 1): ?>
                            <span class="photo-cover-badge">
                                <i class="fas fa-star"></i> Cover
                            </span>
                        <?php endif; ?>

                        <?php if (photo_restore_ready() && photo_is_edited($photo)): ?>
                            <span class="photo-edited-badge" title="Edited - the original is kept and can be restored">
                                <i class="fas fa-sliders"></i> Edited
                            </span>
                        <?php endif; ?>

                        <div class="photo-actions">
                            <?php if (image_processing_ready()): ?>
                                <a href="/admin/photo_edit.php?photo=<?= (int)$photo['id'] ?>&amp;product=<?= (int)$productId ?>"
                                   class="btn-outline btn-sm btn-block">
                                    <i class="fas fa-sliders"></i> Edit
                                </a>
                            <?php endif; ?>

                            <?php if ((int)$photo['is_primary'] !== 1): ?>
                                <form action="/admin/product_photos.php?id=<?= (int)$productId ?>" method="POST">
                                    <?php csrf_field(); ?>
                                    <?php html_hidden('action', 'set_primary'); ?>
                                    <?php html_hidden('photo_id', $photo['id']); ?>
                                    <?php html_submit('Make Cover', ['class' => 'btn-outline btn-sm btn-block']); ?>
                                </form>
                            <?php endif; ?>

                            <form action="/admin/product_photos.php?id=<?= (int)$productId ?>" method="POST"
                                  data-confirm="Delete this photo? The file is removed from the server too.">
                                <?php csrf_field(); ?>
                                <?php html_hidden('action', 'delete'); ?>
                                <?php html_hidden('photo_id', $photo['id']); ?>
                                <?php html_submit('Delete', ['class' => 'btn-outline btn-sm btn-danger btn-block']); ?>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>

            <!-- Submitted by admin.js after a drag finishes. -->
            <form action="/admin/product_photos.php?id=<?= (int)$productId ?>" method="POST"
                  id="reorderForm" class="reorder-form">
                <?php csrf_field(); ?>
                <?php html_hidden('action', 'reorder'); ?>
                <input type="hidden" name="order" id="photoOrder" value="">
                <?php html_submit('Save New Order', ['class' => 'btn-primary', 'id' => 'saveOrderBtn']); ?>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
