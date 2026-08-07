# Refactor notes

What changed, why, and the three things you must do before demonstrating.

---

## 1. Do this first

| # | Action | Why |
|---|--------|-----|
| 1 | Run `database/migration_password_reset.sql` in phpMyAdmin | Creates `password_resets` and adds `orders.stripe_session_id`. Both features degrade safely without it, but Password Reset will not work. |
| 2 | Open `lib/config.php` and set `STRIPE_SECRET_KEY` | Done — your test key is in place. |
| 3 | Run `composer require dompdf/dompdf` | Enables real PDF receipts. Optional — everything still works without it, you just get the printable page instead of a download. |
| 4 | Set `APP_DEBUG` to `false` in `lib/config.php` before submission | Hides PHP warnings from the marker. |

`MAIL_MODE` is set to `'dev'`, so the password reset link is shown on screen and
also written to `storage/mail.log`. Nothing needs an SMTP server. To send real
email instead: `composer require phpmailer/phpmailer`, fill in the `SMTP_*`
constants, and change `MAIL_MODE` to `'prod'`. No page code changes.

---

## 2. The base library

Everything now boots through one file. Every page starts with:

```php
require_once __DIR__ . '/lib/init.php';   // admin pages use admin_auth.php
```

| File | Responsibility |
|------|----------------|
| `lib/config.php` | All constants: DB credentials, upload paths, Stripe, mail, order statuses |
| `lib/init.php` | Hardened session start + loads the whole library |
| `lib/db.php` | The single shared PDO connection, plus `db_one/db_all/db_value/db_exec` |
| `lib/helpers.php` | Request helpers, escaping, errors, flash messages, **HTML helpers**, upload handling |
| `lib/validation.php` | Reusable server-side rules: `v_required`, `v_email`, `v_password`, `v_number`, ... |
| `lib/security.php` | CSRF token generation and checking, password-reset tokens |
| `lib/auth.php` | `require_login()`, `require_admin()`, `require_member()`, `require_guest()` |
| `lib/mailer.php` | `send_mail()` with the dev/prod switch |

`config/database.php` is now a one-line shim so nothing breaks if you miss an
old include somewhere.

### HTML helpers

Forms are generated, not hand-written:

```php
<?php field('email', 'Email Address', function () use ($user) {
    html_email('email', $user['email'], ['required' => true, 'maxlength' => 100]);
}, true); ?>
```

`field()` prints the label, the control, and the error message for that control.
The control automatically keeps the value the user typed after a failed submit
(`temp()`) and gains an `is-invalid` class when it has an error.

Available: `html_text`, `html_email`, `html_password`, `html_number`,
`html_textarea`, `html_select`, `html_file`, `html_hidden`, `html_submit`,
`html_button`, plus `err()`, `err_summary()`, `csrf_field()`.

### Shared partials

- `includes/product_card.php` - `render_product_card()` / `render_product_grid()`
- `includes/admin_rows.php` - `admin_member_rows()`, `admin_product_rows()`, `admin_order_rows()`

The admin listing pages call the same row function twice: once for the full page
and once for the AJAX search response, so the markup exists in exactly one place.

---

## 2b. Schema alignment (after seeing the real ERD)

Two things the code originally got wrong about your database:

**`orders.status` is lowercase.** The ENUM is
`enum('pending','processing','shipped','delivered','cancelled')`. MySQL compares
ENUMs case-insensitively, so inserting `'Pending'` silently stores `'pending'` and
looks fine — but PHP's `===` is case-sensitive, so `$order['status'] === 'Pending'`
was never true. That silently broke the "already in this status" check, the
selected `<option>` in the status dropdown, and the member Cancel Order button.
`ORDER_STATUSES` now holds the lowercase values; `ORDER_STATUS_LABELS` holds the
capitalised text used for display only.

**`users.status` existed but was never used.** It is now enforced:

- Login rejects `banned` with a clear message, and treats `deleted` exactly like a
  wrong password so the page cannot be used to probe for accounts
- `require_login()` re-reads the status on every request, so banning someone takes
  effect immediately instead of waiting for their session to expire
- Admin can Block / Unblock from both the member list and the member detail page,
  with a status filter on the listing — this covers the Section 4.4 module
  *Block + Unblock User Account (Admin)*
- An admin cannot change the status of their own account

The migration also uses `INT(11)` signed for `password_resets.user_id`, matching
your `users.id`. An unsigned foreign key would have been rejected by InnoDB.

---

## 3. Bugs fixed

| Bug | Where |
|-----|-------|
| Admin password change read/wrote a `password` column while the rest of the system uses `password_hash` - it could never succeed | `admin/profile.php` |
| Admin profile photo upload was a stub that showed a fake success message | `admin/profile.php` |
| `admin_header.php` never loaded jQuery, so the admin AJAX search in `main.js` never ran | `includes/admin_header.php` |
| Unauthenticated admin access redirected to `/login.php`, which does not exist | `admin/admin_auth.php` |
| `footer.php` closed the admin wrapper `div`s but was included by member pages | `includes/footer.php` |
| Header search posted to `/search.php`, which did not exist | `search.php` added |
| Sidebar linked to `/admin/dashboard.php`, which did not exist | `admin/dashboard.php` added |
| Catalogue ignored `status`, so deactivated products stayed visible to members | `products.php`, `product_detail.php` |
| Product photos were written to `assets/images/` but read from `assets/uploads/products/` | unified on `assets/uploads/products/` |
| Raw exception text was printed to the browser on a failed checkout | `checkout_success.php` |
| DB connection failure printed the PDO message to the browser | `lib/db.php` |
| Duplicate PDO connections with hardcoded credentials in both layout files | `includes/*header.php` |
| Order status compared with capitalised values against a lowercase ENUM | everywhere, see 2b |
| `users.status` column existed but nothing ever read it | login, guards, admin |

---

## 4. Security added

- **CSRF tokens on every POST form**, including the add-to-cart AJAX call
- **`session_regenerate_id(true)` on login** - closes session fixation
- Session cookie set to `HttpOnly` + `SameSite=Lax`
- Product activate/deactivate moved from a GET link to a POST form
- Uploads validated by real MIME type via `finfo`, renamed to a random filename,
  size-capped, and `assets/uploads/.htaccess` blocks script execution
- `lib/`, `config/` and `storage/` blocked from direct web access
- Login says "invalid email or password" for both cases; forgot-password always
  reports success - neither page can be used to enumerate accounts
- Password reset tokens are stored as SHA-256 hashes, expire in one hour, and are
  single-use; changing a password revokes any outstanding token
- Password rule raised to 8+ characters with a letter and a digit
- `password_needs_rehash()` upgrades old hashes silently on login
- Order creation is idempotent against the Stripe session id and verifies both
  `payment_status` and `client_reference_id` before writing anything

---

## 5. Conventions

- **Zero inline JavaScript.** All behaviour is jQuery in `assets/js/main.js` and
  `assets/js/admin.js`. Markup declares intent with `data-confirm`,
  `.js-auto-submit`, `.js-submit-form`, `.add-to-cart`.
- **Zero inline `style="..."`.** Everything moved into `style.css` /
  `admin_sidebar.css`.
- No `document.getElementById` / `querySelector` / `addEventListener` anywhere.
- Every query is a parameterised PDO prepared statement.
- Every page follows the PRG pattern and reports through flash messages.

---

## 6. Modules added along the way

Basic (were missing): **Password Reset**, **Order Detail (Member)**,
admin profile photo upload, admin dashboard.

Additional (Section 4.4): order cancellation by member with stock restoration,
**captcha integration**, **server-side image processing with GD**, **webcam photo capture**, **youtube product video with a privacy-first facade**,
**multiple product photos with a hand-written slider**,
**drag-and-drop photo upload**,
**product rating and reviews with verified purchase**,
**product stock handling with a movement ledger**, **reward point handling**, **temporary login blocking**, **discount voucher handling**, **add to favorites / wishlist**, **shipping address handling with an address book**, **e-receipt by email and PDF**, **order cancellation by member with reason capture**,
**order status update with a workflow state machine and audit trail**,
**admin maintenance + CRUD**,
**block + unblock user account (admin)**,
product filtering by category and by price range, combined filtering + sorting +
paging, low-in-stock alert, top 5 selling products, hand-coded data charts on the
dashboard, related products, AJAX integration, permanent shopping cart, category
maintenance CRUD, payment via the real Stripe API.

---

## 6b. Admin Maintenance + CRUD

New files: `admin/admins.php` (list) and `admin/admin_form.php` (add / edit).
Row markup lives in `admin_admin_rows()` in `includes/admin_rows.php`, so the full
page and the AJAX search render through the same function. A sidebar entry and a
dashboard tile were added, and form pages now highlight the listing they belong to.

**Delete is a soft delete.** `orders.user_id` has a foreign key to `users`, so
removing the row would either be rejected by InnoDB or orphan order history.
Setting `status = 'deleted'` blocks the login, keeps every existing record intact,
and can be undone - filter the listing by *Deleted* and press **Restore**.

Guard rails, all enforced server-side:

- The **last active administrator** cannot be blocked, deleted, or set to a
  non-active status. The listing shows a warning banner while only one exists.
- You **cannot block or delete your own account** from the listing, and the status
  field is locked to Active when you edit yourself.
- Email uniqueness is checked against every user, admins and members alike.
- Password is required when adding, optional when editing - leave both fields
  blank to keep the existing one. Changing it revokes any outstanding reset link.
- Deleted accounts are hidden from the listing by default.

While building this I hit a name clash worth recording: `includes/admin_header.php`
was assigning `$admin = current_user()`, which silently overwrote the record a page
was editing, because the layout is included *after* the page loads its data. The
layout's variables are now `$layout_user`, `$layout_name`, `$layout_avatar`,
`$layout_nav` and `$layout_page`, so a page is free to use `$admin` or `$user` for
its own data.

---

## 6c. Order Cancellation (Member) + Order Detail (Member)

Two things shipped together, because the cancellation flow needs somewhere to live.

**`order_detail.php` was missing entirely.** Section 4.3 lists *Order History +
Detail (Member)* as a **basic** module, and only the admin had a detail page. The
member now has one, with the items table, shipping address, status, and the
cancellation entry point.

**New files**

| File | Purpose |
|------|---------|
| `lib/orders.php` | All order logic: `member_can_cancel()`, `cancel_order()`, `uncancel_order()`, `restore_order_stock()`, `deduct_order_stock()`, `order_lines()` |
| `includes/order_parts.php` | `render_order_lines()` and `render_cancellation_panel()`, shared by member and admin pages |
| `order_detail.php` | Order Detail (Member) |
| `order_cancel.php` | The cancellation confirmation screen |

**Policy** — configured in `lib/config.php`, not hardcoded in a page:

- `MEMBER_CANCELLABLE_STATUSES = ['pending', 'processing']` — a member may cancel
  until the order ships. `shipped`, `delivered` and `cancelled` are refused with a
  specific message rather than a generic "not allowed".
- `CANCEL_REASONS` is the dropdown. Choosing **Other reason** makes the free-text
  note compulsory — enforced on the server, with jQuery only guiding the user.

**Why a whole page instead of a `confirm()` dialog:** the member sees exactly which
items and which total they are cancelling, has to pick a reason, and has to tick an
acknowledgement. It is also far easier to demonstrate and screenshot than a browser
dialog. The status is re-checked on both GET and POST, so a stale tab cannot slip a
shipped order through.

