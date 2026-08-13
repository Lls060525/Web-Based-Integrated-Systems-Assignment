-- ============================================================
-- Mobile2U - Remember Me module
--
-- Its own file, because phpMyAdmin stops at the first error.
-- Usage: phpMyAdmin -> select mobile2u -> SQL tab -> paste -> Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- Long-lived sign-in tokens
--
-- Key design: selector and validator are split.
-- The cookie holds two parts: "selector:validator".
--
--   selector  stored in plain text with a UNIQUE index; its only job is to find the row
--   validator stored only as a SHA-256 hash, compared with hash_equals()
--
-- Why split them:
--   1. A single token that must also be searchable has to be stored in plain
--      text, so a database leak hands out working cookies. Storing a hash
--      instead is unindexable, forcing a full scan and leaking timing.
--      Splitting gives both: an indexed lookup on the selector, and a value
--      that is useless to anyone who reads the table.
--   2. hash_equals() is a constant-time comparison, which blocks timing attacks.
--
-- Only the validator rotates on each use. The selector deliberately stays
-- fixed (it identifies the device/series). That asymmetry is the point:
--   if the selector changed too, a stolen superseded cookie would match no
--   row at all and look exactly like an expired login, so theft would go undetected.
--   Fixed selector -> the row is still there -> a wrong validator is proof
--   that two browsers hold copies of the same cookie.
-- PHP then destroys every token for that account, whether the thief or the
-- real owner visits second.
-- expires_at is pushed forward on each use, so a device in regular use is
-- never logged out, while one untouched for 30 days lapses on its own.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `remember_tokens` (
    `id`             INT(11)      NOT NULL AUTO_INCREMENT,
    `user_id`        INT(11)      NOT NULL,
    `selector`       CHAR(24)     NOT NULL,
    `validator_hash` CHAR(64)     NOT NULL,
    `expires_at`     DATETIME     NOT NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at`   DATETIME     NULL DEFAULT NULL,
    `ip_address`     VARCHAR(45)  NULL DEFAULT NULL,
    `user_agent`     VARCHAR(255) NULL DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_remember_selector` (`selector`),
    KEY `idx_remember_user` (`user_id`),
    KEY `idx_remember_expiry` (`expires_at`),

    CONSTRAINT `fk_remember_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci;


-- ------------------------------------------------------------
-- Sweep out expired rows; this table needs periodic housekeeping anyway
-- ------------------------------------------------------------
DELETE FROM `remember_tokens` WHERE `expires_at` < NOW();


-- ------------------------------------------------------------
-- Verify
-- ------------------------------------------------------------
SELECT COUNT(*) AS remember_tokens_ready FROM `remember_tokens`;
