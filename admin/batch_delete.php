<?php
// ============================================================
// admin/batch_delete.php - Batch Deletion (Admin)
//
// Deleting a product is not symmetrical with creating one. A product
// somebody has already bought is part of that customer's order record,
// so this page separates the two cases and refuses to blur them.
//
// ------------------------------------------------------------
// THE IDEA THIS PAGE IS BUILT ON
// ------------------------------------------------------------
//
// "Delete" means two different things and an admin rarely says which:
//
//   DEACTIVATE  hide it from the storefront, keep the row
//   DELETE      remove the row and its photo files from disk
//
// For a product nobody has bought, either is fine. For a product that
// appears in an order, only the first is: order_items points at the
// product row, and a receipt printed next year still has to be able to
// say what was bought. Deleting it would either break that link or --
// worse, if the foreign key cascaded -- quietly remove line items from
// a customer's completed order.
//
// So this page does not offer a single Delete button and hope. It
// ANALYSES the selection first, splits it into safe and blocked, and
// shows which is which before anything happens. The blocked ones are
// not silently skipped either; they are named, with the reason.
//
// ------------------------------------------------------------
// SAME TWO-STEP SHAPE AS THE OTHER BATCH TOOLS
// ------------------------------------------------------------
//
//   POST action=analyse  -> works out safe vs blocked, stages the ids
//   POST action=commit   -> applies exactly those staged ids
//
// batch_stage() hands back a one-use token; batch_take_stage() reads
// and destroys it, so a double-click cannot run the deletion twice.
// See lib/batch.php, and admin/batch_import.php for the fuller
// explanation of why parse and write are separated.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

require_permission('batch.manage');

$title    = 'Batch Delete Products - Admin';
$stageKey = 'delete';

$categories = db_all('SELECT id, name FROM categories ORDER BY name ASC');
$categoryOptions = ['' => 'All categories'] + array_column($categories, 'name', 'id');
$statusOptions   = ['all' => 'Any status', 'active' => 'Active only', 'inactive' => 'Inactive only'];

$filterCategory = get('category_id', post('category_id'));
$filterStatus   = get('status', post('status', 'all'));
$filterQuery    = get('q', post('q'));

$analysis = null;

