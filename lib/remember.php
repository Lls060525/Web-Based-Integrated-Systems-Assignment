<?php
// ============================================================
// lib/remember.php
// "Remember me" using the selector + validator scheme.
//
// The cookie holds "selector:validator".
//
//   selector  stored in plain text with a UNIQUE index. Its only job
//             is to find the row.
//   validator stored only as a SHA-256 hash and compared with
//             hash_equals().
//
// Why split them:
//
//   Storing one token in plain text so it can be looked up means a
//   database leak hands out working cookies immediately. Storing a
//   hash instead makes it unindexable, forcing a full table scan and
//   introducing a timing signal. Splitting gives both properties: a
//   fast indexed lookup on the selector, and a value that is useless
//   to anyone who reads the table.
//
// The validator ROTATES on every use, so a cookie works exactly once.
// The SELECTOR deliberately stays the same for the life of the device.
//
// That asymmetry is the whole point. If rotation replaced the selector
// too, a stolen cookie that the real owner had already superseded would
// simply fail to match any row and look identical to an expired login.
// Keeping the selector fixed means the row is still there to be found,
// so a wrong validator against a known selector is unambiguous evidence
// that two different browsers are holding copies of the same cookie.
//
// That is treated as theft: every token for the account is destroyed
// and the person has to sign in again. Whichever browser presents the
// stale copy triggers it, so it is caught whether the attacker or the
// victim visits second.
// ============================================================

/** True once the remember_tokens table exists (see the migration). */
function remember_ready(): bool
{
    return db_table_exists('remember_tokens');
}

// ------------------------------------------------------------
// Cookie handling
// ------------------------------------------------------------

