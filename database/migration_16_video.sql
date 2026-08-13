-- ============================================================
-- Mobile2U - Product Video (YouTube) module
--
-- Its own file, because phpMyAdmin stops at the first error.
-- Usage: phpMyAdmin -> select mobile2u -> SQL tab -> paste -> Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- Product video
--
-- Only the video ID is stored, never the whole URL. Reasons:
--   1. YouTube has half a dozen URL shapes (watch / youtu.be / embed /
--      shorts / live, plus &t= timestamps and tracking parameters).
--      Normalising to an ID on the way in means one shape everywhere after.
--   2. Storing a URL means pasting an arbitrary user string into an iframe
--      src, which is an injection risk. An ID is [A-Za-z0-9_-]{11}, checked
--   3. Thumbnails, embeds and watch links are all built from the ID.
--
-- video_title is optional caption text shown beside the video.
-- ------------------------------------------------------------
ALTER TABLE `products`
    ADD COLUMN `video_id`    VARCHAR(20)  NULL DEFAULT NULL,
    ADD COLUMN `video_title` VARCHAR(120) NULL DEFAULT NULL;


-- ------------------------------------------------------------
-- Verify
-- ------------------------------------------------------------
SHOW COLUMNS FROM `products` LIKE 'video%';
