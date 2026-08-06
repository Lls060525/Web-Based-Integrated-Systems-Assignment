<?php
// ============================================================
// auth/logout.php - Logout (Security module)
// ============================================================

require_once __DIR__ . '/../lib/init.php';

logout_user();

// Start a fresh session purely so the goodbye message can be shown.
session_start();
flash_success('You have been logged out.');

redirect('/auth/login.php');
