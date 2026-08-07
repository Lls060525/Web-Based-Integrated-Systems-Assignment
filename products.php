<?php
// ============================================================
// products.php - public catalogue with search, category filter,
// price filter, sorting and paging (Shopping Cart module)
// ============================================================

require_once __DIR__ . '/lib/init.php';
require_once __DIR__ . '/includes/product_card.php';
require_once __DIR__ . '/includes/review_parts.php';

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

// Rating sort and filter only appear once the module is installed.
if (review_module_ready()) {
    $sortOptions['rating'] = 'Highest Rated';
}

$minRating = get_int('min_rating');

if (!array_key_exists($sort, $sortOptions)) {
    $sort = 'newest';
}

$orderBy = match ($sort) {
    'price_asc'  => 'p.price ASC',
    'price_desc' => 'p.price DESC',
    'name_asc'   => 'p.name ASC',
    // Products with no reviews sort last rather than counting as zero.
    'rating'     => 'COALESCE(rv.avg_rating, 0) DESC, rv.review_count DESC, p.id DESC',
    default      => 'p.id DESC',
};

// One derived table gives every product its aggregate without an
// N+1 query and without a cached column that could drift.
$ratingJoin = review_module_ready()
    ? "LEFT JOIN (
            SELECT product_id,
                   AVG(rating) AS avg_rating,
                   COUNT(*)    AS review_count
              FROM reviews
             WHERE status = 'published'
             GROUP BY product_id
       ) rv ON rv.product_id = p.id"
    : '';

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

if (review_module_ready() && $minRating !== null && $minRating >= 1 && $minRating <= 5) {
    $where[]  = 'rv.avg_rating >= ?';
    $params[] = $minRating;
}

// ---------- Specification filters ----------
// Built from whatever spec_* keys are in the query string. Each becomes
// its own EXISTS subquery, so two filters on two different attributes
// do not multiply rows the way two JOINs onto product_specs would.
$specFilter = spec_filter_sql($_GET);
$params     = array_merge($params, $specFilter['params']);

$whereSql = 'WHERE ' . implode(' AND ', $where) . $specFilter['sql'];

