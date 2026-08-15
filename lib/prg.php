<?php
// ============================================================
// lib/prg.php
// Post / Redirect / Get for the pages that show validation errors.
//
// THE PROBLEM
//
// A form that fails validation used to answer the POST by drawing the
// page again. That works, but it leaves the browser sitting on a history
// entry whose method is POST, so pressing F5 asks "Confirm form
// resubmission?" -- and confirming really does send the whole thing a
// second time.
//
// On a login form that means another failed attempt recorded against the
// account. On checkout it could mean a second order.
//
// THE FIX
//
// Never answer a POST with a page. Answer it with a redirect, and let
// the browser fetch the page with a GET. The history entry is then a
// GET, and F5 simply re-fetches it.
//
// The catch is that a redirect throws away everything the request
// produced: the validation errors and whatever the member had typed.
// Both are parked in the session for exactly one request and picked up
// again on the other side.
//
// WHY post() KNOWS ABOUT THIS
//
// The forms are written as post('email'), and after the redirect the
// request is a GET, so $_POST is empty. Rather than rewrite every field
// in every form, post() falls back to the parked input. From the page's
// point of view nothing changed.
// ============================================================

/** Session keys. Prefixed so they cannot collide with anything else. */
const PRG_ERR_KEY = '_prg_errors';
const PRG_OLD_KEY = '_prg_old_input';

/**
 * Fields that must never be written to the session.
 *
 * Passwords are the point of this list. Parking one in the session means
 * it sits in the session file on disk in plain text, and re-filling a
 * password box after a failed login is not a feature anyone asked for.
 * The CSRF token is excluded because a fresh one is rendered anyway.
 */
const PRG_NEVER_KEEP = ['password', 'password_confirm', 'current_password',
                        'new_password', 'confirm_password', 'csrf_token',
                        'captcha', 'g-recaptcha-response'];

/**
 * Answer a failed POST with a redirect instead of a page.
 *
 * Call it at the very end of the POST branch, after validation has run
 * and the success path has already redirected somewhere else:
 *
 *     if (is_post()) {
 *         csrf_check();
 *         ...validate...
 *         if (no_err()) { ...save...; redirect('/somewhere'); }
 *         redirect_back();          // <- errors survive, F5 is safe
 *     }
 *
 * @param string $url Where to send them. Defaults to the current page,
 *                    which is nearly always the form they just posted.
 */
function redirect_back(string $url = ''): void
{
    global $_err;

    // Nothing went wrong, so this is not the path this function is for.
    //
    // Returning here rather than redirecting is what lets the call sit
    // unconditionally at the end of a POST block. Several pages answer a
    // SUCCESSFUL post by rendering something -- the batch tools show a
    // preview of what they are about to do, mail_test shows the result
    // of the send -- and redirecting those away would throw the very
    // thing the member asked for on the floor.
    if (no_err()) {
        return;
    }

    $_SESSION[PRG_ERR_KEY] = $_err;
    $_SESSION[PRG_OLD_KEY] = prg_keepable_input();

    if ($url === '') {
        // The path only. Keeping the query string matters for forms that
        // live at a URL like product_form.php?id=7, because losing it
        // would turn an edit form into an add form.
        $url = strtok($_SERVER['REQUEST_URI'] ?? '/', '#');
    }

    redirect($url);
}

/**
 * The parts of $_POST that are safe and useful to carry across.
 *
 * Nested arrays are kept -- batch tools post quantities[7]=3 and the
 * options builder posts options[12][label] -- but only one level of
 * nesting, and only scalars inside it. Anything deeper is a shape this
 * project does not produce, so it is dropped rather than trusted.
 */
function prg_keepable_input(): array
{
    $out = [];

    foreach ($_POST as $key => $value) {
        if (in_array($key, PRG_NEVER_KEEP, true)) {
            continue;
        }

        // A name containing "password" is a password whatever it is
        // called, so the explicit list above is backed up by this.
        if (stripos((string)$key, 'password') !== false) {
            continue;
        }

        if (is_scalar($value)) {
            $out[$key] = (string)$value;
            continue;
        }

        if (is_array($value)) {
            $nested = [];

            foreach ($value as $k => $v) {
                if (is_scalar($v)) {
                    $nested[$k] = (string)$v;
                } elseif (is_array($v)) {
                    $inner = array_filter($v, 'is_scalar');
                    if ($inner !== []) {
                        $nested[$k] = array_map('strval', $inner);
                    }
                }
            }

            if ($nested !== []) {
                $out[$key] = $nested;
            }
        }
    }

    return $out;
}

/**
 * Pick the parked errors and input back up. Called once by lib/init.php.
 *
 * Everything is removed as it is read, so it survives exactly one
 * request. Without that, an error from a form submitted five minutes ago
 * would still be on screen after clicking around the site.
 */
function prg_restore(): void
{
    global $_err, $_old;

    $_old = [];

    // Only ever restored onto a GET. A POST has its own $_POST and its
    // own validation run; layering a previous request's errors on top of
    // that would show errors for fields the member has already fixed.
    if (is_post()) {
        unset($_SESSION[PRG_ERR_KEY], $_SESSION[PRG_OLD_KEY]);
        return;
    }

    if (!empty($_SESSION[PRG_ERR_KEY]) && is_array($_SESSION[PRG_ERR_KEY])) {
        $_err = $_SESSION[PRG_ERR_KEY];
    }

    if (!empty($_SESSION[PRG_OLD_KEY]) && is_array($_SESSION[PRG_OLD_KEY])) {
        $_old = $_SESSION[PRG_OLD_KEY];
    }

    unset($_SESSION[PRG_ERR_KEY], $_SESSION[PRG_OLD_KEY]);
}

/**
 * What was typed last time, for a field the form is about to draw.
 *
 * post() calls this on its own, so most pages never need it directly.
 * It is public for the cases post() cannot serve -- checkboxes and
 * <select> lists that ask "was this the chosen one?" about a value.
 */
function old(string $key, string $default = ''): string
{
    global $_old;

    if (!isset($_old[$key]) || is_array($_old[$key])) {
        return $default;
    }

    return (string)$_old[$key];
}

/** The array form, for fields posted as name[] or name[id]. */
function old_array(string $key): array
{
    global $_old;

    return isset($_old[$key]) && is_array($_old[$key]) ? $_old[$key] : [];
}

/** True when this request is the GET that followed a failed POST. */
function is_replayed_form(): bool
{
    global $_old, $_err;

    return !is_post() && ($_old !== [] || $_err !== []);
}
