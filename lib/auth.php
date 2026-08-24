<?php
// ============================================================
// lib/auth.php
// Authentication state and page-level authorization guards.
// ============================================================

/** True when somebody is logged in. */
function is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

/** Role of the current user ('admin' | 'member' | ''). */
function current_role(): string
{
    return $_SESSION['role'] ?? '';
}

function is_admin(): bool
{
    return is_logged_in() && current_role() === 'admin';
}

function is_member(): bool
{
    return is_logged_in() && current_role() === 'member';
}

/** Id of the current user, or null. */
function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**
 * Fresh user row from the database (never a stale session copy).
 * Cached for the duration of the request.
 */
function current_user(): ?array
{
    static $user = null;
    static $loaded = false;

    if (!$loaded) {
        $loaded = true;
        $id = current_user_id();
        if ($id !== null) {
            $user = db_one(
                'SELECT id, name, email, role, status, profile_photo, created_at FROM users WHERE id = ?',
                [$id]
            ) ?: null;
        }
    }

    return $user;
}

/** Establish a logged-in session for a user row. */
function login_user(array $user): void
{
    // Defeat session fixation: the id the attacker planted becomes useless.
    session_regenerate_id(true);

    $_SESSION['user_id']    = (int)$user['id'];
    $_SESSION['role']       = $user['role'];
    $_SESSION['login_time'] = time();

    // Anything that asked can() earlier in THIS request answered for a
    // guest and cached it. The redirect immediately after this call
    // would otherwise read that stale answer and send an administrator
    // to their profile page instead of their real landing page.
    role_reset_cache();
}

/** Destroy the session completely. */
function logout_user(): void
{
    role_reset_cache();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }

    session_destroy();
}

/**
 * How many administrators can still log in.
 * Used to stop the system being left with no usable admin account.
 */
function active_admin_count(): int
{
    return (int)db_value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'");
}

/**
 * Where a user belongs after logging in.
 *
 * This used to hand every administrator /admin/dashboard.php. Once the
 * dashboard became a permission like any other, a role without it met a
 * 403 the moment it signed in -- the first thing a new Delivery Man
 * account saw was "Not authorised", which is a dreadful greeting for
 * something working exactly as designed.
 *
 * admin_landing_url() answers properly: the role's configured landing
 * page if it still holds that permission, otherwise the first area it
 * can open, otherwise its own profile. There is no permission set,
 * including the empty one, that lands on a page the role cannot open.
 *
 * Members are unchanged -- the whole role model is admin-side.
 */
function home_url_for_role(string $role): string
{
    return $role === 'admin' ? admin_landing_url() : '/member/home.php';
}

// ------------------------------------------------------------
// Guards - call at the very top of a protected page
// ------------------------------------------------------------

/**
 * Any logged-in user.
 *
 * The account status is re-read from the database on every request, so an
 * admin who bans someone takes effect immediately instead of waiting for
 * that person's session to expire.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        flash_error('Please log in to continue.');
        redirect('/auth/login.php');
    }

    $user = current_user();

    if (!$user || $user['status'] !== 'active') {
        logout_user();
        session_start();
        flash_error('Your account is no longer active. Please contact support.');
        redirect('/auth/login.php');
    }
}

/** Admin only. Members get a 403 and are sent back to their own area. */
function require_admin(): void
{
    require_login();

    if (!is_admin()) {
        http_response_code(403);
        flash_error('You are not authorised to access the admin area.');
        redirect('/member/home.php');
    }
}

/** Member only. Admins are sent back to the admin area. */
function require_member(): void
{
    require_login();

    if (!is_member()) {
        http_response_code(403);
        flash_error('This page is for members only.');

        // Not the dashboard: an admin whose role lacks dashboard.view
        // would be bounced from one 403 straight into another.
        redirect(admin_landing_url());
    }
}

/** Guests only - used by login/register so logged-in users skip them. */
function require_guest(): void
{
    if (is_logged_in()) {
        redirect(home_url_for_role(current_role()));
    }
}
