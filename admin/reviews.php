<?php
// ============================================================
// admin/reviews.php - Review moderation (Admin)
//
// Reviews are hidden rather than deleted. A hidden review still
// belongs to the customer who wrote it, and hiding is reversible;
// deleting someone's honest opinion is not.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

require_permission('reviews.manage');
require_once __DIR__ . '/../includes/review_parts.php';

$title = 'Review Moderation - Admin';

if (!review_module_ready()) {
    flash_error('Reviews are not available yet: run database/migration_14_reviews.sql.');
    // Somewhere this role can actually open, not the dashboard --
    // otherwise a missing migration bounces them into a 403.
    redirect(admin_landing_url());
}

// ---------- Moderation ----------
if (is_post()) {
    csrf_check();

    $reviewId = post_int('id');
    $review   = $reviewId === null ? null : find_review($reviewId);

    if (!$review) {
        flash_error('Review not found.');

    } elseif (post('action') === 'set_status') {
        $status = post('status');
        $note   = post('admin_note');

        if (mb_strlen($note) > 200) {
            flash_error('The note must not exceed 200 characters.');
        } else {
            try {
                set_review_status($reviewId, $status, $note);
                flash_success('Review ' . ($status === 'hidden' ? 'hidden' : 'published') . '.');
            } catch (\RuntimeException $ex) {
                flash_error($ex->getMessage());
            }
        }

    } else {
        flash_error('Invalid request.');
    }

    redirect('/admin/reviews.php?' . http_build_query(array_filter([
        'filter' => get('filter'),
        'q'      => get('q'),
    ])));
}

$filter  = get('filter');
$q       = get('q');
$pager   = paginate(count_all_reviews($filter, $q), 20);
$reviews = all_reviews($filter, $q, pager_limit($pager));
$stats   = review_overview();

$filterOptions = [
    'published' => 'Published only',
    'hidden'    => 'Hidden only',
    'low'       => 'Low ratings (1-2 stars)',
];

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Review Moderation</h2>

        <div class="admin-header-actions">
            <form action="/admin/reviews.php" method="GET" class="filter-form-inline">
                <?php html_hidden('q', $q); ?>
                <?php html_select('filter', $filterOptions, $filter,
                                  ['class' => 'form-control form-control-sm js-auto-submit'],
                                  'All reviews'); ?>
                <noscript><?php html_submit('Filter', ['class' => 'btn-outline btn-sm']); ?></noscript>
            </form>

            <form action="/admin/reviews.php" method="GET" class="admin-search-form">
                <?php if ($filter !== '') { html_hidden('filter', $filter); } ?>
                <input type="text" name="q" value="<?= e($q) ?>"
                       placeholder="Search product, member or text..." class="admin-search-input">
                <?php html_submit('Search'); ?>
                <?php if ($q !== '' || $filter !== ''): ?>
                    <a href="/admin/reviews.php" class="btn-outline">Clear</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="stat-grid mt-4">
        <div class="card stat-tile">
            <span class="stat-icon"><i class="fas fa-comments"></i></span>
            <span class="stat-value"><?= $stats['total'] ?></span>
            <span class="stat-label">Total Reviews</span>
        </div>
        <div class="card stat-tile">
            <span class="stat-icon"><i class="fas fa-star"></i></span>
            <span class="stat-value"><?= $stats['average'] > 0 ? number_format($stats['average'], 1) : '-' ?></span>
            <span class="stat-label">Average Rating</span>
        </div>
        <a href="/admin/reviews.php?filter=hidden" class="card stat-tile">
            <span class="stat-icon"><i class="fas fa-eye-slash"></i></span>
            <span class="stat-value"><?= $stats['hidden'] ?></span>
            <span class="stat-label">Hidden</span>
        </a>
        <a href="/admin/reviews.php?filter=low" class="card stat-tile">
            <span class="stat-icon"><i class="fas fa-triangle-exclamation"></i></span>
            <span class="stat-value"><?= count_all_reviews('low') ?></span>
            <span class="stat-label">Low Ratings</span>
        </a>
    </div>

    <p class="muted small-note mt-2">
        Reviews are hidden rather than deleted. Hiding is reversible and the review
        still belongs to the customer who wrote it. A low rating on its own is not a
        reason to hide anything &mdash; only abuse or content that breaks the rules is.
    </p>

    <div class="section-margin-top">
        <?php if (count($reviews) === 0): ?>
            <div class="card empty-state-box">
                <h3 class="empty-state-title">No reviews match.</h3>
            </div>
        <?php else: ?>
            <?php foreach ($reviews as $r): ?>
                <div class="card card-padded moderation-card <?= $r['status'] === 'hidden' ? 'is-hidden-review' : '' ?>">

                    <div class="moderation-head">
                        <a href="/product_detail.php?id=<?= (int)$r['product_id'] ?>" class="my-review-product">
                            <img src="<?= e(product_image($r['image'])) ?>" alt="" class="table-thumb">
                            <strong><?= e($r['product_name']) ?></strong>
                        </a>

                        <span class="badge <?= $r['status'] === 'hidden' ? 'badge-danger' : 'badge-success' ?>">
                            <?= $r['status'] === 'hidden' ? 'Hidden' : 'Published' ?>
                        </span>
                    </div>

                    <div class="review-meta">
                        <?php render_stars((float)$r['rating'], 'stars-sm'); ?>
                        <strong><?= e($r['author_name']) ?></strong>
                        <span class="muted small-note"><?= e($r['author_email']) ?></span>
                        <span class="muted small-note"><?= e(fmt_datetime($r['created_at'])) ?></span>
                        <?php if (!empty($r['order_id'])): ?>
                            <?php /* Which order the review is about is worth knowing even
                                     to somebody who may not open it. */ ?>
                            <?php admin_link('/admin/order_detail.php?id=' . (int)$r['order_id'],
                                             'Order #' . (int)$r['order_id'],
                                             ['class' => 'small-note']); ?>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($r['title'])): ?>
                        <h4 class="review-title"><?= e($r['title']) ?></h4>
                    <?php endif; ?>

                    <p class="review-text"><?= nl2br(e($r['body'])) ?></p>

                    <?php if (!empty($r['admin_note'])): ?>
                        <p class="muted small-note">
                            <strong>Moderation note:</strong> <?= e($r['admin_note']) ?>
                        </p>
                    <?php endif; ?>

                    <form action="/admin/reviews.php?<?= e(http_build_query(array_filter(['filter' => $filter, 'q' => $q]))) ?>"
                          method="POST" class="moderation-form">
                        <?php csrf_field(); ?>
                        <?php html_hidden('action', 'set_status'); ?>
                        <?php html_hidden('id', $r['id']); ?>
                        <?php html_hidden('status', $r['status'] === 'hidden' ? 'published' : 'hidden'); ?>

                        <input type="text" name="admin_note" class="form-control form-control-sm"
                               maxlength="200" placeholder="Reason (optional, shown to the member)"
                               value="<?= e($r['admin_note'] ?? '') ?>">

                        <?php html_submit($r['status'] === 'hidden' ? 'Publish' : 'Hide', [
                            'class' => 'btn-outline btn-sm ' . ($r['status'] === 'hidden' ? 'btn-success' : 'btn-danger'),
                        ]); ?>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php render_pager($pager); ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
