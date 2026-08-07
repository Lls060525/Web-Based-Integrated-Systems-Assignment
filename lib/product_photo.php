<?php
// ============================================================
// lib/product_photo.php
// A product's photo gallery.
//
// products.image is kept as a denormalised pointer to the primary
// photo, because the cart, orders, wishlist, receipt and admin
// listings all read it and only ever need one thumbnail. Joining
// the gallery in all of those places would be more work and more
// queries for no benefit.
//
// The cost of a cached column is drift, so there is exactly ONE
// sync point: every function here that changes the gallery ends by
// calling sync_primary_photo(). Nothing else writes products.image.
// ============================================================

/** True once the gallery table exists (see the migration). */
function photo_gallery_ready(): bool
{
    return db_table_exists('product_photos');
}

/** True once the original-photo columns exist (migration 17). */
function photo_restore_ready(): bool
{
    return db_column_exists('product_photos', 'original_filename');
}

/**
 * True when a photo has been edited and can be restored.
 *
 * original_filename being NULL is the whole state machine: no separate
 * "is_edited" flag that could disagree with reality.
 */
function photo_is_edited(array $photo): bool
{
    return !empty($photo['original_filename']);
}

/**
 * Is this file still referenced by any photo row, as either the
 * current image or somebody's kept original?
 *
 * Both columns have to be checked or restoring one photo would delete
 * a file another row is still pointing at.
 */
function photo_file_in_use(string $filename): bool
{
    if (!photo_gallery_ready()) {
        return false;
    }

    if (photo_restore_ready()) {
        return (int)db_value(
            'SELECT COUNT(*) FROM product_photos
              WHERE filename = ? OR original_filename = ?',
            [$filename, $filename]
        ) > 0;
    }

    return (int)db_value('SELECT COUNT(*) FROM product_photos WHERE filename = ?', [$filename]) > 0;
}

/**
 * Record the result of an edit.
 *
 * The FIRST edit promotes the current file to be the kept original and
 * leaves it on disk. Later edits replace only the intermediate file, so
 * exactly two files exist however many times a photo is adjusted.
 *
 * @throws RuntimeException when the photo does not belong to the product
 */
