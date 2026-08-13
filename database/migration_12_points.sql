-- ============================================================
-- Mobile2U - Reward Point module
--
-- Its own file, because phpMyAdmin stops at the first error.
-- Usage: phpMyAdmin -> select mobile2u -> SQL tab -> paste -> Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- Reward point ledger
--
-- There is deliberately NO points_balance column on users.
-- The balance is always SUM(points), because:
--   1. A single cached column drifts the moment one update fails, and once
--      it has drifted there is no way to find which transaction was wrong
--   2. The ledger is append-only: every point has a source, a use and a time
--   3. When reconciling, SUM is the single truth; there is no second number
--
-- points is signed: positive when earned, negative when redeemed.
-- balance_after is a human-readable snapshot only; nothing computes from it.
--
-- UNIQUE(order_id, type) makes double-awarding impossible: refreshing the
-- payment success page, or a resent webhook, cannot grant points twice.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `point_transactions` (
    `id`            INT(11)      NOT NULL AUTO_INCREMENT,
    `user_id`       INT(11)      NOT NULL,
    `order_id`      INT(11)      NULL DEFAULT NULL,
    `type`          ENUM('earn','redeem','refund','reverse','adjust') NOT NULL,
    `points`        INT(11)      NOT NULL,
    `balance_after` INT(11)      NOT NULL DEFAULT 0,
    `description`   VARCHAR(200) NOT NULL,
    `created_by`    INT(11)      NULL DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_points_order_type` (`order_id`, `type`),
    KEY `idx_points_user_time` (`user_id`, `created_at`),

    CONSTRAINT `fk_points_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_points_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,

    CONSTRAINT `fk_points_admin`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci;


-- ------------------------------------------------------------
-- The order remembers the points it earned and spent
-- ------------------------------------------------------------
ALTER TABLE `orders`
    ADD COLUMN `points_earned`   INT(11)       NOT NULL DEFAULT 0,
    ADD COLUMN `points_redeemed` INT(11)       NOT NULL DEFAULT 0,
    ADD COLUMN `points_discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00;


-- ------------------------------------------------------------
-- Give existing members a starting balance so redemption can be demoed
-- Only for members with no ledger entry yet, so re-running grants nothing twice.
-- ------------------------------------------------------------
INSERT INTO `point_transactions` (`user_id`, `order_id`, `type`, `points`, `balance_after`, `description`)
SELECT u.`id`, NULL, 'adjust', 500, 500, 'Welcome bonus'
  FROM `users` u
 WHERE u.`role` = 'member'
   AND u.`status` = 'active'
   AND NOT EXISTS (
        SELECT 1 FROM `point_transactions` t WHERE t.`user_id` = u.`id`
      );


-- ------------------------------------------------------------
-- Verify
-- ------------------------------------------------------------
SELECT u.`name`, u.`email`, COALESCE(SUM(t.`points`), 0) AS balance
  FROM `users` u
  LEFT JOIN `point_transactions` t ON t.`user_id` = u.`id`
 WHERE u.`role` = 'member'
 GROUP BY u.`id`, u.`name`, u.`email`
 ORDER BY u.`id`;
