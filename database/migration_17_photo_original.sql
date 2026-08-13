-- ============================================================
-- Mobile2U - Restore Original Photo
--
-- Its own file, because phpMyAdmin stops at the first error.
-- Usage: phpMyAdmin -> select mobile2u -> SQL tab -> paste -> Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- Keep the original file so an edit can be undone
--
-- What original_filename means:
--   NULL     = never edited; filename IS the original
--   set      = edited; this holds the untouched original
--
-- Why NULL rather than filling both columns identically from the start:
--   "has this been edited" then needs no extra flag column. NULL already
--   says it, and it cannot disagree with filename.
--
-- edited_at is information for the admin only.
-- ------------------------------------------------------------
ALTER TABLE `product_photos`
    ADD COLUMN `original_filename` VARCHAR(255) NULL DEFAULT NULL AFTER `filename`,
    ADD COLUMN `edited_at`         DATETIME     NULL DEFAULT NULL AFTER `original_filename`;


-- ------------------------------------------------------------
-- Verify
-- ------------------------------------------------------------
SELECT `id`, `product_id`, `filename`, `original_filename`, `edited_at`
  FROM `product_photos` ORDER BY `product_id`, `sort_order` LIMIT 20;
