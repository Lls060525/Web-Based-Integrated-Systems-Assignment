<?php
// ============================================================
// stores.php - Store Locator (public)
//
// Works with or without a Google Maps API key. See lib/store.php for
// why that matters.
// ============================================================

require_once __DIR__ . '/lib/init.php';

$title = 'Store Locations - ' . APP_NAME;

$stores   = active_stores();
$selected = null;

$requestedId = get_int('id');

if ($requestedId !== null) {
    foreach ($stores as $store) {
        if ((int)$store['id'] === $requestedId) {
            $selected = $store;
            break;
        }
    }
}

if ($selected === null && $stores !== []) {
    $selected = $stores[0];
}

$driver   = map_driver();
$mapData  = stores_for_map($stores);
$mappable = count($mapData);

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h2 class="page-title">Our Stores</h2>
    <p class="page-subtitle">
        <?= count($stores) ?> location<?= count($stores) === 1 ? '' : 's' ?> across Malaysia.
        Come and try a device before you buy it.
    </p>
</div>

<?php if (!store_module_ready()): ?>

    <div class="alert alert-info">
        The store locator is not installed yet.
    </div>

<?php elseif ($stores === []): ?>

    <div class="card card-padded empty-state-box">
        <h3 class="empty-state-title">No stores listed yet.</h3>
        <p class="muted">Please check back soon.</p>
    </div>

<?php else: ?>

    <div class="locator-layout">

        <aside class="locator-list">
            <?php if ($mappable > 1): ?>
                <button type="button" class="btn-outline btn-block" id="findNearest"
                        data-busy="Locating...">
                    <i class="fas fa-location-crosshairs"></i> Find my nearest store
                </button>

                <p class="muted small-note" id="locateNote">
                    Uses your browser's location. Nothing is sent to our server &ndash;
                    the distances are worked out in your browser.
                </p>
            <?php endif; ?>

            <ul class="store-list" id="storeList">
                <?php foreach ($stores as $store): ?>
                    <li class="store-item <?= $selected && (int)$selected['id'] === (int)$store['id'] ? 'is-active' : '' ?>"
                        data-id="<?= (int)$store['id'] ?>"
                        <?php if (store_has_coordinates($store)): ?>
                            data-lat="<?= e($store['latitude']) ?>"
                            data-lng="<?= e($store['longitude']) ?>"
                        <?php endif; ?>>

                        <a href="/stores.php?id=<?= (int)$store['id'] ?>" class="store-item-link">
                            <span class="store-name">
                                <?= e($store['name']) ?>
                                <?php if ((int)$store['is_primary'] === 1): ?>
                                    <span class="badge badge-info">Flagship</span>
                                <?php endif; ?>
                            </span>

                            <span class="store-city"><?= e($store['city']) ?>, <?= e($store['state']) ?></span>

                            <span class="store-distance" data-distance></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </aside>

        <div class="locator-main">

            <?php if ($driver === 'off'): ?>
                <div class="alert alert-info">Maps are switched off for this site.</div>

            <?php elseif ($driver === 'js'): ?>
                <?php // One map, every store on it, driven by assets/js/storemap.js. ?>
                <div class="store-map" id="storeMap"
                     data-stores="<?= e(json_encode($mapData, JSON_UNESCAPED_UNICODE)) ?>"
                     data-default-lat="<?= e(MAP_DEFAULT_LAT) ?>"
                     data-default-lng="<?= e(MAP_DEFAULT_LNG) ?>"
                     data-zoom="<?= (int)MAP_DEFAULT_ZOOM ?>"
                     data-selected="<?= $selected ? (int)$selected['id'] : 0 ?>"></div>

            <?php elseif ($selected !== null): ?>
                <?php
                    /* Keyless fallback. Google's classic embed endpoint takes
                     * a query and returns a real, interactive map with no API
                     * key, so this is a working map rather than a placeholder.
                     * It can only show one place at a time, which is the one
                     * thing the key actually buys.
                     *
                     * loading="lazy" keeps the iframe off the critical path,
                     * and referrerpolicy stops our URL being handed to Google
                     * on every view.
                     */
                ?>
                <iframe class="store-map"
                        src="<?= e(store_embed_url($selected)) ?>"
                        title="Map showing <?= e($selected['name']) ?>"
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                        allowfullscreen></iframe>
            <?php endif; ?>

            <?php if ($selected !== null): ?>
                <div class="card card-padded store-detail" id="storeDetail">
                    <h3 class="store-detail-name"><?= e($selected['name']) ?></h3>

                    <div class="store-detail-grid">
                        <div>
                            <h4 class="store-detail-label">Address</h4>
                            <address class="store-address">
                                <?php foreach (store_address_lines($selected) as $line): ?>
                                    <?= e($line) ?><br>
                                <?php endforeach; ?>
                            </address>

                            <?php if (!empty($selected['phone'])): ?>
                                <p class="store-contact">
                                    <i class="fas fa-phone"></i>
                                    <a href="tel:<?= e(preg_replace('~[^0-9+]~', '', $selected['phone'])) ?>">
                                        <?= e($selected['phone']) ?>
                                    </a>
                                </p>
                            <?php endif; ?>

                            <?php if (!empty($selected['email'])): ?>
                                <p class="store-contact">
                                    <i class="fas fa-envelope"></i>
                                    <a href="mailto:<?= e($selected['email']) ?>"><?= e($selected['email']) ?></a>
                                </p>
                            <?php endif; ?>
                        </div>

                        <div>
                            <?php $hours = store_hours_lines($selected); ?>

                            <?php if ($hours !== []): ?>
                                <h4 class="store-detail-label">Opening hours</h4>
                                <ul class="store-hours">
                                    <?php foreach ($hours as $line): ?>
                                        <li><?= e($line) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <?php if (store_has_coordinates($selected)): ?>
                                <a href="<?= e(store_directions_url($selected)) ?>"
                                   class="btn-primary mt-2" target="_blank" rel="noopener noreferrer">
                                    <i class="fas fa-diamond-turn-right"></i> Get Directions
                                </a>
                            <?php else: ?>
                                <p class="muted small-note">
                                    Exact coordinates for this store have not been recorded yet.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>

<?php endif; ?>

<?php if ($driver === 'js' && $mappable > 0): ?>
    <?php // The API is loaded here rather than in the layout, so no other
          // page pays for a script it never uses. ?>
    <script src="https://maps.googleapis.com/maps/api/js?key=<?= e(GOOGLE_MAPS_API_KEY) ?>&loading=async"
            defer></script>
<?php endif; ?>

<script src="/assets/js/storemap.js" defer></script>

<?php include __DIR__ . '/includes/footer.php'; ?>
