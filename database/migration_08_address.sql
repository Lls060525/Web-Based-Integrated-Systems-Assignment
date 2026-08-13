-- ============================================================
-- Mobile2U - Shipping Address Handling module
--
-- Its own file, because phpMyAdmin stops at the first error.
-- Usage: phpMyAdmin -> select mobile2u -> SQL tab -> paste -> Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- Member address book
--
-- is_default follows a "one row per member may be 1" rule, enforced by
-- PHP inside a transaction (MySQL has no partial unique index).
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
-- The order remembers which address was used.
--
-- orders.shipping_address still keeps the full text snapshot: if the member
-- later edits or deletes the address, past orders must not change.
-- Hence ON DELETE SET NULL here, not CASCADE.
-- ------------------------------------------------------------
ALTER TABLE `orders`
    ADD COLUMN `shipping_address_id` INT(11) NULL DEFAULT NULL;

ALTER TABLE `orders`
    ADD KEY `idx_orders_address` (`shipping_address_id`),
    ADD CONSTRAINT `fk_orders_address`
        FOREIGN KEY (`shipping_address_id`) REFERENCES `addresses` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;


-- ------------------------------------------------------------
-- Verify
-- ------------------------------------------------------------
SELECT COUNT(*) AS addresses_table_ready FROM `addresses`;
SHOW COLUMNS FROM `orders` LIKE 'shipping_address_id';
