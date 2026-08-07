<?php
// ============================================================
// includes/review_parts.php
// Reusable rating and review blocks, shared by the product page,
// the catalogue cards and the member's own review list.
// ============================================================

if (!function_exists('render_stars')) {

    /**
     * A read-only star display.
     * Half stars are used so 4.5 does not have to round away detail.
     */
    function render_stars(float $rating, string $size = ''): void
    {
        echo '<span class="stars ' . e($size) . '" title="' . e(number_format($rating, 1)) . ' out of 5">';

        for ($i = 1; $i <= 5; $i++) {
            if ($rating >= $i) {
                echo '<i class="fas fa-star"></i>';
            } elseif ($rating >= $i - 0.5) {
                echo '<i class="fas fa-star-half-stroke"></i>';
            } else {
                echo '<i class="far fa-star"></i>';
            }
        }

        echo '</span>';
    }

    /** Compact rating line for a product card. */
    function render_rating_inline(array $summary): void
    {
        if ($summary['count'] === 0) {
            echo '<span class="rating-inline muted small-note">No reviews yet</span>';
            return;
        }

        echo '<span class="rating-inline">';
        render_stars((float)$summary['average'], 'stars-sm');
        echo '<span class="rating-count">(' . (int)$summary['count'] . ')</span>';
        echo '</span>';
    }

    /** The big summary panel: average, total, and a bar per star level. */
    function render_rating_summary(array $summary): void
    {
        $count = (int)$summary['count'];
        ?>
        <div class="rating-summary">
            <div class="rating-score">
                <span class="rating-number"><?= $count > 0 ? number_format($summary['average'], 1) : '-' ?></span>
                <?php render_stars((float)$summary['average']); ?>
                <span class="muted small-note">
                    <?= $count ?> review<?= $count === 1 ? '' : 's' ?>
                </span>
            </div>

            <?php if ($count > 0): ?>
                <ul class="rating-bars">
                    <?php for ($star = 5; $star >= 1; $star--): ?>
                        <?php
                            $n       = (int)$summary['distribution'][$star];
                            $percent = $count > 0 ? round(($n / $count) * 100) : 0;
                        ?>
                        <li>
                            <span class="rating-bar-label"><?= $star ?> star</span>
                            <span class="rating-bar-track">
                                <span class="rating-bar-fill" style="width: <?= $percent ?>%"></span>
                            </span>
                            <span class="rating-bar-count"><?= $n ?></span>
                        </li>
                    <?php endfor; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    /** One review, as shown on the product page. */
    function render_review(array $r, bool $showProduct = false): void
    {
        ?>
        <article class="review-item">
            <div class="review-avatar">
                <img src="<?= e(avatar_image($r['profile_photo'] ?? null)) ?>" alt="">
            </div>

            <div class="review-body">
                <div class="review-head">
                    <strong class="review-author"><?= e($r['author_name'] ?? 'Member') ?></strong>
                    <span class="badge badge-success verified-badge">
                        <i class="fas fa-circle-check"></i> Verified purchase
                    </span>
                </div>

                <div class="review-meta">
                    <?php render_stars((float)$r['rating'], 'stars-sm'); ?>
                    <span class="muted small-note"><?= e(fmt_date($r['created_at'])) ?></span>
                    <?php if (!empty($r['updated_at'])): ?>
                        <span class="muted small-note">(edited)</span>
                    <?php endif; ?>
                </div>

                <?php if ($showProduct && !empty($r['product_name'])): ?>
                    <div class="muted small-note">on <?= e($r['product_name']) ?></div>
                <?php endif; ?>

                <?php if (!empty($r['title'])): ?>
                    <h4 class="review-title"><?= e($r['title']) ?></h4>
                <?php endif; ?>

                <p class="review-text"><?= nl2br(e($r['body'])) ?></p>
            </div>
        </article>
        <?php
    }

    /**
     * The interactive star picker used in the review form.
     * The real value lives in a hidden input; jQuery paints the stars.
     * Without JavaScript the radio buttons still work on their own.
     */
    function render_star_input(int $current = 0): void
    {
        ?>
        <div class="star-input" id="starInput" data-value="<?= $current ?>">
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <label class="star-choice" title="<?= e(REVIEW_RATING_LABELS[$i]) ?>">
                    <input type="radio" name="rating" value="<?= $i ?>"
                           <?= $current === $i ? 'checked' : '' ?> required>
                    <i class="<?= $current >= $i ? 'fas' : 'far' ?> fa-star" data-star="<?= $i ?>"></i>
                    <span class="visually-hidden"><?= $i ?> stars</span>
                </label>
            <?php endfor; ?>

            <span class="star-input-label" id="starLabel">
                <?= $current > 0 ? e(REVIEW_RATING_LABELS[$current]) : 'Select a rating' ?>
            </span>
        </div>
        <?php
    }
}
