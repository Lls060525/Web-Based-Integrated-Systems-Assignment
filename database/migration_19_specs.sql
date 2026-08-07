-- ============================================================
-- Mobile2U - Product Specifications module
--
-- 独立档案，phpMyAdmin 遇到第一个错误就会停。
-- 用法：phpMyAdmin → 选 mobile2u → SQL → 贴上 → Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 1. 规格「栏位定义」
--
-- 为什么不是直接在 products 加栏位（RAM、螢幕、电池...）：
--   手机要 RAM / 储存 / 螢幕 / 电池，
--   传输线要 长度 / 瓦数 / 接头，
--   手錶要 錶带尺寸 / 防水等级。
-- 塞在同一张表 → 大部分栏位永远是 NULL，而且每加一个规格
-- 就要 ALTER TABLE 一次（正式环境上锁表）。
--
-- 所以规格是「资料」不是「结构」：这张表定义有哪些规格，
-- product_specs 存每个商品的值。这就是 EAV 模型。
--
-- category_id 可以是 NULL = 所有分类通用（例如「保固」）。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `spec_attributes` (
    `id`            INT(11)      NOT NULL AUTO_INCREMENT,
    `category_id`   INT(11)      NULL DEFAULT NULL,
    `name`          VARCHAR(80)  NOT NULL,
    `code`          VARCHAR(60)  NOT NULL,
    `data_type`     ENUM('text','number','boolean','enum') NOT NULL DEFAULT 'text',
    `unit`          VARCHAR(20)  NULL DEFAULT NULL,
    `options`       VARCHAR(500) NULL DEFAULT NULL,
    `is_filterable` TINYINT(1)   NOT NULL DEFAULT 0,
    `is_comparable` TINYINT(1)   NOT NULL DEFAULT 1,
    `sort_order`    INT(11)      NOT NULL DEFAULT 0,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_spec_code` (`category_id`, `code`),
    KEY `idx_spec_category` (`category_id`),
    KEY `idx_spec_sort` (`sort_order`),

    CONSTRAINT `fk_spec_category`
        FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci;


-- ------------------------------------------------------------
-- 2. 每个商品的规格值
--
-- 关键设计：同一个值存两份。
--
--   value_text   一律填，负责显示（"8 GB"、"AMOLED"、"Yes"）
--   value_number 只有 number 型别才填，负责筛选和排序
--
-- 为什么要两份：
--   如果只存 VARCHAR，"RAM >= 8" 这种查询要写成
--       WHERE CAST(value_text AS DECIMAL) >= 8
--   CAST 之后索引就用不到了，MySQL 只能整表扫描；而且
--   字串比大小时 "12" < "8"（逐字元比），答案根本是错的。
--   多存一个 DECIMAL 栏位，range 查询就能吃 idx_spec_number。
--
--   这是刻意的反正规化：多一份重复资料，换掉一次全表扫描。
--   两个栏位永远由 lib/spec.php 的同一个函式一起写入，
--   不会有只更新其中一个的情况。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_specs` (
    `id`           INT(11)        NOT NULL AUTO_INCREMENT,
    `product_id`   INT(11)        NOT NULL,
    `attribute_id` INT(11)        NOT NULL,
    `value_text`   VARCHAR(255)   NOT NULL,
    `value_number` DECIMAL(14,4)  NULL DEFAULT NULL,
    `updated_at`   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- 一个商品的同一个规格只能有一个值。
    UNIQUE KEY `uq_product_attribute` (`product_id`, `attribute_id`),

    KEY `idx_spec_value_product` (`product_id`),
    KEY `idx_spec_number` (`attribute_id`, `value_number`),
    KEY `idx_spec_text` (`attribute_id`, `value_text`),

    CONSTRAINT `fk_pspec_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_pspec_attribute`
        FOREIGN KEY (`attribute_id`) REFERENCES `spec_attributes` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci;


-- ------------------------------------------------------------
-- 3. 範例规格栏位
--
-- category_id 用子查询找分类名称。找不到就是 NULL，
-- 也就是「所有分类通用」—— 所以就算你的分类名称跟这里不同，
-- 这段也不会失败，只是变成通用规格而已。
-- ------------------------------------------------------------
INSERT INTO `spec_attributes`
    (`category_id`, `name`, `code`, `data_type`, `unit`, `options`, `is_filterable`, `is_comparable`, `sort_order`)
SELECT * FROM (
    SELECT (SELECT id FROM categories WHERE name = 'Smartphones' LIMIT 1) AS category_id,
           'Display Size' AS name, 'display_size' AS code, 'number' AS data_type,
           'inch' AS unit, NULL AS options, 1 AS is_filterable, 1 AS is_comparable, 10 AS sort_order
    UNION ALL SELECT (SELECT id FROM categories WHERE name = 'Smartphones' LIMIT 1),
           'Display Type', 'display_type', 'enum', NULL, 'AMOLED,OLED,LCD,IPS LCD', 1, 1, 20
    UNION ALL SELECT (SELECT id FROM categories WHERE name = 'Smartphones' LIMIT 1),
           'RAM', 'ram', 'number', 'GB', NULL, 1, 1, 30
    UNION ALL SELECT (SELECT id FROM categories WHERE name = 'Smartphones' LIMIT 1),
           'Storage', 'storage', 'number', 'GB', NULL, 1, 1, 40
    UNION ALL SELECT (SELECT id FROM categories WHERE name = 'Smartphones' LIMIT 1),
           'Battery Capacity', 'battery', 'number', 'mAh', NULL, 1, 1, 50
    UNION ALL SELECT (SELECT id FROM categories WHERE name = 'Smartphones' LIMIT 1),
           'Main Camera', 'main_camera', 'number', 'MP', NULL, 1, 1, 60
    UNION ALL SELECT (SELECT id FROM categories WHERE name = 'Smartphones' LIMIT 1),
           'Processor', 'processor', 'text', NULL, NULL, 0, 1, 70
    UNION ALL SELECT (SELECT id FROM categories WHERE name = 'Smartphones' LIMIT 1),
           'Operating System', 'os', 'enum', NULL, 'Android,iOS,HarmonyOS', 1, 1, 80
    UNION ALL SELECT (SELECT id FROM categories WHERE name = 'Smartphones' LIMIT 1),
           '5G Support', 'has_5g', 'boolean', NULL, NULL, 1, 1, 90
    UNION ALL SELECT (SELECT id FROM categories WHERE name = 'Accessories' LIMIT 1),
           'Cable Length', 'cable_length', 'number', 'm', NULL, 0, 1, 10
    UNION ALL SELECT (SELECT id FROM categories WHERE name = 'Accessories' LIMIT 1),
           'Power Output', 'power_output', 'number', 'W', NULL, 1, 1, 20
    UNION ALL SELECT NULL, 'Warranty', 'warranty', 'number', 'months', NULL, 0, 1, 900
    UNION ALL SELECT NULL, 'Colour', 'colour', 'text', NULL, NULL, 0, 1, 910
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM `spec_attributes` sa
     WHERE sa.code = seed.code
       AND (sa.category_id <=> seed.category_id)
);


-- ------------------------------------------------------------
-- 4. 确认
-- ------------------------------------------------------------
SELECT COUNT(*) AS spec_attributes_ready FROM `spec_attributes`;
SELECT COUNT(*) AS product_specs_ready   FROM `product_specs`;