if (is_post()) {
    csrf_check();

    $action = post('action');

    // ---------- Step 2: commit ----------
    if ($action === 'commit') {
        $staged = batch_take_stage($stageKey, post('token'));

        if ($staged === null) {
            flash_error('That selection has expired or was already applied. Please choose again.');
            redirect('/admin/batch_delete.php');
        }

        // The mode comes from the STAGED data, not from this POST.
        //
        // If it were read from the request, someone could preview a
        // harmless "deactivate" and then submit mode=delete with the
        // same token. Reading it back from what was staged means the
        // action carried out is the one that was previewed.
        $mode = $staged['mode'];

        if ($mode === 'delete') {
            // Typed rather than clicked. A checkbox is muscle memory by the
            // third confirmation dialog; typing the word is not.
            if (post('confirm_phrase') !== BATCH_DELETE_PHRASE) {
                flash_error('Nothing was deleted: the confirmation word did not match.');
                redirect('/admin/batch_delete.php');
            }

            // Deletes rows AND the photo files off disk, inside a
            // transaction. It re-checks the order-history rule itself
            // rather than trusting the analysis: the staged ids were
            // worked out a minute ago, and someone may have placed an
            // order containing one of them since. Anything newly unsafe
            // comes back in $result['refused'].
            $result = batch_delete_products($staged['ids'], (int)current_user_id());

            if (!empty($result['rolled_back'])) {
                flash_error('Nothing was deleted. The batch was rolled back: ' . $result['message']);
            } else {
                $message = $result['deleted'] . ' product(s) permanently deleted';

                if ($result['files'] > 0) {
                    $message .= ', ' . $result['files'] . ' photo file(s) removed from disk';
                }

                if ($result['refused'] > 0) {
                    $message .= '. ' . $result['refused'] . ' were refused because they appear in '
                              . 'customer orders: ' . implode(', ', array_slice($result['refused_names'], 0, 5));
                }

                flash_success($message . '.');
            }

        } else {
            $count = batch_deactivate_products($staged['ids']);
            flash_success($count . ' product(s) deactivated. They are hidden from the storefront '
                        . 'but their order history is intact.');
        }

        redirect('/admin/batch_delete.php');
    }

    // ---------- Step 1: analyse the selection ----------
    if ($action === 'analyse') {
        // The checkboxes arrive as ids[] -- an array, so post() (which
        // returns a string) is not the right tool and $_POST is read
        // directly.
        //
        // Three passes, each removing a different kind of rubbish:
        //   intval    turns "7" into 7 and anything non-numeric into 0
        //   unique    a repeated id would be counted twice in the summary
        //   > 0       drops the zeros the first pass just created
        $ids  = array_map('intval', (array)($_POST['ids'] ?? []));
        $ids  = array_values(array_filter(array_unique($ids), static fn($id) => $id > 0));

        // Anything that is not exactly 'delete' becomes 'deactivate'.
        // Written this way round on purpose: a typo or a tampered value
        // lands on the reversible action, never the destructive one.
        $mode = post('mode') === 'delete' ? 'delete' : 'deactivate';

        if ($ids === []) {
            add_err('ids', 'Select at least one product.');
        } else {
            // Splits the selection into 'safe' (never ordered) and
            // 'blocked' (appears in order_items), with the order count
            // per product so the table can explain itself.
            $result = batch_delete_analysis($ids);

            // The key line on this page.
            //
            // Deleting stages only the SAFE ids -- the blocked ones are
            // dropped here and can never reach batch_delete_products().
            // Deactivating stages ALL of them, because hiding a product
            // that has been ordered is exactly what should happen to it.
            $targets = $mode === 'delete' ? $result['safe'] : $ids;

            $token = batch_stage($stageKey, ['ids' => $targets, 'mode' => $mode]);

            $analysis = [
                'token'   => $token,
                'mode'    => $mode,
                'rows'    => $result['rows'],
                'safe'    => $result['safe'],
                'blocked' => $result['blocked'],
            ];
        }
    }
    // Validation failed. Answer with a redirect rather than a page, so
    // the browser's history entry is a GET and F5 cannot resubmit.
    // The errors and what was typed are carried across the redirect.
    redirect_back();
}

// ---------- Listing for the picker ----------
//
// A query assembled from optional filters. Two things make this safe
// and worth copying, and one line looks lazy but is not:
//
//   LEFT JOIN, not JOIN, on categories. An inner join would silently
//   hide every product whose category_id is NULL -- and on a delete
//   screen, a product you cannot see is a product you cannot tidy up.
//
//   WHERE 1 = 1 exists so that every filter below can start with
//   " AND ". Without it the first filter would need " WHERE " and the
//   rest " AND ", which means tracking whether anything has been added
//   yet. MySQL optimises the constant away, so it costs nothing.
//
//   The filter VALUES never touch the SQL string. Each one appends a
//   ? placeholder and pushes the value into $params, which PDO sends
//   separately. This is the whole defence against SQL injection: the
//   database receives the query shape and the data down different
//   channels, so a value can never be read as syntax. Writing
//   "WHERE name LIKE '%$filterQuery%'" here instead would be the
//   classic hole.
$sql    = 'SELECT p.id, p.name, p.price, p.stock, p.status, c.name AS category_name
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
            WHERE 1 = 1';
$params = [];

if ($filterCategory !== '') {
    $sql     .= ' AND p.category_id = ?';
    $params[] = (int)$filterCategory;
}

if ($filterStatus !== 'all' && $filterStatus !== '') {
    $sql     .= ' AND p.status = ?';
    $params[] = $filterStatus;
}

if ($filterQuery !== '') {
    // The % wildcards go on the VALUE, not into the SQL. The query says
    // "LIKE ?" and the parameter happens to contain percent signs --
    // which is why a customer searching for "50%" cannot change the
    // meaning of the statement.
    $sql     .= ' AND p.name LIKE ?';
    $params[] = '%' . $filterQuery . '%';
}

