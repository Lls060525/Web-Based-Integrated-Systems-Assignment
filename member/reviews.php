<?php
// ============================================================
// member/reviews.php - My Reviews (Member)
// What you have written, and what you can still write.
// ============================================================

require_once __DIR__ . '/../lib/init.php';
require_once __DIR__ . '/../includes/review_parts.php';

require_member();

$title  = 'My Reviews - ' . APP_NAME;
$userId = current_user_id();

if (!review_module_ready()) {
    flash_error('Reviews are not available yet: run database/migration_14_reviews.sql.');
    redirect('/member/profile.php');
}

$mine    = user_reviews($userId);
$pending = products_awaiting_review($userId);

include __DIR__ . '/../includes/header.php';
?>

<nav class="breadcrumb">
    <a href="/member/profile.php">My Profile</a> &gt; <span>My Reviews</span>
</nav>

<div class="page-title-row">
    <h2 class="page-title">My Reviews</h2>
</div>

<?php if (count($pending) > 0): ?>
    <div class="card card-padded">
        <h3>Waiting for your review</h3>
        <p class="muted small-note">
            You have received these products, so you can review them.
        </p>

        <div class="pending-review-grid">
            <?php foreach ($pending as $p): ?>
                <a href="/review_form.php?product=<?= (int)$p['id'] ?>" class="pending-review-item">
                    <img src="<?= e(product_image($p['image'])) ?>" alt="" class="table-thumb">
                    <span class="pending-review-name"><?= e($p['name']) ?></span>
                    <span class="btn-outline btn-sm">Review</span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="section-margin-top">
    <?php if (count($mine) === 0): ?>
        <div class="card empty-state-box">
            <h3 class="empty-state-title">You haven't written any reviews yet.</h3>
            <p>Once an order is shipped you can review what you bought.</p>
            <a href="/orders.php" class="btn-primary shop-now-btn">View My Orders</a>
        </div>
    <?php else: ?>
        <?php foreach ($mine as $r): ?>
            <div class="card card-padded my-review-card">
                <div class="my-review-head">
                    <a href="/product_detail.php?id=<?= (int)$r['product_id'] ?>" class="my-review-product">
                        <img src="<?= e(product_image($r['image'])) ?>" alt="" class="table-thumb">
                        <strong><?= e($r['product_name']) ?></strong>
                    </a>

                    <div class="row-actions">
                        <?php if ($r['status'] === 'hidden'): ?>
                            <span class="badge badge-danger">Hidden by our team</span>
                        <?php endif; ?>
                        <a href="/review_form.php?product=<?= (int)$r['product_id'] ?>"
                           class="btn-outline btn-sm">Edit</a>
                    </div>
                </div>

                <div class="review-meta">
                    <?php render_stars((float)$r['rating'], 'stars-sm'); ?>
                    <span class="muted small-note"><?= e(fmt_date($r['created_at'])) ?></span>
                    <?php if (!empty($r['updated_at'])): ?>
                        <span class="muted small-note">(edited)</span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($r['title'])): ?>
                    <h4 class="review-title"><?= e($r['title']) ?></h4>
                <?php endif; ?>

                <p class="review-text"><?= nl2br(e($r['body'])) ?></p>

                <?php if ($r['status'] === 'hidden' && !empty($r['admin_note'])): ?>
                    <p class="muted small-note">
                        <strong>Note from our team:</strong> <?= e($r['admin_note']) ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
