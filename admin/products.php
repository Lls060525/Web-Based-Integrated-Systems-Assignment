<?php
// ============================================================
// admin/products.php - Product Listing + Basic Searching (Admin)
// ============================================================

require_once __DIR__ . '/admin_auth.php';

require_permission('products.manage');
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

// Table or photo grid. Resolved BEFORE any output, because choosing a
// layout writes the remembering cookie and a cookie cannot be set once
// the headers have gone.
$view = listing_view('products');

// The FROM/WHERE half is built once and used twice: once to count the
// matching rows so the pager knows how many pages there are, and once to
// fetch the current page. Sharing it means the two can never drift apart
// and report a page count that does not match what is listed.
$from = " FROM products p
          LEFT JOIN categories c ON c.id = p.category_id";
$params = [];

if ($q !== '') {
    $from    .= ' WHERE (p.name LIKE ? OR c.name LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$pager = paginate((int)db_value('SELECT COUNT(*)' . $from, $params), 20);

$products = db_all(
    'SELECT p.*, c.name AS category_name' . $from
        . ' ORDER BY p.id DESC' . pager_limit($pager),
    $params
);

if (is_ajax()) {
    // Same renderer the full page uses, so a live search returns cards
    // when the page is showing cards.
    ajax_rows_with_pager(fn() => admin_product_listing($products, $view), $pager);
}

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Product Management</h2>

        <div class="admin-header-actions">
            <form action="/admin/products.php" method="GET" class="admin-search-form" data-target="#productListing">
                <input type="text" name="q" value="<?= e($q) ?>"
                       placeholder="Search products..." class="admin-search-input">

                <?php /* Carried so a search keeps the current layout: the live
                         search serialises this form, and whatever is not in it
                         is not in the request the pager links are built from. */ ?>
                <?php html_hidden('view', $view); ?>

                <?php html_submit('Search'); ?>
                <?php if ($q !== ''): ?>
                    <a href="/admin/products.php?view=<?= e($view) ?>" class="btn-outline">Clear</a>
                <?php endif; ?>
            </form>

            <?php listing_view_toggle('products', $view); ?>

            <a href="/admin/product_form.php" class="btn-primary">+ Add New Product</a>
        </div>
    </div>

    <div class="card mt-4">
        <?php if ($view === 'grid'): ?>
            <?php /* Same id as the tbody below on purpose. The live search
                     targets one selector and the server decides what shape
                     the contents are, so neither side has to know which
                     layout is on. */ ?>
            <div id="productListing" class="record-grid">
                <?php admin_product_listing($products, $view); ?>
            </div>
        <?php else: ?>
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
                    <tbody id="productListing">
                        <?php admin_product_listing($products, $view); ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php render_pager($pager); ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
