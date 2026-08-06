<?php
// ============================================================
// admin/products.php - Product Listing + Basic Searching (Admin)
// ============================================================

require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/admin_rows.php';

$title = 'Product Management - Admin';

// ---------- Activate / deactivate (POST + CSRF, never a bare GET link) ----------
if (is_post()) {
    csrf_check();

    if (post('action') === 'toggle_status') {
        $id     = post_int('id');
        $status = post('status');

        if ($id === null || !in_array($status, ['active', 'inactive'], true)) {
            flash_error('Invalid request.');
        } else {
            db_exec('UPDATE products SET status = ? WHERE id = ?', [$status, $id]);
            flash_success('Product has been ' . ($status === 'active' ? 'activated' : 'deactivated') . '.');
        }
    }

    redirect('/admin/products.php');
}

// ---------- Listing ----------
$q = get('q');

$sql = "SELECT p.*, c.name AS category_name
          FROM products p
          LEFT JOIN categories c ON c.id = p.category_id";
$params = [];

if ($q !== '') {
    $sql     .= ' WHERE (p.name LIKE ? OR c.name LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$sql .= ' ORDER BY p.id DESC';

$products = db_all($sql, $params);

if (is_ajax()) {
    admin_product_rows($products);
    exit;
}

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Product Management</h2>

        <div class="admin-header-actions">
            <form action="/admin/products.php" method="GET" class="admin-search-form" data-target="#productTableBody">
                <input type="text" name="q" value="<?= e($q) ?>"
                       placeholder="Search products..." class="admin-search-input">
                <?php html_submit('Search'); ?>
                <?php if ($q !== ''): ?>
                    <a href="/admin/products.php" class="btn-outline">Clear</a>
                <?php endif; ?>
            </form>

            <a href="/admin/product_form.php" class="btn-primary">+ Add New Product</a>
        </div>
    </div>

    <div class="card mt-4">
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Image</th>
                        <th>Name</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="productTableBody">
                    <?php admin_product_rows($products); ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
