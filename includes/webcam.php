<?php
// ============================================================
// includes/webcam.php
// Webcam capture, rendered as a modal attached to a dropzone.
//
// DESIGN: the captured frame is turned into a File object and
// assigned to the SAME <input type="file"> the dropzone already
// uses. There is no separate "upload a base64 string" endpoint.
//
// Why that matters:
//   - the photo goes through save_uploaded_image() unchanged, so it
//     gets the same finfo MIME check and the same size limit
//   - no new endpoint means no new attack surface, and no second
//     copy of the validation rules to keep in step
//   - it works anywhere the dropzone works, for free
//
// The markup is inert until assets/js/webcam.js finds it, so a
// browser without getUserMedia simply never shows the button.
// ============================================================

if (!function_exists('render_webcam_modal')) {

    /**
     * Renders the shared modal once per page.
     * The button that opens it is added to each dropzone by the script.
     */
    function render_webcam_modal(): void
    {
        static $rendered = false;

        if ($rendered) {
            return;   // one modal serves every dropzone on the page
        }

        $rendered = true;
        ?>
        <div class="webcam-modal" id="webcamModal" hidden aria-hidden="true" role="dialog"
             aria-labelledby="webcamTitle">
            <div class="webcam-dialog">

                <div class="webcam-head">
                    <h3 id="webcamTitle"><i class="fas fa-camera"></i> Take a Photo</h3>
                    <button type="button" class="webcam-close js-webcam-close" aria-label="Close">
                        &times;
                    </button>
                </div>

                <div class="webcam-body">

                    <!-- Live preview -->
                    <div class="webcam-stage" id="webcamStage">
                        <video id="webcamVideo" class="webcam-video is-mirrored"
                               playsinline muted autoplay></video>

                        <canvas id="webcamCanvas" class="webcam-canvas" hidden></canvas>

                        <div class="webcam-countdown" id="webcamCountdown" hidden></div>

                        <div class="webcam-status" id="webcamStatus">
                            <i class="fas fa-spinner fa-spin"></i> Starting camera...
                        </div>
                    </div>

                    <!-- Anything that stops the camera working is explained here -->
                    <div class="webcam-error alert alert-error" id="webcamError" hidden></div>

                    <!-- Controls while previewing -->
                    <div class="webcam-controls" id="webcamLiveControls" hidden>
                        <select id="webcamDevice" class="form-control form-control-sm webcam-device" hidden></select>

                        <label class="check-label webcam-mirror-toggle">
                            <input type="checkbox" id="webcamMirror" checked>
                            <span>Mirror</span>
                        </label>

                        <label class="check-label webcam-timer-toggle">
                            <input type="checkbox" id="webcamTimer">
                            <span>3s timer</span>
                        </label>

                        <button type="button" class="btn-primary js-webcam-shoot">
                            <i class="fas fa-camera"></i> Capture
                        </button>
                    </div>

                    <!-- Controls after a shot has been taken -->
                    <div class="webcam-controls" id="webcamShotControls" hidden>
                        <button type="button" class="btn-outline js-webcam-retake">
                            <i class="fas fa-rotate-left"></i> Retake
                        </button>
                        <button type="button" class="btn-primary js-webcam-use">
                            <i class="fas fa-check"></i> Use This Photo
                        </button>
                    </div>

                    <p class="muted small-note webcam-note">
                        The photo is taken in your browser and only leaves this page when
                        you submit the form. The camera is released as soon as you close
                        this window.
                    </p>
                </div>
            </div>
        </div>
        <?php
    }
}
