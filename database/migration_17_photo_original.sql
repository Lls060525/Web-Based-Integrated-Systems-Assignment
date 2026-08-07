-- ============================================================
-- Mobile2U - Restore Original Photo
--
-- 独立档案，因为 phpMyAdmin 遇到第一个错误就会停。
-- 用法：phpMyAdmin → 选 mobile2u → SQL 分页 → 贴上 → Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 保留原始档案，让编辑可以还原
--
-- original_filename 的语意：
--   NULL       = 从未编辑过，filename 本身就是原图
--   有值       = 已编辑过，这里存的是那张永远不会被动到的原图
--
-- 为什么用 NULL 而不是一开始就把两栏填一样：
--   「有没有被编辑过」这件事就不必再多一个 flag 栏位，
--   NULL 本身已经把状态讲完了，也不可能跟 filename 对不上。
--
-- edited_at 只是给管理员看的资讯。
-- ------------------------------------------------------------
ALTER TABLE `product_photos`
    ADD COLUMN `original_filename` VARCHAR(255) NULL DEFAULT NULL AFTER `filename`,
    ADD COLUMN `edited_at`         DATETIME     NULL DEFAULT NULL AFTER `original_filename`;


-- ------------------------------------------------------------
-- 确认
-- ------------------------------------------------------------
SELECT `id`, `product_id`, `filename`, `original_filename`, `edited_at`
  FROM `product_photos` ORDER BY `product_id`, `sort_order` LIMIT 20;
