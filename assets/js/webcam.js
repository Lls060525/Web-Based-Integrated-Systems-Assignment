/* ============================================================
 * assets/js/webcam.js
 * Capture a photo from the webcam and hand it to a dropzone.
 *
 * The captured frame becomes a real File object assigned to the
 * dropzone's <input type="file">, so it travels with the normal form
 * submit and is validated by the same server code as any other
 * upload. There is no base64 endpoint and no second set of rules.
 *
 * IMPORTANT: getUserMedia only works in a secure context, which means
 * HTTPS or http://localhost. Opening the site by LAN IP (for example
 * http://192.168.1.5) will be refused by the browser, so that case is
 * detected and explained rather than failing silently.
 * ============================================================ */

$(function () {

    var $modal = $('#webcamModal');

    if ($modal.length === 0) { return; }

    /* ---------- Capability checks ---------- */

    var hasApi = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);

    // window.isSecureContext covers HTTPS and localhost in one check.
    var isSecure = window.isSecureContext === true;

    if (!hasApi) {
        return;   // no camera API at all: never offer the button
    }

    /* ---------- Add a camera button to every dropzone ---------- */

    $('.dropzone').each(function () {
        var $zone = $(this);

        if ($zone.find('.js-webcam-open').length) { return; }

        $('<button type="button" class="btn-outline btn-sm dropzone-camera js-webcam-open">'
          + '<i class="fas fa-camera"></i> Use camera</button>')
            .attr('data-input', $zone.data('input'))
            .appendTo($zone.find('.dropzone-text'));
    });

    /* ---------- State ---------- */

    var stream      = null;   // the live MediaStream
    var targetInput = null;   // which file input to fill
    var countdownId = null;

    var $video   = $('#webcamVideo');
    var $canvas  = $('#webcamCanvas');
    var $status  = $('#webcamStatus');
    var $error   = $('#webcamError');
    var $live    = $('#webcamLiveControls');
    var $shot    = $('#webcamShotControls');
    var $devices = $('#webcamDevice');
    var $count   = $('#webcamCountdown');

    /* $detail is appended as TEXT, never as markup.
     *
     * The literal half of these messages is ours and contains <strong>
     * and <code>, so it has to go through .html(). Anything dynamic --
     * a browser error string, the current hostname -- goes through
     * .text() instead. Neither is attacker-controlled today, but
     * concatenating a runtime value into .html() is the shape of an XSS
     * even when this particular value cannot carry one. */
    function showError(message, detail) {
        $error.html(message).removeAttr('hidden');

        if (detail) {
            $error.append($('<span>').text(' ' + detail));
        }

        $status.attr('hidden', true);
        $live.attr('hidden', true);
        $shot.attr('hidden', true);
    }

    function clearError() {
        $error.attr('hidden', true).empty();
    }

    /* ---------- Camera lifecycle ---------- */

    function stopCamera() {
        // Every track must be stopped or the camera indicator light
        // stays on after the dialog is closed.
        if (stream) {
            stream.getTracks().forEach(function (track) { track.stop(); });
            stream = null;
        }

        $video[0].srcObject = null;

        if (countdownId) {
            window.clearInterval(countdownId);
            countdownId = null;
        }
        $count.attr('hidden', true);
    }

    function startCamera(deviceId) {
        stopCamera();
        clearError();

        $status.removeAttr('hidden').html('<i class="fas fa-spinner fa-spin"></i> Starting camera...');
        $canvas.attr('hidden', true);
        $video.removeAttr('hidden');
        $shot.attr('hidden', true);

        var constraints = {
            audio: false,
            video: deviceId
                ? { deviceId: { exact: deviceId } }
                : { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } }
        };

        navigator.mediaDevices.getUserMedia(constraints)
            .then(function (mediaStream) {
                stream = mediaStream;
                $video[0].srcObject = mediaStream;

                $status.attr('hidden', true);
                $live.removeAttr('hidden');

                listDevices();
            })
            .catch(function (err) {
                // The error name tells us exactly what to say.
                var message;
                var detail = '';

                switch (err.name) {
                    case 'NotAllowedError':
                    case 'PermissionDeniedError':
                        message = '<strong>Camera permission was refused.</strong> '
                                + 'Allow camera access for this site in your browser settings, '
                                + 'then try again.';
                        break;
                    case 'NotFoundError':
                    case 'DevicesNotFoundError':
                        message = '<strong>No camera was found.</strong> '
                                + 'Connect a webcam and try again.';
                        break;
                    case 'NotReadableError':
                        message = '<strong>The camera is already in use.</strong> '
                                + 'Close any other app using it, such as Zoom or Teams.';
                        break;
                    case 'OverconstrainedError':
                        message = '<strong>That camera could not be used.</strong> '
                                + 'Try selecting a different one.';
                        break;
                    default:
                        message = '<strong>The camera could not be started.</strong>';
                        detail  = err.message || err.name || '';
                }

                showError(message, detail);
            });
    }

    /* Offer a picker when the machine has more than one camera. */
    function listDevices() {
        if (!navigator.mediaDevices.enumerateDevices) { return; }

        navigator.mediaDevices.enumerateDevices().then(function (devices) {
            var cameras = devices.filter(function (d) { return d.kind === 'videoinput'; });

            if (cameras.length < 2) {
                $devices.attr('hidden', true);
                return;
            }

            $devices.empty();

            cameras.forEach(function (cam, i) {
                $('<option>')
                    .val(cam.deviceId)
                    // The label is empty until permission is granted.
                    .text(cam.label || ('Camera ' + (i + 1)))
                    .appendTo($devices);
            });

            $devices.removeAttr('hidden');
        });
    }

    /* ---------- Opening and closing ---------- */

    $(document).on('click', '.js-webcam-open', function () {
        targetInput = $('#' + $(this).data('input'));

        if (!isSecure) {
            $modal.removeAttr('hidden').attr('aria-hidden', 'false');
            $status.attr('hidden', true);
            $live.attr('hidden', true);

            showError(
                '<strong>The camera needs a secure connection.</strong> '
                + 'Browsers only allow camera access over HTTPS or on '
                + '<code>localhost</code>.<br><br>'
                + 'Open the site as <code>http://localhost/</code> instead and the camera will work.',
                'This page was opened as '
                + window.location.protocol + '//' + window.location.hostname + '.'
            );
            return;
        }

        $modal.removeAttr('hidden').attr('aria-hidden', 'false');
        $('body').addClass('modal-open');

        startCamera();
    });

    function closeModal() {
        stopCamera();
        $modal.attr('hidden', true).attr('aria-hidden', 'true');
        $('body').removeClass('modal-open');
        clearError();
    }

    $(document).on('click', '.js-webcam-close', closeModal);

    // Clicking the backdrop closes, clicking the dialog does not.
    $modal.on('click', function (e) {
        if (e.target === this) { closeModal(); }
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && !$modal.attr('hidden')) { closeModal(); }
    });

    $devices.on('change', function () {
        startCamera($(this).val());
    });

    $('#webcamMirror').on('change', function () {
        $video.toggleClass('is-mirrored', this.checked);
    });

    /* ---------- Capture ---------- */

    function takeShot() {
        var video  = $video[0];
        var canvas = $canvas[0];

        if (!video.videoWidth) {
            showError('The camera is not ready yet. Give it a moment and try again.');
            return;
        }

        canvas.width  = video.videoWidth;
        canvas.height = video.videoHeight;

        var ctx = canvas.getContext('2d');

        // If the preview was mirrored, capture it mirrored too, so the
        // photo matches what the person was looking at when they posed.
        if ($('#webcamMirror').is(':checked')) {
            ctx.translate(canvas.width, 0);
            ctx.scale(-1, 1);
        }

        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        ctx.setTransform(1, 0, 0, 1, 0, 0);

        $video.attr('hidden', true);
        $canvas.removeAttr('hidden');
        $live.attr('hidden', true);
        $shot.removeAttr('hidden');
    }

    $(document).on('click', '.js-webcam-shoot', function () {
        if (!$('#webcamTimer').is(':checked')) {
            takeShot();
            return;
        }

        var remaining = 3;

        $count.text(remaining).removeAttr('hidden');

        countdownId = window.setInterval(function () {
            remaining -= 1;

            if (remaining <= 0) {
                window.clearInterval(countdownId);
                countdownId = null;
                $count.attr('hidden', true);
                takeShot();
                return;
            }

            $count.text(remaining);
        }, 1000);
    });

    $(document).on('click', '.js-webcam-retake', function () {
        $canvas.attr('hidden', true);
        $video.removeAttr('hidden');
        $shot.attr('hidden', true);
        $live.removeAttr('hidden');
    });

    /* ---------- Hand the photo to the dropzone ---------- */

    $(document).on('click', '.js-webcam-use', function () {
        var canvas = $canvas[0];

        if (!targetInput || !targetInput.length) {
            closeModal();
            return;
        }

        // toBlob gives real JPEG bytes. Wrapping them in a File and
        // assigning to input.files means the server receives an
        // ordinary multipart upload and validates it as usual.
        canvas.toBlob(function (blob) {
            if (!blob) {
                showError('The photo could not be saved. Please try again.');
                return;
            }

            var stamp = new Date().toISOString().replace(/[:.]/g, '-');
            var file  = new File([blob], 'webcam-' + stamp + '.jpg', {
                type: 'image/jpeg',
                lastModified: Date.now()
            });

            var transfer = new DataTransfer();
            transfer.items.add(file);
            targetInput[0].files = transfer.files;

            // Let the dropzone run its own preview and validation.
            targetInput.trigger('change');

            closeModal();
        }, 'image/jpeg', 0.92);
    });

    // A tab left in the background should not keep the camera on.
    $(document).on('visibilitychange', function () {
        if (document.hidden && stream) { closeModal(); }
    });

});
