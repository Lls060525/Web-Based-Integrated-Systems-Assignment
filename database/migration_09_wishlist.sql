-- ============================================================
-- Mobile2U - Wishlist / Favorites module
--
-- Its own file, because phpMyAdmin stops at the first error.
-- Usage: phpMyAdmin -> select mobile2u -> SQL tab -> paste -> Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- Member wishlist
--
-- (user_id, product_id) is UNIQUE: one person cannot save the same
-- product twice. The constraint blocks a double-clicked heart at the
-- database level, instead of a check-then-insert in PHP that races.
--
-- CASCADE on both sides: if the member or the product goes, the saved
-- entry goes too. A wishlist has no historical value, unlike an order.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wishlist` (
    `id`         INT(11)  NOT NULL AUTO_INCREMENT,
    `user_id`    INT(11)  NOT NULL,
    `product_id` INT(11)  NOT NULL,
    `added_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wishlist_user_product` (`user_id`, `product_id`),
    KEY `idx_wishlist_user` (`user_id`),
    KEY `idx_wishlist_product` (`product_id`),

    CONSTRAINT `fk_wishlist_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_wishlist_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci;


-- ------------------------------------------------------------
-- Verify
-- ------------------------------------------------------------
SELECT COUNT(*) AS wishlist_table_ready FROM `wishlist`;
