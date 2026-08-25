-- ============================================================
-- Mobile2U - How the order was paid
--
-- checkout.php has always offered three ways to pay:
--
--     'payment_method_types' => ['card', 'fpx', 'grabpay']
--
-- but nothing recorded which one the customer actually used, so the
-- receipt could only say an amount and never how it was settled. For a
-- Malaysian shop that is the missing line: "FPX (Maybank2u)" is how a
-- customer recognises their own payment on a bank statement.
--
-- TWO COLUMNS, NOT ONE
--
--   payment_method  the family        card | fpx | grabpay
--   payment_detail  the instrument    Maybank2u | Visa ****4242
--
-- Split because they answer different questions. The family is a small
-- closed set worth grouping and reporting on -- "how many orders came
-- through FPX this month" is one GROUP BY. The detail is free text that
-- only ever gets shown to a human, and its shape differs per family, so
-- forcing both into one column would mean parsing a string apart again
-- every time either was wanted.
--
-- payment_detail IS A SNAPSHOT
--
-- The same reasoning as voucher_code and order_items.options_text
-- elsewhere in this schema: it stores what was true at the moment of
-- payment. Stripe may rename a bank, and a card certainly expires, but
-- a receipt issued in August must still say in December what it said
-- in August. A receipt that changes after the fact is not a receipt.
--
-- Both NULL for every order placed before this ran, and for any order
-- where Stripe could not be asked. The receipt omits the line rather
-- than inventing one.
--
-- SAFE TO RE-RUN.
-- ============================================================

USE `mobile2u`;

DROP PROCEDURE IF EXISTS `add_column_if_missing`;

DELIMITER $$

CREATE PROCEDURE `add_column_if_missing`(
    IN p_table  VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_def    VARCHAR(255)
)
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table
           AND COLUMN_NAME = p_column
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_def);
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

-- ------------------------------------------------------------
-- 1. The family
--
-- VARCHAR rather than ENUM, unlike users.theme in migration 29. The
-- difference is who owns the list: the three themes are ours and will
-- only change when we change them, whereas this list belongs to Stripe
-- and grows when Malaysia gains a payment method. An ENUM would turn
-- "Stripe added DuitNow" into a schema change and, until that change
-- ran, into rows that silently would not insert.
-- ------------------------------------------------------------
CALL add_column_if_missing(
    'orders',
    'payment_method',
    "VARCHAR(30) NULL DEFAULT NULL COMMENT 'Stripe payment method type: card, fpx, grabpay' AFTER `stripe_session_id`"
);

-- ------------------------------------------------------------
-- 2. The instrument, as shown to a human
-- ------------------------------------------------------------
CALL add_column_if_missing(
    'orders',
    'payment_detail',
    "VARCHAR(60) NULL DEFAULT NULL COMMENT 'Snapshot: bank name, or card brand and last four' AFTER `payment_method`"
);

-- ------------------------------------------------------------
-- 3. An index for reporting
--
-- The dashboard is the intended reader: a breakdown of orders by
-- payment method is a GROUP BY over this column across the whole
-- table, which without an index is a full scan every time the page
-- loads. Narrow column, cheap index.
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS `add_index_if_missing`;

DELIMITER $$

CREATE PROCEDURE `add_index_if_missing`(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_cols  VARCHAR(255)
)
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table
           AND INDEX_NAME = p_index
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (', p_cols, ')');
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

CALL add_index_if_missing('orders', 'idx_orders_payment_method', '`payment_method`');


-- ------------------------------------------------------------
-- 4. Tidy up
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS `add_column_if_missing`;
DROP PROCEDURE IF EXISTS `add_index_if_missing`;


-- ------------------------------------------------------------
-- Verify
--
--   SELECT COALESCE(payment_method, '(not recorded)') AS method,
--          COUNT(*) AS orders
--     FROM orders
--    GROUP BY payment_method
--    ORDER BY orders DESC;
--
-- Existing orders all read "(not recorded)" -- they were paid before
-- the shop was keeping this, and no value can be invented for them.
-- ------------------------------------------------------------
