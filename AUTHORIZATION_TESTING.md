# Testing Authorization Control

How to prove the permission checks are real and not just hidden buttons.
Three tests, increasing in how hard they try to get around it.

Set up once: **Admin → Admins → Add New Admin**, give it the **Stock Clerk**
role, then sign in as that account in a **second browser** (or a private
window) so your Super Admin session stays open in the first.

---

## Test 1 — the sidebar shrinks

Sign in as the Stock Clerk.

The sidebar drops from **16 entries to 6**: Dashboard, Categories, Products,
Stock, Specs, Batch Tools. Members, Admins, Roles, Orders, Vouchers, Reviews,
Stores, Scan QR, Login Security and Mail & PDF are gone.

This is the easy one, and on its own it proves nothing — hiding a link is
presentation. The next two are the point.

---

## Test 2 — typing the address directly

Still signed in as the Stock Clerk, put this in the address bar:

```
http://localhost/admin/members.php
```

You get a **403** page saying *"Your role does not include Members"*, naming
the role you are on and offering a page you can actually open.

Try `/admin/roles.php` too — that is the one that would let you grant yourself
everything, and it is refused the same way.

> The status code matters, not just the wording. Open DevTools → Network,
> reload, and check the document request shows **403**, not 200. A page that
> merely *looks* like an error while returning 200 is not denying anything.

---

## Test 3 — calling the API directly

This is the one worth showing, because it skips the interface entirely.

`admin/qr_scan.php` is a page, but the thing that actually returns customer
data is `api/qr_lookup.php`. Guarding only the page would be theatre — anyone
can call the endpoint.

**Step 1.** Sign in as the Stock Clerk and open any page they *can* reach, for
example `/admin/products.php`. You need to be on a page of the site so the
request carries the session cookie and can read the CSRF token.

**Step 2.** Open DevTools (**F12**) → **Console**, paste this, press Enter:

```js
// Calls the admin-only order lookup directly, bypassing the whole UI.
// The CSRF token is read from the page's own <meta> tag, so this is a
// fully legitimate-looking request -- nothing is being forged.
fetch('/api/qr_lookup.php', {
    method: 'POST',
    body: new URLSearchParams({
        value: 'MU-2026-000001',                       // any order reference
        csrf_token: document.querySelector('meta[name="csrf-token"]').content
    })
})
.then(r => r.json().then(j => ({ http: r.status, body: j })))
.then(console.log);
```

**As the Stock Clerk** you get:

```
{ http: 200, body: { status: "error",
                     message: "Your role does not include order scanning." } }
```

No order. No customer name, no email, no total.

**Step 3.** Now do exactly the same in the other browser, signed in as
**Super Admin**. Same code, same endpoint — and this time the order comes
back in full.

That side-by-side is the whole demonstration: **identical request, different
role, different answer.** The difference is not in the browser, it is in
`can('qr.scan')` on the server.

---

## Test 3b — proving the workflow rules from the console

The QR panel only draws buttons a role is allowed to press. That is a
courtesy, not the rule. These snippets post directly, skipping the interface
entirely, so they show what the **server** does.

Because these are ordinary form posts using Post/Redirect/Get, the refusal
arrives as a flash message on the page you get redirected to. The helper below
follows the redirect and digs the message out.

**Set up.** Sign in as the **Vendor**, open `/admin/qr_scan.php`, and pick an
order that is currently **Processing** — one the Vendor should have no say
over. Note its id.

Paste this once (F12 → Console):

```js
// Posts straight to the server and reports the flash message that comes back.
// The CSRF token is read from the page's own <meta>, so nothing is forged --
// this is exactly what the real form sends, minus the missing button.
async function tryAction(fields) {
    const body = new FormData();
    body.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
    for (const [k, v] of Object.entries(fields)) body.append(k, v);

    const res  = await fetch('/admin/qr_scan.php', { method: 'POST', body });
    const html = await res.text();
    const doc  = new DOMParser().parseFromString(html, 'text/html');
    const msg  = doc.querySelector('.toast-error, .toast-success');

    return { http: res.status, message: msg ? msg.textContent.trim() : '(no message)' };
}
```

