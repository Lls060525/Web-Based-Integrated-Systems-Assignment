# Database notes

XAMPP / MySQL, database name `mobile2u`.

## Schema in use

| Table | Key columns |
|-------|-------------|
| `users` | `id`, `name`, `email`, `password_hash`, `profile_photo`, `role` enum(`admin`,`member`), `status` enum(`active`,`banned`,`deleted`), `created_at` |
| `categories` | `id`, `name`, `description`, `created_at`, `updated_at` |
| `products` | `id`, `name`, `category_id`, `description`, `price`, `image`, `stock`, `status` enum(`active`,`inactive`), `created_at` |
| `cart` | `id`, `user_id`, `product_id`, `quantity`, `added_at` |
| `orders` | `id`, `user_id`, `total_amount`, `shipping_address`, `status` enum(`pending`,`processing`,`shipped`,`delivered`,`cancelled`), `created_at`, plus **`stripe_session_id`**, **`cancel_reason`**, **`cancel_note`**, **`cancelled_at`**, **`cancelled_by`** (all added by the migration) |
| `order_items` | `id`, `order_id`, `product_id`, `quantity`, `price_at_purchase` |
| **`password_resets`** | added by the migration |
| **`order_status_history`** | added by the migration - audit trail of every status change |
| **`addresses`** | added by `migration_08_address.sql` - the member address book |
| **`wishlist`** | added by `migration_09_wishlist.sql` - saved favourites, UNIQUE per member+product |
| **`vouchers`** | added by `migration_10_voucher.sql` - discount codes |
| **`voucher_redemptions`** | added by `migration_10_voucher.sql` - who redeemed what, UNIQUE per order |
| **`login_attempts`** | added by `migration_11_login_blocking.sql` - every sign-in attempt, success or failure |
| **`point_transactions`** | added by `migration_12_points.sql` - append-only reward point ledger |
| **`stock_movements`** | added by `migration_13_stock.sql` - audit trail of every stock change |
| **`reviews`** | added by `migration_14_reviews.sql` - one review per member per product, verified purchase only |
| **`product_photos`** | added by `migration_15_product_photos.sql` - gallery, ordered, one cover per product |
| **`stores`** | added by `migration_23_stores.sql` - branch locations with coordinates |
| **`spec_attributes`** | added by `migration_19_specs.sql` - what a spec IS (name, type, unit) |
| **`product_spec_options`** | added by `migration_20_spec_options.sql` - the choices a customer can pick |
| **`product_specs`** | added by `migration_19_specs.sql` - one value per product per attribute |
| **`remember_tokens`** | added by `migration_18_remember.sql` - long-lived sign-in tokens, one row per remembered device |

`orders` also gains **`receipt_no`**, **`receipt_sent_at`** and **`receipt_sent_count`**
for the E-Receipt module. The migration backfills a receipt number for every
existing order, so old sample data can produce a receipt too.

## Migration files, run in this order

| File | Contents |
|------|----------|
| `migration_password_reset.sql` | Sections 1-7: password reset, Stripe, indexes, cancellation, status history, e-receipt |
| `migration_07_ereceipt.sql` | Just the e-receipt part, split out |
| `migration_08_address.sql` | The `addresses` table and `orders.shipping_address_id` |
| `migration_09_wishlist.sql` | The `wishlist` table |
| `migration_10_voucher.sql` | Vouchers, redemptions, discount columns on `orders`, plus 5 demo vouchers |
| `migration_11_login_blocking.sql` | The `login_attempts` table |
| `migration_12_points.sql` | The point ledger, point columns on `orders`, plus a 500-point welcome bonus for existing members |
| `migration_13_stock.sql` | `products.reorder_level`, the movement ledger, and an opening-stock entry per product |
| `migration_14_reviews.sql` | The `reviews` table |
| `migration_15_product_photos.sql` | The `product_photos` gallery, with existing photos migrated in as covers |
| `migration_16_video.sql` | `products.video_id` and `products.video_title` |
| `migration_17_photo_original.sql` | `product_photos.original_filename` and `edited_at`, so edits can be undone |
| `migration_18_remember.sql` | The `remember_tokens` table for Remember Me |
| `migration_19_specs.sql` | Specification attributes and values, plus 13 sample attributes |
| `migration_20_spec_options.sql` | Per-product specs, customer-selectable specs, cart/order variant columns |
| `migration_21_option_style.sql` | Colour swatches, per-option photo, sold-out toggle |
| `migration_22_render_style.sql` | Display style becomes an explicit setting; clears stray swatches |
| `migration_23_stores.sql` | The `stores` table, plus 5 sample branches with real coordinates |

