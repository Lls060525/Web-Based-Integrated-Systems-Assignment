<?php
require_once __DIR__ . '/admin_auth.php';

$title = 'Order Management - Admin';

// --- 1. 处理订单状态更新 (PRG 模式防重复提交) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $order_id = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
    $new_status = $_POST['status'] ?? '';

    $valid_statuses = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled'];
    
    if ($order_id && in_array($new_status, $valid_statuses)) {
        $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $order_id]);
    }
    
    header('Location: /admin/orders.php');
    exit;
}

// --- 2. 拉取订单数据 (带搜索) ---
$search_query = trim($_GET['q'] ?? '');
// 联合 users 表，查出买家的名字和邮箱
$sql = "SELECT o.*, u.name as customer_name, u.email as customer_email 
        FROM orders o 
        JOIN users u ON o.user_id = u.id 
        WHERE 1=1";
$params = [];

if ($search_query !== '') {
    // 支持搜订单号或买家名字
    $sql .= " AND (o.id LIKE ? OR u.name LIKE ?)";
    $params[] = "%{$search_query}%";
    $params[] = "%{$search_query}%";
}
$sql .= " ORDER BY o.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// --- 3. AJAX 分流渲染逻辑 ---
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($is_ajax) {
    if (count($orders) > 0) {
        foreach ($orders as $o) {
            $status_class = strtolower($o['status']);
            echo '<tr>';
            echo '<td><strong>#' . $o['id'] . '</strong></td>';
            echo '<td>' . date('d M Y, H:i', strtotime($o['created_at'])) . '</td>';
            echo '<td>' . htmlspecialchars($o['customer_name']) . '<br><small style="color:var(--text-muted);">' . htmlspecialchars($o['customer_email']) . '</small></td>';
            echo '<td><strong>RM ' . number_format($o['total_amount'], 2) . '</strong></td>';
            echo '<td><span class="status-badge status-' . $status_class . '">' . $o['status'] . '</span></td>';
            echo '<td>';
            echo '<div style="display:flex; gap:5px; align-items:center;">';
            echo '<a href="order_detail.php?id=' . $o['id'] . '" class="btn-outline btn-sm">View</a>';
            echo '<form action="orders.php" method="POST" style="margin:0; display:flex; gap:5px;">';
            echo '<input type="hidden" name="action" value="update_status">';
            echo '<input type="hidden" name="order_id" value="' . $o['id'] . '">';
            echo '<select name="status" onchange="this.form.submit()" style="padding:4px; border-radius:4px; border:1px solid var(--border); outline:none;">';
            $statuses = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled'];
            foreach ($statuses as $st) {
                $selected = $o['status'] === $st ? 'selected' : '';
                echo "<option value=\"$st\" $selected>$st</option>";
            }
            echo '</select>';
            echo '</form>';
            echo '</div>';
            echo '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="6" class="text-center" style="padding: 30px; color: var(--text-muted);">No orders found.</td></tr>';
    }
    exit;
}

include __DIR__ . '/../includes/header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Order Management</h2>
        <form action="orders.php" method="GET" class="admin-search-form" id="orderSearchForm">
            <input type="text" name="q" placeholder="Search Order ID or Customer..." 
                   value="<?php echo htmlspecialchars($search_query); ?>" class="admin-search-input" id="orderSearchInput">
            <button type="submit" class="btn-primary" style="display:none;">Search</button>
        </form>
    </div>

    <div class="card mt-4">
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Date & Time</th>
                        <th>Customer</th>
                        <th>Total Amount</th>
                        <th>Status</th>
                        <th>Actions (Update Status)</th>
                    </tr>
                </thead>
                <tbody id="orderTableBody">
                    <?php 
                    if (count($orders) > 0) {
                        foreach ($orders as $o) {
                            $status_class = strtolower($o['status']);
                            ?>
                            <tr>
                                <td><strong>#<?php echo $o['id']; ?></strong></td>
                                <td><?php echo date('d M Y, H:i', strtotime($o['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($o['customer_name']); ?><br><small style="color:var(--text-muted);"><?php echo htmlspecialchars($o['customer_email']); ?></small></td>
                                <td><strong>RM <?php echo number_format($o['total_amount'], 2); ?></strong></td>
                                <td><span class="status-badge status-<?php echo $status_class; ?>"><?php echo $o['status']; ?></span></td>
                                <td>
                                    <div style="display:flex; gap:5px; align-items:center;">
                                        <a href="order_detail.php?id=<?php echo $o['id']; ?>" class="btn-outline btn-sm">View</a>
                                        <form action="orders.php" method="POST" style="margin:0; display:flex; gap:5px;">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
                                            <select name="status" onchange="this.form.submit()" style="padding:4px; border-radius:4px; border:1px solid var(--border); outline:none;">
                                                <?php
                                                $statuses = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled'];
                                                foreach ($statuses as $st) {
                                                    $selected = $o['status'] === $st ? 'selected' : '';
                                                    echo "<option value=\"$st\" $selected>$st</option>";
                                                }
                                                ?>
                                            </select>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        echo '<tr><td colspan="6" class="text-center" style="padding: 30px; color: var(--text-muted);">No orders found.</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// AJAX 防抖搜索逻辑
$(function() {
    var searchTimer;
    $('#orderSearchForm').on('submit', function(e) { e.preventDefault(); });

    $('#orderSearchInput').on('input', function() {
        var query = $(this).val();
        var $tbody = $('#orderTableBody');
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() {
            $tbody.html('<tr><td colspan="6" class="text-center" style="padding: 20px; color: var(--text-muted);">Searching...</td></tr>');
            $.ajax({
                url: '/admin/orders.php',
                type: 'GET',
                data: { q: query },
                success: function(response) {
                    $tbody.html(response);
                    var newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + (query ? '?q=' + encodeURIComponent(query) : '');
                    window.history.pushState({path: newUrl}, '', newUrl);
                }
            });
        }, 300);
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>