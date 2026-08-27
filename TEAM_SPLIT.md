# Team split — 5 members

Built from the assignment brief (sections 4.3, 4.4 and 8.1) and from what is
actually in the repository. Every page named below exists.

## How to read this

The brief groups the basic modules into **six** areas and the team has **five**
people, so one person takes two of the smaller areas. Each slot below is:

- **1 basic module group** — mandatory, from section 4.3
- **Additional functions already built** that belong to that area
- **Additional functions still open** — pick from these if you want more

Section 8.1 says individual marks may differ, and section 6.0 says technical
questions will be asked. So whoever takes a slot needs to be able to **explain
those files**, not just show them working. Pick the area you actually want to
understand.

The marks that hang on this: **Basic Modules 30%** and **Additional Modules 20%**
are individual. The other 50% (user experience, database design, conventions,
security, input validation) is team-wide.

> **One function, one owner.** A few features touch two slots — Block/Unblock
> spans Security and Member Maintenance, reward points span Checkout and the
> admin member page. Those are marked *shared* below. Agree who claims each one
> **before** anybody builds their slide: the same function appearing on two
> members' slides is the fastest way to make a tutor ask which of you actually
> wrote it.

---

# Difficulty ranking — read this before you choose

The five slots are **not** equally hard, and the hard one is not the big one.
Measured across the files each slot owns:

| | Slot | Total lines | Code lines | Files | Transactions | Per 1000 code | Verdict |
|---|---|---|---|---|---|---|---|
| 1 | **D** Orders + Fulfilment | 4,424 | 2,705 | 18 | 6 | **2.22** | Hardest to explain, least to read |
| 2 | **B** Product Maintenance | 6,199 | 3,882 | 15 | 8 | 2.06 | Now almost as hard, and much bigger |
| 3 | **E** Member + Admin tools | 7,267 | 4,723 | 25 | 6 | 1.48 | Most to read; the batch tools are the hard part |
| 4 | **A** Security | 5,564 | 3,109 | 20 | 3 | 0.96 | Serious topic, mostly textbook answers |
| 5 | **C** Cart + Checkout | 5,237 | 3,346 | 20 | 3 | 0.90 | One very hard file, rest moderate |

**Code lines exclude comments and blank lines.** Total lines roughly doubled
across the project when the commenting pass went in, and counting those would
say the work had grown when only the explanations had. Read the *code lines*
column for "how much is there"; the total is there so the two are not confused.

A database transaction is the clearest marker of code that has to reason about
*two things happening at once* — the part students find hardest to defend. So
"transactions per 1000 code lines" is a rough measure of how much of a slot is
genuinely difficult rather than long.

### What moved since the first version of this table

**B has nearly caught D.** Specifications can now belong to *several* categories
(`spec_attribute_categories`), which turns a single column into a many-to-many
relation, and `lib/product_photo.php` alone holds five transactions. B is now
the slot with the **most** transactions of any.

**E rose from last to third.** Nothing was added to it — the metric was
previously counting `db_begin()` only, and missed the `beginTransaction()` calls
in `lib/stock.php` and `lib/batch.php`. The old number was wrong, not stale.

**C fell to last** on this measure, which undersells it: its difficulty is
concentrated in `checkout.php` and the variant-aware cart rather than spread
about. See the note under C below.

**D and E are still opposites.** D is the smallest slot and the hardest; E is
the biggest and the most straightforward per line. Choosing by file count would
get this exactly backwards.

---

## What actually makes each one hard

### Slot D — Orders + Fulfilment  ★★★★★  *(hardest)*

Smallest slot, and almost none of it is ordinary CRUD. Six of its functions go
beyond the brief's list — the most of any slot — which means six things with
no textbook answer to fall back on.

You will have to explain, in your own words:

- why cancellation is a **request that gets approved** rather than a status
- why permissions are **per transition** (Vendor: pending→processing only)
  rather than per page
- why staff may only cancel an order that is **currently theirs to move**
- why the QR payload is **HMAC-signed** instead of just holding the order id

Nothing here can be revised from a tutorial. **Take it if you want the hardest
material and the least reading.**

### Slot B — Product Maintenance  ★★★★☆  *(second)*

`lib/spec.php` is 1,526 lines — the largest single file in the project — and
it implements **EAV** (entity-attribute-value). You will be asked why the
specifications are not simply columns on `products`, and "it's more flexible"
is not a sufficient answer; you need the cost side too (specs become a join,
and the database can no longer type-check the values).

