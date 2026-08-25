# Mobile2U — code walkthrough

How the project fits together. The comments in each file explain **why that
file does what it does**; this explains **how the files reach each other**.

Read this once before the demonstration. Section 6.0 of the brief says
technical questions will be asked, and most of them are answered by knowing
which file runs when.

---

## 1. The shape of the project

```
/                    the storefront: products, cart, checkout, orders
/auth                login, logout, register, password reset
/member              profile, addresses, wishlist, points, reviews, devices
/admin               35 staff screens
/api                 11 AJAX endpoints, all returning JSON
/includes            layout and shared render helpers
/lib                 36 library files — the reusable base library
/assets              CSS (2 files), JS (8 files), uploads
/database            25 migrations, a README, and sample CSVs
                     (the schema export required by 7.0 is NOT here yet)
```

Two rules explain most of the layout:

**A file in `/lib` never prints anything.** It fetches, validates, decides,
writes. Anything that produces HTML is a page or an `/includes` partial. That
separation is why the same rule can be enforced by a page, an API endpoint and
a batch tool without being written three times.

**A file in `/includes` never decides anything.** It renders what it is given.
`includes/order_parts.php` draws a status timeline; it does not work out who is
allowed to change the status.

---

## 2. What happens on every single request

Every page begins with one line:

```php
require_once __DIR__ . '/lib/init.php';
```

`lib/init.php` is 116 lines and runs in this order. The order is not arbitrary
— each step depends on the one above it.

| # | Step | Why it is here |
|---|------|----------------|
| 1 | `config.php` | Constants everything else reads |
| 2 | Error reporting | `APP_DEBUG` off ⇒ errors go to the log, never to the screen |
| 3 | **Session start** | With `httponly`, `samesite=Lax`, `secure` when HTTPS |
| 4 | Composer autoload | Before the library, so Stripe/Dompdf/PHPMailer are visible everywhere |
| 5 | The 30-odd `lib/` files | Order matters only for constants; functions resolve at call time |
| 6 | `prg_restore()` | Picks validation errors and old input back up after a redirect |
| 7 | `remember_attempt_login()` | Only if the cookie exists — a guest costs no query |
| 8 | `theme_handle_post()` | The theme switcher lives in the layout, so any page can receive it |
| 9 | Security headers | `nosniff`, `SAMEORIGIN`, `Referrer-Policy` |

**Nothing has been printed yet, and that is the point.** Steps 3, 7 and 8 all
send headers. Once a single byte of output escapes, no page can start a
session, set a cookie, or redirect. This is why every page in this project is
written as *think first, print second*:

```php
require_once 'lib/init.php';   // bootstrap
require_permission('...');      // may redirect
$data = db_all(...);            // gather
if (is_post()) { ... }          // may redirect
include 'includes/header.php';  // ← printing starts HERE
```

---

## 3. The page sandwich

```
include includes/header.php    opens <html>, prints the nav bar
   ...the page's own HTML...
include includes/footer.php    closes everything
```

The admin area has its own pair (`admin_header.php` / `admin_footer.php`) with
the sidebar instead of the shop nav. The sidebar is built from `admin_areas()`
in `lib/role.php`, so it shows only what your role can open — one list drives
the menu, the landing page and the permission checks.

---

## 4. The library, by job

### The four you cannot avoid

| File | What it is for |
|---|---|
| `db.php` | One shared PDO connection. `db_one`, `db_all`, `db_value`, `db_exec` |
| `helpers.php` | `e()`, `get()`, `post()`, `redirect()`, flash messages, HTML helpers |
| `auth.php` | Who is signed in; `require_member()`, `require_admin()`, `login_user()` |
| `role.php` | Permissions. `can()`, `require_permission()`, the admin area map |

### Security

| File | What it is for |
|---|---|
| `security.php` | CSRF tokens; password-reset tokens |
| `login_guard.php` | Temporary blocking after repeated failures |
| `captcha.php` | reCAPTCHA or a local image, chosen by a driver switch |
| `remember.php` | "Remember me" as `selector:validator`, hashed at rest |
| `prg.php` | Post/Redirect/Get, so F5 cannot resubmit |

### Shop

