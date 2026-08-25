<?php
// ============================================================
// admin/specs.php - Specification Attributes (Admin)
//
// This page manages the DEFINITIONS. The values themselves are entered
// per product on admin/product_specs.php.
//
// ------------------------------------------------------------
// THE PROBLEM: EVERY PRODUCT HAS DIFFERENT SPECIFICATIONS
// ------------------------------------------------------------
//
// A phone has RAM, storage, screen size and battery. A cable has
// length and wattage. A case has a colour and nothing else. There is
// no set of columns that fits all three.
//
// The obvious answer is to add columns to `products`: ram_gb,
// storage_gb, screen_inches, cable_length_m... It falls apart quickly.
// Every new kind of product needs a schema change; the table fills
// with columns that are NULL for most rows; and adding "water
// resistance" means an ALTER TABLE on live data because somebody
// stocked a new model.
//
// ------------------------------------------------------------
// THE ANSWER: EAV -- ENTITY, ATTRIBUTE, VALUE
// ------------------------------------------------------------
//
// Instead of columns, specifications are ROWS in two tables:
//
//   spec_attributes   the definitions -- "RAM", measured in GB,
//                     belongs to the Smartphones category
//                     (THIS page maintains these)
//
//   product_specs     the values -- product 7 has attribute 3 with
//                     the value "12"
//                     (admin/product_specs.php maintains these)
//
// Adding a new specification is now INSERTING A ROW rather than
// changing the schema. Products carry only the attributes that apply
// to them, so nothing is NULL-padded.
//
// The cost is honest and worth knowing for the viva: reading a
// product's specs is a JOIN returning many rows instead of reading
// columns off one row, and the database can no longer type-check the
// values -- "12" and "twelve" are both just text. EAV trades the
// database's help for flexibility. For a catalogue whose product
// types are not known in advance, that is the right trade; for a
// fixed, well-understood set of fields it would not be.
//
// ------------------------------------------------------------
// WHY THE DEFINITIONS GET THEIR OWN SCREEN
// ------------------------------------------------------------
//
// Because they are shared. "RAM" is defined once and used by every
// phone, so the unit, the display order and the category it applies to
// are stated in one place. Letting each product invent its own
// attribute names would produce "RAM", "Ram", "Memory" and "RAM (GB)"
// as four unrelated things, and the comparison page would be useless.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

require_permission('specs.manage');

$title = 'Specification Attributes - Admin';

if (!spec_module_ready()) {
    include __DIR__ . '/../includes/admin_header.php';
    echo '<div class="admin-container"><div class="alert alert-error mt-4">'
       . 'This module is not installed yet. Run '
       . '<code>database/migration_19_specs.sql</code> in phpMyAdmin.'
       . '</div></div>';
    include __DIR__ . '/../includes/admin_footer.php';
    exit;
}

if (is_post()) {
    csrf_check();

    if (post('action') === 'delete') {
        $id        = post_int('id');
        $attribute = $id !== null ? find_spec_attribute($id) : null;

        if (!$attribute) {
            flash_error('Attribute not found.');
        } else {
            // The FK cascades, but the count is reported first so the
            // consequence is a decision rather than a surprise.
            //
            // Deleting the "RAM" attribute removes the RAM value from
            // every phone that has one -- ON DELETE CASCADE on
            // product_specs.attribute_id does that automatically, and
            // silently. One click, dozens of rows gone, no message.
            //
            // So the count is taken BEFORE the delete (afterwards the
            // rows are already gone and the answer is always 0) purely
            // so the success message can say how much went with it.
            //
            // Note what this deliberately does NOT do: block the
            // delete. Cascading is correct here -- a RAM value with no
            // RAM attribute is meaningless data, not history worth
            // keeping. It is the difference from products, which are
            // deactivated rather than deleted because an order still
            // refers to them. The test is always the same: does
            // anything else depend on this row surviving?
            $used = (int)db_value(
                'SELECT COUNT(*) FROM product_specs WHERE attribute_id = ?', [$id]
            );

            db_exec('DELETE FROM spec_attributes WHERE id = ?', [$id]);

            flash_success('"' . $attribute['name'] . '" deleted'
                        . ($used > 0 ? ', along with ' . $used . ' recorded value(s).' : '.'));
        }
    }

    redirect('/admin/specs.php');
}

