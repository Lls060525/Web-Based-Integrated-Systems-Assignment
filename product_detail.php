<?php
// ============================================================
// product_detail.php - single product page
// ============================================================

require_once __DIR__ . '/lib/init.php';
require_once __DIR__ . '/includes/product_card.php';
require_once __DIR__ . '/includes/review_parts.php';
require_once __DIR__ . '/includes/product_gallery.php';

$productId = get_int('id');

if ($productId === null) {
    flash_error('That product could not be found.');
    redirect('/products.php');
}

$product = db_one(
    "SELECT p.*, c.name AS category_name
       FROM products p
       LEFT JOIN categories c ON c.id = p.category_id
      WHERE p.id = ? AND p.status = 'active'",
    [$productId]
);

if (!$product) {
    flash_error('That product is no longer available.');
    redirect('/products.php');
}

// Four more items from the same category.
$related = db_all(
    "SELECT p.*, c.name AS category_name
       FROM products p
       LEFT JOIN categories c ON c.id = p.category_id
      WHERE p.status = 'active' AND p.category_id = ? AND p.id <> ?
      ORDER BY RAND() LIMIT 4",
    [$product['category_id'], $product['id']]
);

$title = $product['name'] . ' - ' . APP_NAME;
$stock = (int)$product['stock'];

// ---------- Reviews ----------
$reviewSort   = get('review_sort', 'recent');
$reviewFilter = get_int('stars');

$summary     = rating_summary($productId);
$reviews     = product_reviews($productId, $reviewSort, $reviewFilter);
$myReview    = is_member() ? find_user_review(current_user_id(), $productId) : null;
$mayReview   = is_member() && can_review(current_user_id(), $productId);

$sortOptions = [
    'recent'  => 'Most recent',
    'highest' => 'Highest rated',
    'lowest'  => 'Lowest rated',
];

include __DIR__ . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="/">Home</a> &gt;
    <a href="/products.php">Products</a> &gt;
    <span><?= e($product['name']) ?></span>
</nav>

