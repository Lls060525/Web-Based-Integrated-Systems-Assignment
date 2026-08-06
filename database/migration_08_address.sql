-- ============================================================
-- Mobile2U - Shipping Address Handling module
--
-- 独立档案，因为 phpMyAdmin 遇到第一个错误就会停。
-- 用法：phpMyAdmin → 选 mobile2u → SQL 分页 → 贴上 → Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 会员地址簿
--
-- is_default 用一个「同一会员只能有一笔为 1」的规则维护，
-- 由 PHP 在交易内保证（MySQL 没有部分唯一索引可用）。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `addresses` (
    `id`             INT(11)      NOT NULL AUTO_INCREMENT,
    `user_id`        INT(11)      NOT NULL,
    `label`          VARCHAR(30)  NOT NULL DEFAULT 'Home',
    `recipient_name` VARCHAR(100) NOT NULL,
    `phone`          VARCHAR(20)  NOT NULL,
    `line1`          VARCHAR(150) NOT NULL,
    `line2`          VARCHAR(150) NULL DEFAULT NULL,
    `postcode`       CHAR(5)      NOT NULL,
    `city`           VARCHAR(60)  NOT NULL,
    `state`          VARCHAR(40)  NOT NULL,
    `country`        VARCHAR(60)  NOT NULL DEFAULT 'Malaysia',
    `is_default`     TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME     NULL DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `idx_addresses_user` (`user_id`),
    KEY `idx_addresses_default` (`user_id`, `is_default`),

    CONSTRAINT `fk_addresses_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci;


-- ------------------------------------------------------------
-- 订单记住用了哪一笔地址。
--
-- orders.shipping_address 仍然保留完整文字快照 —— 会员之后改地址
-- 或删掉地址，历史订单上的收件资讯都不能跟着变。
-- 所以这里是 ON DELETE SET NULL，不是 CASCADE。
-- ------------------------------------------------------------
ALTER TABLE `orders`
    ADD COLUMN `shipping_address_id` INT(11) NULL DEFAULT NULL;

ALTER TABLE `orders`
    ADD KEY `idx_orders_address` (`shipping_address_id`),
    ADD CONSTRAINT `fk_orders_address`
        FOREIGN KEY (`shipping_address_id`) REFERENCES `addresses` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;


-- ------------------------------------------------------------
-- 确认
-- ------------------------------------------------------------
SELECT COUNT(*) AS addresses_table_ready FROM `addresses`;
SHOW COLUMNS FROM `orders` LIKE 'shipping_address_id';