**Stock restoration is shared.** `admin/orders.php` now calls the same
`cancel_order()` / `uncancel_order()` as the member pages instead of carrying its
own copy of the loop, so both routes behave identically. Un-cancelling deducts the
stock again and fails loudly if there is no longer enough.

`orders.cancel_reason`, `cancel_note`, `cancelled_at` and `cancelled_by` come from
the migration. Every read is written as `$order['cancel_reason'] ?? null`, so the
pages still work if the migration has not been run — the panel just shows less.

---

## 6d. Order Status Update (Admin)

A basic dropdown existed, but it let an admin move an order **backwards**
(delivered back to pending) and kept no record of who changed what. Both fixed.

**Transition rules** live in `ORDER_STATUS_TRANSITIONS` in `lib/config.php`:

| From | May move to |
|------|-------------|
| `pending` | Processing, Shipped, Cancelled |
| `processing` | Shipped, Cancelled |
| `shipped` | Delivered |
| `delivered` | *(nothing - final state)* |
| `cancelled` | Pending, Processing *(reinstate)* |

Orders move forward only. Cancelling is allowed just up to dispatch, and a
cancelled order can be reinstated. The dropdown is **built from this map**, so
illegal options are never rendered — and `update_order_status()` re-checks on the
server, so a hand-crafted POST is refused with a message naming the legal steps.
Change the workflow by editing that one map; nothing else needs touching.

**Audit trail** — new `order_status_history` table records every change: from, to,
who, their role, an optional note, and the timestamp. Rendered as a vertical
timeline by `render_status_timeline()` in `includes/order_parts.php`:

- the admin order detail page shows the full trail including staff names
- the member order detail page shows the same progress without internal identities

**One function, one place.** `update_order_status()` in `lib/orders.php` applies the
transition check, moves stock when needed, and writes the audit entry, all inside a
transaction. The listing page and the detail page both call it, so the two routes
cannot drift apart. Member cancellation keeps its own stricter rule set
(`MEMBER_CANCELLABLE_STATUSES`) but shares the same `cancel_order()` underneath.

Admins can now attach a **note** to any status change (courier tracking number,
reason for cancelling). Previously the admin cancel reason was hardcoded to
"Cancelled by store staff."

Both `order_status_history` and the cancellation columns are guarded by
`status_history_ready()` / `cancellation_columns_ready()`, so the workflow still
runs if the migration has not been applied - it just records less.

---

## 6e. E-Receipt (Email + PDF)

**New files**

| File | Purpose |
|------|---------|
| `lib/receipt.php` | Receipt numbering, HTML rendering, PDF rendering, email delivery |
| `includes/receipt_template.php` | The receipt itself - one template, three destinations |
| `receipt.php` | Entry point: printable page, PDF download, and email trigger |

**One template, three destinations.** `includes/receipt_template.php` is rendered
into the printable page, into the PDF via Dompdf, and into the email body. Because
it is one file the three can never drift apart.

That template is the **one place in the project that uses inline `style=`**, and it
is deliberate: Dompdf supports only a subset of CSS and email clients strip
`<style>` blocks entirely, so inline attributes are the only thing all three render
identically. Everywhere else still has zero inline styling.

**PDF degrades instead of breaking.** `pdf_engine_available()` checks for Dompdf.
When it is missing the Download PDF button is not rendered at all, `?mode=pdf`
falls through to the printable page with a note explaining the composer command,
and the email simply goes out without an attachment. Install it with:

```
composer require dompdf/dompdf
```

**Delivery** reuses the existing `send_mail()` dev/prod switch, now extended to take
attachments. In `dev` mode the message lands in `storage/mail.log` and the PDF in
`storage/receipts/`, so the whole flow is demonstrable with no SMTP server at all.

**When it is sent**

- automatically after a successful checkout, **after** the database commit so a mail
  failure can never roll back a paid order
- manually from the member order detail page ("Email It To Me")
- manually from the admin order detail page ("Email To Customer")

Resends are capped by `RECEIPT_MAX_SENDS`, and `orders.receipt_sent_at` /
`receipt_sent_count` record the history, shown on both detail pages.

**Receipt numbers** are `MU-2026-000042`, generated on first use and stored in
`orders.receipt_no` under a unique key. The migration backfills every existing
order so old sample data can also produce a receipt.

**Access control:** a member can only reach their own receipt; an admin can reach
any. Emailing is state-changing, so it is a CSRF-protected POST, never a GET link.
`storage/receipts/` is blocked from direct web access — PDFs are only ever served
through `receipt.php` after the ownership check.

---

## 6f. Shipping Address Handling

Before this, the shipping address was whatever Stripe collected on its own page,
flattened into a text blob. There was no address book and no validation of our own.

**New files**

| File | Purpose |
|------|---------|
| `lib/address.php` | Address book CRUD, validation, default handling, formatting |
| `includes/address_parts.php` | `render_address_card()` / `render_address_list()`, shared by the book and checkout |
| `member/addresses.php` | The address book: list, set default, delete |
| `member/address_form.php` | Add / edit an address |

**Snapshot, not a foreign key lookup.** This is the important design decision.
`orders.shipping_address` keeps the full formatted text exactly as it was at the
moment of ordering, and `orders.shipping_address_id` is only a soft reference with
`ON DELETE SET NULL`. If a member later edits or deletes an address, **past orders
must not change** — they record where the parcel actually went. Normalising this
away would silently rewrite history.

**Checkout is now two steps.** `checkout.php` became a proper review screen: order
items, totals, and an address picker with the default pre-selected. Only pressing
*Place Order & Pay* creates the Stripe session. This also fills a gap — there was
no confirmation screen at all before, the cart button went straight to Stripe.

`shipping_address_collection` was removed from the Stripe session because we now
own that data. The chosen address travels to the success callback in **Stripe
metadata**, not the session, so it survives a lost session or a different tab.
`checkout_success.php` re-checks ownership of that address id rather than trusting
what came back over the wire.

**Validation** is real, not decorative:

- Malaysian postcode must be exactly 5 digits
- Phone is normalised to digits and checked against `^(60|0)[0-9]{8,10}$`
- State must be one of the 16 states and federal territories in `MY_STATES`
- Label must be one of `ADDRESS_LABELS`
- Maximum `ADDRESS_MAX_PER_USER` saved addresses, enforced server-side and not
  merely by hiding the button

**Exactly one default.** `set_default_address()` clears every other row and sets
the new one inside a transaction, so two defaults cannot coexist. The first address
a member saves becomes the default automatically, and deleting the default promotes
another one so there is always something pre-selected at checkout.

The whole module is guarded by `address_module_ready()`, so the site still runs
before `database/migration_08_address.sql` has been applied.

---

## 6g. Add to Favorites / Wishlist

**New files**

| File | Purpose |
|------|---------|
| `lib/wishlist.php` | Toggle, count, listing, cached lookup |
| `api/wishlist_action.php` | AJAX endpoint for the heart button |
| `member/wishlist.php` | The wishlist page |

**The heart is everywhere the product is.** It lives in
`includes/product_card.php`, so it appears on the catalogue, search results,
member home, related products and the wishlist itself from that one edit.
Product detail gets a wider version beside Add to Cart.

**One query for a whole grid.** `wishlist_product_ids()` loads the member's saved
ids once per request into a static cache. Rendering twelve product cards therefore
costs one query, not twelve. `is_wishlisted()` is then a plain array lookup.

**The database enforces uniqueness, not PHP.** `UNIQUE(user_id, product_id)` means
a double click cannot create two rows. `toggle_wishlist()` issues a DELETE first
and inserts only when zero rows were removed, so the toggle is a single decision
against the database rather than a check-then-write with a race in the middle.

**The icon never lies.** The heart flips only *after* the server confirms, so it
can never disagree with what was actually stored. Every heart on the page for the
same product updates together, and a `data-busy` flag stops a double click firing
two requests.

**The wishlist page is honest about stock.** Items that went out of stock or were
deactivated are greyed out and labelled rather than silently dropped. *Move All to
Cart* moves what it can and reports how many it skipped and why, instead of
pretending everything worked.

Guests see "Log in to save this" rather than a heart that fails when clicked.
The whole module is guarded by `wishlist_module_ready()`.

Font Awesome was added to the storefront layout for the heart icon. It was already
loaded in the admin layout, and it is an icon font rather than a CSS framework, so
it stays within the assignment's restrictions.

---

## 6h. Discount Voucher Handling

**New files**

| File | Purpose |
|------|---------|
| `lib/voucher.php` | Validation, discount calculation, redemption, release |
| `api/voucher_action.php` | AJAX apply / remove |
| `admin/vouchers.php` | Voucher listing with usage stats |
| `admin/voucher_form.php` | Create / edit a voucher |

**The discount never comes from the browser.** Only the *code* is kept in the
session. The amount is recalculated from the database on every render and again at
the moment of payment. `api/voucher_action.php` reads the subtotal with its own
`SUM(p.price * c.quantity)` query rather than accepting one from the request, so a
tampered form field buys nothing.

**Two limits, both enforced against races.**

- *Total usage* is claimed with a conditional UPDATE:
  `SET used_count = used_count + 1 WHERE id = ? AND (usage_limit IS NULL OR used_count < usage_limit)`.
  Zero affected rows means someone else took the last use, and the whole
  transaction rolls back rather than creating a discounted order.
- *Per member* is counted from `voucher_redemptions`, which has
  `UNIQUE(order_id)` so one order can never carry two vouchers.

**Revalidated after payment.** Between the Stripe page and the success callback the
last remaining use may have been taken. `checkout_success.php` runs the full check
again and redeems inside the same transaction that creates the order.

**Stripe sees the same figure we do.** The discount is sent as a one-off
`\Stripe\Coupon` with `amount_off`, attached via `discounts`, rather than shaving
the line items. The customer sees the identical breakdown on Stripe's page, and
per-item rounding cannot drift away from our total.

**Cancelling gives the voucher back.** `cancel_order()` calls `release_voucher()`,
which decrements `used_count` and deletes the redemption row, so a cancelled order
does not silently consume a customer's one allowed use.

**Validation that prevents free money.** A *fixed* discount larger than the minimum
spend is refused at the admin form, because it would let an order reach zero.
Percentage vouchers take an optional cap. `calculate_discount()` also clamps the
result to the subtotal, so no arithmetic path can produce a negative total.

**History is preserved.** `orders` stores `subtotal`, `discount_amount`,
`voucher_id` **and** `voucher_code` as a text snapshot. Editing or deleting a
voucher later never rewrites what a past receipt says was used. A voucher that has
been redeemed cannot be deleted at all, only disabled.

The migration seeds five demo vouchers including a deliberately **expired** one and
a deliberately **used up** one, so the rejection paths can be demonstrated rather
than only the happy path.

---

## 6i. Temporary Login Blocking (3 Attempts)

**New files**

| File | Purpose |
|------|---------|
| `lib/login_guard.php` | Attempt recording, lock evaluation, unlock, pruning |
| `admin/login_attempts.php` | Who is locked, release a lock, full audit trail |

**Two counters, not one.**

- *Per email* locks after `LOGIN_MAX_ATTEMPTS` (3) failures inside a
  15 minute window. Stops a brute force aimed at one account.
- *Per IP* blocks after `LOGIN_MAX_ATTEMPTS_PER_IP` (10) failures. Stops one
  machine working through many accounts. The threshold is higher on purpose,
  because a campus or lab network shares an address between many people.

**Failures are recorded for addresses that do not exist.** This matters. If only
real accounts were tracked, "does this address get locked?" would answer "is this
address registered?" — turning the lockout into an account-enumeration oracle.