function apply_photo_edit(int $photoId, int $productId, string $newFilename): void
{
    $photo = find_product_photo($photoId, $productId);

    if (!$photo) {
        throw new RuntimeException('Photo not found.');
    }

    $previous = $photo['filename'];
    $original = $photo['original_filename'] ?? null;

    db()->beginTransaction();

    try {
        if (photo_restore_ready()) {
            if (empty($original)) {
                // First edit: keep what we had as the original.
                db_exec(
                    'UPDATE product_photos
                        SET filename = ?, original_filename = ?, edited_at = NOW()
                      WHERE id = ? AND product_id = ?',
                    [$newFilename, $previous, $photoId, $productId]
                );
            } else {
                db_exec(
                    'UPDATE product_photos
                        SET filename = ?, edited_at = NOW()
                      WHERE id = ? AND product_id = ?',
                    [$newFilename, $photoId, $productId]
                );
            }
        } else {
            db_exec(
                'UPDATE product_photos SET filename = ? WHERE id = ? AND product_id = ?',
                [$newFilename, $photoId, $productId]
            );
        }

        sync_primary_photo($productId);
        db()->commit();

    } catch (\Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    // Only remove the file we just replaced, and only when it is neither
    // somebody's original nor still in use elsewhere.
    $keepingAsOriginal = photo_restore_ready() && empty($original);

    if (!$keepingAsOriginal && !photo_file_in_use($previous)) {
        delete_uploaded_file(DIR_UPLOAD_PRODUCTS, $previous);
    }
}

/**
 * Put the photo back to how it was uploaded.
 *
 * The edited file is removed and original_filename returns to NULL, so
 * the row is once again in the "never edited" state.
 *
 * @throws RuntimeException when there is nothing to restore
 */
function restore_photo_original(int $photoId, int $productId): void
{
    if (!photo_restore_ready()) {
        throw new RuntimeException('Restoring originals is not available yet: run database/migration_17_photo_original.sql.');
    }

    $photo = find_product_photo($photoId, $productId);

    if (!$photo) {
        throw new RuntimeException('Photo not found.');
    }

    if (!photo_is_edited($photo)) {
        throw new RuntimeException('This photo has not been edited, so there is nothing to restore.');
    }

    $original = $photo['original_filename'];
    $edited   = $photo['filename'];

    // Refuse rather than restore a file that is no longer on disk.
    if (!is_file(DIR_UPLOAD_PRODUCTS . basename($original))) {
        throw new RuntimeException('The original file is missing from the server and cannot be restored.');
    }

    db()->beginTransaction();

    try {
        db_exec(
            'UPDATE product_photos
                SET filename = ?, original_filename = NULL, edited_at = NULL
              WHERE id = ? AND product_id = ?',
            [$original, $photoId, $productId]
        );

        sync_primary_photo($productId);
        db()->commit();

    } catch (\Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    // The edited version is only deleted after the row points elsewhere.
    if (!photo_file_in_use($edited)) {
        delete_uploaded_file(DIR_UPLOAD_PRODUCTS, $edited);
    }
}

// ------------------------------------------------------------
// Reading
// ------------------------------------------------------------

/** Every photo of a product, in display order, primary first. */
function product_photos(int $productId): array
{
    if (!photo_gallery_ready()) {
        return [];
    }

    return db_all(
        'SELECT * FROM product_photos
          WHERE product_id = ?
          ORDER BY is_primary DESC, sort_order ASC, id ASC',
        [$productId]
    );
}

/** How many photos a product has. */
function product_photo_count(int $productId): int
{
    if (!photo_gallery_ready()) {
        return 0;
    }

    return (int)db_value('SELECT COUNT(*) FROM product_photos WHERE product_id = ?', [$productId]);
}

/** One photo, but only if it belongs to the given product. */
function find_product_photo(int $photoId, int $productId): ?array
{
    if (!photo_gallery_ready()) {
        return null;
    }

    return db_one(
        'SELECT * FROM product_photos WHERE id = ? AND product_id = ?',
        [$photoId, $productId]
    ) ?: null;
}

/**
 * Photos ready for display: always returns at least one entry so a
 * gallery never renders empty, falling back to the placeholder.
 */
function gallery_images(array $product): array
{
    $photos = photo_gallery_ready() ? product_photos((int)$product['id']) : [];

    if (count($photos) > 0) {
        // The id travels with the image so a selectable option can point
        // at a specific slide ("choosing Blue shows the blue phone").
        return array_map(static fn(array $p): array => [
            'id'  => (int)$p['id'],
            'url' => product_image($p['filename']),
            'alt' => $p['alt_text'] ?: $product['name'],
        ], $photos);
    }

    // No gallery yet: fall back to the single column.
    return [[
        'id'  => 0,
        'url' => product_image($product['image'] ?? null),
        'alt' => $product['name'],
    ]];
}

// ------------------------------------------------------------
// The single sync point
// ------------------------------------------------------------

/**
 * Bring products.image back in line with the gallery.
 *
 * This is the ONLY function that writes products.image once the
 * gallery module is installed. Every mutation below calls it, so the
 * cached column cannot drift away from the gallery.
 */
function sync_primary_photo(int $productId): void
{
    if (!photo_gallery_ready()) {
        return;
    }

    $primary = db_value(
        'SELECT filename FROM product_photos
          WHERE product_id = ?
          ORDER BY is_primary DESC, sort_order ASC, id ASC
          LIMIT 1',
        [$productId]
    );

    db_exec(
        'UPDATE products SET image = ? WHERE id = ?',
        [$primary ?: 'default-product.png', $productId]
    );
}

// ------------------------------------------------------------
// Writing
// ------------------------------------------------------------

/**
 * Add one photo to a product's gallery.
 *
 * @return int the new photo id
 * @throws RuntimeException when the product already has the maximum
 */
function add_product_photo(int $productId, string $filename, string $altText = ''): int
{
    if (!photo_gallery_ready()) {
        throw new RuntimeException('The photo gallery is not set up yet.');
    }

    if (product_photo_count($productId) >= PRODUCT_MAX_PHOTOS) {
        throw new RuntimeException('A product can have at most ' . PRODUCT_MAX_PHOTOS . ' photos.');
    }

    $nextOrder = (int)db_value(
        'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_photos WHERE product_id = ?',
        [$productId]
    );

    // The very first photo becomes the cover automatically.
    $isPrimary = product_photo_count($productId) === 0 ? 1 : 0;

    db_exec(
        'INSERT INTO product_photos (product_id, filename, alt_text, sort_order, is_primary)
         VALUES (?, ?, ?, ?, ?)',
        [$productId, $filename, ($altText === '' ? null : $altText), $nextOrder, $isPrimary]
    );

    $id = (int)db_last_id();

    sync_primary_photo($productId);

    return $id;
}

/** Make one photo the cover, clearing the flag on the others. */
function set_primary_photo(int $photoId, int $productId): void
{
    db()->beginTransaction();

    try {
        db_exec('UPDATE product_photos SET is_primary = 0 WHERE product_id = ?', [$productId]);
        db_exec('UPDATE product_photos SET is_primary = 1 WHERE id = ? AND product_id = ?', [$photoId, $productId]);

        sync_primary_photo($productId);

        db()->commit();
    } catch (\Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

/**
 * Remove a photo, delete its file, and promote another cover if the
 * one being removed was the primary.
 */
function delete_product_photo(int $photoId, int $productId): void
{
    $photo = find_product_photo($photoId, $productId);

    if (!$photo) {
        throw new RuntimeException('Photo not found.');
    }

    db()->beginTransaction();

    try {
        db_exec('DELETE FROM product_photos WHERE id = ? AND product_id = ?', [$photoId, $productId]);

        // If the cover went, the next photo in order takes over.
        if ((int)$photo['is_primary'] === 1) {
            $next = db_value(
                'SELECT id FROM product_photos WHERE product_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1',
                [$productId]
            );

            if ($next) {
                db_exec('UPDATE product_photos SET is_primary = 1 WHERE id = ?', [$next]);
            }
        }

        sync_primary_photo($productId);

        db()->commit();

    } catch (\Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    // Files are only removed after the row is safely gone. Both the
    // current image and any kept original have to go, or deleting an
    // edited photo would leave its original orphaned on disk forever.
    $files = array_filter([
        $photo['filename'],
        $photo['original_filename'] ?? null,
    ]);

    foreach (array_unique($files) as $file) {
        if (!photo_file_in_use($file)) {
            delete_uploaded_file(DIR_UPLOAD_PRODUCTS, $file);
        }
    }
}

/**
 * Apply a new display order.
 *
 * @param int[] $orderedIds photo ids in the order they should appear
 */
function reorder_product_photos(int $productId, array $orderedIds): void
{
    if (!photo_gallery_ready()) {
        return;
    }

    db()->beginTransaction();

    try {
        $position = 0;

        foreach ($orderedIds as $photoId) {
            $photoId = (int)$photoId;

            // product_id is in the WHERE clause, so ids belonging to a
            // different product simply do nothing.
            db_exec(
                'UPDATE product_photos SET sort_order = ? WHERE id = ? AND product_id = ?',
                [$position, $photoId, $productId]
            );

            $position++;
        }

        sync_primary_photo($productId);

        db()->commit();
    } catch (\Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}
