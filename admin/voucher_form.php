<?php
// ============================================================
// admin/voucher_form.php - Create / edit a discount voucher
//
// ------------------------------------------------------------
// ONE FILE, TWO JOBS
// ------------------------------------------------------------
//
// This is the "add" form and the "edit" form at once, and $isEdit is
// the only thing that tells them apart:
//
//   /admin/voucher_form.php          -> $id is null  -> creating
//   /admin/voucher_form.php?id=4     -> $id is 4     -> editing
//
// They are one file because the two would otherwise be near-identical
// twins: same fifteen fields, same validation, same layout. Two copies
// means every future rule has to be written twice, and the day
// somebody updates only one of them the add form starts accepting
// something the edit form rejects. The same pattern is used by
// product_form.php, store_form.php and spec_form.php.
//
// The technique that makes it work is the $voucher array below. It is
// filled with DEFAULTS first, then overwritten from the database when
// editing. From that point on the HTML at the bottom just prints
// $voucher without caring which mode it is in.
//
// ------------------------------------------------------------
// WHERE THE VALIDATION LIVES
// ------------------------------------------------------------
//
// Everything is checked HERE, on the server, after the POST. The
// required and pattern attributes in the HTML are a convenience for
// the person typing -- they are enforced by the browser, and a browser
// is the one part of this system an attacker controls completely.
// Anything that matters is re-checked below.
//
// The v_* helpers (v_required, v_number, v_in, v_max) come from
// lib/validation.php. Each records a message against a field name via
// add_err(), and the form redisplays them beside the right input.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

require_permission('vouchers.manage');

if (!voucher_module_ready()) {
    flash_error('Vouchers are not available yet: run database/migration_10_voucher.sql.');
    // Somewhere this role can actually open, not the dashboard --
    // otherwise a missing migration bounces them into a 403.
    redirect(admin_landing_url());
}

$id     = get_int('id');
$isEdit = $id !== null;

// The shape of a voucher, with the defaults a NEW one starts from.
//
// Declaring every key here, even the empty ones, is what lets the HTML
// at the bottom write $voucher['max_discount'] unconditionally. Build
// this array only when editing and every field in the form would need
// a ?? '' beside it, and the one that gets forgotten becomes a PHP
// warning printed into the page.
//
// The two dates are chosen rather than blank because a voucher with no
// dates is the least useful thing to hand somebody: valid from today,
// expiring in a month, is what a promotion usually is.
$voucher = [
    'code'           => '',
    'description'    => '',
    'type'           => 'percent',
    'value'          => '',
    'min_spend'      => '0',
    'max_discount'   => '',
    'usage_limit'    => '',
    'used_count'     => 0,
    'per_user_limit' => '1',
    'starts_at'      => date('Y-m-d'),
    'expires_at'     => date('Y-m-d', strtotime('+30 days')),
    'status'         => 'active',
];

if ($isEdit) {
    $found = db_one('SELECT * FROM vouchers WHERE id = ?', [$id]);

    if (!$found) {
        flash_error('Voucher not found.');
        redirect('/admin/vouchers.php');
    }

    // Replaces the defaults wholesale. Everything downstream now reads
    // the stored voucher without knowing anything changed.
    $voucher = $found;

    // Date inputs want Y-m-d, the database holds a full datetime.
    //
    // <input type="date"> silently shows EMPTY when handed
    // "2026-08-25 14:30:00" -- it does not complain, it just appears
    // blank. Saving the form then wipes a date that was set. Trimming
    // the time off here is what keeps an edit from quietly destroying
    // data the admin never touched.
    $voucher['starts_at']  = !empty($found['starts_at'])  ? date('Y-m-d', strtotime($found['starts_at']))  : '';
    $voucher['expires_at'] = !empty($found['expires_at']) ? date('Y-m-d', strtotime($found['expires_at'])) : '';
}

