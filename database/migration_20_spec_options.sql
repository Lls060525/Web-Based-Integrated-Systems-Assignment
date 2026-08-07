-- ============================================================
-- Mobile2U - Spec 弹性化 + 顾客可选规格（变体）
--
-- 先跑 migration_19_specs.sql，再跑这个。
-- phpMyAdmin 遇到第一个错误就会停，所以这是独立档案。
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 1. 商品专属规格
--
-- 原本 spec_attributes 只能绑分类，所以要加一个规格就得先去
-- 「分类栏位定义」新增，而且整个分类的商品表单都会多一栏。
--
-- 加上 product_id 之后就有三种範围：
--   product_id 有值            → 只属于这一个商品（想加就加）
--   category_id 有值           → 该分类的商品共用
--   两个都 NULL                → 全站通用（例如保固）
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
-- 2. 这个规格要不要让顾客在购买时选
-- ------------------------------------------------------------
ALTER TABLE `spec_attributes`
    ADD COLUMN `is_selectable` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_comparable`;


-- ------------------------------------------------------------
-- 3. 每个商品可选的选项
--
-- 为什么选项要绑「商品」而不是绑「规格栏位」：
--   同样是「颜色」，A 手机有黑/白，B 手机有蓝/绿。
--   把选项存在栏位定义上，就等于全部商品共用同一组颜色。
--
-- price_delta 让不同选项可以有价差（256GB 比 128GB 贵）。
-- 允许负数，因为「上一代配色 -50」也是合理的。
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
-- 4. 购物车要记住顾客选了什么
--
-- options_signature 是正规化过的字串，格式 "栏位id:值|栏位id:值"，
-- 而且一定照栏位 id 由小到大排。
--
-- 为什么要正规化：
--   「黑色 + 256GB」跟「256GB + 黑色」是同一件商品。
--   不排序的话会产生两个不同字串，购物车就会出现两行一模一样的
--   东西，数量也永远合併不起来。
--
-- 用 '' 而不是 NULL 代表「没有选项」，这样 WHERE ... = ? 就能直接
-- 比对；NULL = NULL 在 SQL 里是 NULL，不是 TRUE。
-- ------------------------------------------------------------
ALTER TABLE `cart`
    ADD COLUMN `options_signature` VARCHAR(255) NOT NULL DEFAULT '' AFTER `product_id`;

ALTER TABLE `cart`
    ADD KEY `idx_cart_line` (`user_id`, `product_id`, `options_signature`);


-- ------------------------------------------------------------
-- 5. 订单要「快照」顾客当时选的东西
--
-- 跟 price_at_purchase 同一个道理：选项之后可能被改名或删掉，
-- 但客人当时买的就是「太空灰」。存文字而不是外键，历史才不会
-- 被后来的编辑改写。
-- ------------------------------------------------------------
ALTER TABLE `order_items`
    ADD COLUMN `options_text` VARCHAR(255) NULL DEFAULT NULL AFTER `product_id`;


-- ------------------------------------------------------------
-- 6. 确认
-- ------------------------------------------------------------
SELECT COUNT(*) AS product_spec_options_ready FROM `product_spec_options`;
