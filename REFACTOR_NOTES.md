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

## 7. Still on your list

- Export the database to `database/mobile2u.sql` (Section 7.0 deliverable)
- Pre-insert more sample data - see `database/README.md`. In particular add one
  `banned` member, two or three products with stock <= 5, and at least one
  `pending` order, otherwise three of the new modules have nothing to show
- **Move `lib/config.php` out of the ZIP or rotate the Stripe key before sharing
  the project anywhere public** - a secret key in source control is a real leak
- Prepare the slide: cover page, ERD from phpMyAdmin, per-member sub-cover and screenshots