<div class="product-detail-layout">

    <div class="product-detail-img-wrapper">
        <?php render_product_gallery($product); ?>
    </div>

    <div class="product-detail-info">
        <h1 class="product-detail-title"><?= e($product['name']) ?></h1>

        <?php if ($product['category_name']): ?>
            <a href="/products.php?category=<?= (int)$product['category_id'] ?>" class="badge">
                <?= e($product['category_name']) ?>
            </a>
        <?php endif; ?>

        <?php if (review_module_ready() && $summary['count'] > 0): ?>
            <a href="#reviews" class="rating-link">
                <?php render_stars((float)$summary['average'], 'stars-sm'); ?>
                <span class="rating-count">
                    <?= number_format($summary['average'], 1) ?>
                    &middot; <?= $summary['count'] ?> review<?= $summary['count'] === 1 ? '' : 's' ?>
                </span>
            </a>
        <?php endif; ?>

        <div class="price price-lg" id="productPrice"
             data-base="<?= e(number_format((float)$product['price'], 2, '.', '')) ?>">
            <?= e(money($product['price'])) ?>
        </div>

        <div class="product-description card">
            <h3>Product Description</h3>
            <p><?= nl2br(e($product['description'])) ?></p>
        </div>

        <div class="stock-line">
            <span class="muted">Availability:</span>
            <strong class="<?= e(stock_class($product)) ?>"><?= e(stock_message($product)) ?></strong>
        </div>

        <?php
            $choices = product_selectable_specs((int)$product['id']);

            // Map a photo id to its slide index, so choosing a colour can
            // drive the existing gallery instead of a second image widget.
            $slideOf = [];
            foreach (gallery_images($product) as $i => $img) {
                if (!empty($img['id'])) {
                    $slideOf[(int)$img['id']] = $i;
                }
            }
        ?>

        <?php if ($choices !== []): ?>
            <div class="configurator" id="configurator">
                <?php foreach ($choices as $step => $choice): ?>
                    <?php $isSwatch = !empty($choice['is_swatch']); ?>

                    <div class="config-step">
                        <div class="config-head">
                            <span class="config-step-no"><?= $step + 1 ?>.</span>
                            <span class="config-title">
                                Choose your <?= e(strtolower($choice['name'])) ?>
                            </span>
                            <span class="config-chosen" data-for="<?= (int)$choice['id'] ?>"></span>
                        </div>

                        <div class="config-options <?= $isSwatch ? 'is-swatches' : '' ?>">
                            <?php
                                /* Which option starts selected.
                                 *
                                 * The admin's chosen default wins, but only if
                                 * it is still available -- otherwise the page
                                 * would open on a sold-out choice and the Add
                                 * button would fail on the first click. The
                                 * first available option is the fallback.
                                 */
                                $defaultId = null;

                                foreach ($choice['choices'] as $o) {
                                    if (spec_style_ready() && (int)($o['is_available'] ?? 1) === 0) {
                                        continue;
                                    }

                                    if ($defaultId === null) {
                                        $defaultId = (int)$o['id'];
                                    }

                                    if ((int)$o['is_default'] === 1) {
                                        $defaultId = (int)$o['id'];
                                        break;
                                    }
                                }
                            ?>

                            <?php foreach ($choice['choices'] as $option): ?>
                                <?php
                                    $available = !spec_style_ready() || (int)($option['is_available'] ?? 1) === 1;
                                    $delta     = (float)$option['price_delta'];
                                    $id        = 'opt' . (int)$option['id'];
                                    $photoId   = (int)($option['photo_id'] ?? 0);
                                    $slide     = $slideOf[$photoId] ?? null;
                                ?>

                                <input type="radio"
                                       class="js-spec-option config-input"
                                       id="<?= e($id) ?>"
                                       name="spec_option_<?= (int)$choice['id'] ?>"
                                       data-attribute="<?= (int)$choice['id'] ?>"
                                       data-label="<?= e($option['value_text']) ?>"
                                       data-delta="<?= e(number_format($delta, 2, '.', '')) ?>"
                                       <?= $slide !== null ? 'data-slide="' . (int)$slide . '"' : '' ?>
                                       value="<?= e($option['value_text']) ?>"
                                       <?= $available ? '' : 'disabled' ?>
                                       <?= ($available && (int)$option['id'] === $defaultId) ? 'checked' : '' ?>>

                                <?php if ($isSwatch): ?>
                                    <label for="<?= e($id) ?>"
                                           class="config-swatch <?= $available ? '' : 'is-gone' ?>"
                                           title="<?= e($option['value_text']) ?><?= $available ? '' : ' (sold out)' ?>">
                                        <span class="swatch-dot"
                                              style="background: <?= e($option['swatch_hex'] ?: '#cccccc') ?>"></span>
                                        <span class="swatch-name"><?= e($option['value_text']) ?></span>
                                    </label>

                                <?php else: ?>
                                    <label for="<?= e($id) ?>"
                                           class="config-tile <?= $available ? '' : 'is-gone' ?>">
                                        <span class="tile-name"><?= e($option['value_text']) ?></span>

                                        <span class="tile-price">
                                            <?php if (!$available): ?>
                                                Sold out
                                            <?php elseif ($delta > 0): ?>
                                                +<?= e(money($delta)) ?>
                                            <?php elseif ($delta < 0): ?>
                                                <?= e(money($delta)) ?>
                                            <?php else: ?>
                                                Included
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="config-summary" id="configSummary">
                    <div class="config-summary-head">Your <?= e($product['name']) ?></div>

                    <div class="config-summary-lines" id="configSummaryLines"></div>

                    <div class="config-summary-total">
                        <span>Total</span>
                        <strong id="configTotal"><?= e(money($product['price'])) ?></strong>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="product-detail-actions">
            <?php if ($stock > 0): ?>
                <button type="button" class="add-to-cart btn-primary btn-lg btn-block"
                        data-id="<?= (int)$product['id'] ?>">Add to Cart</button>
            <?php else: ?>
                <button type="button" class="btn-outline btn-lg btn-block" disabled>Out of Stock</button>
            <?php endif; ?>

            <?php if (is_member()): ?>
                <?php $saved = is_wishlisted((int)$product['id']); ?>
                <button type="button"
                        class="btn-outline btn-lg btn-block mt-2 js-wishlist wishlist-inline <?= $saved ? 'is-saved' : '' ?>"
                        data-id="<?= (int)$product['id'] ?>"
                        aria-pressed="<?= $saved ? 'true' : 'false' ?>">
                    <i class="<?= $saved ? 'fas' : 'far' ?> fa-heart"></i>
                    <span class="wishlist-label"><?= $saved ? 'Saved to Wishlist' : 'Save to Wishlist' ?></span>
                </button>
            <?php else: ?>
                <a href="/auth/login.php" class="btn-outline btn-lg btn-block mt-2">
                    <i class="far fa-heart"></i> Log in to save this
                </a>
            <?php endif; ?>

            <?php if (qr_module_ready() && $product['status'] === 'active'): ?>
                <details class="qr-share">
                    <summary><i class="fas fa-qrcode"></i> Share this product</summary>

                    <div class="qr-share-body">
                        <img class="qr-image"
                             src="/api/qr_image.php?type=product&amp;id=<?= (int)$product['id'] ?>&amp;size=180"
                             alt="QR code linking to <?= e($product['name']) ?>"
                             width="150" height="150" loading="lazy">

                        <p class="muted small-note">
                            Point a phone camera at this to open the product page.
                            Handy for a printed shelf label.
                        </p>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (review_module_ready()): ?>
