<?php
// ============================================================
// admin/evidence.php - serve a delivery evidence photo
//
// These files are NOT in a public folder. assets/uploads/evidence/
// carries a .htaccess that denies everything, because a delivery photo
// shows a customer's parcel and usually their doorstep -- it is closer
// to personal data than to a product image, and a guessable URL would
// hand it to anyone who tried.
//
// So the file is read from disk and streamed here, after checking that
// the person asking is allowed to see the order it belongs to.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

require_permission('orders.manage');

$historyId = get_int('id');

if ($historyId === null || !evidence_column_ready()) {
    http_response_code(404);
    exit('Not found.');
}

// The filename comes from the DATABASE, never from the request.
//
// That is the whole defence against path traversal here: there is no
// user-supplied path to sanitise, because the only thing the request
// controls is which history row to look at.
$row = db_one(
    'SELECT evidence_photo FROM order_status_history WHERE id = ?',
    [$historyId]
);

if (!$row || empty($row['evidence_photo'])) {
    http_response_code(404);
    exit('Not found.');
}

$path = DIR_UPLOAD_EVIDENCE . basename($row['evidence_photo']);

if (!is_file($path)) {
    http_response_code(404);
    exit('The photograph is no longer on the server.');
}

// Sniffed rather than derived from the extension, so a file that somehow
// is not an image cannot be served as one.
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $path);
finfo_close($finfo);

if (!in_array($mime, UPLOAD_ALLOWED_MIMES, true)) {
    http_response_code(415);
    exit('Unsupported file.');
}

// private: this is one customer's parcel, so it must not be cached by a
// proxy that might then hand it to somebody else.
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="evidence-' . (int)$historyId . '"');

readfile($path);
