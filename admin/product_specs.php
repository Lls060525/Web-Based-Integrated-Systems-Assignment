<?php
// ============================================================
// admin/product_specs.php - Enter the spec VALUES for one product
//
// The form is built from the attributes that apply to this product's
// category, so a phone gets the phone fields and a cable gets the cable
// fields without anyone maintaining two forms.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

$productId = get_int('id');

if ($productId === null) {
    flash_error('Invalid product.');
    redirect('/admin/products.php');
}

if (!spec_module_ready()) {
    flash_error('Run database/migration_19_specs.sql first.');
    redirect('/admin/products.php');
}

$product = db_one(
    'SELECT p.*, c.name AS category_name
       FROM products p
       LEFT JOIN categories c ON c.id = p.category_id
      WHERE p.id = ?',
    [$productId]
);

if (!$product) {
    flash_error('Product not found.');
    redirect('/admin/products.php');
}

$title      = 'Specs: ' . $product['name'] . ' - Admin';
$attributes = spec_attributes_for_product($product);

// Split once, here, rather than testing is_selectable in three places
// further down. A selectable attribute has choices, not a single value,
// so it does not belong in the value grid at all.
$valueAttributes = array_values(array_filter(
    $attributes,
    static fn(array $a) => !spec_options_ready() || (int)($a['is_selectable'] ?? 0) === 0
));

