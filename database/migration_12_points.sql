-- ============================================================
-- Mobile2U - Reward Point module
--
-- 独立档案，因为 phpMyAdmin 遇到第一个错误就会停。
-- 用法：phpMyAdmin → 选 mobile2u → SQL 分页 → 贴上 → Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 积分流水帐 (ledger)
--
-- 刻意「不」在 users 上放一个 points_balance 栏位。
-- 余额一律用 SUM(points) 算出来，理由：
--   1. 单一栏位一旦有任何一次更新失败就会跟实际交易对不上，
--      而且对不上之后没有任何方法能查出是哪一笔出错
--   2. 流水帐是 append-only，每一分积分都有来源、去向和时间
--   3. 要对帐时 SUM 就是唯一事实，不会有两个数字互相矛盾
--
-- points 是有号数：赚取为正，兑换为负。
-- balance_after 只是给人看的快照，不参与计算。
--
-- UNIQUE(order_id, type) 保证同一张订单不可能重复发放积分 ——
-- 重新整理付款成功页、或 webhook 重送都不会多给。
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
-- 订单记住这笔交易的积分进出
-- ------------------------------------------------------------
ALTER TABLE `orders`
    ADD COLUMN `points_earned`   INT(11)       NOT NULL DEFAULT 0,
    ADD COLUMN `points_redeemed` INT(11)       NOT NULL DEFAULT 0,
    ADD COLUMN `points_discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00;


-- ------------------------------------------------------------
-- 给既有会员一点起始积分，方便 demo 兑换
-- 只发给还没有任何积分纪录的人，重跑不会重复发。
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
-- 确认
-- ------------------------------------------------------------
SELECT u.`name`, u.`email`, COALESCE(SUM(t.`points`), 0) AS balance
  FROM `users` u
  LEFT JOIN `point_transactions` t ON t.`user_id` = u.`id`
 WHERE u.`role` = 'member'
 GROUP BY u.`id`, u.`name`, u.`email`
 ORDER BY u.`id`;
