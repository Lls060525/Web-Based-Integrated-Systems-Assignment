<?php
// ============================================================
// admin/categories.php - Category Maintenance (Admin)
// ============================================================

require_once __DIR__ . '/admin_auth.php';

require_permission('categories.manage');

$title = 'Category Maintenance - Admin';

// ---------- Delete ----------
if (is_post()) {
    csrf_check();

    if (post('action') === 'delete') {
        $id = post_int('id');

        if ($id !== null) {
            // Check the dependency first so we can give a useful message
            // instead of relying on a foreign-key exception.
            $inUse = (int)db_value('SELECT COUNT(*) FROM products WHERE category_id = ?', [$id]);

            if ($inUse > 0) {
                flash_error('Cannot delete this category: ' . $inUse . ' product(s) still belong to it.');
            } else {
                db_exec('DELETE FROM categories WHERE id = ?', [$id]);
                flash_success('Category deleted successfully.');
            }
        }
    }

    redirect('/admin/categories.php');
}

// ---------- Listing (with the product count per category) ----------
$categories = db_all(
    'SELECT c.id, c.name, c.description, c.created_at, COUNT(p.id) AS product_count
       FROM categories c
       LEFT JOIN products p ON p.category_id = c.id
      GROUP BY c.id
      ORDER BY c.id DESC'
);

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Category Maintenance</h2>
        <a href="/admin/category_form.php" class="btn-primary">+ Add New Category</a>
    </div>

    <div class="card mt-4">
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Products</th>
                        <th>Created At</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($categories) === 0): ?>
                    <tr><td colspan="6" class="table-empty">No categories found.</td></tr>
                <?php else: ?>
                    <?php foreach ($categories as $c): ?>
                        <tr>
                            <td>#<?= (int)$c['id'] ?></td>
                            <td><strong><?= e($c['name']) ?></strong></td>
                            <td class="muted"><?= e($c['description'] ?: '-') ?></td>
                            <td><?= (int)$c['product_count'] ?></td>
                            <td><?= e(fmt_datetime($c['created_at'])) ?></td>
                            <td class="text-center">
                                <a href="/admin/category_form.php?id=<?= (int)$c['id'] ?>" class="btn-outline btn-sm">Edit</a>

                                <form action="/admin/categories.php" method="POST" class="inline-form"
                                      data-confirm="Delete the category &quot;<?= e($c['name']) ?>&quot;?">
                                    <?php csrf_field(); ?>
                                    <?php html_hidden('action', 'delete'); ?>
                                    <?php html_hidden('id', $c['id']); ?>
                                    <?php html_submit('Delete', ['class' => 'btn-outline btn-sm btn-danger']); ?>
                                </form>
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
