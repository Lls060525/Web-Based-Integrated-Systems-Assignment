-- ============================================================
-- Mobile2U - Indexes for the original schema tables
--
-- Every table this project ADDED was indexed as it was created:
-- addresses, wishlist, vouchers, login_attempts, points, stock_movements,
-- reviews, product_photos, remember_tokens, spec_attributes and friends
-- all declare their keys in their own migration.
--
-- The six tables that came with the ORIGINAL schema never got the same
-- treatment: users, products, categories, orders, order_items and cart
-- have a PRIMARY KEY and nothing else. Every filter and every sort on
-- those tables is a full table scan.
--
-- That is invisible on twenty rows of sample data and it is the first
-- thing to go wrong on real volume, so it is worth fixing now rather
-- than discovering it during the demo.
--
-- ------------------------------------------------------------
-- SAFE TO RE-RUN.
--
-- A plain ALTER TABLE ... ADD KEY fails with "Duplicate key name" if the
-- index is already there, and phpMyAdmin stops at the first error, so one
-- already-present index would silently skip everything after it. The
-- procedure below checks information_schema first and does nothing if the
-- index exists. That also means it does not matter whether the original
-- schema happened to declare some of these already.
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 0. The helper
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS `add_index_if_missing`;

DELIMITER $$

CREATE PROCEDURE `add_index_if_missing`(
    IN p_table   VARCHAR(64),
    IN p_index   VARCHAR(64),
    IN p_columns VARCHAR(255)
)
BEGIN
    -- p_columns arrives backtick-quoted for the DDL; this is the same list
    -- as bare names, to compare against information_schema.
    DECLARE v_plain VARCHAR(255);

    SET v_plain = REPLACE(REPLACE(REPLACE(p_columns, '`', ''), ', ', ','), ' ', '');

    -- Three reasons to do nothing, all of them normal:
    --
    --   1. the table does not exist, because an optional module was never
    --      installed. Skipping beats aborting the whole file.
    --   2. an index with this name is already there, i.e. this migration
    --      has been run before.
    --   3. an index over exactly these columns is already there under a
    --      DIFFERENT name. InnoDB creates one automatically for every
    --      foreign key, so products.category_id and order_items.order_id
    --      may well already be covered. Adding a second index over the
    --      same columns costs disk and slows every INSERT for no gain.
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = p_table
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = p_table
           AND INDEX_NAME   = p_index
    ) AND NOT EXISTS (
        SELECT 1
          FROM (
            SELECT INDEX_NAME,
                   GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
              FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = p_table
             GROUP BY INDEX_NAME
          ) AS existing
         WHERE existing.cols = v_plain
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (', p_columns, ')');
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;


-- ------------------------------------------------------------
-- 1. products
--
-- The catalogue is the busiest query in the shop. It always filters on
-- status, then optionally on category and price, then sorts.
--
-- Column order matters and is not arbitrary. MySQL can only use a
-- composite index left to right, so (status, category_id) serves both
-- "WHERE status = 'active'" and "WHERE status = 'active' AND category_id = ?",
-- while (category_id, status) would serve only the second.
-- ------------------------------------------------------------
CALL add_index_if_missing('products', 'idx_products_status_category', '`status`, `category_id`');

-- Price filter and the price sorts. status first for the same reason:
-- every catalogue query has it, so it belongs at the front.
CALL add_index_if_missing('products', 'idx_products_status_price', '`status`, `price`');

-- Admin stock page sorts by stock ascending to surface what has run out.
CALL add_index_if_missing('products', 'idx_products_stock', '`stock`');

-- The join back to categories, and admin's per-category views.
CALL add_index_if_missing('products', 'idx_products_category', '`category_id`');

-- Sorting the catalogue by name.
CALL add_index_if_missing('products', 'idx_products_name', '`name`');


-- ------------------------------------------------------------
-- 2. orders
--
-- (user_id, created_at) is the important one: "my orders, newest first"
-- is the member's landing page, and the composite lets MySQL find the
-- rows AND return them already ordered, so there is no filesort.
-- ------------------------------------------------------------
CALL add_index_if_missing('orders', 'idx_orders_user_created', '`user_id`, `created_at`');

-- The admin list sorts every order by date with no user filter.
CALL add_index_if_missing('orders', 'idx_orders_created', '`created_at`');

-- Status filters on both the admin list and the member list.
CALL add_index_if_missing('orders', 'idx_orders_status', '`status`');


-- ------------------------------------------------------------
-- 3. order_items
--
-- order_id is looked up for every receipt, every order detail page and
-- the bulk "load all lines for these orders" query on the orders list.
-- product_id drives the top-selling report and the delete-safety check.
-- ------------------------------------------------------------
CALL add_index_if_missing('order_items', 'idx_order_items_order', '`order_id`');
CALL add_index_if_missing('order_items', 'idx_order_items_product', '`product_id`');


-- ------------------------------------------------------------
-- 4. users
--
-- The admin member list is always "role = 'member'" plus an optional
-- status, which is exactly (role, status).
--
-- email is looked up on every sign-in, every registration duplicate check
-- and every password reset. A plain index, deliberately not UNIQUE: the
-- application already refuses a duplicate address, and a UNIQUE
-- constraint added here would simply fail on any database that has
-- somehow acquired one, taking the rest of the migration with it.
-- ------------------------------------------------------------
CALL add_index_if_missing('users', 'idx_users_role_status', '`role`, `status`');
CALL add_index_if_missing('users', 'idx_users_email', '`email`');


-- ------------------------------------------------------------
-- 5. categories
--
-- Small table, but it is sorted by name on every page that renders the
-- category dropdown.
-- ------------------------------------------------------------
CALL add_index_if_missing('categories', 'idx_categories_name', '`name`');


-- ------------------------------------------------------------
-- 6. cart
--
-- migration_20 already added (user_id, product_id, options_signature),
-- and because user_id is leftmost that index already answers
-- "WHERE user_id = ?". Nothing further is needed here; this note exists
-- so the omission reads as deliberate rather than forgotten.
-- ------------------------------------------------------------


-- ------------------------------------------------------------
-- 7. Tidy up
--
-- The procedure was only ever scaffolding for this migration.
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS `add_index_if_missing`;


-- ------------------------------------------------------------
-- Verify
--
-- Run this afterwards to see what is now in place:
--
--   SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
--     FROM information_schema.STATISTICS
--    WHERE TABLE_SCHEMA = 'mobile2u'
--      AND TABLE_NAME IN ('users','products','categories','orders','order_items','cart')
--    GROUP BY TABLE_NAME, INDEX_NAME
--    ORDER BY TABLE_NAME, INDEX_NAME;
-- ------------------------------------------------------------