On top of EAV, a spec may now belong to **several categories at once** — Storage
applies to Smartphones *and* Tablets but not to cables. That is a second
many-to-many relation (`spec_attribute_categories`) layered on the first, and it
brings two questions worth rehearsing:

- **why a link table rather than a second `category_id` column.** The honest
  answer is that the alternatives were both wrong: defining Storage once per
  category produces two attributes that merely share a name, so a phone and a
  tablet can never appear on one comparison row; making it global hangs a
  Storage field on every cable in the shop.
- **why "no rows" means "every category".** The absence of a restriction is the
  restriction being absent. Warranty has no links and therefore reaches
  everything, which is exactly what `category_id IS NULL` used to mean.

It also has the subtlest bug in the project's history, which is worth being able
to tell as a story: the duplicate-name rule had to change from *unique within a
category* to *no overlap between category sets*, because two specs called
Storage are fine when one covers phones and the other covers cables — they never
meet — but not when both cover phones.

Also carries GD image processing, the configurator that lets a customer's choice
change the price, and the five transactions in `lib/product_photo.php`.

> **Ranks 3, 4 and 5 — E, A and C — are close enough to be noise.** D and B are
> clearly the two hardest; below them the measure separates slots by less than
> it separates people. Read all three descriptions and pick on the *kind* of
> work, not the position.

### Slot C — Cart + Checkout  ★★★☆☆

Unusual shape: **one genuinely hard file surrounded by easy ones.**
`checkout_success.php` is the most consequential code in the project — money
and stock both move — and it is the only slot using `SELECT ... FOR UPDATE`
(row locking, so two tabs cannot spend the same stock).

Its position at the bottom of the table undersells it: the metric rewards
difficulty *spread thinly*, and C's is concentrated. One hard file still has to
be defended in the viva.

The rest — listings, filtering, the cart — is comfortable. Take it if you want
one hard thing to master properly rather than many medium ones.

### Slot A — Security + Authorisation  ★★★☆☆

Feels intimidating and is the most *learnable*, because every concept is
standard and heavily documented: password hashing, CSRF, session fixation,
role-based access control.

Two things make it easier than it looks: `AUTHORIZATION_TESTING.md` gives you
ready-made evidence to demonstrate, and the security marks reward showing that
attacks *fail* — which is easier than explaining how something works.

The one question you must have a real answer to: **why permissions rather than
role-name checks?** (Because a role name changes and a permission does not; and
because adding a role must not mean editing every page.)

### Slot E — Member + Profile + Admin tools  ★★★☆☆

The most files and the most lines, but mostly the **same patterns repeated**:
list, form, validate, save. Once you understand one CRUD screen you understand
seven.

The one hard piece is `lib/batch.php` (1,051 lines): the preview-then-commit
flow with one-use tokens, and optimistic concurrency control in the price
updater — "what if somebody edited a price between the preview and the
confirm?" `lib/stock.php` carries three more transactions.

Those six transactions are why E sits third rather than last. They are real, but
they live in two files out of twenty-five — so the *slot* is not hard, two files
in it are. Read those two properly and the rest is CRUD.

**Take it if you want steady, predictable work.** Be honest that there is a lot
of it — this is volume, not difficulty.

---

## How to actually choose

Marks do not scale with difficulty. Basic Modules (30%) and Additional Modules
(20%) are awarded on **your** area whichever it is, so taking the hardest slot
earns no bonus. Difficulty only matters for matching the work to the person.

| If you… | Take |
|---|---|
| are strongest in the group and want to be stretched | **D** |
| like database design and want the deepest single topic | **B** |
| want one hard thing done really well | **C** |
| are anxious about the viva and want solid ground | **A** |
| are reliable but less confident, and would rather have volume than puzzles | **E** |

Two warnings from the numbers:

**Do not give D to the weakest member because "it has the fewest files".** It
has the fewest files *and* the highest concentration of difficult code. That
combination is a trap.

**Do not give E to somebody short on time.** It is 4,647 lines across 25 files.
None of it is hard, but all of it has to be read.

---

# Slot A — Security + Authorisation

### Basic module (4.3)
Roles: Admin + Member · Login + Logout · Password Hashing · Password Reset

### Pages
```
auth/login.php              auth/logout.php
auth/register.php           auth/forgot_password.php
auth/reset_password.php     verify.php
admin/login_attempts.php    admin/roles.php
admin/role_form.php         member/devices.php
lib/auth.php  lib/role.php  lib/security.php  lib/login_guard.php  lib/remember.php
```

