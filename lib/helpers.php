<?php
// ============================================================
// lib/helpers.php
// Base library: request access, error handling, flash messages
// and the reusable HTML helpers used by every form in the app.
//
// Convention (as used in practical):
//   $_err  holds field-name => error-message
//   temp() returns the value that should be shown in a control
//   err()  prints the error message belonging to a control
// ============================================================

$_err = [];   // field errors collected by server-side validation
$_old = [];   // what was submitted last time, replayed after a failed POST (lib/prg.php)

// ------------------------------------------------------------
// Request helpers
// ------------------------------------------------------------

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function is_get(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'GET';
}

/** True when the request came from jQuery $.ajax(). */
function is_ajax(): bool
{
    return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/** Read a trimmed POST value. */
function post(string $key, string $default = ''): string
{
    if (array_key_exists($key, $_POST)) {
        return is_array($_POST[$key]) ? $default : trim((string)$_POST[$key]);
    }

    // Not a POST field. On the GET that follows a failed submission this
    // is where the member's previous answer comes back from, so forms
    // written as post('email') repopulate themselves with no change.
    // See lib/prg.php.
    global $_old;

    if (isset($_old[$key]) && !is_array($_old[$key])) {
        return trim((string)$_old[$key]);
    }

    return $default;
}

/**
 * Absolute base URL of this installation, no trailing slash.
 *
 * Was duplicated in lib/security.php and lib/receipt.php; QR codes need
 * it too, so it lives in one place now.
 */
function base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host;
}

/** Read a trimmed GET value. */
function get(string $key, string $default = ''): string
{
    return is_array($_GET[$key] ?? null) ? $default : trim((string)($_GET[$key] ?? $default));
}

/** Read an integer from GET, or null when missing/invalid. */
function get_int(string $key): ?int
{
    $v = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    return ($v === false || $v === null) ? null : $v;
}

/** Read an integer from POST, or null when missing/invalid. */
function post_int(string $key): ?int
{
    if (array_key_exists($key, $_POST)) {
        $v = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT);
        return ($v === false || $v === null) ? null : $v;
    }

    // Same fallback as post(): a replayed form still knows what was
    // chosen in its number and id fields.
    global $_old;

    if (isset($_old[$key]) && !is_array($_old[$key])) {
        $v = filter_var($_old[$key], FILTER_VALIDATE_INT);
        return $v === false ? null : $v;
    }

    return null;
}

/** Redirect and stop. */
function redirect(string $url = ''): void
{
    if ($url === '') {
        $url = $_SERVER['REQUEST_URI'] ?? '/';
    }

    /* Only ever redirect to a path on this site.
     *
     * Two ways an off-site redirect could otherwise slip in:
     *
     *   - "//evil.com" is a PROTOCOL-RELATIVE url. It looks like a path
     *     because it starts with a slash, but a browser reads it as an
     *     absolute address on another host. REQUEST_URI can be made to
     *     start with it, and that is the default value above.
     *   - "https://evil.com" if a caller ever passes one through from
     *     user input.
     *
     * An open redirect is what turns a link that genuinely begins on
     * this domain into a working phishing link, so the rule is that the
     * target must start with exactly one slash.
     */
    if (!preg_match('~^/(?!/)~', $url)) {
        $url = '/';
    }

    // header() has rejected newlines since PHP 5.1.2, so response
    // splitting is not possible here; the check above is about the
    // destination, not the header.
    header('Location: ' . $url);
    exit;
}

// ------------------------------------------------------------
// Output escaping
// ------------------------------------------------------------

/** Escape a value for safe HTML output. Use everywhere. */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Escape and echo. */
function pe($value): void
{
    echo e($value);
}

// ------------------------------------------------------------
// Error handling
// ------------------------------------------------------------

/** Record a validation error for a field. */
function add_err(string $key, string $message): void
{
    global $_err;
    if (!isset($_err[$key])) {
        $_err[$key] = $message;
    }
}

/** True when there are no validation errors at all. */
function no_err(): bool
{
    global $_err;
    return count($_err) === 0;
}

