# Pre-submission audit

A full pass over the project, checked against **BMIT2013 Assignment (202605).pdf**
rather than from memory. Everything below was found by scanning every file, not
by spot-reading.

---

## Summary

**One code defect was found and fixed**, plus one piece of dead CSS.

Correction to an earlier version of this document, which said no code
defects were found: `admin/spec_form.php` contained **two** top-level
`if (is_post())` blocks. The second (133 lines) was the pre-PRG original,
left behind when the newer handler was added above it rather than
replacing it. It was unreachable -- the first block always exits, via
`redirect()` on success or `redirect_back()` on failure -- and it also
referenced `$found`, which is undefined on the "add" path, emitting a
warning to the error log on every failed validation. Removed; the file
went from 470 to 337 lines.

The earlier sweep missed it because it checked whether every file
PARSED, not whether every block could be REACHED. Brace balance says
nothing about dead code.

The risks that remain are **not bugs** — they are things that must be done before
the ZIP is handed in, and one of them is a required deliverable that does not
exist yet.

| | |
|---|---|
| Code defects | **1** (fixed) |
| Dead code removed | 2 (duplicate `.toggle-btn` CSS; 133 dead lines in `spec_form.php`) |
| Basic modules missing | **0 of 19** |
| Additional modules implemented | **36+** of the ~44 listed |
| Blocking submission issues | **3** |

---

## 1. Static analysis — what was checked and what was found

| Check | Result |
|-------|--------|
| PHP brace/paren balance, all 130 files | balanced |
| JavaScript syntax, all 8 files | parses |
| CSS brace balance, both files | balanced |
| Functions called but never defined | none (8 apparent hits were prose inside strings) |
| POST entry points with no CSRF check | none |
| `/admin` pages with no permission guard | none (only `profile.php`, deliberately) |
| Request data interpolated into SQL | none |
| Request data echoed without `e()` | none |
| `include`/`require` pointing at a missing file | none |
| Inline `onclick`/`onchange` handlers (brief forbids) | none |
| Banned frameworks (Bootstrap, React, Vue, Laravel…) | none |
| Duplicate CSS selectors that actually conflict | 1, removed |

### The one real finding

`assets/css/admin_sidebar.css` declared `.toggle-btn` twice. The second block set
every property the first one did — `background`, `border`, `font-size`, `cursor`,
`color` — so the first block was entirely dead. Removed.

The other six duplicate selectors in `style.css` (`body`, `.nav a:hover`,
`.product-card`, `.profile-content .card`, `.form-actions.text-right`, and the
`.form-standard input` group) were each checked property by property: **none of
them overlap**, so they are untidy rather than broken. Left alone, because
merging them risks changing cascade order for no gain.

---

## 2. Requirement coverage

### 4.3 Basic modules — all 19 present

| Requirement | Where |
|---|---|
| Roles: Admin + Member | `lib/auth.php`, `lib/role.php` |
| Login + Logout | `auth/login.php`, `auth/logout.php` |
| Password Hashing | `password_hash()` with `PASSWORD_DEFAULT`, rehash on login |
| Password Reset | `auth/forgot_password.php`, `auth/reset_password.php` |
| Profile Update / Password Update / Photo | `member/profile.php`, `admin/profile.php` |
| Member Listing + Detail (Admin) | `admin/members.php`, `admin/member_detail.php` |
| Basic Searching (Admin) | AJAX live search on 6 listings |
| Member Registration | `auth/register.php` |
| Product Listing + Detail | `products.php`, `product_detail.php` |
| Product CRUD | `admin/products.php`, `admin/product_form.php` |
| Product Photo Upload | `admin/product_photos.php` |
| Shopping Cart | `cart.php`, `lib/cart.php` |
| Checkout + Create Order | `checkout.php`, `checkout_success.php` |
| Order History + Detail (Member) | `orders.php`, `order_detail.php` |
| Order Listing + Detail (Admin) | `admin/orders.php`, `admin/order_detail.php` |

### 4.4 Additional modules — 34+ of the ~44 examples

Implemented: Category CRUD · Admin CRUD · Order Cancellation · Order Status
Update · Stripe payment · E-Receipt (email + PDF) · Shipping addresses ·
Wishlist · Vouchers · Reward points · Stock handling · Low-stock alert · Ratings
and reviews · Filtering by category · Filtering by price range · Filtering +
sorting + paging combined · Multiple photos per product · Multiple photo upload ·
Dynamic photo slider · Drag-and-drop upload · YouTube video · Webcam capture ·
Image processing · Batch insert from CSV · Batch price update · Batch delete ·
Email verification · CAPTCHA · Temporary login blocking · Block/unblock account ·
Remember Me · Google Maps store locator · QR generate + scan · Permanent cart ·
Data charts · Top selling products · AJAX · Product comparison · Record
Listing (table view + photo view) · Remember user preference (dark theme)

