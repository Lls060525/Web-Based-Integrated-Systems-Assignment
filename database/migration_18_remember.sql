-- ============================================================
-- Mobile2U - Remember Me module
--
-- 独立档案，因为 phpMyAdmin 遇到第一个错误就会停。
-- 用法：phpMyAdmin → 选 mobile2u → SQL 分页 → 贴上 → Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 长期登入凭证
--
-- 关键设计：selector + validator 分离。
-- Cookie 里存的是 "selector:validator" 两段。
--
--   selector  明文存、加 UNIQUE 索引，只用来「找到那一列」
--   validator 只存 SHA-256 杂凑，用 hash_equals() 比对
--
-- 为什么要拆成两段：
--   1. 如果只有一个 token 又要能查询，就得明文存 —— 资料库一外泄，
--      每一个 cookie 都能立刻拿来登入。存杂凑又没办法用索引查，
--      只能整表扫描逐笔比对，慢而且会有时序差异。
--      拆开之后：查询用明文 selector（快、可索引），
--      验证用杂凑 validator（外泄也无法反推）。
--   2. hash_equals() 是定时比较，挡掉时序攻击。
--
-- 每次使用只轮换 validator，selector 故意保持不变（等于「device/series
-- 编号」）。这个不对称是重点：
--   如果连 selector 也换掉，被偷走的旧 cookie 就会查无此列，看起来
--   跟「过期」一模一样，偷窃就侦测不到了。
--   selector 不变 → 那一列还在 → validator 对不上就是铁证：
--   同一个 cookie 被两个浏览器同时持有。
-- 这时 PHP 会把该使用者「所有」凭证一次清空，不管是小偷先访问还是
-- 本人先访问都抓得到。
-- expires_at 每次使用会往后延，所以常用的装置不会突然被登出，
-- 放着 30 天不用的才会自己失效。
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
-- 顺手清掉过期的（这张表本来就该定期打扫）
-- ------------------------------------------------------------
DELETE FROM `remember_tokens` WHERE `expires_at` < NOW();


-- ------------------------------------------------------------
-- 确认
-- ------------------------------------------------------------
SELECT COUNT(*) AS remember_tokens_ready FROM `remember_tokens`;
