-- ============================================================
-- Mobile2U - Product Rating + Review module
--
-- Its own file, because phpMyAdmin stops at the first error.
-- Usage: phpMyAdmin -> select mobile2u -> SQL tab -> paste -> Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- Product reviews
--
-- UNIQUE(user_id, product_id): one review per member per product.
-- They can edit their own; they cannot flood the page.
--
-- order_id records which order earned the right to review. That is the
-- verified-purchase proof: no purchase, no review.
-- ON DELETE SET NULL, so the review survives if the order is ever removed.
--
-- There are deliberately NO rating_avg / rating_count columns on products.
-- Reviews are written rarely, AVG() is fast enough on demand, and the
-- number can never disagree with the reviews themselves.
-- (Stock keeps a cached column because concurrent decrements need an atomic
--  conditional update. Reviews have no such need, so the trade-off differs.)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reviews` (
    `id`         INT(11)      NOT NULL AUTO_INCREMENT,
    `product_id` INT(11)      NOT NULL,
    `user_id`    INT(11)      NOT NULL,
    `order_id`   INT(11)      NULL DEFAULT NULL,
    `rating`     TINYINT(1)   NOT NULL,
    `title`      VARCHAR(120) NULL DEFAULT NULL,
    `body`       VARCHAR(1500) NOT NULL,
    `status`     ENUM('published','hidden') NOT NULL DEFAULT 'published',
    `admin_note` VARCHAR(200) NULL DEFAULT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NULL DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_review_user_product` (`user_id`, `product_id`),
    KEY `idx_reviews_product` (`product_id`, `status`),
    KEY `idx_reviews_rating` (`rating`),

    CONSTRAINT `fk_reviews_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_reviews_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_reviews_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,

    -- Rating must be 1 to 5, enforced by the database and not only by PHP.
    CONSTRAINT `chk_reviews_rating` CHECK (`rating` BETWEEN 1 AND 5)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci;


-- ------------------------------------------------------------
-- Verify
-- ------------------------------------------------------------
SELECT COUNT(*) AS reviews_table_ready FROM `reviews`;
