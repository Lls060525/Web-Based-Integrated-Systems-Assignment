# Security audit

A full pass over the project. Everything below was checked by scanning every
PHP, JS and CSS file rather than by spot-reading.

---

## Fixed during the audit

### 1. `.git` was reachable over the web — critical

The document root is the project root, so `http://host/.git/` served the whole
repository. `/.git/config` alone leaks the remote URL, and the object store lets
anyone reconstruct **the entire source**, including `lib/config.php` with the
Stripe key and the Gmail App Password.

`database/` was equally exposed. It holds the migrations and, once exported for
submission, `mobile2u.sql` — every user row including password hashes.

**Fixed** with a root `.htaccess` that blocks dot-files and dot-directories,
`.sql`, `.md`, `.log`, `.lock`, `.json` and friends, plus `Options -Indexes` so a
folder with no index file stops printing its contents. Per-directory
`.htaccess` files were added to `.git/`, `database/`, `includes/` and `vendor/`
as well, so the protection does not depend on one file.

> **This only works if Apache is allowed to read `.htaccess`.** In your vhost the
> `<Directory>` block must say `AllowOverride All`. With `AllowOverride None`
> these files are ignored silently. Verify by opening
> `http://localhost:8001/.git/config` — you should get **403 Forbidden**, not a
> file.

### 2. Open redirect in `redirect()`

`redirect()` fell back to `$_SERVER['REQUEST_URI']` when called with no argument,
and passed whatever it had straight to `header('Location: ...')`.

`REQUEST_URI` can be made to start with `//`, and `Location: //evil.com` is a
**protocol-relative** redirect to another host. It looks like a path because it
starts with a slash. That is what turns a link genuinely beginning on your domain
into a working phishing link.

**Fixed**: the target must now match `^/(?!/)` — exactly one leading slash — or it
is replaced with `/`. Verified against absolute URLs, protocol-relative URLs,
`javascript:` and relative paths.

### 3. Runtime values concatenated into `.html()`

`webcam.js` built error messages by concatenating `err.message` and
`window.location.hostname` into markup passed to `.html()`.

Neither is exploitable today — a browser will not put `<` in `location.hostname`
— but it is the shape of an XSS. **Fixed**: the literal half still goes through
`.html()`, anything dynamic now goes through `.text()`.

### 4. Credentials were committed to git

`lib/config.php` is tracked and appears in 2 commits, and there was no
`.gitignore`.

**Fixed**: added `.gitignore` (which excludes `lib/config.php`, `vendor/`,
uploads, the mail log and the database export) and `lib/config.example.php` as a
template with every secret blanked.

**This does not undo the history.** See "Still to do" below.

---

## Checked and found correct

| Area | Result |
|------|--------|
| **SQL injection** | Every query is a prepared statement. 26 SQL strings interpolate a PHP variable; each was traced to source — placeholder lists built with `array_fill`, column names chosen by code, `ORDER BY` from a `match` with a `default` arm, and integers. No user input reaches SQL as text. |
| **XSS** | All output goes through `e()`. The scan surfaced 143 unescaped `<?= ?>` blocks; each was a ternary emitting a literal CSS class, an integer from `COUNT()`, or `nl2br(e(...))`. |
| **CSRF** | Every POST entry point calls `csrf_check()`, `csrf_valid()` or `ajax_guard_post()`. The only two files reading `$_POST` without one are `lib/helpers.php` and `lib/captcha.php`, which are libraries, not entry points. |
| **IDOR** | Every owned record is fetched *and* written with `AND user_id = ?` — orders via `find_member_order()`, addresses via `find_user_address()`, remember-me tokens, cart rows. Checkout re-verifies that the chosen shipping address belongs to the buyer. |
| **Privilege escalation** | `users.role` is never written outside `/admin`. Member profile updates touch only name, email, password and photo, so `role=admin` cannot be smuggled in. |
| **Admin access** | All 31 files in `/admin` include `admin_auth.php`. |
| **File upload** | The MIME type is sniffed with `finfo`, the extension is derived from the *sniffed* type rather than the filename, and the stored name is `bin2hex(random_bytes(8))`. Path traversal and double-extension tricks are both impossible. `assets/uploads/.htaccess` additionally refuses to execute anything there as a script. |
| **Passwords** | `password_hash()` with `PASSWORD_DEFAULT`, verified with `password_verify()`, transparently rehashed on login when the algorithm changes. No MD5 or SHA-1 anywhere near a password. |
| **Sessions** | `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS, and `session_regenerate_id(true)` on login. |
| **Header injection** | `header('Location: ...')` — PHP has rejected newlines in header values since 5.1.2. |
| **Brute force** | Account lockout after repeated failures, CAPTCHA after the first, and per-session cooldowns on the endpoints that send mail. |
| **Double submit** | One-use nonces on the actions that send mail; staged-token consumption on the batch tools; `stripe_session_id` idempotency on checkout. |

---

## Still to do — these need you, not code

### 1. Rotate the two live secrets

`lib/config.php` currently contains a working Stripe test key and a working Gmail
App Password, **and both are in the git history**. Adding `.gitignore` now does
not remove them from the 2 commits that already have them.

1. Revoke the App Password at <https://myaccount.google.com/security>
2. Roll the Stripe test key in the Stripe dashboard
3. Replace both in `lib/config.php` with placeholders before zipping
4. If this was ever pushed to GitHub, assume both are public

Anyone holding the ZIP or the repo can otherwise send email as you.

### 2. `APP_DEBUG` is `true`

It must be `false` before submission or before exposing the site on any network.
With it on, an uncaught error prints a stack trace including absolute file paths.

### 3. `MAIL_MODE` is `'prod'`

The site will really send email. That is fine for testing, but combined with
`APP_DEBUG` and a shared network it is worth being deliberate about.

### 4. Confirm `AllowOverride All`

The `.htaccess` protection added above is inert if Apache is configured with
`AllowOverride None` for your vhost. Test: `http://localhost:8001/.git/config`
must return **403**.

---

## Notes

- `config/database.php` is a dead compatibility shim that is no longer referenced
  by anything. It is behind `config/.htaccess`, so it is not reachable, but it
  can be deleted.
- `api/check_email.php` deliberately reveals whether an address is registered.
  That is unavoidable: the registration form already reveals it by refusing
  duplicates, so the endpoint is no more revealing than the form it belongs to.
  It is throttled per session, and the login and password-reset pages
  deliberately do not use it.
