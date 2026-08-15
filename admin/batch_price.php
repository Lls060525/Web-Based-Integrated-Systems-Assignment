<?php
// ============================================================
// admin/batch_price.php - Batch Updating (Admin)
//
// Repricing in bulk. Choose which products, choose the arithmetic,
// then read the old -> new table before anything is saved.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

$title    = 'Batch Update Prices - Admin';
$stageKey = 'price';

$categories = db_all('SELECT id, name FROM categories ORDER BY name ASC');
$categoryOptions = ['' => 'All categories'] + array_column($categories, 'name', 'id');

$statusOptions = ['all' => 'Any status', 'active' => 'Active only', 'inactive' => 'Inactive only'];

$filter = [
    'category_id' => post('category_id'),
    'status'      => post('status', 'all'),
    'q'           => post('q'),
    'price_min'   => post('price_min'),
    'price_max'   => post('price_max'),
];

$operation = post('operation', 'percent_up');
$valueRaw  = post('value');
$rounding  = post('rounding', 'none');

$preview = null;

if (is_post()) {
    csrf_check();

    $action = post('action');

    // ---------- Step 2: commit ----------
    if ($action === 'commit') {
        $staged = batch_take_stage($stageKey, post('token'));

        if ($staged === null) {
            flash_error('That preview has expired or was already applied. Please generate it again.');
            redirect('/admin/batch_price.php');
        }

        $result = batch_apply_prices($staged['rows'], (int)current_user_id());

        if (!empty($result['rolled_back'])) {
            flash_error('No prices were changed. The batch was rolled back: ' . $result['message']);
        } else {
            $message = $result['updated'] . ' price'
                     . ($result['updated'] === 1 ? '' : 's') . ' updated.';

            if ($result['skipped'] > 0) {
                $message .= ' ' . $result['skipped'] . ' skipped because they were changed by '
                          . 'someone else after the preview was generated: '
                          . implode(', ', array_slice($result['conflicts'], 0, 5))
                          . (count($result['conflicts']) > 5 ? '...' : '');
                flash_error($message);
            } else {
                flash_success($message);
            }
        }

        redirect('/admin/batch_price.php');
    }

    // ---------- Step 1: preview ----------
    if ($action === 'preview') {
        if (!array_key_exists($operation, batch_price_operations())) {
            add_err('operation', 'Choose a valid operation.');
        }

        if (!array_key_exists($rounding, batch_rounding_modes())) {
            $rounding = 'none';
        }

        if ($valueRaw === '' || !is_numeric($valueRaw)) {
            add_err('value', 'Enter the number to apply.');
        } else {
            $value = (float)$valueRaw;

            if ($value < 0) {
                add_err('value', 'Enter a positive number and pick the direction above.');
            } elseif (in_array($operation, ['percent_up', 'percent_down'], true) && $value > 100) {
                add_err('value', 'A percentage over 100 is almost always a typo. '
                               . 'Use "Multiply by a factor" if you really mean it.');
            } elseif ($operation === 'multiply' && ($value <= 0 || $value > 100)) {
                add_err('value', 'The factor must be between 0 and 100.');
            }
        }

        if (no_err()) {
            $result = batch_price_preview($filter, $operation, (float)$valueRaw, $rounding);

            if ($result['rows'] === []) {
                add_err('q', 'No products match that filter, so there is nothing to update.');
            } else {
                $token = batch_stage($stageKey, [
                    'rows'      => $result['rows'],
                    'operation' => $operation,
                    'value'     => (float)$valueRaw,
                    'rounding'  => $rounding,
                ]);

                $preview = [
                    'token'  => $token,
                    'rows'   => $result['rows'],
                    'totals' => $result['totals'],
                ];
            }
        }
    }
    // Validation failed. Answer with a redirect rather than a page, so
    // the browser's history entry is a GET and F5 cannot resubmit.
    // The errors and what was typed are carried across the redirect.
    redirect_back();
}

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Batch Tools</h2>
    </div>

    <?php include __DIR__ . '/../includes/batch_nav.php'; ?>

    <?php err_summary(); ?>

    <?php if ($preview !== null): ?>

        <?php $t = $preview['totals']; ?>

        <div class="card card-padded mt-4">
            <h3 class="section-heading">Step 2 of 2 &mdash; review before saving</h3>
            <p class="muted small-note">
                <?= (int)$t['count'] ?> product(s) match. No price has changed yet.
            </p>

            <div class="batch-summary">
                <div class="batch-stat">
                    <span class="batch-stat-value"><?= (int)$t['count'] ?></span>
                    <span class="batch-stat-label">Affected</span>
                </div>
                <div class="batch-stat">
                    <span class="batch-stat-value"><?= e(money($t['old_total'])) ?></span>
                    <span class="batch-stat-label">Sum of old prices</span>
                </div>
                <div class="batch-stat is-update">
                    <span class="batch-stat-value"><?= e(money($t['new_total'])) ?></span>
                    <span class="batch-stat-label">Sum of new prices</span>
                </div>
                <div class="batch-stat <?= $t['stock_value_delta'] < 0 ? 'is-error' : 'is-insert' ?>">
                    <span class="batch-stat-value">
                        <?= $t['stock_value_delta'] >= 0 ? '+' : '' ?><?= e(money($t['stock_value_delta'])) ?>
                    </span>
                    <span class="batch-stat-label">Change in stock value</span>
                </div>
            </div>

            <?php if ($t['clamped'] > 0): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-triangle-exclamation"></i>
                    <strong><?= (int)$t['clamped'] ?> price(s) hit a limit</strong>
                    and were clamped to the allowed range (0.01 to 999,999.99). Check the
                    highlighted rows &mdash; this usually means the discount is larger than
                    the price itself.
                </div>
            <?php endif; ?>

            <?php if ($t['unchanged'] > 0): ?>
                <p class="muted small-note">
                    <?= (int)$t['unchanged'] ?> row(s) round back to the price they already have
                    and will be written unchanged.
                </p>
            <?php endif; ?>

            <div class="table-responsive mt-4">
                <table class="admin-table batch-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th class="text-right">Stock</th>
                            <th class="text-right">Old price</th>
                            <th class="text-right">New price</th>
                            <th class="text-right">Change</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview['rows'] as $row): ?>
                            <tr class="<?= $row['clamped'] ? 'batch-row is-error' : '' ?>">
                                <td><?= e($row['name']) ?></td>
                                <td><?= e($row['category_name'] ?? '-') ?></td>
                                <td><?= e(ucfirst($row['status'])) ?></td>
                                <td class="text-right"><?= (int)$row['stock'] ?></td>
                                <td class="text-right price-old"><?= e(money($row['old'])) ?></td>
                                <td class="text-right price-new"><?= e(money($row['new'])) ?></td>
                                <td class="text-right <?= $row['delta'] >= 0 ? 'delta-up' : 'delta-down' ?>">
                                    <?= $row['delta'] >= 0 ? '+' : '' ?><?= e(money($row['delta'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="form-actions batch-actions">
                <a href="/admin/batch_price.php" class="btn-outline">Cancel</a>

                <form action="/admin/batch_price.php" method="POST" class="inline-form"
                      data-confirm="Apply the new prices to <?= (int)$t['count'] ?> product(s)?">
                    <?php csrf_field(); ?>
                    <?php html_hidden('action', 'commit'); ?>
                    <?php html_hidden('token', $preview['token']); ?>
                    <?php html_submit('Apply New Prices', ['class' => 'btn-primary', 'data-busy' => 'Updating...']); ?>
                </form>
            </div>
        </div>

    <?php else: ?>

        <form action="/admin/batch_price.php" method="POST" class="form-standard">
            <?php csrf_field(); ?>
            <?php html_hidden('action', 'preview'); ?>

            <div class="card card-padded mt-4">
                <h3 class="section-heading">1. Which products</h3>
                <p class="muted small-note">Leave everything blank to select the whole catalogue.</p>

                <div class="batch-options">
                    <?php field('category_id', 'Category', function () use ($categoryOptions, $filter) {
                        html_select('category_id', $categoryOptions, $filter['category_id']);
                    }); ?>

                    <?php field('status', 'Status', function () use ($statusOptions, $filter) {
                        html_select('status', $statusOptions, $filter['status']);
                    }); ?>
                </div>

                <?php field('q', 'Name contains', function () use ($filter) {
                    html_text('q', $filter['q'], ['placeholder' => 'e.g. Galaxy']);
                }); ?>

                <div class="batch-options">
                    <?php field('price_min', 'Current price from', function () use ($filter) {
                        html_number('price_min', $filter['price_min'], ['step' => '0.01', 'min' => '0']);
                    }); ?>

                    <?php field('price_max', 'Current price up to', function () use ($filter) {
                        html_number('price_max', $filter['price_max'], ['step' => '0.01', 'min' => '0']);
                    }); ?>
                </div>
            </div>

            <div class="card card-padded mt-4">
                <h3 class="section-heading">2. What to do</h3>

                <div class="batch-options">
                    <?php field('operation', 'Operation', function () use ($operation) {
                        html_select('operation', batch_price_operations(), $operation);
                    }, true); ?>

                    <?php field('value', 'Amount', function () use ($valueRaw) {
                        html_number('value', $valueRaw, [
                            'step' => '0.01', 'min' => '0', 'placeholder' => 'e.g. 10',
                        ]);
                        echo '<small class="form-hint">A percentage for the percentage '
                           . 'operations, otherwise a ringgit amount.</small>';
                    }, true); ?>
                </div>

                <?php field('rounding', 'Round the result', function () use ($rounding) {
                    html_select('rounding', batch_rounding_modes(), $rounding);
                }); ?>

                <div class="form-actions">
                    <?php html_submit('Preview Changes', ['class' => 'btn-primary']); ?>
                </div>
            </div>
        </form>

    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