// Ordered by name because this is a list to hunt through by eye, not
// the newest-first listing the other admin pages use.
$sql .= ' ORDER BY p.name ASC';

$products = db_all($sql, $params);

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Batch Tools</h2>
    </div>

    <?php include __DIR__ . '/../includes/batch_nav.php'; ?>

    <?php err_summary(); ?>

    <?php if ($analysis !== null): ?>

        <div class="card card-padded mt-4">
            <h3 class="section-heading">Step 2 of 2 &mdash; review the impact</h3>

            <?php if ($analysis['mode'] === 'delete'): ?>
                <p class="muted small-note">
                    Nothing has been removed yet. Rows marked <strong>protected</strong> will be
                    left alone whatever you choose.
                </p>
            <?php else: ?>
                <p class="muted small-note">
                    These products will be set to <strong>inactive</strong>. Nothing is removed,
                    and they can be reactivated at any time from Product Management.
                </p>
            <?php endif; ?>

            <?php if ($analysis['mode'] === 'delete' && $analysis['blocked'] !== []): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-shield-alt"></i>
                    <strong><?= count($analysis['blocked']) ?> product(s) cannot be deleted.</strong>
                    They appear in customer orders. Order history renders each line by joining
                    back to the product, so deleting one would make those lines disappear from
                    the customer's past orders while the order total stayed the same. Deactivate
                    them instead &mdash; the storefront stops offering them and the records survive.
                </div>
            <?php endif; ?>

            <?php if ($analysis['mode'] === 'delete' && $analysis['safe'] === []): ?>
                <div class="alert alert-error">
                    <i class="fas fa-circle-xmark"></i>
                    Every product you selected has order history, so there is nothing to delete.
                </div>
            <?php endif; ?>

            <div class="table-responsive mt-4">
                <table class="admin-table batch-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Category</th>
                            <th class="text-right">Stock</th>
                            <th class="text-right">In orders</th>
                            <th class="text-right">In carts</th>
                            <th class="text-right">Wishlists</th>
                            <th class="text-right">Reviews</th>
                            <th class="text-right">Photos</th>
                            <th>Outcome</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($analysis['rows'] as $row): ?>
                            <?php $protected = $analysis['mode'] === 'delete' && !$row['deletable']; ?>
                            <tr class="batch-row <?= $protected ? 'is-error' : 'is-insert' ?>">
                                <td><?= e($row['name']) ?></td>
                                <td><?= e($row['category_name'] ?? '-') ?></td>
                                <td class="text-right"><?= (int)$row['stock'] ?></td>
                                <td class="text-right"><strong><?= (int)$row['orders'] ?></strong></td>
                                <td class="text-right"><?= (int)$row['cart'] ?></td>
                                <td class="text-right"><?= (int)$row['wishlist'] ?></td>
                                <td class="text-right"><?= (int)$row['reviews'] ?></td>
                                <td class="text-right"><?= (int)$row['photos'] ?></td>
                                <td>
                                    <?php if ($analysis['mode'] !== 'delete'): ?>
                                        <span class="badge badge-info">Deactivate</span>
                                    <?php elseif ($protected): ?>
                                        <span class="badge badge-danger">Protected</span>
                                    <?php else: ?>
                                        <span class="badge badge-muted">Delete permanently</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form action="/admin/batch_delete.php" method="POST" class="form-standard mt-4">
                <?php csrf_field(); ?>
                <?php html_hidden('action', 'commit'); ?>
                <?php html_hidden('token', $analysis['token']); ?>

                <?php if ($analysis['mode'] === 'delete' && $analysis['safe'] !== []): ?>
                    <div class="alert alert-error">
                        <strong>This cannot be undone.</strong>
                        <?= count($analysis['safe']) ?> product(s), along with their photos,
                        cart entries, wishlist entries, reviews and stock history, will be
                        removed permanently.
                    </div>

                    <?php field('confirm_phrase', 'Type ' . BATCH_DELETE_PHRASE . ' to confirm',
                        function () {
                            html_text('confirm_phrase', '', [
                                'autocomplete' => 'off',
                                'spellcheck'   => 'false',
                                'placeholder'  => BATCH_DELETE_PHRASE,
                            ]);
                        }, true); ?>
                <?php endif; ?>

                <div class="form-actions batch-actions">
                    <a href="/admin/batch_delete.php" class="btn-outline">Cancel</a>

                    <?php if ($analysis['mode'] === 'delete'): ?>
                        <?php if ($analysis['safe'] !== []): ?>
                            <?php html_submit('Delete ' . count($analysis['safe']) . ' Product(s) Permanently',
                                ['class' => 'btn-danger-solid']); ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php html_submit('Deactivate ' . count($analysis['rows']) . ' Product(s)',
                            ['class' => 'btn-primary']); ?>
                    <?php endif; ?>
                </div>
            </form>
        </div>

    <?php else: ?>

        <div class="card card-padded mt-4">
            <form action="/admin/batch_delete.php" method="GET" class="batch-filter-form">
                <?php html_select('category_id', $categoryOptions, $filterCategory); ?>
                <?php html_select('status', $statusOptions, $filterStatus); ?>
                <input type="text" name="q" value="<?= e($filterQuery) ?>"
                       placeholder="Name contains..." class="admin-search-input">
                <?php html_submit('Filter'); ?>
                <a href="/admin/batch_delete.php" class="btn-outline">Reset</a>
            </form>
        </div>

        <form action="/admin/batch_delete.php" method="POST">
            <?php csrf_field(); ?>
            <?php html_hidden('action', 'analyse'); ?>
            <?php html_hidden('category_id', $filterCategory); ?>
            <?php html_hidden('status', $filterStatus); ?>
            <?php html_hidden('q', $filterQuery); ?>

            <div class="card mt-4">
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th class="col-check">
                                    <input type="checkbox" id="batchSelectAll"
                                           aria-label="Select every product listed">
                                </th>
                                <th>Product</th>
                                <th>Category</th>
                                <th class="text-right">Price</th>
                                <th class="text-right">Stock</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($products === []): ?>
                                <tr><td colspan="6" class="text-center muted">No products match that filter.</td></tr>
                            <?php else: ?>
                                <?php foreach ($products as $p): ?>
                                    <tr>
                                        <td class="col-check">
                                            <input type="checkbox" name="ids[]" class="batch-select"
                                                   value="<?= (int)$p['id'] ?>"
                                                   aria-label="Select <?= e($p['name']) ?>">
                                        </td>
                                        <td><?= e($p['name']) ?></td>
                                        <td><?= e($p['category_name'] ?? '-') ?></td>
                                        <td class="text-right"><?= e(money($p['price'])) ?></td>
                                        <td class="text-right"><?= (int)$p['stock'] ?></td>
                                        <td>
                                            <span class="badge badge-<?= $p['status'] === 'active' ? 'success' : 'muted' ?>">
                                                <?= e(ucfirst($p['status'])) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card card-padded mt-4">
                <h3 class="section-heading">What should happen to them</h3>

                <div class="form-group form-check">
                    <label for="mode_deactivate" class="check-label">
                        <input type="radio" name="mode" id="mode_deactivate" value="deactivate" checked>
                        <span>
                            Deactivate <span class="badge badge-success">Recommended</span>
                            <small class="form-hint">
                                Hidden from the storefront, kept in the database. Reversible,
                                and safe for products that appear in past orders.
                            </small>
                        </span>
                    </label>
                </div>

                <div class="form-group form-check">
                    <label for="mode_delete" class="check-label">
                        <input type="radio" name="mode" id="mode_delete" value="delete">
                        <span>
                            Delete permanently
                            <small class="form-hint">
                                Removes the product and its photos, cart entries, wishlist
                                entries, reviews and stock history. Anything with order history
                                is refused automatically.
                            </small>
                        </span>
                    </label>
                </div>

                <div class="form-actions">
                    <span class="muted small-note" id="batchCount">0 selected</span>
                    <?php html_submit('Review Selection', ['class' => 'btn-primary']); ?>
                </div>
            </div>
        </form>

    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