**phpMyAdmin stops at the first error.** If you have already run part of a file,
re-running the whole thing halts at *"Duplicate column name"* and never reaches the
later sections. That is why the newer modules ship as their own small files — run
the one you still need rather than the whole thing again.

## Important: ENUM values are lowercase

`orders.status` is `enum('pending','processing','shipped','delivered','cancelled')`.

MySQL compares ENUMs case-insensitively, so inserting `'Pending'` silently stores
`'pending'`. **PHP's `===` is case-sensitive**, so any code comparing against
`'Pending'` would never match what comes back out of the database. That is why
`ORDER_STATUSES` in `lib/config.php` holds the lowercase values and
`ORDER_STATUS_LABELS` holds the capitalised display text. Keep it that way.

The same applies to `users.status` and `products.status`.

## Before submission

Section 7.0 requires a **database export file (SQL format)**:

- phpMyAdmin → select `mobile2u` → **Export** tab
- Export method: **Custom**
- Format: **SQL**
- Tick **Add DROP TABLE / VIEW / PROCEDURE** and **Add CREATE DATABASE**
- Make sure **Data** is included so the sample records go with it
- Save as `database/mobile2u.sql`

## Sample data reminder

The brief asks for sufficient pre-inserted sample data. Aim for at least:

- **At least 2 admins** (the system refuses to block or delete the last active
  one, so with only a single admin the Admin Maintenance module cannot be demoed).
  Add a third with `status = 'deleted'` to show the Restore flow.
- 4 or 5 members, with **one set to `banned`** so Block/Unblock can be demonstrated
- 4 or 5 categories
- 15 to 20 products across those categories, with **two or three below their
  reorder level** and at least one at **zero** so the Low-In-Stock Alert and the
  Out-of-stock state both have something to show. Set different reorder levels on
  different products to make the point that the threshold is per product.
- Orders in several different statuses:
  - one `pending` **and** one `processing` so the member Cancel Order button appears
  - one `shipped` so you can show cancellation is correctly refused after dispatch
  - one `delivered` so the admin sees *"Workflow complete"* and no dropdown, which
    demonstrates the state machine better than any successful update does
  - one `cancelled` so the cancellation panel and the Reinstate path can be shown

The migration seeds `order_status_history` for orders that already exist, so the
timeline is never empty on sample data created before this module.

**Addresses:** give at least one member **two or three saved addresses** with one
marked default, so the checkout picker has something to choose between. A member
with no address is also worth showing — checkout correctly refuses to proceed and
sends them to add one.

**Vouchers:** the migration already seeds five, including `EXPIRED5` (expired) and
`SOLDOUT` (usage limit reached). Try both during the demo — the rejection messages
prove the validation is real, which shows more than a successful discount does.
`WELCOME10` and `SAVE50` are the ones that work.

**Product video:** add a YouTube link to one or two products on
**Admin → Products → Edit**. Paste different URL shapes to show the parser
handling them — a normal `watch?v=` link, a `youtu.be` short link, and one with a
`&t=` timestamp all reduce to the same id. Pasting something that is not a YouTube
address is rejected with a message. On the storefront, point out that the video
tab shows a poster and **nothing loads from YouTube until you press play**.

**Product photos:** the migration moves every existing `products.image` into the
gallery as that product's cover, so nothing looks empty afterwards. Give **one or
two products three or four photos each** — the slider and the thumbnail strip only
make sense with more than one. Demo the drag-to-reorder and "Make Cover" on
**Admin → Products → the photo count button**, then reload the storefront page and
show the order and cover changed to match.

