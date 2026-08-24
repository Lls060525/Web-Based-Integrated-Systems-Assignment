<?php
// ============================================================
// admin/stores.php - Store Locations (Admin)
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
        db()->beginTransaction();

        try {
            db_exec('UPDATE stores SET is_primary = 0');
            db_exec('UPDATE stores SET is_primary = 1, is_active = 1 WHERE id = ?', [$id]);
            db()->commit();

            flash_success($store['name'] . ' is now the flagship store.');

        } catch (\Throwable $e) {
            db()->rollBack();
            flash_error('Could not update the flagship store.');
        }

    } elseif ($action === 'delete') {
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
