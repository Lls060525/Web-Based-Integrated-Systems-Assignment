<?php
// ============================================================
// admin/store_form.php - Add / edit a store
//
// Coordinates are entered by pasting a Google Maps link, because
// geocoding an address is itself a paid Google API and this project
// must work without a billing account.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

if (!store_module_ready()) {
    flash_error('Run database/migration_23_stores.sql first.');
    redirect('/admin/stores.php');
}

$storeId = get_int('id');
$isEdit  = $storeId !== null;

$store = [
    'name' => '', 'code' => '', 'address_line1' => '', 'address_line2' => '',
    'city' => '', 'state' => '', 'postcode' => '', 'country' => 'Malaysia',
    'latitude' => '', 'longitude' => '', 'phone' => '', 'email' => '',
    'opening_hours' => "Mon-Fri 10:00 - 22:00\nSat-Sun 10:00 - 22:00",
    'is_active' => 1, 'sort_order' => 100,
];

if ($isEdit) {
    $found = find_store($storeId);

    if (!$found) {
        flash_error('Store not found.');
        redirect('/admin/stores.php');
    }

    $store = $found;
}

$title      = ($isEdit ? 'Edit' : 'Add') . ' Store - Admin';
$mapPasted  = '';

if (is_post()) {
    csrf_check();

    $name      = post('name');
    $code      = strtoupper(trim(post('code')));
    $address1  = post('address_line1');
    $address2  = post('address_line2');
    $city      = post('city');
    $state     = post('state');
    $postcode  = post('postcode');
    $country   = post('country', 'Malaysia');
    $phone     = post('phone');
    $email     = post('email');
    $hours     = post('opening_hours');
    $active    = post('is_active') === '1' ? 1 : 0;
    $sortOrder = post('sort_order', '100');
    $mapPasted = post('map_link');

    if (v_required('name', $name, 'Store name')) {
        v_max('name', $name, 120, 'Store name');
    }

    if (v_required('code', $code, 'Store code')) {
        v_max('code', $code, 30, 'Store code');

        if (!preg_match('~^[A-Z0-9\-]+$~', $code)) {
            add_err('code', 'The code may only contain letters, numbers and hyphens.');
        } else {
            $clash = db_one('SELECT id FROM stores WHERE code = ? AND id <> ?',
                            [$code, $storeId ?? 0]);

            if ($clash) {
                add_err('code', 'Another store already uses that code.');
            }
        }
    }

    if (v_required('address_line1', $address1, 'Address')) {
        v_max('address_line1', $address1, 150, 'Address');
    }

    v_max('address_line2', $address2, 150, 'Address line 2');
    v_required('city', $city, 'City');
    v_required('state', $state, 'State');
    v_required('postcode', $postcode, 'Postcode');
    v_max('phone', $phone, 30, 'Phone');
    v_max('opening_hours', $hours, 500, 'Opening hours');
    v_integer('sort_order', $sortOrder, 0, 9999, 'Sort order');

    if ($email !== '') {
        v_email('email', $email);
    }

    // ---------- Coordinates ----------
    // Accepts a full Google Maps URL or a bare "lat, lng" pair. Anything
    // else is refused with an explanation rather than silently stored,
    // because a wrong coordinate puts the shop in the sea and nothing on
    // the page would look broken.
    $latitude  = $store['latitude'];
    $longitude = $store['longitude'];

    if (trim($mapPasted) !== '') {
        $coords = parse_map_coordinates($mapPasted);

        if ($coords === null) {
            if (preg_match('~^https?://(maps\.app\.goo\.gl|goo\.gl/maps)~i', trim($mapPasted))) {
                add_err('map_link', 'A shortened Google Maps link does not contain the '
                                  . 'coordinates, so they cannot be read from it. Open the link '
                                  . 'in a browser first, then copy the full address bar.');
            } else {
                add_err('map_link', 'No coordinates found in that. Paste the Google Maps address '
                                  . 'bar, or the pair you get from right-clicking the spot on '
                                  . 'the map, for example 3.1578, 101.7117');
            }
        } else {
            $latitude  = $coords['lat'];
            $longitude = $coords['lng'];
        }
    }

    if (no_err()) {
        $fields = [
            'name'          => $name,
            'code'          => $code,
            'address_line1' => $address1,
            'address_line2' => $address2 !== '' ? $address2 : null,
            'city'          => $city,
            'state'         => $state,
            'postcode'      => $postcode,
            'country'       => $country !== '' ? $country : 'Malaysia',
            'latitude'      => $latitude  !== '' ? $latitude  : null,
            'longitude'     => $longitude !== '' ? $longitude : null,
            'phone'         => $phone !== '' ? $phone : null,
            'email'         => $email !== '' ? $email : null,
            'opening_hours' => $hours !== '' ? $hours : null,
            'is_active'     => $active,
            'sort_order'    => (int)$sortOrder,
        ];

        $columns = array_keys($fields);
        $values  = array_values($fields);

        if ($isEdit) {
            $set      = implode(', ', array_map(static fn($c) => "$c = ?", $columns));
            $values[] = $storeId;

            db_exec("UPDATE stores SET $set WHERE id = ?", $values);
            flash_success('Store updated.');

        } else {
            $marks = implode(', ', array_fill(0, count($columns), '?'));

            db_exec('INSERT INTO stores (' . implode(', ', $columns) . ") VALUES ($marks)", $values);
            flash_success('Store added.');
        }

        redirect('/admin/stores.php');
    }

    // Redraw with what was typed. $fields is not referenced here: it only
    // exists inside the branch above, and that branch redirects.
    $store = array_merge($store, [
        'name' => $name, 'code' => $code, 'address_line1' => $address1,
        'address_line2' => $address2, 'city' => $city, 'state' => $state,
        'postcode' => $postcode, 'country' => $country, 'phone' => $phone,
        'email' => $email, 'opening_hours' => $hours, 'is_active' => $active,
        'sort_order' => $sortOrder, 'latitude' => $latitude, 'longitude' => $longitude,
    ]);
    // Validation failed. Answer with a redirect rather than a page, so
    // the browser's history entry is a GET and F5 cannot resubmit.
    // The errors and what was typed are carried across the redirect.
    redirect_back();
}

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container admin-container-narrow">
    <nav class="breadcrumb">
        <a href="/admin/stores.php">Store Locations</a> &gt;
        <span><?= $isEdit ? 'Edit' : 'Add' ?></span>
    </nav>

    <div class="card card-padded mt-4">
        <h2 class="section-heading"><?= $isEdit ? 'Edit Store' : 'Add Store' ?></h2>

        <?php err_summary(); ?>

        <form action="/admin/store_form.php<?= $isEdit ? '?id=' . (int)$storeId : '' ?>"
              method="POST" class="form-standard">
            <?php csrf_field(); ?>

            <div class="batch-options">
                <?php field('name', 'Store Name', function () use ($store) {
                    html_text('name', $store['name'], ['required' => true, 'maxlength' => 120,
                                                       'placeholder' => 'Mobile2U Kuala Lumpur']);
                }, true); ?>

                <?php field('code', 'Store Code', function () use ($store) {
                    html_text('code', $store['code'], ['required' => true, 'maxlength' => 30,
                                                       'placeholder' => 'M2U-KL']);
                    echo '<small class="form-hint">Letters, numbers and hyphens. Must be unique.</small>';
                }, true); ?>
            </div>

            <?php field('address_line1', 'Address', function () use ($store) {
                html_text('address_line1', $store['address_line1'],
                          ['required' => true, 'maxlength' => 150]);
            }, true); ?>

            <?php field('address_line2', 'Address Line 2', function () use ($store) {
                html_text('address_line2', (string)($store['address_line2'] ?? ''),
                          ['maxlength' => 150]);
            }); ?>

            <div class="batch-options">
                <?php field('postcode', 'Postcode', function () use ($store) {
                    html_text('postcode', $store['postcode'], ['required' => true, 'maxlength' => 12]);
                }, true); ?>

                <?php field('city', 'City', function () use ($store) {
                    html_text('city', $store['city'], ['required' => true, 'maxlength' => 80]);
                }, true); ?>
            </div>

            <div class="batch-options">
                <?php field('state', 'State', function () use ($store) {
                    html_text('state', $store['state'], ['required' => true, 'maxlength' => 80]);
                }, true); ?>

                <?php field('country', 'Country', function () use ($store) {
                    html_text('country', $store['country'], ['maxlength' => 60]);
                }); ?>
            </div>

            <hr class="qr-divider">

            <h3 class="section-heading">Map position</h3>

            <?php field('map_link', 'Paste a Google Maps link', function () use ($mapPasted) {
                html_text('map_link', $mapPasted, [
                    'placeholder' => 'https://www.google.com/maps/place/... or 3.1578, 101.7117',
                    'spellcheck'  => 'false',
                ]);
                echo '<small class="form-hint">'
                   . 'Find the shop in <a href="https://www.google.com/maps" target="_blank" '
                   . 'rel="noopener noreferrer">Google Maps</a>, then either copy the address '
                   . 'bar or right-click the exact spot and click the coordinates to copy them. '
                   . 'Both work. A shortened <code>maps.app.goo.gl</code> link will not, '
                   . 'because the coordinates are not in it.'
                   . '</small>';
            }); ?>

            <div class="batch-options">
                <?php field('latitude', 'Latitude', function () use ($store) {
                    html_text('latitude', (string)($store['latitude'] ?? ''),
                              ['readonly' => true, 'placeholder' => 'Not set']);
                }); ?>

                <?php field('longitude', 'Longitude', function () use ($store) {
                    html_text('longitude', (string)($store['longitude'] ?? ''),
                              ['readonly' => true, 'placeholder' => 'Not set']);
                }); ?>
            </div>

            <?php if (store_has_coordinates($store)): ?>
                <p class="muted small-note">
                    <i class="fas fa-circle-check spec-yes"></i>
                    Position recorded.
                    <a href="<?= e(store_maps_url($store)) ?>" target="_blank" rel="noopener noreferrer">
                        Check it on Google Maps
                    </a>
                    before saving &ndash; a wrong coordinate puts the shop in the sea and
                    nothing on the page will look broken.
                </p>
            <?php endif; ?>

            <hr class="qr-divider">

            <h3 class="section-heading">Contact and hours</h3>

            <div class="batch-options">
                <?php field('phone', 'Phone', function () use ($store) {
                    html_text('phone', (string)($store['phone'] ?? ''), ['maxlength' => 30]);
                }); ?>

                <?php field('email', 'Email', function () use ($store) {
                    html_email('email', (string)($store['email'] ?? ''), ['maxlength' => 100]);
                }); ?>
            </div>

            <?php field('opening_hours', 'Opening Hours', function () use ($store) {
                html_textarea('opening_hours', (string)($store['opening_hours'] ?? ''), ['rows' => 3]);
                echo '<small class="form-hint">One line per entry.</small>';
            }); ?>

            <?php field('sort_order', 'Sort Order', function () use ($store) {
                html_number('sort_order', (string)$store['sort_order'], ['min' => 0, 'max' => 9999]);
            }); ?>

            <div class="form-group form-check">
                <label for="is_active" class="check-label">
                    <input type="checkbox" name="is_active" id="is_active" value="1"
                           <?= (int)$store['is_active'] === 1 ? 'checked' : '' ?>>
                    <span>Visible on the store locator</span>
                </label>
            </div>

            <div class="form-actions">
                <a href="/admin/stores.php" class="btn-outline">Cancel</a>
                <?php html_submit($isEdit ? 'Save Changes' : 'Add Store', ['class' => 'btn-primary']); ?>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
