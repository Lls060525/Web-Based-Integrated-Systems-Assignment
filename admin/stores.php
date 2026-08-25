<?php
// ============================================================
// admin/stores.php - Store Locations (Admin)
//
// Four actions on the store list -- toggle visibility, set the
// flagship, delete, and the implicit "just show me the list".
//
// Two things in this file are worth reading closely, and they are the
// same idea applied twice: SOME STATES MUST ALWAYS HOLD, and the code
// is responsible for them because the database cannot express them.
//
//   "at least one active store"  -- enforced in the toggle branch
//   "exactly one flagship"       -- enforced in the primary branch
//
// A CHECK constraint cannot say either of those; both are statements
// about the table as a whole rather than about one row. So they are
// enforced here, and the second one needs a transaction to stay true
// even for the instant it takes to move the flag.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

require_permission('stores.manage');

$title = 'Store Locations - Admin';

if (!store_module_ready()) {
    include __DIR__ . '/../includes/admin_header.php';
    echo '<div class="admin-container"><div class="alert alert-error mt-4">'
       . 'This module is not installed yet. Run '
       . '<code>database/migration_23_stores.sql</code> in phpMyAdmin.'
       . '</div></div>';
    include __DIR__ . '/../includes/admin_footer.php';
    exit;
}

if (is_post()) {
    csrf_check();

    $id     = post_int('id');
    $action = post('action');
    $store  = $id !== null ? find_store($id) : null;

    if (!$store) {
        flash_error('Store not found.');

    } elseif ($action === 'toggle') {
        $next = (int)$store['is_active'] === 1 ? 0 : 1;

        // Deactivating the last visible store would empty the public
        // locator with no warning, so it is refused the same way the
        // admin module refuses to remove the last admin.
        if ($next === 0) {
            $remaining = (int)db_value(
                'SELECT COUNT(*) FROM stores WHERE is_active = 1 AND id <> ?', [$id]
            );

            if ($remaining === 0) {
                flash_error('This is the only active store. Add another before hiding this one, '
                          . 'or the store locator will be empty.');
                redirect('/admin/stores.php');
            }
        }

        db_exec('UPDATE stores SET is_active = ? WHERE id = ?', [$next, $id]);
        flash_success($store['name'] . ($next === 1 ? ' is now visible.' : ' is now hidden.'));

    } elseif ($action === 'primary') {
        // Exactly one flagship, so the pair of writes is a transaction.
        //
        // THIS IS THE CLEAREST EXAMPLE OF A TRANSACTION IN THE PROJECT
        // -- worth understanding before the demo, because "why is that
        // a transaction" is an obvious thing to be asked.
        //
        // Moving a flag from one row to another cannot be done in one
        // statement. It takes two: clear it everywhere, then set it
        // here. Between those two statements the table is in a state
        // that must never be observed -- NO store is the flagship.
        //
        // Without a transaction, a crash, a timeout or a lost
        // connection between the two lines leaves the shop with no
        // flagship at all, permanently, and nothing reports it. Another
        // request reading the table in that window sees the same thing.
        //
        // beginTransaction() makes the pair atomic: either both
        // statements land or neither does. There is no moment at which
        // another connection can see zero flagships.
        db()->beginTransaction();

        try {
            // Deliberately unfiltered -- clear the flag on EVERY row.
            // Cheaper and safer than finding the current holder first,
            // and it self-heals if two rows somehow both have it.
            db_exec('UPDATE stores SET is_primary = 0');

            // is_active = 1 as well as is_primary = 1: the flagship is
            // what the storefront shows first, so a hidden flagship
            // would be a contradiction. Set together, in one statement,
            // so the two can never disagree.
            db_exec('UPDATE stores SET is_primary = 1, is_active = 1 WHERE id = ?', [$id]);

            // Nothing is permanent until this line.
            db()->commit();

            flash_success($store['name'] . ' is now the flagship store.');

        } catch (\Throwable $e) {
            // Undoes the first UPDATE as well as the second. Without
            // this the flag would stay cleared everywhere -- the exact
            // broken state the transaction exists to prevent.
            db()->rollBack();
            flash_error('Could not update the flagship store.');
        }

    } elseif ($action === 'delete') {
        // A hard DELETE, unlike products and admins, which are
        // deactivated instead.
        //
        // The difference is whether anything else points at the row. An
        // order references the product that was bought, so deleting it
        // would damage a customer's records. Nothing references a
        // store -- it is display data for the locator page -- so there
        // is no history to protect and a real delete is honest.
        db_exec('DELETE FROM stores WHERE id = ?', [$id]);
        flash_success($store['name'] . ' deleted.');

    } else {
        flash_error('Invalid request.');
    }

    redirect('/admin/stores.php');
}

