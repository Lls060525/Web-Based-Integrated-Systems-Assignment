<?php
// ============================================================
// includes/captcha_field.php
// Renders whichever CAPTCHA the configured driver needs.
// ============================================================

if (!function_exists('render_captcha_field')) {

    /**
     * @param string $formKey identifies the challenge in the session,
     *                        so two forms on one page cannot clash
     */
    function render_captcha_field(string $formKey): void
    {
        if (!captcha_enabled()) {
            return;
        }

        if (!captcha_ready()) {
            // Say so rather than silently letting bots through.
            echo '<div class="alert alert-info"><strong>CAPTCHA is not configured.</strong> '
               . e(captcha_status()) . '</div>';
            return;
        }

        if (CAPTCHA_DRIVER === 'recaptcha') {
            ?>
            <div class="form-group">
                <label>Verification <span class="required">*</span></label>
                <div class="g-recaptcha" data-sitekey="<?= e(RECAPTCHA_SITE_KEY) ?>"></div>
                <?php err('captcha'); ?>
            </div>
            <script src="https://www.google.com/recaptcha/api.js" async defer></script>
            <?php
            return;
        }

        // ---------- Local image challenge ----------
        ?>
        <div class="form-group captcha-group">
            <label for="captcha">
                Type the code shown <span class="required">*</span>
            </label>

            <div class="captcha-row">
                <div class="captcha-image-wrap">
                    <!-- The query string busts the cache on every render. -->
                    <img src="/api/captcha_image.php?form=<?= e($formKey) ?>&amp;t=<?= time() ?>"
                         alt="Verification code"
                         class="captcha-image js-captcha-image"
                         data-form="<?= e($formKey) ?>">

                    <button type="button" class="captcha-refresh js-captcha-refresh"
                            title="Show a different code" aria-label="New code">
                        <i class="fas fa-rotate-right"></i>
                    </button>
                </div>

                <input type="text" id="captcha" name="captcha"
                       class="form-control captcha-input<?= has_err('captcha') ? ' is-invalid' : '' ?>"
                       maxlength="<?= CAPTCHA_LENGTH ?>"
                       autocomplete="off" autocapitalize="off" spellcheck="false"
                       placeholder="<?= CAPTCHA_LENGTH ?> characters" required>
            </div>

            <small class="form-hint">
                Not case sensitive. Click the arrow for a different code.
            </small>
            <?php err('captcha'); ?>
        </div>
        <?php
    }
}
