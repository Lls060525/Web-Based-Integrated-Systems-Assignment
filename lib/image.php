<?php
// ============================================================
// lib/image.php
// Server-side image processing with GD.
//
// The processing genuinely happens in PHP, not as a CSS transform on
// the way out. The bytes on disk change, so the edited photo is the
// same everywhere it appears - listings, cart, receipts and email.
//
// EVERY OPERATION WRITES A NEW FILE and returns the new name.
// Overwriting the original filename looks correct on the server but
// the browser keeps showing its cached copy, so an edit appears to do
// nothing until a hard refresh. A new name sidesteps cache entirely
// and gives a natural undo point until the old file is deleted.
// ============================================================

/** True when the GD extension is available. */
function image_processing_ready(): bool
{
    return extension_loaded('gd') && function_exists('imagecreatetruecolor');
}

/**
 * Why GD is not loaded, in enough detail to actually fix it.
 *
 * "Uncomment extension=gd in php.ini" is the standard advice and it is
 * not enough, because a XAMPP machine has more than one php.ini and the
 * one you find first is usually not the one Apache reads. The CLI has
 * its own too, so `php -m` in a terminal can list gd while the web
 * server still has not got it.
 *
 * This reports the file PHP ACTUALLY loaded for this request -- which,
 * read from a page in the browser, is by definition Apache's copy.
 */
function gd_diagnosis(): array
{
    $loadedIni = php_ini_loaded_file();
    $extDir    = ini_get('extension_dir');

    // Windows names the file php_gd.dll from PHP 8.0; before that it was
    // php_gd2.dll. An extension= line naming the old one silently fails.
    $candidates = [];

    if (is_string($extDir) && $extDir !== '' && is_dir($extDir)) {
        foreach (['php_gd.dll', 'php_gd2.dll', 'gd.so'] as $file) {
            if (is_file(rtrim($extDir, '/\\') . DIRECTORY_SEPARATOR . $file)) {
                $candidates[] = $file;
            }
        }
    }

    return [
        'loaded'      => extension_loaded('gd'),
        'ini_file'    => $loadedIni === false || $loadedIni === '' ? null : $loadedIni,
        'scanned_dir' => php_ini_scanned_files() ?: null,
        'ext_dir'     => $extDir === false || $extDir === '' ? null : $extDir,
        'ext_files'   => $candidates,
        'sapi'        => PHP_SAPI,
        'php_version' => PHP_VERSION,
    ];
}

/** The GD hint, written for the machine it is running on. */
function gd_hint(): string
{
    $d = gd_diagnosis();

    if ($d['loaded']) {
        return '';
    }

    if ($d['ini_file'] === null) {
        return 'PHP is running with no php.ini at all. Copy php.ini-development '
             . 'to php.ini in your PHP folder, then enable GD in it.';
    }

    $parts = [];

    $parts[] = 'Edit THIS file - not any other php.ini on the machine: '
             . $d['ini_file'];

    $parts[] = 'Find the line ";extension=gd", remove the leading semicolon, save, '
             . 'then FULLY stop and start Apache (a restart sometimes leaves the old '
             . 'process running).';

    if ($d['ext_dir'] === null) {
        $parts[] = 'extension_dir is not set. Add: extension_dir = "C:\\xampp\\php\\ext"';
    } elseif ($d['ext_files'] === []) {
        $parts[] = 'Note: no GD library file was found in ' . $d['ext_dir']
                 . ', so this PHP build may not ship one.';
    }

    $parts[] = 'Reading this in a browser means the path above is the one Apache uses. '
             . 'A terminal running "php -m" reads a different file, so it is not a '
             . 'reliable check.';

    return implode(' ', $parts);
}

/** Which GD features this build actually has. */
function image_capabilities(): array
{
    if (!extension_loaded('gd')) {
        return [];
    }

    $info = function_exists('gd_info') ? gd_info() : [];

    return [
        'jpeg'   => function_exists('imagecreatefromjpeg'),
        'png'    => function_exists('imagecreatefrompng'),
        'gif'    => function_exists('imagecreatefromgif'),
        'webp'   => function_exists('imagecreatefromwebp'),
        'rotate' => function_exists('imagerotate'),
        'flip'   => function_exists('imageflip'),
        'filter' => function_exists('imagefilter'),
        'exif'   => function_exists('exif_read_data'),
        'version'=> $info['GD Version'] ?? 'unknown',
    ];
}

