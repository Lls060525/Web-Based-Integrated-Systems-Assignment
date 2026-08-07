<?php
// ============================================================
// lib/store.php
// Store locations and the map that shows them.
//
// Google's JavaScript Maps API needs an API key, and a key needs a
// billing account with a card on it. That is a real obstacle for a
// project that has to run on somebody else's machine, so this module
// works BOTH ways:
//
//   MAP_DRIVER = 'embed'  no key at all. Google's classic embed URL is
//                         dropped into an <iframe>. Works immediately.
//   MAP_DRIVER = 'js'     the full JavaScript API: one map, every store
//                         as a marker, info windows, fit-to-bounds.
//
// The page is written so the embed version is not a broken stub -- it
// shows a real Google map of the selected store, with directions. The
// key only buys the multi-marker view.
// ============================================================

/** True once migration_23 has been run. */
function store_module_ready(): bool
{
    return db_table_exists('stores');
}

/** Which map renderer is in use. */
function map_driver(): string
{
    if (!defined('MAP_DRIVER')) {
        return 'embed';
    }

    if (MAP_DRIVER === 'js' && trim((string)GOOGLE_MAPS_API_KEY) !== '') {
        return 'js';
    }

    return MAP_DRIVER === 'off' ? 'off' : 'embed';
}

/** Diagnostics for the admin environment page. */
function map_status(): array
{
    $driver = map_driver();

    return [
        'driver'  => $driver,
        'has_key' => defined('GOOGLE_MAPS_API_KEY') && trim((string)GOOGLE_MAPS_API_KEY) !== '',
        'note'    => match ($driver) {
            'js'    => 'Full JavaScript map with one marker per store.',
            'embed' => 'Keyless embed. Add a Google Maps API key and set MAP_DRIVER to "js" '
                     . 'for the multi-marker map.',
            default => 'Maps are switched off.',
        },
    ];
}

// ------------------------------------------------------------
// Reading coordinates out of what a human pastes
// ------------------------------------------------------------

/**
 * Pull latitude and longitude out of a Google Maps link, or a plain pair.
 *
 * Geocoding an address into coordinates is itself a paid Google API, so
 * the admin form does not try. Instead it accepts what somebody can get
 * for free in ten seconds: right-click the spot in Google Maps, copy the
 * coordinates, or just copy the address bar.
 *
 * Google writes coordinates into a URL in more than one place, and they
 * do not always agree:
 *
 *   .../@3.1578,101.7117,17z            the map CENTRE
 *   ...!3d3.1578!4d101.7117             the PLACE itself
 *
 * The place wins when both are present, because the centre drifts as
 * soon as anybody pans the map before copying the link.
 *
 * @return array{lat: float, lng: float}|null
 */
function parse_map_coordinates(string $input): ?array
{
    $input = trim($input);

    if ($input === '') {
        return null;
    }

    // A shortened link is just an identifier; resolving it needs a
    // network round trip, which this form deliberately does not make.
    if (preg_match('~^https?://(maps\.app\.goo\.gl|goo\.gl/maps)~i', $input)) {
        return null;
    }

    $patterns = [
        '~!3d(-?\d+\.?\d*)!4d(-?\d+\.?\d*)~',        // the place marker
        '~[@](-?\d+\.\d+),\s*(-?\d+\.\d+)~',         // the map centre
        '~[?&](?:q|ll|daddr|destination)=(-?\d+\.?\d*),\s*(-?\d+\.?\d*)~i',
        '~^\s*(-?\d+\.?\d*)\s*,\s*(-?\d+\.?\d*)\s*$~', // a plain pair
    ];

    foreach ($patterns as $pattern) {
        if (!preg_match($pattern, $input, $m)) {
            continue;
        }

        $lat = (float)$m[1];
        $lng = (float)$m[2];

        if (valid_coordinates($lat, $lng)) {
            return ['lat' => round($lat, 7), 'lng' => round($lng, 7)];
        }
    }

    return null;
}

/**
 * Range check.
 *
 * Exactly 0,0 is rejected as well. It is a real point in the Atlantic
 * that no shop is at, and it is what a half-filled form produces, so
 * treating it as valid puts a marker off the coast of Africa.
 */
function valid_coordinates(?float $lat, ?float $lng): bool
{
    if ($lat === null || $lng === null) {
        return false;
    }

    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return false;
    }

    return !(abs($lat) < 0.000001 && abs($lng) < 0.000001);
}

// ------------------------------------------------------------
// Distance
// ------------------------------------------------------------

/**
 * Great-circle distance in kilometres.
 *
 * Straight Pythagoras on latitude and longitude is wrong, because a
 * degree of longitude is about 111km at the equator and shrinks to zero
 * at the poles. Malaysia is near the equator so the error is smallish,
 * but "nearest store" should not be a function of how far north you are.
 */
function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadius = 6371.0088;

    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);

    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

    return $earthRadius * 2 * asin(min(1.0, sqrt($a)));
}

/** "850 m" reads better than "0.85 km". */
function format_distance(float $km): string
{
    if ($km < 1) {
        return round($km * 1000) . ' m';
    }

    return number_format($km, $km < 10 ? 1 : 0) . ' km';
}

