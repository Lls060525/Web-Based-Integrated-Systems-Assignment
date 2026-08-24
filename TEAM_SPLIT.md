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
| User Email Verification (Email) | yes | `verify.php` |
| **Role + Permission Management** | **no — extra** | `lib/role.php`, `admin/roles.php` |
| **Per-transition authorisation** | **no — extra** | built on this slot's `can()`, demoed by D |
| **CSRF protection on every POST** | **no — extra** | `lib/security.php` |
| **Post/Redirect/Get on 21 forms** | **no — extra** | `lib/prg.php` |

### Still open — pick from here
- SMS Integration (security code on login)
- Two-factor authentication by email code
- Session timeout with a warning countdown
- Login history visible to the member ("last signed in from…")

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
| **Customer-selectable variants** | **no — extra** | `admin/product_options.php` |
| **Apple-style configurator** | **no — extra** | `product_detail.php` |
| **Nameable product photos** | **no — extra** | `admin/product_photos.php` |

### Still open — pick from here
- Record Listing: Table View + Photo View toggle *(in the brief, not yet built)*
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
| Discount Voucher Handling | yes | `lib/voucher.php`, `admin/vouchers.php` |
| Reward Point Handling | yes | `lib/points.php`, `member/points.php` |
| Permanent Shopping Cart (for Member) | yes | `lib/cart.php` |
| Google Maps Integration (Store Location) | yes | `lib/store.php`, `admin/stores.php` |
| AJAX Integration | yes | `lib/ajax.php`, `api/` |
| **Product comparison** | **no — extra** | `compare.php` |
| **Variant-aware cart lines** | **no — extra** | `lib/cart.php` |
| **Live search suggestions** | **no — extra** | `api/search_suggest.php` |

### Still open — pick from here
- Remember User Preference (e.g. dark theme) *(in the brief)*
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
| Shipping Address Handling | yes | `lib/address.php` |
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
| Record Listing (Table View + Photo View) | B | small |
| Remember User Preference (e.g. theme) | C | small |
| SMS Integration (security code) | A | medium — needs a gateway account |
| Real-Time Chat | E | large — polling or WebSocket |

Only four of the brief's ~44 examples are untouched, so anyone wanting more
should take from the "still open" lists above instead — those are ideas beyond
the brief, which section 4.4 explicitly invites ("for example, but not limited
to").

---

# Two things that are nobody's module and everybody's problem

**The database export.** Section 7.0 requires it and it does not exist. See
`PRE_SUBMISSION_AUDIT.md` §3.1 — the six core tables are not in any migration, so
without the export a grader gets nothing. One person should own producing it
**after** the sample data is in.

**The slide.** Section 5.0: cover page, ERD from phpMyAdmin, then a sub-cover and
screenshots per member. Each person produces their own section from the pages
listed in their slot.