/** The operations offered in the editor. */
function image_operations(): array
{
    return [
        'rotate_left'  => ['label' => 'Rotate left',  'icon' => 'fa-rotate-left'],
        'rotate_right' => ['label' => 'Rotate right', 'icon' => 'fa-rotate-right'],
        'flip_h'       => ['label' => 'Flip across',  'icon' => 'fa-arrows-left-right'],
        'flip_v'       => ['label' => 'Flip down',    'icon' => 'fa-arrows-up-down'],
        'grayscale'    => ['label' => 'Grayscale',    'icon' => 'fa-circle-half-stroke'],
        'brighten'     => ['label' => 'Brighten',     'icon' => 'fa-sun'],
        'darken'       => ['label' => 'Darken',       'icon' => 'fa-moon'],
        'sharpen'      => ['label' => 'Sharpen',      'icon' => 'fa-wand-magic-sparkles'],
    ];
}

// ------------------------------------------------------------
// Loading and saving
// ------------------------------------------------------------

/**
 * Read an image file into a GD resource.
 *
 * @return array{0: \GdImage, 1: string}|null  [resource, extension]
 */
function image_load(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    // The real type comes from the file contents, never the extension.
    $info = @getimagesize($path);

    if ($info === false) {
        return null;
    }

    $image = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => @imagecreatefrompng($path),
        IMAGETYPE_GIF  => @imagecreatefromgif($path),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default        => false,
    };

    if ($image === false) {
        return null;
    }

    $ext = match ($info[2]) {
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
        default        => 'jpg',
    };

    // Preserve transparency for the formats that have it, otherwise
    // rotating a PNG fills the corners with solid black.
    if ($ext === 'png' || $ext === 'webp' || $ext === 'gif') {
        imagealphablending($image, false);
        imagesavealpha($image, true);
    }

    return [$image, $ext];
}

/** Write a GD resource out in the given format. */
function image_save(\GdImage $image, string $path, string $ext): bool
{
    return match ($ext) {
        'png'  => imagepng($image, $path, 8),
        'gif'  => imagegif($image, $path),
        'webp' => function_exists('imagewebp') ? imagewebp($image, $path, 90) : false,
        default => imagejpeg($image, $path, 90),
    };
}

/** A unique name in the same folder, keeping the extension. */
function image_new_filename(string $prefix, string $ext): string
{
    return $prefix . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
}

// ------------------------------------------------------------
// Operations
// ------------------------------------------------------------

/**
 * Apply one operation and write the result as a NEW file.
 *
 * @return string the new filename
 * @throws RuntimeException on any failure
 */
function process_image(string $dir, string $filename, string $operation): string
{
    if (!image_processing_ready()) {
        throw new RuntimeException('Image processing is unavailable: the PHP GD extension is not enabled.');
    }

    if (!array_key_exists($operation, image_operations())) {
        throw new RuntimeException('Unknown operation.');
    }

    // basename() stops a crafted filename escaping the folder.
    $path = $dir . basename($filename);

    $loaded = image_load($path);

    if ($loaded === null) {
        throw new RuntimeException('That file could not be read as an image.');
    }

    [$image, $ext] = $loaded;

    try {
        $result = apply_operation($image, $operation, $ext);

        $newName = image_new_filename('img', $ext);

        if (!image_save($result, $dir . $newName, $ext)) {
            throw new RuntimeException('The processed image could not be saved.');
        }

        return $newName;

    } finally {
        // GD resources are freed even when something throws.
        imagedestroy($image);

        if (isset($result) && $result instanceof \GdImage && $result !== $image) {
            imagedestroy($result);
        }
    }
}

