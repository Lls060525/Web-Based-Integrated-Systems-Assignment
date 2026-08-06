<?php
// ============================================================
// index.php - entry point, routes the visitor to the right area
// ============================================================

require_once __DIR__ . '/lib/init.php';

if (!is_logged_in()) {
    redirect('/products.php');   // guests can still browse the catalogue
}

redirect(home_url_for_role(current_role()));
