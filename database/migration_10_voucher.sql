-- ============================================================
-- Mobile2U - Discount Voucher module
--
-- 独立档案，因为 phpMyAdmin 遇到第一个错误就会停。
-- 用法：phpMyAdmin → 选 mobile2u → SQL 分页 → 贴上 → Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 1. 优惠券
--
-- type = 'percent' 时 value 是百分比 (10 = 10%)，max_discount 是封顶金额
-- type = 'fixed'   时 value 是固定折扣金额，max_discount 不使用
--
-- used_count 是冗余计数，用来做「先检查再扣」的原子性更新：
--   UPDATE vouchers SET used_count = used_count + 1
--    WHERE id = ? AND (usage_limit IS NULL OR used_count < usage_limit)
-- 影响 0 笔就代表刚好被别人抢走了，交易整笔回滚。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vouchers` (
    `id`             INT(11)       NOT NULL AUTO_INCREMENT,
    `code`           VARCHAR(30)   NOT NULL,
    `description`    VARCHAR(200)  NULL DEFAULT NULL,
    `type`           ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    `value`          DECIMAL(10,2) NOT NULL,
    `min_spend`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `max_discount`   DECIMAL(10,2) NULL DEFAULT NULL,
    `usage_limit`    INT(11)       NULL DEFAULT NULL,
    `used_count`     INT(11)       NOT NULL DEFAULT 0,
    `per_user_limit` INT(11)       NOT NULL DEFAULT 1,
    `starts_at`      DATETIME      NULL DEFAULT NULL,
    `expires_at`     DATETIME      NULL DEFAULT NULL,
    `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME      NULL DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_vouchers_code` (`code`),
    KEY `idx_vouchers_status` (`status`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci;


-- ------------------------------------------------------------
-- 2. 兑换纪录
--
-- 每笔订单最多用一张券，所以 order_id 设 UNIQUE。
-- 这张表同时用来算「这个会员用过这张券几次」，
-- 不必在 users 或 vouchers 上塞额外栏位。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `voucher_redemptions` (
    `id`              INT(11)       NOT NULL AUTO_INCREMENT,
    `voucher_id`      INT(11)       NOT NULL,
    `user_id`         INT(11)       NOT NULL,
    `order_id`        INT(11)       NOT NULL,
    `discount_amount` DECIMAL(10,2) NOT NULL,
    `redeemed_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_redemption_order` (`order_id`),
    KEY `idx_redemption_voucher_user` (`voucher_id`, `user_id`),

    CONSTRAINT `fk_redemption_voucher`
        FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_redemption_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_redemption_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci;


-- ------------------------------------------------------------
-- 3. 订单记住折扣
--
-- voucher_code 存文字快照 —— 券之后改名或被删掉，
-- 历史订单和收据上仍然要显示当时用的是哪一张。
-- subtotal 是折扣前金额，total_amount 维持原意（实付金额）。
-- ------------------------------------------------------------
ALTER TABLE `orders`
    ADD COLUMN `subtotal`        DECIMAL(10,2) NULL DEFAULT NULL,
    ADD COLUMN `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN `voucher_id`      INT(11)       NULL DEFAULT NULL,
    ADD COLUMN `voucher_code`    VARCHAR(30)   NULL DEFAULT NULL;

ALTER TABLE `orders`
    ADD KEY `idx_orders_voucher` (`voucher_id`),
    ADD CONSTRAINT `fk_orders_voucher`
        FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;

-- 既有订单没有折扣，subtotal 就等于实付金额
UPDATE `orders` SET `subtotal` = `total_amount` WHERE `subtotal` IS NULL;


-- ------------------------------------------------------------
-- 4. 范例优惠券，方便 demo
-- ------------------------------------------------------------
INSERT IGNORE INTO `vouchers`
    (`code`, `description`, `type`, `value`, `min_spend`, `max_discount`, `usage_limit`, `per_user_limit`, `starts_at`, `expires_at`, `status`)
VALUES
    ('WELCOME10', '10% off your order, capped at RM 100', 'percent', 10.00, 100.00, 100.00, 100, 1,
     DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_ADD(NOW(), INTERVAL 90 DAY), 'active'),

    ('SAVE50',    'RM 50 off when you spend RM 500',      'fixed',   50.00, 500.00, NULL,  50,  2,
     DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_ADD(NOW(), INTERVAL 90 DAY), 'active'),

    ('MEGA20',    '20% off, no cap, limited stock',       'percent', 20.00, 1000.00, NULL, 10,  1,
     DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY), 'active'),

    -- 已过期，用来示范「过期券会被拒绝」
    ('EXPIRED5',  'Expired test voucher',                 'fixed',    5.00,   0.00, NULL, NULL, 1,
     DATE_SUB(NOW(), INTERVAL 60 DAY), DATE_SUB(NOW(), INTERVAL 30 DAY), 'active'),

    -- 已用完，用来示范「用量上限」
    ('SOLDOUT',   'Fully redeemed test voucher',          'fixed',   10.00,   0.00, NULL,    0, 1,
     DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY), 'active');


-- ------------------------------------------------------------
-- 5. 确认
-- ------------------------------------------------------------
SELECT `code`, `type`, `value`, `min_spend`, `usage_limit`, `used_count`, `expires_at`, `status`
  FROM `vouchers` ORDER BY `id`;
