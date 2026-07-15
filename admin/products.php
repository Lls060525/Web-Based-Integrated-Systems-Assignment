<?php
// /admin/products.php
require_once __DIR__ . '/../admin/admin_auth.php'; // Enforce admin security

$title = 'Product Management - Admin';

// --- Handle Status Toggle ---
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $toggle_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $new_status = $_GET['toggle_status'] === 'active' ? 'active' : 'inactive';

    if ($toggle_id) {
        $stmt = $pdo->prepare("UPDATE products SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $toggle_id]);

        // PRG Pattern to clear URL parameters
        header('Location: /admin/products.php');
        exit;
    }
}

// --- Fetch Products ---
$search_query = trim($_GET['q'] ?? '');
$sql = "SELECT * FROM products";
$params = [];

if ($search_query !== '') {
    $sql .= " WHERE name LIKE ?";
    $params[] = "%{$search_query}%";
}
$sql .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    if (count($products) > 0) {
        foreach ($products as $p) {
            ?>
            <tr>
                <td>#<?php echo $p['id']; ?></td>
                <td>
                    <img src="/assets/images/<?php echo htmlspecialchars($p['image']); ?>" alt="product" style="width: 50px; height: 50px; object-fit: cover; border-radius: 4px;">
                </td>
                <td><strong><?php echo htmlspecialchars($p['name']); ?></strong></td>
                <td><?php echo number_format($p['price'], 2); ?></td>
                <td><?php echo $p['stock']; ?></td>
                <td>
                    <?php if ($p['status'] === 'active'): ?>
                        <span class="badge" style="background: #e6f4ea; color: #1e8e3e;">Active</span>
                    <?php else: ?>
                        <span class="badge" style="background: #fce8e6; color: #d93025;">Inactive</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="product_form.php?id=<?php echo $p['id']; ?>" class="btn-outline btn-sm">Edit</a>

                    <?php if ($p['status'] === 'active'): ?>
                        <a href="products.php?toggle_status=inactive&id=<?php echo $p['id']; ?>" class="btn-outline btn-sm" style="color: #d93025; border-color: #d93025;" onclick="return confirm('Deactivate this product?');">Deactivate</a>
                    <?php else: ?>
                        <a href="products.php?toggle_status=active&id=<?php echo $p['id']; ?>" class="btn-outline btn-sm" style="color: #1e8e3e; border-color: #1e8e3e;">Activate</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
        }
    } else {
        echo '<tr><td colspan="7" class="text-center" style="padding: 30px; color: var(--text-muted);">No products found.</td></tr>';
    }

    exit;
}

include __DIR__ . '/../includes/header.php';
?>

    <div class="admin-container">
        <div class="admin-header">
            <h2>Product Management</h2>

            <div style="display: flex; gap: 15px; align-items: center;">
                <form action="products.php" method="GET" class="admin-search-form">
                    <input type="text" name="q" placeholder="Search products..."
                           value="<?php echo htmlspecialchars($search_query); ?>" class="admin-search-input">
                    <?php if($search_query): ?>
                        <a href="products.php" class="btn-outline">Clear</a>
                    <?php endif; ?>
                    <button type="submit" class="btn-primary">Search</button>
                </form>
                <a href="product_form.php" class="btn-primary">+ Add New Product</a>
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
                        <th>Price (RM)</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (count($products) > 0): ?>
                        <?php foreach ($products as $p): ?>
                            <tr>
                                <td>#<?php echo $p['id']; ?></td>
                                <td>
                                    <img src="/assets/images/<?php echo htmlspecialchars($p['image']); ?>" alt="product" style="width: 50px; height: 50px; object-fit: cover; border-radius: 4px;">
                                </td>
                                <td><strong><?php echo htmlspecialchars($p['name']); ?></strong></td>
                                <td><?php echo number_format($p['price'], 2); ?></td>
                                <td><?php echo $p['stock']; ?></td>
                                <td>
                                    <?php if ($p['status'] === 'active'): ?>
                                        <span class="badge" style="background: #e6f4ea; color: #1e8e3e;">Active</span>
                                    <?php else: ?>
                                        <span class="badge" style="background: #fce8e6; color: #d93025;">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="product_form.php?id=<?php echo $p['id']; ?>" class="btn-outline btn-sm">Edit</a>

                                    <?php if ($p['status'] === 'active'): ?>
                                        <a href="products.php?toggle_status=inactive&id=<?php echo $p['id']; ?>" class="btn-outline btn-sm" style="color: #d93025; border-color: #d93025;" onclick="return confirm('Deactivate this product?');">Deactivate</a>
                                    <?php else: ?>
                                        <a href="products.php?toggle_status=active&id=<?php echo $p['id']; ?>" class="btn-outline btn-sm" style="color: #1e8e3e; border-color: #1e8e3e;">Activate</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center" style="padding: 30px; color: var(--text-muted);">No products found.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php include __DIR__ . '/../includes/footer.php'; ?>