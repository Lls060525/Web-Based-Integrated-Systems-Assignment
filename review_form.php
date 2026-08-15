<?php
// ============================================================
// review_form.php - Write or edit a product review (Member)
//
// Eligibility is checked on GET and again on POST, and once more
// inside save_review(), so a stale tab or a hand-made request
// cannot produce a review for something that was never bought.
// ============================================================

require_once __DIR__ . '/lib/init.php';
require_once __DIR__ . '/includes/review_parts.php';

require_member();

$userId    = current_user_id();
$productId = get_int('product');

if (!review_module_ready()) {
    flash_error('Reviews are not available yet: run database/migration_14_reviews.sql.');
    redirect('/products.php');
}

if ($productId === null) {
    flash_error('Invalid product.');
    redirect('/products.php');
}

$product = db_one('SELECT * FROM products WHERE id = ?', [$productId]);

if (!$product) {
    flash_error('Product not found.');
    redirect('/products.php');
}

$purchase = purchase_for_review($userId, $productId);

if ($purchase === null) {
    flash_error(review_blocked_reason());
    redirect('/product_detail.php?id=' . $productId);
}

$existing = find_user_review($userId, $productId);
$isEdit   = $existing !== null;

if (is_post()) {
    csrf_check();

    // ---------- Delete ----------
    if (post('action') === 'delete' && $isEdit) {
        delete_own_review((int)$existing['id'], $userId);
        flash_success('Your review has been removed.');
        redirect('/product_detail.php?id=' . $productId);
    }

    // ---------- Save ----------
    $data = validate_review_input();

    if (no_err()) {
        try {
            save_review($userId, $productId, $data);
            flash_success($isEdit ? 'Your review has been updated.' : 'Thank you for your review.');
            redirect('/product_detail.php?id=' . $productId . '#reviews');

        } catch (\RuntimeException $ex) {
            add_err('rating', $ex->getMessage());
        }
    }
    // Validation failed. Answer with a redirect rather than a page, so
    // the browser's history entry is a GET and F5 cannot resubmit.
    // The errors and what was typed are carried across the redirect.
    redirect_back();
}

$title = ($isEdit ? 'Edit' : 'Write') . ' a Review - ' . APP_NAME;

include __DIR__ . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="/products.php">Products</a> &gt;
    <a href="/product_detail.php?id=<?= (int)$productId ?>"><?= e($product['name']) ?></a> &gt;
    <span><?= $isEdit ? 'Edit Review' : 'Write a Review' ?></span>
</nav>

<div class="form-page">
    <div class="page-title-row">
        <h2 class="page-title"><?= $isEdit ? 'Edit Your Review' : 'Write a Review' ?></h2>
    </div>

    <div class="card card-padded review-product-strip">
        <img src="<?= e(product_image($product['image'])) ?>" alt="" class="table-thumb">
        <div>
            <strong><?= e($product['name']) ?></strong>
            <div class="muted small-note">
                <i class="fas fa-circle-check stock-ok"></i>
                Verified purchase &mdash; order #<?= (int)$purchase['id'] ?>,
                <?= e(fmt_date($purchase['created_at'])) ?>
            </div>
        </div>
    </div>

    <div class="card card-padded mt-4">

        <?php err_summary(); ?>

        <form action="/review_form.php?product=<?= (int)$productId ?>" method="POST" class="form-standard">
            <?php csrf_field(); ?>

            <?php field('rating', 'Your Rating', function () use ($existing) {
                render_star_input((int)($existing['rating'] ?? 0));
            }, true); ?>

            <?php field('title', 'Title (optional)', function () use ($existing) {
                html_text('title', $existing['title'] ?? '', [
                    'maxlength'   => REVIEW_TITLE_MAX,
                    'placeholder' => 'Sum up your experience in a few words',
                ]);
            }); ?>

            <?php field('body', 'Your Review', function () use ($existing) {
                html_textarea('body', $existing['body'] ?? '', [
                    'rows'        => 6,
                    'maxlength'   => REVIEW_BODY_MAX,
                    'required'    => true,
                    'id'          => 'reviewBody',
                    'placeholder' => 'What did you like or dislike? How did you use it?',
                ]);
                echo '<small class="form-hint">'
                   . 'At least ' . REVIEW_BODY_MIN . ' characters. '
                   . '<span id="bodyCount">0</span> / ' . REVIEW_BODY_MAX
                   . '</small>';
            }, true); ?>

            <div class="form-actions">
                <a href="/product_detail.php?id=<?= (int)$productId ?>" class="btn-outline">Cancel</a>
                <?php html_submit($isEdit ? 'Update Review' : 'Publish Review'); ?>
            </div>
        </form>

        <?php if ($isEdit): ?>
            <form action="/review_form.php?product=<?= (int)$productId ?>" method="POST"
                  class="mt-4 review-delete-form"
                  data-confirm="Delete your review of <?= e($product['name']) ?>?">
                <?php csrf_field(); ?>
                <?php html_hidden('action', 'delete'); ?>
                <?php html_submit('Delete My Review', ['class' => 'btn-outline btn-sm btn-danger']); ?>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