**CAPTCHA:** no database change, but run `composer require gregwar/captcha`.
Check **Admin → Mail & PDF**, which reports the driver state. The best thing to
demo is the **login** one: sign in correctly first and point out there is no
CAPTCHA at all, then get the password wrong once and watch it appear. That shows
it is targeted rather than a blanket tax on every visitor. Registration and forgot
password always show one.

**Image processing:** run `migration_17_photo_original.sql` so edits can be undone. Needs the **GD extension** — check
**Admin → Mail & PDF**, which now lists it. If it is off, uncomment
`;extension=gd` in `C:\xampp\php\php.ini` and restart Apache.

Demo it from **Admin → Products → photo count → Edit** on any photo. Rotate twice,
then press **Restore Original** — the editor shows the original and the edited
version side by side, so the undo is visible rather than something you have to
take on trust. Then reload the storefront to show the change followed the photo everywhere, not
just on the admin screen. The best thing to show is a **photo taken on a phone**:
it uploads upright because the EXIF orientation is applied on the way in, which is
the failure most sites have.

**Webcam capture:** no database change. **Open the site as
`http://localhost/` for this demo** — browsers refuse camera access over a LAN IP
such as `http://192.168.1.5`, and the dialog will say so rather than appearing
broken. Click *Use camera* on any photo field, allow the permission, capture, and
show that the shot lands in the same preview as a dragged file and uploads through
the same validation. Denying the permission is also worth showing: the message
explains how to re-enable it.

**Drag-and-drop upload:** no database change and nothing to seed. Demo it by
dragging an image onto the photo field on **Admin → Products → Edit**. Worth
showing two failure paths as well: drag a file that is too large, and drag a
non-image — both are rejected in the browser instantly, and the server would reject
them again even if the browser check were bypassed.

**Reviews:** nothing is seeded, because a review requires a real completed order —
that is the security property, so faking one in SQL would undermine the demo. To
show it properly, set one member's order to `shipped`, log in as them, and the
"Write a review" button appears. Then try to reach
`/review_form.php?product=N` for a product they never bought: it refuses. That
refusal is the most convincing part of the module.

**Stock:** the migration writes an opening-stock entry for every existing product,
so no ledger is empty. Demo the reconciliation line on **Admin → Stock** — it
confirms every product's column matches its movement history. Then record a restock
and place an order, and show both appearing in the same history.

**Reward points:** the migration gives every existing active member a 500-point
welcome bonus, which is enough to redeem straight away (minimum is 100). Demo the
full loop: redeem points at checkout, see them deducted in the ledger, then cancel
the order and watch the spent points come back while the earned ones are removed.
The ledger makes every step visible, which is the point of the design.

**Login blocking:** nothing to seed. Demo it live — type a wrong password three
times and the lockout panel appears with a running countdown. Then show
**Admin → Login Security**, where the attempt is logged with its IP, and release
the lock with the Unlock button. Locking a *non-existent* email is worth showing
too: it proves the system does not leak which addresses are registered.

**Specifications:** run `migration_19_specs.sql`. It seeds 13 sample attributes
(display size, RAM, storage, battery, 5G, cable length, warranty and so on). The
category is matched by name via a subquery, so if your categories are not called
*Smartphones* and *Accessories* the seed still succeeds &mdash; those attributes
simply become global instead of failing.

**Fill in at least three phones in the same category**, and make sure they differ
on two or three specs and agree on the rest. That is what makes the comparison
page demonstrate anything.

Three things to show:

1. **Admin → Specs** defines the *attributes*; **Admin → Products → the tick-list
   button** fills in the *values*. Point out that the value form is built from the
   product's category, so a phone gets RAM and battery while a cable gets length
   and wattage &mdash; without anyone maintaining two forms.
2. **The catalogue filter.** Pick a category first (spec filters only appear
   inside one, because attributes belong to categories). Set *RAM* to a minimum
   and watch the list narrow. Worth saying out loud: the numeric value is stored a
   second time in a `DECIMAL` column, because on a `VARCHAR` the comparison
   `'12' >= '8'` is **false** &mdash; strings compare character by character, so a
   RAM filter would silently drop every 12GB and 128GB phone.