$categoryId = get_int('category');
$attributes = all_spec_attributes($categoryId);
$categories = db_all('SELECT id, name FROM categories ORDER BY name ASC');
$coverage   = spec_coverage();

$categoryOptions = ['' => 'All categories'] + array_column($categories, 'name', 'id');

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Specification Attributes</h2>

        <div class="admin-header-actions">
            <form action="/admin/specs.php" method="GET" class="admin-search-form">
                <?php html_select('category', $categoryOptions, (string)($categoryId ?? '')); ?>
                <?php html_submit('Filter'); ?>
            </form>

            <a href="/admin/spec_form.php" class="btn-primary">+ Add Attribute</a>
        </div>
    </div>

    <p class="muted small-note">
        An attribute is the <em>name</em> of a spec, not its value. Attributes tied to a
        category only appear on products in that category; leave the category blank to
        make one apply to everything.
        <strong><?= (int)$coverage['with'] ?></strong> of
        <strong><?= (int)$coverage['total'] ?></strong> products currently have specs recorded.
    </p>

    <div class="card mt-4">
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Attribute</th>
                        <th>Category</th>
                        <th>Type</th>
                        <th>Unit</th>
                        <th class="text-center">Filterable</th>
                        <th class="text-center">Compare</th>
                        <th class="text-center">Customer picks</th>
                        <th class="text-right">In use</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($attributes === []): ?>
                        <tr>
                            <td colspan="9" class="text-center muted">
                                No attributes yet. Add one, or run the migration to load the samples.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($attributes as $a): ?>
                            <tr>
                                <td>
                                    <strong><?= e($a['name']) ?></strong>
                                    <div class="muted small-note"><code><?= e($a['code']) ?></code></div>
                                </td>
                                <td>
                                    <?php if ($a['category_name'] === null): ?>
                                        <span class="badge badge-info">All categories</span>
                                    <?php else: ?>
                                        <?= e($a['category_name']) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= e(ucfirst($a['data_type'])) ?>
                                    <?php if ($a['data_type'] === 'enum'): ?>
                                        <div class="muted small-note"><?= e($a['options']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($a['unit'] ?: '-') ?></td>
                                <td class="text-center">
                                    <?= (int)$a['is_filterable'] === 1
                                        ? '<i class="fas fa-check spec-yes"></i>'
                                        : '<span class="muted">-</span>' ?>
                                </td>
                                <td class="text-center">
                                    <?= (int)$a['is_comparable'] === 1
                                        ? '<i class="fas fa-check spec-yes"></i>'
                                        : '<span class="muted">-</span>' ?>
                                </td>
                                <td class="text-center">
                                    <?= spec_options_ready() && (int)($a['is_selectable'] ?? 0) === 1
                                        ? '<i class="fas fa-check spec-yes"></i>'
                                        : '<span class="muted">-</span>' ?>
                                </td>
                                <td class="text-right"><?= (int)$a['value_count'] ?></td>
                                <td class="text-right">
                                    <div class="row-actions">
                                        <a href="/admin/spec_form.php?id=<?= (int)$a['id'] ?>"
                                           class="btn-outline btn-sm">Edit</a>

                                        <form action="/admin/specs.php" method="POST" class="inline-form"
                                              data-confirm="Delete &quot;<?= e($a['name']) ?>&quot;<?=
                                                  (int)$a['value_count'] > 0
                                                      ? ' and the ' . (int)$a['value_count'] . ' value(s) recorded against it'
                                                      : '' ?>? This cannot be undone.">
                                            <?php csrf_field(); ?>
                                            <?php html_hidden('action', 'delete'); ?>
                                            <?php html_hidden('id', $a['id']); ?>
                                            <?php html_submit('Delete', ['class' => 'btn-outline btn-sm btn-danger']); ?>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
