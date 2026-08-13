-- ============================================================
-- Mobile2U - Display style becomes an explicit setting, not an inference
--
-- Run migration_21_option_style.sql first, then this one.
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 1. How this spec is drawn
--
-- The old rule was: if any choice carries a colour, draw the group as swatches.
-- That was wrong. <input type="color"> posts #000000 even when nobody
-- touches it, it passed the hex check and was stored, so RAM options quietly
-- picked up a black swatch and RAM started rendering as colour circles.
--
-- How a spec is drawn is a property of the spec, not something to
--   tile   name above, price difference below (storage, warranty)
--   swatch a circle of colour (colour, and nothing else)
-- ------------------------------------------------------------
ALTER TABLE `spec_attributes`
    ADD COLUMN `render_style` ENUM('tile','swatch') NOT NULL DEFAULT 'tile' AFTER `is_selectable`;


-- ------------------------------------------------------------
-- 2. Mark the ones that genuinely are colours
--
-- Only names that look like a colour; everything else stays a tile.
-- ------------------------------------------------------------
UPDATE `spec_attributes`
   SET `render_style` = 'swatch'
 WHERE `code` IN ('colour', 'color')
    OR `name` LIKE '%Colour%'
    OR `name` LIKE '%Color%';


-- ------------------------------------------------------------
-- 3. Clear swatches wrongly stored on non-colour specs
--
-- These are the #000000 values the bug above left behind.
-- ------------------------------------------------------------
UPDATE `product_spec_options` o
  JOIN `spec_attributes` a ON a.id = o.attribute_id
   SET o.`swatch_hex` = NULL
 WHERE a.`render_style` <> 'swatch';


-- ------------------------------------------------------------
-- 4. Ask for the colour before the storage
--
-- Every phone site asks for the finish first, because the colour changes the
-- photograph and it is natural to see the object before configuring it.
-- ------------------------------------------------------------
UPDATE `spec_attributes`
   SET `sort_order` = 5
 WHERE `render_style` = 'swatch' AND `is_selectable` = 1;


-- ------------------------------------------------------------
-- 5. Verify
-- ------------------------------------------------------------
SELECT `render_style`, COUNT(*) AS attributes
  FROM `spec_attributes`
 GROUP BY `render_style`;

SELECT COUNT(*) AS stray_swatches_left
  FROM `product_spec_options` o
  JOIN `spec_attributes` a ON a.id = o.attribute_id
 WHERE a.`render_style` <> 'swatch' AND o.`swatch_hex` IS NOT NULL;
