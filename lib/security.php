<?php
// ============================================================
// lib/security.php
// CSRF protection and password-reset token handling.
// ============================================================

// ------------------------------------------------------------
// CSRF (Cross-Site Request Forgery) protection
// ------------------------------------------------------------

/** Current CSRF token for this session, generated on first use. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Print the hidden CSRF input. Every POST form must call this. */
function csrf_field(): void
{
    echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** True when the submitted token matches the session token. */
function csrf_valid(): bool
{
    $submitted = $_POST['csrf_token'] ?? '';
    return is_string($submitted)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submitted);
}

/**
 * Abort the request when the CSRF token is missing or wrong.
 * Call this at the top of every POST handler.
 */
function csrf_check(): void
{
    if (!is_post()) {
        return;
    }
    if (!csrf_valid()) {
        http_response_code(419);
        exit('Your session has expired or the request could not be verified. Please go back and try again.');
    }
}

// ------------------------------------------------------------
// Password reset tokens
// ------------------------------------------------------------

/**
 * True once database/migration_password_reset.sql has been run.
 * Guarding on this means a missing migration degrades the feature
 * instead of throwing a fatal error mid-demonstration.
 */
function reset_module_ready(): bool
{
    return db_table_exists('password_resets');
}

/**
 * Issue a reset token for a user.
 * Only the SHA-256 hash is stored, so a leaked database row
 * cannot be replayed as a working reset link.
 *
 * @return string the plain token to place in the reset URL
 */
function create_reset_token(int $userId): string
{
    // One active token per user - drop any earlier ones.
    revoke_reset_tokens($userId);

    $token     = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + RESET_TOKEN_TTL);

    db_exec(
        'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)',
        [$userId, hash('sha256', $token), $expiresAt]
    );

    return $token;
}

/**
 * Look up a reset token.
 * Returns the joined reset + user row, or null when the token is
 * unknown, already used or expired.
 */
function find_reset_token(string $token): ?array
{
    if ($token === '' || !reset_module_ready()) {
        return null;
    }

    $row = db_one(
        'SELECT r.id, r.user_id, r.expires_at, r.used_at, u.email, u.name
           FROM password_resets r
           JOIN users u ON u.id = r.user_id
          WHERE r.token_hash = ?',
        [hash('sha256', $token)]
    );

    if (!$row) {
        return null;
    }
    if ($row['used_at'] !== null) {
        return null;
    }
    if (strtotime($row['expires_at']) < time()) {
        return null;
    }

    return $row;
}

/** Mark a reset token as consumed so it cannot be reused. */
function consume_reset_token(int $resetId): void
{
    if (reset_module_ready()) {
        db_exec('UPDATE password_resets SET used_at = NOW() WHERE id = ?', [$resetId]);
    }
}

/**
 * Invalidate every outstanding reset link for a user.
 * Called whenever the password changes by any other route.
 */
function revoke_reset_tokens(int $userId): void
{
    if (reset_module_ready()) {
        db_exec('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
    }
}

/** Build the absolute reset URL that gets emailed or displayed. */
function reset_url(string $token): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/auth/reset_password.php?token=' . urlencode($token);
}