**The lock is checked before the password is verified.** A locked account cannot be
tested even by someone holding the correct password, so the lock cannot be used to
confirm a guess. The blocked attempt is still recorded, so hammering a locked
account is neither free nor invisible.

**A success resets the counter without a DELETE.** The failure query only counts
attempts made *after* the most recent successful sign-in for that email. There is
no separate reset step that could be forgotten or fail halfway.

**The IP comes from `REMOTE_ADDR` only.** `X-Forwarded-For` is deliberately
ignored: without a known trusted proxy in front, honouring it would let an attacker
reset their own counter by sending a different header each request.

**Honest feedback without leaking.** The member sees how many attempts remain and a
live countdown to unlock, but the failure message stays "Invalid email or
password" — it never says which half was wrong. The lockout panel points at the
password reset, which still works while the account is locked.

The countdown is jQuery and disables the button, but that is presentation only.
The server refuses a locked login regardless of what the timer shows.

**Admin side:** `admin/login_attempts.php` lists currently locked accounts, shows
24-hour statistics, offers a manual Unlock for a genuinely stuck user, and keeps a
searchable audit of every attempt with IP and browser. Records older than
`LOGIN_ATTEMPT_RETENTION_DAYS` can be pruned.

All thresholds live in `lib/config.php`.

---

## 6j. Reward Point Handling

**New files**

| File | Purpose |
|------|---------|
| `lib/points.php` | Ledger, balance, conversion, redemption, reversal |
| `api/points_action.php` | AJAX apply / remove at checkout |
| `member/points.php` | Balance, the rules, and the full history |

**A ledger, not a balance column.** This is the decision worth defending. There is
deliberately no `users.points_balance`; the balance is always
`SUM(points) FROM point_transactions`. A cached total drifts the first time one
update half-fails, and once it has drifted **nothing can say which entry was
wrong**. An append-only ledger is self-correcting and every point has a source, a
time and a reason. `balance_after` is stored purely so the history reads nicely —
no calculation ever reads it.

**Double awarding is impossible.** `UNIQUE(order_id, type)` means one order can
produce at most one `earn` row and one `redeem` row. Refreshing the success page or
a retried callback simply hits the constraint and is ignored, rather than minting
points.

**Points are awarded on what was actually paid**, not on the pre-discount subtotal.
Otherwise a member could stack a voucher to farm points on money they never spent.

**Order of operations at checkout:** voucher first, then points on the remainder.
The other way round would let a percentage voucher discount an amount the member
had already covered with points.

**Three independent caps on redemption**, all re-checked server side: the member's
balance, `POINTS_MAX_REDEEM_PERCENT` (50%) of the order, and the order total
itself. Redemption is in whole blocks of 100, so the money value is always exact
and never a fraction of a sen.

**Revalidated after payment.** The balance may have changed while the member was on
Stripe's page, so `checkout_success.php` re-runs the full check and spends the
points inside the same transaction as the order. An insufficient balance throws and
rolls the whole order back rather than allowing an overspend.

**Cancelling unwinds both directions.** `reverse_order_points()` removes what the
order earned *and* returns what it spent, each as its own mirror-image ledger entry
rather than by editing history.

**Stripe sees one figure.** The voucher discount and the points discount are
combined into a single one-off coupon, so the amount charged matches our summary
exactly and no rounding can drift between the two.

**Admin side:** the member detail page shows the balance, the last 20 ledger
entries, and a manual adjustment form for goodwill credits. Adjustments require a
reason, are attributed to the admin who made them, and cannot take a balance below
zero.

---

## 6k. Product Stock Handling

**New files**

| File | Purpose |
|------|---------|
| `lib/stock.php` | Every read and write of stock, plus classification and reporting |
| `admin/stock.php` | Stock overview, adjustments, movement history, reconciliation |

**Stock was written in four different places** before this: `checkout_success.php`,
two functions in `lib/orders.php`, and nowhere else recorded *why* it changed. All
of them now call `lib/stock.php`, so there is exactly one place that touches
`products.stock` and every change lands in the ledger.

### The design choice worth explaining

`products.stock` stays the authoritative value and `stock_movements` is the audit
trail. That is **deliberately the opposite** of `lib/points.php`, where the balance
is `SUM(points)` with no cached column.

The reason is concurrency. Selling has to be atomic:

```sql
UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?
```

The check and the decrement are one statement, so two members buying the last unit
at the same instant cannot both succeed — the second gets zero affected rows and
their transaction rolls back. If the quantity had to be derived with `SUM()`, we
would have to read first and write second, and the gap between them is exactly the
race we are trying to close.

Redeeming points has no such race: it happens once per checkout, inside a
transaction, for one member.

The price of keeping a column is that it could drift from the ledger, so
`stock_reconciliation()` compares the two and the stock page reports any mismatch
rather than silently correcting it. A mismatch is a bug worth investigating, not
something to paper over.

### Other changes

- **Per-product reorder level.** `products.reorder_level` replaces the hardcoded 5.
  A flagship phone and a phone case do not have the same sensible low-water mark.
  The dashboard alert, product list, wishlist and storefront badges all read it.
- **Editing a product no longer silently rewrites stock.** Changing the quantity on
  the product form now records a `adjust` movement with a reason, so a correction
  made there is as traceable as one made in Stock Control.
- **Adjustments take a direction plus a positive quantity** rather than asking an
  admin to type a minus sign, and going below zero is refused by the same
  conditional-update trick used for sales.
- **Movement types** distinguish sale, return, restock, correction, damage and
  opening stock, so the history reads as a story rather than a list of numbers.

---

## 6l. Product Rating + Review

**New files**

| File | Purpose |
|------|---------|
| `lib/review.php` | Eligibility, reading, writing, moderation |
| `includes/review_parts.php` | Stars, rating summary, review card, star picker |
| `review_form.php` | Write or edit a review |
| `member/reviews.php` | My reviews, plus what is still awaiting one |
| `admin/reviews.php` | Moderation |

**Verified purchase is the whole point.** A member can only review a product they
actually received. That is not a flag we set — it is a JOIN against their own
orders:

```sql
FROM orders o JOIN order_items oi ON oi.order_id = o.id
WHERE o.user_id = ? AND oi.product_id = ? AND o.status IN ('shipped','delivered')
```

It is checked when rendering the page, again on POST, and once more inside
`save_review()`, so the write path is safe even if a page is bypassed entirely.
Every review therefore carries a genuine "Verified purchase" badge.

**One review per member per product**, enforced by `UNIQUE(user_id, product_id)`.
Members edit their existing review rather than posting again, so a product cannot
be flooded from one account.

**The rating range is enforced by the database**, not only PHP:
`CHECK (rating BETWEEN 1 AND 5)`.

**No cached average.** There is deliberately no `products.rating_avg`. Reviews are
written rarely and read as an aggregate, so `AVG()` is both fast enough and always
correct. This is the third time this project has faced the cached-value question
and the answer differs each time for a stated reason:

| Module | Approach | Why |
|--------|----------|-----|
| Reward points | `SUM()`, no column | Auditability matters most; no concurrent race |
| Stock | Column is authoritative, ledger audits it | Needs an atomic conditional decrement |
| Ratings | `AVG()`, no column | Rarely written, no race, aggregate is cheap |

For the catalogue the aggregate comes from **one derived table joined once**, not a
query per product, so sorting by rating and filtering by "4 stars and up" cost the
same as any other listing.

**Moderation hides, it does not delete.** A hidden review still belongs to the
member who wrote it, hiding is reversible, and the member sees that it was hidden
along with any note the admin left. The page states explicitly that a low rating is
not by itself a reason to hide anything.

**Star picker is progressive enhancement.** The markup is real radio buttons that
work without JavaScript; jQuery adds the hover preview and the label.

---

## 6m. Drag-and-Drop Photo Upload

No migration for this one — it is a UI module.

**New files**

| File | Purpose |
|------|---------|
| `includes/dropzone.php` | `render_dropzone()`, the reusable field |
| `assets/js/dropzone.js` | The jQuery component: drag, drop, preview, progress |

**One component, five places.** Admin product photo, admin profile photo, new
admin photo, member profile photo, and the optional photo at registration all use
`render_dropzone()`. The three separate preview handlers that existed before
(`#photoInput`, `#productImageInput`, `#adminPhotoInput`) were **deleted**, not left
alongside, so nothing fights over the same elements.

### The design decision worth defending

The dropped file is **assigned to the real `<input type="file">` and submitted with
the form**. It is *not* uploaded to a staging folder the moment it is dropped.

An upload-on-drop design creates an orphan file on the server every time someone
drops a photo and then abandons the form — and then needs a scheduled cleanup job
to find those files again. Assigning to the input means:

- the existing server-side validation and storage run completely unchanged
- a cancelled form leaves nothing behind
- there is no temp directory to secure or sweep

Assigning to `input.files` requires the `DataTransfer` API, so the script feature
detects it. Where it is missing the zone degrades to click-to-browse and says so,
rather than accepting a drop that would silently do nothing.

**The progress bar is real, not simulated.** When a form containing a dropzone is
submitted, `dropzone.js` sends it through `XMLHttpRequest` and reports
`xhr.upload.onprogress`. The server sees an ordinary multipart POST either way.

**Browser validation is for feedback only.** The zone checks type and size
immediately so the user is not left waiting, but `save_uploaded_image()` still
reads the real MIME type with `finfo` on the server. A renamed `.php` file passes
the browser check and fails the server one, which is the check that matters.

**Two details that are easy to get wrong**, both handled:

- `dragover` must call `preventDefault()` or the `drop` event never fires at all.
- Dropping a file **outside** the zone makes the browser navigate away to it and
  lose the whole form, so there is a document-level handler cancelling that.

**Progressive enhancement.** The markup is a plain file input; the script only
hides it after it has attached itself (`.is-enhanced`). With JavaScript off every
upload form still works exactly as it did before.

---

## 6n. 1 Product = Multiple Photos

This one covers three of the Section 4.4 examples at once: **1 Product =
Multiple Photos**, **Multiple Photos Upload**, and **Product Photos Sliders
(Dynamic)**.

**New files**

| File | Purpose |
|------|---------|
| `lib/product_photo.php` | Gallery reads and writes, and the one sync point |
| `admin/product_photos.php` | Upload several, drag to reorder, set cover, delete |
| `includes/product_gallery.php` | The storefront slider |

### Why `products.image` survived

The obvious move is to delete `products.image` and join `product_photos`
everywhere. It was kept, deliberately, as a denormalised pointer to the cover
photo — because the cart, orders, wishlist, receipt, admin listings and email all
read it, and every one of them wants exactly one thumbnail. Joining a gallery in
all of those places is more code and more queries for no gain.

A cached column drifts, so the mitigation is that **exactly one function writes
it**: `sync_primary_photo()`. Every mutation in `lib/product_photo.php` ends by
calling it. While finishing this I found `admin/product_form.php` was still writing
`image` on edit, which would have made that claim false, so that write is now
suppressed once the gallery module is installed. A grep for `image = ?` returns one
real writer.

### Other decisions

- **The first photo becomes the cover automatically**, and deleting the cover
  promotes the next one, so a product is never left without one.
- **Files are deleted only after the row is gone**, and only when no other row
  still references the same filename.
- **An upload that breaks the photo limit deletes the file it just wrote** rather
  than leaving it orphaned on disk.
