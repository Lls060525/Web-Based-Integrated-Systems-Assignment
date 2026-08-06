<?php
// ============================================================
// products.php - public catalogue with search, category filter,
// price filter, sorting and paging (Shopping Cart module)
// ============================================================

require_once __DIR__ . '/lib/init.php';
require_once __DIR__ . '/includes/product_card.php';

$title = 'Products - ' . APP_NAME;

// ---------- Read and validate the filter inputs ----------
$q          = get('q');
$categoryId = get_int('category');
$minPrice   = get('min_price');
$maxPrice   = get('max_price');
$sort       = get('sort', 'newest');
$page       = max(1, (int)get('page', '1'));
$perPage    = 12;

$sortOptions = [
    'newest'     => 'Newest First',
    'price_asc'  => 'Price: Low to High',
    'price_desc' => 'Price: High to Low',
    'name_asc'   => 'Name: A to Z',
];

if (!array_key_exists($sort, $sortOptions)) {
    $sort = 'newest';
}

$orderBy = match ($sort) {
    'price_asc'  => 'p.price ASC',
    'price_desc' => 'p.price DESC',
    'name_asc'   => 'p.name ASC',
    default      => 'p.id DESC',
};

// ---------- Build the query (always parameterised) ----------
$where  = ["p.status = 'active'"];
$params = [];

if ($q !== '') {
    $where[]  = '(p.name LIKE ? OR p.description LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

if ($categoryId !== null) {
    $where[]  = 'p.category_id = ?';
    $params[] = $categoryId;
}

if (is_numeric($minPrice)) {
    $where[]  = 'p.price >= ?';
    $params[] = (float)$minPrice;
}

if (is_numeric($maxPrice)) {
    $where[]  = 'p.price <= ?';
    $params[] = (float)$maxPrice;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$total      = (int)db_value("SELECT COUNT(*) FROM products p $whereSql", $params);
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$products = db_all(
    "SELECT p.*, c.name AS category_name
       FROM products p
       LEFT JOIN categories c ON c.id = p.category_id
       $whereSql
      ORDER BY $orderBy
      LIMIT $perPage OFFSET $offset",
    $params
);

$categories = db_all('SELECT id, name FROM categories ORDER BY name ASC');

/** Build a URL that keeps the current filters but changes one value. */
function catalogue_url(array $override = []): string
{
    $params = array_merge($_GET, $override);
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
    return '/products.php?' . http_build_query($params);
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h2 class="page-title">Latest Flagship Devices</h2>
    <p class="page-subtitle"><?= $total ?> product<?= $total === 1 ? '' : 's' ?> available</p>
</div>

<div class="catalogue-layout">

    <aside class="filter-sidebar">
        <form action="/products.php" method="GET" class="filter-form">
            <?php html_hidden('q', $q); ?>

            <div class="filter-block">
                <h4>Category</h4>
                <ul class="filter-list">
                    <li>
                        <a href="<?= e(catalogue_url(['category' => null, 'page' => null])) ?>"
                           class="<?= $categoryId === null ? 'active' : '' ?>">All Categories</a>
                    </li>
                    <?php foreach ($categories as $c): ?>
                        <li>
                            <a href="<?= e(catalogue_url(['category' => $c['id'], 'page' => null])) ?>"
                               class="<?= $categoryId === (int)$c['id'] ? 'active' : '' ?>"><?= e($c['name']) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="filter-block">
                <h4>Price Range (RM)</h4>
                <div class="price-range">
                    <input type="number" name="min_price" step="0.01" min="0"
                           value="<?= e($minPrice) ?>" placeholder="Min" class="form-control">
                    <span>&ndash;</span>
                    <input type="number" name="max_price" step="0.01" min="0"
                           value="<?= e($maxPrice) ?>" placeholder="Max" class="form-control">
                </div>
                <?php if ($categoryId !== null) { html_hidden('category', $categoryId); } ?>
                <?php html_hidden('sort', $sort); ?>
                <?php html_submit('Apply', ['class' => 'btn-outline btn-block']); ?>
            </div>

            <?php if ($q !== '' || $categoryId !== null || $minPrice !== '' || $maxPrice !== ''): ?>
                <a href="/products.php" class="btn-outline btn-block">Clear All Filters</a>
            <?php endif; ?>
        </form>
    </aside>

    <div class="catalogue-main">

        <form action="/products.php" method="GET" class="sort-bar">
            <?php html_hidden('q', $q); ?>
            <?php if ($categoryId !== null) { html_hidden('category', $categoryId); } ?>
            <?php html_hidden('min_price', $minPrice); ?>
            <?php html_hidden('max_price', $maxPrice); ?>

            <label for="sort">Sort by</label>
            <?php html_select('sort', $sortOptions, $sort, ['class' => 'form-control js-auto-submit']); ?>
            <noscript><?php html_submit('Go', ['class' => 'btn-outline btn-sm']); ?></noscript>
        </form>

        <?php render_product_grid($products, 'No products match your filters.'); ?>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= e(catalogue_url(['page' => $page - 1])) ?>" class="page-link">&larr; Previous</a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="<?= e(catalogue_url(['page' => $i])) ?>"
                       class="page-link<?= $i === $page ? ' active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= e(catalogue_url(['page' => $page + 1])) ?>" class="page-link">Next &rarr;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
