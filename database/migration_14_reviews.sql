-- ============================================================
-- Mobile2U - Product Rating + Review module
--
-- 独立档案，因为 phpMyAdmin 遇到第一个错误就会停。
-- 用法：phpMyAdmin → 选 mobile2u → SQL 分页 → 贴上 → Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 商品评价
--
-- UNIQUE(user_id, product_id)：一个会员对一件商品只能有一则评价。
-- 想改就改自己那一则，不能洗版。
--
-- order_id 记录「是哪一张订单让你有资格评价」。这就是
-- verified purchase 的凭证 —— 没买过就写不了。
-- 用 ON DELETE SET NULL，订单万一被删评价内容仍然保留。
--
-- 刻意「不」在 products 上放 rating_avg / rating_count 快取栏位。
-- 评价的写入频率极低，平均值用 AVG() 当场算完全够快，
-- 而且永远不会跟实际评价对不上。
-- (库存那边保留快取栏位是因为并发扣减需要原子条件更新，
--  评价没有那个需求，所以两边的取舍不同。)
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

    -- 星等只能是 1 到 5。资料库层挡住，不只靠 PHP。
    CONSTRAINT `chk_reviews_rating` CHECK (`rating` BETWEEN 1 AND 5)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci;


-- ------------------------------------------------------------
-- 确认
-- ------------------------------------------------------------
SELECT COUNT(*) AS reviews_table_ready FROM `reviews`;