/** True when a specific field has an error. */
function has_err(string $key): bool
{
    global $_err;
    return isset($_err[$key]);
}

/** Print the error message belonging to a field. */
function err(string $key): void
{
    global $_err;
    if (isset($_err[$key])) {
        echo '<span class="err">' . e($_err[$key]) . '</span>';
    }
}

/** Print every error as one summary box (used at top of forms). */
function err_summary(): void
{
    global $_err;
    if (count($_err) === 0) {
        return;
    }
    echo '<div class="alert alert-error"><ul class="err-list">';
    foreach ($_err as $message) {
        echo '<li>' . e($message) . '</li>';
    }
    echo '</ul></div>';
}

// ------------------------------------------------------------
// Flash messages (survive one redirect - PRG pattern)
// ------------------------------------------------------------

function flash_success(string $message): void
{
    $_SESSION['flash_success'] = $message;
}

function flash_error(string $message): void
{
    $_SESSION['flash_error'] = $message;
}

/** Render and clear any pending flash message. */
function flash(): void
{
    if (!empty($_SESSION['flash_success'])) {
        echo '<div class="toast-message toast-success">' . e($_SESSION['flash_success']) . '</div>';
        unset($_SESSION['flash_success']);
    }
    if (!empty($_SESSION['flash_error'])) {
        echo '<div class="toast-message toast-error">' . e($_SESSION['flash_error']) . '</div>';
        unset($_SESSION['flash_error']);
    }
}

/**
 * One label/value row in a read-only information panel.
 *
 * Not a form field. Panels like "Account Information" were built out of
 * <input readonly disabled>, which looks approximately right and is
 * wrong in three ways: the value is clipped to whatever width the input
 * happens to have, the CSS that greys it out also sets
 * pointer-events: none so the text cannot even be SELECTED -- an admin
 * looking at a member could not copy their email address -- and a screen
 * reader announces a form control that can never be filled in.
 *
 * A definition list says what this actually is: a label and a value.
 *
 * @param bool $mono Use a monospaced face, for ids and coordinates where
 *                   the characters matter individually.
 */
function detail_row(string $label, $value, bool $mono = false): void
{
    $text = (string)$value;

    echo '<div class="info-row">'
       . '<dt class="info-label">' . e($label) . '</dt>'
       . '<dd class="info-value' . ($mono ? ' is-mono' : '') . '">'
       . ($text === '' ? '<span class="muted">Not set</span>' : e($text))
       . '</dd>'
       . '</div>';
}

// ------------------------------------------------------------
// Value retention
// ------------------------------------------------------------

/**
 * Value that should currently be shown in a control:
 * the submitted value on a failed POST, otherwise the supplied default.
 *
 * Every html_text/html_email/html_number/html_textarea/html_select goes
 * through here, which is why the PRG change needed no edits to any form.
 * Before, a failed submission re-rendered the page from the POST itself,
 * so $_POST was still populated. Now the page is fetched with a GET
 * after a redirect and $_POST is empty -- the values come back from the
 * copy parked in the session instead. See lib/prg.php.
 */
function temp(string $key, $default = '')
{
    if (is_post() && isset($_POST[$key]) && !is_array($_POST[$key])) {
        return trim((string)$_POST[$key]);
    }

    global $_old;

    if (isset($_old[$key]) && !is_array($_old[$key])) {
        return trim((string)$_old[$key]);
    }

    return $default;
}

// ------------------------------------------------------------
// HTML helpers - generate input controls
// ------------------------------------------------------------

/** Turn ['class' => 'x', 'required' => true] into an attribute string. */
function html_attr(array $attr): string
{
    $out = '';
    foreach ($attr as $name => $value) {
        if ($value === false || $value === null) {
            continue;
        }
        if ($value === true) {
            $out .= ' ' . e($name);
        } else {
            $out .= ' ' . e($name) . '="' . e($value) . '"';
        }
    }
    return $out;
}