$stores  = all_stores();
$mapInfo = map_status();

$withCoords = 0;
foreach ($stores as $store) {
    if (store_has_coordinates($store)) {
        $withCoords++;
    }
}

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Store Locations</h2>

        <div class="admin-header-actions">
            <a href="/stores.php" class="btn-outline" target="_blank" rel="noopener">View Locator</a>
            <a href="/admin/store_form.php" class="btn-primary">+ Add Store</a>
        </div>
    </div>

    <div class="alert alert-<?= $mapInfo['driver'] === 'js' ? 'info' : 'warning' ?>">
        <i class="fas fa-map-location-dot"></i>
        <strong>Map driver: <?= e($mapInfo['driver']) ?>.</strong>
        <?= e($mapInfo['note']) ?>
        <?php if ($withCoords < count($stores)): ?>
            <br>
            <?= count($stores) - $withCoords ?> store(s) have no coordinates and will not
            appear on a map.
        <?php endif; ?>
    </div>

    <div class="card mt-4">
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Store</th>
                        <th>City</th>
                        <th>Coordinates</th>
                        <th>Phone</th>
                        <th class="text-center">Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($stores === []): ?>
                        <tr><td colspan="6" class="text-center muted">
                            No stores yet. Add one, or run the migration to load the samples.
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($stores as $s): ?>
                            <tr class="<?= (int)$s['is_active'] === 1 ? '' : 'row-inactive' ?>">
                                <td>
                                    <strong><?= e($s['name']) ?></strong>
                                    <?php if ((int)$s['is_primary'] === 1): ?>
                                        <span class="badge badge-info">Flagship</span>
                                    <?php endif; ?>
                                    <div class="muted small-note"><code><?= e($s['code']) ?></code></div>
                                </td>
                                <td><?= e($s['city']) ?><div class="muted small-note"><?= e($s['state']) ?></div></td>
                                <td>
                                    <?php if (store_has_coordinates($s)): ?>
                                        <a href="<?= e(store_maps_url($s)) ?>" target="_blank"
                                           rel="noopener noreferrer" class="coord-link">
                                            <?= e($s['latitude']) ?>, <?= e($s['longitude']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="err small-note">
                                            <i class="fas fa-triangle-exclamation"></i> Not set
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($s['phone'] ?: '-') ?></td>
                                <td class="text-center">
                                    <span class="badge badge-<?= (int)$s['is_active'] === 1 ? 'success' : 'muted' ?>">
                                        <?= (int)$s['is_active'] === 1 ? 'Visible' : 'Hidden' ?>
                                    </span>
                                </td>
                                <td class="text-right">
                                    <div class="row-actions">
                                        <a href="/admin/store_form.php?id=<?= (int)$s['id'] ?>"
                                           class="btn-outline btn-sm">Edit</a>

                                        <?php if ((int)$s['is_primary'] !== 1): ?>
                                            <form action="/admin/stores.php" method="POST" class="inline-form">
                                                <?php csrf_field(); ?>
                                                <?php html_hidden('action', 'primary'); ?>
                                                <?php html_hidden('id', $s['id']); ?>
                                                <?php html_submit('Set Flagship', ['class' => 'btn-outline btn-sm']); ?>
                                            </form>
                                        <?php endif; ?>

                                        <form action="/admin/stores.php" method="POST" class="inline-form">
                                            <?php csrf_field(); ?>
                                            <?php html_hidden('action', 'toggle'); ?>
                                            <?php html_hidden('id', $s['id']); ?>
                                            <?php html_submit((int)$s['is_active'] === 1 ? 'Hide' : 'Show',
                                                              ['class' => 'btn-outline btn-sm']); ?>
                                        </form>

                                        <form action="/admin/stores.php" method="POST" class="inline-form"
                                              data-confirm="Delete <?= e($s['name']) ?>? This cannot be undone.">
                                            <?php csrf_field(); ?>
                                            <?php html_hidden('action', 'delete'); ?>
                                            <?php html_hidden('id', $s['id']); ?>
                                            <?php html_submit('Delete', ['class' => 'btn-outline btn-sm btn-danger']); ?>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
