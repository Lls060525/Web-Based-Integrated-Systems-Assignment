-- ============================================================
-- Mobile2U - 规格的显示方式改成「明确设定」而不是「自动推断」
--
-- 先跑 migration_21_option_style.sql，再跑这个。
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 1. 这个规格要怎么显示
--
-- 原本的做法是「只要有选项填了颜色，整组就变成色票」。
-- 那是错的：<input type="color"> 就算没去动它，送出来也是
-- #000000，通过 hex 检查后就被存起来 —— 于是 RAM 的选项拿到
-- 黑色色票，整组莫名其妙变成色票。
--
-- 显示方式是规格自己的属性，不该从选项的资料反推。
--   tile   一般磁贴：名称在上、价差在下（容量、保固）
--   swatch 颜色圆点（只有颜色需要）
-- ------------------------------------------------------------
ALTER TABLE `spec_attributes`
    ADD COLUMN `render_style` ENUM('tile','swatch') NOT NULL DEFAULT 'tile' AFTER `is_selectable`;


-- ------------------------------------------------------------
-- 2. 把看起来真的是颜色的那些标成 swatch
--
-- 只认名称像颜色的，其余一律留 tile。
-- ------------------------------------------------------------
UPDATE `spec_attributes`
   SET `render_style` = 'swatch'
 WHERE `code` IN ('colour', 'color')
    OR `name` LIKE '%Colour%'
    OR `name` LIKE '%Color%';


-- ------------------------------------------------------------
-- 3. 清掉误存到非颜色规格上的色票
--
-- 这些就是上面那个 bug 留下来的 #000000。
-- ------------------------------------------------------------
UPDATE `product_spec_options` o
  JOIN `spec_attributes` a ON a.id = o.attribute_id
   SET o.`swatch_hex` = NULL
 WHERE a.`render_style` <> 'swatch';


-- ------------------------------------------------------------
-- 4. 让顾客先选颜色再选容量
--
-- 手机网站的顺序都是「先挑外观，再挑规格」，因为颜色会换图，
-- 先看到实体长什么样比较自然。
-- ------------------------------------------------------------
UPDATE `spec_attributes`
   SET `sort_order` = 5
 WHERE `render_style` = 'swatch' AND `is_selectable` = 1;


-- ------------------------------------------------------------
-- 5. 确认
-- ------------------------------------------------------------
SELECT `render_style`, COUNT(*) AS attributes
  FROM `spec_attributes`
 GROUP BY `render_style`;

SELECT COUNT(*) AS stray_swatches_left
  FROM `product_spec_options` o
  JOIN `spec_attributes` a ON a.id = o.attribute_id
 WHERE a.`render_style` <> 'swatch' AND o.`swatch_hex` IS NOT NULL;