/**
 * Generic <input> generator used by the typed helpers below.
 *
 * The id defaults to the field name but a caller may override it.
 *
 * This used to print id="$key" and THEN append the caller's attributes,
 * which emitted the attribute twice. A browser keeps the first one, so
 * passing ['id' => 'somethingElse'] silently had no effect and every
 * jQuery selector written against that id matched nothing. Pulling the
 * id out of $attr first is what makes the override real.
 */
function html_input(string $type, string $key, $value = '', array $attr = []): void
{
    $id = $attr['id'] ?? $key;
    unset($attr['id']);

    $attr['class'] = trim(($attr['class'] ?? 'form-control') . (has_err($key) ? ' is-invalid' : ''));
    echo '<input type="' . e($type) . '" id="' . e($id) . '" name="' . e($key) . '"'
       . ' value="' . e($value) . '"' . html_attr($attr) . '>';
}

function html_text(string $key, $default = '', array $attr = []): void
{
    html_input('text', $key, temp($key, $default), $attr);
}

function html_email(string $key, $default = '', array $attr = []): void
{
    html_input('email', $key, temp($key, $default), $attr);
}

/**
 * Password field with a show/hide toggle.
 *
 * The toggle is built in here rather than added page by page, so all
 * eleven password fields in the project get it from one change and
 * cannot drift apart.
 *
 * The button is type="button" on purpose: a bare <button> inside a form
 * defaults to type="submit", so leaving it off would make the eye icon
 * submit the login form.
 *
 * Pass ['toggle' => false] to leave it off.
 */
function html_password(string $key, array $attr = []): void
{
    $showToggle = $attr['toggle'] ?? true;
    unset($attr['toggle']);

    if (!$showToggle) {
        // Never echo a password back to the browser.
        html_input('password', $key, '', $attr);
        return;
    }

    $id = $attr['id'] ?? $key;

    echo '<div class="password-field">';

    // Never echo a password back to the browser.
    html_input('password', $key, '', $attr);

    // aria-pressed tells a screen reader whether the password is
    // currently visible; aria-label gives the button a name, since it
    // contains only an icon.
    echo '<button type="button" class="password-toggle"'
       . ' aria-label="Show password" aria-pressed="false"'
       . ' aria-controls="' . e($id) . '" tabindex="-1">'
       . '<i class="fas fa-eye" aria-hidden="true"></i>'
       . '</button>';

    echo '</div>';
}

function html_number(string $key, $default = '', array $attr = []): void
{
    html_input('number', $key, temp($key, $default), $attr);
}

function html_hidden(string $key, $value): void
{
    echo '<input type="hidden" name="' . e($key) . '" value="' . e($value) . '">';
}

function html_file(string $key, array $attr = []): void
{
    $id = $attr['id'] ?? $key;
    unset($attr['id']);

    $attr['class'] = trim(($attr['class'] ?? 'form-control') . (has_err($key) ? ' is-invalid' : ''));
    echo '<input type="file" id="' . e($id) . '" name="' . e($key) . '"' . html_attr($attr) . '>';
}

function html_textarea(string $key, $default = '', array $attr = []): void
{
    $id = $attr['id'] ?? $key;
    unset($attr['id']);

    $attr['class'] = trim(($attr['class'] ?? 'form-control') . (has_err($key) ? ' is-invalid' : ''));
    $attr['rows']  = $attr['rows'] ?? 4;
    echo '<textarea id="' . e($id) . '" name="' . e($key) . '"' . html_attr($attr) . '>'
       . e(temp($key, $default)) . '</textarea>';
}

/**
 * <select> built from an associative array [value => label].
 */
function html_select(string $key, array $items, $default = '', array $attr = [], string $placeholder = ''): void
{
    $id = $attr['id'] ?? $key;
    unset($attr['id']);

    $attr['class'] = trim(($attr['class'] ?? 'form-control') . (has_err($key) ? ' is-invalid' : ''));
    $selected = (string)temp($key, $default);

    echo '<select id="' . e($id) . '" name="' . e($key) . '"' . html_attr($attr) . '>';
    if ($placeholder !== '') {
        echo '<option value="">' . e($placeholder) . '</option>';
    }
    foreach ($items as $value => $label) {
        $isSel = ((string)$value === $selected) ? ' selected' : '';
        echo '<option value="' . e($value) . '"' . $isSel . '>' . e($label) . '</option>';
    }
    echo '</select>';
}

