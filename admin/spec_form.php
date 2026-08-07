<?php
// ============================================================
// admin/spec_form.php - Add / edit a specification attribute
// ============================================================

require_once __DIR__ . '/admin_auth.php';

$attributeId = get_int('id');
$isEdit      = $attributeId !== null;

if (!spec_module_ready()) {
    flash_error('Run database/migration_19_specs.sql first.');
    redirect('/admin/specs.php');
}

$attribute = [
    'render_style'  => 'tile',
    'is_selectable' => 0,
    'name'          => '',
    'code'          => '',
    'category_id'   => '',
    'data_type'     => 'text',
    'unit'          => '',
    'options'       => '',
    'is_filterable' => 0,
    'is_comparable' => 1,
    'sort_order'    => 100,
];

if ($isEdit) {
    $found = find_spec_attribute($attributeId);

    if (!$found) {
        flash_error('Attribute not found.');
        redirect('/admin/specs.php');
    }

    $attribute = $found;
}

$title = ($isEdit ? 'Edit' : 'Add') . ' Attribute - Admin';

$categories      = db_all('SELECT id, name FROM categories ORDER BY name ASC');
$categoryOptions = ['' => 'All categories (global)'] + array_column($categories, 'name', 'id');

if (is_post()) {
    csrf_check();

    $name       = post('name');
    $categoryId = post('category_id') === '' ? null : post_int('category_id');
    $dataType   = post('data_type');
    $unit       = post('unit');
    $options    = post('options');
    $sortOrder  = post('sort_order', '100');
    $filterable = post('is_filterable') === '1' ? 1 : 0;
    $comparable = post('is_comparable') === '1' ? 1 : 0;
    $selectable  = spec_options_ready() && post('is_selectable') === '1' ? 1 : 0;
    $renderStyle = post('render_style', 'tile');

    if (!array_key_exists($renderStyle, spec_render_styles())) {
        $renderStyle = 'tile';
    }

    if (v_required('name', $name, 'Attribute name')) {
        v_max('name', $name, 80, 'Attribute name');
    }

    v_in('data_type', $dataType, array_keys(spec_data_types()), 'Type');
    v_max('unit', $unit, 20, 'Unit');
    v_integer('sort_order', $sortOrder, 0, 9999, 'Sort order');

    if ($dataType === 'enum') {
        $list = array_filter(array_map('trim', explode(',', $options)));

        if (count($list) < 2) {
            add_err('options', 'A choice list needs at least two options, separated by commas.');
        } else {
            $options = implode(',', $list);
        }
    } else {
        // Cleared rather than kept: leaving a stale list on a text
        // attribute would resurface if the type were switched back.
        $options = '';
    }

    // A unit only means something on a number.
    if ($dataType !== 'number') {
        $unit = '';
    }

    $code = spec_slug($name);

    if ($code === '') {
        add_err('name', 'The name must contain at least one letter or number.');
    }

    // UNIQUE is on (category_id, code), so the same code may exist under
    // a different category. NULL never equals NULL in SQL, so global
    // attributes are checked with IS NULL rather than = ?.
    if (no_err()) {
        $clash = $categoryId === null
            ? db_one('SELECT id FROM spec_attributes WHERE code = ? AND category_id IS NULL AND id <> ?',
                     [$code, $attributeId ?? 0])
            : db_one('SELECT id FROM spec_attributes WHERE code = ? AND category_id = ? AND id <> ?',
                     [$code, $categoryId, $attributeId ?? 0]);

        if ($clash) {
            add_err('name', 'An attribute with that name already exists for this category.');
        }
    }

    if (no_err()) {
        // Columns are assembled rather than written as nested ternaries.
        // The module adds columns over three migrations, so the set is
        // variable; building it in one place keeps the SQL and the
        // parameter list impossible to get out of step.
        $fields = [
            'name'          => $name,
            'code'          => $code,
            'category_id'   => $categoryId,
            'data_type'     => $dataType,
            'unit'          => $unit !== '' ? $unit : null,
            'options'       => $options !== '' ? $options : null,
            'is_filterable' => $filterable,
            'is_comparable' => $comparable,
            'sort_order'    => (int)$sortOrder,
        ];

        if (spec_options_ready()) {
            $fields['is_selectable'] = $selectable;
        }

        if (spec_render_ready()) {
            $fields['render_style'] = $renderStyle;
        }

        $columns = array_keys($fields);
        $values  = array_values($fields);

        if ($isEdit) {
            $set = implode(', ', array_map(static fn($c) => "$c = ?", $columns));

            $values[] = $attributeId;

            db_exec("UPDATE spec_attributes SET $set WHERE id = ?", $values);

            flash_success('Attribute updated.');

        } else {
            $marks = implode(', ', array_fill(0, count($columns), '?'));

            db_exec(
                'INSERT INTO spec_attributes (' . implode(', ', $columns) . ") VALUES ($marks)",
                $values
            );

            flash_success('Attribute added.');
        }

        redirect('/admin/specs.php');
    }

    $attribute = $found;
}

$title = ($isEdit ? 'Edit' : 'Add') . ' Attribute - Admin';

