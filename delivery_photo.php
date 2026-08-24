<?php
// ============================================================
// delivery_photo.php - the delivery photo, for the customer it belongs to
//
// The same file admin/evidence.php serves, but reached under completely
// different rules. That page asks "do you have orders.manage"; this one
// asks "is this your parcel".
//
// Two endpoints rather than one with a branch, because the two questions
// have nothing in common and mixing them is how an ownership check ends
// up accidentally skipped for the admin path -- or worse, the reverse.
// ============================================================

require_once __DIR__ . '/lib/init.php';

require_member();

$historyId = get_int('id');

if ($historyId === null || !evidence_column_ready()) {
    http_response_code(404);
    exit('Not found.');
}

// One query answers ownership AND fetches the filename.
//
// Joining orders and filtering on user_id is what makes this safe: a
// member guessing another id gets no row at all, which is the same
// answer as a row that does not exist. There is no way to tell the two
// apart from outside, so guessing tells an attacker nothing.
//
// to_status = 'delivered' is a deliberate second limit. The SHIPPED
// photo is a warehouse record -- it shows a bench, other people's
// parcels, sometimes a picking list. The DELIVERED photo is about this
// customer's own doorstep and is the one they have a reason to see.
$row = db_one(
    "SELECT h.evidence_photo
       FROM order_status_history h
       JOIN orders o ON o.id = h.order_id
      WHERE h.id = ?
        AND o.user_id = ?
        AND h.to_status = 'delivered'
        AND h.evidence_photo IS NOT NULL",
    [$historyId, current_user_id()]
);

if (!$row) {
    http_response_code(404);
    exit('Not found.');
}

$path = DIR_UPLOAD_EVIDENCE . basename($row['evidence_photo']);

if (!is_file($path)) {
    http_response_code(404);
    exit('That photograph is no longer on the server.');
}

// Sniffed, not trusted from the extension, so a file that somehow is not
// an image cannot be served as one.
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $path);
finfo_close($finfo);

if (!in_array($mime, UPLOAD_ALLOWED_MIMES, true)) {
    http_response_code(415);
    exit('Unsupported file.');
}

// private, not public: this is one household's doorstep. A shared proxy
// caching it and handing it to the next person asking would be exactly
// the wrong outcome.
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="delivery-' . (int)$historyId . '"');

readfile($path);
