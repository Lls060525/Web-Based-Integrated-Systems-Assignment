<?php
// ============================================================
// admin/orders.php - Order Listing + Status Update (Admin)
// ============================================================

require_once __DIR__ . '/admin_auth.php';

require_permission('orders.manage');
require_once __DIR__ . '/../includes/admin_rows.php';

$title = 'Order Management - Admin';

// ---------- Status update (PRG pattern) ----------
if (is_post()) {
    csrf_check();

    if (post('action') === 'update_status') {
        $orderId   = post_int('order_id');
        $newStatus = post('status');
        $note      = post('status_note');

        if ($newStatus === '') {
            // The "Move to..." placeholder was submitted - do nothing.

        } elseif ($orderId === null || !in_array($newStatus, ORDER_STATUSES, true)) {
            flash_error('Invalid order status.');

        } elseif (mb_strlen($note) > 500) {
            flash_error('The note must not exceed 500 characters.');

        } else {
            // The list has no file input, so a move that needs a photograph
            // is refused here and the person is sent to the order page --
            // which does have one. Silently allowing it without evidence
            // would be a hole straight through the rule.
            if (transition_needs_evidence($newStatus)) {
                flash_error(order_status_label($newStatus)
                    . ' needs a photograph, so it cannot be set from the list. '
                    . 'Open the order, or scan its QR code.');

            } else {
                $result = handle_status_change($orderId, $newStatus, $note);

                $result['ok']
                    ? flash_success($result['message'])
                    : flash_error($result['message']);
            }
        }
    }

    redirect('/admin/orders.php');
}

// ---------- Listing ----------
$q = get('q');

// FROM/WHERE built once, used for both the count and the page. See the
// note in admin/products.php.
$from = ' FROM orders o
          JOIN users u ON u.id = o.user_id';
$params = [];

if ($q !== '') {
    $from    .= ' WHERE (o.id LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$pager = paginate((int)db_value('SELECT COUNT(*)' . $from, $params), 20);

$orders = db_all(
    'SELECT o.*, u.name AS customer_name, u.email AS customer_email' . $from
        . ' ORDER BY o.created_at DESC' . pager_limit($pager),
    $params
);

if (is_ajax()) {
    ajax_rows_with_pager(fn() => admin_order_rows($orders), $pager);
}

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Order Management</h2>

        <form action="/admin/orders.php" method="GET" class="admin-search-form" data-target="#orderTableBody">
            <input type="text" name="q" value="<?= e($q) ?>"
                   placeholder="Search order id, customer or email..." class="admin-search-input">
            <?php html_submit('Search'); ?>
            <?php if ($q !== ''): ?>
                <a href="/admin/orders.php" class="btn-outline">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card mt-4">
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Date &amp; Time</th>
                        <th>Customer</th>
                        <th>Total Amount</th>
                        <th>Status</th>
                        <th>Actions (Update Status)</th>
                    </tr>
                </thead>
                <tbody id="orderTableBody">
                    <?php admin_order_rows($orders); ?>
                </tbody>
            </table>
        </div>

        <?php render_pager($pager); ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
