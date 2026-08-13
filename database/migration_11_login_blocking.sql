-- ============================================================
-- Mobile2U - Temporary Login Blocking module
--
-- Its own file, because phpMyAdmin stops at the first error.
-- Usage: phpMyAdmin -> select mobile2u -> SQL tab -> paste -> Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- Login attempts
--
-- Both successes and failures are recorded, for two reasons:
--   1. A success is what clears the earlier failure count for that account
--   2. An admin can see a pattern like "this IP tried 20 times before succeeding"
--
-- email has no foreign key, because attempts against accounts that do not
-- exist must be recorded too. Recording only real addresses would let an
-- attacker infer which addresses are registered from whether a lock happens.
--
-- ip_address is VARCHAR(45): the longest IPv6 form is 45 characters.
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
-- Verify
-- ------------------------------------------------------------
SELECT COUNT(*) AS login_attempts_table_ready FROM `login_attempts`;
