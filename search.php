<?php
// ============================================================
// search.php - target of the header search box.
// Keeps the URL the user typed and hands the work to the
// catalogue page, which already does searching and filtering.
// ============================================================

require_once __DIR__ . '/lib/init.php';

redirect('/products.php?' . http_build_query(['q' => get('q')]));
