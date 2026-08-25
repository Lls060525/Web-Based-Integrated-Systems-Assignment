<?php
// ============================================================
// lib/theme.php
// The remembered appearance preference: Light, Dark, or System.
//
// WHERE THE ANSWER COMES FROM, IN ORDER
//
//   1. this request        somebody just clicked the switch
//   2. the user's account  users.theme, for anyone signed in
//   3. the cookie          for guests, and as a fallback
//   4. 'system'            follow the operating system
//
// The account beats the cookie because the account is the thing that
// travels. Sign in on a lab machine and your own preference should
// arrive with you, not inherit whatever the last person chose.
//
// The cookie is still written for signed-in users. It costs one header
// and it means the very first paint after logging in is already the
// right colour, before any query has run.
//
// HOW 'system' IS HONOURED
//
// Not here. PHP cannot see the operating system's setting -- only the
// browser knows, through prefers-color-scheme. So 'system' is passed
// through to the markup as-is and the CSS decides, which is also why
// the dark rules are written twice: once for an explicit choice and
// once inside a media query. See the palette block in style.css.
//
// WHY THERE IS NO FLASH OF THE WRONG COLOUR
//
// The attribute is printed on <html> by the server, in the very first
// tag of the response. There is no moment at which the page exists
// without knowing its theme, so nothing needs to be corrected later.
// A JavaScript solution would paint light and then repaint dark, which
// is the flicker everyone recognises from sites that get this wrong.
// ============================================================

/** The only three answers. Anything else is not a theme. */
const THEMES = ['system', 'light', 'dark'];

const THEME_DEFAULT = 'system';
const THEME_COOKIE  = 'theme';
const THEME_TTL     = 60 * 60 * 24 * 365;   // a year

/** True once migration_29_theme.sql has been run. */
function theme_column_ready(): bool
{
    static $ready = null;

    if ($ready === null) {
        $ready = db_column_exists('users', 'theme');
    }

    return $ready;
}

/**
 * The request-lifetime holder behind current_theme().
 *
 * A one-element array rather than a bare static, because a static
 * inside a function cannot be cleared from outside it -- and this one
 * has to be, on login and after a change. Same shape as
 * cancel_request_cache() in lib/cancellation.php.
 *
 * @return array{value: string|null}
 */
function &theme_cache(): array
{
    static $cache = ['value' => null];

    return $cache;
}

/**
 * The theme in force for this request.
 *
 * Cached for the request: the layout asks once for the <html> attribute
 * and again for the switcher, and the answer must not change in between
 * or the page would disagree with its own switch.
 */
function current_theme(): string
{
    $cache = &theme_cache();

    if ($cache['value'] !== null) {
        return $cache['value'];
    }

    // From the signed-in account.
    if (is_logged_in() && theme_column_ready()) {
        $stored = db_value('SELECT theme FROM users WHERE id = ?', [current_user_id()]);

        if (is_string($stored) && in_array($stored, THEMES, true)) {
            return $cache['value'] = $stored;
        }
    }

    // From the cookie: the only store a guest has.
    $cookie = $_COOKIE[THEME_COOKIE] ?? null;

    if (is_string($cookie) && in_array($cookie, THEMES, true)) {
        return $cache['value'] = $cookie;
    }

    return $cache['value'] = THEME_DEFAULT;
}

/**
 * Record a new choice, in whichever stores apply.
 *
 * Returns false for a value that is not a theme. The caller is a POST
 * handler and the value came from a form, so this is the boundary where
 * a made-up value has to stop -- everything downstream writes it into
 * an HTML attribute.
 */
function set_theme(string $theme): bool
{
    if (!in_array($theme, THEMES, true)) {
        return false;
    }

    if (is_logged_in() && theme_column_ready()) {
        db_exec('UPDATE users SET theme = ? WHERE id = ?', [$theme, current_user_id()]);
    }

    theme_remember_in_cookie($theme);

    // Keep this request consistent with what was just saved. Without it
    // the page rendered immediately after the change would still show
    // the previous theme, because current_theme() cached the old answer
    // before the update ran.
    theme_reset_cache($theme);

    return true;
}