### Additional functions already built
| Function | In the brief's list? | Where |
|---|---|---|
| Temporary Login Blocking (3 attempts) | yes | `lib/login_guard.php` |
| Block + Unblock User Account | yes | `admin/members.php` — *shared with E, agree who claims it* |
| CAPTCHA Integration (3rd-party) | yes | `lib/captcha.php` |
| Remember Me (Retain Login Session) | yes | `lib/remember.php` |
| **Role + Permission Management** | **no — extra** | `lib/role.php`, `admin/roles.php` |
| **Per-transition authorisation** | **no — extra** | built on this slot's `can()`, demoed by D |
| **CSRF protection on every POST** | **no — extra** | `lib/security.php` |
| **Post/Redirect/Get on 21 forms** | **no — extra** | `lib/prg.php` |
| **Email domain (MX) validation** | **no — extra** | `lib/validation.php` |
| User Email Verification (Email) | yes | `lib/verification.php`, `verify_email.php` |
| **One-time code (OTP) as well as a link** | **no — extra** | `lib/verification.php` |
| **Live "is this address available" check** | **no — extra** | `api/check_email.php` |
| **Config completeness check at startup** | **no — extra** | `lib/init.php` |

### Still open — pick from here
- SMS Integration (security code on login)
- Two-factor authentication by email code
- Session timeout with a warning countdown
- Login history visible to the member ("last signed in from…")

### Three design decisions worth being able to defend

These are small in code and disproportionately good viva material, because each
one is a case where the *obvious* implementation is wrong.

**The CAPTCHA fails closed.** When Google cannot be reached, the check returns
false and the signup is refused. It used to return true, on the reasoning that
failing closed would lock everyone out — which has the logic backwards: the one
condition an attacker most benefits from, the check not running, was also the
condition under which the check waved everything through. The lockout worry is
real but belongs to configuration: `CAPTCHA_DRIVER = 'image'` is fully local and
needs no internet. *Say this if you are asked to name a security trade-off you
got wrong and corrected.*

**A misconfigured driver is refused too, not skipped.** A placeholder key, or
the image driver with `gregwar/captcha` missing, used to pass silently — and
`/vendor/` is in `.gitignore`, so a fresh clone would have disabled the CAPTCHA
on the marker's machine while the form still looked protected. A broken security
control should look broken.

**The welcome email is sent after the response.** `redirect_then()` in
`lib/helpers.php` sends the 302, closes the connection, then talks to Gmail —
about 76% of the old registration wait was the SMTP conversation, happening
*after* the account already existed and the user was already logged in. The part
worth explaining is `session_write_close()`: PHP holds an exclusive lock on the
session file for the whole script, so without releasing it first the browser
follows the redirect and then the *next* request blocks on that lock for exactly
as long as before. The fix would have looked like it did nothing.

### The strongest thing to demo
`AUTHORIZATION_TESTING.md` — console snippets that bypass the interface entirely
and are still refused. That is the clearest evidence for the security marks.

---

# Slot B — Product Maintenance (Admin)

### Basic module (4.3)
Product Listing + Detail · Basic Searching · Product CRUD · Product Photo Upload

### Pages
```
admin/products.php          admin/product_form.php
admin/product_photos.php    admin/photo_edit.php
admin/product_specs.php     admin/product_options.php
admin/categories.php        admin/category_form.php
admin/specs.php             admin/spec_form.php
lib/product_photo.php  lib/image.php  lib/video.php  lib/spec.php
```

### Additional functions already built
| Function | In the brief's list? | Where |
|---|---|---|
| Category Maintenance + CRUD | yes | `admin/categories.php` |
| 1 Product = Multiple Photos | yes | `lib/product_photo.php` |
| Multiple Photos Upload | yes | `admin/product_photos.php` |
| Product Photo Sliders (Dynamic) | yes | `includes/product_gallery.php` |
| Drag-and-Drop Photo Upload | yes | `assets/js/dropzone.js` |
| Webcam Integration (Capture Photo) | yes | `includes/webcam.php` |
| Image Processing (Flip, Rotate) | yes | `admin/photo_edit.php` |
| Product Video Integration (YouTube) | yes | `lib/video.php` |
| **Product specifications (EAV)** | **no — extra** | `lib/spec.php` |
| **One spec across several categories** | **no — extra** | `spec_attribute_categories`, migration 34 |
| **Customer-selectable variants** | **no — extra** | `admin/product_options.php` |
| **Apple-style configurator** | **no — extra** | `product_detail.php` |
| **Nameable product photos** | **no — extra** | `admin/product_photos.php` |
| Record Listing (Table + Photo View) | yes | `lib/listing_view.php` |

