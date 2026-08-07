<?php
// ============================================================
// lib/review.php
// Product ratings and reviews.
//
// The defining rule of this module is VERIFIED PURCHASE: a member
// can only review a product they actually received. That check is
// a JOIN against their own orders, so it cannot be faked from the
// browser and does not rely on any flag we set ourselves.
//
// There is deliberately no cached rating_avg column on products.
// Reviews are written rarely and read as an aggregate, so AVG() is
// both fast enough and always correct. (Stock keeps a cached column
// because its concurrent decrement needs an atomic conditional
// update; ratings have no such requirement.)
// ============================================================

/** True once the reviews table exists (see the migration). */
function review_module_ready(): bool
{
    return db_table_exists('reviews');
}

// ------------------------------------------------------------
// Eligibility
// ------------------------------------------------------------

/**
 * The order that entitles this member to review this product,
 * or null when they have not received it.
 *
 * Ownership and delivery are both in the WHERE clause: there is no
 * separate flag that could be set incorrectly.
 */
function purchase_for_review(int $userId, int $productId): ?array
{
    if (!review_module_ready()) {
        return null;
    }

    $statuses     = REVIEW_ELIGIBLE_ORDER_STATUSES;
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));

    $params = array_merge([$userId, $productId], $statuses);

    return db_one(
        "SELECT o.id, o.created_at
           FROM orders o
           JOIN order_items oi ON oi.order_id = o.id
          WHERE o.user_id = ?
            AND oi.product_id = ?
            AND o.status IN ($placeholders)
          ORDER BY o.created_at DESC
          LIMIT 1",
        $params
    ) ?: null;
}

/** True when this member may write or edit a review for this product. */
function can_review(int $userId, int $productId): bool
{
    return purchase_for_review($userId, $productId) !== null;
}

/** Why the review form is not being offered, phrased for the member. */
function review_blocked_reason(): string
{
    return 'Only customers who have received this product can review it. '
         . 'Reviews appear once your order has been shipped.';
}

// ------------------------------------------------------------
// Reading
// ------------------------------------------------------------

/** One member's review of one product, whatever its status. */
function find_user_review(int $userId, int $productId): ?array
{
    if (!review_module_ready()) {
        return null;
    }

    return db_one('SELECT * FROM reviews WHERE user_id = ? AND product_id = ?', [$userId, $productId]) ?: null;
}

/** A single review by id. */
function find_review(int $id): ?array
{
    if (!review_module_ready()) {
        return null;
    }

    return db_one('SELECT * FROM reviews WHERE id = ?', [$id]) ?: null;
}

/**
 * Published reviews for a product.
 * $sort: 'recent' | 'highest' | 'lowest' | 'helpful'
 */
function product_reviews(int $productId, string $sort = 'recent', ?int $filterRating = null): array
{
    if (!review_module_ready()) {
        return [];
    }

    $order = match ($sort) {
        'highest' => 'r.rating DESC, r.id DESC',
        'lowest'  => 'r.rating ASC, r.id DESC',
        default   => 'r.id DESC',
    };

    $sql = "SELECT r.*, u.name AS author_name, u.profile_photo
              FROM reviews r
              JOIN users u ON u.id = r.user_id
             WHERE r.product_id = ? AND r.status = 'published'";
    $params = [$productId];

    if ($filterRating !== null && $filterRating >= 1 && $filterRating <= 5) {
        $sql     .= ' AND r.rating = ?';
        $params[] = $filterRating;
    }

    $sql .= ' ORDER BY ' . $order;

    return db_all($sql, $params);
}

/**
 * Aggregate rating for a product.
 *
 * @return array{average: float, count: int, distribution: array<int,int>}
 */
function rating_summary(int $productId): array
{
    $empty = ['average' => 0.0, 'count' => 0, 'distribution' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0]];

    if (!review_module_ready()) {
        return $empty;
    }

    $row = db_one(
        "SELECT COUNT(*) AS total, COALESCE(AVG(rating), 0) AS average
           FROM reviews
          WHERE product_id = ? AND status = 'published'",
        [$productId]
    );

    if ((int)$row['total'] === 0) {
        return $empty;
    }

    $distribution = $empty['distribution'];

    $rows = db_all(
        "SELECT rating, COUNT(*) AS total
           FROM reviews
          WHERE product_id = ? AND status = 'published'
          GROUP BY rating",
        [$productId]
    );

    foreach ($rows as $r) {
        $distribution[(int)$r['rating']] = (int)$r['total'];
    }

    return [
        'average'      => round((float)$row['average'], 1),
        'count'        => (int)$row['total'],
        'distribution' => $distribution,
    ];
}

/** Every review a member has written. */
function user_reviews(int $userId): array
{
    if (!review_module_ready()) {
        return [];
    }

    return db_all(
        'SELECT r.*, p.name AS product_name, p.image, p.status AS product_status
           FROM reviews r
           JOIN products p ON p.id = r.product_id
          WHERE r.user_id = ?
          ORDER BY r.id DESC',
        [$userId]
    );
}

/**
 * Products from a member's completed orders that they have not
 * reviewed yet, so the site can prompt them.
 */