3. **Compare.** Open a phone, choose another underneath the spec table. The page
   opens showing *only the rows that differ*; the toggle brings the identical ones
   back. On two phones from one maker most rows are the same and the three that
   differ are the whole reason anyone opened the page.

Also worth demoing: an attribute whose products all share one value is **not**
offered as a filter at all. A filter that matches everything is noise.

**Specs part 2 (`migration_20_spec_options.sql`):** run it after 19. It adds two
things.

**Add a spec to one product only.** On **Admin → Products → the tick-list button**
there is now an *Add a spec to this product* box. Type a name and a value and it
exists immediately, attached to that product alone &mdash; no other product in the
category grows an empty field for it. Use **Admin → Specs** instead when you do
want the whole category to have it.

**Customer-selectable specs (variants).** Tick *the customer chooses this*, then
set the choices under **Manage Customer Choices**. Each choice can carry a price
difference, so 256GB can be RM 300 more than 128GB.

The demo worth doing:

1. Give a phone a **Colour** (Black RM 0, White RM 50) and a **Storage**
   (128GB RM 0, 256GB RM 300).
2. On the storefront the price now **follows the selection**. The catalogue grid
   shows *Choose Options* instead of *Add to Cart*, because there is nowhere in a
   grid to pick a colour and defaulting on the customer's behalf would be
   dishonest.
3. Add **Black 256GB**, then add **White 256GB**. Two separate cart lines, priced
   differently. Then add **Black 256GB** again &mdash; it merges into the first
   line at quantity 2 rather than making a third.
4. Place the order, then go to **Admin → Manage Customer Choices** and **delete
   the White option**. Open the old order: it still says White, at the price that
   was actually charged. A cart still holding it says *no longer offered* and
   checkout refuses to proceed.

Step 4 is the point. `order_items.options_text` and `price_at_purchase` are
snapshots for the same reason: an admin editing the catalogue must not rewrite
what somebody already bought.

**Specs part 3 (`migration_21_option_style.sql`):** makes the picker behave like a
product configurator rather than a set of radio buttons.

Set it up on one phone under **Admin → Products → tick-list → Manage Customer
Choices**:

- Give each colour a **swatch**. The group then renders as colour circles instead
  of text, inferred from the data &mdash; an attribute whose options have colours
  *is* a colour attribute, so there is nothing extra to configure.
- Point each colour at a **photo** from that product's gallery. Choosing the
  colour switches the main image, which is the most recognisable thing about the
  Apple page. It drives the existing slider by clicking its thumbnail, so the
  counter and active state stay correct for free.
- Mark one choice **sold out**. It greys out and cannot be selected, and the
  page will not open on it either &mdash; the default falls through to the first
  option that is actually available.

Then demo the honest part: **grey is only a hint**. Open dev tools, remove the
`disabled` attribute from the sold-out radio, select it and press Add to Cart.
The server refuses it, because `spec_validate_selection()` re-checks availability
against the database. That is worth more than any amount of styling.

Storage-style options render as **tiles** with the price difference underneath
(*Included*, *+RM 300*), and a running summary lists the base price, each chosen
extra and the configured total, updating as you click.

**Specs part 4 (`migration_22_render_style.sql`):** fixes a real bug and tidies the
buying flow. Run it after 21.

The bug: the storefront used to decide "this is a colour spec" by checking whether
any of its options had a colour saved. But `<input type="color">` posts `#000000`
even when nobody touches it, so **RAM options quietly picked up a black swatch and
RAM started rendering as colour circles**. Display style is now a setting on the
attribute (*Tiles* or *Colour swatches*), and the migration clears the stray
`#000000` values that the old behaviour left behind.

The colour box now only appears for a spec set to *Colour swatches*, and the
server ignores a posted colour for anything else &mdash; so it cannot come back.

The migration also sets colour to sort first, because every phone site asks for
the finish before the storage: the colour changes the photo, so you see the thing
before you configure it.

The resulting flow, on a configured phone:

1. **Choose your colour** &mdash; swatches, main image changes as you click
2. **Choose your storage** &mdash; tiles reading *Included* / *+RM 300*
3. **Your &lt;phone&gt;** &mdash; a recap listing base price and each extra
4. A **sticky bar** follows the page with the running total and Add to Cart, so
   scrolling down to the specs and reviews does not lose the configuration

**Store locator (`migration_23_stores.sql`):** seeds five real Malaysian locations
with real coordinates, so the map has something on it straight away.

**It works with no Google API key.** That matters, because the Maps JavaScript API
needs a key and a key needs a billing account with a card on it. `MAP_DRIVER` in
`lib/config.php` chooses:

| Driver | Needs a key | What you get |
|--------|-------------|--------------|
| `embed` (default) | no | A real, interactive Google map of the selected store, in an iframe |
| `js` | yes | One map with every store as a marker, info windows, fit-to-bounds |

So the keyless version is a working map, not a placeholder. **Admin → Mail & PDF**
reports which driver is active.

What to demo:

1. **Admin → Stores → Add Store.** Instead of typing coordinates, open Google
   Maps, find the shop, and paste the address bar. The coordinates are read out of
   the URL. Show the failure too: paste a shortened `maps.app.goo.gl` link and it
   refuses with an explanation, because the coordinates genuinely are not in it.
2. **The locator.** Open `/stores.php`, click between branches, use *Get
   Directions* to hand off to the customer's own maps app.
3. **Find my nearest store.** Allow the location prompt and the list reorders by
   distance. Say out loud that the distances are computed **in the browser** &mdash;
   the visitor's position is never sent to the server. Open it as
   `http://localhost/`; geolocation needs a secure context exactly like the camera,
   and a LAN IP is refused with a message rather than failing silently.

Worth pointing out in the schema: `latitude`/`longitude` are `DECIMAL(10,7)`, not
`FLOAT`. Binary floats cannot store `3.139003` exactly, so coordinates drift and
comparisons stop being reliable &mdash; and these are numbers used for distance
maths.

**AJAX: no migration needed.** Three things to demo:

1. **Live search.** Type in the header search box. Suggestions appear after two
   characters, with thumbnails and prices, navigable with the arrow keys and
   Enter. Worth saying: results that *start* with what you typed are ranked above
   ones that merely contain it.
2. **Load More** at the bottom of the catalogue. Point out that the numbered
   pagination is **still there** &mdash; the button was added on top of it, so
   turning JavaScript off leaves a working catalogue.
3. **Inline email check** on the registration form. Type an address that already
   exists and it says so before you finish filling the form in.

If asked what is interesting about it, the answer is the out-of-order response
problem: type `iph`, pause, finish typing `iphone`, and on a slow connection the
first response can come back last and overwrite the right results. Debouncing does
not fix that; a per-request sequence number does.

**QR codes: no migration needed.** Run `composer require endroid/qr-code` and set
`QR_SECRET` in `lib/config.php` to a long random string. For the *scanner*, also
save `jsQR.js` into `assets/js/vendor/` — see the README in that folder. It is
optional (the scanner falls back to a CDN) but it makes the demo work offline.

Note that changing `QR_SECRET` changes every reference code, which is the point:
codes minted under the old secret stop validating. Both are checked on
**Admin → Mail & PDF**.

The demo that makes the design land is the one about *what is inside the code*.
Open a receipt, right-click the QR and note the URL it encodes:
`/verify.php?t=o42.61b7e3...`. Then explain the version that was rejected:
`/verify.php?order=42`. That one works too, and it also lets anybody change 42 to
43 and read somebody else's receipt. The signature is what stops that, so try it
live &mdash; edit one character of the token in the address bar and the page
refuses it.

Three things to show:

1. **Generate.** A member's order page shows a collection QR with a typed
   reference underneath it (`M2U-001A-61B7E3`). The PDF receipt carries the same
   pair. Point out that the PDF embeds the image as a `data:` URI rather than
   linking to `/api/qr_image.php`, because Dompdf runs with `isRemoteEnabled`
   off &mdash; and that setting is deliberate, since it is what stops a crafted
   receipt from making the server fetch arbitrary URLs.