Beyond the brief's list: **role and permission management**, **per-transition
authorisation**, **delivery evidence photos**, a **cancellation approval
workflow**, and the **payment method recorded and shown on the receipt**
(FPX with the customer's bank, card brand and last four, or GrabPay).

Not implemented (the brief only asks for "some"): SMS integration, real-time
chat.

### 2.x Technology and convention rules

| Rule | Status |
|---|---|
| HTML5 + CSS3, no template | own CSS, ~4900 lines |
| jQuery rather than plain JS | jQuery throughout; no inline handlers anywhere |
| PHP 8.2+ | uses `match`, enums in constants, named arguments, `str_contains` |
| MySQL via **PDO** | every query is a PDO prepared statement |
| No PHP/CSS/JS frameworks | none present |
| Small libraries allowed | Stripe, Dompdf, PHPMailer, gregwar/captcha, endroid/qr-code — all permitted by the NOTE in 2.2 |
| Reusable base library + HTML helpers | `lib/` (25 files), `html_text()`, `html_select()`, `field()`, `detail_row()`, … |
| Server-side validation with error messages | `lib/validation.php`, `add_err()`/`err()`/`err_summary()` |
| Authorisation protecting pages | `require_admin()`, `require_permission()` on all 31 admin pages |

---

## 3. Blocking issues — do these before submitting

### 3.1 The database export does not exist — **required deliverable**

Section 7.0 requires a **database export file (SQL format)**. There is no
`database/mobile2u.sql`, and `.gitignore` excludes it.

This matters more than it looks. The six core tables — `users`, `products`,
`categories`, `orders`, `order_items`, `cart` — are **not created by any
migration**. They came from the original schema, which was never checked in. So a
grader who takes only the ZIP and runs the 23 migration files gets nothing:
`migration_07` immediately tries to `ALTER TABLE orders`, which does not exist.

The export is the only thing that carries those tables.

```powershell
cd C:\xampp\mysql\bin
.\mysqldump.exe -u root --databases mobile2u --routines --events `
  > D:\Web-Based-Integrated-Systems-Assignment\database\mobile2u.sql
```

Then check the file is not empty and contains `CREATE TABLE `users``.

Section 4.2 also says *"ensure you pre-insert sufficient sample data"* — export
**after** the sample products, members and orders are in.

### 3.2 `lib/config.php` still holds live secrets

The file is correctly untracked by git, **but a ZIP does not read `.gitignore`**.
It currently contains a working Stripe test key and a working Gmail App Password.

Before zipping: delete `lib/config.php`. `lib/config.example.php` is the template
that should be handed in.

### 3.3 `MAIL_MODE` is `'prod'`

The system will really send email during the demonstration. That is a live
dependency on Google and on the room's network. Either test it beforehand on the
same network, or switch to `'dev'` and demonstrate `storage/mail.log` plus the
PDF download, which cannot fail.

---

## 4. Worth knowing before the demo

**`CAPTCHA_DRIVER` is `'recaptcha'`.** Google reCAPTCHA site keys are bound to a
domain. If the demo runs over a Cloudflare tunnel or any address other than the
registered one, the CAPTCHA shows *"Invalid domain for site key"* — which is what
happened during development. Switching to `CAPTCHA_DRIVER = 'image'` uses the
local gregwar/captcha and works on any address. It also removes an internet
dependency from the demo.

**`cloudflared.exe` is 52 MB** and is local tooling, not part of the project. It
is the bulk of the ZIP. Delete before packaging.

**`config/database.php` is dead.** Nothing references it. Safe to delete; it only
invites a question you gain nothing by answering.

**Section 6.0: "Be sure you understand your codes."** The three parts most likely
to be asked about, and where the reasoning is written down:

- `lib/role.php` — why permissions rather than role-name checks
- `lib/orders.php` — why the transition rule lives in one function
- `lib/cancellation.php` — why cancellation is a request and not a status

`AUTHORIZATION_TESTING.md` has console snippets that demonstrate the security
holds when the interface is bypassed entirely. That is the strongest thing to
show for the "General Web Security" 10%.

---

## 5. Pre-submission checklist

```
[ ] run migrations 25-30                 (29 = theme, 30 = payment method)
[ ] mysqldump -> database/mobile2u.sql   (REQUIRED deliverable)
[ ] delete lib/config.php                (Stripe key + Gmail App Password)
[ ] delete cloudflared.exe               (52 MB)
[ ] delete config/database.php           (dead file)
[ ] MAIL_MODE -> 'dev' if not demoing live email
[ ] CAPTCHA_DRIVER -> 'image' if demoing over a tunnel
[ ] confirm APP_DEBUG is false           (it already is)
[x] jsQR.js downloaded for offline scanning  (261 KB, present)
[ ] slide: ERD from phpMyAdmin + per-member screenshots
[ ] zip the project folder
```
