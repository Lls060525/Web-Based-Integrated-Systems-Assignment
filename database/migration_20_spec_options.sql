-- ============================================================
-- Mobile2U - Per-product specs and customer-selectable specs (variants)
--
-- Run migration_19_specs.sql first, then this one.
-- phpMyAdmin stops at the first error, so this is its own file.
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 1. Specs that belong to one product
--
-- spec_attributes could only be tied to a category, so adding one spec meant
-- defining it for the category, and every product in it grew an empty field.
--
-- With product_id there are now three scopes:
--   product_id set    -> this product only (add one whenever you like)
--   category_id set   -> shared by that category
--   both NULL         -> the whole shop (Warranty, for example)
-- ------------------------------------------------------------
ALTER TABLE `spec_attributes`
    ADD COLUMN `product_id` INT(11) NULL DEFAULT NULL AFTER `category_id`;

ALTER TABLE `spec_attributes`
    ADD KEY `idx_spec_product` (`product_id`);

ALTER TABLE `spec_attributes`
    ADD CONSTRAINT `fk_spec_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE;


-- ------------------------------------------------------------
-- 2. Whether the customer picks this spec when buying
-- ------------------------------------------------------------
ALTER TABLE `spec_attributes`
    ADD COLUMN `is_selectable` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_comparable`;


-- ------------------------------------------------------------
-- 3. The choices each product offers
--
-- Why the choices belong to the PRODUCT rather than to the attribute:
--   "Colour" means black/white on one phone and blue/green on another.
--   Storing the list on the definition would give every product one palette.
--
-- price_delta lets choices differ in price (256GB costs more than 128GB).
-- Negative is allowed: last year's colourway at -50 is a real thing.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_spec_options` (
    `id`           INT(11)       NOT NULL AUTO_INCREMENT,
    `product_id`   INT(11)       NOT NULL,
    `attribute_id` INT(11)       NOT NULL,
    `value_text`   VARCHAR(120)  NOT NULL,
    `price_delta`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `sort_order`   INT(11)       NOT NULL DEFAULT 0,
    `is_default`   TINYINT(1)    NOT NULL DEFAULT 0,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_product_option` (`product_id`, `attribute_id`, `value_text`),
    KEY `idx_option_product` (`product_id`, `sort_order`),

    CONSTRAINT `fk_option_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_option_attribute`
        FOREIGN KEY (`attribute_id`) REFERENCES `spec_attributes` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci;


-- ------------------------------------------------------------
-- 4. The cart remembers what the customer chose
--
-- options_signature is a canonical string, "attributeId:value|attributeId:value",
-- always sorted by attribute id ascending.
--
-- Why canonical:
--   "Black + 256GB" and "256GB + Black" are the same product.
--   Without a fixed order they produce two different strings, so the cart
--   shows two identical-looking lines whose quantities never merge.
--
-- '' rather than NULL means "no options", so WHERE ... = ? can match it;
-- NULL = NULL is NULL in SQL, not TRUE.
-- ------------------------------------------------------------
ALTER TABLE `cart`
    ADD COLUMN `options_signature` VARCHAR(255) NOT NULL DEFAULT '' AFTER `product_id`;

ALTER TABLE `cart`
    ADD KEY `idx_cart_line` (`user_id`, `product_id`, `options_signature`);


-- ------------------------------------------------------------
-- 5. The order snapshots what was chosen
--
-- Same reasoning as price_at_purchase: an option may later be renamed or
-- deleted, but the customer bought "Space Grey". Text, not a foreign key,
-- so a later edit cannot rewrite history.
-- ------------------------------------------------------------
ALTER TABLE `order_items`
    ADD COLUMN `options_text` VARCHAR(255) NULL DEFAULT NULL AFTER `product_id`;


-- ------------------------------------------------------------
-- 6. Verify
-- ------------------------------------------------------------
SELECT COUNT(*) AS product_spec_options_ready FROM `product_spec_options`;
