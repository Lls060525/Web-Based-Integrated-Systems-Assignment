<?php
// /admin/product_form.php
require_once __DIR__ . '/../admin/admin_auth.php';

$title = 'Manage Product - Admin';
$product_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$is_edit = $product_id !== false && $product_id !== null;

$errors = [];
$success = "";

// Initialize empty data
$product = [
    'name' => '', 'category_id' => '', 'description' => '', 'price' => '', 'stock' => '0', 'image' => 'default-product.png'
];

// Fetch all categories for the dropdown
$cat_stmt = $pdo->query("SELECT * FROM categories ORDER BY name ASC");
$categories = $cat_stmt->fetchAll();

// Fetch existing data if editing
if ($is_edit) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $fetched = $stmt->fetch();
    if ($fetched) {
        $product = $fetched;
    } else {
        die("Product not found.");
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
    $description = trim($_POST['description'] ?? '');
    $price = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT);
    $stock = filter_input(INPUT_POST, 'stock', FILTER_VALIDATE_INT);

    // Validations
    if (empty($name)) $errors[] = "Product name is required.";
    if (!$category_id) $errors[] = "Please select a category.";
    if ($price === false || $price <= 0) $errors[] = "Valid price is required.";
    if ($stock === false || $stock < 0) $errors[] = "Valid stock quantity is required.";

    // File Upload Logic
    $image_filename = $product['image'];
    if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['product_image']['name'], PATHINFO_EXTENSION);
        $image_filename = 'prod_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        move_uploaded_file($_FILES['product_image']['tmp_name'], __DIR__ . '/../assets/images/' . $image_filename);
    }

    if (empty($errors)) {
        if ($is_edit) {
            $stmt = $pdo->prepare("UPDATE products SET name = ?, category_id = ?, description = ?, price = ?, stock = ?, image = ? WHERE id = ?");
            $stmt->execute([$name, $category_id, $description, $price, $stock, $image_filename, $product_id]);
            $success = "Product updated successfully!";

            // Update local array to reflect changes instantly
            $product['name'] = $name; $product['category_id'] = $category_id; $product['description'] = $description;
            $product['price'] = $price; $product['stock'] = $stock; $product['image'] = $image_filename;
        } else {
            $stmt = $pdo->prepare("INSERT INTO products (name, category_id, description, price, stock, image) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $category_id, $description, $price, $stock, $image_filename]);
            $new_id = $pdo->lastInsertId();
            header("Location: product_form.php?id=$new_id&success=1");
            exit;
        }
    }
}

if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success = "New product added successfully!";
}

include __DIR__ . '/../includes/header.php';
?>

    <div class="admin-container" style="max-width: 800px;">
        <div class="admin-header">
            <h2><?php echo $is_edit ? 'Edit Product' : 'Add New Product'; ?></h2>
            <a href="products.php" class="btn-outline">&larr; Back to List</a>
        </div>

        <div class="card mt-4" style="padding: 30px;">
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <ul style="margin: 0; padding-left: 20px;">
                        <?php foreach ($errors as $err) echo "<li>" . htmlspecialchars($err) . "</li>"; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form action="" method="POST" enctype="multipart/form-data" class="form-standard">

                <div style="display: flex; gap: 20px;">
                    <div class="form-group" style="flex: 2;">
                        <label>Product Name</label>
                        <input type="text" name="name" value="<?php echo htmlspecialchars($product['name']); ?>" required>
                    </div>

                    <div class="form-group" style="flex: 1;">
                        <label>Category</label>
                        <select name="category_id" required style="width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 4px; font-size: 14px; outline: none; background: #fff;">
                            <option value="">-- Select Category --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo ($product['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="4" style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 4px;" required><?php echo htmlspecialchars($product['description']); ?></textarea>
                </div>

                <div style="display: flex; gap: 20px;">
                    <div class="form-group" style="flex: 1;">
                        <label>Price (RM)</label>
                        <input type="number" step="0.01" name="price" value="<?php echo htmlspecialchars($product['price']); ?>" required>
                    </div>

                    <div class="form-group" style="flex: 1;">
                        <label>Stock Quantity</label>
                        <input type="number" name="stock" value="<?php echo htmlspecialchars($product['stock']); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Product Image</label>
                    <?php if ($is_edit): ?>
                        <div style="margin-bottom: 10px;">
                            <img src="/assets/images/<?php echo htmlspecialchars($product['image']); ?>" style="width: 100px; border-radius: 4px; border: 1px solid var(--border);">
                        </div>
                    <?php endif; ?>
                    <input type="file" name="product_image" accept="image/*">
                    <p style="font-size: 12px; color: var(--text-muted); margin-top: 5px;">Leave blank to keep current image.</p>
                </div>

                <div class="form-actions text-right">
                    <button type="submit" class="btn-primary"><?php echo $is_edit ? 'Save Changes' : 'Publish Product'; ?></button>
                </div>
            </form>
        </div>
    </div>

<?php include __DIR__ . '/../includes/footer.php'; ?>