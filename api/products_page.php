<?php
// ============================================================
// api/products_page.php - one more page of catalogue results
//
// Returns rendered HTML, not JSON.
//
// The product card is a real piece of markup: image, badges, rating
// stars, stock state, wishlist heart, "Choose Options" vs "Add to
// Cart". Returning JSON would mean writing that card a second time in
// JavaScript, and then keeping the two versions in step forever. The
// server already has render_product_card(); this endpoint calls it.
//
// The trade-off is a slightly larger response. That is worth one
// template instead of two.
// ============================================================

require_once __DIR__ . '/../lib/init.php';
require_once __DIR__ . '/../includes/product_card.php';
require_once __DIR__ . '/../includes/review_parts.php';

ajax_guard_read();

// ---------- The same filters the page itself applies ----------
$q          = get('q');
$categoryId = get_int('category');
$minPrice   = get('min_price');
$maxPrice   = get('max_price');
$minRating  = get_int('min_rating');
$sort       = get('sort', 'newest');
$page       = max(1, (int)get('page', '1'));
$perPage    = 12;

$orderBy = match ($sort) {
    'price_asc'  => 'p.price ASC',
    'price_desc' => 'p.price DESC',
    'name_asc'   => 'p.name ASC',
    'rating'     => 'COALESCE(rv.avg_rating, 0) DESC, rv.review_count DESC, p.id DESC',
    default      => 'p.id DESC',
};

$ratingJoin = review_module_ready()
    ? "LEFT JOIN (
            SELECT product_id, AVG(rating) AS avg_rating, COUNT(*) AS review_count
              FROM reviews WHERE status = 'published' GROUP BY product_id
       ) rv ON rv.product_id = p.id"
    : '';

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

if (is_numeric($minPrice)) { $where[] = 'p.price >= ?'; $params[] = (float)$minPrice; }
if (is_numeric($maxPrice)) { $where[] = 'p.price <= ?'; $params[] = (float)$maxPrice; }

if (review_module_ready() && $minRating !== null && $minRating >= 1 && $minRating <= 5) {
    $where[]  = 'rv.avg_rating >= ?';
    $params[] = $minRating;
}

$specFilter = spec_filter_sql($_GET);
$params     = array_merge($params, $specFilter['params']);
$whereSql   = 'WHERE ' . implode(' AND ', $where) . $specFilter['sql'];

$total  = (int)db_value("SELECT COUNT(*) FROM products p $ratingJoin $whereSql", $params);
$offset = ($page - 1) * $perPage;

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

// Captured rather than echoed, so the HTML travels inside the JSON
// alongside the flag the button needs.
ob_start();

foreach ($products as $product) {
    render_product_card($product);
}

$html = (string)ob_get_clean();

json_ok([
    'html'      => $html,
    'count'     => count($products),
    'page'      => $page,
    'total'     => $total,
    'has_more'  => ($offset + count($products)) < $total,
    'shown'     => $offset + count($products),
]);
