<?php
// ============================================================
// lib/stock.php
// Every change to products.stock goes through this file.
//
// DESIGN NOTE, worth reading before changing anything:
//
// products.stock stays the authoritative value, and stock_movements
// is the audit trail explaining how it got there. That is the OPPOSITE
// of lib/points.php, where the balance is SUM(points) with no column.
//
// The reason is concurrency. Selling stock has to be atomic:
//     UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?
// The check and the decrement happen in one statement, so two members
// buying the last unit at the same instant cannot both succeed. If the
// quantity had to be derived with SUM() we would need to read first and
// write second, and the gap between them is exactly the race we are
// trying to close.
//
// Redeeming points has no such race: it happens once per checkout,
// inside a transaction, for a single member.
//
// The price of keeping a column is that it could theoretically drift
// from the ledger, so stock_reconciliation() exists to detect that and
// the admin stock page shows any mismatch.
// ============================================================

/** True once the movement table exists (see the migration). */
function stock_module_ready(): bool
{
    return db_table_exists('stock_movements');
}

/** True once products.reorder_level exists. */
function reorder_level_ready(): bool
{
    return db_column_exists('products', 'reorder_level');
}

// ------------------------------------------------------------
// Classification
// ------------------------------------------------------------

/** The threshold below which a product counts as low. */
function reorder_level(array $product): int
{
    return (int)($product['reorder_level'] ?? STOCK_DEFAULT_REORDER_LEVEL);
}

/** 'out' | 'low' | 'ok' for one product row. */
function stock_state(array $product): string
{
    $stock = (int)$product['stock'];

    if ($stock <= 0) {
        return 'out';
    }

    return $stock <= reorder_level($product) ? 'low' : 'ok';
}

/** Wording shown to a member on the storefront. */
function stock_message(array $product): string
{
    return match (stock_state($product)) {
        'out' => 'Out of Stock',
        'low' => 'Low Stock (only ' . (int)$product['stock'] . ' left)',
        default => 'In Stock (' . (int)$product['stock'] . ')',
    };
}

/** CSS class matching the state. */
function stock_class(array $product): string
{
    return match (stock_state($product)) {
        'out' => 'stock-out',
        'low' => 'stock-low',
        default => 'stock-ok',
    };
}

// ------------------------------------------------------------
// Ledger
// ------------------------------------------------------------

/**
 * Append one movement.
 * Call this only from the functions below, so stock_after is always
 * written from a value that has just been read back.
 */
function record_stock_movement(
    int $productId,
    string $type,
    int $quantity,
    string $reason,
    ?int $orderId = null,
    ?int $createdBy = null
): void {
    if (!stock_module_ready()) {
        return;
    }

    $stockAfter = (int)db_value('SELECT stock FROM products WHERE id = ?', [$productId]);

    db_exec(
        'INSERT INTO stock_movements
                (product_id, type, quantity, stock_after, order_id, reason, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$productId, $type, $quantity, $stockAfter, $orderId, $reason, $createdBy]
    );
}

/** Movement history for one product, newest first. */
function stock_movements(int $productId, int $limit = 50): array
{
    if (!stock_module_ready()) {
        return [];
    }

    return db_all(
        'SELECT m.*, u.name AS admin_name
           FROM stock_movements m
           LEFT JOIN users u ON u.id = m.created_by
          WHERE m.product_id = ?
          ORDER BY m.id DESC
          LIMIT ' . max(1, min(200, $limit)),
        [$productId]
    );
}

/** Label for a movement type. */
function stock_type_label(string $type): string
{
    return STOCK_ALL_MOVEMENT_LABELS[$type] ?? ucfirst($type);
}

// ------------------------------------------------------------
// Mutations
// ------------------------------------------------------------

/**
 * Take stock out for a sale.
 *
 * The conditional UPDATE is the whole point: it refuses rather than
 * going negative, and it does the check and the decrement in one
 * statement so there is no window between them.
 *
 * @throws RuntimeException when there is no longer enough stock
 */
function deduct_stock(int $productId, int $quantity, int $orderId): void
{
    if ($quantity <= 0) {
        return;
    }

    $affected = db_exec(
        'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?',
        [$quantity, $productId, $quantity]
    );

    if ($affected === 0) {
        $name = db_value('SELECT name FROM products WHERE id = ?', [$productId]) ?: ('#' . $productId);
        throw new RuntimeException('Stock ran out for ' . $name . ' while the order was being placed.');
    }

    record_stock_movement(
        $productId,
        'sale',
        -$quantity,
        'Sold on order #' . $orderId,
        $orderId
    );
}

