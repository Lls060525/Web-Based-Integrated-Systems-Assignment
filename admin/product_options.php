<?php
// ============================================================
// admin/product_options.php - the choices a customer can pick
//
// Options belong to the PRODUCT, not to the attribute. "Colour" means
// black and white on one phone and blue and green on another, so the
// list lives here rather than on the attribute definition.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

$productId = get_int('id');

if ($productId === null) {
    flash_error('Invalid product.');
    redirect('/admin/products.php');
}

if (!spec_options_ready()) {
    flash_error('Run database/migration_20_spec_options.sql first.');
    redirect('/admin/products.php');
}

$product = db_one(
    'SELECT p.*, c.name AS category_name
       FROM products p LEFT JOIN categories c ON c.id = p.category_id
      WHERE p.id = ?',
    [$productId]
);

if (!$product) {
    flash_error('Product not found.');
    redirect('/admin/products.php');
}

$title = 'Choices: ' . $product['name'] . ' - Admin';

// Any attribute that reaches this product and is marked selectable,
// whether or not it has choices yet.
$selectable = db_all(
    'SELECT * FROM spec_attributes
      WHERE is_selectable = 1
        AND (product_id = ? OR (product_id IS NULL AND (category_id IS NULL OR category_id = ?)))
      ORDER BY sort_order ASC, name ASC',
    [$productId, $product['category_id']]
);