- `$_FILES` for `photos[]` arrives as parallel arrays. Rather than rewriting the
  upload helper, the loop rebuilds a single-file structure per iteration so
  `save_uploaded_image()` runs unchanged — same validation, same MIME check.

### The slider is hand-written

No carousel library: it is a list of slides plus an active index, about forty lines
of jQuery. The brief awards more for code you can explain, and there is nothing
here that needs a dependency. Arrow keys work, but only while the gallery has
focus, so they do not hijack the page for someone scrolling past.

**Without JavaScript it still works.** The thumbnails are anchors to each slide and
`.gallery-slide:target` reveals the matching one, so every photo remains reachable.

### Reordering

Native HTML5 drag and drop on the tiles. The new order is written into a hidden
field and saved by an ordinary CSRF-protected POST — the drag is presentation, the
save is a normal form submit. `reorder_product_photos()` puts `product_id` in the
WHERE clause, so an id belonging to another product simply does nothing.

---

## 6o. Product Video Integration (YouTube)

**New files**

| File | Purpose |
|------|---------|
| `lib/video.php` | URL parsing, validation, embed and thumbnail URLs |

The video becomes one more slide at the end of the existing gallery, so it reuses
the arrows, the counter and the thumbnail strip built in the previous module rather
than adding a second player somewhere else on the page.

### Only the video ID is stored

An admin can paste any YouTube address — `watch?v=`, `youtu.be`, `/embed/`,
`/shorts/`, `/live/`, with or without `&t=` and tracking parameters — and
`parse_youtube_id()` normalises it to the 11-character id before anything is saved.

That is a security decision as much as a tidiness one. Storing the raw string would
mean putting whatever somebody typed directly into an `iframe src`, which is an
injection hole. The id is matched against `^[A-Za-z0-9_-]{11}$` on the way in and
again before it is rendered, so only a valid id can ever reach the page.

The edit form shows the id back as a full watch URL, because that is the shape
people recognise and paste.

### The facade pattern

**No iframe exists on page load.** The gallery renders a poster image with a play
button; the iframe is created by jQuery only when the visitor presses play.

That means a product page with a video makes **no request to YouTube, loads no
third-party JavaScript and sets no cookie** unless the video is actually watched.
An embedded YouTube iframe normally pulls several hundred kilobytes of script on
every page view, for a video most visitors never play. The page also states this
under the poster, so the behaviour is visible rather than hidden.

`youtube-nocookie.com` is used for the embed, which is YouTube's privacy-enhanced
host, and the iframe is given `referrerpolicy="strict-origin-when-cross-origin"`.

### Two details

- **The poster is a remote image.** If the network is unavailable it would show as
  broken, so a jQuery `error` handler swaps in the product's own photo. Useful for
  an offline demonstration.
- **Sliding away from the video stops it.** Otherwise audio keeps playing from a
  slide the visitor has already moved past. The slider fires a `gallery:change`
  event and the video handler listens for it.

---

## 6p. Webcam Integration (Capture Photo)

No migration — the captured photo goes into the existing upload path.

**New files**

| File | Purpose |
|------|---------|
| `includes/webcam.php` | The capture dialog, rendered once per page |
| `assets/js/webcam.js` | getUserMedia, capture, and handing the file over |

### The captured photo is a real File, not a base64 string

`canvas.toBlob()` produces genuine JPEG bytes, which are wrapped in a `File` and
assigned to the dropzone's existing `<input type="file">` through `DataTransfer`.

The alternative — POSTing a base64 data URL to a new endpoint — would mean a second
upload path with its own validation to keep in step with the first. This way the
photo is an ordinary multipart upload: `save_uploaded_image()` runs unchanged, the
`finfo` MIME check still applies, the size limit still applies, and there is no new
endpoint to secure.

It also works everywhere the dropzone works, for free: product photos, member
avatars, admin avatars, registration.

### The demo-day trap, handled in the UI

`getUserMedia` **only works in a secure context** — HTTPS or `http://localhost`.
Opening the site by LAN address such as `http://192.168.1.5` is refused by the
browser, with an error that looks like a bug in the code.

`window.isSecureContext` is checked *before* requesting the camera, and the dialog
explains exactly what is wrong and what to open instead. Worth knowing before the
demonstration rather than during it.

### Other things handled

- **Every failure has its own message.** `NotAllowedError` (permission refused),
  `NotFoundError` (no camera), `NotReadableError` (another app such as Zoom has
  it), `OverconstrainedError` — each says what to actually do.
- **The camera is always released.** `stopCamera()` stops every track and clears
  `srcObject`, on close, on Escape, on backdrop click, and on `visibilitychange`
  when the tab goes to the background. Otherwise the camera light stays on after
  the dialog is gone, which users notice and distrust.
- **A camera picker appears only when there is more than one device**, populated
  from `enumerateDevices()`.
- **The preview is mirrored** because posing to an unmirrored image feels wrong,
  and the capture is mirrored to match, so the photo is what the person saw. A
  toggle turns it off.
- **Optional 3 second timer** for anyone framing a shot by themselves.
- The camera button is added by the script, so a browser without `getUserMedia`
  never sees an option that would fail.

---

## 6q. Image Processing (Flip, Rotate, etc.)

No migration — the operations rewrite files that already exist.

**New files**

| File | Purpose |
|------|---------|
| `lib/image.php` | GD operations, EXIF auto-orientation, capability probing |
| `admin/photo_edit.php` | The editor for one product photo |

### The processing is real

Rotate, flip, grayscale, brighten, darken and sharpen all run in PHP through GD
and **change the bytes on disk**. That was the point of doing it server side rather
than as a CSS `transform` on the way out: the edited photo is the same everywhere
it appears — listings, cart, receipts, the PDF and the email.

Sharpen is a 3&times;3 convolution kernel whose values sum to 1, so overall
brightness is unchanged while the negative neighbours exaggerate edges.

### Every operation writes a NEW file

This looks wasteful until you try the alternative. Overwriting the original
filename is correct on the server, but the browser keeps showing its cached copy,
so the edit appears to have failed until a hard refresh. Writing a new name
sidesteps caching completely.

The sequence is deliberate: write the new file, point the gallery row at it, call
`sync_primary_photo()`, and **only then** delete the old file — and only if no
other row still references it. If anything fails partway, the original is still
there.

### EXIF auto-orientation on upload

Phone cameras usually store the sensor image as-is and record how the phone was
held in an EXIF tag. Browsers honour that tag; **GD does not**. So a photo that
looks upright in the file manager turns sideways the moment it is processed.

`auto_orient_image()` now runs inside `save_uploaded_image()`, so the rotation is
baked in at upload time and every later operation starts from an image that is
genuinely the right way up. Saving through GD also drops the EXIF block, so the
tag cannot be applied a second time by something else.

That single fix is why rotating a phone photo behaves predictably instead of
jumping 90 degrees the first time it is touched.

### Restoring the original

The first edit **promotes the current file to be the kept original** and leaves it
on disk; every edit after that replaces only the intermediate file. So a photo has
at most two files however many times it is adjusted — the untouched original and
whatever it currently looks like.

`product_photos.original_filename` carries the whole state:

| Value | Meaning |
|-------|---------|
| `NULL` | Never edited. `filename` *is* the original. |
| set | Edited. This is the file that will never be touched. |

Using NULL rather than a separate `is_edited` flag means there is no second field
that could disagree with reality.

**Restore** points `filename` back at the original, sets `original_filename` to
NULL — returning the row to the "never edited" state — and only then deletes the
edited file. It refuses if the original is missing from disk rather than leaving
the row pointing at nothing.

Deleting a photo now removes **both** files. Without that, deleting an edited photo
would leave its original orphaned on the server forever. Every deletion goes
through `photo_file_in_use()`, which checks `filename` **and** `original_filename`
across all rows, so a file another photo still references is never removed.

The editor shows the original and the current version side by side once a photo has
been edited, and the gallery marks edited photos with a badge.

### Smaller details

- **Transparency survives rotation.** Rotating a PNG needs an alpha background
  colour or the new corners come out solid black. JPEG has no alpha, so it falls
  back to white.
- **The file type comes from `getimagesize()`**, never the extension.
- **`basename()` on the filename** stops a crafted value escaping the upload folder.
- **GD resources are freed in a `finally`**, so a failure does not leak memory.
- The page reports exactly which GD features this PHP build has, and the
  environment check on the diagnostics page now includes GD.

---

## 6r. CAPTCHA Integration (3rd-Party Library)

Run `composer require gregwar/captcha`. No migration.

**New files**

| File | Purpose |
|------|---------|
| `lib/captcha.php` | Driver switch, challenge generation, verification |
| `api/captcha_image.php` | Serves the challenge PNG |
| `includes/captcha_field.php` | Renders whichever field the driver needs |

### Library, not service

`CAPTCHA_DRIVER` switches between `image` (gregwar/captcha), `recaptcha` (Google
v2) and `off`, the same shape as `MAIL_MODE`.

The default is **`image`**, deliberately. reCAPTCHA is a third-party *service*: it
needs a working internet connection at the moment of the demonstration and keys
tied to a domain. A composer package that draws the image on this server is still
a third-party library, works offline, and cannot fail because of the Wi-Fi in the
room. reCAPTCHA is fully implemented and one config line away if you prefer it.

### The answer never reaches the browser

Only `hash('sha256', ...)` of the phrase goes into the session. Reading the page
source, or the session file, does not reveal the answer. Comparison uses
`hash_equals()`.

**One use, right or wrong.** The challenge is unset from the session as soon as it
is checked, so a captured image cannot be replayed and a wrong guess costs a
reload. The endpoint also sends `Cache-Control: no-store`, or the browser would
show a stale image while the session already held a different answer.

Look-alike characters (`0/O`, `1/l/I`) are excluded from the alphabet. A CAPTCHA
nobody can read is not security, it is a wall in front of your own customers.

### Where it applies, and where it deliberately does not

| Form | When |
|------|------|
| Registration | Always — this is what bots sign up through |
| Forgot password | Always — otherwise it is a way to have the site mail anyone repeatedly |
| Login | **Only after a failed attempt** |

That last row is the interesting one. Showing a CAPTCHA to everyone punishes the
honest majority for the sake of the rare bot. It hooks into
`failed_attempts_for_email()` from the login-blocking module, so a member who types
their password correctly **never sees one**, while a script working through a list
meets one on its second try. The threshold is `CAPTCHA_ON_LOGIN_AFTER`.

It is also verified **before** the password is checked, so response timing cannot
be used to tell a real address from an invented one.

### Failing open, on purpose

If Google is unreachable, `captcha_check_recaptcha()` logs it and lets the request
through rather than locking every visitor out of registration. CSRF, login blocking
and the rest still apply. Failing closed on a third-party outage turns their
problem into a total outage of your own.

Likewise, if the driver is misconfigured the form says so plainly instead of
silently letting everything through. The diagnostics page reports the driver state.

---

## 7. Still on your list

- Export the database to `database/mobile2u.sql` (Section 7.0 deliverable)
- Pre-insert more sample data - see `database/README.md`. In particular add one
  `banned` member, two or three products with stock <= 5, and at least one
  `pending` order, otherwise three of the new modules have nothing to show
- **Move `lib/config.php` out of the ZIP or rotate the Stripe key before sharing
  the project anywhere public** - a secret key in source control is a real leak
- Prepare the slide: cover page, ERD from phpMyAdmin, per-member sub-cover and screenshots


## Remember Me (Retain Login Session)

