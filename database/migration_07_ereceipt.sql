-- ============================================================
-- Mobile2U - E-Receipt module only
--
-- 这个档案从完整 migration 切出来，只做 E-Receipt 需要的改动。
--
-- 为什么要单独一个档案：
--   phpMyAdmin 执行 SQL 时遇到第一个错误就会停止。
--   如果你已经跑过前面几段，重跑整个 migration 会卡在
--   「Duplicate column name」，后面的第 7 段永远执行不到。
--
-- 用法：phpMyAdmin → 选 mobile2u → SQL 分页 → 贴上 → Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 收据栏位
-- ------------------------------------------------------------
ALTER TABLE `orders`
    ADD COLUMN `receipt_no`         VARCHAR(30) NULL DEFAULT NULL,
    ADD COLUMN `receipt_sent_at`    DATETIME    NULL DEFAULT NULL,
    ADD COLUMN `receipt_sent_count` INT(11)     NOT NULL DEFAULT 0;

-- 收据编号要唯一
ALTER TABLE `orders`
    ADD UNIQUE KEY `uq_orders_receipt_no` (`receipt_no`);

-- ------------------------------------------------------------
-- 回填：让既有订单也有收据编号
-- 格式 MU-2026-000042
-- ------------------------------------------------------------
UPDATE `orders`
   SET `receipt_no` = CONCAT('MU-', DATE_FORMAT(`created_at`, '%Y'), '-', LPAD(`id`, 6, '0'))
 WHERE `receipt_no` IS NULL;

-- ------------------------------------------------------------
-- 确认
-- ------------------------------------------------------------
SELECT `id`, `receipt_no`, `receipt_sent_at`, `receipt_sent_count`
  FROM `orders`
 ORDER BY `id`
 LIMIT 10;
