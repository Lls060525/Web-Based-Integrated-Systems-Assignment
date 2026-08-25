-- ============================================================
-- Mobile2U - Remembered appearance preference
--
-- Stores which colour scheme a signed-in user chose, so the choice
-- follows the ACCOUNT rather than the browser. A cookie alone would
-- forget the moment somebody used the lab machine next to theirs, and
-- "remember my preference" that only works on one computer is not
-- really remembering anything.
--
-- Guests still get a cookie -- there is nowhere else to put it -- and
-- lib/theme.php promotes that cookie into this column on login, so
-- choosing dark before signing up is not silently discarded.
--
-- WHY A COLUMN AND NOT A PREFERENCES TABLE
--
-- One row per user with one small value is exactly what a column is
-- for. A key/value preferences table would be the right answer at a
-- dozen settings, because each new one would then cost no schema
-- change; at one setting it costs an extra join on every page load to
-- store a single word. If this grows past three or four preferences,
-- that is the moment to convert -- not now.
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
-- The column
--
-- An ENUM rather than a VARCHAR: there are exactly three answers and
-- the database should be the one refusing a fourth. The value reaches
-- an HTML attribute, so a stray value would be a stray attribute.
--
-- DEFAULT 'system' rather than 'light'. Somebody who has never touched
-- the setting is best served by whatever their operating system is
-- already doing -- a phone in night mode opening a blazing white page
-- is a worse first impression than either theme on its own.
-- ------------------------------------------------------------
CALL add_column_if_missing(
    'users',
    'theme',
    "ENUM('system','light','dark') NOT NULL DEFAULT 'system' COMMENT 'Remembered colour scheme preference'"
);


-- ------------------------------------------------------------
-- Tidy up
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS `add_column_if_missing`;


-- ------------------------------------------------------------
-- Verify
--
--   SELECT theme, COUNT(*) AS users
--     FROM users
--    GROUP BY theme;
--
-- Expect every existing account to read 'system' immediately after
-- running this, because the default applies to rows that already exist.
-- ------------------------------------------------------------
