-- ============================================================
-- Mobile2U - Temporary Login Blocking module
--
-- 独立档案，因为 phpMyAdmin 遇到第一个错误就会停。
-- 用法：phpMyAdmin → 选 mobile2u → SQL 分页 → 贴上 → Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 登入尝试纪录
--
-- 成功和失败都记录，理由有二：
--   1. 成功登入要用来清掉该帐号先前的失败计数
--   2. 管理员看得到「这个 IP 试了 20 次才成功」这种可疑模式
--
-- email 不设外键，因为要连「不存在的帐号」也一起记。
-- 如果只记真实存在的 email，攻击者就能靠「有没有被锁」
-- 反推哪些 email 有注册 —— 那就变成帐号列举的破口。
--
-- ip_address 用 VARCHAR(45)：IPv6 最长 45 个字元。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`           INT(11)      NOT NULL AUTO_INCREMENT,
    `email`        VARCHAR(100) NOT NULL,
    `ip_address`   VARCHAR(45)  NOT NULL,
    `user_agent`   VARCHAR(255) NULL DEFAULT NULL,
    `success`      TINYINT(1)   NOT NULL DEFAULT 0,
    `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_attempts_email_time` (`email`, `attempted_at`),
    KEY `idx_attempts_ip_time`    (`ip_address`, `attempted_at`),
    KEY `idx_attempts_time`       (`attempted_at`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci;


-- ------------------------------------------------------------
-- 确认
-- ------------------------------------------------------------
SELECT COUNT(*) AS login_attempts_table_ready FROM `login_attempts`;
