# Optimisation pass

Three problems, fixed in the order they mattered: pagination first because it
was a visible functional gap, then indexes because they were the cheapest win,
then the N+1 queries.

---

## 1. Pagination

**The problem.** No admin listing had a `LIMIT`. Members, products, orders,
vouchers, stock, reviews and login attempts all selected every matching row and
rendered every one of them.

`login_attempts` was the urgent one. It gains a row on *every* sign-in attempt,
successful or failed, so it is the first table to reach thousands of rows. It did
have a cap — a flat `LIMIT 100` — but with no way to page back, which meant the
audit trail existed and could not actually be audited.

**The fix.** A shared pager in `lib/paginate.php`:

| Function | Does |
|----------|------|
| `paginate($total, $perPage)` | clamps the requested page, returns page/offset/pages/from/to |
| `pager_limit($p)` | the `LIMIT n OFFSET n` clause |
| `pager_url($page)` | the same URL with only the page changed |
| `pager_body($p)` | the markup, returned as a string |
| `render_pager($p)` | the markup, echoed inside a container |

Applied to eight listings:

`admin/login_attempts.php` · `admin/members.php` · `admin/products.php` ·
`admin/orders.php` · `admin/vouchers.php` · `admin/stock.php` ·
`admin/reviews.php` · `orders.php` (the member's own order history)

**Three things worth knowing about how it works.**

*The page number is clamped, not trusted.* `?page=-5` and `?page=99999` both have
to land somewhere sensible, and an offset built from a negative number is a SQL
error rather than an empty result.

*`LIMIT` and `OFFSET` are interpolated, not bound.* MySQL rejects a placeholder
there when PDO emulates prepares. That is safe **only** because both numbers come
out of `paginate()`, which produced them with `(int)` casts from a clamped page
number. No request text reaches that string.

*Each listing builds its `FROM`/`WHERE` once and uses it twice* — once for
`COUNT(*)`, once for the page. If the two were written separately they would
eventually drift and the pager would advertise pages that do not exist.

**The pager had to survive the AJAX live search.** Five of these listings answer
an AJAX request with table rows only. Rows alone are no longer enough: after a
search the row set changes, so the page count changes with it. The response is now
`rows` + `<!--pager-->` + `pager markup`, split by `assets/js/admin.js`. They
cannot simply be concatenated into the `tbody`, because a `<nav>` placed inside a
`<tbody>` is hoisted out of the table by the HTML parser and would appear above it.

**One bug found on the way.** The live search sent only `q`. `admin/members.php`
carries a hidden `status` and `admin/stock.php` a hidden `filter`, so typing in
the search box inside a filtered view quietly searched the *unfiltered* table. It
now sends `$form.serialize()`, which picks up every hidden field.

---

## 2. Indexes

**The problem.** Every table this project *added* was indexed as it was created —
addresses, wishlist, vouchers, login_attempts, points, stock_movements, reviews,
product_photos, remember_tokens and the spec tables all declare their keys in
their own migration.

The six tables from the **original schema** never got the same treatment.
`users`, `products`, `categories`, `orders`, `order_items` and `cart` had a
`PRIMARY KEY` and nothing else, so every filter and every sort on them was a full
table scan.

**The fix.** `database/migration_24_indexes.sql`, thirteen indexes:

| Table | Index | Why |
|-------|-------|-----|
| products | `(status, category_id)` | the catalogue query, always |
| products | `(status, price)` | price filter and the price sorts |
| products | `(stock)` | admin stock page sorts by stock ascending |
| products | `(category_id)` | the join back to categories |
| products | `(name)` | sorting the catalogue by name |
| orders | `(user_id, created_at)` | "my orders, newest first" |
| orders | `(created_at)` | admin order list |
| orders | `(status)` | status filters on both lists |
| order_items | `(order_id)` | every receipt and order detail page |
| order_items | `(product_id)` | top-selling report, delete-safety check |
| users | `(role, status)` | admin member list |
| users | `(email)` | every sign-in |
| categories | `(name)` | the category dropdown |

Column order is not arbitrary. MySQL uses a composite index left to right, so
`(status, category_id)` serves both `WHERE status = 'active'` and
`WHERE status = 'active' AND category_id = ?`, while `(category_id, status)`
would serve only the second. `(user_id, created_at)` lets MySQL find the member's
orders *and* return them already sorted, with no filesort.

**This migration is safe to re-run.** A plain `ALTER TABLE ... ADD KEY` fails with
"Duplicate key name", and phpMyAdmin stops at the first error — so one
already-present index would silently skip everything after it. A helper procedure
checks `information_schema` first and skips when:

1. the table does not exist (an optional module was never installed),
2. an index of that name is already there,
3. an index over exactly those columns exists under a **different** name — InnoDB
   creates one automatically for every foreign key, so `products.category_id` and
   `order_items.order_id` may already be covered, and a second index over the same
   columns costs disk and slows every INSERT for nothing.

---

## 3. N+1 queries

A query inside a loop. Fine on sample data; multiplies with real volume.

### `batch_delete_analysis()` — 6N+1 → 7 queries

Six `COUNT(*)` per product, checking orders, cart, wishlist, reviews, photos and
stock movements. Selecting 100 products for deletion cost **601 round trips**.
Each count is now one `GROUP BY` over the whole selection — six queries no matter
how many products were picked. A product with no related rows returns no row from
`GROUP BY`, so an absent key reads as zero.

### `spec_describe_signature()` — nested two deep → 1 query per page

One query per chosen attribute, inside a loop over cart lines. A five-line cart
with three options each cost fifteen queries just to print the labels.

Now `spec_prefetch_options()` loads every line's options in one query and
`spec_option_map()` answers from a request-lifetime cache. Called at the top of
`cart.php`, `checkout.php` and `checkout_success.php`. Forgetting the prefetch is
not an error, only slower — the map still fills itself on demand.

The cache is deliberately **per request only**. These labels are recomputed rather
than stored on the cart line so that an admin editing a price delta shows up
before checkout instead of after; caching across requests would undo that.

### `spec_filter_options()` — 2N+1 → 3 queries

One `MIN`/`MAX` or one `GROUP BY` per filterable attribute. With the 13 seeded
attributes that was 14 queries on **every load of the shop's busiest page**. Now
one query for the numeric ranges, one for the text values, grouped by
`attribute_id`.

### `product_selectable_specs()` — N+1 → 2 queries

One query per attribute to fetch its choices. Now one query for all of the
product's options, grouped in PHP.

### The product grid — 12N → 1 query

The worst one, and it was on the catalogue page. Every product tile called
`product_selectable_specs()` purely to decide between an "Add to Cart" button and
a "Choose Options" link. Twelve tiles meant twelve full attribute queries plus
their own N+1 underneath.

The tile only ever needed a yes/no, so it now asks `product_needs_choice()`.
`render_product_grid()` prefetches the answer for every tile in one query before
drawing any of them. `api/products_page.php` renders cards directly rather than
through the grid helper, so it does the same prefetch itself.

### Left alone deliberately

- **Writes inside loops** (`checkout_success.php` inserting order lines,
  `lib/product_photo.php` reordering photos). One row, one INSERT — that is what
  the loop is for.
- **`batch_insert_products()` looking up category names.** It loops over the
  distinct *new* categories in a CSV, which is a handful, and each one needs its
  own INSERT regardless.
- **`cart`** got no new index. `migration_20` already added
  `(user_id, product_id, options_signature)`, and because `user_id` is leftmost
  that index already answers `WHERE user_id = ?`.

---

## Still outstanding before submission

Unchanged by this pass, listed again because they are easy to forget:

1. `APP_DEBUG` is still `true` — set it to `false`
2. `MAIL_MODE` is `'prod'` — it really sends email
3. Run migrations 19–24 if you have not
4. Export `database/mobile2u.sql`
5. `config/database.php` is a dead, unreferenced shim and can be deleted
6. Never re-add `lib/config.php` to git — it is in `.gitignore`, and
   `lib/config.example.php` is the template to hand in