**Files:** `lib/remember.php`, `member/devices.php`,
`database/migration_18_remember.sql`, plus hooks in `lib/init.php`,
`auth/login.php`, `auth/logout.php`, `auth/reset_password.php`,
`member/profile.php`, `admin/profile.php`, `admin/members.php`.

### The cookie is not the session

Raising `session.cookie_lifetime` would have been one line, and it is what most
student projects do. It was rejected: the PHP session id would then have to
remain valid for weeks, it is stored in a file on the server, it cannot be
revoked per device, and it cannot be rotated. Remember Me is therefore a
*separate* long-lived credential whose only power is to start a fresh, normal,
short-lived session.

### Selector + validator

The cookie holds `selector:validator`.

| Half | Stored as | Job |
|------|-----------|-----|
| `selector` | plain text, `UNIQUE` index | find the row |
| `validator` | SHA-256 hash only | prove the row is yours |

One opaque token cannot do both jobs. Stored in plain text so it can be indexed,
a database leak becomes a pile of working cookies. Stored as a hash, it cannot be
indexed, so every request would scan the table and compare one row at a time,
which also leaks timing. Splitting gives an indexed lookup on a value that is not
secret, and a secret value that is never stored in a usable form. The comparison
uses `hash_equals()` so there is no timing signal even on the half that matters.

### Why the selector must NOT rotate

This is the part worth defending out loud, because the first version got it wrong.

The validator is replaced on every single request. The selector deliberately is
not. It identifies the *device* for the life of that login.

If both halves were replaced, a stolen cookie the real owner had already
superseded would match no row at all — indistinguishable from an expired login,
and silently ignored. Keeping the selector fixed means the row is still there, so
a **wrong validator against a known selector** has only one explanation: two
browsers are holding copies of the same cookie.

The response is to delete every token for that account, not just the suspicious
one. The system cannot tell the victim from the thief, so it refuses to guess and
distrusts both. Losing a convenience beats leaving an intruder signed in.

A simulation of the full lifecycle (`victim first`, `thief first`, forged cookie,
replayed database hash, two honest devices) is what exposed the original flaw —
the "victim visits first" case was returning a silent logout instead of an alarm.

### Everything that revokes a token

| Event | Scope | Reason |
|-------|-------|--------|
| Log out | this device | other devices were remembered on purpose |
| Validator mismatch | **all** devices | possible theft, cannot identify the culprit |
| Change password | all devices | the old password may be how one was planted |
| Reset password | all devices | usually someone recovering a hijacked account |
| Admin blocks member | all devices | a block that a stale cookie undoes is not a block |
| Account not `active` | all devices | checked on every automatic login |
| 6th device | oldest | `REMEMBER_MAX_DEVICES`, so an abandoned laptop lapses |

### Sliding expiry

`expires_at` is pushed forward on each use. A device in daily use is never
logged out; one untouched for `REMEMBER_DAYS` lapses by itself.

### Sessions are labelled by how they began

`$_SESSION['auth_via']` records `password` or `remember`, and
`session_is_remembered()` exposes it. A session restored from a cookie is weaker
evidence of identity than a freshly typed password, so the distinction exists for
any action that should demand a real sign-in. The devices page states which kind
the current session is.

### Cost on ordinary requests

`remember_attempt_login()` is called from `lib/init.php`, but guarded by
`isset($_COOKIE[REMEMBER_COOKIE])`. A guest browsing the catalogue pays nothing;
only a browser that actually presents a cookie triggers a lookup.


## Batch Operations (Insert / Update / Delete)

**Files:** `lib/batch.php`, `admin/batch_import.php`, `admin/batch_price.php`,
`admin/batch_delete.php`, `includes/batch_nav.php`, `database/samples/*.csv`.
No migration: these work on the tables that already exist.

### Two-phase by design

Nothing is written on the first submit. Every one of the three tools parses,
validates and reports what it *would* do, and only touches the database after
the admin confirms. A batch mistake is not a small mistake. `admin/product_form.php`
can misprice one product; this page can misprice the catalogue.

The preview is held in the **session**, not in hidden fields, and handed back by
a one-use token:

- If the browser could restate the rows, the confirm step would be a way to write
  rows that never passed validation.
- The token is consumed on read, so a double submit or a back-button replay is
  rejected rather than applied twice.
- It expires after `BATCH_STAGE_TTL`, because the preview describes the catalogue
  as it was when it was generated.

### Parsing: why `fgetcsv` and not `explode(',')`

`explode(',')` cannot read this line correctly:

```
"Galaxy S24 Ultra, 512GB",Smartphones,5499.00
```

It yields four fields instead of three and shifts every value one column left, so
the price ends up in the stock column. It does not fail &mdash; it silently
imports wrong data, which is worse. `fgetcsv` over an in-memory stream handles
quoting, embedded delimiters and embedded newlines.

Three other things the parser has to do that are easy to miss:

| Problem | Handling |
|---------|----------|
| Excel writes a UTF-8 BOM | Stripped, otherwise the first header arrives as `\xEF\xBB\xBFname` and the `name` column is reported missing |
| European Excel uses `;` | Delimiter auto-detected from the header line |
| Windows / old Mac line endings | `\r\n` and `\r` normalised to `\n` |

Columns are matched **by name, not by position**, so reordering or adding a column
in the spreadsheet does not shift every value into the wrong field.

Delimiter choices are word tokens (`tab`) rather than characters (`"\t"`) because
`post()` and `temp()` trim every value &mdash; a literal tab would come back as an
empty string and silently fall back to a comma.

### Import applies the same rules as the single-product form

The price bounds, length limits, status set and category check are all repeated
rather than waived because the data "came from a file". A bulk importer that skips
validation is a hole straight through every rule the rest of the system enforces.

Two decisions worth defending:

- **Stock is not overwritten on update.** The file says what the supplier listed;
  the database knows what has since been sold. Trusting the file would silently
  undo every sale recorded against that product.
- **Imported stock writes an `initial` movement** to the ledger. Without it, the
  reconciliation report on Admin → Stock would start flagging every imported
  product as a mismatch.

`stop_on_error` defaults to all-or-nothing: a half-loaded price list is harder to
recover from than an empty one, because you cannot tell by looking which half
landed.

### Update: rounding must not reverse the change

`batch_new_price()` is one function used by both the preview and the commit, so
the two can never drift apart.

Two bugs were found by testing it rather than by reading it:

1. `floor($n) + 0.99` for charm pricing turned RM95.00 into RM95.99 &mdash; a
   "round to .99" step that *raises* the price after a discount. Fixed by rounding
   to the nearest `.99` on either side.
2. Nearest-`.99` still reversed the direction on cheap products, where the `.99`
   grid is coarser than the adjustment: RM1.98 less 5% is RM1.881, whose nearest
   `.99` is RM1.99. Now, whenever the tidied value lands on the far side of the
   original price, the exact value is kept instead. Better a price that does not
   end in `.99` than a discount that raises it.

A sweep of 553,860 operations confirms no combination reverses direction, and a
50,000-case fuzz confirms every result stays inside `DECIMAL(10,2)` bounds.

The commit uses **optimistic concurrency**: `UPDATE ... WHERE id = ? AND price = ?`
carries the price the admin was shown, so a product edited in another tab between
preview and confirm is skipped and reported rather than clobbered.

### Delete: the asymmetry with insert

Deleting a product is not the inverse of creating one. `orders.php` renders each
order line with an **`INNER JOIN` onto `products`**, so removing a product that
somebody has bought does not merely lose the product: those lines disappear from
the customer's past orders while the order total stays the same. That is silent
corruption of records the business has to keep.

So `batch_delete_products()` refuses any product with order history no matter what
the caller asked for &mdash; the check lives in the function, not in the page, so
a future caller cannot talk it out of it. Deactivation is offered instead, which
is why it is the default and the recommended option.

For products that *are* safe to remove, children go first (`cart`, `wishlist`,
`reviews`, `product_photos`, `stock_movements`). Several of those have
`ON DELETE CASCADE`, but the explicit deletes keep the behaviour identical on a
copy of the project where a migration was skipped.

Photo **files** are removed only after the transaction commits. Deleting them
inside it would leave the files gone but the rows restored if the commit failed.
`photo_file_in_use()` is consulted first, because a photo edit can leave two rows
pointing at one file.

Permanent deletion requires typing the word `DELETE`. By the third confirmation
dialog a checkbox is muscle memory; typing a word is not.


## Generate + Scan QR Code

**Files:** `lib/qrcode.php`, `api/qr_image.php`, `api/qr_lookup.php`, `verify.php`,
`admin/qr_scan.php`, `assets/js/qrscan.js`, plus hooks in `includes/receipt_template.php`,
`order_detail.php`, `product_detail.php`, `admin/mail_test.php`.
No migration. Needs `composer require endroid/qr-code`.

### The interesting part is the payload, not the squares

A QR code on a receipt is public from the moment it is printed. Anyone who can
see the paper can photograph it. So the obvious design is wrong:

```
/verify.php?order=42        <- change 42 to 43 and read someone else's receipt
```

Every code therefore carries an HMAC over its payload:

```
/verify.php?t=o42.61b7e3295dedb47fe40cb30761bebe2d
```

The server can confirm it issued the code; nobody without `QR_SECRET` can mint a
new one or edit an existing one. `hash_equals()` does the comparison, so the
signature cannot be recovered one character at a time by timing the rejection.

Verified by simulation: payload swaps, forged signatures, unsigned tokens and
empty input are all rejected; 200,000 random guesses at a known order id produced
zero hits.

### What the public page is allowed to say

`verify.php` is public on purpose &mdash; the point is that someone holding a
receipt can check it without an account. Because it is public it shows the
minimum that makes the check meaningful: receipt number, date, item count, total,
status, and a masked name (`Tan W. F.`). No address, no email, no full name. The
person holding the paper is not necessarily the customer.

The admin scanner returns more, because it is behind `is_admin()`.

### The typed fallback is not an afterthought

A camera is not always available: the phone is flat, the screen is cracked, the
receipt got wet. So every code is also printed as `M2U-001A-61B7E3`.

The first block is the order id in a Crockford-style alphabet; `I`, `L`, `O` and
`U` are excluded because they are misread as `1`, `1`, `0` and `V` off paper. The
second block is six characters of the signature. Because the id is *in* the code,
a typed reference is checked by arithmetic rather than by scanning the orders
table for a match.

Input is normalised before checking, so the code resolves whether it is typed
with dashes, without them, in lower case, or with stray spaces &mdash; but one
wrong character fails. A round trip over 200,000 order ids had zero failures.

Honest trade-off: six hex characters is ~24 bits, far less than the 128-bit QR
token. That is why the short code only ever reaches the public page, which shows
nothing sensitive, and why the admin lookup requires an admin session.

### Why the image endpoint takes a type and an id, not text

`api/qr_image.php` refuses to encode arbitrary text. An endpoint that renders
`?text=<anything>` is an open QR generator: someone could point it at a phishing
site and hand out codes served from this domain, which is exactly the endorsement
a QR code implies. It accepts `type=order|product` and builds the payload itself.

Order codes additionally require a session and are scoped to the owner or an
admin &mdash; otherwise the endpoint would mint valid signed tokens for any order
on request, defeating the signature entirely.

### SVG on screen, PNG in the PDF

SVG is the default: no GD dependency, stays sharp when a customer pinches to zoom,
and prints at printer resolution rather than at whatever pixel size was guessed
here.