<?php $specs = product_specs((int)$product['id']); ?>

<?php if ($specs !== []): ?>
    <section class="specs-section" id="specs">
        <h2 class="page-title">Specifications</h2>

        <div class="card card-padded">
            <table class="spec-table">
                <tbody>
                    <?php foreach ($specs as $spec): ?>
                        <tr>
                            <th scope="row"><?= e($spec['name']) ?></th>
                            <td><?= e(spec_display($spec)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php $rivals = spec_comparable_products($product); ?>

            <?php if ($rivals !== []): ?>
                <form action="/compare.php" method="GET" class="spec-compare-form">
                    <label for="compareWith" class="spec-compare-label">Compare with</label>

                    <select id="compareWith" name="with" class="form-control">
                        <?php foreach ($rivals as $rival): ?>
                            <option value="<?= (int)$rival['id'] ?>">
                                <?= e($rival['name']) ?> &mdash; <?= e(money($rival['price'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input type="hidden" name="base" value="<?= (int)$product['id'] ?>">
                    <?php html_submit('Compare', ['class' => 'btn-outline']); ?>
                </form>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<section class="reviews-section" id="reviews">
    <h2 class="page-title">Ratings &amp; Reviews</h2>

    <div class="reviews-layout">

        <aside class="reviews-summary card card-padded">
            <?php render_rating_summary($summary); ?>

            <?php if (!is_logged_in()): ?>
                <a href="/auth/login.php" class="btn-outline btn-block mt-4">Log in to review</a>

            <?php elseif ($myReview !== null): ?>
                <div class="my-review-note">
                    <p class="muted small-note">You reviewed this product.</p>
                    <?php render_stars((float)$myReview['rating'], 'stars-sm'); ?>
                    <?php if ($myReview['status'] === 'hidden'): ?>
                        <p class="err small-note">
                            Your review is currently hidden by our team.
                        </p>
                    <?php endif; ?>
                    <a href="/review_form.php?product=<?= (int)$productId ?>"
                       class="btn-outline btn-block mt-2">Edit My Review</a>
                </div>

            <?php elseif ($mayReview): ?>
                <a href="/review_form.php?product=<?= (int)$productId ?>"
                   class="btn-primary btn-block mt-4">Write a Review</a>

            <?php else: ?>
                <p class="muted small-note mt-4"><?= e(review_blocked_reason()) ?></p>
            <?php endif; ?>
        </aside>

        <div class="reviews-list">
            <?php if ($summary['count'] > 0): ?>
                <form action="/product_detail.php" method="GET" class="sort-bar">
                    <?php html_hidden('id', $productId); ?>
                    <?php html_hidden('stars', $reviewFilter ?? ''); ?>

                    <label for="review_sort">Sort by</label>
                    <?php html_select('review_sort', $sortOptions, $reviewSort,
                                      ['class' => 'form-control js-auto-submit']); ?>

                    <?php if ($reviewFilter !== null): ?>
                        <a href="/product_detail.php?id=<?= (int)$productId ?>" class="btn-outline btn-sm">
                            Clear <?= (int)$reviewFilter ?>-star filter
                        </a>
                    <?php endif; ?>
                    <noscript><?php html_submit('Go', ['class' => 'btn-outline btn-sm']); ?></noscript>
                </form>
            <?php endif; ?>

            <?php if (count($reviews) === 0): ?>
                <div class="card empty-state-box">
                    <h3 class="empty-state-title">
                        <?= $summary['count'] === 0
                            ? 'No reviews yet.'
                            : 'No reviews match that filter.' ?>
                    </h3>
                    <p>
                        <?= $summary['count'] === 0
                            ? 'Be the first to share your experience with this product.'
                            : 'Try a different star rating.' ?>
                    </p>
                </div>
            <?php else: ?>
                <?php foreach ($reviews as $r): ?>
                    <?php render_review($r); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (count($related) > 0): ?>
    <section class="related-products">
        <h2 class="page-title">You May Also Like</h2>
        <?php render_product_grid($related); ?>
    </section>
<?php endif; ?>

<?php if ($choices !== [] && $stock > 0): ?>
    <?php // Only for a configurable product: on a plain one the button in
          // the panel above is never far away, and a bar that follows the
          // page for no reason is just clutter. ?>
    <div class="config-bar" id="configBar" hidden>
        <div class="config-bar-inner">
            <div class="config-bar-text">
                <strong><?= e($product['name']) ?></strong>
                <span class="config-bar-spec" id="configBarSpec"></span>
            </div>

            <div class="config-bar-buy">
                <span class="config-bar-total" id="configBarTotal"><?= e(money($product['price'])) ?></span>
                <button type="button" class="add-to-cart btn-primary"
                        data-id="<?= (int)$product['id'] ?>">Add to Cart</button>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
