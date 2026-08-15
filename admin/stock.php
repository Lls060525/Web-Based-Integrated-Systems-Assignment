<?php
// ============================================================
// admin/stock.php - Product Stock Handling (Admin)
//
// Stock overview, quick adjustments, movement history, and a
// reconciliation check between products.stock and the ledger.
// ============================================================

require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/admin_rows.php';

$title = 'Stock Control - Admin';

if (!stock_module_ready()) {
    flash_error('Stock control is not available yet: run database/migration_13_stock.sql.');
    redirect('/admin/dashboard.php');
}

// ---------- Adjustment ----------
if (is_post()) {
    csrf_check();

    if (post('action') === 'adjust') {
        $productId = post_int('product_id');
        $quantity  = post_int('quantity');
        $type      = post('type');
        $reason    = post('reason');
        $direction = post('direction');

        $product = $productId === null
            ? null
            : db_one('SELECT id, name, stock FROM products WHERE id = ?', [$productId]);

        if (!$product) {
            flash_error('Product not found.');

        } elseif ($quantity === null || $quantity <= 0) {
            flash_error('Enter a quantity greater than zero.');

        } elseif ($reason === '') {
            flash_error('A reason is required so the movement can be explained later.');

        } elseif (mb_strlen($reason) > 200) {
            flash_error('The reason must not exceed 200 characters.');

        } elseif (!in_array($direction, ['in', 'out'], true)) {
            flash_error('Choose whether stock is coming in or going out.');

        } else {
            // The form collects a positive quantity plus a direction,
            // which is harder to get wrong than typing a minus sign.
            $delta = $direction === 'out' ? -$quantity : $quantity;

            try {
                $newStock = adjust_stock($productId, $delta, $type, $reason, current_user_id());

                flash_success($product['name'] . ': stock '
                    . ($delta > 0 ? 'increased' : 'decreased') . ' by ' . abs($delta)
                    . '. New level is ' . $newStock . '.');

            } catch (\RuntimeException $ex) {
                flash_error($ex->getMessage());
            }
        }
    }

    // Come back to the same product so the history is still on screen.
    $back = '/admin/stock.php';
    $pid  = post_int('product_id');

    if ($pid !== null) {
        $back .= '?product=' . $pid;
    }

    redirect($back);
}

// ---------- Listing ----------
$q      = get('q');
$filter = get('filter');

$threshold = reorder_level_ready() ? 'p.reorder_level' : (string)STOCK_DEFAULT_REORDER_LEVEL;

// FROM/WHERE built once so the count and the page can never disagree.
// $threshold is a column name or an integer constant chosen by the code
// above, never anything from the request.
$from = " FROM products p
          LEFT JOIN categories c ON c.id = p.category_id
         WHERE 1 = 1";
$params = [];