The PDF cannot use it. Dompdf's SVG support is partial, and it runs with
`isRemoteEnabled = false`, so it will not fetch `/api/qr_image.php` over HTTP.
That setting is deliberate &mdash; it is what stops a crafted document from making
the server request arbitrary URLs &mdash; so the receipt embeds a PNG `data:` URI
instead. The same markup then also works in email clients, which block remote
images by default.

### Scanning runs in the browser

`assets/js/qrscan.js` draws video frames to an off-screen canvas and passes them
to jsQR. Only the decoded string is posted to the server; the video never leaves
the machine. That is faster than uploading frames and much less to justify.

Details that matter in practice:

- **Repeat suppression.** A code stays in frame for hundreds of frames. Without a
  guard the page would fire a request every 16ms while the camera is held steady.
  The same value is ignored for four seconds.
- **`facingMode: environment`** first, because staff scan a customer's screen.
- **Secure-context check.** `getUserMedia` needs HTTPS or `localhost`; a LAN IP is
  refused by the browser, so that case is detected and explained rather than
  failing silently. Same reasoning as the webcam module.
- **Tracks stopped on `pagehide`**, so the camera light does not stay on.
- **CDN failure is handled.** jsQR loads from cdnjs; if it is missing the page says
  so and the typed field still works, which matters on an offline marking machine.

### Library detection instead of assumption

Endroid changed its API substantially between v4 and v5 (a static factory became a
constructor, options became enums). `qr_driver()` probes what composer actually
resolved rather than assuming, the same way `lib/captcha.php` picks its driver,
and `admin/mail_test.php` reports the result along with whether `QR_SECRET` is
still the shipped placeholder.

### Small cleanup along the way

`base_url()` moved into `lib/helpers.php`. The scheme/host construction had been
copy-pasted into `lib/security.php` and `lib/receipt.php`; QR codes needed it as
well, so there is now one copy.


### Two bugs found while testing the scanner

**1. The HTML helpers emitted a duplicate `id`, project-wide.**

`html_input()` printed `id="$key"` and then appended the caller's attributes,
so `html_text('manual_code', '', ['id' => 'qrManualCode'])` produced:

```html
<input type="text" id="manual_code" name="manual_code" id="qrManualCode">
```

A browser keeps the **first** `id`, so the override silently did nothing and
`$('#qrManualCode')` matched an empty set. The manual lookup reported "type the
reference" no matter what was typed, because `.val()` on an empty set is
`undefined`.

This was never specific to the scanner. The same silent failure applied to every
custom id in the project:

| Selector | Page | What had quietly stopped working |
|----------|------|----------------------------------|
| `#voucherType`, `#voucherValue` | `admin/voucher_form.php` | percent/fixed hint and the max-discount column |
| `#cancelReason`, `#cancelNote` | `order_cancel.php` | reason handling |
| `#reviewBody` | `review_form.php` | character counter |
| `#profileName`, `#profileEmail` | `member/profile.php` | inline edit |

Fixed once in `html_input()`, `html_select()`, `html_textarea()` and
`html_file()`: the id is pulled out of `$attr` before the attribute string is
built, so a caller-supplied id genuinely overrides the default.

**2. jsQR was loaded from a hardcoded CDN path that does not serve it.**

The page carried `<script src="https://cdnjs.cloudflare.com/.../jsQR.js">`. That
path 404s, and a single hardcoded CDN is a single point of failure anyway.

Loading now happens inside `assets/js/qrscan.js` — so the page still contains no
inline script — and tries three sources in order:

1. `/assets/js/vendor/jsQR.js` (self-hosted, works with no internet)
2. jsDelivr
3. unpkg

A 200 response that is not actually the library counts as a miss, so the chain
continues rather than stopping on a soft failure. If all three fail the page says
exactly which file to save and where, and the typed-reference path still works.

**3. The collection code was hidden when the QR library was missing.**

`order_detail.php` wrapped the whole block in `qr_module_ready()`, but
`order_short_code()` is plain arithmetic and needs no library. A member with no
QR package installed therefore had no reference to type — the fallback was gated
behind the thing it was meant to be a fallback for. Only the image is conditional
now.


## Specifications Management

**Files:** `lib/spec.php`, `admin/specs.php`, `admin/spec_form.php`,
`admin/product_specs.php`, `compare.php`, `database/migration_19_specs.sql`,
plus hooks in `products.php`, `product_detail.php`, `includes/admin_rows.php`,
`admin/product_form.php`.

### Specs are data, not schema

The obvious design is columns on `products`: `ram`, `storage`, `screen_size`,
`battery`. It falls apart immediately, because a cable needs length, wattage and
connector; a watch needs strap size and a water rating. One wide table would be
mostly `NULL` for most rows, and every new spec would be an `ALTER TABLE` &mdash;
which locks the table on a real deployment.

So there are two tables. `spec_attributes` says what a spec *is*; `product_specs`
holds one value per product per attribute. That shape is EAV, and its well-known
weakness is that the database can no longer type-check anything. Two things push
back:

1. Every attribute carries a `data_type`, and PHP validates against it on the way
   in (`spec_normalise_value()`).
2. Numeric values are stored a **second** time in a `DECIMAL` column.

### The duplicated numeric column is the point

This is the decision worth defending. `product_specs` stores every value twice:

| Column | Always filled? | Job |
|--------|----------------|-----|
| `value_text` | yes | display (`"8"`, `"AMOLED"`, `"Yes"`) |
| `value_number` | numbers only | filtering and sorting |

Without it, "RAM of at least 8GB" has to be written
`WHERE CAST(value_text AS DECIMAL) >= 8`, which has two separate problems:

- The `CAST` makes the index unusable, so MySQL scans the whole table.
- Worse, without the cast the comparison is **wrong**. `'12' >= '8'` is `false`,
  because strings compare character by character. A RAM filter on a `VARCHAR`
  column silently drops every 12GB, 64GB and 128GB phone and looks like it worked.

A deliberate denormalisation, then: one duplicated value in exchange for a correct
answer and a usable index. The usual risk with duplication is drift, and the guard
against it is that `save_product_spec()` is the only function in the project that
writes to `product_specs`, and it always writes both columns together.

Verified by simulation: `'RAM >= 8'` on text returns `['8']`; on the numeric
column it returns all five test values.

### Units live on the attribute, not in the value

`spec_normalise_value()` strips the unit, so `8`, `8 GB` and `8GB` all store `8`.
Leaving it in would let `"8"` and `"8 GB"` exist as two distinct values that no
filter can reconcile. The unit is re-attached for display by `spec_display()`.

The same function absorbs the rest of what people actually type: `6,000 mAh`
becomes `6000`, `6.1 inch` becomes `6.1`, and `abc` is rejected with the
attribute's name in the message.

### Filters are only offered where they help

`spec_filter_options()` drops:

- attributes where fewer than two distinct values exist, and
- numeric attributes where `MIN == MAX`.

A filter that every product matches is noise, and one that matches nothing is
worse. Filters are also only shown once a category is selected, because
attributes belong to categories &mdash; across the whole catalogue a "RAM" facet
would sit above a list that is mostly cables.

### EXISTS, not JOIN

`spec_filter_sql()` emits one `EXISTS` subquery per active filter. Two filters on
two different attributes would otherwise need two joins onto `product_specs` and
produce duplicate rows that a `DISTINCT` then has to clean up.

The `spec_<id>_<mode>` keys are parsed defensively &mdash; the id must be a
positive integer and the mode one of `eq`/`min`/`max`, so a key like
`spec_1 OR 1=1_eq` is dropped before it reaches SQL. Values are bound as
parameters regardless.

### The comparison hides what is the same

`spec_comparison()` flags every row where all products agree, and `compare.php`
hides those by default. Comparing two phones from one maker, most of the table is
identical and the three rows that differ are the entire reason somebody opened
the page.

One subtlety: the flag uses `array_unique(..., SORT_STRING)` rather than
`SORT_REGULAR`. A product with no value for an attribute contributes `null`, and
`SORT_REGULAR`'s loose comparison of `null` against a string is exactly the kind
of edge case that would make "identical" quietly wrong. Casting to string first
means a **missing** value reliably counts as a difference, which is the honest
answer &mdash; "we don't know" is not the same as "the same".


## Specs part 2: per-product specs and customer-selectable variants

**Files:** `database/migration_20_spec_options.sql`, `lib/spec.php` (extended),
`admin/product_options.php`, plus changes in `admin/product_specs.php`,
`admin/spec_form.php`, `product_detail.php`, `includes/product_card.php`,
`api/cart_action.php`, `cart.php`, `checkout.php`, `checkout_success.php`,
`lib/orders.php`, `includes/order_parts.php`, `includes/receipt_template.php`,
`assets/js/main.js`.

### Three scopes instead of one

`spec_attributes` gained a nullable `product_id`, so an attribute now reaches
exactly as far as it should:

| `product_id` | `category_id` | Reach |
|---|---|---|
| set | &mdash; | this product only |
| null | set | that category |
| null | null | the whole shop |

The first row is what makes "just let me add one" work. Before, adding *SIM Slots*
to a single phone meant defining it for the category, which put an empty *SIM
Slots* field on every other phone. Now it is attached to that product alone.

### Options belong to the product, not the attribute

`product_spec_options` is keyed on `(product_id, attribute_id, value_text)`. The
choices could not live on the attribute definition, because "Colour" means black
and white on one phone and blue and green on another. Each option carries a
`price_delta`, which may be negative &mdash; last year's colourway at RM 50 off is
a real thing.

### The signature is the line identity

A selection travels as a canonical string: `3:Black|7:256`, sorted by attribute id.

Sorting is not cosmetic. "Black + 256GB" and "256GB + Black" are the same product;
without a canonical order they produce two different strings, so the cart would
show two identical-looking lines whose quantities never merge. Verified by
simulation: adding the same selection with the keys in the opposite order lands on
the existing line at quantity 2, while three genuinely different variants stay
three lines.

The separators are stripped from values before they go in, so a colour called
`Red|9:Free` cannot forge an extra pair into the signature. Parsing rejects
anything whose attribute id is not a positive integer, and a duplicated attribute
collapses to one value rather than two.

`''` rather than `NULL` means "no options chosen", because `WHERE
options_signature = ?` has to match, and `NULL = NULL` is `NULL` in SQL, not true.

### The posted selection is never trusted

`spec_validate_selection()` re-checks every choice against
`product_spec_options` and refuses a product that offers a choice if none was
made. A radio group is trivially editable in dev tools; the price delta is
therefore taken from the database row that matched, never from the form.

### Cart recomputes, orders snapshot

Deliberately different on purpose:

- **Cart** derives the label and the price from the *current* options every time
  it is rendered. An admin changing a delta shows up before checkout rather than
  after. If an option has been deleted, the line is flagged and checkout refuses
  &mdash; it is not silently repriced.
- **Orders** write `options_text` and fold the delta into `price_at_purchase` at
  purchase time, and never look at `product_spec_options` again. Same reasoning
  that already applied to `price_at_purchase`: deleting a colour must not rewrite
  what a customer bought last month.

Simulated end to end: buy *White 256GB*, delete the White option, and the order
still reads White at RM 3,349 while a cart line holding it turns into a warning.

### Grids offer "Choose Options", not "Add to Cart"

A product with selectable specs cannot be added from the catalogue grid, because
there is nowhere there to pick a colour. Posting a default on the customer's
behalf would be quietly wrong, so the button becomes a link to the detail page
instead.

### Live price in the browser

