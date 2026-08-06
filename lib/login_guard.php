<?php
// ============================================================
// lib/login_guard.php
// Temporary login blocking after repeated failures.
//
// Two independent counters:
//   - per EMAIL, which stops a brute force aimed at one account
//   - per IP,    which stops one machine working through many accounts
//
// Attempts are recorded for addresses that do not exist as well.
// If only real accounts were tracked, "is this locked?" would become
// an oracle telling an attacker which emails are registered.
// ============================================================

/** True once the login_attempts table exists (see the migration). */
function login_guard_ready(): bool
{
    return db_table_exists('login_attempts');
}

/** Best guess at the client IP, capped to the column width. */
function client_ip(): string
{
    // REMOTE_ADDR is the only value the client cannot forge. Proxy
    // headers are deliberately ignored: trusting X-Forwarded-For without
    // a known proxy in front would let anyone reset their own counter.
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

/** Write one attempt to the audit trail. */
function record_login_attempt(string $email, bool $success): void
{
    if (!login_guard_ready()) {
        return;
    }

    db_exec(
        'INSERT INTO login_attempts (email, ip_address, user_agent, success)
         VALUES (?, ?, ?, ?)',
        [
            mb_substr(strtolower(trim($email)), 0, 100),
            client_ip(),
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            $success ? 1 : 0,
        ]
    );
}

/**
 * Failed attempts for one email since its last success, inside the window.
 *
 * Counting "since the last success" is what makes the lock temporary in
 * the useful sense: one correct login wipes the slate without needing a
 * separate DELETE.
 */
function failed_attempts_for_email(string $email): int
{
    if (!login_guard_ready()) {
        return 0;
    }

    $email = strtolower(trim($email));

    return (int)db_value(
        'SELECT COUNT(*)
           FROM login_attempts
          WHERE email = ?
            AND success = 0
            AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            AND attempted_at > COALESCE(
                  (SELECT MAX(attempted_at) FROM login_attempts
                    WHERE email = ? AND success = 1),
                  \'1970-01-01\'
                )',
        [$email, LOGIN_ATTEMPT_WINDOW_MINUTES, $email]
    );
}

/** Failed attempts from one IP inside the window. */
function failed_attempts_for_ip(string $ip): int
{
    if (!login_guard_ready()) {
        return 0;
    }

    return (int)db_value(
        'SELECT COUNT(*)
           FROM login_attempts
          WHERE ip_address = ?
            AND success = 0
            AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
        [$ip, LOGIN_ATTEMPT_WINDOW_MINUTES]
    );
}

/** When the most recent failure for an email happened, or null. */
function last_failure_time(string $email): ?int
{
    if (!login_guard_ready()) {
        return null;
    }

    $when = db_value(
        'SELECT MAX(attempted_at) FROM login_attempts WHERE email = ? AND success = 0',
        [strtolower(trim($email))]
    );

    return $when ? strtotime($when) : null;
}

/**
 * Is this login attempt currently blocked?
 *
 * @return array{
 *   locked: bool, scope: string, remaining_attempts: int,
 *   seconds_left: int, message: string
 * }
 */
function login_lock_status(string $email): array
{
    $free = [
        'locked'             => false,
        'scope'              => '',
        'remaining_attempts' => LOGIN_MAX_ATTEMPTS,
        'seconds_left'       => 0,
        'message'            => '',
    ];

    if (!login_guard_ready() || trim($email) === '') {
        return $free;
    }

    // ---------- Per IP ----------
    $ipFails = failed_attempts_for_ip(client_ip());

    if ($ipFails >= LOGIN_MAX_ATTEMPTS_PER_IP) {
        return [
            'locked'             => true,
            'scope'              => 'ip',
            'remaining_attempts' => 0,
            'seconds_left'       => LOGIN_LOCKOUT_MINUTES * 60,
            'message'            => 'Too many failed sign-in attempts from this device. '
                                  . 'Please wait ' . LOGIN_LOCKOUT_MINUTES . ' minutes and try again.',
        ];
    }

    // ---------- Per email ----------
    $fails = failed_attempts_for_email($email);

    if ($fails < LOGIN_MAX_ATTEMPTS) {
        return [
            'locked'             => false,
            'scope'              => '',
            'remaining_attempts' => LOGIN_MAX_ATTEMPTS - $fails,
            'seconds_left'       => 0,
            'message'            => '',
        ];
    }

    // Locked, but the lock expires LOGIN_LOCKOUT_MINUTES after the last failure.
    $lastFail = last_failure_time($email);
    $unlockAt = ($lastFail ?? time()) + (LOGIN_LOCKOUT_MINUTES * 60);
    $left     = $unlockAt - time();

    if ($left <= 0) {
        // The lock has aged out. The window query above will already have
        // stopped counting those attempts, so this is just a safety net.
        return $free;
    }

    return [
        'locked'             => true,
        'scope'              => 'email',
        'remaining_attempts' => 0,
        'seconds_left'       => $left,
        'message'            => 'This account is temporarily locked after '
                              . LOGIN_MAX_ATTEMPTS . ' failed sign-in attempts. '
                              . 'Try again in ' . format_countdown($left) . '.',
    ];
}

/** "4 minutes 12 seconds", for a human-readable countdown. */
function format_countdown(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . ' second' . ($seconds === 1 ? '' : 's');
    }

    $minutes = (int)floor($seconds / 60);
    $rest    = $seconds % 60;

    $text = $minutes . ' minute' . ($minutes === 1 ? '' : 's');

    if ($rest > 0) {
        $text .= ' ' . $rest . ' second' . ($rest === 1 ? '' : 's');
    }

    return $text;
}