| File | What it is for |
|---|---|
| `cart.php` | Loading and pricing the cart |
| `voucher.php` | Validating and redeeming discount codes |
| `points.php` | Reward points as an **append-only ledger** |
| `stock.php` | Every change to `products.stock`, plus the movement ledger |
| `orders.php` | The status state machine and who may move it |
| `cancellation.php` | Cancellation as a request somebody approves |
| `payment.php` | Naming how an order was paid (FPX / card / GrabPay) |
| `receipt.php` | E-receipt as HTML, PDF and email |
| `qrcode.php` | QR generation and the **signed** payload inside it |

### Catalogue

`spec.php` (specifications and selectable options), `product_photo.php`
(gallery), `image.php` (GD processing), `video.php`, `review.php`,
`wishlist.php`, `store.php`.

### Plumbing

`validation.php`, `paginate.php`, `ajax.php`, `batch.php`, `mailer.php`,
`address.php`, `listing_view.php`, `theme.php`.

---

## 5. Three flows worth being able to trace

### 5.1 Signing in

```
auth/login.php
  ├─ captcha_verify()          after 1 failure, a CAPTCHA appears
  ├─ login_guard.php           is this email currently locked?
  ├─ password_verify()         constant-time compare against the hash
  ├─ password_needs_rehash()   silently upgrade an old hash on success
  ├─ record_login_attempt()    written on EVERY attempt, pass or fail
  └─ login_user()  (lib/auth.php)
        ├─ session_regenerate_id(true)   defeats session fixation
        ├─ role_reset_cache()            drops permissions cached as a guest
        └─ theme_adopt_guest_choice()    carries a guest's dark-mode choice in
  → redirect to home_url_for_role()      each role lands where it can work
```

**The refusal order is deliberate.** Locked-out is checked *before* the
password, and a wrong password, a banned account and a non-existent email all
produce the same message — "Invalid email or password." Saying "no such
account" would turn the login form into a way to test which email addresses
are registered.

**Every attempt is recorded**, successes included. That is what makes
`admin/login_attempts.php` an audit trail rather than just a lockout list.

### 5.2 Placing an order

This is the longest path in the project and the one most likely to be asked
about, because money and stock both move.

```
cart.php                    quantities adjusted over AJAX (api/cart_update.php)
   ↓
checkout.php                address, voucher, points chosen
   │   builds a Stripe Checkout Session, payment_method_types:
   │   ['card', 'fpx', 'grabpay']
   ↓
   ⇢ the customer leaves the site entirely, to Stripe
   ↓
checkout_success.php        ← everything important happens here
```

`checkout_success.php`, in order:

1. **Retrieve the session from Stripe.** Never trust the query string — the
   browser has just come back from a third party.
2. **Check `payment_status === 'paid'`** and that `client_reference_id` is the
   signed-in member. A payment session belonging to someone else is refused.
3. **Duplicate guard** on `stripe_session_id`, which has a UNIQUE index. A
   refresh cannot create a second order.
4. **Ask Stripe how it was paid** — *before* the transaction opens, because
   this is a network call and the transaction below holds row locks.
5. **`beginTransaction()`**
   - `SELECT ... FOR UPDATE` on the cart rows, so two tabs cannot spend the
     same stock
   - re-price every line from the database — never from the browser
   - **re-validate the voucher and the points**; someone may have taken the
     last use while the customer was on Stripe's page
   - `INSERT` the order and its items, with prices and chosen options as
     **snapshots**
   - `deduct_stock()` per line — throws if another order took the last unit,
     which rolls the whole thing back
   - redeem voucher, spend points, award points
6. **`commit()`**
7. **Send the receipt** — *after* the commit, inside its own `try`, so a mail
   failure can never roll back a paid order.

The pattern to take away: **everything is recomputed server-side at the moment
of payment**, and anything that can fail without harming the order is done
outside the transaction.

### 5.3 Moving an order through fulfilment

```
pending ──▶ processing ──▶ shipped ──▶ delivered   (final: no change allowed)
   └──────────┴────────────┴──▶ cancellation requested
                                      ├─ approved  ──▶ cancelled
                                      └─ rejected  ──▶ nothing changes

cancelled ──▶ pending      a reinstated order restarts the queue
```

The allowed moves are declared once, in `ORDER_STATUS_TRANSITIONS`
(`lib/config.php`), and every check reads that constant. Nothing may skip a
step, and `delivered` is listed in `ORDER_FINAL_STATUSES` — it is the end.

