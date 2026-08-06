<?php
// ============================================================
// lib/config.php
// Central application configuration.
// Every tunable value lives here so that no page hardcodes
// credentials, paths or keys.
// ============================================================

// ---------- Database ----------
define('DB_HOST',    '127.0.0.1');
define('DB_NAME',    'mobile2u');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');

// ---------- Application ----------
define('APP_NAME',  'Mobile2U');
define('APP_DEBUG', true);   // set to false before submission/production

// ---------- Upload directories (absolute paths) ----------
define('DIR_ROOT',            dirname(__DIR__));
define('DIR_UPLOAD_AVATARS',  DIR_ROOT . '/assets/uploads/avatars/');
define('DIR_UPLOAD_PRODUCTS', DIR_ROOT . '/assets/uploads/products/');

// ---------- Upload directories (public URLs) ----------
define('URL_UPLOAD_AVATARS',  '/assets/uploads/avatars/');
define('URL_UPLOAD_PRODUCTS', '/assets/uploads/products/');
define('URL_IMAGES',          '/assets/images/');

define('UPLOAD_MAX_SIZE', 2 * 1024 * 1024); // 2 MB
define('UPLOAD_ALLOWED_MIMES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

// ---------- Password reset ----------
define('RESET_TOKEN_TTL', 3600); // reset link valid for 1 hour (seconds)

// ---------- Mail delivery mode ----------
// 'dev'  : the reset link is displayed on screen (no SMTP needed - safest for demo)
// 'prod' : the reset link is emailed through SMTP using PHPMailer
define('MAIL_MODE', 'prod');

// IMPORTANT: when MAIL_MODE is 'prod', Gmail (and every other SMTP
// provider) will only accept a From address that matches the account
// you authenticated with. Using a made-up address gets the message
// rejected or silently rewritten, so MAIL_FROM tracks SMTP_USER.
define('MAIL_FROM_NAME', APP_NAME);

// SMTP settings (only used when MAIL_MODE is 'prod')
define('SMTP_HOST',   'smtp.gmail.com');
define('SMTP_PORT',   587);
define('SMTP_USER',   'tanwf-wm24@student.tarc.edu.my');
define('SMTP_PASS',   'tpsg iryi yooj svyr'); // Gmail App Password, not the login password
define('SMTP_SECURE', 'tls');

// Derived, so it can never drift out of sync with the SMTP account.
define('MAIL_FROM', MAIL_MODE === 'prod' ? SMTP_USER : 'no-reply@mobile2u.local');

// ---------- Stripe (Payment - additional module) ----------
define('STRIPE_SECRET_KEY', 'secret_key_goes_here');
define('STRIPE_CURRENCY',   'myr');

// ---------- Order statuses (single source of truth) ----------
// These MUST match orders.status ENUM in the database, which is lowercase:
//   enum('pending','processing','shipped','delivered','cancelled')
// PHP string comparison is case-sensitive even though MySQL's is not, so the
// stored values and the values used in code have to be spelled identically.
define('ORDER_STATUSES', ['pending', 'processing', 'shipped', 'delivered', 'cancelled']);

// Human-readable labels for display. Never store these.
define('ORDER_STATUS_LABELS', [
    'pending'    => 'Pending',
    'processing' => 'Processing',
    'shipped'    => 'Shipped',
    'delivered'  => 'Delivered',
    'cancelled'  => 'Cancelled',
]);

// ---------- Order cancellation (Member) ----------
// A member may cancel only while the order has not shipped yet.
define('MEMBER_CANCELLABLE_STATUSES', ['pending', 'processing']);

// Reasons offered in the cancellation form. The key is stored in
// orders.cancel_reason, the value is the label shown to the user.
define('CANCEL_REASONS', [
    'changed_mind'    => 'I changed my mind',
    'ordered_wrong'   => 'I ordered the wrong item',
    'found_cheaper'   => 'I found a better price elsewhere',
    'wrong_address'   => 'The shipping address is wrong',
    'delivery_slow'   => 'Delivery is taking too long',
    'other'           => 'Other reason',
]);

// Choosing "other" makes the free-text note compulsory.
define('CANCEL_REASON_REQUIRING_NOTE', 'other');

// ---------- Order status transitions (Admin) ----------
// Which statuses an order may move to, given where it is now.
// Orders move forward, never backward: once something is delivered
// it cannot quietly become "pending" again. Cancelling is allowed
// only before dispatch, and a cancelled order can be reinstated.
//
// Loosen or tighten the workflow by editing this one map.
define('ORDER_STATUS_TRANSITIONS', [
    'pending'    => ['processing', 'shipped', 'cancelled'],
    'processing' => ['shipped', 'cancelled'],
    'shipped'    => ['delivered'],
    'delivered'  => [],                         // final state
    'cancelled'  => ['pending', 'processing'],  // reinstate a cancelled order
]);

// Statuses that no longer allow any change at all.
define('ORDER_FINAL_STATUSES', ['delivered']);

// ---------- Temporary login blocking ----------
// After this many failed attempts the account is locked for a while.
define('LOGIN_MAX_ATTEMPTS', 3);

// How long the lock lasts, in minutes.
define('LOGIN_LOCKOUT_MINUTES', 15);

// Only failures inside this window count towards the limit, so an
// old mistake from yesterday does not add to today's total.
define('LOGIN_ATTEMPT_WINDOW_MINUTES', 15);

// A single machine trying many different accounts is also blocked.
// This threshold is higher because a shared IP (a lab, a campus
// network) can legitimately produce several failures.
define('LOGIN_MAX_ATTEMPTS_PER_IP', 10);

// How long to keep the audit rows before they can be pruned.
define('LOGIN_ATTEMPT_RETENTION_DAYS', 30);

// ---------- Product stock ----------
// Fallback low-stock threshold for products created before the
// per-product reorder_level column existed.
define('STOCK_DEFAULT_REORDER_LEVEL', 5);

// Movement types offered in the admin adjustment form.
define('STOCK_MOVEMENT_LABELS', [
    'restock' => 'Restock (goods received)',
    'adjust'  => 'Correction (stock count)',
    'damage'  => 'Damaged or lost',
]);

// Every type that can appear in the ledger, including automatic ones.
define('STOCK_ALL_MOVEMENT_LABELS', [
    'initial' => 'Opening stock',
    'sale'    => 'Sold',
    'return'  => 'Returned',
    'restock' => 'Restocked',
    'adjust'  => 'Correction',
    'damage'  => 'Damaged / lost',
]);

// ---------- Reward points ----------
// Earning: 1 point for every RM 1 spent, rounded down.
define('POINTS_EARNED_PER_RM', 1);

// Redeeming: this many points are worth RM 1.
define('POINTS_PER_RM_REDEEMED', 100);

// Redemption must be in whole blocks, so the value is always exact.
define('POINTS_REDEEM_STEP', 100);

// Nothing smaller than this can be redeemed.
define('POINTS_MIN_REDEEM', 100);

// Points may not pay for more than this share of an order, so a
// member always contributes something and the store keeps a margin.
define('POINTS_MAX_REDEEM_PERCENT', 50);

// ---------- Shipping addresses ----------
// Malaysian states and federal territories, used for the dropdown
// and for server-side validation of whatever gets posted.
define('MY_STATES', [
    'Johor'           => 'Johor',
    'Kedah'           => 'Kedah',
    'Kelantan'        => 'Kelantan',
    'Melaka'          => 'Melaka',
    'Negeri Sembilan' => 'Negeri Sembilan',
    'Pahang'          => 'Pahang',
    'Perak'           => 'Perak',
    'Perlis'          => 'Perlis',
    'Pulau Pinang'    => 'Pulau Pinang',
    'Sabah'           => 'Sabah',
    'Sarawak'         => 'Sarawak',
    'Selangor'        => 'Selangor',
    'Terengganu'      => 'Terengganu',
    'W.P. Kuala Lumpur' => 'W.P. Kuala Lumpur',
    'W.P. Labuan'       => 'W.P. Labuan',
    'W.P. Putrajaya'    => 'W.P. Putrajaya',
]);

// Labels offered when saving an address.
define('ADDRESS_LABELS', [
    'Home'   => 'Home',
    'Work'   => 'Work',
    'Family' => 'Family',
    'Other'  => 'Other',
]);

// A member may not keep more than this many saved addresses.
define('ADDRESS_MAX_PER_USER', 10);

// ---------- E-Receipt ----------
define('RECEIPT_PREFIX', 'MU');

// Company details printed on the receipt.
define('COMPANY_NAME',    'Mobile2U Sdn. Bhd.');
define('COMPANY_REG_NO',  '202601234567 (1234567-A)');
define('COMPANY_ADDRESS', "Level 8, Menara Teknologi\nJalan Tun Razak\n50400 Kuala Lumpur, Malaysia");
define('COMPANY_EMAIL',   'support@mobile2u.local');
define('COMPANY_PHONE',   '+60 3-1234 5678');

// Where generated PDFs are cached.
define('DIR_RECEIPTS', DIR_ROOT . '/storage/receipts/');

// A member may resend their own receipt at most this many times.
define('RECEIPT_MAX_SENDS', 10);

// ---------- Account statuses ----------
// Matches users.status ENUM: enum('active','banned','deleted')
define('USER_STATUSES', ['active', 'banned', 'deleted']);

define('USER_STATUS_LABELS', [
    'active'  => 'Active',
    'banned'  => 'Banned',
    'deleted' => 'Deleted',
]);
