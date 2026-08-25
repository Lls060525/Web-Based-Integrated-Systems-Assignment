<?php
// ============================================================
// lib/listing_view.php
// One record set, two layouts: a dense TABLE for comparing numbers
// and a PHOTO grid for recognising things by sight.
//
// A listing opts in with two lines:
//
//     $view = listing_view('products');          // near the top
//     listing_view_toggle('products', $view);    // in the header
//
// and then renders whichever layout $view names.
//
// WHERE THE CHOICE LIVES, AND WHY IN TWO PLACES
//
// The choice is held in the URL *and* in a cookie, which sounds like
// one too many until you look at what each is for:
//
//   The URL carries it so everything built from the query string keeps
//   it. pager_url() rebuilds links from $_GET, so page 2 stays in the
//   same layout for free, and a link pasted to a colleague opens the
//   way the sender saw it.
//
//   The cookie carries it so it survives leaving the page. That is the
//   actual requirement -- "remember how I like to look at this" -- and
//   a URL cannot do it, because the next visit starts from a bare
//   /admin/products.php with no query string at all.
//
// The URL wins when both are present. An explicit click should beat a
// remembered preference, otherwise the toggle would appear to be dead
// for anyone whose cookie disagreed with the link they just followed.
// ============================================================

/**
 * The layouts a listing may be shown in.
 *
 * A closed list, checked on the way in. The value reaches a CSS class
 * name and a cookie, and both a cookie and a query string are things a
 * visitor writes -- so "grid" has to mean grid and anything else has to
 * mean the default, not "whatever was typed".
 */
const LISTING_VIEWS = ['table', 'grid'];

/** Default when nothing has been chosen: the denser of the two. */
const LISTING_VIEW_DEFAULT = 'table';

/** How long the remembered choice lasts. */
const LISTING_VIEW_TTL = 60 * 60 * 24 * 90;   // 90 days

/**
 * Work out how this listing should be drawn, and remember it.
 *
 * Order of preference: ?view= in the URL, then the cookie, then the
 * default. Anything not in LISTING_VIEWS is treated as absent rather
 * than as an error -- a stale bookmark should show a working page, not
 * a refusal.
 *
 * @param string $key identifies the listing, so products and members
 *                    can be remembered independently
 */
function listing_view(string $key, string $default = LISTING_VIEW_DEFAULT): string
{
    $cookie = listing_view_cookie($key);

    // From the URL. Also the only branch that writes, because a cookie
    // should only change when somebody actually asked for a change.
    $asked = (string)get('view', '');

    if (in_array($asked, LISTING_VIEWS, true)) {
        // Only when it is news. Re-sending an identical cookie on every
        // page load is a header nobody reads.
        if ($asked !== $cookie && !headers_sent()) {
            $cookieName = listing_view_cookie_name($key);

            setcookie($cookieName, $asked, [
                'expires'  => time() + LISTING_VIEW_TTL,
                'path'     => '/',
                'httponly' => false, // no secret in it; readable by script is harmless
                'samesite' => 'Lax',
                'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            ]);

            // So a later listing_view() call in THIS request sees the new
            // value. $_COOKIE is the request's copy and setcookie() does
            // not touch it, which would otherwise make the first render
            // after a toggle disagree with itself.
            $_COOKIE[$cookieName] = $asked;
        }

        return $asked;
    }

    if ($cookie !== null) {
        return $cookie;
    }

    return in_array($default, LISTING_VIEWS, true) ? $default : LISTING_VIEW_DEFAULT;
}

/** The cookie name for one listing. */
function listing_view_cookie_name(string $key): string
{
    // The key comes from code rather than from a request, but it is
    // filtered anyway: a cookie name with a space or a semicolon in it
    // produces a malformed Set-Cookie header rather than an obvious
    // failure, which is a horrible thing to debug later.
    return 'view_' . preg_replace('/[^a-z0-9_]/i', '', $key);
}

/** The remembered choice, or null if there is none worth trusting. */
function listing_view_cookie(string $key): ?string
{
    $value = $_COOKIE[listing_view_cookie_name($key)] ?? null;

    return is_string($value) && in_array($value, LISTING_VIEWS, true) ? $value : null;
}

/**
 * A URL for the current listing in a different layout.
 *
 * Built from $_GET so the search term and every filter survive the
 * switch. Switching layout is a change of clothes, not a change of
 * subject -- landing back on an unfiltered page one would lose the work
 * of getting to the rows you were looking at.
 *
 * The page number is deliberately kept for the same reason, which is
 * only safe because both layouts show the same number of records per
 * page. If a layout ever wants a different page size, this has to drop
 * the page parameter.
 */
function listing_view_url(string $view): string
{
    $params = $_GET;
    $params['view'] = $view;

    $params = array_filter(
        $params,
        static fn($v) => $v !== '' && $v !== null && !is_array($v)
    );

    return strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($params);
}

/**
 * The two-button switch.
 *
 * Links rather than buttons in a form: this changes nothing on the
 * server beyond a preference, so it is a GET, and a GET should be a
 * link. It also means the switch works with JavaScript off, which the
 * rest of the admin listings already manage.
 */
function listing_view_toggle(string $key, string $current): void
{
    $options = [
        'table' => ['icon' => 'fa-list',  'label' => 'Table view'],
        'grid'  => ['icon' => 'fa-th',    'label' => 'Photo view'],
    ];

    echo '<div class="view-toggle" role="group" aria-label="Layout">';

    foreach ($options as $view => $meta) {
        $isCurrent = $view === $current;

        echo '<a href="' . e(listing_view_url($view)) . '"'
           . ' class="view-toggle-btn' . ($isCurrent ? ' active' : '') . '"'
           . ' title="' . e($meta['label']) . '"'
           . ' aria-label="' . e($meta['label']) . '"'
           // Tells a screen reader which of the two is on, which the
           // colour change alone does not.
           . ' aria-pressed="' . ($isCurrent ? 'true' : 'false') . '">'
           . '<i class="fas ' . $meta['icon'] . '" aria-hidden="true"></i>'
           . '</a>';
    }

    echo '</div>';
}

/**
 * The "nothing here" message, in whichever shape the layout needs.
 *
 * A table needs a <tr> with a colspan; a grid needs a plain block. The
 * wrong one is not merely ugly: a <tr> outside a table is discarded by
 * the HTML parser, so an empty photo view would silently show nothing
 * at all rather than saying there was nothing.
 */
function listing_empty(string $view, int $colspan, string $message = 'No records found.'): void
{
    if ($view === 'table') {
        echo '<tr><td colspan="' . (int)$colspan . '" class="table-empty">' . e($message) . '</td></tr>';
        return;
    }

    echo '<p class="table-empty grid-empty">' . e($message) . '</p>';
}