Two screens can move an order: `admin/order_detail.php` and
`admin/qr_scan.php` (scan the code on the parcel). Both render the same
partial, `includes/status_actions.php`, and both call the same function:

```
update_order_status()   in lib/orders.php
  ├─ is this transition allowed by the SEQUENCE?   no skipping ahead
  ├─ does this role hold the permission for THIS transition?
  │     Vendor        pending → processing
  │     Delivery Man  processing → shipped → delivered
  ├─ does this transition require photographic evidence?
  └─ log_status_change()   who, when, from, to, and the photo
```

Permissions are **per transition**, not per page. A Vendor opening a shipped
order is told plainly that it is not theirs to move, rather than being offered
a button that will fail.

Cancellation is a **request**, never a direct status change
(`lib/cancellation.php`). It is raised, then approved by someone holding
`orders.approve_cancel`; only on approval does the order change and the stock
come back. Staff may only request a cancellation for an order that is
currently theirs to move forward — the rule falls out of the transitions their
role already holds, so it cannot drift.

---

## 6. Patterns that repeat everywhere

Recognise these six and most of the project reads itself.

**Ownership lives in the WHERE clause.**
`find_member_order($orderId, $userId)` is `WHERE id = ? AND user_id = ?`. Another
customer's order is never *fetched*, so it cannot be leaked by a branch added
later. Fetch-then-check rots the first time somebody adds a line above the
check.

**Prepared statements, always.** Values reach the database through `?`
placeholders and a separate array — never concatenated into the SQL string.
Even the `%` wildcards for `LIKE` go on the *value*, not into the query.

**`e()` on everything printed.** Escaping happens at output, not at input, so
the database holds what the user actually typed.

**CSRF on every POST.** `csrf_field()` in the form, `csrf_check()` in the
handler. GET reads, POST changes.

**Post/Redirect/Get.** A POST answers with a redirect; errors and old input
travel in the session and are restored by `prg_restore()`. `redirect_back()`
returns early when there are no errors — that is what lets the batch tools
render a preview on success.

**Optional features degrade, never crash.** `*_module_ready()` asks whether a
migration has been run. Missing ⇒ an explanation and a redirect, not a fatal
error about an unknown table in front of a marker.

---

## 7. Two modelling decisions you should be able to defend

**Points have no balance column; stock does.** Reward points are summed from
an append-only ledger every time. Stock keeps a running total in
`products.stock` *and* a movement ledger. The difference is read frequency —
stock is read on every product view, points only on the points page. The cache
buys speed and costs the possibility of disagreement, which is why
`admin/stock.php` carries a reconciliation report and `adjust_stock()` writes
both inside one transaction.

**Specifications use EAV, not columns.** A phone has RAM; a cable has length.
`spec_attributes` holds the definitions and `product_specs` the values, so a
new specification is an inserted row rather than an `ALTER TABLE`. The cost is
honest: reading specs is a join, and the database can no longer type-check the
values.

---

## 8. Where to look when something specific is asked

| Question | File |
|---|---|
| How are passwords stored? | `auth/register.php`, `lib/auth.php` |
| How do you stop SQL injection? | any `db_*` call; `admin/batch_delete.php` has the fullest note |
| How do you stop CSRF? | `lib/security.php` |
| How does authorisation work? | `lib/role.php`, then `AUTHORIZATION_TESTING.md` |
| Can a customer see another's order? | `order_detail.php` header comment |
| Why is that a transaction? | `admin/stores.php` (flagship swap) — the clearest example |
| How does the QR verification work? | `verify.php`, `lib/qrcode.php` |
| What happens if two people buy the last unit? | `checkout_success.php` step 5 |

`AUTHORIZATION_TESTING.md` contains console snippets that bypass the interface
entirely and are still refused. That is the strongest single thing to
demonstrate for the security marks.

---

## 9. Before you hand it in

See `PRE_SUBMISSION_AUDIT.md` for the full checklist. The three that block
submission:

- **`database/mobile2u.sql` does not exist** and section 7.0 requires it. The
  six core tables are in no migration, so a grader running the migrations alone
  gets nothing.
- **Delete `lib/config.php`** before zipping — it holds a live Stripe key and a
  Gmail App Password. A ZIP does not respect `.gitignore`.
- **Run migrations 25–30.**
