# Making the E-Receipt really send a PDF

Out of the box `MAIL_MODE = 'dev'` and `vendor/` contains only Stripe, so
receipts are written to `storage/mail.log` and no PDF is produced.

The steps below make it arrive in a real inbox.

---

## Step 1: Install Composer (if you have not already)

If `vendor/` already contains Stripe you have run Composer before and can skip
this. To check, open CMD and run:

```
composer -V
```

A version number means it is installed. If you get "not recognised as an internal
or external command", download it from
<https://getcomposer.org/Composer-Setup.exe> and accept the defaults — the
installer detects XAMPP's `php.exe` on its own. **Close and reopen CMD**
afterwards.

---

## Step 2: Install Dompdf and PHPMailer

Open CMD and change to the project folder:

```
cd /d D:\Web-Based-Integrated-Systems-Assignment
composer require dompdf/dompdf phpmailer/phpmailer gregwar/captcha
```

Afterwards `vendor/` should contain `dompdf/` and `phpmailer/`.

The dependencies are already listed in `composer.json`, so plain
`composer install` works too.

> **Fails to install?** The usual cause is PHP's openssl extension being off.
> Open `C:\xampp\php\php.ini`, find `;extension=openssl`, remove the leading
> semicolon, save, and restart Apache.

---

## Step 3: Get a Gmail App Password

`SMTP_PASS` in `lib/config.php` must be an **App Password**, not the password you
log in with.

To create one:

1. Go to <https://myaccount.google.com/security>
2. Turn on **2-Step Verification** first — without it there is no App Password option
3. Search for **App passwords**
4. Create one with any name, for example `Mobile2U`
5. You get 16 characters such as `abcd efgh ijkl mnop`. Paste the whole thing
   into `SMTP_PASS`; the spaces do not matter

> **A university address may not work.** A `@student.tarc.edu.my` address is a
> Google Workspace account, and many institutions disable App Passwords for
> them. If step 6 keeps failing, send from a personal Gmail account instead —
> the recipient can still be any address.

---

## Step 4: Switch on real sending

In `lib/config.php`, change:

```php
define('MAIL_MODE', 'dev');
```

to:

```php
define('MAIL_MODE', 'prod');
```

---

## Step 5: Run the E-Receipt SQL

In phpMyAdmin, select `mobile2u` -> SQL tab -> paste
**`database/migration_07_ereceipt.sql`** -> Go.

> Do not re-run the whole `migration_password_reset.sql`. phpMyAdmin stops at the
> first error, and since the earlier sections have already been applied it would
> halt on "Duplicate column name" and never reach section 7.

---

## Step 6: Verify

Sign in as an admin and open **Mail & PDF** in the sidebar
(`/admin/mail_test.php`). That page checks each part of the chain:

| Check | What to do if it fails |
|-------|------------------------|
| Composer autoloader loaded | run `composer install` |
| Dompdf installed | run `composer require dompdf/dompdf` |
| PHPMailer installed | run `composer require phpmailer/phpmailer` |
| PHP openssl extension | edit php.ini, restart Apache |
| MAIL_MODE is "prod" | edit `lib/config.php` |
| From address matches SMTP account | handled automatically |
| storage/receipts writable | grant write permission on the folder |
| Receipt columns exist | run the migration SQL |

Once everything is green, enter a recipient at the bottom of the same page and
press **Send Test Message**. It attaches the PDF of the most recent order and
goes through exactly the same code path as a real receipt.

If it arrives, the whole chain works.

---

## Where the PDF download appears

Once Dompdf is installed, a **Download PDF** button appears in three places:

- Member: order detail page `/order_detail.php?id=N`, right-hand column
- Member: receipt page `/receipt.php?id=N`, top toolbar
- Admin: order detail page `/admin/order_detail.php?id=N`, right-hand column

It downloads as `Receipt-MU-2026-000042.pdf`.

A copy of every emailed PDF is also written to `storage/receipts/`. That folder
is blocked from direct access by its `.htaccess`, so the files are only reachable
through `receipt.php`, which checks who is asking first.

**The assignment can still be submitted without Dompdf.** The button simply does
not appear; the receipt page is still there, and **Print -> Save as PDF** produces
a PDF, just not a server-generated one.

---

## Advice for the demo

Real sending depends on the network and on Google, and it is embarrassing when it
fails in front of an audience. Two approaches:

**The safe one** — record a video or take screenshots the night before proving
the mail arrives, then switch `MAIL_MODE` back to `dev` for the demo and show
`storage/mail.log` plus the PDF download. The PDF is generated locally and cannot
fail.

**The normal one** — leave it on `prod`, but test once beforehand on the same
network you will present from.

Both earn the marks. The second looks better; the first is safer.

---

## Before submitting

`lib/config.php` holds real credentials:

- your **Stripe secret key**
- your **Gmail App Password**

**An App Password is a working key for sending mail as you.** Anyone who receives
the ZIP can use it. Before handing it in:

1. **Revoke** that App Password on your Google account security page — it takes seconds
2. Replace `SMTP_PASS` and `STRIPE_SECRET_KEY` with placeholder strings
3. If this project was ever pushed to GitHub, **the old commits still contain
   them**, so revoking is not optional

`lib/config.example.php` is the template to hand in. `lib/config.php` is listed in
`.gitignore` so it is not committed again.
