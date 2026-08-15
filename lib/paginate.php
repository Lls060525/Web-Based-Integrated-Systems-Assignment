<?php
// ============================================================
// lib/paginate.php
// Shared pagination for the admin listings.
//
// Every admin listing used to run a bare SELECT with no LIMIT and
// render the whole table. That is fine on sample data and quietly
// fatal later: login_attempts gains a row on EVERY sign-in attempt,
// success or failure, so it is the first table to reach thousands of
// rows and the first page to become unusable.
//
// The storefront catalogue already paginated. This is the same idea
// packaged so a listing opts in with three lines instead of copying
// the arithmetic and the markup each time.
// ============================================================

/**
 * Work out the current page, the offset, and how many pages there are.
 *
 * The page number is clamped rather than trusted: ?page=-5 and
 * ?page=99999 both have to land somewhere sensible, and an offset built
 * from a negative number is a SQL error rather than an empty result.
 *
 * @return array{page:int, per_page:int, offset:int, total:int, pages:int, from:int, to:int}
 */
function paginate(int $total, int $perPage = 25, string $param = 'page'): array
{
    $perPage = max(1, $perPage);
    $pages   = max(1, (int)ceil($total / $perPage));

    $page = (int)get($param, '1');
    $page = max(1, min($page, $pages));

    $offset = ($page - 1) * $perPage;

    return [
        'page'     => $page,
        'per_page' => $perPage,
        'offset'   => $offset,
        'total'    => $total,
        'pages'    => $pages,
        // 1-based, for "Showing 26 to 50 of 137".
        'from'     => $total === 0 ? 0 : $offset + 1,
        'to'       => min($offset + $perPage, $total),
    ];
}

/**
 * Build a URL that keeps every current filter and changes only the page.
 *
 * Rebuilt from $_GET rather than from a hand-listed set of parameters,
 * so a listing that gains a new filter later does not silently lose it
 * when somebody clicks page 2.
 */
function pager_url(int $page, string $param = 'page'): string
{
    $params = $_GET;
    $params[$param] = $page;

    // Empty values only make the URL noisy.
    $params = array_filter(
        $params,
        static fn($v) => $v !== '' && $v !== null && !is_array($v)
    );

    return strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($params);
}

/**
 * The pager markup itself, returned rather than echoed.
 *
 * Returned as a string because the AJAX live search has to send the rows
 * and the pager back in one response and let the JavaScript put each in
 * its own place.
 *
 * The window is capped: with 400 pages, printing 400 links is its own
 * usability problem, so it shows a few either side of the current page
 * with first and last always reachable.
 */
function pager_body(array $p, string $param = 'page'): string
{
    if ($p['total'] === 0) {
        return '';
    }

    if ($p['pages'] <= 1) {
        // One page, but still worth saying how many rows there are.
        return '<span class="pager-summary">' . e($p['total'])
             . ' record' . ($p['total'] === 1 ? '' : 's') . '</span>';
    }

    $page  = $p['page'];
    $pages = $p['pages'];
    $span  = 2;                       // pages either side of the current one

    $out = '<span class="pager-summary">Showing ' . e($p['from']) . ' to ' . e($p['to'])
         . ' of ' . e($p['total']) . '</span>';

    if ($page > 1) {
        $out .= '<a href="' . e(pager_url($page - 1, $param)) . '" class="page-link" rel="prev">&larr; Prev</a>';
    }

    $gap = false;

    for ($i = 1; $i <= $pages; $i++) {
        $inWindow = $i === 1 || $i === $pages || abs($i - $page) <= $span;

        if (!$inWindow) {
            // One ellipsis per gap, not one per skipped page.
            if (!$gap) {
                $out .= '<span class="page-gap">&hellip;</span>';
                $gap  = true;
            }
            continue;
        }

        $gap = false;

        $out .= '<a href="' . e(pager_url($i, $param)) . '" class="page-link'
              . ($i === $page ? ' active' : '') . '"'
              . ($i === $page ? ' aria-current="page"' : '') . '>' . $i . '</a>';
    }

    if ($page < $pages) {
        $out .= '<a href="' . e(pager_url($page + 1, $param)) . '" class="page-link" rel="next">Next &rarr;</a>';
    }

    return $out;
}

/**
 * Render the pager inside a container that is ALWAYS emitted.
 *
 * The container has to exist even when there is nothing to draw, because
 * after a live search returns a single page of results the JavaScript
 * still needs somewhere to put the replacement -- and an element that
 * only exists sometimes is an element the script cannot rely on.
 */
function render_pager(array $p, string $param = 'page'): void
{
    echo '<nav class="pagination admin-pagination" id="pagerSlot" aria-label="Pages">'
       . pager_body($p, $param)
       . '</nav>';
}

/**
 * The LIMIT/OFFSET clause for the current page.
 *
 * MySQL refuses a bound placeholder for LIMIT and OFFSET when PDO is
 * emulating prepares, so these two numbers have to be interpolated. That
 * is safe here and only here: both come out of paginate(), which
 * produced them with (int) casts from a clamped page number. Nothing a
 * visitor typed reaches this string.
 */
function pager_limit(array $p): string
{
    return ' LIMIT ' . (int)$p['per_page'] . ' OFFSET ' . (int)$p['offset'];
}
