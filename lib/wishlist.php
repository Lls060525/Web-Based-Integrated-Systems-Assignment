<?php
// ============================================================
// lib/wishlist.php
// Favorites / wishlist for members.
// ============================================================

/** True once the wishlist table exists (see the migration). */
function wishlist_module_ready(): bool
{
    return db_table_exists('wishlist');
}

/**
 * Product ids the current member has favourited.
 *
 * Loaded once per request and cached, so rendering a grid of 12
 * product cards costs one query rather than twelve.
 */
function wishlist_product_ids(): array
{
    static $ids = null;

    if ($ids === null) {
        $ids = [];

        if (is_member() && wishlist_module_ready()) {
            $rows = db_all('SELECT product_id FROM wishlist WHERE user_id = ?', [current_user_id()]);
            foreach ($rows as $row) {
                $ids[(int)$row['product_id']] = true;
            }
        }
    }

    return $ids;
}

/** True when the current member has favourited this product. */
function is_wishlisted(int $productId): bool
{
    return isset(wishlist_product_ids()[$productId]);
}

/** How many products a member has saved. */
function wishlist_count(?int $userId = null): int
{
    if (!wishlist_module_ready()) {
        return 0;
    }

    $userId ??= current_user_id();

    if ($userId === null) {
        return 0;
    }

    return (int)db_value('SELECT COUNT(*) FROM wishlist WHERE user_id = ?', [$userId]);
}

/** The member's saved products, newest first. */
function wishlist_items(int $userId): array
{
    if (!wishlist_module_ready()) {
        return [];
    }

    return db_all(
        "SELECT w.id AS wishlist_id, w.added_at,
                p.*, c.name AS category_name
           FROM wishlist w
           JOIN products p ON p.id = w.product_id
           LEFT JOIN categories c ON c.id = p.category_id
          WHERE w.user_id = ?
          ORDER BY w.added_at DESC",
        [$userId]
    );
}

/**
 * Add or remove a favourite, whichever the current state is not.
 *
 * The INSERT relies on the UNIQUE(user_id, product_id) key rather
 * than a check-then-insert, so two rapid clicks cannot create a
 * duplicate row.
 *
 * @return array{added: bool, count: int}
 */
function toggle_wishlist(int $userId, int $productId): array
{
    $removed = db_exec(
        'DELETE FROM wishlist WHERE user_id = ? AND product_id = ?',
        [$userId, $productId]
    );

    if ($removed === 0) {
        db_exec(
            'INSERT IGNORE INTO wishlist (user_id, product_id) VALUES (?, ?)',
            [$userId, $productId]
        );
    }

    return [
        'added' => $removed === 0,
        'count' => wishlist_count($userId),
    ];
}

/** Remove one product from a member's wishlist. */
function remove_from_wishlist(int $userId, int $productId): void
{
    db_exec('DELETE FROM wishlist WHERE user_id = ? AND product_id = ?', [$userId, $productId]);
}

/** Empty a member's wishlist. */
function clear_wishlist(int $userId): int
{
    return db_exec('DELETE FROM wishlist WHERE user_id = ?', [$userId]);
}
