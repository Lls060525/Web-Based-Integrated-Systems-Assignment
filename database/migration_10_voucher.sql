-- ============================================================
-- Mobile2U - Discount Voucher module
--
-- Its own file, because phpMyAdmin stops at the first error.
-- Usage: phpMyAdmin -> select mobile2u -> SQL tab -> paste -> Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 1. Vouchers
--
-- type = 'percent': value is a percentage (10 = 10%), max_discount caps it
-- type = 'fixed':   value is a flat amount, max_discount is unused
--
-- used_count is a denormalised counter used for an atomic conditional
--   UPDATE vouchers SET used_count = used_count + 1
--    WHERE id = ? AND (usage_limit IS NULL OR used_count < usage_limit)
-- update: zero rows affected means someone else took the last one, and
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
-- 2. Redemptions
--
-- One voucher per order at most, so order_id is UNIQUE.
-- This table also answers "how many times has this member used this
-- voucher", so no extra column is needed on users or vouchers.
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
-- 3. The order remembers its discount
--
-- voucher_code is a text snapshot: if the voucher is renamed or deleted,
-- past orders and receipts must still show which one was used.
-- subtotal is the pre-discount amount; total_amount stays as the amount paid.
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

-- Existing orders had no discount, so subtotal equals what was paid
UPDATE `orders` SET `subtotal` = `total_amount` WHERE `subtotal` IS NULL;


-- ------------------------------------------------------------
-- 4. Sample vouchers, for the demo
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

    -- expired, to demonstrate that an expired voucher is refused
    ('EXPIRED5',  'Expired test voucher',                 'fixed',    5.00,   0.00, NULL, NULL, 1,
     DATE_SUB(NOW(), INTERVAL 60 DAY), DATE_SUB(NOW(), INTERVAL 30 DAY), 'active'),

    -- fully used, to demonstrate the usage limit
    ('SOLDOUT',   'Fully redeemed test voucher',          'fixed',   10.00,   0.00, NULL,    0, 1,
     DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY), 'active');


-- ------------------------------------------------------------
-- 5. Verify
-- ------------------------------------------------------------
SELECT `code`, `type`, `value`, `min_spend`, `usage_limit`, `used_count`, `expires_at`, `status`
  FROM `vouchers` ORDER BY `id`;
