<?php
// ============================================================
// includes/admin_rows.php
//
// Row renderers shared by the admin listing pages.
// Each listing page renders its <tbody> through these functions
// twice: once for the full page and once for the AJAX search
// response. The markup therefore exists in exactly one place.
// ============================================================

if (!function_exists('admin_empty_row')) {

    /** A single "nothing found" row spanning the whole table. */
    function admin_empty_row(int $colspan, string $message = 'No records found.'): void
    {
        echo '<tr><td colspan="' . $colspan . '" class="table-empty">' . e($message) . '</td></tr>';
    }

    /** ---------------- Members ---------------- */
    function admin_member_rows(array $members): void
    {
        if (count($members) === 0) {
            admin_empty_row(7, 'No members found.');
            return;
        }

        foreach ($members as $m):
            $isActive = $m['status'] === 'active';
            $badge = match ($m['status']) {
                'active'  => 'badge-success',
                'banned'  => 'badge-danger',
                default   => 'badge-warning',
            };
            ?>
            <tr>
                <td>#<?= (int)$m['id'] ?></td>
                <td><img src="<?= e(avatar_image($m['profile_photo'])) ?>" alt="" class="table-avatar"></td>
                <td><strong><?= e($m['name']) ?></strong></td>
                <td><?= e($m['email']) ?></td>
                <td><?= e(fmt_date($m['created_at'])) ?></td>
                <td><span class="badge <?= $badge ?>"><?= e(user_status_label($m['status'])) ?></span></td>
                <td>
                    <div class="row-actions">
                        <a href="/admin/member_detail.php?id=<?= (int)$m['id'] ?>" class="btn-outline btn-sm">View</a>

                        <?php if ($m['status'] !== 'deleted'): ?>
                            <form action="/admin/members.php" method="POST" class="inline-form"
                                  data-confirm="<?= $isActive
                                        ? 'Block ' . e($m['name']) . '? They will not be able to log in.'
                                        : 'Unblock ' . e($m['name']) . '?' ?>">
                                <?php csrf_field(); ?>
                                <?php html_hidden('action', 'set_status'); ?>
                                <?php html_hidden('id', $m['id']); ?>
                                <?php html_hidden('status', $isActive ? 'banned' : 'active'); ?>
                                <?php html_submit($isActive ? 'Block' : 'Unblock', [
                                    'class' => 'btn-outline btn-sm ' . ($isActive ? 'btn-danger' : 'btn-success'),
                                ]); ?>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach;
    }

    /** ---------------- Admins ---------------- */
    function admin_admin_rows(array $admins): void
    {
        if (count($admins) === 0) {
            admin_empty_row(7, 'No administrators found.');
            return;
        }

        $selfId = current_user_id();

        foreach ($admins as $a):
            $isSelf    = (int)$a['id'] === $selfId;
            $isDeleted = $a['status'] === 'deleted';
            $isActive  = $a['status'] === 'active';

            $badge = match ($a['status']) {
                'active' => 'badge-success',
                'banned' => 'badge-danger',
                default  => 'badge-warning',
            };
            ?>
            <tr class="<?= $isDeleted ? 'row-deleted' : '' ?>">
                <td>#<?= (int)$a['id'] ?></td>
                <td><img src="<?= e(avatar_image($a['profile_photo'])) ?>" alt="" class="table-avatar"></td>
                <td>
                    <strong><?= e($a['name']) ?></strong>
                    <?php if ($isSelf): ?>
                        <span class="badge badge-self">You</span>
                    <?php endif; ?>
                </td>
                <td><?= e($a['email']) ?></td>
                <td><?= e(fmt_date($a['created_at'])) ?></td>
                <td><span class="badge <?= $badge ?>"><?= e(user_status_label($a['status'])) ?></span></td>
                <td>
                    <div class="row-actions">
                        <a href="/admin/admin_form.php?id=<?= (int)$a['id'] ?>" class="btn-outline btn-sm">Edit</a>

                        <?php if ($isSelf): ?>
                            <span class="muted small-note">Manage your own account in Profile</span>

                        <?php elseif ($isDeleted): ?>
                            <form action="/admin/admins.php" method="POST" class="inline-form"
                                  data-confirm="Restore <?= e($a['name']) ?> as an active administrator?">
                                <?php csrf_field(); ?>
                                <?php html_hidden('action', 'set_status'); ?>
                                <?php html_hidden('id', $a['id']); ?>
                                <?php html_hidden('status', 'active'); ?>
                                <?php html_submit('Restore', ['class' => 'btn-outline btn-sm btn-success']); ?>
                            </form>

                        <?php else: ?>
                            <form action="/admin/admins.php" method="POST" class="inline-form"
                                  data-confirm="<?= $isActive
                                        ? 'Block ' . e($a['name']) . '? They will not be able to log in.'
                                        : 'Unblock ' . e($a['name']) . '?' ?>">
                                <?php csrf_field(); ?>
                                <?php html_hidden('action', 'set_status'); ?>
                                <?php html_hidden('id', $a['id']); ?>
                                <?php html_hidden('status', $isActive ? 'banned' : 'active'); ?>
                                <?php html_submit($isActive ? 'Block' : 'Unblock', [
                                    'class' => 'btn-outline btn-sm ' . ($isActive ? 'btn-danger' : 'btn-success'),
                                ]); ?>
                            </form>

                            <form action="/admin/admins.php" method="POST" class="inline-form"
                                  data-confirm="Delete the administrator <?= e($a['name']) ?>?&#10;&#10;The account is deactivated and kept in the database so existing records stay intact.">
                                <?php csrf_field(); ?>
                                <?php html_hidden('action', 'delete'); ?>
                                <?php html_hidden('id', $a['id']); ?>
                                <?php html_submit('Delete', ['class' => 'btn-outline btn-sm btn-danger']); ?>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach;
    }

    /** ---------------- Products ---------------- */
    function admin_product_rows(array $products): void
    {
        if (count($products) === 0) {
            admin_empty_row(7, 'No products found.');
            return;
        }

        foreach ($products as $p):
            $isActive = $p['status'] === 'active';
            $state    = stock_state($p);
            ?>
            <tr>
                <td>#<?= (int)$p['id'] ?></td>
                <td><img src="<?= e(product_image($p['image'])) ?>" alt="" class="table-thumb"></td>
                <td><strong><?= e($p['name']) ?></strong></td>
                <td><?= e(money($p['price'])) ?></td>
                <td>
                    <?= (int)$p['stock'] ?>
                    <?php if ($state === 'out'): ?>
                        <span class="badge badge-danger">Out</span>
                    <?php elseif ($state === 'low'): ?>
                        <span class="badge badge-warning">Low</span>
                    <?php endif; ?>
                    <?php if (reorder_level_ready()): ?>
                        <br><small class="muted">reorder at <?= reorder_level($p) ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge <?= $isActive ? 'badge-success' : 'badge-danger' ?>">
                        <?= $isActive ? 'Active' : 'Inactive' ?>
                    </span>
                </td>
                <td>
                    <a href="/admin/product_form.php?id=<?= (int)$p['id'] ?>" class="btn-outline btn-sm">Edit</a>

                    <?php if (photo_gallery_ready()): ?>
                        <a href="/admin/product_photos.php?id=<?= (int)$p['id'] ?>" class="btn-outline btn-sm">
                            <i class="fas fa-images"></i>
                            <?= product_photo_count((int)$p['id']) ?>
                        </a>
                    <?php endif; ?>

                    <?php if (spec_module_ready()): ?>
                        <?php $specCount = count(product_specs((int)$p['id'])); ?>
                        <a href="/admin/product_specs.php?id=<?= (int)$p['id'] ?>"
                           class="btn-outline btn-sm <?= $specCount === 0 ? 'is-empty' : '' ?>"
                           title="<?= $specCount === 0 ? 'No specifications recorded' : $specCount . ' specification(s)' ?>">
                            <i class="fas fa-list-check"></i> <?= $specCount ?>
                        </a>
                    <?php endif; ?>

                    <form action="/admin/products.php" method="POST" class="inline-form"
                          <?= $isActive ? 'data-confirm="Deactivate this product? Members will no longer see it."' : '' ?>>
                        <?php csrf_field(); ?>
                        <?php html_hidden('action', 'toggle_status'); ?>
                        <?php html_hidden('id', $p['id']); ?>
                        <?php html_hidden('status', $isActive ? 'inactive' : 'active'); ?>
                        <?php html_submit($isActive ? 'Deactivate' : 'Activate', [
                            'class' => 'btn-outline btn-sm ' . ($isActive ? 'btn-danger' : 'btn-success'),
                        ]); ?>
                    </form>
                </td>
            </tr>
        <?php endforeach;
    }

    /** ---------------- Vouchers ---------------- */
    function admin_voucher_rows(array $vouchers): void
    {
        if (count($vouchers) === 0) {
            admin_empty_row(8, 'No vouchers found.');
            return;
        }

        $now = time();

        foreach ($vouchers as $v):
            $expired  = !empty($v['expires_at']) && strtotime($v['expires_at']) < $now;
            $notYet   = !empty($v['starts_at'])  && strtotime($v['starts_at'])  > $now;
            $usedUp   = $v['usage_limit'] !== null && (int)$v['used_count'] >= (int)$v['usage_limit'];
            $live     = $v['status'] === 'active' && !$expired && !$notYet && !$usedUp;

            [$stateLabel, $stateClass] = match (true) {
                $v['status'] !== 'active' => ['Inactive',  'badge-danger'],
                $expired                  => ['Expired',   'badge-danger'],
                $notYet                   => ['Scheduled', 'badge-warning'],
                $usedUp                   => ['Used up',   'badge-warning'],
                default                   => ['Live',      'badge-success'],
            };
            ?>
            <tr class="<?= $live ? '' : 'row-muted' ?>">
                <td><code class="voucher-code"><?= e($v['code']) ?></code></td>
                <td>
                    <?= e($v['type'] === 'percent'
                            ? rtrim(rtrim(number_format((float)$v['value'], 2), '0'), '.') . '%'
                            : money($v['value'])) ?>
                    <?php if ($v['type'] === 'percent' && $v['max_discount'] !== null): ?>
                        <br><small class="muted">max <?= e(money($v['max_discount'])) ?></small>
                    <?php endif; ?>
                </td>
                <td><?= (float)$v['min_spend'] > 0 ? e(money($v['min_spend'])) : '<span class="muted">-</span>' ?></td>
                <td>
                    <?= (int)$v['used_count'] ?><?= $v['usage_limit'] !== null ? ' / ' . (int)$v['usage_limit'] : '' ?>
                    <?php if ($v['usage_limit'] !== null): ?>
                        <div class="usage-bar">
                            <span style="width: <?= min(100, (int)round(((int)$v['used_count'] / max(1, (int)$v['usage_limit'])) * 100)) ?>%"></span>
                        </div>
                    <?php endif; ?>
                </td>
                <td><?= (int)$v['per_user_limit'] === 0 ? '<span class="muted">Unlimited</span>' : (int)$v['per_user_limit'] ?></td>
                <td><?= !empty($v['expires_at']) ? e(fmt_date($v['expires_at'])) : '<span class="muted">Never</span>' ?></td>
                <td><span class="badge <?= $stateClass ?>"><?= e($stateLabel) ?></span></td>
                <td>
                    <div class="row-actions">
                        <a href="/admin/voucher_form.php?id=<?= (int)$v['id'] ?>" class="btn-outline btn-sm">Edit</a>

                        <form action="/admin/vouchers.php" method="POST" class="inline-form">
                            <?php csrf_field(); ?>
                            <?php html_hidden('action', 'toggle_status'); ?>
                            <?php html_hidden('id', $v['id']); ?>
                            <?php html_hidden('status', $v['status'] === 'active' ? 'inactive' : 'active'); ?>
                            <?php html_submit($v['status'] === 'active' ? 'Disable' : 'Enable', [
                                'class' => 'btn-outline btn-sm ' . ($v['status'] === 'active' ? 'btn-danger' : 'btn-success'),
                            ]); ?>
                        </form>

                        <?php if ((int)$v['used_count'] === 0): ?>
                            <form action="/admin/vouchers.php" method="POST" class="inline-form"
                                  data-confirm="Delete the voucher <?= e($v['code']) ?>?">
                                <?php csrf_field(); ?>
                                <?php html_hidden('action', 'delete'); ?>
                                <?php html_hidden('id', $v['id']); ?>
                                <?php html_submit('Delete', ['class' => 'btn-outline btn-sm btn-danger']); ?>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach;
    }

    /** ---------------- Orders ---------------- */
    function admin_order_rows(array $orders): void
    {
        if (count($orders) === 0) {
            admin_empty_row(6, 'No orders found.');
            return;
        }

        foreach ($orders as $o):
            $nextOptions = next_status_options($o['status']);
            ?>
            <tr>
                <td><strong>#<?= (int)$o['id'] ?></strong></td>
                <td><?= e(fmt_datetime($o['created_at'])) ?></td>
                <td>
                    <?= e($o['customer_name']) ?><br>
                    <small class="muted"><?= e($o['customer_email']) ?></small>
                </td>
                <td><strong><?= e(money($o['total_amount'])) ?></strong></td>
                <td>
                    <span class="status-badge status-<?= e($o['status']) ?>"><?= e(order_status_label($o['status'])) ?></span>
                </td>
                <td>
                    <div class="row-actions">
                        <a href="/admin/order_detail.php?id=<?= (int)$o['id'] ?>" class="btn-outline btn-sm">View</a>

                        <?php if (count($nextOptions) === 0): ?>
                            <span class="muted small-note">Workflow complete</span>
                        <?php else: ?>
                            <form action="/admin/orders.php" method="POST" class="inline-form">
                                <?php csrf_field(); ?>
                                <?php html_hidden('action', 'update_status'); ?>
                                <?php html_hidden('order_id', $o['id']); ?>
                                <select name="status" class="form-control form-control-sm js-auto-submit">
                                    <option value="">Move to...</option>
                                    <?php foreach ($nextOptions as $value => $label): ?>
                                        <option value="<?= e($value) ?>"><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <noscript><button type="submit" class="btn-outline btn-sm">Go</button></noscript>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach;
    }

/**
 * Stock listing rows.
 *
 * Extracted so admin/stock.php can answer an AJAX search with the rows
 * ALONE. Without this the page returned its whole self, layout included,
 * and the live search injected the entire admin panel into the table
 * body -- the panel appeared nested inside itself.
 *
 * @param string $q        current search term, preserved on the Manage link
 * @param string $filter   current state filter, preserved likewise
 * @param int|null $selectedId  the product whose panel is open
 */
function admin_stock_rows(array $products, string $q = '', string $filter = '',
                          ?int $selectedId = null): void
{
    if (count($products) === 0) {
        echo '<tr><td colspan="6" class="table-empty">No products match.</td></tr>';
        return;
    }

    foreach ($products as $p) {
        $state = stock_state($p);
        $badge = match ($state) {
            'out'   => 'badge-danger',
            'low'   => 'badge-warning',
            default => 'badge-success',
        };

        $isSelected = $selectedId !== null && $selectedId === (int)$p['id'];

        $link = '/admin/stock.php?product=' . (int)$p['id']
              . ($q !== '' ? '&amp;q=' . urlencode($q) : '')
              . ($filter !== '' ? '&amp;filter=' . urlencode($filter) : '');
        ?>
        <tr class="<?= $isSelected ? 'row-selected' : '' ?>">
            <td class="cell-thumb">
                <img src="<?= e(product_image($p['image'])) ?>" alt="" class="table-thumb">
            </td>
            <td>
                <strong><?= e($p['name']) ?></strong><br>
                <small class="muted"><?= e($p['category_name'] ?: 'Uncategorised') ?></small>
            </td>
            <td><strong class="stock-number"><?= (int)$p['stock'] ?></strong></td>
            <td class="muted"><?= reorder_level($p) ?></td>
            <td>
                <span class="badge <?= $badge ?>">
                    <?= $state === 'out' ? 'Out' : ($state === 'low' ? 'Low' : 'OK') ?>
                </span>
            </td>
            <td>
                <a href="<?= $link ?>" class="btn-outline btn-sm">Manage</a>
            </td>
        </tr>
        <?php
    }
}

}
