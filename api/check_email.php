<?php
// ============================================================
// api/check_email.php - is this address already registered?
//
// Answers while somebody is still typing, instead of after they have
// filled in the whole form and pressed Sign Up.
//
// This DOES leak whether an address has an account, which is normally
// something to avoid. It is unavoidable here: the registration form
// already tells you, because it refuses to create a duplicate. The
// endpoint is therefore no more revealing than the form it belongs to,
// and it is rate limited so it cannot be walked through a word list
// faster than the form could be.
//
// The login and password-reset pages deliberately do NOT use it.
// ============================================================

require_once __DIR__ . '/../lib/init.php';

ajax_guard_read();

$email = trim(get('email'));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_ok(['known' => false, 'checked' => false]);
}

// A cheap per-session throttle. Enumerating addresses through this is
// no faster than through the form itself.
$_SESSION['email_checks'] = ($_SESSION['email_checks'] ?? 0) + 1;

if ($_SESSION['email_checks'] > 60) {
    json_ok(['known' => false, 'checked' => false]);
}

$exists = db_one('SELECT id FROM users WHERE email = ?', [$email]);

json_ok([
    'checked' => true,
    'known'   => (bool)$exists,
    'message' => $exists
        ? 'That address already has an account.'
        : 'That address is available.',
]);
