-- ============================================================
-- Mobile2U - Multiple Product Photos module
--
-- 独立档案，因为 phpMyAdmin 遇到第一个错误就会停。
-- 用法：phpMyAdmin → 选 mobile2u → SQL 分页 → 贴上 → Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 商品相簿
--
-- sort_order 决定显示顺序，前台的滑动图廊直接照这个排。
-- is_primary 标记封面照，同一商品只能有一张。
--
-- 关于 products.image：它「保留」下来，当作封面照的反正规化快取。
--
-- 为什么不直接砍掉？因为购物车、订单、收藏、收据、后台列表
-- 全都在读 p.image。全部改成 JOIN 相簿表风险高、改动大，
-- 而且那些地方只需要一张缩图，JOIN 反而是浪费。
--
-- 代价是要保持同步，所以 lib/product_photo.php 里所有会改动
-- 相簿的函式最后都会呼叫 sync_primary_photo()，只有那一个
-- 同步点，不会有第二个地方偷偷写 products.image。
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
-- 把既有的单张照片搬进相簿当封面
-- 只处理还没有任何相片纪录的商品，重跑不会重复。
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
-- 确认
-- ------------------------------------------------------------
SELECT p.`id`, p.`name`, p.`image` AS cover_column,
       COUNT(pp.`id`) AS photo_count,
       MAX(CASE WHEN pp.`is_primary` = 1 THEN pp.`filename` END) AS primary_in_gallery
  FROM `products` p
  LEFT JOIN `product_photos` pp ON pp.`product_id` = p.`id`
 GROUP BY p.`id`, p.`name`, p.`image`
 ORDER BY p.`id`;