if (is_post()) {
    csrf_check();

    $action = post('action');

    // ---------- Add one choice ----------
    if ($action === 'add') {
        $attributeId = post_int('attribute_id');
        $value       = trim(post('value_text'));
        $delta       = post('price_delta', '0');
        $photoId     = post_int('photo_id');

        // Resolved first, because whether the colour field is read at all
        // depends on which spec this is.
        $attribute = null;

        foreach ($selectable as $candidate) {
            if ((int)$candidate['id'] === $attributeId) {
                $attribute = $candidate;
                break;
            }
        }

        if ($attribute === null) {
            add_err('attribute_id', 'That spec is not selectable for this product.');
        }

        // The colour is only read for a spec that is actually drawn as
        // swatches. An <input type="color"> always posts a value --
        // #000000 when it was never touched -- so reading it for every
        // spec is what gave RAM a black swatch and turned it into colour
        // circles. The field is not rendered for tile specs now, and this
        // guard is what makes that a rule rather than a cosmetic choice.
        $swatch       = '';
        $isSwatchSpec = spec_render_ready()
            && $attribute !== null
            && ($attribute['render_style'] ?? 'tile') === 'swatch';

        if ($isSwatchSpec) {
            $swatch = trim(post('swatch_hex'));

            if ($swatch !== '' && !preg_match('~^#[0-9a-fA-F]{6}$~', $swatch)) {
                $swatch = '';
            }
        }

        if (v_required('value_text', $value, 'Choice')) {
            v_max('value_text', $value, 120, 'Choice');
        }

        if ($delta === '' || !is_numeric($delta)) {
            add_err('price_delta', 'Price difference must be a number. Use 0 for no change.');
        } elseif (abs((float)$delta) > 999999) {
            add_err('price_delta', 'That price difference is out of range.');
        }

        if (no_err()) {
            $exists = db_one(
                'SELECT id FROM product_spec_options
                  WHERE product_id = ? AND attribute_id = ? AND value_text = ?',
                [$productId, $attributeId, $value]
            );

            if ($exists) {
                add_err('value_text', '"' . $value . '" is already a choice for this spec.');
            } else {
                $next = (int)db_value(
                    'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM product_spec_options
                      WHERE product_id = ? AND attribute_id = ?',
                    [$productId, $attributeId]
                );

                if (spec_style_ready()) {
                    db_exec(
                        'INSERT INTO product_spec_options
                                (product_id, attribute_id, value_text, price_delta,
                                 swatch_hex, photo_id, sort_order)
                         VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$productId, $attributeId, $value, (float)$delta,
                         $swatch !== '' ? $swatch : null,
                         $photoId ?: null, $next]
                    );
                } else {
                    db_exec(
                        'INSERT INTO product_spec_options
                                (product_id, attribute_id, value_text, price_delta, sort_order)
                         VALUES (?, ?, ?, ?, ?)',
                        [$productId, $attributeId, $value, (float)$delta, $next]
                    );
                }

                flash_success('"' . $value . '" added.');
                redirect('/admin/product_options.php?id=' . $productId);
            }
        }
    }

    // ---------- Remove one choice ----------
    if ($action === 'delete') {
        $optionId = post_int('option_id');

        $removed = db_exec(
            'DELETE FROM product_spec_options WHERE id = ? AND product_id = ?',
            [$optionId, $productId]
        );

        // Existing orders keep their own snapshot in order_items.options_text,
        // so removing a choice never rewrites what somebody already bought.
        // Carts still holding it are flagged on the cart page instead.
        flash_success($removed > 0 ? 'Choice removed.' : 'Choice not found.');
        redirect('/admin/product_options.php?id=' . $productId);
    }

    // ---------- Sold out / back in stock ----------
    // A toggle rather than a delete: removing the option would make it
    // vanish for customers and invalidate any cart line holding it,
    // whereas this greys it out and comes back with one click.
    if ($action === 'availability' && spec_style_ready()) {
        $optionId = post_int('option_id');
        $state    = post('state') === '1' ? 1 : 0;

        db_exec('UPDATE product_spec_options SET is_available = ? WHERE id = ? AND product_id = ?',
                [$state, $optionId, $productId]);

        flash_success($state === 1 ? 'Choice is available again.' : 'Choice marked sold out.');
        redirect('/admin/product_options.php?id=' . $productId);
    }

    // ---------- Mark the default ----------
    if ($action === 'default') {
        $optionId    = post_int('option_id');
        $attributeId = post_int('attribute_id');

        db_exec('UPDATE product_spec_options SET is_default = 0
                  WHERE product_id = ? AND attribute_id = ?', [$productId, $attributeId]);

        db_exec('UPDATE product_spec_options SET is_default = 1
                  WHERE id = ? AND product_id = ?', [$optionId, $productId]);

        flash_success('Default choice updated.');
        redirect('/admin/product_options.php?id=' . $productId);
    }
}

$photoOptions = ['' => 'No photo change'];

if (spec_style_ready() && photo_gallery_ready()) {
    foreach (product_photos($productId) as $i => $photo) {
        $photoOptions[(int)$photo['id']] = 'Photo ' . ($i + 1)
            . ($photo['alt_text'] ? ' - ' . $photo['alt_text'] : '');
    }
}

$options = product_options($productId);

$byAttribute = [];
foreach ($options as $option) {
    $byAttribute[(int)$option['attribute_id']][] = $option;
}

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container">
    <nav class="breadcrumb">
        <a href="/admin/products.php">Product Management</a> &gt;
        <a href="/admin/product_specs.php?id=<?= (int)$productId ?>"><?= e($product['name']) ?></a> &gt;
        <span>Customer Choices</span>
    </nav>

    <div class="admin-header">
        <h2>Customer Choices</h2>
        <div class="admin-header-actions">
            <a href="/product_detail.php?id=<?= (int)$productId ?>" class="btn-outline">View on Store</a>
            <a href="/admin/product_specs.php?id=<?= (int)$productId ?>" class="btn-outline">Back to Specs</a>
        </div>
    </div>

    <?php err_summary(); ?>

    <?php if ($selectable === []): ?>

        <div class="card card-padded mt-4 empty-state-box">
            <h3 class="empty-state-title">No selectable specs yet.</h3>
            <p class="muted">
                A spec has to be marked <em>the customer chooses this</em> before it can
                have choices. Add one on the
                <a href="/admin/product_specs.php?id=<?= (int)$productId ?>">specifications page</a>,
                or tick the box on an existing attribute under
                <a href="/admin/specs.php">Specs</a>.
            </p>
        </div>

    <?php else: ?>

        <p class="muted small-note">
            Base price is <strong><?= e(money($product['price'])) ?></strong>.
            A price difference is added to it, so <code>0</code> means no change and
            <code>-50</code> makes that choice cheaper.
            <?php if (spec_style_ready()): ?>
                Give a choice a colour and it renders as a swatch on the storefront;
                point it at a photo and picking it switches the main image.
            <?php endif; ?>
        </p>

        <?php foreach ($selectable as $attribute): ?>
            <?php
                $rows          = $byAttribute[(int)$attribute['id']] ?? [];
                $isSwatchGroup = spec_render_ready()
                              && ($attribute['render_style'] ?? 'tile') === 'swatch';
            ?>

            <div class="card card-padded mt-4">
                <h3 class="section-heading">
                    <?= e($attribute['name']) ?>
                    <span class="badge badge-info"><?= e(spec_scope_label($attribute)) ?></span>
                    <?php if (spec_render_ready()): ?>
                        <span class="badge badge-muted">
                            <?= $isSwatchGroup ? 'Colour swatches' : 'Tiles' ?>
                        </span>
                    <?php endif; ?>
                </h3>

                <?php if (spec_render_ready() && !$isSwatchGroup): ?>
                    <p class="muted small-note">
                        Shown as tiles with the price difference underneath. Only a spec set to
                        <em>Colour swatches</em> under
                        <a href="/admin/spec_form.php?id=<?= (int)$attribute['id'] ?>">its definition</a>
                        asks for a colour, which is why there is no colour box here.
                    </p>
                <?php endif; ?>

                <?php if ($rows === []): ?>
                    <p class="muted small-note">
                        No choices yet, so this does not appear on the storefront.
                        A selectable spec with fewer than one choice is skipped entirely.
                    </p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Choice</th>
                                    <th class="text-right">Price difference</th>
                                    <th class="text-right">Sells for</th>
                                    <?php if (spec_style_ready()): ?>
                                        <th>Photo</th>
                                        <th class="text-center">In stock</th>
                                    <?php endif; ?>
                                    <th class="text-center">Default</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <?php $delta = (float)$row['price_delta']; ?>
                                    <?php $live = !spec_style_ready() || (int)($row['is_available'] ?? 1) === 1; ?>
                                    <tr class="<?= $live ? '' : 'option-sold-out' ?>">
                                        <td>
                                            <?php if ($isSwatchGroup && !empty($row['swatch_hex'])): ?>
                                                <span class="swatch-chip"
                                                      style="background: <?= e($row['swatch_hex']) ?>"></span>
                                            <?php endif; ?>
                                            <strong><?= e($row['value_text']) ?></strong>
                                        </td>
                                        <td class="text-right <?= $delta > 0 ? 'delta-up' : ($delta < 0 ? 'delta-down' : 'muted') ?>">
                                            <?= $delta > 0 ? '+' : '' ?><?= e(money($delta)) ?>
                                        </td>
                                        <td class="text-right">
                                            <?= e(money((float)$product['price'] + $delta)) ?>
                                        </td>

                                        <?php if (spec_style_ready()): ?>
                                            <td class="muted small-note">
                                                <?= e($photoOptions[(int)($row['photo_id'] ?? 0)] ?? 'No photo change') ?>
                                            </td>
                                            <td class="text-center">
                                                <form action="/admin/product_options.php?id=<?= (int)$productId ?>"
                                                      method="POST" class="inline-form">
                                                    <?php csrf_field(); ?>
                                                    <?php html_hidden('action', 'availability'); ?>
                                                    <?php html_hidden('option_id', $row['id']); ?>
                                                    <?php html_hidden('state', $live ? '0' : '1'); ?>
                                                    <?php html_submit($live ? 'In stock' : 'Sold out', [
                                                        'class' => 'btn-outline btn-sm ' . ($live ? '' : 'btn-danger'),
                                                    ]); ?>
                                                </form>
                                            </td>
                                        <?php endif; ?>

                                        <td class="text-center">
                                            <?php if ((int)$row['is_default'] === 1): ?>
                                                <i class="fas fa-check spec-yes"></i>
                                            <?php else: ?>
                                                <form action="/admin/product_options.php?id=<?= (int)$productId ?>"
                                                      method="POST" class="inline-form">
                                                    <?php csrf_field(); ?>
                                                    <?php html_hidden('action', 'default'); ?>
                                                    <?php html_hidden('option_id', $row['id']); ?>
                                                    <?php html_hidden('attribute_id', $attribute['id']); ?>
                                                    <?php html_submit('Set', ['class' => 'btn-outline btn-sm']); ?>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right">
                                            <form action="/admin/product_options.php?id=<?= (int)$productId ?>"
                                                  method="POST" class="inline-form"
                                                  data-confirm="Remove &quot;<?= e($row['value_text']) ?>&quot;? Past orders keep their own record of it.">
                                                <?php csrf_field(); ?>
                                                <?php html_hidden('action', 'delete'); ?>
                                                <?php html_hidden('option_id', $row['id']); ?>
                                                <?php html_submit('Remove', ['class' => 'btn-outline btn-sm btn-danger']); ?>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <form action="/admin/product_options.php?id=<?= (int)$productId ?>"
                      method="POST" class="option-add-form">
                    <?php csrf_field(); ?>
                    <?php html_hidden('action', 'add'); ?>
                    <?php html_hidden('attribute_id', $attribute['id']); ?>

                    <input type="text" name="value_text" maxlength="120"
                           placeholder="e.g. Midnight Black" class="form-control"
                           aria-label="New choice for <?= e($attribute['name']) ?>">

                    <input type="number" name="price_delta" step="0.01" value="0"
                           placeholder="0.00" class="form-control option-delta-input"
                           aria-label="Price difference">

                    <?php if (spec_style_ready()): ?>
                        <?php if (spec_render_ready() && ($attribute['render_style'] ?? 'tile') === 'swatch'): ?>
                            <label class="option-swatch-field" title="Swatch colour">
                                <span class="muted small-note">Colour</span>
                                <input type="color" name="swatch_hex" value="#888888"
                                       class="option-colour-input" aria-label="Swatch colour">
                            </label>
                        <?php endif; ?>

                        <?php if (count($photoOptions) > 1): ?>
                            <select name="photo_id" class="form-control option-photo-select"
                                    aria-label="Photo to show when this is chosen">
                                <?php foreach ($photoOptions as $value => $label): ?>
                                    <option value="<?= e($value) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php html_submit('Add Choice', ['class' => 'btn-outline']); ?>
                </form>
            </div>
        <?php endforeach; ?>

    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