### Still open — pick from here
- Product import from a supplier URL
- Bulk photo re-ordering by drag
- Product duplication ("save as new")
- Auto-generate a product description from its specs

---

# Slot C — Shopping Cart + Checkout (Member)

### Basic module (4.3)
Product Listing + Detail · Basic Searching · Shopping Cart · Checkout + Create Order

### Pages
```
products.php        product_detail.php      compare.php
cart.php            checkout.php            checkout_success.php
checkout_cancel.php search.php              stores.php
admin/vouchers.php  admin/voucher_form.php
admin/stores.php    admin/store_form.php
lib/cart.php  lib/voucher.php  lib/points.php  lib/store.php
```

> The voucher and store ADMIN screens live here rather than with the other
> admin tools, because this slot already owns `lib/voucher.php` and
> `lib/store.php`. Splitting a feature's library from its screens means two
> people have to answer for one thing.

### Additional functions already built
| Function | In the brief's list? | Where |
|---|---|---|
| Product Filtering (by Category) | yes | `products.php` |
| Product Filtering (by Price Range) | yes | `products.php` |
| Filtering, Sorting and Paging combined | yes | `products.php` |
| Payment (Real — Stripe API) | yes | `checkout.php` |
| **Payment method recorded (FPX / card / GrabPay)** | **no — extra** | `lib/payment.php` |
| Discount Voucher Handling | yes | `lib/voucher.php`, `admin/vouchers.php` |
| Reward Point Handling | yes | `lib/points.php`, `member/points.php` |
| Permanent Shopping Cart (for Member) | yes | `lib/cart.php` |
| Google Maps Integration (Store Location) | yes | `lib/store.php`, `admin/stores.php` |
| AJAX Integration | yes | `lib/ajax.php`, `api/` |
| **Product comparison** | **no — extra** | `compare.php` |
| **Variant-aware cart lines** | **no — extra** | `lib/cart.php` |
| **Live search suggestions** | **no — extra** | `api/search_suggest.php` |
| Remember User Preference (dark theme) | yes | `lib/theme.php` |
| **Theme switch without losing the page** | **no — extra** | `api/set_theme.php`, `assets/js/main.js` |
| **Mobile transition animations** | **no — extra** | `assets/css/style.css` |

### Still open — pick from here
- Recently viewed products
- "Customers also bought" on the product page
- Save cart for later / multiple named carts
- Estimated delivery date at checkout

---

# Slot D — Order Maintenance + Fulfilment

### Basic module (4.3)
Order History + Detail (Member) · Order Listing + Detail (Admin)

### Pages
```
orders.php              order_detail.php        order_cancel.php
receipt.php             delivery_photo.php
admin/orders.php        admin/order_detail.php
admin/qr_scan.php       admin/cancellations.php admin/evidence.php
lib/orders.php  lib/cancellation.php  lib/receipt.php  lib/qrcode.php  lib/address.php
```

### Additional functions already built
| Function | In the brief's list? | Where |
|---|---|---|
| Order Cancellation (Member) | yes | `order_cancel.php` |
| Order Status Update (Admin) | yes | `includes/status_actions.php` |
| E-Receipt (Email or PDF) | yes | `lib/receipt.php` |
| **Receipt names the payment method** | **no — extra** | `includes/receipt_template.php` |
| Shipping Address Handling | yes | `lib/address.php` |
| **Cascading state / city / postcode** | **no — extra** | `lib/postcode.php`, `api/address_lookup.php` |
| Generate + Scan QR Code | yes | `lib/qrcode.php`, `admin/qr_scan.php` |
| **Strict status sequence** | **no — extra** | `lib/orders.php` |
| **Delivery evidence photos** | **no — extra** | `admin/evidence.php` |
| **Cancellation approval workflow** | **no — extra** | `lib/cancellation.php` |
| **Customer-visible delivery photo** | **no — extra** | `delivery_photo.php` |
| **HMAC-signed QR payloads** | **no — extra** | `lib/qrcode.php` |

### Still open — pick from here
- Courier tracking number + a link to the courier's site
- Order status notification email at each step
- Returns / refund request workflow (mirrors cancellation)
- Delivery route list for the driver ("my deliveries today")
- Partial shipment (split one order into two parcels)

### The strongest thing to demo
Scan a QR code on a phone → mark Shipped with a camera photo → the customer sees
that photo on their own order page. It crosses three modules and is hard to fake.

---

# Slot E — Member Maintenance + User Profile + Admin Tools

