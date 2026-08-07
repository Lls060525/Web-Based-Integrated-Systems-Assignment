/* ============================================================
 * assets/js/qrscan.js
 * Scan a receipt QR code with the camera.
 *
 * Decoding happens here, in the browser. Frames are drawn to an
 * off-screen canvas and passed to jsQR; only the decoded string is sent
 * to the server. The video itself never leaves the machine.
 *
 * Like the webcam module, getUserMedia needs a secure context, which
 * means HTTPS or http://localhost. Opening the site by LAN IP is
 * refused by the browser, so that case is detected and explained rather
 * than left looking broken.
 * ============================================================ */

$(function () {

    var $video = $('#qrVideo');

    if ($video.length === 0) { return; }

    var video       = $video[0];
    var canvas      = document.getElementById('qrCanvas');
    var context     = canvas.getContext('2d', { willReadFrequently: true });

    var $status      = $('#qrStatus');
    var $start       = $('#qrStart');
    var $stop        = $('#qrStop');
    var $switch      = $('#qrSwitch');
    var $placeholder = $('#qrPlaceholder');
    var $reticle     = $('#qrReticle');
    var $unsupported = $('#qrUnsupported');

    var stream       = null;
    var frameHandle  = null;
    var scanning     = false;
    var facing       = 'environment';   // rear camera first: staff scan a customer's screen
    var lastValue    = '';
    var lastAt       = 0;
    var busy         = false;

    /* ---------- Loading the decoder ---------- */
    /*
     * jsQR is fetched here rather than with a <script> tag on the page,
     * for two reasons: it keeps the page free of inline script, and a
     * single hardcoded CDN is a single point of failure. The local copy
     * is tried FIRST, so dropping the file into assets/js/vendor/ makes
     * the scanner work on a machine with no internet at all.
     */
    var DECODER_SOURCES = [
        '/assets/js/vendor/jsQR.js',
        'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js',
        'https://unpkg.com/jsqr@1.4.0/dist/jsQR.js'
    ];

    function loadDecoder(onReady, onFail) {
        if (typeof window.jsQR === 'function') { onReady(); return; }

        var index = 0;

        (function attempt() {
            if (index >= DECODER_SOURCES.length) { onFail(); return; }

            var url = DECODER_SOURCES[index++];

            $.ajax({
                url: url,
                dataType: 'script',
                cache: true,          // no cache-busting parameter, so the CDN copy is reused
                timeout: 8000
            }).done(function () {
                // A 200 that is not actually the library still counts as a miss.
                if (typeof window.jsQR === 'function') { onReady(); } else { attempt(); }
            }).fail(attempt);
        })();
    }

    /* ---------- Capability checks ---------- */

    var hasApi   = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    var isSecure = window.isSecureContext === true;

    function refuse(message) {
        $start.prop('disabled', true);
        $unsupported.html(message).prop('hidden', false);
    }

    if (!hasApi) {
        refuse('<strong>This browser has no camera API.</strong> ' +
               'Use the reference field below instead.');

    } else if (!isSecure) {
        refuse('<strong>The camera needs a secure connection.</strong> ' +
               'Open this page as <code>http://localhost/</code> or over HTTPS. ' +
               'Browsers block camera access on a plain LAN address such as ' +
               '<code>http://192.168.1.5</code>. The reference field below works either way.');

    } else {
        // Only worth fetching the decoder if a camera could be used at all.
        $start.prop('disabled', true);
        $status.text('Loading decoder...');

        loadDecoder(function () {
            $start.prop('disabled', false);
            $status.text('Camera is off.');

        }, function () {
            refuse('<strong>The decoding library could not be loaded.</strong> ' +
                   'This machine may be offline, or a CDN may be blocked. ' +
                   'To fix it permanently, save ' +
                   '<code>https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js</code> ' +
                   'as <code>assets/js/vendor/jsQR.js</code> and reload. ' +
                   'Typing the reference below works either way.');
            $status.text('Decoder unavailable.');
        });
    }

    /* ---------- Camera ---------- */

    function setStatus(text) { $status.text(text); }

    function startCamera() {
        if (scanning) { return; }

        setStatus('Starting camera...');
        $start.prop('disabled', true);

        navigator.mediaDevices.getUserMedia({
            video: { facingMode: facing, width: { ideal: 1280 }, height: { ideal: 720 } },
            audio: false
        }).then(function (mediaStream) {
            stream = mediaStream;
            video.srcObject = mediaStream;
            video.setAttribute('playsinline', true);

            return video.play();

        }).then(function () {
            scanning = true;

            $video.prop('hidden', false);
            $placeholder.prop('hidden', true);
            $reticle.prop('hidden', false);
            $start.prop('hidden', true).prop('disabled', false);
            $stop.prop('hidden', false);
            $switch.prop('hidden', false);

            setStatus('Point the camera at the QR code.');
            frameHandle = requestAnimationFrame(tick);

        }).catch(function (error) {
            $start.prop('disabled', false);

            var message = 'The camera could not be started.';

            if (error && (error.name === 'NotAllowedError' || error.name === 'SecurityError')) {
                message = 'Camera permission was denied. Allow it from the icon in the ' +
                          'address bar, then try again.';
            } else if (error && error.name === 'NotFoundError') {
                message = 'No camera was found on this machine.';
            } else if (error && error.name === 'NotReadableError') {
                message = 'The camera is already in use by another program.';
            }

            setStatus(message);
        });
    }

    function stopCamera() {
        scanning = false;

        if (frameHandle) { cancelAnimationFrame(frameHandle); frameHandle = null; }

        if (stream) {
            stream.getTracks().forEach(function (track) { track.stop(); });
            stream = null;
        }

        video.srcObject = null;

        $video.prop('hidden', true);
        $placeholder.prop('hidden', false);
        $reticle.prop('hidden', true);
        $start.prop('hidden', false);
        $stop.prop('hidden', true);
        $switch.prop('hidden', true);

        setStatus('Camera is off.');
    }

    /* ---------- Frame loop ---------- */

    function tick() {
        if (!scanning) { return; }

        if (video.readyState === video.HAVE_ENOUGH_DATA) {
            canvas.width  = video.videoWidth;
            canvas.height = video.videoHeight;

            context.drawImage(video, 0, 0, canvas.width, canvas.height);

            var image = context.getImageData(0, 0, canvas.width, canvas.height);

            var code = window.jsQR(image.data, image.width, image.height, {
                inversionAttempts: 'dontInvert'
            });

            if (code && code.data) {
                handleCode(code.data);
            }
        }

        frameHandle = requestAnimationFrame(tick);
    }

    /* ---------- Result handling ---------- */

    function handleCode(value) {
        var now = Date.now();

        // The same code stays in frame for many frames. Without this the
        // page would fire a request every 16ms while the camera is held
        // steady, which is a self-inflicted flood.
        if (value === lastValue && now - lastAt < 4000) { return; }
        if (busy) { return; }

        lastValue = value;
        lastAt    = now;

        $reticle.addClass('is-hit');
        window.setTimeout(function () { $reticle.removeClass('is-hit'); }, 400);

        lookup(value);
    }

    function lookup(value) {
        busy = true;
        setStatus('Checking code...');

        $.post('/api/qr_lookup.php', {
            value: value,
            csrf_token: $('meta[name="csrf-token"]').attr('content')
        }, null, 'json').done(function (data) {

            if (!data || data.status !== 'ok') {
                showError((data && data.message) || 'That code could not be read.');
                setStatus('Not recognised. Try again.');
                return;
            }

            showResult(data);
            setStatus('Found ' + data.receipt_no + '.');

        }).fail(function () {
            showError('The lookup request failed. Check that you are still signed in.');
            setStatus('Request failed.');

        }).always(function () {
            busy = false;
        });
    }

    function showError(message) {
        $('#qrResult').prop('hidden', true);
        $('#qrEmpty').prop('hidden', true);
        $('#qrError').text(message).prop('hidden', false);
    }

    function showResult(data) {
        $('#qrError').prop('hidden', true);
        $('#qrEmpty').prop('hidden', true);

        // .text() throughout: every one of these values came back from the
        // server and must not be treated as markup.
        $('#qrReceiptNo').text(data.receipt_no);
        $('#qrCustomer').text(data.customer);
        $('#qrEmail').text(data.email);
        $('#qrPlaced').text(data.placed);
        $('#qrShortCode').text(data.short_code);
        $('#qrSource').text(data.source);
        $('#qrTotal').text(data.total);
        $('#qrItemCount').text(data.item_count);

        $('#qrStatusBadge')
            .text(data.status_label)
            .attr('class', 'badge badge-' + (data.order_status === 'cancelled' ? 'danger' : 'success'));

        var $items = $('#qrItems').empty();

        $.each(data.items || [], function (i, item) {
            $items.append(
                $('<li>').append(
                    $('<span>').addClass('qr-item-name').text(item.name),
                    $('<span>').addClass('qr-item-qty').text('x' + item.quantity),
                    $('<span>').addClass('qr-item-price').text(item.price)
                )
            );
        });

        $('#qrOpenOrder').attr('href', data.detail_url);
        $('#qrResult').prop('hidden', false);
    }

    /* ---------- Events ---------- */

    $start.on('click', startCamera);
    $stop.on('click', stopCamera);

    $switch.on('click', function () {
        facing = (facing === 'environment') ? 'user' : 'environment';
        stopCamera();
        startCamera();
    });

    $('#qrManualForm').on('submit', function (e) {
        e.preventDefault();

        var value = $.trim($('#qrManualCode').val());

        if (value === '') {
            showError('Type the reference printed under the QR code.');
            return;
        }

        lookup(value);
    });

    // Releasing the camera on navigation stops the recording light from
    // staying on after the page is gone.
    $(window).on('pagehide beforeunload', function () {
        if (scanning) { stopCamera(); }
    });

});
