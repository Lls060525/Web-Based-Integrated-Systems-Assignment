<?php
// ============================================================
// compare.php - Side-by-side specification comparison
//
// Accepts either form:
//   ?ids=4,7,9          an explicit list
//   ?base=4&with=7      the launcher on the product page
//
// The rows where every product says the same thing are collapsed by
// default. Comparing two phones from one maker, most of the table is
// identical and the three rows that differ are the entire reason
// somebody opened this page.
// ============================================================

require_once __DIR__ . '/lib/init.php';

$title = 'Compare Products - ' . APP_NAME;

const COMPARE_MAX = 4;

// ---------- Read the selection ----------
$ids = [];

if (get('ids') !== '') {
    foreach (explode(',', get('ids')) as $part) {
        $part = trim($part);

        if (ctype_digit($part)) {
            $ids[] = (int)$part;
        }
    }
}

foreach (['base', 'with'] as $key) {
    $value = get_int($key);

    if ($value !== null && $value > 0) {
        $ids[] = $value;
    }
}

// A product compared against itself is a blank column, and more than
// four will not fit on a phone screen.
$ids = array_values(array_unique($ids));
$ids = array_slice($ids, 0, COMPARE_MAX);

$showSame   = get('same') === '1';
$comparison = spec_comparison($ids);
$products   = $comparison['products'];
$rows       = $comparison['rows'];

$differing = 0;
foreach ($rows as $row) {
    if (empty($row['same'])) {
        $differing++;
    }
}

// ---------- What else could be added ----------
$candidates = [];

if ($products !== [] && count($products) < COMPARE_MAX) {
    $marks = implode(',', array_fill(0, count($ids), '?'));

    $candidates = db_all(
        "SELECT id, name, price FROM products
          WHERE category_id = ? AND status = 'active' AND id NOT IN ($marks)
          ORDER BY name ASC LIMIT 20",
        array_merge([$products[0]['category_id']], $ids)
    );
}

/** Rebuild this page's URL with a different id list. */
function compare_url(array $ids, bool $showSame): string
{
    $url = '/compare.php?ids=' . implode(',', $ids);

    return $showSame ? $url . '&same=1' : $url;
}

include __DIR__ . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="/products.php">Products</a> &gt; <span>Compare</span>
</nav>

<div class="page-title-row">
    <h2 class="page-title">Compare Products</h2>

    <?php if (count($products) > 1 && $rows !== []): ?>
        <a href="<?= e(compare_url($ids, !$showSame)) ?>" class="btn-outline btn-sm">
            <?= $showSame ? 'Show only differences' : 'Show identical rows too' ?>
        </a>
    <?php endif; ?>
</div>

<?php if (!spec_module_ready()): ?>

    <div class="alert alert-info">
        The specifications module is not installed yet.
    </div>

<?php elseif (count($products) < 2): ?>

    <div class="card card-padded empty-state-box">
        <h3 class="empty-state-title">Pick at least two products.</h3>
        <p class="muted">
            Open any product and use <strong>Compare with</strong> underneath its
            specification table.
        </p>
        <a href="/products.php" class="btn-primary mt-4">Browse Products</a>
    </div>

<?php else: ?>

    <?php if ($rows === []): ?>
        <div class="alert alert-info">
            None of these products has specifications recorded yet, so there is
            nothing to line up.
        </div>
    <?php elseif ($differing === 0): ?>
        <div class="alert alert-info">
            <i class="fas fa-equals"></i>
            These products are identical on every recorded specification.
            The difference between them is elsewhere &mdash; price, colour or brand.
        </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="compare-table">
            <thead>
                <tr>
                    <th class="compare-corner">
                        <?= $showSame ? count($rows) : $differing ?> row(s) shown
                    </th>

                    <?php foreach ($products as $product): ?>
                        <th class="compare-head">
                            <?php $others = array_values(array_diff($ids, [(int)$product['id']])); ?>

                            <?php if (count($products) > 2): ?>
                                <a href="<?= e(compare_url($others, $showSame)) ?>"
                                   class="compare-remove" title="Remove from comparison"
                                   aria-label="Remove <?= e($product['name']) ?>">&times;</a>
                            <?php endif; ?>

                            <img src="<?= e(product_image($product['image'])) ?>"
                                 alt="<?= e($product['name']) ?>" class="compare-img">

                            <a href="/product_detail.php?id=<?= (int)$product['id'] ?>"
                               class="compare-name"><?= e($product['name']) ?></a>

                            <span class="compare-price"><?= e(money($product['price'])) ?></span>

                            <span class="compare-stock <?= e(stock_class($product)) ?>">
                                <?= e(stock_message($product)) ?>
                            </span>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php if (!$showSame && !empty($row['same'])) { continue; } ?>

                    <tr class="<?= !empty($row['same']) ? 'compare-same' : 'compare-differs' ?>">
                        <th scope="row" class="compare-label">
                            <?= e($row['name']) ?>
                            <?php if (!empty($row['unit'])): ?>
                                <span class="muted">(<?= e($row['unit']) ?>)</span>
                            <?php endif; ?>
                        </th>

                        <?php foreach ($products as $product): ?>
                            <?php $value = $row['values'][(int)$product['id']] ?? null; ?>
                            <td>
                                <?php if ($value === null): ?>
                                    <span class="muted">&mdash;</span>
                                <?php else: ?>
                                    <?= e($value) ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($candidates !== []): ?>
        <div class="card card-padded mt-4">
            <form action="/compare.php" method="GET" class="spec-compare-form">
                <label for="addProduct" class="spec-compare-label">
                    Add another (<?= count($products) ?> of <?= COMPARE_MAX ?>)
                </label>

                <input type="hidden" name="ids" value="<?= e(implode(',', $ids)) ?>">
                <?php if ($showSame): ?>
                    <input type="hidden" name="same" value="1">
                <?php endif; ?>

                <select id="addProduct" name="with" class="form-control">
                    <?php foreach ($candidates as $candidate): ?>
                        <option value="<?= (int)$candidate['id'] ?>">
                            <?= e($candidate['name']) ?> &mdash; <?= e(money($candidate['price'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php html_submit('Add', ['class' => 'btn-outline']); ?>
            </form>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
