<?php
// ============================================================
// config/database.php  (compatibility shim)
//
// The database connection now lives in lib/db.php and all
// configuration lives in lib/config.php.
// This file only remains so that any older include keeps working.
// New code should require lib/init.php instead.
// ============================================================

require_once __DIR__ . '/../lib/init.php';

$pdo = db();