// ------------------------------------------------------------
// Reading stores
// ------------------------------------------------------------

/** Stores customers can see, in display order. */
function active_stores(): array
{
    if (!store_module_ready()) {
        return [];
    }

    return db_all(
        'SELECT * FROM stores WHERE is_active = 1 ORDER BY is_primary DESC, sort_order ASC, name ASC'
    );
}

/** Every store, for the admin listing. */
function all_stores(): array
{
    if (!store_module_ready()) {
        return [];
    }

    return db_all('SELECT * FROM stores ORDER BY is_primary DESC, sort_order ASC, name ASC');
}

function find_store(int $id): ?array
{
    if (!store_module_ready()) {
        return null;
    }

    $row = db_one('SELECT * FROM stores WHERE id = ?', [$id]);

    return $row ?: null;
}

/** The store used for contact details when no particular one is asked for. */
function primary_store(): ?array
{
    if (!store_module_ready()) {
        return null;
    }

    $row = db_one('SELECT * FROM stores WHERE is_active = 1 ORDER BY is_primary DESC, sort_order ASC LIMIT 1');

    return $row ?: null;
}

/** True when this store can appear on a map at all. */
function store_has_coordinates(array $store): bool
{
    return valid_coordinates(
        $store['latitude']  === null ? null : (float)$store['latitude'],
        $store['longitude'] === null ? null : (float)$store['longitude']
    );
}

/**
 * Active stores sorted by distance from a point, nearest first.
 *
 * Sorted in PHP rather than SQL. MySQL has ST_Distance_Sphere, but it
 * needs the coordinates stored as a spatial POINT rather than two
 * decimal columns, and this table is a handful of rows -- sorting five
 * shops in PHP is not the bottleneck, and it keeps the schema readable.
 */
function stores_by_distance(float $lat, float $lng): array
{
    $stores = [];

    foreach (active_stores() as $store) {
        if (!store_has_coordinates($store)) {
            continue;
        }

        $store['distance_km'] = haversine_km(
            $lat, $lng, (float)$store['latitude'], (float)$store['longitude']
        );

        $stores[] = $store;
    }

    usort($stores, static fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);

    return $stores;
}

// ------------------------------------------------------------
// Formatting and links
// ------------------------------------------------------------

/** The address as lines, for a block, or joined for one line. */
function store_address_lines(array $store): array
{
    $lines = array_filter([
        $store['address_line1'] ?? '',
        $store['address_line2'] ?? '',
        trim(($store['postcode'] ?? '') . ' ' . ($store['city'] ?? '')),
        trim(($store['state'] ?? '') . ', ' . ($store['country'] ?? '')),
    ], static fn($line) => trim((string)$line) !== '' && trim((string)$line) !== ',');

    return array_values($lines);
}

function store_address_one_line(array $store): string
{
    return implode(', ', store_address_lines($store));
}

/** What a map should search for: coordinates if we have them, else the address. */
function store_map_query(array $store): string
{
    if (store_has_coordinates($store)) {
        return $store['latitude'] . ',' . $store['longitude'];
    }

    return store_address_one_line($store);
}

/**
 * Keyless Google Maps embed URL.
 *
 * This endpoint has worked without an API key for years and is what
 * makes the module usable before anybody sets up billing.
 */
function store_embed_url(array $store, int $zoom = 16): string
{
    return 'https://maps.google.com/maps?q=' . rawurlencode(store_map_query($store))
         . '&z=' . $zoom . '&output=embed';
}

/** "Directions" opens the customer's own maps app. */
function store_directions_url(array $store): string
{
    return 'https://www.google.com/maps/dir/?api=1&destination='
         . rawurlencode(store_map_query($store));
}

/** A plain link to the place, for the admin listing. */
function store_maps_url(array $store): string
{
    return 'https://www.google.com/maps/search/?api=1&query='
         . rawurlencode(store_map_query($store));
}

/** Opening hours split into lines for display. */
function store_hours_lines(array $store): array
{
    $raw = trim((string)($store['opening_hours'] ?? ''));

    if ($raw === '') {
        return [];
    }

    $lines = preg_split('~\r\n|\r|\n|;~', $raw);

    return array_values(array_filter(array_map('trim', $lines), static fn($l) => $l !== ''));
}

/**
 * Everything the JavaScript map needs, as a plain array ready for
 * json_encode. Only what the page actually uses, so an internal note or
 * an email address never reaches the browser by accident.
 */
function stores_for_map(array $stores): array
{
    $out = [];

    foreach ($stores as $store) {
        if (!store_has_coordinates($store)) {
            continue;
        }

        $out[] = [
            'id'      => (int)$store['id'],
            'name'    => $store['name'],
            'lat'     => (float)$store['latitude'],
            'lng'     => (float)$store['longitude'],
            'address' => store_address_one_line($store),
            'phone'   => $store['phone'] ?? '',
            'url'     => '/stores.php?id=' . (int)$store['id'],
        ];
    }

    return $out;
}
