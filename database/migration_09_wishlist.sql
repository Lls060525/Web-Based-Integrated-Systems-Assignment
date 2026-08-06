-- ============================================================
-- Mobile2U - Wishlist / Favorites module
--
-- 独立档案，因为 phpMyAdmin 遇到第一个错误就会停。
-- 用法：phpMyAdmin → 选 mobile2u → SQL 分页 → 贴上 → Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 会员收藏清单
--
-- (user_id, product_id) 设成 UNIQUE：同一个人不可能收藏同一件
-- 商品两次。这条约束让「重复点击爱心」在资料库层就被挡下，
-- 不必靠 PHP 先查再插那种会有 race condition 的写法。
--
-- 两边都是 CASCADE：会员删了或商品删了，收藏纪录跟着消失，
-- 因为收藏本身没有历史价值（不像订单要留快照）。
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
-- 确认
-- ------------------------------------------------------------
SELECT COUNT(*) AS wishlist_table_ready FROM `wishlist`;