$categories      = db_all('SELECT id, name FROM categories ORDER BY name ASC');
$categoryOptions = ['' => 'All categories (global)'] + array_column($categories, 'name', 'id');

if (is_post()) {
    csrf_check();

    $name       = post('name');
    $categoryId = post('category_id') === '' ? null : post_int('category_id');
    $dataType   = post('data_type');
    $unit       = post('unit');
    $options    = post('options');
    $sortOrder  = post('sort_order', '100');
    $filterable = post('is_filterable') === '1' ? 1 : 0;
    $comparable = post('is_comparable') === '1' ? 1 : 0;
    $selectable  = spec_options_ready() && post('is_selectable') === '1' ? 1 : 0;
    $renderStyle = post('render_style', 'tile');

    if (!array_key_exists($renderStyle, spec_render_styles())) {
        $renderStyle = 'tile';
    }

    if (v_required('name', $name, 'Attribute name')) {
        v_max('name', $name, 80, 'Attribute name');
    }

    v_in('data_type', $dataType, array_keys(spec_data_types()), 'Type');
    v_max('unit', $unit, 20, 'Unit');
    v_integer('sort_order', $sortOrder, 0, 9999, 'Sort order');

    if ($dataType === 'enum') {
        $list = array_filter(array_map('trim', explode(',', $options)));

        if (count($list) < 2) {
            add_err('options', 'A choice list needs at least two options, separated by commas.');
        } else {
            $options = implode(',', $list);
        }
    } else {
        // Cleared rather than kept: leaving a stale list on a text
        // attribute would resurface if the type were switched back.
        $options = '';
    }

    // A unit only means something on a number.
    if ($dataType !== 'number') {
        $unit = '';
    }

    $code = spec_slug($name);

    if ($code === '') {
        add_err('name', 'The name must contain at least one letter or number.');
    }

    // UNIQUE is on (category_id, code), so the same code may exist under
    // a different category. NULL never equals NULL in SQL, so global
    // attributes are checked with IS NULL rather than = ?.
    if (no_err()) {
        $clash = $categoryId === null
            ? db_one('SELECT id FROM spec_attributes WHERE code = ? AND category_id IS NULL AND id <> ?',
                     [$code, $attributeId ?? 0])
            : db_one('SELECT id FROM spec_attributes WHERE code = ? AND category_id = ? AND id <> ?',
                     [$code, $categoryId, $attributeId ?? 0]);

        if ($clash) {
            add_err('name', 'An attribute with that name already exists for this category.');
        }
    }

    if (no_err()) {
        if ($isEdit) {
            db_exec(
                'UPDATE spec_attributes
                    SET name = ?, code = ?, category_id = ?, data_type = ?, unit = ?,
                        options = ?, is_filterable = ?, is_comparable = ?, sort_order = ?'
                    . (spec_options_ready() ? ', is_selectable = ?' : '')
                    . (spec_render_ready()  ? ', render_style = ?' : '') . '
                  WHERE id = ?',
                spec_render_ready()
                    ? [$name, $code, $categoryId, $dataType, $unit ?: null, $options ?: null,
                       $filterable, $comparable, (int)$sortOrder, $selectable, $renderStyle, $attributeId]
                    : (spec_options_ready()
                    ? [$name, $code, $categoryId, $dataType, $unit ?: null, $options ?: null,
                       $filterable, $comparable, (int)$sortOrder, $selectable, $attributeId]
                    : [$name, $code, $categoryId, $dataType, $unit ?: null, $options ?: null,
                       $filterable, $comparable, (int)$sortOrder, $attributeId])
            );

            flash_success('Attribute updated.');

        } else {
            db_exec(
                'INSERT INTO spec_attributes
                        (name, code, category_id, data_type, unit, options,
                         is_filterable, is_comparable, sort_order'
                    . (spec_options_ready() ? ', is_selectable' : '')
                    . (spec_render_ready()  ? ', render_style' : '') . ')
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?'
                    . (spec_options_ready() ? ', ?' : '')
                    . (spec_render_ready()  ? ', ?' : '') . ')',
                spec_render_ready()
                    ? [$name, $code, $categoryId, $dataType, $unit ?: null, $options ?: null,
                       $filterable, $comparable, (int)$sortOrder, $selectable, $renderStyle]
                    : (spec_options_ready()
                    ? [$name, $code, $categoryId, $dataType, $unit ?: null, $options ?: null,
                       $filterable, $comparable, (int)$sortOrder, $selectable]
                    : [$name, $code, $categoryId, $dataType, $unit ?: null, $options ?: null,
                       $filterable, $comparable, (int)$sortOrder])
            );

            flash_success('Attribute added.');
        }

        redirect('/admin/specs.php');
    }

    // Keep what was typed so the form can be redrawn with it.
    $attribute = array_merge($attribute, [
        'render_style'  => $renderStyle,
        'is_selectable' => $selectable,
        'name'          => $name,
        'category_id'   => $categoryId,
        'data_type'     => $dataType,
        'unit'          => $unit,
        'options'       => $options,
        'is_filterable' => $filterable,
        'is_comparable' => $comparable,
        'sort_order'    => $sortOrder,
    ]);
}

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container admin-narrow">
    <nav class="breadcrumb">
        <a href="/admin/specs.php">Specification Attributes</a> &gt;
        <span><?= $isEdit ? 'Edit' : 'Add' ?></span>
    </nav>

    <div class="card card-padded mt-4">
        <h2 class="section-heading"><?= $isEdit ? 'Edit Attribute' : 'Add Attribute' ?></h2>

        <?php err_summary(); ?>

        <form action="/admin/spec_form.php<?= $isEdit ? '?id=' . (int)$attributeId : '' ?>"
              method="POST" class="form-standard">
            <?php csrf_field(); ?>

            <?php field('name', 'Attribute Name', function () use ($attribute) {
                html_text('name', $attribute['name'], ['required' => true, 'maxlength' => 80,
                                                       'placeholder' => 'e.g. Battery Capacity']);
                echo '<small class="form-hint">A code is generated from this automatically'
                   . ($attribute['code'] !== '' ? ' (currently <code>' . e($attribute['code']) . '</code>)' : '')
                   . '.</small>';
            }, true); ?>

            <?php field('category_id', 'Applies To', function () use ($categoryOptions, $attribute) {
                html_select('category_id', $categoryOptions, (string)($attribute['category_id'] ?? ''));
                echo '<small class="form-hint">Leave as <em>All categories</em> for something '
                   . 'like Warranty that belongs on every product.</small>';
            }); ?>

            <?php field('data_type', 'Type', function () use ($attribute) {
                html_select('data_type', spec_data_types(), $attribute['data_type'],
                            ['id' => 'specDataType']);
                echo '<small class="form-hint">Choose <strong>Number</strong> for anything you '
                   . 'want customers to filter or sort by. Numbers are stored separately so a '
                   . '"RAM of at least 8GB" filter can use an index.</small>';
            }, true); ?>

            <div id="specUnitRow">
                <?php field('unit', 'Unit', function () use ($attribute) {
                    html_text('unit', (string)($attribute['unit'] ?? ''), [
                        'maxlength' => 20, 'placeholder' => 'GB, mAh, inch, W',
                    ]);
                    echo '<small class="form-hint">Shown after the value. Keep it out of the '
                       . 'value itself, or "8" and "8 GB" become two different things.</small>';
                }); ?>
            </div>

            <div id="specOptionsRow">
                <?php field('options', 'Choices', function () use ($attribute) {
                    html_text('options', (string)($attribute['options'] ?? ''), [
                        'placeholder' => 'AMOLED, OLED, LCD, IPS LCD',
                    ]);
                    echo '<small class="form-hint">Separated by commas. At least two.</small>';
                }); ?>
            </div>

            <?php field('sort_order', 'Sort Order', function () use ($attribute) {
                html_number('sort_order', (string)$attribute['sort_order'], ['min' => 0, 'max' => 9999]);
                echo '<small class="form-hint">Lower numbers appear first in the spec table.</small>';
            }); ?>

            <div class="form-group form-check">
                <label for="is_filterable" class="check-label">
                    <input type="checkbox" name="is_filterable" id="is_filterable" value="1"
                           <?= (int)$attribute['is_filterable'] === 1 ? 'checked' : '' ?>>
                    <span>
                        Customers can filter by this
                        <small class="form-hint">
                            Adds it to the catalogue sidebar. Only worth ticking where products
                            genuinely differ &mdash; a filter every product matches is noise.
                        </small>
                    </span>
                </label>
            </div>

            <?php if (spec_options_ready()): ?>
                <div class="form-group form-check">
                    <label for="is_selectable" class="check-label">
                        <input type="checkbox" name="is_selectable" id="is_selectable" value="1"
                               <?= (int)($attribute['is_selectable'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <span>
                            The customer chooses this when buying
                            <small class="form-hint">
                                Colour, storage size and the like. The available choices are
                                set per product, because "Colour" means black and white on one
                                phone and blue and green on another.
                            </small>
                        </span>
                    </label>
                </div>
            <?php endif; ?>

            <?php if (spec_render_ready()): ?>
                <?php field('render_style', 'How the choices look', function () use ($attribute) {
                    html_select('render_style', spec_render_styles(),
                                $attribute['render_style'] ?? 'tile');
                    echo '<small class="form-hint">Only applies to a spec the customer chooses. '
                       . 'Pick <strong>Colour swatches</strong> for colour and nothing else &ndash; '
                       . 'RAM and storage are numbers, and a circle of colour says nothing about them.'
                       . '</small>';
                }); ?>
            <?php endif; ?>

            <div class="form-group form-check">
                <label for="is_comparable" class="check-label">
                    <input type="checkbox" name="is_comparable" id="is_comparable" value="1"
                           <?= (int)$attribute['is_comparable'] === 1 ? 'checked' : '' ?>>
                    <span>
                        Show in the comparison table
                    </span>
                </label>
            </div>

            <div class="form-actions">
                <a href="/admin/specs.php" class="btn-outline">Cancel</a>
                <?php html_submit($isEdit ? 'Save Changes' : 'Add Attribute', ['class' => 'btn-primary']); ?>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
