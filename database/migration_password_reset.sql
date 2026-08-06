-- ============================================================
-- Mobile2U - migration
--
-- Run this ONCE in phpMyAdmin:
--   1. Select the `mobile2u` database on the left
--   2. Open the SQL tab
--   3. Paste this whole file and press Go
--
-- IMPORTANT: phpMyAdmin STOPS at the first error. If you have already
-- run part of this file, re-running it will stop at "Duplicate column
-- name" and never reach the later sections. Either:
--   - run the individual section you still need, or
--   - use the split file database/migration_07_ereceipt.sql
--
-- Column types below deliberately match the existing schema
-- (users.id is INT(11) SIGNED, so the foreign key must be too).
-- ============================================================

USE `mobile2u`;


-- ------------------------------------------------------------
-- 1. Single-use password reset tokens  (Password Reset module)
--
-- Only the SHA-256 hash of the token is stored, so a leaked
-- database row cannot be replayed as a working reset link.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`         INT(11)   NOT NULL AUTO_INCREMENT,
    `user_id`    INT(11)   NOT NULL,
    `token_hash` CHAR(64)  NOT NULL,
    `expires_at` DATETIME  NOT NULL,
    `used_at`    DATETIME  NULL DEFAULT NULL,
    `created_at` DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_password_resets_token` (`token_hash`),
    KEY `idx_password_resets_user` (`user_id`),

    CONSTRAINT `fk_password_resets_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci;


-- ------------------------------------------------------------
-- 2. Stripe idempotency  (Payment module)
--
-- Remembers which Stripe checkout session created an order, so
-- refreshing the success page can never create a duplicate order.
--
-- If this errors with "Duplicate column name", the column already
-- exists and you can safely ignore it.
-- ------------------------------------------------------------
ALTER TABLE `orders`
    ADD COLUMN `stripe_session_id` VARCHAR(255) NULL DEFAULT NULL AFTER `shipping_address`,
    ADD UNIQUE KEY `uq_orders_stripe_session` (`stripe_session_id`);


-- ------------------------------------------------------------
-- 3. Helpful indexes for the search and filter features
--
-- If any of these errors with "Duplicate key name", it already
-- exists and you can safely ignore it.
-- ------------------------------------------------------------
ALTER TABLE `products` ADD KEY `idx_products_status`   (`status`);
ALTER TABLE `products` ADD KEY `idx_products_category` (`category_id`);
ALTER TABLE `orders`   ADD KEY `idx_orders_user`       (`user_id`);
ALTER TABLE `orders`   ADD KEY `idx_orders_status`     (`status`);
ALTER TABLE `cart`     ADD KEY `idx_cart_user`         (`user_id`);


-- ------------------------------------------------------------
-- 4. Sanity check
--
-- Every account must have a usable status, and every order must
-- use the lowercase ENUM values the PHP code compares against.
-- ------------------------------------------------------------
UPDATE `users`  SET `status` = 'active'  WHERE `status` IS NULL OR `status` = '';
UPDATE `orders` SET `status` = LOWER(`status`);




-- ------------------------------------------------------------
-- 5. Order Cancellation module  (Member)
--
-- Records who cancelled, when, and why. `cancelled_by` lets the
-- admin see at a glance whether the member cancelled it themselves
-- or whether staff did it from the admin panel.
--
-- If this errors with "Duplicate column name", it is already applied.
-- ------------------------------------------------------------
ALTER TABLE `orders`
    ADD COLUMN `cancel_reason` VARCHAR(100) NULL DEFAULT NULL AFTER `status`,
    ADD COLUMN `cancel_note`   VARCHAR(500) NULL DEFAULT NULL AFTER `cancel_reason`,
    ADD COLUMN `cancelled_at`  DATETIME     NULL DEFAULT NULL AFTER `cancel_note`,
    ADD COLUMN `cancelled_by`  ENUM('member','admin') NULL DEFAULT NULL AFTER `cancelled_at`;

-- Backfill any order that was already cancelled before this migration,
-- so the detail page never shows a blank cancellation panel.
UPDATE `orders`
   SET `cancelled_at` = COALESCE(`cancelled_at`, `created_at`),
       `cancelled_by` = COALESCE(`cancelled_by`, 'admin')
 WHERE `status` = 'cancelled';


-- ------------------------------------------------------------
-- 6. Order Status Update module  (Admin)
--
-- Audit trail: every status change is recorded with who made it,
-- what it changed from and to, and an optional note. The admin
-- order detail page renders this as a timeline.
--
-- changed_by is ON DELETE SET NULL so the history survives even if
-- an admin account is later removed.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_status_history` (
    `id`          INT(11) NOT NULL AUTO_INCREMENT,
    `order_id`    INT(11) NOT NULL,
    `from_status` ENUM('pending','processing','shipped','delivered','cancelled') NULL DEFAULT NULL,
    `to_status`   ENUM('pending','processing','shipped','delivered','cancelled') NOT NULL,
    `changed_by`  INT(11) NULL DEFAULT NULL,
    `actor_role`  ENUM('member','admin','system') NOT NULL DEFAULT 'admin',
    `note`        VARCHAR(500) NULL DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_osh_order` (`order_id`),
    KEY `idx_osh_created` (`created_at`),

    CONSTRAINT `fk_osh_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_osh_user`
        FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci;

-- Seed one "order placed" entry per existing order so the timeline
-- is never empty on sample data created before this migration.
INSERT INTO `order_status_history` (`order_id`, `from_status`, `to_status`, `changed_by`, `actor_role`, `note`, `created_at`)
SELECT o.`id`, NULL, 'pending', o.`user_id`, 'member', 'Order placed.', o.`created_at`
  FROM `orders` o
 WHERE NOT EXISTS (
        SELECT 1 FROM `order_status_history` h WHERE h.`order_id` = o.`id`
      );

-- And a second entry recording the status each order is in now.
INSERT INTO `order_status_history` (`order_id`, `from_status`, `to_status`, `changed_by`, `actor_role`, `note`, `created_at`)
SELECT o.`id`, 'pending', o.`status`, NULL, 'system', 'Backfilled from existing data.',
       COALESCE(o.`cancelled_at`, o.`created_at`)
  FROM `orders` o
 WHERE o.`status` <> 'pending'
   AND NOT EXISTS (
        SELECT 1 FROM `order_status_history` h
         WHERE h.`order_id` = o.`id` AND h.`to_status` = o.`status`
      );


-- ------------------------------------------------------------
-- 7. E-Receipt module
--
-- Tracks when a receipt was last sent and how many times, so the
-- order pages can show "Receipt sent on ..." and the resend button
-- can be rate limited.
--
-- If this errors with "Duplicate column name", it is already applied.
-- ------------------------------------------------------------
ALTER TABLE `orders`
    ADD COLUMN `receipt_no`        VARCHAR(30) NULL DEFAULT NULL AFTER `stripe_session_id`,
    ADD COLUMN `receipt_sent_at`   DATETIME    NULL DEFAULT NULL AFTER `receipt_no`,
    ADD COLUMN `receipt_sent_count` INT(11)    NOT NULL DEFAULT 0 AFTER `receipt_sent_at`,
    ADD UNIQUE KEY `uq_orders_receipt_no` (`receipt_no`);

-- Give every existing order a receipt number so old sample data
-- can also produce a receipt.  Format: MU-<year>-<zero padded id>
UPDATE `orders`
   SET `receipt_no` = CONCAT('MU-', DATE_FORMAT(`created_at`, '%Y'), '-', LPAD(`id`, 6, '0'))
 WHERE `receipt_no` IS NULL;


-- ------------------------------------------------------------
-- 8. Verify - run last, check the output looks sane
-- ------------------------------------------------------------
SELECT 'password_resets table' AS item, COUNT(*) AS rows_now FROM `password_resets`;
SELECT DISTINCT `status` AS order_statuses_in_use FROM `orders`;
SELECT DISTINCT `status` AS user_statuses_in_use  FROM `users`;
SELECT `id`, `status`, `cancel_reason`, `cancelled_by`, `cancelled_at`
  FROM `orders` WHERE `status` = 'cancelled' LIMIT 10;
SELECT `order_id`, `from_status`, `to_status`, `actor_role`, `created_at`
  FROM `order_status_history` ORDER BY `order_id`, `id` LIMIT 20;
SELECT `id`, `receipt_no`, `receipt_sent_at`, `receipt_sent_count` FROM `orders` LIMIT 10;