Then run each of these, replacing `12` with your order id.

```js
// 1. Vendor tries processing -> shipped. Not their transition.
await tryAction({ action: 'advance', order_id: 12, to_status: 'shipped' });
```

```
{ http: 200, message: "Your role cannot move orders to Shipped." }
```

```js
// 2. Vendor tries to cancel an order that is already processing.
//    It is not their turn -- a Vendor only owns an order while it is Pending.
await tryAction({ action: 'request_cancel', order_id: 12, cancel_reason: 'other' });
```

```
{ http: 200, message: "You do not have permission to act on this order." }
```

```js
// 3. Anyone tries to skip a step: pending -> shipped.
//    Refused for everybody, including a Super Admin -- the sequence is strict.
await tryAction({ action: 'advance', order_id: 12, to_status: 'delivered' });
```

```
{ http: 200, message: "An order that is Processing cannot move to Delivered. Allowed next steps: Shipped." }
```

```js
// 4. The one that matters most: a role that IS allowed, but with no photo.
//    Sign in as the Delivery Man first, then run this on a Processing order.
await tryAction({ action: 'advance', order_id: 12, to_status: 'shipped' });
```

```
{ http: 200, message: "A photograph is required to mark this order Shipped." }
```

Test 4 is worth doing last, because it shows the two rules are independent:
the driver passes the permission check and is still stopped by the evidence
requirement. Neither one is doing the other's job.

### Now show it working

Sign in as the **Delivery Man**, scan or open the same Processing order through
the interface, attach a photo, and press *Mark as Shipped*. Same endpoint, same
role — it goes through. The difference is the photograph, and the photograph is
now attached to that history row for good.

### Why the checks are in three places

Reading the code, the same rule appears more than once, and that is deliberate:

| Layer | What it does | If you removed it |
|-------|--------------|-------------------|
| `render_status_actions()` | Does not draw the button | The button appears and refuses when pressed |
| `handle_status_change()` | Refuses before spending an upload | A photo is uploaded and then thrown away |
| `update_order_status()` | Refuses at the last possible moment | A future page that forgets the wrapper is unguarded |

Only the last one is load-bearing. The other two exist so the failure happens
early and reads well.

---

## What to say if asked "why 200 and not 403?"

`json_error()` answers with HTTP **200** on purpose. These are
application-level refusals, not transport failures, and jQuery routes any
non-2xx response into `.fail()` — where the `message` would be thrown away and
the user would see a generic "something went wrong" instead of the real reason.

The refusal is in `status: "error"`, which every caller in this project checks.
The one exception is `ajax_require_role()`'s admin check, which does return
**403**, because "you are not an admin at all" is a different class of problem
from "your role lacks this one permission".

---

## Test 4 — revocation takes effect immediately

Worth showing because most implementations get this wrong.

1. Sign in as the Stock Clerk. Open **Products**. It works.
2. In the *other* browser, as Super Admin: **Roles → Stock Clerk → Edit**,
   untick **Products**, save.
3. Back in the Stock Clerk's browser, **do not log out**. Just click Products
   again.

Refused immediately.

Permissions are not copied into the session at login. `current_permissions()`
runs one query per request, so access is decided by what the database says
*now*. An admin who has just been demoted does not keep their old access
because they left a tab open.

---

## Where the checks live

| Layer | File | What it does |
|-------|------|--------------|
| Page guard | every file in `/admin` | `require_permission('x')` under `admin_auth.php` |
| API guard | `api/qr_lookup.php` | `can('qr.scan')` after `ajax_guard_post('admin')` |
| Sidebar | `includes/admin_header.php` | `array_filter` over the same `can()` |
| The check itself | `lib/role.php` | `can()` → `current_permissions()` → live DB query |

`admin/profile.php` is deliberately the one page with no permission guard —
every admin can edit their own name, email, password and photo. That is only
safe because it can *only* write the signed-in user's own row: `$userId` comes
from `current_user_id()`, never from the request, and it cannot touch
`role_id`. Otherwise a Stock Clerk could promote themselves.