if ($q !== '') {
    $from    .= ' AND (p.name LIKE ? OR c.name LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

if ($filter === 'out') {
    $from .= ' AND p.stock <= 0';
} elseif ($filter === 'low') {
    $from .= " AND p.stock > 0 AND p.stock <= $threshold";
} elseif ($filter === 'ok') {
    $from .= " AND p.stock > $threshold";
}

$pager = paginate((int)db_value('SELECT COUNT(*)' . $from, $params), 20);

$products = db_all(
    'SELECT p.*, c.name AS category_name' . $from
        . ' ORDER BY p.stock ASC, p.name ASC' . pager_limit($pager),
    $params
);

$overview = stock_overview();
$mismatch = stock_reconciliation();

// Selected product for the adjustment panel and history.
$selectedId = get_int('product');
$selected   = $selectedId === null
    ? null
    : db_one('SELECT * FROM products WHERE id = ?', [$selectedId]);

// An AJAX search must return the ROWS only. Returning the whole page is
// what made the admin panel render inside its own table body.
if (is_ajax()) {
    ajax_rows_with_pager(
        fn() => admin_stock_rows($products, $q, $filter, $selected ? (int)$selected['id'] : null),
        $pager
    );
}

$history = $selected ? stock_movements((int)$selected['id'], 30) : [];

$filterOptions = [
    'out' => 'Out of stock',
    'low' => 'Low stock',
    'ok'  => 'Healthy',
];

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Stock Control</h2>

        <div class="admin-header-actions">
            <form action="/admin/stock.php" method="GET" class="filter-form-inline">
                <?php html_hidden('q', $q); ?>
                <?php html_select('filter', $filterOptions, $filter,
                                  ['class' => 'form-control form-control-sm js-auto-submit'],
                                  'All products'); ?>
                <noscript><?php html_submit('Filter', ['class' => 'btn-outline btn-sm']); ?></noscript>
            </form>

            <form action="/admin/stock.php" method="GET" class="admin-search-form"
                  data-target="#stockTableBody">
                <?php if ($filter !== '') { html_hidden('filter', $filter); } ?>
                <input type="text" name="q" value="<?= e($q) ?>"
                       placeholder="Search product or category..." class="admin-search-input">
                <?php html_submit('Search'); ?>
                <?php if ($q !== '' || $filter !== ''): ?>
                    <a href="/admin/stock.php" class="btn-outline">Clear</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Overview -->
    <div class="stat-grid mt-4">
        <div class="card stat-tile">
            <span class="stat-icon"><i class="fas fa-boxes-stacked"></i></span>
            <span class="stat-value"><?= number_format($overview['total_units']) ?></span>
            <span class="stat-label">Units In Stock</span>
        </div>
        <a href="/admin/stock.php?filter=low" class="card stat-tile">
            <span class="stat-icon"><i class="fas fa-triangle-exclamation"></i></span>
            <span class="stat-value"><?= $overview['low_stock'] ?></span>
            <span class="stat-label">Products Low</span>
        </a>
        <a href="/admin/stock.php?filter=out" class="card stat-tile">
            <span class="stat-icon"><i class="fas fa-circle-xmark"></i></span>
            <span class="stat-value"><?= $overview['out_of_stock'] ?></span>
            <span class="stat-label">Products Out</span>
        </a>
        <div class="card stat-tile">
            <span class="stat-icon"><i class="fas fa-sack-dollar"></i></span>
            <span class="stat-value"><?= e(money($overview['stock_value'])) ?></span>
            <span class="stat-label">Stock Value</span>
        </div>
    </div>

    <!-- Reconciliation -->
    <?php if (count($mismatch) > 0): ?>
        <div class="alert alert-error mt-4">
            <strong>Reconciliation warning.</strong>
            The stock column disagrees with the movement ledger for
            <?= count($mismatch) ?> product(s). This should never happen, so it
            points at a real bug rather than something to correct by hand:
            <ul class="err-list mt-2">
                <?php foreach ($mismatch as $m): ?>
                    <li>
                        <?= e($m['name']) ?> &mdash;
                        stock column <strong><?= (int)$m['stock'] ?></strong>,
                        ledger total <strong><?= (int)$m['ledger_total'] ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php else: ?>
        <p class="muted small-note mt-2">
            <i class="fas fa-circle-check stock-ok"></i>
            Every product's stock level matches the sum of its recorded movements.
        </p>
    <?php endif; ?>

    <div class="stock-layout mt-4">

        <!-- Product list -->
        <div class="stock-list">
            <div class="card">
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th></th>
                                <th>Product</th>
                                <th>Stock</th>
                                <th>Reorder At</th>
                                <th>State</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="stockTableBody">
                        <?php admin_stock_rows($products, $q, $filter,
                                                       $selected ? (int)$selected['id'] : null); ?>
                        </tbody>
                    </table>
                </div>

                <?php render_pager($pager); ?>
            </div>
        </div>

        <!-- Adjustment + history -->
        <aside class="stock-side">
            <?php if ($selected === null): ?>
                <div class="card card-padded">
                    <h3>Adjust Stock</h3>
                    <p class="muted small-note">
                        Choose <strong>Manage</strong> on a product to record a movement
                        and see its history.
                    </p>
                </div>
            <?php else: ?>
                <div class="card card-padded">
                    <h3>Adjust Stock</h3>
                    <p class="selected-product">
                        <strong><?= e($selected['name']) ?></strong><br>
                        <span class="muted small-note">
                            Currently <strong><?= (int)$selected['stock'] ?></strong> in stock,
                            reorder at <?= reorder_level($selected) ?>
                        </span>
                    </p>

                    <form action="/admin/stock.php?product=<?= (int)$selected['id'] ?>"
                          method="POST" class="form-standard">
                        <?php csrf_field(); ?>
                        <?php html_hidden('action', 'adjust'); ?>
                        <?php html_hidden('product_id', $selected['id']); ?>

                        <?php field('direction', 'Direction', function () {
                            html_select('direction', ['in' => 'Stock in (+)', 'out' => 'Stock out (-)'], 'in',
                                        ['required' => true]);
                        }, true); ?>

                        <?php field('quantity', 'Quantity', function () {
                            html_number('quantity', '', ['min' => '1', 'required' => true]);
                            echo '<small class="form-hint">Always a positive number. The direction above decides the sign.</small>';
                        }, true); ?>

                        <?php field('type', 'Movement Type', function () {
                            html_select('type', STOCK_MOVEMENT_LABELS, 'restock', ['required' => true]);
                        }, true); ?>

                        <?php field('reason', 'Reason', function () {
                            html_text('reason', '', [
                                'required'    => true,
                                'maxlength'   => 200,
                                'placeholder' => 'Delivery from supplier, invoice #1234',
                            ]);
                        }, true); ?>

                        <?php html_submit('Record Movement', ['class' => 'btn-primary btn-block']); ?>
                    </form>
                </div>

                <div class="card card-padded mt-4">
                    <h3>Movement History</h3>
                    <p class="muted small-note">Newest first.</p>

                    <?php if (count($history) === 0): ?>
                        <p class="muted">No movements recorded.</p>
                    <?php else: ?>
                        <ul class="movement-list">
                            <?php foreach ($history as $m): ?>
                                <?php $positive = (int)$m['quantity'] > 0; ?>
                                <li class="movement-item">
                                    <span class="movement-qty <?= $positive ? 'points-plus' : 'points-minus' ?>">
                                        <?= $positive ? '+' : '' ?><?= (int)$m['quantity'] ?>
                                    </span>
                                    <span class="movement-body">
                                        <strong><?= e(stock_type_label($m['type'])) ?></strong>
                                        <span class="muted small-note">
                                            &rarr; <?= (int)$m['stock_after'] ?> in stock
                                        </span>
                                        <span class="movement-reason"><?= e($m['reason']) ?></span>
                                        <span class="muted small-note">
                                            <?= e(fmt_datetime($m['created_at'])) ?>
                                            <?php if (!empty($m['admin_name'])): ?>
                                                &middot; <?= e($m['admin_name']) ?>
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
