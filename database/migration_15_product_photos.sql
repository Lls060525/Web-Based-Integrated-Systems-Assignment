-- ============================================================
-- Mobile2U - Multiple Product Photos module
--
-- Its own file, because phpMyAdmin stops at the first error.
-- Usage: phpMyAdmin -> select mobile2u -> SQL tab -> paste -> Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- Product photo gallery
--
-- sort_order drives the display order; the storefront slider follows it.
-- is_primary marks the cover photo; only one per product.
--
-- About products.image: it is KEPT, as a denormalised cache of the cover.
--
-- Why not drop it? The cart, orders, wishlist, receipts and admin listings
-- all read p.image. Converting them all to join the gallery would be a large,
-- risky change, and each of those places only needs one thumbnail anyway.
--
-- The cost is keeping it in sync, so every function in lib/product_photo.php
-- that changes the gallery ends by calling sync_primary_photo(). That is the
-- only sync point; nothing else writes products.image.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_photos` (
    `id`         INT(11)      NOT NULL AUTO_INCREMENT,
    `product_id` INT(11)      NOT NULL,
    `filename`   VARCHAR(255) NOT NULL,
    `alt_text`   VARCHAR(150) NULL DEFAULT NULL,
    `sort_order` INT(11)      NOT NULL DEFAULT 0,
    `is_primary` TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_photos_product_order` (`product_id`, `sort_order`),
    KEY `idx_photos_primary` (`product_id`, `is_primary`),

    CONSTRAINT `fk_photos_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci;


-- ------------------------------------------------------------
-- Move each existing single photo into the gallery as its cover
-- Only products with no gallery row yet, so re-running adds nothing twice.
-- ------------------------------------------------------------
INSERT INTO `product_photos` (`product_id`, `filename`, `sort_order`, `is_primary`)
SELECT p.`id`, p.`image`, 0, 1
  FROM `products` p
 WHERE p.`image` IS NOT NULL
   AND p.`image` <> ''
   AND NOT EXISTS (
        SELECT 1 FROM `product_photos` pp WHERE pp.`product_id` = p.`id`
      );


-- ------------------------------------------------------------
-- Verify
-- ------------------------------------------------------------
SELECT p.`id`, p.`name`, p.`image` AS cover_column,
       COUNT(pp.`id`) AS photo_count,
       MAX(CASE WHEN pp.`is_primary` = 1 THEN pp.`filename` END) AS primary_in_gallery
  FROM `products` p
  LEFT JOIN `product_photos` pp ON pp.`product_id` = p.`id`
 GROUP BY p.`id`, p.`name`, p.`image`
 ORDER BY p.`id`;