if (is_post()) {
    csrf_check();

    // ---------- Add a spec that belongs to this product alone ----------
    // Scoped to the product, so adding "SIM Slots" to one phone does not
    // put an empty "SIM Slots" field on every other phone in the category.
    if (post('action') === 'add_attribute') {
        $newName = post('new_name');
        $newType = post('new_type', 'text');
        $newUnit = post('new_unit');
        $newVal  = post('new_value');
        $newSel   = post('new_selectable') === '1';
        $newStyle = post('new_style', 'tile');

        if (!array_key_exists($newStyle, spec_render_styles())) {
            $newStyle = 'tile';
        }

        if (v_required('new_name', $newName, 'Spec name')) {
            v_max('new_name', $newName, 80, 'Spec name');
        }

        v_in('new_type', $newType, array_keys(spec_data_types()), 'Type');

        if (spec_slug($newName) === '') {
            add_err('new_name', 'The name needs at least one letter or number.');
        }

        // A name already covering this product would create two fields
        // that look identical on the form.
        //
        // Saying only "it already applies" is a dead end: the admin cannot
        // see WHERE it already is, and if it is a customer-choice spec it
        // has no field in the grid at all, so there is nothing to find.
        // The message therefore names the scope and the next action.
        foreach ($attributes as $existing) {
            if (strcasecmp($existing['name'], trim($newName)) !== 0) {
                continue;
            }

            $isSelectable = spec_options_ready() && (int)($existing['is_selectable'] ?? 0) === 1;

            if ($isSelectable) {
                $where = 'It is a customer-choice spec, so it has no single value here. '
                       . 'Set what customers can pick under "Manage Customer Choices" below.';

            } elseif (!empty($existing['product_id'])) {
                $where = 'You already added it to this product. '
                       . 'Its field is in the grid above - just type the value there and save.';

            } elseif ($existing['category_id'] === null) {
                $where = 'It applies to every product in the shop. '
                       . 'Its field is in the grid above - just type the value there and save.';

            } else {
                $categoryName = (string)db_value(
                    'SELECT name FROM categories WHERE id = ?', [$existing['category_id']]
                );

                $where = 'It comes from the ' . ($categoryName !== '' ? $categoryName : 'product')
                       . ' category, so every product in it has this field. '
                       . 'Its field is in the grid above - just type the value there and save.';
            }

            add_err('new_name', '"' . $existing['name'] . '" already applies to this product. ' . $where);
            break;
        }

        if (no_err()) {
            $newId = create_product_attribute($productId, trim($newName), $newType,
                                              trim($newUnit), $newSel, $newStyle);

            if ($newVal !== '' && !$newSel) {
                $attribute = find_spec_attribute($newId);

                if ($attribute) {
                    save_product_spec($productId, $attribute, $newVal);
                }
            }

            flash_success($newSel
                ? '"' . trim($newName) . '" added. Now set the choices customers can pick from.'
                : '"' . trim($newName) . '" added to this product.');

            redirect($newSel
                ? '/admin/product_options.php?id=' . $productId
                : '/admin/product_specs.php?id=' . $productId);
        }
    }

    $submitted = $_POST['spec'] ?? [];

    if (!is_array($submitted)) {
        $submitted = [];
    }

    // Validated in full before anything is written, so a single bad
    // field does not leave half the specs saved and half not.
    $isValueSave = post('action') !== 'add_attribute';
    $values      = [];

    foreach ($valueAttributes as $attribute) {
        $raw    = (string)($submitted[$attribute['id']] ?? '');
        $result = spec_normalise_value($attribute, $raw);

        if ($result['error'] !== null) {
            add_err('spec_' . $attribute['id'], $result['error']);
        }

        $values[(int)$attribute['id']] = $raw;
    }

    if (no_err() && $isValueSave) {
        db()->beginTransaction();

        try {
            foreach ($valueAttributes as $attribute) {
                save_product_spec($productId, $attribute, $values[(int)$attribute['id']]);
            }

            db()->commit();
            flash_success('Specifications saved.');

        } catch (\Throwable $e) {
            db()->rollBack();
            flash_error('Could not save the specifications: ' . $e->getMessage());
        }

        redirect('/admin/product_specs.php?id=' . $productId);
    }

    $current = $values;

    // Validation failed. Answer with a redirect rather than a page, so
    // the browser's history entry is a GET and F5 cannot resubmit.
    // The errors and what was typed are carried across the redirect.
    redirect_back();
} else {
    $current = product_spec_map($productId);
}

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container">
    <nav class="breadcrumb">
        <a href="/admin/products.php">Product Management</a> &gt;
        <a href="/admin/product_form.php?id=<?= (int)$productId ?>"><?= e($product['name']) ?></a> &gt;
        <span>Specifications</span>
    </nav>

    <div class="admin-header">
        <h2>Specifications</h2>

        <div class="admin-header-actions">
            <a href="/product_detail.php?id=<?= (int)$productId ?>" class="btn-outline">View on Store</a>
            <a href="/admin/specs.php" class="btn-outline">Manage Attributes</a>
        </div>
    </div>

    <p class="muted small-note">
        <strong><?= e($product['name']) ?></strong> &middot;
        <?= e($product['category_name'] ?? 'No category') ?>.
        The fields below are the attributes defined for this category, plus any global ones.
        Leave a field blank to remove that spec.
    </p>

    <?php err_summary(); ?>

    <?php if ($valueAttributes === []): ?>

        <div class="card card-padded mt-4 empty-state-box">
            <h3 class="empty-state-title">No attributes apply to this product.</h3>
            <p class="muted">
                Specification attributes are defined per category.
                <?php if ($product['category_name'] === null): ?>
                    This product has no category, so only global attributes would apply
                    and none exist yet.
                <?php else: ?>
                    Nothing has been defined for <strong><?= e($product['category_name']) ?></strong> yet.
                <?php endif; ?>
            </p>
            <p class="muted">
                You can add one just for this product using the form below, or define one
                for the whole category from <a href="/admin/specs.php">Specs</a>.
            </p>
        </div>

    <?php else: ?>

        <form action="/admin/product_specs.php?id=<?= (int)$productId ?>"
              method="POST" class="form-standard">
            <?php csrf_field(); ?>

            <div class="card card-padded mt-4">
                <div class="spec-form-grid">
                    <?php foreach ($valueAttributes as $a): ?>
                        <?php
                            $key   = 'spec_' . $a['id'];
                            $value = (string)($current[(int)$a['id']] ?? '');
                            $label = $a['name'] . ($a['unit'] ? ' (' . $a['unit'] . ')' : '');
                        ?>

                        <div class="form-group <?= has_err($key) ? 'has-error' : '' ?>">
                            <label for="<?= e($key) ?>">
                                <?= e($label) ?>

                                <?php
                                    /* Where this field comes from. Without it the
                                     * grid gives no clue why "RAM" is already here,
                                     * which is exactly the confusion the duplicate
                                     * message has to clear up after the fact.
                                     */
                                    if (!empty($a['product_id'])) {
                                        $tag = ['This product', 'badge-success'];
                                    } elseif ($a['category_id'] === null) {
                                        $tag = ['All products', 'badge-info'];
                                    } else {
                                        $tag = [$product['category_name'] ?: 'Category', 'badge-muted'];
                                    }
                                ?>

                                <span class="badge <?= e($tag[1]) ?> spec-global-tag"><?= e($tag[0]) ?></span>
                            </label>

                            <?php if ($a['data_type'] === 'boolean'): ?>
                                <select id="<?= e($key) ?>" name="spec[<?= (int)$a['id'] ?>]" class="form-control">
                                    <option value="">&mdash;</option>
                                    <option value="Yes" <?= $value === 'Yes' ? 'selected' : '' ?>>Yes</option>
                                    <option value="No"  <?= $value === 'No'  ? 'selected' : '' ?>>No</option>
                                </select>

                            <?php elseif ($a['data_type'] === 'enum'): ?>
                                <select id="<?= e($key) ?>" name="spec[<?= (int)$a['id'] ?>]" class="form-control">
                                    <option value="">&mdash;</option>
                                    <?php foreach (spec_options($a) as $option): ?>
                                        <option value="<?= e($option) ?>" <?= $value === $option ? 'selected' : '' ?>>
                                            <?= e($option) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                            <?php elseif ($a['data_type'] === 'number'): ?>
                                <input type="text" inputmode="decimal"
                                       id="<?= e($key) ?>" name="spec[<?= (int)$a['id'] ?>]"
                                       value="<?= e($value) ?>" class="form-control"
                                       placeholder="<?= e($a['unit'] ? 'number in ' . $a['unit'] : 'number') ?>">

                            <?php else: ?>
                                <input type="text" maxlength="255"
                                       id="<?= e($key) ?>" name="spec[<?= (int)$a['id'] ?>]"
                                       value="<?= e($value) ?>" class="form-control">
                            <?php endif; ?>

                            <?php err($key); ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="form-actions">
                    <a href="/admin/products.php" class="btn-outline">Back</a>
                    <?php html_submit('Save Specifications', ['class' => 'btn-primary']); ?>
                </div>
            </div>
        </form>

    <?php endif; ?>

    <?php if (spec_options_ready()): ?>

        <?php $selectable = product_selectable_specs($productId); ?>

        <div class="card card-padded mt-4">
            <h3 class="section-heading">Customer choices</h3>
            <p class="muted small-note">
                Specs the customer picks when buying, such as colour or storage.
                Each choice can carry a price difference.
            </p>

            <?php if ($selectable === []): ?>
                <p class="muted small-note">
                    None set up for this product yet.
                </p>
            <?php else: ?>
                <ul class="spec-choice-summary">
                    <?php foreach ($selectable as $sel): ?>
                        <li>
                            <strong><?= e($sel['name']) ?></strong>
                            <span class="muted">
                                <?= e(implode(', ', array_column($sel['choices'], 'value_text'))) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <a href="/admin/product_options.php?id=<?= (int)$productId ?>" class="btn-outline">
                Manage Customer Choices
            </a>
        </div>

        <div class="card card-padded mt-4">
            <h3 class="section-heading">Add a spec to this product</h3>
            <p class="muted small-note">
                This one belongs to <strong><?= e($product['name']) ?></strong> only. Other
                products in the same category will not grow an empty field for it. Use
                <a href="/admin/specs.php">Specs</a> instead if you want it on the whole category.
            </p>

            <form action="/admin/product_specs.php?id=<?= (int)$productId ?>"
                  method="POST" class="form-standard">
                <?php csrf_field(); ?>
                <?php html_hidden('action', 'add_attribute'); ?>

                <div class="batch-options">
                    <?php field('new_name', 'Name', function () {
                        html_text('new_name', '', ['maxlength' => 80, 'placeholder' => 'e.g. SIM Slots']);
                    }, true); ?>

                    <?php field('new_type', 'Type', function () {
                        html_select('new_type', spec_data_types(), post('new_type', 'text'));
                    }); ?>
                </div>

                <div class="batch-options">
                    <?php field('new_unit', 'Unit (numbers only)', function () {
                        html_text('new_unit', '', ['maxlength' => 20, 'placeholder' => 'GB, mAh, mm']);
                    }); ?>

                    <?php field('new_value', 'Value', function () {
                        html_text('new_value', '', ['placeholder' => 'Leave blank to fill in later']);
                    }); ?>
                </div>

                <div class="form-group form-check">
                    <label for="new_selectable" class="check-label">
                        <input type="checkbox" name="new_selectable" id="new_selectable" value="1"
                               <?= post('new_selectable') === '1' ? 'checked' : '' ?>>
                        <span>
                            The customer chooses this when buying
                            <small class="form-hint">
                                For colour, storage size and the like. You will be sent
                                straight to the choices editor, because a selectable spec
                                has options rather than one value.
                            </small>
                        </span>
                    </label>
                </div>

                <?php if (spec_render_ready()): ?>
                    <?php field('new_style', 'How the choices look', function () {
                        html_select('new_style', spec_render_styles(), post('new_style', 'tile'));
                        echo '<small class="form-hint">Only used when the box above is ticked. '
                           . 'Colour swatches are for colour; everything else is tiles.</small>';
                    }); ?>
                <?php endif; ?>

                <div class="form-actions">
                    <?php html_submit('Add Spec', ['class' => 'btn-outline']); ?>
                </div>
            </form>
        </div>

    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