/** Write the cookie with the strictest flags the connection allows. */
function remember_set_cookie(string $value, int $expires): void
{
    setcookie(REMEMBER_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'httponly' => true,   // JavaScript cannot read it, so XSS cannot steal it
        'samesite' => 'Lax',  // not sent on cross-site POSTs
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}

/** Remove the cookie from the browser. */
function remember_clear_cookie(): void
{
    remember_set_cookie('', time() - 3600);
    unset($_COOKIE[REMEMBER_COOKIE]);
}

/**
 * Split the cookie into its two halves.
 *
 * @return array{0: string, 1: string}|null
 */
function remember_parse_cookie(): ?array
{
    $raw = $_COOKIE[REMEMBER_COOKIE] ?? '';

    if ($raw === '' || !str_contains($raw, ':')) {
        return null;
    }

    [$selector, $validator] = explode(':', $raw, 2);

    // Shape check before the value ever reaches a query.
    if (!preg_match('~^[a-f0-9]{24}$~', $selector) || !preg_match('~^[a-f0-9]{64}$~', $validator)) {
        return null;
    }

    return [$selector, $validator];
}

// ------------------------------------------------------------
// Issuing
// ------------------------------------------------------------

/**
 * Register a NEW device: fresh selector, fresh validator, cookie sent.
 * Called only when someone ticks the box at sign-in. Returning visits
 * go through remember_rotate() instead, which keeps the selector.
 */
function remember_issue(int $userId): void
{
    if (!remember_ready()) {
        return;
    }

    $selector  = bin2hex(random_bytes(12));   // 24 hex characters
    $validator = bin2hex(random_bytes(32));   // 64 hex characters
    $expires   = time() + (REMEMBER_DAYS * 86400);

    db_exec(
        'INSERT INTO remember_tokens
                (user_id, selector, validator_hash, expires_at, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?)',
        [
            $userId,
            $selector,
            hash('sha256', $validator),
            date('Y-m-d H:i:s', $expires),
            client_ip(),
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]
    );


    remember_prune_user($userId);
    remember_set_cookie($selector . ':' . $validator, $expires);
}

/** Keep only the newest REMEMBER_MAX_DEVICES tokens for a user. */
function remember_prune_user(int $userId): void
{
    $ids = db_all(
        'SELECT id FROM remember_tokens
          WHERE user_id = ?
          ORDER BY created_at DESC, id DESC',
        [$userId]
    );

    if (count($ids) <= REMEMBER_MAX_DEVICES) {
        return;
    }

    $stale = array_slice(array_column($ids, 'id'), REMEMBER_MAX_DEVICES);
    $marks = implode(',', array_fill(0, count($stale), '?'));

    db_exec("DELETE FROM remember_tokens WHERE id IN ($marks)", $stale);
}

// ------------------------------------------------------------
// Automatic login
// ------------------------------------------------------------

/**
 * Try to sign in from the cookie. Called once per request by
 * lib/init.php, and only when the cookie is actually present.
 */
function remember_attempt_login(): void
{
    if (is_logged_in() || !remember_ready()) {
        return;
    }

    $parts = remember_parse_cookie();

    if ($parts === null) {
        return;
    }

    [$selector, $validator] = $parts;

    $token = db_one('SELECT * FROM remember_tokens WHERE selector = ?', [$selector]);

    if (!$token) {
        // Unknown selector: an old or invented cookie. Just drop it.
        remember_clear_cookie();
        return;
    }

    if (strtotime($token['expires_at']) < time()) {
        db_exec('DELETE FROM remember_tokens WHERE id = ?', [$token['id']]);
        remember_clear_cookie();
        return;
    }

    // Constant-time comparison, so no timing signal leaks.
    if (!hash_equals($token['validator_hash'], hash('sha256', $validator))) {
        // THEFT SIGNAL. The selector exists but the validator is wrong,
        // which means either a forged cookie or a stolen one being
        // replayed after the real owner already rotated it. Destroy
        // every token this account has and make them sign in again.
        db_exec('DELETE FROM remember_tokens WHERE user_id = ?', [$token['user_id']]);
        remember_clear_cookie();

        error_log('Remember-me validator mismatch for user ' . $token['user_id']
                . ' from ' . client_ip() . ' - all tokens revoked.');
        return;
    }

    $user = db_one(
        'SELECT id, name, role, status FROM users WHERE id = ?',
        [$token['user_id']]
    );

    // A banned or deleted account must not come back through a cookie.
    if (!$user || $user['status'] !== 'active') {
        db_exec('DELETE FROM remember_tokens WHERE user_id = ?', [$token['user_id']]);
        remember_clear_cookie();
        return;
    }

    // ---------- Rotate ----------
    // Same row, same selector, brand-new validator. The cookie just used
    // is now worthless, so a copy of it stops working immediately.
    remember_rotate($token);

    login_user($user);

    // Mark how this session was established. A session restored from a
    // cookie is weaker evidence of identity than a typed password.
    $_SESSION['auth_via'] = 'remember';
}

/**
 * Replace the validator on an existing device row and refresh its cookie.
 *
 * The expiry is pushed forward at the same time, so a device in regular
 * use never gets logged out, while one left untouched for REMEMBER_DAYS
 * lapses on its own.
 */
function remember_rotate(array $token): void
{
    $validator = bin2hex(random_bytes(32));
    $expires   = time() + (REMEMBER_DAYS * 86400);

    db_exec(
        'UPDATE remember_tokens
            SET validator_hash = ?, expires_at = ?, last_used_at = NOW(),
                ip_address = ?, user_agent = ?
          WHERE id = ?',
        [
            hash('sha256', $validator),
            date('Y-m-d H:i:s', $expires),
            client_ip(),
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            $token['id'],
        ]
    );

    remember_set_cookie($token['selector'] . ':' . $validator, $expires);
}

/** True when this session came from a cookie rather than a password. */
function session_is_remembered(): bool
{
    return ($_SESSION['auth_via'] ?? 'password') === 'remember';
}

// ------------------------------------------------------------
// Revoking
// ------------------------------------------------------------

/** Forget only the device being used right now. */
function remember_forget_current(): void
{
    if (!remember_ready()) {
        remember_clear_cookie();
        return;
    }

    $parts = remember_parse_cookie();

    if ($parts !== null) {
        db_exec('DELETE FROM remember_tokens WHERE selector = ?', [$parts[0]]);
    }

    remember_clear_cookie();
}

/**
 * Forget every device for a user.
 * Called when the password changes, because the old password may be
 * what an attacker used to plant a cookie in the first place.
 */
function remember_forget_all(int $userId): int
{
    if (!remember_ready()) {
        return 0;
    }

    $removed = db_exec('DELETE FROM remember_tokens WHERE user_id = ?', [$userId]);

    if ($userId === current_user_id()) {
        remember_clear_cookie();
    }

    return $removed;
}

/** Revoke one specific device, if it belongs to this user. */
function remember_forget_device(int $tokenId, int $userId): bool
{
    if (!remember_ready()) {
        return false;
    }

    $token = db_one('SELECT selector FROM remember_tokens WHERE id = ? AND user_id = ?',
                    [$tokenId, $userId]);

    if (!$token) {
        return false;
    }

    db_exec('DELETE FROM remember_tokens WHERE id = ? AND user_id = ?', [$tokenId, $userId]);

    // If they just revoked the device they are sitting at, the cookie
    // has to go too or the next request would look like theft.
    $current = remember_parse_cookie();

    if ($current !== null && $current[0] === $token['selector']) {
        remember_clear_cookie();
    }

    return true;
}

// ------------------------------------------------------------
// Listing
// ------------------------------------------------------------

/** Remembered devices for a member, newest first. */
function remember_devices(int $userId): array
{
    if (!remember_ready()) {
        return [];
    }

    return db_all(
        'SELECT * FROM remember_tokens
          WHERE user_id = ? AND expires_at > NOW()
          ORDER BY COALESCE(last_used_at, created_at) DESC',
        [$userId]
    );
}

/** True when this row is the device making the current request. */
function remember_is_current_device(array $token): bool
{
    $current = remember_parse_cookie();

    return $current !== null && $current[0] === $token['selector'];
}

/** A readable name for a user agent string. */
function describe_user_agent(?string $ua): string
{
    if (empty($ua)) {
        return 'Unknown device';
    }

    $browser = match (true) {
        str_contains($ua, 'Edg/')     => 'Edge',
        str_contains($ua, 'OPR/')     => 'Opera',
        str_contains($ua, 'Chrome/')  => 'Chrome',
        str_contains($ua, 'Firefox/') => 'Firefox',
        str_contains($ua, 'Safari/')  => 'Safari',
        default                       => 'Browser',
    };

    $platform = match (true) {
        str_contains($ua, 'Windows')  => 'Windows',
        str_contains($ua, 'Android')  => 'Android',
        str_contains($ua, 'iPhone')   => 'iPhone',
        str_contains($ua, 'iPad')     => 'iPad',
        str_contains($ua, 'Mac OS')   => 'Mac',
        str_contains($ua, 'Linux')    => 'Linux',
        default                       => 'Unknown platform',
    };

    return $browser . ' on ' . $platform;
}

/** Delete expired rows. Safe to call whenever. */
function remember_prune_expired(): int
{
    if (!remember_ready()) {
        return 0;
    }

    return db_exec('DELETE FROM remember_tokens WHERE expires_at < NOW()');
}
