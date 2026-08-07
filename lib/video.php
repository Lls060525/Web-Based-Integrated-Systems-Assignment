<?php
// ============================================================
// lib/video.php
// YouTube video integration.
//
// Only the 11-character video ID is ever stored. An admin can paste
// any of YouTube's URL shapes and it is normalised on the way in.
//
// This matters for security as well as tidiness: putting whatever
// somebody typed straight into an iframe src is an injection hole.
// The ID is matched against [A-Za-z0-9_-]{11} before it is stored,
// so what reaches the page can only be a valid id.
// ============================================================

/** True once products.video_id exists (see the migration). */
function video_module_ready(): bool
{
    return db_column_exists('products', 'video_id');
}

/**
 * Pull the video id out of anything a person is likely to paste.
 *
 * Handles:
 *   https://www.youtube.com/watch?v=ID&t=42s
 *   https://youtu.be/ID?si=xxxx
 *   https://www.youtube.com/embed/ID
 *   https://www.youtube.com/shorts/ID
 *   https://www.youtube.com/live/ID
 *   https://m.youtube.com/watch?v=ID
 *   ID           (already just the id)
 *
 * @return string|null the id, or null when nothing valid was found
 */
function parse_youtube_id(string $input): ?string
{
    $input = trim($input);

    if ($input === '') {
        return null;
    }

    // Already a bare id.
    if (preg_match('~^[A-Za-z0-9_-]{11}$~', $input)) {
        return $input;
    }

    $patterns = [
        // watch?v=ID  (anywhere in the query string)
        '~[?&]v=([A-Za-z0-9_-]{11})~',
        // youtu.be/ID
        '~youtu\.be/([A-Za-z0-9_-]{11})~',
        // /embed/ID , /shorts/ID , /live/ID , /v/ID
        '~youtube\.com/(?:embed|shorts|live|v)/([A-Za-z0-9_-]{11})~',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $input, $matches)) {
            return $matches[1];
        }
    }

    return null;
}

/** Is this a valid stored id? */
function is_valid_video_id(?string $id): bool
{
    return $id !== null && $id !== '' && (bool)preg_match('~^[A-Za-z0-9_-]{11}$~', $id);
}

/**
 * Embed URL.
 *
 * youtube-nocookie.com is the privacy-enhanced host: YouTube does not
 * store viewing data in cookies until the video is actually played.
 */
function youtube_embed_url(string $id, bool $autoplay = true): string
{
    $params = [
        'rel'            => '0',   // no unrelated videos at the end
        'modestbranding' => '1',
        'playsinline'    => '1',
    ];

    if ($autoplay) {
        // Only set when the visitor has clicked, never on page load.
        $params['autoplay'] = '1';
    }

    return 'https://www.youtube-nocookie.com/embed/' . rawurlencode($id)
         . '?' . http_build_query($params);
}

/** The normal watch page, for a "watch on YouTube" link. */
function youtube_watch_url(string $id): string
{
    return 'https://www.youtube.com/watch?v=' . rawurlencode($id);
}

/** Poster image for the facade. */
function youtube_thumbnail_url(string $id): string
{
    return 'https://img.youtube.com/vi/' . rawurlencode($id) . '/hqdefault.jpg';
}