/** The actual pixel work. */
function apply_operation(\GdImage $image, string $operation, string $ext): \GdImage
{
    switch ($operation) {

        case 'rotate_left':
        case 'rotate_right':
            // GD rotates anticlockwise, so "right" is a negative angle.
            $angle = $operation === 'rotate_left' ? 90 : -90;

            // A transparent background keeps PNG corners clean; JPEG has
            // no alpha so it falls back to white rather than black.
            $background = ($ext === 'jpg')
                ? imagecolorallocate($image, 255, 255, 255)
                : imagecolorallocatealpha($image, 0, 0, 0, 127);

            $rotated = imagerotate($image, $angle, $background);

            if ($rotated === false) {
                throw new RuntimeException('The image could not be rotated.');
            }

            if ($ext !== 'jpg') {
                imagealphablending($rotated, false);
                imagesavealpha($rotated, true);
            }

            return $rotated;

        case 'flip_h':
            imageflip($image, IMG_FLIP_HORIZONTAL);
            return $image;

        case 'flip_v':
            imageflip($image, IMG_FLIP_VERTICAL);
            return $image;

        case 'grayscale':
            imagefilter($image, IMG_FILTER_GRAYSCALE);
            return $image;

        case 'brighten':
            imagefilter($image, IMG_FILTER_BRIGHTNESS, 25);
            return $image;

        case 'darken':
            imagefilter($image, IMG_FILTER_BRIGHTNESS, -25);
            return $image;

        case 'sharpen':
            // A 3x3 convolution kernel. The values sum to 1 so the
            // overall brightness is unchanged; the negative neighbours
            // exaggerate the difference at edges.
            $kernel = [
                [ 0.0, -1.0,  0.0],
                [-1.0,  5.0, -1.0],
                [ 0.0, -1.0,  0.0],
            ];
            imageconvolution($image, $kernel, 1, 0);
            return $image;
    }

    throw new RuntimeException('Unknown operation.');
}

// ------------------------------------------------------------
// Automatic correction on upload
// ------------------------------------------------------------

/**
 * Rotate a JPEG upright using its EXIF orientation tag, then strip it.
 *
 * Phone cameras usually save the sensor image as-is and record how the
 * phone was held. Browsers honour that tag, but GD does not, so a photo
 * that looks upright in the file manager can appear sideways once it has
 * been processed. Baking the rotation in on upload means every later
 * operation starts from an image that is genuinely the right way up.
 *
 * @return bool true when the file was rewritten
 */
function auto_orient_image(string $path): bool
{
    if (!image_processing_ready() || !function_exists('exif_read_data')) {
        return false;
    }

    $info = @getimagesize($path);

    if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
        return false;   // only JPEG carries EXIF orientation
    }

    $exif = @exif_read_data($path);

    if ($exif === false || empty($exif['Orientation'])) {
        return false;
    }

    $orientation = (int)$exif['Orientation'];

    if ($orientation === 1) {
        return false;   // already upright
    }

    $image = @imagecreatefromjpeg($path);

    if ($image === false) {
        return false;
    }

    // The eight EXIF orientations, as a rotation plus an optional flip.
    $white = imagecolorallocate($image, 255, 255, 255);

    switch ($orientation) {
        case 2: imageflip($image, IMG_FLIP_HORIZONTAL); break;
        case 3: $image = imagerotate($image, 180, $white); break;
        case 4: imageflip($image, IMG_FLIP_VERTICAL); break;
        case 5: $image = imagerotate($image, -90, $white); imageflip($image, IMG_FLIP_HORIZONTAL); break;
        case 6: $image = imagerotate($image, -90, $white); break;
        case 7: $image = imagerotate($image, 90, $white); imageflip($image, IMG_FLIP_HORIZONTAL); break;
        case 8: $image = imagerotate($image, 90, $white); break;
        default: imagedestroy($image); return false;
    }

    if ($image === false) {
        return false;
    }

    // Saving through GD drops the EXIF block, so the tag cannot be
    // applied a second time by something else later.
    $ok = imagejpeg($image, $path, 90);
    imagedestroy($image);

    return $ok;
}

/** Width and height of a stored image, for display. */
function image_dimensions(string $dir, string $filename): ?array
{
    $info = @getimagesize($dir . basename($filename));

    if ($info === false) {
        return null;
    }

    return ['width' => $info[0], 'height' => $info[1]];
}