/**
 * Clear the failure history for one email.
 * Used by the admin unlock button; a successful login does not need it
 * because the counter only looks at attempts since the last success.
 */
function clear_login_attempts(string $email): int
{
    if (!login_guard_ready()) {
        return 0;
    }

    return db_exec(
        'DELETE FROM login_attempts WHERE email = ? AND success = 0',
        [strtolower(trim($email))]
    );
}

/** Emails that are locked out right now, for the admin screen. */
function locked_accounts(): array
{
    if (!login_guard_ready()) {
        return [];
    }

    return db_all(
        'SELECT a.email,
                COUNT(*)             AS fails,
                MAX(a.attempted_at)  AS last_attempt,
                MAX(a.ip_address)    AS last_ip,
                u.id                 AS user_id,
                u.name               AS user_name
           FROM login_attempts a
           LEFT JOIN users u ON u.email = a.email
          WHERE a.success = 0
            AND a.attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            AND a.attempted_at > COALESCE(
                  (SELECT MAX(s.attempted_at) FROM login_attempts s
                    WHERE s.email = a.email AND s.success = 1),
                  \'1970-01-01\'
                )
          GROUP BY a.email, u.id, u.name
         HAVING fails >= ?
          ORDER BY last_attempt DESC',
        [LOGIN_ATTEMPT_WINDOW_MINUTES, LOGIN_MAX_ATTEMPTS]
    );
}

/** Recent attempts for the admin audit view. */
function recent_login_attempts(int $limit = 50, string $filter = ''): array
{
    if (!login_guard_ready()) {
        return [];
    }

    $sql    = 'SELECT * FROM login_attempts';
    $params = [];

    if ($filter !== '') {
        $sql     .= ' WHERE (email LIKE ? OR ip_address LIKE ?)';
        $params[] = '%' . $filter . '%';
        $params[] = '%' . $filter . '%';
    }

    $sql .= ' ORDER BY attempted_at DESC LIMIT ' . max(1, min(500, $limit));

    return db_all($sql, $params);
}

/** Delete audit rows older than the retention period. */
function prune_login_attempts(): int
{
    if (!login_guard_ready()) {
        return 0;
    }

    return db_exec(
        'DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
        [LOGIN_ATTEMPT_RETENTION_DAYS]
    );
}
