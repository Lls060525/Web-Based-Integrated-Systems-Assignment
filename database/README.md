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

**Wishlist:** save **four or five products** for one member, and make sure one of
them is out of stock or inactive. The greyed-out card and the "could not be moved"
message prove the page handles the unhappy path, which demonstrates more than a
list of available items does.
