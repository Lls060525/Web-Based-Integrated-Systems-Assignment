-- ============================================================
-- Mobile2U - 让规格选择长得像 Apple 的产品配置页
--
-- 先跑 migration_20_spec_options.sql，再跑这个。
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 1. 色票
--
-- 存 hex 而不是「颜色名称」，因为「午夜色」「星光色」这种名字
-- 浏览器不认得，而且同一个名字在不同型号是不同的颜色。
-- 留 NULL 就照旧显示成文字按钮 —— 容量、保固这种本来就没有颜色。
-- ------------------------------------------------------------
ALTER TABLE `product_spec_options`
    ADD COLUMN `swatch_hex` CHAR(7) NULL DEFAULT NULL AFTER `price_delta`;


-- ------------------------------------------------------------
-- 2. 这个选项对应哪一张商品照片
--
-- 选蓝色就换成蓝色那张图，这是 Apple 页面上最明显的一个行为。
-- 直接指到 product_photos，不另外存档名，这样照片被换掉或删掉时
-- 不会留下指向不存在档案的死连结。
--
-- ON DELETE SET NULL：照片被删掉时，选项本身要留着（客人还是买得到
-- 蓝色），只是不再换图而已。
-- ------------------------------------------------------------
ALTER TABLE `product_spec_options`
    ADD COLUMN `photo_id` INT(11) NULL DEFAULT NULL AFTER `swatch_hex`;

ALTER TABLE `product_spec_options`
    ADD KEY `idx_option_photo` (`photo_id`);

ALTER TABLE `product_spec_options`
    ADD CONSTRAINT `fk_option_photo`
        FOREIGN KEY (`photo_id`) REFERENCES `product_photos` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;


-- ------------------------------------------------------------
-- 3. 这个选项现在还买不买得到
--
-- 不是删掉，而是标成缺货。删掉的话客人下次就找不到这个颜色了，
-- 而且已经在购物车里的那一行会变成无效；标缺货则是灰掉、不能选，
-- 补货后一个勾就回来。
--
-- 这不是完整的「颜色 x 容量」库存矩阵 —— 那需要另一张组合表。
-- 这里是每个选项各自的开关，够用而且诚实。
-- ------------------------------------------------------------
ALTER TABLE `product_spec_options`
    ADD COLUMN `is_available` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_default`;


-- ------------------------------------------------------------
-- 4. 确认
-- ------------------------------------------------------------
SELECT COUNT(*) AS options_ready,
       SUM(`is_available`) AS available_now
  FROM `product_spec_options`;
