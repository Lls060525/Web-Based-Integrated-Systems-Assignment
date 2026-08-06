<?php
// ============================================================
// admin/voucher_form.php - Create / edit a discount voucher
// ============================================================

require_once __DIR__ . '/admin_auth.php';

if (!voucher_module_ready()) {
    flash_error('Vouchers are not available yet: run database/migration_10_voucher.sql.');
    redirect('/admin/dashboard.php');
}

$id     = get_int('id');
$isEdit = $id !== null;

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

    $voucher = $found;
    // Date inputs want Y-m-d, the database holds a full datetime.
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
    if (v_required('code', $code, 'Voucher code')) {
        if (!preg_match('/^[A-Z0-9_-]{3,30}$/', $code)) {
            add_err('code', 'Use 3 to 30 characters: letters, digits, hyphen or underscore only.');
        } else {
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
    if ($type === 'percent') {
        v_number('value', $value, 0.01, 100, 'Percentage');

        if ($maxDiscount !== '' && !is_numeric($maxDiscount)) {
            add_err('max_discount', 'Maximum discount must be a number, or left blank for no cap.');
        }
    } else {
        v_number('value', $value, 0.01, 999999, 'Discount amount');
        $maxDiscount = '';   // meaningless for a fixed amount
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
