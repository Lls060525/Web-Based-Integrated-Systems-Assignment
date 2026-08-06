<?php
// ============================================================
// admin/admin_auth.php
// Gateway for every admin page. Keep this as the FIRST line of
// each file in /admin:
//
//     require_once __DIR__ . '/admin_auth.php';
//
// It boots the library and blocks anyone who is not an admin.
// ============================================================

require_once __DIR__ . '/../lib/init.php';

require_admin();
