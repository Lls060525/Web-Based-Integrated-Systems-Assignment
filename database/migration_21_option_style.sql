-- ============================================================
-- Mobile2U - Make the spec picker behave like a product configurator
--
-- Run migration_20_spec_options.sql first, then this one.
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 1. Colour swatches
--
-- Hex is stored rather than a colour name, because "Midnight" and
-- "Starlight" mean nothing to a browser, and the same name is a different
-- colour on different models. NULL renders as a text tile, which is right
-- ------------------------------------------------------------
ALTER TABLE `product_spec_options`
    ADD COLUMN `swatch_hex` CHAR(7) NULL DEFAULT NULL AFTER `price_delta`;


-- ------------------------------------------------------------
-- 2. Which product photo this choice shows
--
-- Choosing blue switches to the blue photo, the most recognisable behaviour
-- on such a page. It points at product_photos rather than storing a filename,
-- so replacing or deleting a photo cannot leave a dead reference.
--
-- ON DELETE SET NULL: if the photo goes, the choice itself stays (blue is
-- still buyable), it just stops changing the picture.
-- ------------------------------------------------------------
ALTER TABLE `product_spec_options`
    ADD COLUMN `photo_id` INT(11) NULL DEFAULT NULL AFTER `swatch_hex`;

ALTER TABLE `product_spec_options`
    ADD KEY `idx_option_photo` (`photo_id`);

ALTER TABLE `product_spec_options`
    ADD CONSTRAINT `fk_option_photo`
        FOREIGN KEY (`photo_id`) REFERENCES `product_photos` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;


-- ------------------------------------------------------------
-- 3. Whether this choice is currently available
--
-- Marked sold out rather than deleted. Deleting would make the colour vanish
-- for customers and invalidate any cart line holding it. This greys it out
-- and it comes back with one click when restocked.
--
-- This is NOT a full colour-by-storage stock matrix; that needs a separate
-- combination table. This is a per-choice switch: enough, and honest about it.
-- ------------------------------------------------------------
ALTER TABLE `product_spec_options`
    ADD COLUMN `is_available` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_default`;


-- ------------------------------------------------------------
-- 4. Verify
-- ------------------------------------------------------------
SELECT COUNT(*) AS options_ready,
       SUM(`is_available`) AS available_now
  FROM `product_spec_options`;
