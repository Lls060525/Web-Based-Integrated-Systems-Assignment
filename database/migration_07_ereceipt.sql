-- ============================================================
-- Mobile2U - E-Receipt module only
--
-- Split out of the full migration: only the E-Receipt changes.
--
-- Why a separate file:
--   phpMyAdmin stops executing at the first error.
--   If you have already run the earlier sections, re-running the whole
--   migration halts on "Duplicate column name" and never reaches section 7.
--
-- Usage: phpMyAdmin -> select mobile2u -> SQL tab -> paste -> Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- Receipt columns
-- ------------------------------------------------------------
ALTER TABLE `orders`
    ADD COLUMN `receipt_no`         VARCHAR(30) NULL DEFAULT NULL,
    ADD COLUMN `receipt_sent_at`    DATETIME    NULL DEFAULT NULL,
    ADD COLUMN `receipt_sent_count` INT(11)     NOT NULL DEFAULT 0;

-- Receipt numbers must be unique
ALTER TABLE `orders`
    ADD UNIQUE KEY `uq_orders_receipt_no` (`receipt_no`);

-- ------------------------------------------------------------
-- Backfill, so existing orders get a receipt number too
-- Format: MU-2026-000042
-- ------------------------------------------------------------
UPDATE `orders`
   SET `receipt_no` = CONCAT('MU-', DATE_FORMAT(`created_at`, '%Y'), '-', LPAD(`id`, 6, '0'))
 WHERE `receipt_no` IS NULL;

-- ------------------------------------------------------------
-- Verify
-- ------------------------------------------------------------
SELECT `id`, `receipt_no`, `receipt_sent_at`, `receipt_sent_count`
  FROM `orders`
 ORDER BY `id`
 LIMIT 10;
