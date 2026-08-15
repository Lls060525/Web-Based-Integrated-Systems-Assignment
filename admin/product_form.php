<?php
// ============================================================
// admin/product_form.php - Product CRUD + Photo Upload (Admin)
// ============================================================

require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/dropzone.php';

$productId = get_int('id');
$isEdit    = $productId !== null;

// Defaults for the "add" mode.
$product = [
    'name'        => '',
    'category_id' => '',
    'description' => '',
    'price'       => '',
    'stock'         => '0',
    'reorder_level' => (string)STOCK_DEFAULT_REORDER_LEVEL,
    'image'       => null,
    'status'        => 'active',
    'video_id'      => null,
    'video_title'   => null,
];

if ($isEdit) {
    $found = db_one('SELECT * FROM products WHERE id = ?', [$productId]);

    if (!$found) {
        flash_error('Product not found.');
        redirect('/admin/products.php');
    }
    $product = $found;
}

$categories = db_all('SELECT id, name FROM categories ORDER BY name ASC');
$categoryOptions = array_column($categories, 'name', 'id');

$statusOptions = ['active' => 'Active', 'inactive' => 'Inactive'];

if (is_post()) {
    csrf_check();

    $name        = post('name');
    $categoryId  = post_int('category_id');
    $description = post('description');
    $price       = post('price');
    $stock       = post('stock');
    $reorder     = post('reorder_level', (string)STOCK_DEFAULT_REORDER_LEVEL);
    $status      = post('status');
    $videoInput  = post('video_url');
    $videoTitle  = post('video_title');

    // ---------- Server-side validation ----------
    if (v_required('name', $name, 'Product name')) {
        v_max('name', $name, 150, 'Product name');
    }

    if ($categoryId === null) {
        add_err('category_id', 'Please select a category.');
    } elseif (!array_key_exists($categoryId, $categoryOptions)) {
        add_err('category_id', 'The selected category does not exist.');
    }

    if (v_required('description', $description, 'Description')) {
        v_max('description', $description, 2000, 'Description');
    }

    v_number('price', $price, 0.01, 999999.99, 'Price');
    v_integer('stock', $stock, 0, 100000, 'Stock quantity');

    if (reorder_level_ready()) {
        v_integer('reorder_level', $reorder, 0, 100000, 'Reorder level');
    }
    v_in('status', $status, ['active', 'inactive'], 'Status');

    // ---------- Video ----------
    // Anything pasted is normalised to an 11-character id, or rejected.
    $videoId = null;

    if (video_module_ready() && $videoInput !== '') {
        $videoId = parse_youtube_id($videoInput);

        if ($videoId === null) {
            add_err('video_url', 'That does not look like a YouTube link. '
                               . 'Paste the address from the browser bar, for example '
                               . 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        }
    }

    v_max('video_title', $videoTitle, 120, 'Video caption');

    // ---------- Photo upload ----------
    // Once the gallery module is installed it owns products.image
    // entirely, and photos are managed on admin/product_photos.php.
    // Ignoring any file posted here keeps sync_primary_photo() the
    // single writer of that column.
    $galleryOwnsPhotos = photo_gallery_ready() && $isEdit;

    $image    = $product['image'];
    $newImage = $galleryOwnsPhotos
        ? null
        : save_uploaded_image('product_image', DIR_UPLOAD_PRODUCTS, 'prod');

    if ($newImage !== null) {
        $image = $newImage;
    } elseif (!$isEdit && no_err() && empty($product['image'])) {
        // Adding a product with no photo: fall back to the placeholder.
        $image = 'default-product.png';
    }

    if (no_err()) {
        if ($isEdit) {
            // Stock is NOT written here. Editing a product must not silently
            // rewrite the quantity, because that would bypass the movement
            // ledger. Any change is recorded through Stock Control instead.
            $sql    = 'UPDATE products
                          SET name = ?, category_id = ?, description = ?,
                              price = ?, status = ?';
            $params = [$name, $categoryId, $description, $price, $status];

            // Only this form writes the column while the gallery is absent.
            if (!$galleryOwnsPhotos) {
                $sql      = str_replace('price = ?, status = ?', 'price = ?, image = ?, status = ?', $sql);
                $params   = [$name, $categoryId, $description, $price, $image, $status];
            }

            if (reorder_level_ready()) {
                $sql     .= ', reorder_level = ?';
                $params[] = (int)$reorder;
            }

            if (video_module_ready()) {
                $sql     .= ', video_id = ?, video_title = ?';
                $params[] = $videoId;
                $params[] = ($videoTitle === '' ? null : $videoTitle);
            }

            $sql     .= ' WHERE id = ?';
            $params[] = $productId;

            db_exec($sql, $params);

            // A deliberate stock correction from this form still goes
            // through the ledger, so nothing is ever unexplained.
            $newStock = (int)$stock;
            $oldStock = (int)$found['stock'];

            if ($newStock !== $oldStock && stock_module_ready()) {
                try {
                    adjust_stock(
                        $productId,
                        $newStock - $oldStock,
                        'adjust',
                        'Corrected on the product edit form',
                        current_user_id()
                    );
                } catch (\RuntimeException $ex) {
                    flash_error('Stock was not changed: ' . $ex->getMessage());
                }
            } elseif ($newStock !== $oldStock) {
                db_exec('UPDATE products SET stock = ? WHERE id = ?', [$newStock, $productId]);
            }

            if ($newImage !== null) {
                delete_uploaded_file(DIR_UPLOAD_PRODUCTS, $found['image']);
            }

            flash_success('Product updated successfully.');
        } else {
            $cols = ['name', 'category_id', 'description', 'price', 'stock', 'image', 'status'];
            $vals = [$name, $categoryId, $description, $price, $stock, $image, $status];

            if (reorder_level_ready()) {
                $cols[] = 'reorder_level';
                $vals[] = (int)$reorder;
            }

            if (video_module_ready()) {
                $cols[] = 'video_id';
                $vals[] = $videoId;

                $cols[] = 'video_title';
                $vals[] = ($videoTitle === '' ? null : $videoTitle);
            }

            db_exec(
                'INSERT INTO products (' . implode(', ', $cols) . ') VALUES ('
                . implode(', ', array_fill(0, count($cols), '?')) . ')',
                $vals
            );

            $newProductId = (int)db_last_id();

            // The first photo starts the gallery, so the two never
            // disagree about what the cover is.
            if (photo_gallery_ready() && $newImage !== null) {
                add_product_photo($newProductId, $newImage);
            }

            // Opening stock is the first entry in this product's ledger.
            if ((int)$stock > 0 && stock_module_ready()) {
                record_stock_movement(
                    $newProductId,
                    'initial',
                    (int)$stock,
                    'Opening stock set when the product was created',
                    null,
                    current_user_id()
                );
            }

            flash_success('Product added successfully.');
        }

        redirect('/admin/products.php');
    }

    // Validation failed: keep the newly uploaded image visible in the preview.
    $product['image'] = $image;
    // Validation failed. Answer with a redirect rather than a page, so
    // the browser's history entry is a GET and F5 cannot resubmit.
    // The errors and what was typed are carried across the redirect.
    redirect_back();
}

$title = ($isEdit ? 'Edit' : 'Add') . ' Product - Admin';

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container admin-container-narrow">
    <div class="admin-header">
        <h2><?= $isEdit ? 'Edit Product' : 'Add New Product' ?></h2>

        <div class="admin-header-actions">
            <?php if ($isEdit && spec_module_ready()): ?>
                <a href="/admin/product_specs.php?id=<?= (int)$productId ?>" class="btn-outline">
                    <i class="fas fa-list-check"></i> Specifications
                </a>
            <?php endif; ?>
            <a href="/admin/products.php" class="btn-outline">&larr; Back to List</a>
        </div>
    </div>

    <div class="card mt-4 card-padded">

        <?php err_summary(); ?>

        <?php if (count($categories) === 0): ?>
            <div class="alert alert-info">
                There are no categories yet.
                <a href="/admin/category_form.php">Create one first</a> so products can be classified.
            </div>
        <?php endif; ?>

        <form action="" method="POST" enctype="multipart/form-data" class="form-standard">
            <?php csrf_field(); ?>

            <div class="form-row">
                <div class="form-col form-col-2">
                    <?php field('name', 'Product Name', function () use ($product) {
                        html_text('name', $product['name'], ['required' => true, 'maxlength' => 150]);
                    }, true); ?>
                </div>

                <div class="form-col">
                    <?php field('category_id', 'Category', function () use ($categoryOptions, $product) {
                        html_select('category_id', $categoryOptions, $product['category_id'],
                                    ['required' => true], '-- Select Category --');
                    }, true); ?>
                </div>
            </div>

            <?php field('description', 'Description', function () use ($product) {
                html_textarea('description', $product['description'], ['rows' => 5, 'required' => true, 'maxlength' => 2000]);
            }, true); ?>

            <div class="form-row">
                <div class="form-col">
                    <?php field('price', 'Price (RM)', function () use ($product) {
                        html_number('price', $product['price'], ['step' => '0.01', 'min' => '0.01', 'required' => true]);
                    }, true); ?>
                </div>

                <div class="form-col">
                    <?php field('stock', 'Stock Quantity', function () use ($product, $isEdit) {
                        html_number('stock', $product['stock'], ['min' => '0', 'required' => true]);
                        if ($isEdit) {
                            echo '<small class="form-hint">Changing this records a correction in the '
                               . '<a href="/admin/stock.php">stock ledger</a>.</small>';
                        }
                    }, true); ?>
                </div>

                <?php if (reorder_level_ready()): ?>
                    <div class="form-col">
                        <?php field('reorder_level', 'Reorder Level', function () use ($product) {
                            html_number('reorder_level', $product['reorder_level'] ?? STOCK_DEFAULT_REORDER_LEVEL,
                                        ['min' => '0', 'required' => true]);
                            echo '<small class="form-hint">Flag as low at or below this.</small>';
                        }, true); ?>
                    </div>
                <?php endif; ?>

                <div class="form-col">
                    <?php field('status', 'Status', function () use ($statusOptions, $product) {
                        html_select('status', $statusOptions, $product['status']);
                    }, true); ?>
                </div>
            </div>

            <?php if (video_module_ready()): ?>
                <fieldset class="form-fieldset">
                    <legend><i class="fab fa-youtube"></i> Product Video (optional)</legend>

                    <?php field('video_url', 'YouTube Link', function () use ($product) {
                        // The stored value is an id; show it back as a full
                        // watch URL so it is obvious what to paste.
                        $current = is_valid_video_id($product['video_id'] ?? null)
                            ? youtube_watch_url($product['video_id'])
                            : '';

                        html_text('video_url', $current, [
                            'maxlength'   => 200,
                            'placeholder' => 'https://www.youtube.com/watch?v=...',
                        ]);
                        echo '<small class="form-hint">'
                           . 'Any YouTube address works: watch, youtu.be, shorts or embed. '
                           . 'Leave empty to remove the video.</small>';
                    }); ?>

                    <?php field('video_title', 'Caption', function () use ($product) {
                        html_text('video_title', $product['video_title'] ?? '', [
                            'maxlength'   => 120,
                            'placeholder' => 'Hands-on review',
                        ]);
                    }); ?>

                    <?php if (is_valid_video_id($product['video_id'] ?? null)): ?>
                        <div class="video-current">
                            <img src="<?= e(youtube_thumbnail_url($product['video_id'])) ?>" alt="">
                            <div>
                                <strong>Video attached</strong>
                                <div class="muted small-note">
                                    ID <code><?= e($product['video_id']) ?></code>
                                </div>
                                <a href="<?= e(youtube_watch_url($product['video_id'])) ?>"
                                   target="_blank" rel="noopener noreferrer"
                                   class="small-note">Open on YouTube</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </fieldset>
            <?php endif; ?>

            <?php if (photo_gallery_ready() && $isEdit): ?>
                <?php field('product_image', 'Photos', function () use ($product, $productId) { ?>
                    <div class="gallery-link-box">
                        <img src="<?= e(product_image($product['image'])) ?>" alt="" class="image-preview">
                        <div>
                            <strong><?= product_photo_count((int)$productId) ?> photo(s)</strong>
                            <div class="muted small-note">
                                Photos are managed on their own page so you can upload several
                                at once and drag them into order.
                            </div>
                            <a href="/admin/product_photos.php?id=<?= (int)$productId ?>"
                               class="btn-outline btn-sm mt-2">Manage Photos</a>
                        </div>
                    </div>
                <?php }); ?>
            <?php else: ?>
                <?php field('product_image', 'Product Photo', function () use ($product) {
                    render_dropzone('product_image', product_image($product['image']), [
                        'hint' => 'JPG, PNG, GIF or WEBP, maximum '
                                . (UPLOAD_MAX_SIZE / 1024 / 1024) . ' MB. '
                                . (photo_gallery_ready()
                                    ? 'You can add more photos after saving.'
                                    : 'Leave empty to keep the current photo.'),
                    ]);
                }); ?>
            <?php endif; ?>

            <div class="form-actions text-right">
                <a href="/admin/products.php" class="btn-outline">Cancel</a>
                <?php html_submit($isEdit ? 'Save Changes' : 'Publish Product'); ?>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
