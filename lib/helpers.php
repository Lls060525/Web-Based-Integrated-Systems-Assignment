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
    return is_array($_POST[$key] ?? null) ? $default : trim((string)($_POST[$key] ?? $default));
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
    $v = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT);
    return ($v === false || $v === null) ? null : $v;
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

// ------------------------------------------------------------
// Value retention
// ------------------------------------------------------------

/**
 * Value that should currently be shown in a control:
 * the submitted value on a failed POST, otherwise the supplied default.
 */
function temp(string $key, $default = '')
{
    if (is_post() && isset($_POST[$key]) && !is_array($_POST[$key])) {
        return trim((string)$_POST[$key]);
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
function save_uploaded_image(string $key, string $targetDir, string $prefix): ?string
{
    if (!isset($_FILES[$key]) || $_FILES[$key]['error'] === UPLOAD_ERR_NO_FILE) {
        return null; // nothing uploaded - not an error by itself
    }

    $file = $_FILES[$key];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        add_err($key, 'Upload failed. Please try again.');
        return null;
    }

    if ($file['size'] > UPLOAD_MAX_SIZE) {
        add_err($key, 'File size must not exceed ' . (UPLOAD_MAX_SIZE / 1024 / 1024) . ' MB.');
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
