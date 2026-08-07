<?php
// ============================================================
// lib/captcha.php
// CAPTCHA with a driver switch, the same shape as lib/mailer.php.
//
//   'image'     gregwar/captcha, rendered by PHP on this server
//   'recaptcha' Google reCAPTCHA v2
//   'off'       disabled
//
// The default is 'image' deliberately. reCAPTCHA is a third-party
// SERVICE: it needs an internet connection at the moment of the demo
// and keys tied to a domain. A composer package generating the image
// locally is still a third-party library, works offline, and cannot
// fail because of the network in the room.
//
// The answer never reaches the browser. Only a SHA-256 hash of the
// phrase is kept in the session, so reading the page source or the
// session file does not give the answer away.
// ============================================================

/** Is any CAPTCHA switched on? */
function captcha_enabled(): bool
{
    return CAPTCHA_DRIVER !== 'off';
}

/** Is the local image driver actually usable? */
function captcha_image_ready(): bool
{
    return class_exists(\Gregwar\Captcha\CaptchaBuilder::class) && image_processing_ready();
}

/** Whichever driver is configured, can it run right now? */
function captcha_ready(): bool
{
    if (!captcha_enabled()) {
        return false;
    }

    if (CAPTCHA_DRIVER === 'recaptcha') {
        return RECAPTCHA_SITE_KEY !== '' && RECAPTCHA_SITE_KEY !== 'your-site-key-here';
    }

    return captcha_image_ready();
}

/** Why the configured driver is not working, for the diagnostics page. */
function captcha_status(): string
{
    if (!captcha_enabled()) {
        return 'CAPTCHA is switched off in lib/config.php.';
    }

    if (CAPTCHA_DRIVER === 'recaptcha') {
        return captcha_ready()
            ? 'Google reCAPTCHA v2 is configured.'
            : 'reCAPTCHA keys have not been set in lib/config.php.';
    }

    if (!class_exists(\Gregwar\Captcha\CaptchaBuilder::class)) {
        return 'The gregwar/captcha package is not installed. Run: composer require gregwar/captcha';
    }

    if (!image_processing_ready()) {
        return 'The PHP GD extension is required to draw the challenge image.';
    }

    return 'Local image CAPTCHA is working.';
}

// ------------------------------------------------------------
// Local image driver
// ------------------------------------------------------------

/**
 * Make a new challenge and return the PNG bytes.
 *
 * The phrase is hashed into the session; the plain text is discarded
 * as soon as the image has been drawn.
 */
function captcha_build_image(string $formKey): ?string
{
    if (!captcha_image_ready()) {
        return null;
    }

    $builder = new \Gregwar\Captcha\CaptchaBuilder(null,
        new \Gregwar\Captcha\PhraseBuilder(CAPTCHA_LENGTH, 'abcdefghjkmnpqrstuvwxyz23456789')
    );
    // Characters that look alike (0/O, 1/l/I) are left out of the
    // alphabet above, because a CAPTCHA nobody can read is not security,
    // it is just a wall in front of your own customers.

    $builder->build(200, 60);

    $_SESSION['captcha'][$formKey] = [
        'hash'    => hash('sha256', strtolower($builder->getPhrase())),
        'created' => time(),
    ];

    ob_start();
    $builder->output();
    return (string)ob_get_clean();
}

/**
 * Check a typed answer.
 *
 * The challenge is consumed whether it passed or failed, so the same
 * image can never be answered twice and a wrong guess costs a reload.
 */
function captcha_check_image(string $formKey, string $answer): bool
{
    $stored = $_SESSION['captcha'][$formKey] ?? null;

    // One use only, right or wrong.
    unset($_SESSION['captcha'][$formKey]);

    if ($stored === null || $answer === '') {
        return false;
    }

    if (time() - (int)$stored['created'] > CAPTCHA_TTL) {
        return false;
    }

    return hash_equals($stored['hash'], hash('sha256', strtolower(trim($answer))));
}

// ------------------------------------------------------------
// reCAPTCHA driver
// ------------------------------------------------------------

/** Ask Google whether the token is genuine. */
function captcha_check_recaptcha(string $token): bool
{
    if ($token === '') {
        return false;
    }

    $postData = http_build_query([
        'secret'   => RECAPTCHA_SECRET_KEY,
        'response' => $token,
        'remoteip' => client_ip(),
    ]);

    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => $postData,
            'timeout'       => 10,
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);

    if ($raw === false) {
        // Google is unreachable. Failing closed would lock every visitor
        // out of registration, so this is logged and allowed through;
        // the other protections (CSRF, login blocking) still apply.
        error_log('reCAPTCHA verification could not reach Google.');
        return true;
    }

    $result = json_decode($raw, true);

    return is_array($result) && !empty($result['success']);
}

// ------------------------------------------------------------
// Public interface used by the pages
// ------------------------------------------------------------

/**
 * Validate whatever the configured driver expects.
 * Records an error against $field when it fails.
 */
function captcha_verify(string $formKey, string $field = 'captcha'): bool
{
    if (!captcha_ready()) {
        return true;   // never block a form because the driver is misconfigured
    }

    if (CAPTCHA_DRIVER === 'recaptcha') {
        $ok = captcha_check_recaptcha($_POST['g-recaptcha-response'] ?? '');

        if (!$ok) {
            add_err($field, 'Please confirm you are not a robot.');
        }

        return $ok;
    }

    $ok = captcha_check_image($formKey, post($field));

    if (!$ok) {
        add_err($field, 'That code was not correct. A new image has been generated.');
    }

    return $ok;
}

/** Forget a pending challenge, e.g. after a successful submit. */
function captcha_clear(string $formKey): void
{
    unset($_SESSION['captcha'][$formKey]);
}
