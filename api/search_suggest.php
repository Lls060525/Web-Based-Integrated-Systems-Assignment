<?php
// ============================================================
// api/search_suggest.php - live search suggestions
//
// Read only, so it takes GET and carries no CSRF token. See the note
// on ajax_guard_read() in lib/ajax.php for why that is deliberate
// rather than an omission.
// ============================================================

require_once __DIR__ . '/../lib/init.php';

ajax_guard_read();

$term = trim(get('q'));

// Two characters is the floor. One character matches most of the
// catalogue, so the request costs a full scan and the answer is useless.
if (mb_strlen($term) < 2) {
    json_ok(['term' => $term, 'products' => [], 'categories' => [], 'total' => 0]);
}

$limit = ajax_limit('limit', 6, 10);

// LIKE '%term%' cannot use an index, so the term length floor above and
// this hard limit are what keep it cheap. A catalogue of a few hundred
// rows does not need full-text search, and adding one would be a bigger
// claim than this module can honestly support.
$like = '%' . $term . '%';

$products = db_all(
    "SELECT p.id, p.name, p.price, p.image, p.stock, c.name AS category_name
       FROM products p
       LEFT JOIN categories c ON c.id = p.category_id
      WHERE p.status = 'active' AND p.name LIKE ?
      ORDER BY
        -- A product that STARTS with what was typed is a better answer
        -- than one that merely contains it somewhere.
        CASE WHEN p.name LIKE ? THEN 0 ELSE 1 END,
        p.name ASC
      LIMIT " . (int)$limit,
    [$like, $term . '%']
);

$categories = db_all(
    'SELECT id, name FROM categories WHERE name LIKE ? ORDER BY name ASC LIMIT 3',
    [$like]
);

$total = (int)db_value(
    "SELECT COUNT(*) FROM products WHERE status = 'active' AND name LIKE ?", [$like]
);

$items = [];

foreach ($products as $product) {
    $items[] = [
        'id'       => (int)$product['id'],
        'name'     => $product['name'],
        'price'    => money($product['price']),
        'image'    => product_image($product['image']),
        'category' => $product['category_name'] ?? '',
        'in_stock' => (int)$product['stock'] > 0,
        'url'      => '/product_detail.php?id=' . (int)$product['id'],
    ];
}

$categoryItems = [];

foreach ($categories as $category) {
    $categoryItems[] = [
        'name' => $category['name'],
        'url'  => '/products.php?category=' . (int)$category['id'],
    ];
}

json_ok([
    'term'       => $term,
    'products'   => $items,
    'categories' => $categoryItems,
    'total'      => $total,
    'more_url'   => '/products.php?q=' . rawurlencode($term),
]);
