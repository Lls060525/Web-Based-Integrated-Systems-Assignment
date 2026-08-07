/* ============================================================
 * assets/js/storemap.js
 * Store locator: nearest-store sorting, and the Google map when a
 * key is configured.
 *
 * Two independent halves:
 *
 *   1. "Find my nearest store" uses the browser's Geolocation API and
 *      works with NO map at all. The distances are computed here, so
 *      the visitor's position is never sent to our server.
 *
 *   2. The Google map is only built when lib/config.php has an API key
 *      and MAP_DRIVER is 'js'. Without one the page shows a keyless
 *      iframe instead, rendered by PHP.
 * ============================================================ */

$(function () {

    /* ---------- Distance ---------- */

    // Same formula as haversine_km() in lib/store.php. Straight
    // Pythagoras on degrees would treat a degree of longitude as a fixed
    // distance, which it is not once you leave the equator.
    function haversineKm(lat1, lng1, lat2, lng2) {
        var R = 6371.0088;
        var toRad = function (d) { return d * Math.PI / 180; };

        var dLat = toRad(lat2 - lat1);
        var dLng = toRad(lng2 - lng1);

        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2)
              + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2))
              * Math.sin(dLng / 2) * Math.sin(dLng / 2);

        return R * 2 * Math.asin(Math.min(1, Math.sqrt(a)));
    }

    function formatDistance(km) {
        if (km < 1)  { return Math.round(km * 1000) + ' m'; }
        if (km < 10) { return km.toFixed(1) + ' km'; }

        return Math.round(km).toLocaleString() + ' km';
    }

    /* ---------- Find my nearest store ---------- */

    var $findBtn = $('#findNearest');

    if ($findBtn.length) {
        if (!navigator.geolocation) {
            $findBtn.prop('disabled', true);
            $('#locateNote').text('This browser cannot share your location.');
        }

        $findBtn.on('click', function () {
            var $btn  = $(this);
            var $note = $('#locateNote');

            // Geolocation needs a secure context, exactly like the camera.
            if (window.isSecureContext !== true) {
                $note.html('Location needs a secure connection. Open this page as ' +
                           '<code>http://localhost/</code> or over HTTPS.');
                return;
            }

            $btn.prop('disabled', true).addClass('is-busy');
            $note.text('Asking your browser for your location...');

            navigator.geolocation.getCurrentPosition(function (pos) {
                var lat = pos.coords.latitude;
                var lng = pos.coords.longitude;

                var $items = $('#storeList .store-item').filter(function () {
                    return $(this).data('lat') !== undefined;
                });

                $items.each(function () {
                    var $item = $(this);
                    var km = haversineKm(lat, lng,
                                         parseFloat($item.data('lat')),
                                         parseFloat($item.data('lng')));

                    $item.data('km', km);
                    $item.find('[data-distance]').text(formatDistance(km) + ' away');
                });

                // Re-ordered in place: the nearest store moves to the top
                // of the list the visitor is already looking at.
                var sorted = $items.get().sort(function (a, b) {
                    return $(a).data('km') - $(b).data('km');
                });

                $('#storeList').append(sorted);
                $items.removeClass('is-nearest').first();
                $(sorted[0]).addClass('is-nearest');

                $note.text('Sorted by distance from you. Nearest: ' +
                           $(sorted[0]).find('.store-name').text().trim() + '.');

                $btn.prop('disabled', false).removeClass('is-busy');

                if (window.mobile2uMap && window.mobile2uMap.centreOn) {
                    window.mobile2uMap.centreOn(lat, lng);
                }

            }, function (error) {
                var message = 'Your location could not be read.';

                if (error && error.code === error.PERMISSION_DENIED) {
                    message = 'Location permission was denied. You can still pick a store from the list.';
                } else if (error && error.code === error.POSITION_UNAVAILABLE) {
                    message = 'Your position is not available right now.';
                } else if (error && error.code === error.TIMEOUT) {
                    message = 'Locating you took too long. Please try again.';
                }

                $note.text(message);
                $btn.prop('disabled', false).removeClass('is-busy');

            }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 });
        });
    }

    /* ---------- Google map (only when a key is configured) ---------- */

    var $map = $('#storeMap');

    if ($map.length === 0) { return; }

    var stores = $map.data('stores') || [];

    if (!stores.length) { return; }

    // The API script is loaded with defer, so it may not have run yet.
    // Polling briefly is simpler than a global callback and keeps the
    // markup free of a function name that only exists for Google.
    var waited = 0;

    var build = function () {
        if (typeof google === 'undefined' || !google.maps) {
            waited += 200;

            if (waited > 8000) {
                $map.addClass('map-failed')
                    .text('The map could not be loaded. Check the API key in lib/config.php, '
                        + 'and that the Maps JavaScript API is enabled for it.');
                return;
            }

            window.setTimeout(build, 200);
            return;
        }

        var map = new google.maps.Map($map[0], {
            center: {
                lat: parseFloat($map.data('default-lat')),
                lng: parseFloat($map.data('default-lng'))
            },
            zoom: parseInt($map.data('zoom'), 10) || 11,
            mapTypeControl: false,
            streetViewControl: false
        });

        var bounds = new google.maps.LatLngBounds();
        var info   = new google.maps.InfoWindow();

        $.each(stores, function (i, store) {
            var marker = new google.maps.Marker({
                position: { lat: store.lat, lng: store.lng },
                map: map,
                title: store.name
            });

            bounds.extend(marker.getPosition());

            marker.addListener('click', function () {
                // Built with DOM methods, not string concatenation: a
                // store name is admin-entered text and must not be able
                // to inject markup into the info window.
                var $box = $('<div>').addClass('map-info');

                $box.append($('<strong>').text(store.name));
                $box.append($('<div>').addClass('map-info-address').text(store.address));

                if (store.phone) {
                    $box.append($('<div>').text(store.phone));
                }

                $box.append($('<a>').attr('href', store.url).text('View details'));

                info.setContent($box[0]);
                info.open(map, marker);
            });

            $('#storeList .store-item[data-id="' + store.id + '"]').on('click', function () {
                map.panTo(marker.getPosition());
                map.setZoom(16);
                google.maps.event.trigger(marker, 'click');
            });
        });

        // Every store visible, rather than a guessed zoom level.
        if (stores.length > 1) {
            map.fitBounds(bounds);
        } else {
            map.setCenter({ lat: stores[0].lat, lng: stores[0].lng });
            map.setZoom(16);
        }

        window.mobile2uMap = {
            centreOn: function (lat, lng) {
                new google.maps.Marker({
                    position: { lat: lat, lng: lng },
                    map: map,
                    title: 'You are here',
                    icon: {
                        path: google.maps.SymbolPath.CIRCLE,
                        scale: 7,
                        fillColor: '#1a73e8',
                        fillOpacity: 1,
                        strokeColor: '#ffffff',
                        strokeWeight: 2
                    }
                });

                map.panTo({ lat: lat, lng: lng });
            }
        };
    };

    build();
});
