-- ============================================================
-- Mobile2U - Product Stock Handling module
--
-- 独立档案，因为 phpMyAdmin 遇到第一个错误就会停。
-- 用法：phpMyAdmin → 选 mobile2u → SQL 分页 → 贴上 → Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 1. 每个商品自己的补货警戒线
--
-- 原本「低库存」是写死的 5。不同商品的合理水位差很多：
-- 旗舰机可能剩 3 台就要补，配件剩 20 个才需要。
-- ------------------------------------------------------------
ALTER TABLE `products`
    ADD COLUMN `reorder_level` INT(11) NOT NULL DEFAULT 5;


-- ------------------------------------------------------------
-- 2. 库存异动流水帐
--
-- 注意：这里跟 point_transactions 的设计「刻意不同」。
--
-- 积分的余额是 SUM(points) 算出来的，没有快取栏位。
-- 库存则「保留」products.stock 当权威值，流水帐只是稽核轨迹。
--
-- 为什么不一致？因为并发写入的需求不同：
--   扣库存必须是原子的条件更新
--     UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?
--   这个「检查 + 扣减」一步完成，才不会两个人同时买到最后一件。
--   如果库存要靠 SUM 算，就得先查再写，中间必然有空隙。
--
-- 积分没有这个问题，因为兑换是在交易内序列化的单一使用者操作。
--
-- 代价是 products.stock 理论上可能跟流水帐总和对不上，
-- 所以 admin/stock.php 有一个对帐检查会把差异标出来。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stock_movements` (
    `id`          INT(11)      NOT NULL AUTO_INCREMENT,
    `product_id`  INT(11)      NOT NULL,
    `type`        ENUM('sale','return','restock','adjust','damage','initial') NOT NULL,
    `quantity`    INT(11)      NOT NULL,
    `stock_after` INT(11)      NOT NULL,
    `order_id`    INT(11)      NULL DEFAULT NULL,
    `reason`      VARCHAR(200) NOT NULL,
    `created_by`  INT(11)      NULL DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_movements_product_time` (`product_id`, `created_at`),
    KEY `idx_movements_order` (`order_id`),
    KEY `idx_movements_type` (`type`),

    CONSTRAINT `fk_movements_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_movements_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,

    CONSTRAINT `fk_movements_admin`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci;


-- ------------------------------------------------------------
-- 3. 给既有商品补一笔「期初库存」，流水帐才不会一片空白
-- 只补给还没有任何异动纪录的商品，重跑不会重复。
-- ------------------------------------------------------------
INSERT INTO `stock_movements` (`product_id`, `type`, `quantity`, `stock_after`, `reason`)
SELECT p.`id`, 'initial', p.`stock`, p.`stock`, 'Opening stock recorded when the module was installed'
  FROM `products` p
 WHERE NOT EXISTS (
        SELECT 1 FROM `stock_movements` m WHERE m.`product_id` = p.`id`
      );


-- ------------------------------------------------------------
-- 4. 确认
-- ------------------------------------------------------------
SELECT p.`id`, p.`name`, p.`stock`, p.`reorder_level`,
       COALESCE(SUM(m.`quantity`), 0) AS ledger_total,
       CASE WHEN p.`stock` = COALESCE(SUM(m.`quantity`), 0) THEN 'OK' ELSE 'MISMATCH' END AS reconciled
  FROM `products` p
  LEFT JOIN `stock_movements` m ON m.`product_id` = p.`id`
 GROUP BY p.`id`, p.`name`, p.`stock`, p.`reorder_level`
 ORDER BY p.`id`;
