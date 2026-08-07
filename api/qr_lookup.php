<?php
// ============================================================
// api/qr_lookup.php - resolve a scanned code to an order
// Always responds with JSON.
//
// Admin only. The scanner is a counter tool: it is used to confirm that
// the person collecting an order is holding a real receipt, and it
// returns more than the public verify.php page does.
// ============================================================

require_once __DIR__ . '/../lib/init.php';

// One call replaces the role check, the POST check and the CSRF
// check that used to be copy-pasted into every endpoint.
ajax_guard_post('admin');

$raw = trim(post('value'));

if ($raw === '') {
    json_out(['status' => 'error', 'message' => 'Nothing was scanned.']);
}

// ---------- Work out what was scanned ----------
// The camera hands back whatever the code contained, which may be one
// of our URLs, a bare token, a typed reference, or something entirely
// unrelated that happened to be in frame.
$orderId = null;
$source  = '';

if (preg_match('~[?&]t=([^&\s]+)~', $raw, $m)) {
    $orderId = order_id_from_token(urldecode($m[1]));
    $source  = 'QR code';

} elseif (str_contains($raw, '.') && !str_contains($raw, ' ')) {
    $orderId = order_id_from_token($raw);
    $source  = 'QR code';

} else {
    $orderId = order_id_from_short_code($raw);
    $source  = 'typed reference';
}

if ($orderId === null) {
    json_out([
        'status'  => 'error',
        'message' => 'That code did not come from ' . APP_NAME . ', or it has been altered.',
        'scanned' => mb_substr($raw, 0, 120),
    ]);
}

$order = db_one(
    'SELECT o.*, u.name AS customer_name, u.email AS customer_email
       FROM orders o
       JOIN users u ON u.id = o.user_id
      WHERE o.id = ?',
    [$orderId]
);

if (!$order) {
    json_out(['status' => 'error', 'message' => 'Order #' . $orderId . ' no longer exists.']);
}

$lines = order_lines($orderId);
$items = [];

foreach ($lines as $line) {
    $items[] = [
        'name'     => $line['product_name']
                    . (!empty($line['options_text']) ? ' (' . $line['options_text'] . ')' : ''),
        'quantity' => (int)$line['quantity'],
        'price'    => money($line['price_at_purchase']),
    ];
}

json_out([
    'status'      => 'ok',
    'source'      => $source,
    'id'          => (int)$order['id'],
    'receipt_no'  => receipt_number($order),
    'short_code'  => order_short_code((int)$order['id']),
    'customer'    => $order['customer_name'],
    'email'       => $order['customer_email'],
    'placed'      => fmt_datetime($order['created_at']),
    'order_status'=> $order['status'],
    'status_label'=> order_status_label($order['status']),
    'total'       => money($order['total_amount']),
    'item_count'  => array_sum(array_column($items, 'quantity')),
    'items'       => $items,
    'detail_url'  => '/admin/order_detail.php?id=' . (int)$order['id'],
]);
