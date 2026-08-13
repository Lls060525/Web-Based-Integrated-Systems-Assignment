-- ============================================================
-- Mobile2U - Product Specifications module
--
-- Its own file, because phpMyAdmin stops at the first error.
-- Usage: phpMyAdmin -> select mobile2u -> SQL tab -> paste -> Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 1. Specification attribute definitions
--
-- Why not just add columns to products (RAM, screen, battery...):
--   a phone needs RAM / storage / screen / battery,
--   a cable needs length / wattage / connector,
--   a watch needs strap size / water rating.
-- One wide table means most columns are NULL for most rows, and every new
-- spec is an ALTER TABLE, which locks the table in production.
--
-- So specs are DATA, not schema. This table defines which specs exist;
-- product_specs holds the value per product. That shape is called EAV.
--
-- category_id may be NULL, meaning it applies to every category (Warranty).
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
-- 2. The value of each spec for each product
--
-- Key design: the same value is stored twice.
--
--   value_text   always filled, used for display ("8", "AMOLED", "Yes")
--   value_number only for numeric types, used for filtering and sorting
--
-- Why both:
--   With only a VARCHAR, "RAM >= 8" has to be written as
--       WHERE CAST(value_text AS DECIMAL) >= 8
--   CAST(value_text AS DECIMAL) >= 8, which makes the index unusable, and
--   worse, as strings "12" < "8" character by character, so it is wrong.
--   A second DECIMAL column lets range queries use idx_spec_number.
--
--   A deliberate denormalisation: one duplicated value in exchange for a
--   correct answer and a usable index. Both columns are always written by
--   the same single function in lib/spec.php, so they cannot drift apart.
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

    -- One value per product per attribute.
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
-- 3. Sample attributes
--
-- category_id is looked up by category name. If the name is not found the
-- subquery yields NULL, meaning "applies to every category", so this still
-- succeeds even if your categories are named differently.
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
-- 4. Verify
-- ------------------------------------------------------------
SELECT COUNT(*) AS spec_attributes_ready FROM `spec_attributes`;
SELECT COUNT(*) AS product_specs_ready   FROM `product_specs`;