> Two of the brief's smaller areas, plus the reporting tools. Similar total
> weight to the others.

### Basic modules (4.3) — **two**
**Member Maintenance:** Member Listing + Detail · Basic Searching · Member
Registration · Profile Photo Upload
**User Profile:** Profile Update · Password Update · Profile Photo Upload

### Pages
```
member/profile.php      member/home.php         member/addresses.php
member/address_form.php member/wishlist.php     member/points.php
member/reviews.php      review_form.php
admin/members.php       admin/member_detail.php admin/profile.php
admin/admins.php        admin/admin_form.php    admin/dashboard.php
admin/stock.php         admin/reviews.php
admin/batch_import.php  admin/batch_price.php   admin/batch_delete.php
admin/mail_test.php
lib/wishlist.php  lib/review.php  lib/stock.php  lib/batch.php  lib/mailer.php
```

### Additional functions already built
| Function | In the brief's list? | Where |
|---|---|---|
| Admin Maintenance + CRUD | yes | `admin/admins.php` |
| Add to Favorites or Wishlist | yes | `lib/wishlist.php` |
| Product Rating + Review | yes | `lib/review.php` |
| Product Stock Handling | yes | `lib/stock.php` |
| Low-In-Stock Alert | yes | `admin/stock.php` |
| Batch Insertion (CSV) | yes | `admin/batch_import.php` |
| Batch Updating (Increase Prices) | yes | `admin/batch_price.php` |
| Batch Deletion | yes | `admin/batch_delete.php` |
| Data Charts | yes | `admin/dashboard.php` |
| Top Selling Products (Top 5) | yes | `admin/dashboard.php` |
| **Stock reconciliation report** | **no — extra** | `lib/stock.php` |
| **Batch preview before commit** | **no — extra** | `lib/batch.php` |
| **Points ledger with no cached total** | **no — extra** | `admin/member_detail.php` — the ADMIN view; the member view is C's |

### Still open — pick from here
- Real-Time Chat (member ↔ support) *(in the brief)*
- Export any listing to CSV or Excel
- Sales report filtered by date range
- Member activity log
- Admin notification centre (low stock, pending cancellations, new reviews)
- Scheduled report emailed weekly

---

# Everything in the brief that nobody has built yet

Free to claim. Roughly hardest last.

| Function | Suggested slot | Rough effort |
|---|---|---|
| SMS Integration (security code) | A | medium — needs a gateway account |
| Real-Time Chat | E | large — polling or WebSocket |

Only two of the brief's ~44 examples are untouched, so anyone wanting more
should take from the "still open" lists above instead — those are ideas beyond
the brief, which section 4.4 explicitly invites ("for example, but not limited
to").

---

# Things that are nobody's module and everybody's problem

**The database export.** Section 7.0 requires it and it does not exist. See
`PRE_SUBMISSION_AUDIT.md` §3.1. The clearest way to see the problem: the
migrations run from **07 to 34, and 01–06 do not exist**. Those six numbers are
the six core tables — users, categories, products, cart, orders, order_items —
which were created by hand and never written down. Every later migration assumes
they are already there, so running all 28 of them against an empty database
produces nothing but errors. Without the export a grader cannot build the schema
at all. One person should own producing it **after** the sample data is in.

**The migrations must all be run.** Twenty-nine files: 07–34 plus
`migration_password_reset.sql`, which is unnumbered and easy to skip. A copy of
the project with some applied and some not does not crash — every feature checks
first, with `spec_multi_category_ready()`, `spec_options_ready()`,
`theme_column_ready()` and so on — it just quietly lacks features. That is the
right behaviour for development and a bad surprise on demo day, because nothing
announces the absence. Run them all, then check the *Verify* block at the bottom
of each file.

**`lib/config.php` is in `.gitignore`,** because it holds live Stripe and Gmail
credentials. Correct for secrets, and it means the file is **never updated by a
pull**: add a setting in a new feature and every other copy is silently a version
behind. `lib/init.php` now compares against the tracked `lib/config.example.php` on
startup and lists everything missing at once — take that page seriously if you
see it rather than pasting in one constant at a time.

**The slide.** Section 5.0: cover page, ERD from phpMyAdmin, then a sub-cover and
screenshots per member. Each person produces their own section from the pages
listed in their slot.

> The ERD screenshot will now show `spec_attribute_categories` and `postcodes`.
> Whoever takes B and D should expect to be asked what those two tables are for
> — they are the only pure link/reference tables in the schema, and a tutor
> looking for database-design marks will notice them.
