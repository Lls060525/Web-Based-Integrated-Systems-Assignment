<?php
// ============================================================
// lib/ajax.php
// One place for everything the /api endpoints had been repeating:
// the JSON header, the encode-and-exit, the CSRF check and the
// "who is allowed to call this" check.
//
// Before this, each endpoint declared its own *_json() function and
// its own guard block. Four near-identical copies is four places for
// them to drift apart, and a guard that is copy-pasted is a guard
// somebody eventually forgets to paste.
// ============================================================

/**
 * Send a JSON response and stop.
 *
 * The header is set here rather than at the top of each file, so an
 * endpoint cannot accidentally emit JSON as text/html.
 */
function json_out(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        // A JSON response is per-request state; caching one would show a
        // stale cart count on the next page.
        header('Cache-Control: no-store');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Shorthand for the success shape every endpoint here returns. */
function json_ok(array $payload = []): void
{
    json_out(['status' => 'ok'] + $payload);
}

/** Shorthand for the failure shape. */
function json_error(string $message, array $extra = [], int $status = 200): void
{
    // 200 by default on purpose: these are application-level refusals
    // ("out of stock"), not transport failures, and jQuery routes a
    // non-2xx into .fail() where the message would be thrown away.
    json_out(['status' => 'error', 'message' => $message] + $extra, $status);
}

/**
 * Standard guard for an endpoint that CHANGES something.
 *
 * @param string $role 'any' | 'member' | 'admin'
 */
function ajax_guard_post(string $role = 'any'): void
{
    if (!is_post()) {
        json_error('This endpoint only accepts POST.', [], 405);
    }

    // Checked before the role, so an expired session cannot be told
    // apart from a missing token by probing.
    if (!csrf_valid()) {
        json_error('Your session has expired. Please refresh the page.');
    }

    ajax_require_role($role);
}

/**
 * Guard for an endpoint that only READS.
 *
 * No CSRF token. That is not an oversight: a CSRF token protects
 * against a third-party site causing a state CHANGE with the visitor's
 * cookies. A read-only endpoint has no state to change, and requiring a
 * token would stop it being usable from a plain link or a bookmark.
 * The obligation this creates is that a read endpoint must genuinely
 * not write anything.
 */
function ajax_guard_read(string $role = 'any'): void
{
    ajax_require_role($role);
}

function ajax_require_role(string $role): void
{
    if ($role === 'member' && !is_member()) {
        json_error('Please log in to continue.', ['redirect' => '/auth/login.php']);
    }

    if ($role === 'admin' && !is_admin()) {
        json_error('Admin access required.', [], 403);
    }

    if ($role === 'login' && !is_logged_in()) {
        json_error('Please log in to continue.', ['redirect' => '/auth/login.php']);
    }
}

/**
 * Clamp a requested page size.
 *
 * Without this, ?limit=100000 turns a suggestion box into a way to dump
 * the whole catalogue in one request.
 */
function ajax_limit(string $key, int $default, int $max): int
{
    $value = (int)get($key, (string)$default);

    return max(1, min($max, $value ?: $default));
}

/**
 * Send an AJAX listing response: the table rows, then the pager.
 *
 * The two halves are separated by a sentinel comment rather than sent as
 * JSON because the caller is echoing raw <tr> markup already. The
 * JavaScript splits on the sentinel and puts each half where it belongs.
 *
 * They cannot simply be concatenated into the tbody: a <nav> placed
 * inside a <tbody> is hoisted out of the table by the HTML parser, so it
 * would appear above the table instead of below it.
 */
const AJAX_PAGER_SEPARATOR = '<!--pager-->';

function ajax_rows_with_pager(callable $renderRows, array $pager): void
{
    $renderRows();

    echo AJAX_PAGER_SEPARATOR;
    echo pager_body($pager);

    exit;
}