/** Put stock back, e.g. when an order is cancelled. */
function return_stock(int $productId, int $quantity, int $orderId, string $reason = ''): void
{
    if ($quantity <= 0) {
        return;
    }

    db_exec('UPDATE products SET stock = stock + ? WHERE id = ?', [$quantity, $productId]);

    record_stock_movement(
        $productId,
        'return',
        $quantity,
        $reason !== '' ? $reason : 'Returned from cancelled order #' . $orderId,
        $orderId
    );
}

/**
 * Manual change by an admin.
 *
 * @param int $delta positive to add, negative to remove
 * @throws RuntimeException when the change would push stock below zero
 */
function adjust_stock(int $productId, int $delta, string $type, string $reason, ?int $adminId = null): int
{
    if ($delta === 0) {
        throw new RuntimeException('Enter a non-zero quantity.');
    }

    if (!array_key_exists($type, STOCK_MOVEMENT_LABELS)) {
        throw new RuntimeException('Invalid movement type.');
    }

    if ($delta < 0) {
        // Same conditional-update trick, so a correction cannot go negative.
        $affected = db_exec(
            'UPDATE products SET stock = stock + ? WHERE id = ? AND stock >= ?',
            [$delta, $productId, -$delta]
        );

        if ($affected === 0) {
            $current = (int)db_value('SELECT stock FROM products WHERE id = ?', [$productId]);
            throw new RuntimeException('That would take stock below zero. Current stock is ' . $current . '.');
        }
    } else {
        db_exec('UPDATE products SET stock = stock + ? WHERE id = ?', [$delta, $productId]);
    }

    record_stock_movement($productId, $type, $delta, $reason, null, $adminId);

    return (int)db_value('SELECT stock FROM products WHERE id = ?', [$productId]);
}

// ------------------------------------------------------------
// Reporting
// ------------------------------------------------------------

/** Products at or below their reorder level, worst first. */
function low_stock_products(int $limit = 50): array
{
    $threshold = reorder_level_ready() ? 'p.reorder_level' : (string)STOCK_DEFAULT_REORDER_LEVEL;

    return db_all(
        "SELECT p.*, c.name AS category_name
           FROM products p
           LEFT JOIN categories c ON c.id = p.category_id
          WHERE p.status = 'active' AND p.stock <= $threshold
          ORDER BY p.stock ASC, p.name ASC
          LIMIT " . max(1, min(200, $limit))
    );
}

/** Counts for the stock dashboard tiles. */
function stock_overview(): array
{
    $threshold = reorder_level_ready() ? 'reorder_level' : (string)STOCK_DEFAULT_REORDER_LEVEL;

    $row = db_one(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END)                        AS out_of_stock,
            SUM(CASE WHEN stock > 0 AND stock <= $threshold THEN 1 ELSE 0 END) AS low_stock,
            COALESCE(SUM(stock), 0)                                            AS total_units,
            COALESCE(SUM(stock * price), 0)                                    AS stock_value
         FROM products
         WHERE status = 'active'"
    );

    return [
        'total'        => (int)$row['total'],
        'out_of_stock' => (int)$row['out_of_stock'],
        'low_stock'    => (int)$row['low_stock'],
        'total_units'  => (int)$row['total_units'],
        'stock_value'  => (float)$row['stock_value'],
    ];
}

/**
 * Products whose stock column disagrees with the sum of their movements.
 *
 * This is the safety net for keeping a cached column. In a healthy
 * system it returns nothing; anything it finds is a real bug worth
 * investigating rather than silently correcting.
 */
function stock_reconciliation(): array
{
    if (!stock_module_ready()) {
        return [];
    }

    return db_all(
        'SELECT p.id, p.name, p.stock,
                COALESCE(SUM(m.quantity), 0) AS ledger_total
           FROM products p
           LEFT JOIN stock_movements m ON m.product_id = p.id
          GROUP BY p.id, p.name, p.stock
         HAVING p.stock <> COALESCE(SUM(m.quantity), 0)
          ORDER BY p.name ASC'
    );
}