`main.js` recomputes the displayed price from `data-delta` on each radio. Two
details: `toFixed(2)` first, because floating-point addition would otherwise print
a trailing run of nines; then a thousands separator, so it matches PHP's `money()`
rather than showing `3349.00` beside `RM 3,349.00` elsewhere on the page. The
authoritative total is still the server's.

### Degrades before the migration

Every new column is reached through `db_column_exists()` and every new table
through `spec_options_ready()`, so the cart, checkout and order pages behave
exactly as before on a database where `migration_20` has not been run.


## Specs part 3: the configurator UI

**Files:** `database/migration_21_option_style.sql`, `lib/spec.php`,
`lib/product_photo.php`, `admin/product_options.php`, `product_detail.php`,
`assets/js/main.js`, `assets/css/style.css`.

Three columns on `product_spec_options` turn a radio group into a configurator:
`swatch_hex`, `photo_id`, `is_available`.

### Swatches are inferred, not configured

There is no "this is the colour attribute" setting. `product_selectable_specs()`
marks a group as `is_swatch` when any of its options has a `swatch_hex`. An
attribute whose options have colours *is* a colour attribute, so asking the admin
to say so as well would be a second source of truth that can disagree with the
first.

The colour is stored as hex rather than a name because "Midnight" and "Starlight"
mean nothing to a browser, and the same name is a different colour on different
models.

### The image swap reuses the gallery

`photo_id` is a foreign key onto `product_photos`, not a filename, so replacing or
deleting a photo cannot leave an option pointing at a file that no longer exists.
`ON DELETE SET NULL` keeps the option itself alive: the colour is still buyable,
it just stops changing the picture.

`gallery_images()` now returns the photo id alongside the URL, which lets
`product_detail.php` build a photo-id-to-slide-index map. The JS then switches
image by **triggering a click on the existing thumbnail**, which reuses the
slider's own logic including the counter and active state, instead of adding a
second widget that manipulates the same images and drifts out of step.

### Sold out is a toggle, not a delete

Deleting a colour makes it vanish for customers and invalidates any cart line
holding it. `is_available` greys it out instead and comes back with one click.

It is not a full colour-by-storage stock matrix &mdash; that needs a combination
table &mdash; and the migration says so rather than implying otherwise.

Two things follow from it:

- **The default skips it.** The initially-checked option is the admin's default
  *if that is still available*, otherwise the first one that is. Opening on a
  sold-out choice would make the first click on Add to Cart fail.
- **The server re-checks it.** `disabled` on a radio is a hint that survives
  exactly as long as it takes to open dev tools, so
  `spec_validate_selection()` rejects an unavailable option regardless of what
  was posted.

### Live summary

`main.js` rebuilds a line-by-line summary from the checked radios: base price,
each chosen extra as *Included* / *+RM 300*, then the total. It shares one
`asMoney()` helper with the headline price so both match PHP's `money()`
formatting exactly.

### Dead CSS removed

The earlier pill styles (`.spec-choice-pill` and friends) were superseded by
`.config-tile` / `.config-swatch`. They were deleted rather than left in place,
so there is one set of rules for one control instead of two that quietly
disagree.


## Specs part 4: display style, and the bug that forced it

**Files:** `database/migration_22_render_style.sql`, `lib/spec.php`,
`admin/spec_form.php`, `admin/product_specs.php`, `admin/product_options.php`,
`product_detail.php`, `assets/js/main.js`.

### The bug

Part 3 inferred the display style from the data: *any option carrying a
`swatch_hex` turns the whole group into colour circles*. I defended that at the
time as avoiding a second source of truth.

It was wrong, and in a way that inference is specifically prone to.
`<input type="color">` has no empty state &mdash; it posts `#000000` whether or
not anybody touched it. The admin form showed that input for every option, so
adding "8GB" to RAM stored a black swatch against it, and RAM began rendering as
a row of black circles.

The inference was not reading a fact about the spec. It was reading an artefact of
the form widget.

### The fix

`spec_attributes.render_style` is now an explicit `ENUM('tile','swatch')`. How a
spec is drawn is a property of the spec, not something to reverse-engineer from
its options.

Three layers, so it cannot recur:

1. The colour input is **not rendered** for a tile spec.
2. The colour is **not read** server-side for a tile spec, so hand-posting it does
   nothing.
3. The migration **clears** the `#000000` values the old behaviour already wrote.

### Ordering follows how people actually buy

Swatch specs are moved to `sort_order = 5` so colour comes before storage. Every
phone site does this, and for a reason: the colour changes the photograph, so the
customer sees the object before configuring it.

### SQL assembled from one field map

`admin/spec_form.php` built its INSERT and UPDATE from nested ternaries, because
the column set grows across migrations 19, 20 and 22. That got unreadable and is
exactly where a column list and a parameter list drift apart. It now builds one
`$fields` map and derives the columns, placeholders and values from it, so the
three can no longer disagree.

### Sticky buy bar

A configured product gets a bar pinned to the bottom of the viewport carrying the
selection and the running total. It is rendered only for a product that actually
has choices and is in stock, and stays `hidden` until the JS has filled it, so it
never flashes an unconfigured state. On a plain product it does not exist at all
&mdash; a bar that follows the page for no reason is clutter.


## Double submit protection

**Files:** `lib/security.php`, `lib/config.php`, `assets/js/main.js`,
`assets/css/style.css`, applied in `admin/mail_test.php`, `receipt.php`,
`order_detail.php`, `auth/forgot_password.php`, plus busy labels on checkout,
order cancellation and the batch commits.

The reported symptom was **Admin → Mail & PDF**: holding the button down sent a
message per click.

### Why CSRF did not already stop it

The CSRF token deliberately lasts for the whole session, because pages like the
cart post with the same token repeatedly. A second click therefore carries a
perfectly valid token. CSRF answers "did this come from our form"; it says
nothing about "is this the same submission twice".

### Three layers

**1. The browser stops the obvious case.** A delegated `submit` handler flags the
form and disables its submit buttons.

Two details that are easy to get wrong:

- The button is **not** disabled synchronously. A disabled control is excluded
  from the form data, so disabling it before the browser serialises the form
  would silently drop the submit button's own name and value. The disable runs on
  the next tick instead.
- The handler is registered **after** the `data-confirm` handlers. Both are
  delegated on `document`, so they run in registration order; if the guard ran
  first it would mark the form busy and then a cancelled confirm dialog would
  leave that form permanently dead. Cancelling calls
  `stopImmediatePropagation()`, so with this order the guard is never reached.

A `pageshow` handler clears the state, because a back/forward restore returns a
cached page with the button still disabled.

**2. A one-use nonce stops the rest.** `form_nonce()` mints a value per render and
`form_nonce_valid()` consumes it. A double click, an F5 on the POST and a
back-then-resubmit all arrive with the same nonce, and only the first finds it.
Up to ten are held per action so the same form open in several tabs still works,
and the list is bounded so a session cannot grow on a page somebody refreshes.

This is the layer that matters, because `disabled` on a button lasts exactly as
long as it takes to open dev tools.

**3. A cooldown stops the determined case.** The nonce cannot stop somebody
reloading the page for a fresh one. `action_cooldown()` puts a real floor between
attempts: 30 seconds for the SMTP test and the password-reset mail, 60 for a
member re-sending their own receipt.

Verified by simulation: five simultaneous submits with one nonce yield exactly one
success; two tabs each keep a working nonce; 50 page loads hold 10 nonces, not 50;
and the cooldown blocks at 29 seconds and allows at 30.

### Not everywhere

Actions that were already idempotent were left alone rather than given a second
mechanism: checkout is protected by `orders.stripe_session_id`, and the batch
tools by their staged-payload token, which is consumed on read for exactly this
reason.

### The static checker was rewritten too

The brace-balance checker used a regex to strip HTML between `?>` and `<?php`,
which mis-sliced multi-line PHP blocks and produced two false alarms during this
work. It now walks the source and extracts only what is genuinely inside
`<?php` / `<?=` blocks, handling strings, both comment styles, heredocs, and the
fact that `?>` closes a PHP tag even inside a `//` comment. Re-run across every
PHP file in the project afterwards: all balanced.


## Google Maps Integration (Store Location)

**Files:** `database/migration_23_stores.sql`, `lib/store.php`, `stores.php`,
`admin/stores.php`, `admin/store_form.php`, `assets/js/storemap.js`, plus hooks in
`includes/footer.php` and `admin/mail_test.php`.

### It has to work without a billing account

The Maps JavaScript API needs an API key, and a key needs a Google Cloud billing
account with a card attached. A project that has to run on a marker's machine
cannot depend on that, so `MAP_DRIVER` picks between two real implementations:

- **`embed`** (default) drops Google's classic embed URL into an `<iframe>`. That
  endpoint has needed no key for years. It shows one location at a time.
- **`js`** loads the full API and puts every store on one map with markers, info
  windows and fit-to-bounds.

The point is that the keyless path is a **working Google map**, not a disabled
stub. The key buys the multi-marker view and nothing else. Same driver pattern as
`lib/captcha.php`.

The API script tag is emitted only on `stores.php`, and only for the `js` driver,
so no other page loads a script it never uses.

### Coordinates come from a pasted link

Geocoding an address into coordinates is itself a paid Google API, so the admin
form does not attempt it. `parse_map_coordinates()` reads them out of whatever
somebody can get for free in ten seconds: the Google Maps address bar, or the
pair you get from right-clicking a spot.

Google puts coordinates in a URL in more than one place, and they disagree:

```
.../@3.1578,101.7117,17z        the map CENTRE
...!3d3.1578!4d101.7117         the PLACE itself
```

The place wins, because the centre drifts the moment anybody pans the map before
copying the link. Verified with a URL where the two differ.

Rejected explicitly, each with its own message: shortened `maps.app.goo.gl` links
(the coordinates genuinely are not in them, and resolving one needs a network
round trip this form does not make), out-of-range values, and **`0,0`** &mdash;
which is a real point in the Atlantic, is what a half-filled form produces, and
would otherwise put a shop off the coast of Africa.

### DECIMAL, not FLOAT

`latitude` and `longitude` are `DECIMAL(10,7)`. A binary float cannot represent
`3.139003` exactly, so values drift and equality comparisons stop being
trustworthy. These are numbers that feed distance arithmetic. Seven decimal places
is about a centimetre.

### Distance is haversine, in the browser

`haversine_km()` exists in PHP and again in `storemap.js`. Straight Pythagoras on
degrees treats a degree of longitude as a fixed distance, which it is not once you
leave the equator. Honest caveat: at Malaysia's latitude the difference between
the two is small &mdash; the reason to use haversine is that it is *correct*
anywhere, not that it dramatically changes these particular numbers.

"Find my nearest store" runs entirely client-side. The visitor's coordinates are
never sent to the server, which is both faster and much less to justify. It also
works with no map at all, so it is useful under the `embed` driver too.

Sorting happens in PHP rather than SQL. MySQL has `ST_Distance_Sphere`, but it
wants a spatial `POINT` column rather than two decimals, and sorting five shops is
not the bottleneck.

### Small safety details

- `stores_for_map()` builds the JSON explicitly rather than passing the row
  through, so an internal note or an email address cannot reach the browser by
  accident.
- Info windows are built with DOM methods, not string concatenation: a store name
  is admin-entered text and must not be able to inject markup.
- The iframe carries `loading="lazy"` and `referrerpolicy`, so it stays off the
  critical path and does not hand our URL to Google on every page view.
- Hiding the last visible store is refused, the same way the admin module refuses
  to remove the last admin &mdash; otherwise the public locator silently empties.
- Setting the flagship is a transaction, because "exactly one" is two writes.


