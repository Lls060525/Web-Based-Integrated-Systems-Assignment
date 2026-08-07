<?php
// ============================================================
// auth/logout.php - Logout (Security module)
// ============================================================

require_once __DIR__ . '/../lib/init.php';

// Signing out should forget THIS device only. Other devices the member
// deliberately chose to remember stay signed in.
remember_forget_current();

logout_user();

// Start a fresh session purely so the goodbye message can be shown.
session_start();
flash_success('You have been logged out.');

redirect('/auth/login.php');