$total      = (int)db_value("SELECT COUNT(*) FROM products p $ratingJoin $whereSql", $params);
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$products = db_all(
    "SELECT p.*, c.name AS category_name
       FROM products p
       LEFT JOIN categories c ON c.id = p.category_id
       $ratingJoin
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

            <?php if (review_module_ready()): ?>
                <div class="filter-block">
                    <h4>Customer Rating</h4>
                    <ul class="filter-list">
                        <li>
                            <a href="<?= e(catalogue_url(['min_rating' => null, 'page' => null])) ?>"
                               class="<?= $minRating === null ? 'active' : '' ?>">Any rating</a>
                        </li>
                        <?php for ($stars = 4; $stars >= 1; $stars--): ?>
                            <li>
                                <a href="<?= e(catalogue_url(['min_rating' => $stars, 'page' => null])) ?>"
                                   class="rating-filter <?= $minRating === $stars ? 'active' : '' ?>">
                                    <?php render_stars((float)$stars, 'stars-sm'); ?>
                                    <span>&amp; up</span>
                                </a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php
                /* Spec filters are only offered inside a category.
                 *
                 * Attributes belong to categories, so across the whole
                 * catalogue a "RAM" filter would sit above a list that is
                 * mostly cables and cases. Narrowing first is also how
                 * people actually shop.
                 */
                $specFilters = $categoryId !== null ? spec_filter_options($categoryId) : [];
            ?>

            <?php foreach ($specFilters as $attribute): ?>
                <div class="filter-block">
                    <h4>
                        <?= e($attribute['name']) ?>
                        <?php if (!empty($attribute['unit'])): ?>
                            <span class="muted">(<?= e($attribute['unit']) ?>)</span>
                        <?php endif; ?>
                    </h4>

                    <?php if ($attribute['data_type'] === 'number'): ?>
                        <?php
                            $minKey = 'spec_' . $attribute['id'] . '_min';
                            $maxKey = 'spec_' . $attribute['id'] . '_max';
                        ?>
                        <div class="price-range">
                            <input type="number" name="<?= e($minKey) ?>" step="any"
                                   min="<?= e($attribute['range']['min']) ?>"
                                   max="<?= e($attribute['range']['max']) ?>"
                                   value="<?= e(get($minKey)) ?>"
                                   placeholder="<?= e(rtrim(rtrim(number_format($attribute['range']['min'], 2, '.', ''), '0'), '.')) ?>"
                                   class="form-control">
                            <span>&ndash;</span>
                            <input type="number" name="<?= e($maxKey) ?>" step="any"
                                   min="<?= e($attribute['range']['min']) ?>"
                                   max="<?= e($attribute['range']['max']) ?>"
                                   value="<?= e(get($maxKey)) ?>"
                                   placeholder="<?= e(rtrim(rtrim(number_format($attribute['range']['max'], 2, '.', ''), '0'), '.')) ?>"
                                   class="form-control">
                        </div>

                    <?php else: ?>
                        <?php $key = 'spec_' . $attribute['id'] . '_eq'; ?>
                        <ul class="filter-list">
                            <li>
                                <a href="<?= e(catalogue_url([$key => null, 'page' => null])) ?>"
                                   class="<?= get($key) === '' ? 'active' : '' ?>">Any</a>
                            </li>
                            <?php foreach ($attribute['values'] as $value): ?>
                                <li>
                                    <a href="<?= e(catalogue_url([$key => $value['value_text'], 'page' => null])) ?>"
                                       class="<?= get($key) === $value['value_text'] ? 'active' : '' ?>">
                                        <?= e($value['value_text']) ?>
                                        <span class="filter-count"><?= (int)$value['product_count'] ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

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

                <?php /* Enum spec filters are links, so their value has to
                          survive the price form being submitted too. */ ?>
                <?php foreach ($specFilter['active'] as $specKey => $specValue): ?>
                    <?php if (str_ends_with($specKey, '_eq')) { html_hidden($specKey, $specValue); } ?>
                <?php endforeach; ?>

                <?php html_submit('Apply', ['class' => 'btn-outline btn-block']); ?>
            </div>

            <?php if ($q !== '' || $categoryId !== null || $minPrice !== '' || $maxPrice !== ''
                      || $minRating !== null || $specFilter['active'] !== []): ?>
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
            <?php if ($minRating !== null) { html_hidden('min_rating', $minRating); } ?>

            <?php foreach ($specFilter['active'] as $specKey => $specValue): ?>
                <?php html_hidden($specKey, $specValue); ?>
            <?php endforeach; ?>

            <label for="sort">Sort by</label>
            <?php html_select('sort', $sortOptions, $sort, ['class' => 'form-control js-auto-submit']); ?>
            <noscript><?php html_submit('Go', ['class' => 'btn-outline btn-sm']); ?></noscript>
        </form>

        <?php render_product_grid($products, 'No products match your filters.', 'productGrid'); ?>

        <?php if ($totalPages > 1): ?>
            <?php
                /* The numbered links stay. Load More is added ON TOP of
                 * them, not instead: with JavaScript off, or if the
                 * endpoint fails, paging still works exactly as before.
                 * The button removes itself from the flow only once it
                 * has successfully fetched something.
                 */
                $loadQuery = $_GET;
                unset($loadQuery['page']);
            ?>

            <?php if ($page < $totalPages): ?>
                <div class="load-more-wrap">
                    <p class="muted small-note">
                        Showing <span id="resultCount"><?= count($products) ?> of <?= $total ?></span>
                    </p>

                    <?php html_button('Load More', [
                        'id'         => 'loadMore',
                        'class'      => 'btn-outline',
                        'data-next'  => $page + 1,
                        'data-query' => http_build_query($loadQuery),
                    ]); ?>
                </div>
            <?php endif; ?>

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