function products_awaiting_review(int $userId): array
{
    if (!review_module_ready()) {
        return [];
    }

    $statuses     = REVIEW_ELIGIBLE_ORDER_STATUSES;
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));

    $params = array_merge([$userId], $statuses, [$userId]);

    return db_all(
        "SELECT DISTINCT p.id, p.name, p.image, o.id AS order_id
           FROM orders o
           JOIN order_items oi ON oi.order_id = o.id
           JOIN products p ON p.id = oi.product_id
          WHERE o.user_id = ?
            AND o.status IN ($placeholders)
            AND p.status = 'active'
            AND NOT EXISTS (
                  SELECT 1 FROM reviews r
                   WHERE r.product_id = p.id AND r.user_id = ?
                )
          ORDER BY o.created_at DESC",
        $params
    );
}

// ------------------------------------------------------------
// Writing
// ------------------------------------------------------------

/**
 * Validate a submitted review.
 * Errors go to add_err() against the field names.
 *
 * @return array{rating: int, title: string, body: string}
 */
function validate_review_input(): array
{
    $rating = post_int('rating') ?? 0;
    $title  = post('title');
    $body   = post('body');

    if ($rating < 1 || $rating > 5) {
        add_err('rating', 'Please choose a rating from 1 to 5 stars.');
    }

    v_max('title', $title, REVIEW_TITLE_MAX, 'Title');

    if (v_required('body', $body, 'Review')) {
        v_min('body', $body, REVIEW_BODY_MIN, 'Review');
        v_max('body', $body, REVIEW_BODY_MAX, 'Review');
    }

    return ['rating' => $rating, 'title' => $title, 'body' => $body];
}

/**
 * Create or update a member's review.
 *
 * Eligibility is re-checked here, not just in the page, so the write
 * path is safe on its own.
 *
 * @throws RuntimeException when the member has not bought the product
 */
function save_review(int $userId, int $productId, array $data): void
{
    $purchase = purchase_for_review($userId, $productId);

    if ($purchase === null) {
        throw new RuntimeException(review_blocked_reason());
    }

    $existing = find_user_review($userId, $productId);

    if ($existing) {
        db_exec(
            'UPDATE reviews
                SET rating = ?, title = ?, body = ?, updated_at = NOW()
              WHERE id = ? AND user_id = ?',
            [
                $data['rating'],
                ($data['title'] === '' ? null : $data['title']),
                $data['body'],
                $existing['id'],
                $userId,
            ]
        );
    } else {
        db_exec(
            'INSERT INTO reviews (product_id, user_id, order_id, rating, title, body)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $productId,
                $userId,
                $purchase['id'],
                $data['rating'],
                ($data['title'] === '' ? null : $data['title']),
                $data['body'],
            ]
        );
    }
}

/** A member deleting their own review. */
function delete_own_review(int $reviewId, int $userId): void
{
    db_exec('DELETE FROM reviews WHERE id = ? AND user_id = ?', [$reviewId, $userId]);
}

// ------------------------------------------------------------
// Moderation
// ------------------------------------------------------------

/** Admin listing with the product and author joined in. */
function all_reviews(string $filter = '', string $search = ''): array
{
    if (!review_module_ready()) {
        return [];
    }

    $sql = "SELECT r.*, p.name AS product_name, p.image, u.name AS author_name, u.email AS author_email
              FROM reviews r
              JOIN products p ON p.id = r.product_id
              JOIN users u ON u.id = r.user_id
             WHERE 1 = 1";
    $params = [];

    if (in_array($filter, ['published', 'hidden'], true)) {
        $sql     .= ' AND r.status = ?';
        $params[] = $filter;
    } elseif ($filter === 'low') {
        $sql .= ' AND r.rating <= 2';
    }

    if ($search !== '') {
        $sql     .= ' AND (p.name LIKE ? OR u.name LIKE ? OR r.body LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    $sql .= ' ORDER BY r.id DESC';

    return db_all($sql, $params);
}

/** Publish or hide a review, with an optional internal note. */
function set_review_status(int $reviewId, string $status, string $note = ''): void
{
    if (!in_array($status, ['published', 'hidden'], true)) {
        throw new RuntimeException('Invalid review status.');
    }

    db_exec(
        'UPDATE reviews SET status = ?, admin_note = ?, updated_at = NOW() WHERE id = ?',
        [$status, ($note === '' ? null : $note), $reviewId]
    );
}

/** Site-wide review statistics for the admin page. */
function review_overview(): array
{
    if (!review_module_ready()) {
        return ['total' => 0, 'published' => 0, 'hidden' => 0, 'average' => 0.0];
    }

    $row = db_one(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published,
            SUM(CASE WHEN status = 'hidden'    THEN 1 ELSE 0 END) AS hidden,
            COALESCE(AVG(CASE WHEN status = 'published' THEN rating END), 0) AS average
         FROM reviews"
    );

    return [
        'total'     => (int)$row['total'],
        'published' => (int)$row['published'],
        'hidden'    => (int)$row['hidden'],
        'average'   => round((float)$row['average'], 1),
    ];
}
