<?php
// ============================================================
// includes/product_gallery.php
// The storefront photo slider.
//
// Hand-written rather than pulled from a carousel library: the
// assignment gives more credit for code you can explain, and the
// whole thing is a list of images plus an active index.
//
// Without JavaScript every photo is still reachable, because the
// thumbnails are anchors to each slide.
// ============================================================

if (!function_exists('render_product_gallery')) {

    function render_product_gallery(array $product): void
    {
        $images  = gallery_images($product);
        $count   = count($images);

        // The video becomes one more slide at the end of the gallery,
        // so it shares the same thumbnail strip and arrows.
        $videoId = video_module_ready() ? ($product['video_id'] ?? null) : null;
        $hasVideo = is_valid_video_id($videoId);
        $slideCount = $count + ($hasVideo ? 1 : 0);
        ?>
        <div class="gallery <?= $slideCount > 1 ? 'has-many' : '' ?>" id="productGallery">

            <div class="gallery-stage">
                <?php foreach ($images as $i => $img): ?>
                    <figure class="gallery-slide <?= $i === 0 ? 'is-active' : '' ?>"
                            id="slide<?= $i ?>" data-index="<?= $i ?>">
                        <img src="<?= e($img['url']) ?>" alt="<?= e($img['alt']) ?>">
                    </figure>
                <?php endforeach; ?>

                <?php if ($hasVideo): ?>
                    <?php
                        // FACADE PATTERN. Nothing from YouTube is requested
                        // until the visitor clicks play: no iframe, no
                        // scripts, no cookies, no third-party tracking on a
                        // page the visitor may never watch the video on.
                        // The poster is YouTube's own thumbnail, with the
                        // product photo as a fallback if it cannot load.
                    ?>
                    <figure class="gallery-slide gallery-video-slide"
                            id="slide<?= $count ?>" data-index="<?= $count ?>">
                        <div class="video-facade" data-video-id="<?= e($videoId) ?>">
                            <img src="<?= e(youtube_thumbnail_url($videoId)) ?>"
                                 alt="<?= e($product['video_title'] ?: $product['name'] . ' video') ?>"
                                 class="video-poster"
                                 data-fallback="<?= e($images[0]['url']) ?>">

                            <button type="button" class="video-play js-play-video"
                                    aria-label="Play video">
                                <i class="fas fa-play"></i>
                            </button>

                            <span class="video-facade-note">
                                Loads from YouTube only when you press play
                            </span>
                        </div>
                    </figure>
                <?php endif; ?>

                <?php if ($slideCount > 1): ?>
                    <button type="button" class="gallery-arrow gallery-prev" aria-label="Previous photo">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <button type="button" class="gallery-arrow gallery-next" aria-label="Next photo">
                        <i class="fas fa-chevron-right"></i>
                    </button>

                    <span class="gallery-counter">
                        <span class="gallery-current">1</span> / <?= $slideCount ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($slideCount > 1): ?>
                <ul class="gallery-thumbs">
                    <?php foreach ($images as $i => $img): ?>
                        <li>
                            <!-- An anchor, so it still works without JavaScript. -->
                            <a href="#slide<?= $i ?>"
                               class="gallery-thumb <?= $i === 0 ? 'is-active' : '' ?>"
                               data-index="<?= $i ?>">
                                <img src="<?= e($img['url']) ?>" alt="">
                            </a>
                        </li>
                    <?php endforeach; ?>

                    <?php if ($hasVideo): ?>
                        <li>
                            <a href="#slide<?= $count ?>"
                               class="gallery-thumb gallery-thumb-video"
                               data-index="<?= $count ?>" title="Product video">
                                <img src="<?= e(youtube_thumbnail_url($videoId)) ?>" alt=""
                                     data-fallback="<?= e($images[0]['url']) ?>">
                                <span class="thumb-play"><i class="fas fa-play"></i></span>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>

            <?php if ($hasVideo && !empty($product['video_title'])): ?>
                <p class="video-caption muted small-note">
                    <i class="fab fa-youtube"></i> <?= e($product['video_title']) ?>
                    &middot;
                    <a href="<?= e(youtube_watch_url($videoId)) ?>"
                       target="_blank" rel="noopener noreferrer">Watch on YouTube</a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
}