2. **Scan.** **Admin → Scan QR**, with the receipt on a phone screen. Open the
   page as `http://localhost/` &mdash; the camera is refused on a LAN IP and the
   page says so rather than looking broken. Decoding runs in the browser; only
   the decoded text is posted back, so the video never leaves the machine.
3. **The fallback.** Type the reference by hand instead. Every counter system has
   one, because phones get dropped and receipts get wet. Then type it with the
   dashes removed and in lower case to show it still resolves, and change one
   character to show it does not.

**Batch tools: no migration needed.** The three batch pages work on the tables
that already exist, so there is nothing to run. Sample files live in
`database/samples/`:

| File | What it demonstrates |
|------|----------------------|
| `products_import.csv` | A clean import. Columns are in the "wrong" order on purpose, one product name contains a comma, and one description spans two lines |
| `products_import_broken.csv` | Six different validation failures in seven rows |
| `products_import_semicolon.csv` | Semicolon-separated, as Excel exports on a European locale |

**Insert** &mdash; import `products_import.csv` first and point out that the column
order does not matter, because columns are matched by name. Then open the file in
a text editor and show the row `"Galaxy S24 Ultra, 512GB"`: the name contains a
comma, and splitting the line on commas would produce seven fields instead of six
and shift every value one column left. The parser uses `fgetcsv`, which respects
the quotes.

Then import `products_import_broken.csv`. Every row fails for a different reason
and the preview names each one. Run it once with **all or nothing** ticked
(nothing is imported) and once without (the good rows land, the bad ones are
listed). That contrast is the demo &mdash; it shows the failure policy is a real
choice rather than a label.

**Update** &mdash; the honest demo is a mistake caught by the preview. Choose
*Multiply by a factor* with a value of `10` across all products and stop at the
preview: the old-to-new table and the *change in stock value* figure make the
error obvious before anything is written. Cancel, then do the real thing:
increase Accessories by 10% and round to `.99`. Worth pointing out that the
rounding will never turn a decrease into an increase, which is a real edge case
on cheap products where the `.99` grid is coarser than the discount itself.

**Delete** &mdash; select a product that has been ordered and one that has not, and
choose *Delete permanently*. The one with order history comes back marked
**protected**. That is the point of the module: member order history renders each
line with an `INNER JOIN` back to `products`, so deleting an ordered product would
make those lines silently vanish from the customer's past orders while the order
total stayed the same. Deactivation is offered instead. Deleting the unordered
product also removes its photos from disk.

**Remember Me:** run `migration_18_remember.sql`. Nothing to seed — a token
only exists once someone ticks the box.

The demo that actually proves the design is the **theft detection**, and it takes
two browsers:

1. In Chrome, sign in with **Keep me signed in** ticked. Open DevTools →
   Application → Cookies and copy the value of `mobile2u_remember`.
2. Close the tab and reopen the site — you are still signed in, no password. Show
   **My Profile → Signed-in Devices**: the device is listed as *This device*.
3. Now paste that copied cookie into **Firefox** (a stand-in for a stolen cookie).
4. Refresh **Chrome** first. Still signed in — and the cookie value in DevTools has
   *changed*, because the validator rotates on every request.
5. Refresh **Firefox**. It is thrown out, **and so is Chrome on its next refresh**.
   Both have to sign in again.

Step 5 is the point: the site cannot tell which browser is the thief, so it
distrusts both rather than guessing. Explain that this is only possible because
the *selector stays the same* while the *validator rotates* — if the whole token
were replaced, the stale copy would simply look expired and nothing would be
detected.

Also worth showing: **change your password**, then reload — every remembered
device is revoked, because the old password may be how the cookie was planted in
the first place. Same when an admin blocks a member: their cookies die with the
account, so blocking is not undone by a browser that never logged out.

**Wishlist:** save **four or five products** for one member, and make sure one of
them is out of stock or inactive. The greyed-out card and the "could not be moved"
message prove the page handles the unhappy path, which demonstrates more than a
list of available items does.