/** Submit button. */
function html_submit(string $label, array $attr = []): void
{
    $attr['class'] = $attr['class'] ?? 'btn-primary';
    echo '<button type="submit"' . html_attr($attr) . '>' . e($label) . '</button>';
}

/** Plain button (used with jQuery event handlers - never inline onclick). */
function html_button(string $label, array $attr = []): void
{
    $attr['class'] = $attr['class'] ?? 'btn-outline';
    echo '<button type="button"' . html_attr($attr) . '>' . e($label) . '</button>';
}

/**
 * Label + control + error message wrapped in one .form-group.
 * $control is a closure that renders the control itself.
 */
function field(string $key, string $label, callable $control, bool $required = false): void
{
    echo '<div class="form-group">';
    echo '<label for="' . e($key) . '">' . e($label);
    if ($required) {
        echo ' <span class="required">*</span>';
    }
    echo '</label>';
    $control();
    err($key);
    echo '</div>';
}

// ------------------------------------------------------------
// Formatting helpers
// ------------------------------------------------------------

/** Display label for an order status stored in the database. */
function order_status_label(string $status): string
{
    return ORDER_STATUS_LABELS[$status] ?? ucfirst($status);
}

/** Display label for an account status stored in the database. */
function user_status_label(string $status): string
{
    return USER_STATUS_LABELS[$status] ?? ucfirst($status);
}

function money($amount): string
{
    return 'RM ' . number_format((float)$amount, 2);
}

function fmt_date($value, string $format = 'd M Y'): string
{
    return $value ? date($format, strtotime($value)) : '-';
}

function fmt_datetime($value): string
{
    return fmt_date($value, 'd M Y, H:i');
}

/** Public URL for a product image, falling back to the placeholder. */
function product_image(?string $file): string
{
    if (empty($file) || $file === 'default-product.png') {
        return URL_IMAGES . 'default-product.png';
    }
    return URL_UPLOAD_PRODUCTS . rawurlencode($file);
}

/** Public URL for a user avatar, falling back to the placeholder. */
function avatar_image(?string $file): string
{
    if (empty($file) || $file === 'default-avatar.png') {
        return URL_IMAGES . 'default-avatar.png';
    }
    return URL_UPLOAD_AVATARS . rawurlencode($file);
}

// ------------------------------------------------------------
// File upload helper (shared by product photo and avatar upload)
// ------------------------------------------------------------

/**
 * Validate and store an uploaded image.
 * Returns the new file name on success, or null on failure
 * (the reason is recorded against $key via add_err()).
 */
function save_uploaded_image(string $key, string $targetDir, string $prefix, ?int $maxSize = null): ?string
{
    // Per-call ceiling. A product photo chosen on a desktop and a
    // photograph taken at a doorstep are not the same kind of file, and
    // one limit for both means one of them is wrong.
    $maxSize = $maxSize ?? UPLOAD_MAX_SIZE;

    if (!isset($_FILES[$key]) || $_FILES[$key]['error'] === UPLOAD_ERR_NO_FILE) {
        return null; // nothing uploaded - not an error by itself
    }

    $file = $_FILES[$key];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        // Say WHICH failure. "Upload failed, please try again" invites
        // somebody to try the same 6 MB photo four more times.
        add_err($key, upload_error_message($file['error'], $maxSize));
        return null;
    }

    if ($file['size'] > $maxSize) {
        add_err($key, 'That image is '
            . round($file['size'] / 1024 / 1024, 1) . ' MB. The limit is '
            . round($maxSize / 1024 / 1024, 1) . ' MB.');
        return null;
    }

    // Trust the real content type, not the file extension supplied by the browser.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, UPLOAD_ALLOWED_MIMES, true)) {
        add_err($key, 'Only JPG, PNG, GIF and WEBP images are allowed.');
        return null;
    }

    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    };

    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        add_err($key, 'Server error: upload folder is not writable.');
        return null;
    }

    $filename = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], $targetDir . $filename)) {
        add_err($key, 'Server error: failed to save the uploaded file.');
        return null;
    }

    // Phone photos carry an EXIF orientation tag that browsers honour but
    // GD ignores. Baking the rotation in now means the stored file is
    // genuinely upright, so every later operation starts from truth.
    // Defined in lib/image.php; a no-op when GD is unavailable.
    if (function_exists('auto_orient_image')) {
        auto_orient_image($targetDir . $filename);
    }

    return $filename;
}