$typeOptions   = ['percent' => 'Percentage off', 'fixed' => 'Fixed amount off'];
$statusOptions = ['active' => 'Active', 'inactive' => 'Inactive'];

if (is_post()) {
    csrf_check();

    $code        = strtoupper(post('code'));
    $description = post('description');
    $type        = post('type');
    $value       = post('value');
    $minSpend    = post('min_spend', '0');
    $maxDiscount = post('max_discount');
    $usageLimit  = post('usage_limit');
    $perUser     = post('per_user_limit', '1');
    $startsAt    = post('starts_at');
    $expiresAt   = post('expires_at');
    $status      = post('status');

    // ---------- Code ----------
    //
    // Three checks, nested so each only runs if the previous passed --
    // there is no point asking whether "" is already taken.
    if (v_required('code', $code, 'Voucher code')) {

        // Uppercased at the top of this block, so "save10" and "SAVE10"
        // are the same voucher. A customer typing a code on a phone
        // gets whatever autocorrect gives them; the code is normalised
        // once here rather than compared case-insensitively in five
        // different places later.
        //
        // The character set is restricted for a practical reason: this
        // code gets read aloud, printed on posters and typed by hand.
        // Allowing spaces or punctuation invites codes nobody can
        // enter correctly.
        if (!preg_match('/^[A-Z0-9_-]{3,30}$/', $code)) {
            add_err('code', 'Use 3 to 30 characters: letters, digits, hyphen or underscore only.');
        } else {
            // ---- The uniqueness check, and the clause that makes
            //      editing possible ----
            //
            // "Is any other voucher already using this code?"
            //
            // AND id <> ? is the important half. When editing voucher
            // 4, voucher 4 itself obviously has this code -- without
            // excluding it, saving the form without touching the code
            // would report "already in use" and refuse to save. The
            // form would be impossible to submit twice.
            //
            // This exact pattern appears in every edit form that has a
            // unique field: exclude yourself, then ask.
            $dupSql    = 'SELECT id FROM vouchers WHERE code = ?';
            $dupParams = [$code];

            if ($isEdit) {
                $dupSql     .= ' AND id <> ?';
                $dupParams[] = $id;
            }

            if (db_one($dupSql, $dupParams)) {
                add_err('code', 'Another voucher already uses this code.');
            }
        }
    }

    v_max('description', $description, 200, 'Description');
    v_in('type', $type, ['percent', 'fixed'], 'Discount type');
    v_in('status', $status, ['active', 'inactive'], 'Status');

    // ---------- Value, meaning depends on the type ----------
    //
    // One column, two meanings. `value` holds 15 for "15% off" and 15
    // for "RM 15 off", and only `type` says which -- so the bounds
    // have to be chosen per type, not once for the column.
    //
    // This is worth noticing as a design point: the alternative is two
    // nullable columns (percent_value, fixed_value) where exactly one
    // is filled, which pushes "which one is set" into every query that
    // ever touches a voucher. One value plus a type keeps that
    // decision in a single place.
    if ($type === 'percent') {
        // Capped at 100: a discount over 100% would pay the customer.
        v_number('value', $value, 0.01, 100, 'Percentage');

        // max_discount only means something for a percentage -- it is
        // the cap that stops "20% off" costing RM 1,400 on a flagship
        // phone. Optional, so blank is allowed and only a non-numeric
        // value is an error.
        if ($maxDiscount !== '' && !is_numeric($maxDiscount)) {
            add_err('max_discount', 'Maximum discount must be a number, or left blank for no cap.');
        }
    } else {
        v_number('value', $value, 0.01, 999999, 'Discount amount');

        // Cleared rather than ignored.
        //
        // "A cap on a fixed RM 15 discount" is not a thing, so leaving
        // whatever was typed in the box would store a number that
        // means nothing -- and the next person to read the row would
        // reasonably wonder whether it was being applied. Blanking it
        // here means the stored voucher can only be interpreted one
        // way. Data that cannot be misread beats data that merely
        // happens to be unused.
        $maxDiscount = '';
    }

    if (!is_numeric($minSpend) || (float)$minSpend < 0) {
        add_err('min_spend', 'Minimum spend must be zero or more.');
    }

    // A fixed discount larger than the minimum spend can hand out free money.
    if (no_err() && $type === 'fixed' && (float)$minSpend > 0 && (float)$value > (float)$minSpend) {
        add_err('value', 'A fixed discount larger than the minimum spend would let an order reach zero. '
                       . 'Raise the minimum spend or lower the discount.');
    }

    if ($usageLimit !== '' && (filter_var($usageLimit, FILTER_VALIDATE_INT) === false || (int)$usageLimit < 0)) {
        add_err('usage_limit', 'Total usage limit must be a whole number, or blank for unlimited.');
    }

    if (filter_var($perUser, FILTER_VALIDATE_INT) === false || (int)$perUser < 0) {
        add_err('per_user_limit', 'Per-user limit must be a whole number. Use 0 for unlimited.');
    }

    // ---------- Dates ----------
    if ($startsAt !== '' && $expiresAt !== '' && strtotime($expiresAt) < strtotime($startsAt)) {
        add_err('expires_at', 'The expiry date cannot be before the start date.');
    }

    // Editing must not set a limit below what has already been redeemed.
    if ($isEdit && $usageLimit !== '' && (int)$usageLimit < (int)$voucher['used_count']) {
        add_err('usage_limit', 'This voucher has already been redeemed '
                             . (int)$voucher['used_count'] . ' times. The limit cannot be lower than that.');
    }

    if (no_err()) {
        $params = [
            $code,
            ($description === '' ? null : $description),
            $type,
            (float)$value,
            (float)$minSpend,
            ($maxDiscount === '' ? null : (float)$maxDiscount),
            ($usageLimit === '' ? null : (int)$usageLimit),
            (int)$perUser,
            ($startsAt  === '' ? null : $startsAt . ' 00:00:00'),
            ($expiresAt === '' ? null : $expiresAt . ' 23:59:59'),
            $status,
        ];

        if ($isEdit) {
            $params[] = $id;
            db_exec(
                'UPDATE vouchers
                    SET code = ?, description = ?, type = ?, value = ?, min_spend = ?,
                        max_discount = ?, usage_limit = ?, per_user_limit = ?,
                        starts_at = ?, expires_at = ?, status = ?, updated_at = NOW()
                  WHERE id = ?',
                $params
            );
            flash_success('Voucher ' . $code . ' updated.');
        } else {
            db_exec(
                'INSERT INTO vouchers
                        (code, description, type, value, min_spend, max_discount,
                         usage_limit, per_user_limit, starts_at, expires_at, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $params
            );
            flash_success('Voucher ' . $code . ' created.');
        }

        redirect('/admin/vouchers.php');
    }
    // Validation failed. Answer with a redirect rather than a page, so
    // the browser's history entry is a GET and F5 cannot resubmit.
    // The errors and what was typed are carried across the redirect.
    redirect_back();
}

$title = ($isEdit ? 'Edit' : 'Add') . ' Voucher - Admin';

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container admin-container-narrow">
    <div class="admin-header">
        <h2><?= $isEdit ? 'Edit Voucher' : 'Add New Voucher' ?></h2>
        <a href="/admin/vouchers.php" class="btn-outline">&larr; Back to Vouchers</a>
    </div>

    <div class="card mt-4 card-padded">

        <?php err_summary(); ?>

        <?php if ($isEdit && (int)$voucher['used_count'] > 0): ?>
            <div class="alert alert-info">
                This voucher has been redeemed <strong><?= (int)$voucher['used_count'] ?></strong> time(s).
                Changing the discount will not affect orders already placed &mdash; each order
                stores its own copy of the code and the amount saved.
            </div>
        <?php endif; ?>

        <form action="" method="POST" class="form-standard">
            <?php csrf_field(); ?>

            <div class="form-row">
                <div class="form-col">
                    <?php field('code', 'Voucher Code', function () use ($voucher) {
                        html_text('code', $voucher['code'], [
                            'required'    => true,
                            'maxlength'   => 30,
                            'autofocus'   => true,
                            'placeholder' => 'WELCOME10',
                            'class'       => 'form-control voucher-code-input',
                        ]);
                        echo '<small class="form-hint">Letters, digits, hyphen or underscore. Stored uppercase.</small>';
                    }, true); ?>
                </div>

                <div class="form-col">
                    <?php field('status', 'Status', function () use ($statusOptions, $voucher) {
                        html_select('status', $statusOptions, $voucher['status']);
                    }, true); ?>
                </div>
            </div>

            <?php field('description', 'Description', function () use ($voucher) {
                html_text('description', $voucher['description'], [
                    'maxlength'   => 200,
                    'placeholder' => 'Shown to members on the checkout page',
                ]);
            }); ?>

            <div class="form-row">
                <div class="form-col">
                    <?php field('type', 'Discount Type', function () use ($typeOptions, $voucher) {
                        html_select('type', $typeOptions, $voucher['type'], ['id' => 'voucherType']);
                    }, true); ?>
                </div>

                <div class="form-col">
                    <?php field('value', 'Value', function () use ($voucher) {
                        html_number('value', $voucher['value'], [
                            'step'     => '0.01',
                            'min'      => '0.01',
                            'required' => true,
                            'id'       => 'voucherValue',
                        ]);
                        echo '<small class="form-hint" id="valueHint">Percentage off, for example 10 means 10%.</small>';
                    }, true); ?>
                </div>

                <div class="form-col" id="maxDiscountCol">
                    <?php field('max_discount', 'Maximum Discount (RM)', function () use ($voucher) {
                        html_number('max_discount', $voucher['max_discount'], ['step' => '0.01', 'min' => '0']);
                        echo '<small class="form-hint">Optional cap. Blank means no cap.</small>';
                    }); ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-col">
                    <?php field('min_spend', 'Minimum Spend (RM)', function () use ($voucher) {
                        html_number('min_spend', $voucher['min_spend'], ['step' => '0.01', 'min' => '0']);
                        echo '<small class="form-hint">0 means no minimum.</small>';
                    }); ?>
                </div>

                <div class="form-col">
                    <?php field('usage_limit', 'Total Usage Limit', function () use ($voucher) {
                        html_number('usage_limit', $voucher['usage_limit'], ['min' => '0']);
                        echo '<small class="form-hint">Blank means unlimited.</small>';
                    }); ?>
                </div>

                <div class="form-col">
                    <?php field('per_user_limit', 'Uses Per Member', function () use ($voucher) {
                        html_number('per_user_limit', $voucher['per_user_limit'], ['min' => '0', 'required' => true]);
                        echo '<small class="form-hint">0 means unlimited per member.</small>';
                    }, true); ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-col">
                    <?php field('starts_at', 'Valid From', function () use ($voucher) {
                        html_input('date', 'starts_at', temp('starts_at', $voucher['starts_at']));
                        echo '<small class="form-hint">Blank means it is valid immediately.</small>';
                    }); ?>
                </div>

                <div class="form-col">
                    <?php field('expires_at', 'Expires On', function () use ($voucher) {
                        html_input('date', 'expires_at', temp('expires_at', $voucher['expires_at']));
                        echo '<small class="form-hint">Blank means it never expires.</small>';
                    }); ?>
                </div>
            </div>

            <?php if ($isEdit): ?>
                <p class="muted small-note">
                    Redeemed <?= (int)$voucher['used_count'] ?> time(s) so far.
                </p>
            <?php endif; ?>

            <div class="form-actions text-right">
                <a href="/admin/vouchers.php" class="btn-outline">Cancel</a>
                <?php html_submit($isEdit ? 'Save Changes' : 'Create Voucher'); ?>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
