<?php
// ============================================================
// router.php - front controller for PHP's built-in dev server
//
// PHP's built-in server does NOT read .htaccess. It has no concept of
// it. So every protection in the .htaccess files -- blocking .git,
// database/, composer.json, directory listings -- silently does
// nothing when the site is started with:
//
//     php -S localhost:8001
//
// This script restores those rules at the PHP level. Start the server
// with it as the router:
//
//     php -S localhost:8001 router.php
//
// On real Apache this file is never used; the .htaccess files do the
// job instead. Keeping both means the project is protected in whichever
// way it happens to be served.
// ============================================================

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = rawurldecode($path);

// Normalise separators so a Windows-style path cannot slip past the
// checks below.
$normalised = str_replace('\\', '/', $path);

/**
 * Refuse and stop.
 */
function router_deny(string $reason): void
{
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');

    echo '<!doctype html><meta charset="utf-8">'
       . '<title>403 Forbidden</title>'
       . '<h1>403 Forbidden</h1>'
       . '<p>This path is not served.</p>'
       . '<!-- ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . ' -->';

    exit;
}

// ---------- 1. Dot files and dot directories ----------
// .git is the important one. /.git/config leaks the remote URL, and the
// object store lets anyone reconstruct the entire source, including
// lib/config.php with its API keys.
foreach (explode('/', trim($normalised, '/')) as $segment) {
    if ($segment !== '' && $segment[0] === '.') {
        router_deny('dot path');
    }
}

// ---------- 2. Directories that are server-side only ----------
// These mirror the per-directory .htaccess files.
$blockedDirs = ['lib', 'includes', 'config', 'storage', 'vendor', 'database'];

$firstSegment = strtok(trim($normalised, '/'), '/');

if ($firstSegment !== false && in_array(strtolower($firstSegment), $blockedDirs, true)) {
    router_deny('server-side directory');
}

// ---------- 3. File types that are never meant to be fetched ----------
// .sql   the migrations, and the database export that contains every
//        user row including password hashes
// .md    the design notes
// .log   storage/mail.log records every message the site has sent
// .lock  composer.lock is a list of exact dependency versions
$extension = strtolower(pathinfo($normalised, PATHINFO_EXTENSION));

$blockedExtensions = ['sql', 'md', 'log', 'lock', 'json', 'ini', 'bak',
                      'old', 'orig', 'dist', 'sh', 'yml', 'yaml', 'env'];

if (in_array($extension, $blockedExtensions, true)) {
    router_deny('blocked extension');
}

// ---------- 4. Uploads are data, never code ----------
// A PHP file that somehow reached the uploads folder must be served as
// text rather than executed. save_uploaded_image() makes this very
// unlikely, but defence in depth costs nothing here.
if (str_starts_with(ltrim($normalised, '/'), 'assets/uploads/') && $extension === 'php') {
    router_deny('script in uploads');
}

// ---------- 5. No directory listings ----------
$file = __DIR__ . $normalised;

if (is_dir($file)) {
    $hasIndex = is_file(rtrim($file, '/') . '/index.php')
             || is_file(rtrim($file, '/') . '/index.html');

    if (!$hasIndex) {
        router_deny('directory listing');
    }
}

// ---------- Otherwise, behave normally ----------
// Returning false tells the built-in server to serve the requested file
// itself: static assets as-is, .php files through the interpreter.
return false;
