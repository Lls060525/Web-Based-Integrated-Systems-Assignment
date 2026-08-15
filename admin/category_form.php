<?php
// ============================================================
// admin/category_form.php - Category CRUD (Admin)
// ============================================================

require_once __DIR__ . '/admin_auth.php';

$id     = get_int('id');
$isEdit = $id !== null;

$category = ['name' => '', 'description' => ''];

if ($isEdit) {
    $found = db_one('SELECT id, name, description FROM categories WHERE id = ?', [$id]);

    if (!$found) {
        flash_error('Category not found.');
        redirect('/admin/categories.php');
    }
    $category = $found;
}

if (is_post()) {
    csrf_check();

    $name        = post('name');
    $description = post('description');

    // ---------- Server-side validation ----------
    if (v_required('name', $name, 'Category name')) {
        v_max('name', $name, 100, 'Category name');

        // Names must stay unique so the catalogue filter is unambiguous.
        $dupSql    = 'SELECT id FROM categories WHERE name = ?';
        $dupParams = [$name];
        if ($isEdit) {
            $dupSql     .= ' AND id <> ?';
            $dupParams[] = $id;
        }
        if (db_one($dupSql, $dupParams)) {
            add_err('name', 'Another category already uses this name.');
        }
    }

    v_max('description', $description, 500, 'Description');

    if (no_err()) {
        if ($isEdit) {
            db_exec('UPDATE categories SET name = ?, description = ? WHERE id = ?', [$name, $description, $id]);
            flash_success('Category updated successfully.');
        } else {
            db_exec('INSERT INTO categories (name, description) VALUES (?, ?)', [$name, $description]);
            flash_success('Category created successfully.');
        }

        redirect('/admin/categories.php');
    }
    // Validation failed. Answer with a redirect rather than a page, so
    // the browser's history entry is a GET and F5 cannot resubmit.
    // The errors and what was typed are carried across the redirect.
    redirect_back();
}

$title = ($isEdit ? 'Edit' : 'Add') . ' Category - Admin';

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container admin-container-narrow">
    <div class="admin-header">
        <h2><?= $isEdit ? 'Edit Category' : 'Add New Category' ?></h2>
        <a href="/admin/categories.php" class="btn-outline">&larr; Back to Categories</a>
    </div>

    <div class="card mt-4 card-padded">

        <?php err_summary(); ?>

        <form action="" method="POST" class="form-standard">
            <?php csrf_field(); ?>

            <?php field('name', 'Category Name', function () use ($category) {
                html_text('name', $category['name'], ['required' => true, 'maxlength' => 100, 'autofocus' => true]);
            }, true); ?>

            <?php field('description', 'Description', function () use ($category) {
                html_textarea('description', $category['description'], ['rows' => 5, 'maxlength' => 500]);
            }); ?>

            <div class="form-actions text-right">
                <a href="/admin/categories.php" class="btn-outline">Cancel</a>
                <?php html_submit('Save Category'); ?>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