/** Write the cookie, if there is still a chance to send a header. */
function theme_remember_in_cookie(string $theme): void
{
    if (!in_array($theme, THEMES, true) || headers_sent()) {
        return;
    }

    setcookie(THEME_COOKIE, $theme, [
        'expires'  => time() + THEME_TTL,
        'path'     => '/',
        // Readable by script on purpose: it holds no secret, and a
        // future enhancement that wants to react before the page loads
        // would need to see it.
        'httponly' => false,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);

    $_COOKIE[THEME_COOKIE] = $theme;
}

/**
 * Forget the cached answer, optionally seeding a new one.
 *
 * Called after a change and after login, for the same reason
 * role_reset_cache() exists: a value worked out earlier in the request
 * was worked out for a different user or a different setting, and
 * serving it afterwards shows somebody a page that disagrees with what
 * they just did.
 */
function theme_reset_cache(?string $seed = null): void
{
    $cache = &theme_cache();

    // null means "work it out again next time it is asked"; a seed
    // means "this is the answer now", which saves re-querying for a
    // value we just wrote ourselves.
    $cache['value'] = ($seed !== null && in_array($seed, THEMES, true)) ? $seed : null;
}

/**
 * Carry a guest's choice into the account they just signed into.
 *
 * Somebody who picks dark, then registers or logs in, has expressed a
 * preference; throwing it away at the door and showing them the default
 * makes the setting look broken. Only applied when the account has not
 * already got a preference of its own -- an existing choice on the
 * account is the more considered of the two and must win.
 */
function theme_adopt_guest_choice(): void
{
    if (!is_logged_in() || !theme_column_ready()) {
        return;
    }

    $cookie = $_COOKIE[THEME_COOKIE] ?? null;

    if (!is_string($cookie) || !in_array($cookie, THEMES, true) || $cookie === THEME_DEFAULT) {
        return;
    }

    db_exec(
        "UPDATE users SET theme = ? WHERE id = ? AND theme = 'system'",
        [$cookie, current_user_id()]
    );

    // The account may have had a preference of its own, in which case
    // the UPDATE above changed nothing. Either way the cached answer
    // was worked out for the previous visitor and has to go.
    theme_reset_cache();
}

/**
 * The value for the data-theme attribute on <html>.
 *
 * 'system' is emitted as-is rather than resolved, because resolving it
 * would mean guessing. The CSS handles it.
 */
function theme_attribute(): string
{
    return current_theme();
}

/**
 * The switcher.
 *
 * A POST form rather than links, because this WRITES -- to the database
 * for a signed-in user. A GET that changes stored state is the thing
 * that lets a page prefetcher or a crawler change somebody's settings
 * for them, and it is why this carries a CSRF token while the
 * table/photo toggle in lib/listing_view.php does not.
 *
 * Every button posts back to the current URL, so the switch works on
 * any page and returns to it.
 */
function theme_switcher(): void
{
    $current = current_theme();

    $options = [
        'light'  => ['icon' => 'fa-sun',            'label' => 'Light'],
        'dark'   => ['icon' => 'fa-moon',           'label' => 'Dark'],
        'system' => ['icon' => 'fa-circle-half-stroke', 'label' => 'Match system'],
    ];

    // The action is the current path with its query string intact, so
    // changing the theme on page 3 of a filtered listing comes back to
    // page 3 of that filtered listing.
    $action = e($_SERVER['REQUEST_URI'] ?? '/');

    echo '<form method="POST" action="' . $action . '" class="theme-switcher" '
       . 'role="group" aria-label="Colour theme">';

    csrf_field();
    html_hidden('action', 'set_theme');

    foreach ($options as $value => $meta) {
        $isCurrent = $value === $current;

        echo '<button type="submit" name="theme" value="' . e($value) . '"'
           . ' class="theme-btn' . ($isCurrent ? ' active' : '') . '"'
           . ' title="' . e($meta['label']) . '"'
           . ' aria-label="' . e($meta['label']) . '"'
           . ' aria-pressed="' . ($isCurrent ? 'true' : 'false') . '">'
           . '<i class="fas ' . $meta['icon'] . '" aria-hidden="true"></i>'
           . '</button>';
    }

    echo '</form>';
}

/**
 * Handle a theme change posted from the switcher.
 *
 * Called from lib/init.php on EVERY request, before a page runs its own
 * POST handling. The switcher lives in the shared layout, so its form
 * can be submitted from any page -- and a page that knew nothing about
 * it would otherwise fall through to its own handler, fail to recognise
 * the action, and in several cases redirect with a puzzling error.
 *
 * Returns true when it consumed the request, so init.php can redirect
 * and stop. The redirect is what keeps this a Post/Redirect/Get: a
 * refresh afterwards must not re-submit the change.
 */
function theme_handle_post(): bool
{
    if (!is_post() || post('action') !== 'set_theme') {
        return false;
    }

    // Same protection as any other state change. An expired token here
    // is harmless in effect but the check must not be conditional, or
    // "which POSTs are protected" stops having a simple answer.
    if (!csrf_valid()) {
        return false;
    }

    set_theme(post('theme'));

    return true;
}
