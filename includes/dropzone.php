<?php
// ============================================================
// includes/dropzone.php
// Reusable drag-and-drop file field.
//
// DESIGN: the dropped file is assigned to the real <input type="file">
// and travels with the normal form submit. It is NOT uploaded to a
// staging folder the moment it is dropped.
//
// Why: an upload-on-drop design creates an orphan file every time
// someone drops a photo and then abandons the form, and then needs a
// cleanup job to find them again. Assigning to the input means the
// existing server-side validation and storage code runs unchanged,
// and a cancelled form leaves nothing behind.
//
// The progress bar is real, not simulated: assets/js/dropzone.js
// submits the form through XMLHttpRequest and reports upload.onprogress.
//
// Without JavaScript the plain file input is still there and works.
// ============================================================

if (!function_exists('render_dropzone')) {

    /**
     * @param string  $name     the file input's name attribute
     * @param ?string $preview  URL of the current image, if any
     * @param array   $options  label, hint, accept, maxMb, shape
     */
    function render_dropzone(string $name, ?string $preview = null, array $options = []): void
    {
        $id      = 'dz_' . preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        $accept  = $options['accept'] ?? 'image/*';
        $maxMb   = $options['maxMb']  ?? (UPLOAD_MAX_SIZE / 1024 / 1024);
        $shape   = $options['shape']  ?? 'wide';      // 'wide' | 'round'
        $hint    = $options['hint']   ?? 'JPG, PNG, GIF or WEBP. Maximum ' . $maxMb . ' MB.';
        $hasErr  = has_err($name);

        // The round variant lives in narrow sidebars, so it stacks
        // vertically instead of trying to fit a row into 250px.
        $stacked = $options['stacked'] ?? ($shape === 'round');
        ?>
        <div class="dropzone <?= $stacked ? 'dropzone-stacked' : '' ?> <?= $hasErr ? 'is-invalid' : '' ?>"
             id="<?= e($id) ?>"
             data-input="<?= e($id) ?>_input"
             data-max-mb="<?= e((string)$maxMb) ?>">

            <!-- The real input. Kept in the DOM so the form works with
                 JavaScript disabled; the script only hides it. -->
            <input type="file"
                   id="<?= e($id) ?>_input"
                   name="<?= e($name) ?>"
                   accept="<?= e($accept) ?>"
                   class="dropzone-input">

            <div class="dropzone-body">
                <div class="dropzone-preview dropzone-preview-<?= e($shape) ?>">
                    <img src="<?= e($preview ?: URL_IMAGES . 'default-product.png') ?>"
                         alt="" class="dropzone-image"
                         <?= $preview ? '' : 'data-placeholder="1"' ?>>
                    <span class="dropzone-overlay">
                        <i class="fas fa-arrow-up-from-bracket"></i>
                    </span>
                </div>

                <div class="dropzone-text">
                    <strong class="dropzone-title">
                        Drag a photo here, or <button type="button" class="dropzone-browse">browse</button>
                    </strong>
                    <span class="dropzone-hint"><?= e($hint) ?></span>
                    <span class="dropzone-filename"></span>
                    <span class="dropzone-error err"></span>
                </div>

            </div>

            <button type="button" class="dropzone-clear btn-outline btn-sm" hidden>
                Remove photo
            </button>

            <div class="dropzone-progress" hidden>
                <div class="dropzone-progress-bar"></div>
                <span class="dropzone-progress-text">0%</span>
            </div>

            <div class="dropzone-veil">
                <span><i class="fas fa-hand-pointer"></i> Drop to upload</span>
            </div>
        </div>

        <?php err($name); ?>
        <?php
    }
}