## AJAX Integration

**Files:** `lib/ajax.php`, `api/search_suggest.php`, `api/products_page.php`,
`api/check_email.php`, `assets/js/ajax.js`, plus a refactor of the five existing
endpoints and hooks in `includes/header.php`, `products.php`, `auth/register.php`.

### First, the endpoints stopped repeating themselves

Every `/api` file had its own `*_json()` function, its own
`header('Content-Type: application/json')`, its own `if (!is_member())` and its
own `if (!is_post() || !csrf_valid())`. Five near-identical copies.

That is five places to drift apart, and a guard that gets copy-pasted is a guard
somebody eventually forgets to paste. All five now open with one line:

```php
ajax_guard_post('member');   // or 'admin'
```

Doing this also surfaced a latent fatal: `api/cart_action.php` already defined a
function called `json_out`, which would have collided with the shared one the
moment both loaded. A grep for redeclarations against `lib/ajax.php` is now part
of the check script.

The two image endpoints (`captcha_image`, `qr_image`) keep their own rules on
purpose &mdash; they return images, not JSON, and `qr_image` has per-type access
control that does not fit the shared shape.

### Read endpoints carry no CSRF token, deliberately

`ajax_guard_read()` does not check CSRF. That is a decision, not an oversight: a
CSRF token protects against a third-party page causing a state **change** using
the visitor's cookies. A read-only endpoint has no state to change, and demanding
a token would stop it working from a plain link.

The obligation this creates is that a read endpoint must genuinely not write. The
check script now greps the three read endpoints for `db_exec`, `INSERT`, `UPDATE`
and `DELETE`, and expects none.

### The bug live search is famous for

Debouncing is the obvious part: one request per pause instead of one per
keystroke. It is not sufficient.

Type `iph`, pause long enough that the request fires, then finish typing
`iphone`. Two requests are now genuinely in flight. If the first is slower, its
response arrives **last** and overwrites the correct results with stale ones. The
box then shows results for a word the user is no longer looking at.

Simulated all three configurations:

| | panel ends up showing |
|---|---|
| debounce only | `iph` &mdash; **wrong**, the stale response won |
| debounce + `abort()` | `iphone` |
| debounce + `abort()` + sequence guard | `iphone` |

`abort()` handles it in most cases. The sequence guard covers the window where
the response has already arrived when the abort fires, which `abort()` cannot
win. Every request carries an incrementing number and anything older than what
has already been drawn is dropped. Two integers, and the failure mode is gone.

The same guard is on the inline email check, for the same reason.

### HTML from the server for load-more, JSON for suggestions

Two endpoints, two answer formats, on purpose.

`search_suggest.php` returns **JSON**, because a suggestion row is a thumbnail, a
name and a price, and building that in JS is trivial.

`products_page.php` returns **rendered HTML**, because a product card is not
trivial: image, badges, rating stars, stock state, wishlist heart, and now
*Choose Options* versus *Add to Cart*. Returning JSON would mean writing that card
a second time in JavaScript and keeping the two in step forever. The endpoint
calls the same `render_product_card()` the first page used. The cost is a larger
response; the gain is one template instead of two.

### Everything degrades

- The search form still submits normally with JavaScript off; the suggestion
  panel is added on top of it. `Enter` is only intercepted when a suggestion is
  actually highlighted.
- **Load More is added alongside the numbered pagination, not instead of it.** If
  the endpoint fails or JS is off, paging works exactly as before.
- Suggestions are keyboard-navigable and marked up as an ARIA combobox with a
  listbox, so the panel is announced rather than appearing silently.

### Cheap by construction

`LIKE '%term%'` cannot use an index, so the suggestion endpoint has a two-character
minimum and a clamped `LIMIT`. Without the clamp, `?limit=100000` turns a
suggestion box into a way to dump the catalogue in one request. Results that
*start* with the term are ordered above ones that merely contain it.

`check_email.php` does reveal whether an address is registered. That is normally
worth avoiding, but it is unavoidable here: the registration form already reveals
it by refusing duplicates, so the endpoint is no more revealing than the form it
belongs to. It is throttled per session, and the login and password-reset pages
deliberately do not use it.


## Stylesheet: duplicate selectors

Reported symptom: the *Edit My Review* button in the review sidebar was too big.
The cause was not that button.

`assets/css/style.css` had grown two competing definitions of several core
selectors. CSS has no warning for this &mdash; the later rule silently wins every
property they share, so editing the earlier one appears to do nothing.

| Selector | Copies | What the loser said vs the winner |
|----------|--------|-----------------------------------|
| `.btn-primary` | 2 | `padding: 10px 20px` vs `width: 100%; padding: 12px; font-size: 16px; font-weight: bold` |
| `.btn-outline` | 2 | `padding: 8px 16px` vs `padding: 9px 16px` |
| `.alert` | 2 | `padding: 15px` vs `padding: 14px 16px` |
| `.alert-error` / `.alert-success` | 2 | two different colour pairs |
| `.mt-2` / `.mt-4` | 2 | `10px / 30px` vs `8px / 24px` |
| `.text-center` / `.text-right` | 2 | identical, just dead weight |

### The one that mattered

The winning `.btn-primary` carried **`width: 100%`**. It had been written for the
login form and then applied to every primary button in the project. Consequences:

- Every admin toolbar button stretched, and only looked survivable because
  `.form-actions`, `.row-actions` and `.admin-header-actions` are all flex
  containers that squeeze their children.
- `.btn-block` was meaningless on a primary button, because it was already full
  width.
- There was no way to have an inline primary button at all.

### The fix

One canonical block. Primary and outline now share the same box &mdash; same
padding, same font size, same line height &mdash; so they line up when they sit
next to each other, which they do in nearly every `.form-actions` row. Width is
not set: a button is as wide as its label, and `.btn-block` is what makes it fill
its container.

Removing `width: 100%` was safe to do wholesale because every place that genuinely
wanted a full-width button already said `btn-block` (the auth forms, the sidebar
CTAs). Everything else was being stretched against its will.

### The audit is repeatable

The check script now parses the stylesheet, skips `@media` bodies so a deliberate
responsive override is not reported as a clash, and lists any selector defined
more than once **where the copies set the same property**. That last condition is
what makes it useful rather than noisy: two rules on one selector are fine until
they disagree.

After the pass: zero real conflicts. The one it still flags and I deliberately
kept is `input[type="radio"]` overriding `border-radius` to make radios round
after the shared checkbox rule &mdash; that is an intentional override, not a
duplicate.


### The missing box-model reset

Reported symptom: the *Proceed to Checkout* button's right edge sat flush against
the edge of its card while the left edge respected the padding.

The stylesheet had **no universal `box-sizing: border-box`**. Without it,
`width: 100%` means "100% of the parent *plus* my own padding and border", so any
padded full-width element overflows to the right.

The checkout button is `.btn-primary.btn-block.btn-lg`:

```
card inner width           272px
button padding + border     46px   (22px each side + 1px border each side)
rendered width             318px   -> 46px past the right edge
```

Only the right side shows it, because the overflow grows rightward from a
left-aligned box. That is the signature of this bug and why it looked like a
one-off alignment problem rather than a global one.

The file had been working around it by adding `box-sizing` to **nine individual
rules**, one at a time, as each symptom surfaced. Every one of those was the same
bug being patched locally.

One universal rule fixes the class, including **42 `.btn-block` buttons** across
the login, register, cart, checkout, admin and profile pages that were all
overflowing and had not been reported yet.

Three widths were adjusted so the reset changes nothing visually where it
otherwise would have: `.profile-sidebar` 250 to 290, `.filter-sidebar` 230 to 266
and `.qty-input` 60 to 72 &mdash; each the old content width plus the padding it
already had, so the rendered size is identical. The remaining fixed-width elements
that shrink are thumbnails and swatches losing 1&ndash;4px of border, which is
both invisible and the more correct measurement.

`::before` and `::after` are included, because a pseudo-element with a border has
the same arithmetic &mdash; the busy spinner is one.

The nine local patches were left in place rather than deleted. They are now
redundant but harmless, and keeping them means this change can be reverted by
removing one rule.


## Admin live search rendering the panel inside itself

Reported on **Admin -> Stock**: typing in the search box made the whole admin
panel -- sidebar, header, everything -- appear nested inside the results table.

### Cause

The live search in `assets/js/admin.js` bound to **every** `.admin-search-input`
on the site, and when the form declared no target it fell back to
`'.admin-table tbody'`:

```js
$('.admin-search-input').on('input', ...)
var selector = $(...).data('target') || '.admin-table tbody';
```

Live search is a contract with two halves:

- **PHP**: `if (is_ajax()) { ...rows only...; exit; }`
- **HTML**: `<form class="admin-search-form" data-target="#someTbody">`

Five pages had the input but not the PHP half, so the AJAX request returned the
entire page and the fallback selector dropped it straight into the table body.
`admins`, `members`, `orders`, `products` and `vouchers` had both halves and
worked, which is why the bug looked page-specific rather than structural.

### Fix

**Opt-in is now strict.** The handler binds only inside
`.admin-search-form[data-target]`, and bails if that target does not resolve. A
page that has not declared the contract submits its form normally -- a working
plain search instead of a broken clever one.

That alone fixed four pages: **stock**, **reviews**, **login security** and
**batch delete** (whose input was not even in an `.admin-search-form`, so it was
being hijacked by a handler that had nothing to do with it).

**Stock then got the contract properly**, since it is a search-heavy page. Its
row markup moved into `admin_stock_rows()` in `includes/admin_rows.php`, which
the page now calls twice: once for the full render and once for the AJAX reply.
One template, two callers -- the same reason `products.php` has worked all along.

### The check that would have caught it

The audit now cross-references all three signals per page and states the verdict,
so "declares a target but has no `is_ajax()` branch" is a named failure rather
than something you find by typing in a box:

| page | data-target | is_ajax | verdict |
|------|-------------|---------|---------|
| admins, members, orders, products, vouchers, stock | yes | yes | live search |
| reviews, login_attempts | no | no | plain form, opted out |
| batch_delete | no | no | plain form, not bound |


### The double-submit guard fighting the AJAX search

Immediately after the fix above, the Search button on the live-search pages span
on "Working..." forever and never returned results.

Self-inflicted. The double-submit guard added earlier disables a form's submit
button and swaps its label, on the assumption that a page load is coming to
reset it. An AJAX form calls `preventDefault()`, so **no page load is coming** and
nothing ever put the button back.

The handler order is what makes the fix simple. jQuery runs handlers bound
directly on an element during the target phase, and delegated handlers on
`document` during bubbling -- so the AJAX form's `preventDefault()` always
happens *before* the guard sees the event. The guard now starts with:

```js
if (e.isDefaultPrevented()) { return; }
```

A form that has already cancelled its own submission is not navigating away, so
there is nothing to guard. This covers every AJAX form in the project, not just
the admin search: the QR manual lookup and the catalogue forms get the same
protection for free.

### And the Search button was inert anyway

Worth noting separately, because it was a real defect hiding behind the first
one: on those pages the search only ever ran from the `input` event. The button's
submit was swallowed by `preventDefault()` and nothing else happened, so pressing
Search genuinely did nothing.

The search is now one function with three callers -- debounced typing, the button,
and Enter -- and pressing the button flushes the pending debounce rather than
queueing a second request.

While it was being restructured it also gained the same **out-of-order response
guard** used by the storefront live search, for the same reason: a slow early
request could otherwise land after a fast later one and put stale rows back on
screen.