/**
 * Turn a PHP upload error code into something worth reading.
 *
 * UPLOAD_ERR_INI_SIZE is the one that matters. It means the file was
 * bigger than php.ini's upload_max_filesize, which sits ABOVE anything
 * this application can configure -- raising EVIDENCE_MAX_SIZE does
 * nothing if php.ini still says 2M. XAMPP ships with 2M, so a phone
 * photo hits it immediately, and the message has to name the file to
 * edit or the reader has no way to know.
 */
function upload_error_message(int $code, int $appLimit): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
            $ini = php_ini_loaded_file();

            return 'The file is larger than PHP itself allows ('
                 . ini_get('upload_max_filesize') . '). This is a server setting, '
                 . 'not an application one: raise upload_max_filesize and post_max_size'
                 . ($ini ? ' in ' . $ini : ' in php.ini')
                 . ', then restart Apache.';

        case UPLOAD_ERR_FORM_SIZE:
            return 'The file is larger than this form allows ('
                 . round($appLimit / 1024 / 1024, 1) . ' MB).';

        case UPLOAD_ERR_PARTIAL:
            return 'Only part of the file arrived. This usually means the '
                 . 'connection dropped -- please try again.';

        case UPLOAD_ERR_NO_TMP_DIR:
            return 'The server has no temporary folder configured for uploads.';

        case UPLOAD_ERR_CANT_WRITE:
            return 'The server could not write the file to disk.';

        case UPLOAD_ERR_EXTENSION:
            return 'A PHP extension stopped the upload.';

        default:
            return 'Upload failed (error code ' . $code . '). Please try again.';
    }
}

/**
 * Did PHP discard this POST for exceeding post_max_size?
 *
 * When the request body is larger than post_max_size, PHP does not
 * report an error -- it silently throws away $_POST AND $_FILES and
 * carries on. Every downstream check then sees an empty form:
 *
 *   csrf_valid()  -> false -> "your session has expired"
 *   $_FILES       -> empty -> "a photograph is required"
 *
 * Both messages are wrong, and both send the reader somewhere useless.
 * The tell is a POST that announced a Content-Length but arrived with
 * nothing in it.
 *
 * post_max_size must be larger than upload_max_filesize, because the
 * body carries the file PLUS the other fields. Raising only one of them
 * is the usual mistake.
 */
function post_exceeded_limit(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return false;
    }

    if ($_POST !== [] || $_FILES !== []) {
        return false;   // something arrived, so it was not discarded
    }

    return (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
}

/** post_max_size in bytes, or 0 when it cannot be read. */
function post_max_bytes(): int
{
    return php_size_to_bytes((string)ini_get('post_max_size'));
}

/** Turn a php.ini shorthand size ("8M", "512K", "1G") into bytes. */
function php_size_to_bytes(string $value): int
{
    $value = trim($value);

    if ($value === '') {
        return 0;
    }

    $unit   = strtolower($value[strlen($value) - 1]);
    $number = (int)$value;

    return match ($unit) {
        'g'     => $number * 1024 * 1024 * 1024,
        'm'     => $number * 1024 * 1024,
        'k'     => $number * 1024,
        default => $number,
    };
}

/** Delete a previously uploaded file, ignoring the placeholders. */
function delete_uploaded_file(string $targetDir, ?string $file): void
{
    if (empty($file) || $file === 'default-avatar.png' || $file === 'default-product.png') {
        return;
    }
    $path = $targetDir . basename($file);
    if (is_file($path)) {
        @unlink($path);
    }
}
