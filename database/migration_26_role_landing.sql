-- ============================================================
-- Mobile2U - Where each role lands after signing in
--
-- Every administrator was sent to /admin/dashboard.php on login,
-- because home_url_for_role() only knew "admin" and "member". Once
-- dashboard.view became a permission like any other, a role without it
-- met a 403 the instant it signed in -- the first thing a new Delivery
-- Man account saw was "Not authorised", which is a miserable greeting
-- for something that is working exactly as designed.
--
-- This stores a PERMISSION CODE rather than a URL:
--
--   * it can be validated against the permissions table
--   * the code can ask "does this role still hold it?" and fall back
--     when the answer is no
--   * moving a page later changes one line of PHP, not a database row
--
-- NULL means "work it out automatically", which is also what happens
-- when the stored choice is no longer permitted. A role can therefore
-- never land on a page it cannot open.
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
-- 1. The column
--
-- Deliberately NOT a foreign key onto permissions.code. If a permission
-- were ever removed, ON DELETE SET NULL would be the desired behaviour,
-- but the code already treats an unrecognised value as "automatic", so
-- the constraint would buy nothing and would block the permissions table
-- from being reorganised.
-- ------------------------------------------------------------
CALL add_column_if_missing(
    'roles',
    'landing_permission',
    "VARCHAR(60) NULL DEFAULT NULL COMMENT 'Permission code of the page shown after login; NULL = automatic' AFTER `description`"
);


-- ------------------------------------------------------------
-- 2. Sensible defaults for the roles that already exist
--
-- Only set where the role actually holds the permission, so this cannot
-- itself create the problem it exists to fix. Roles added since are left
-- NULL and fall back to automatic, which is the right default.
-- ------------------------------------------------------------
UPDATE `roles` r
   SET r.landing_permission = 'dashboard.view'
 WHERE r.landing_permission IS NULL
   AND EXISTS (
       SELECT 1 FROM `role_permissions` rp
         JOIN `permissions` p ON p.id = rp.permission_id
        WHERE rp.role_id = r.id AND p.code = 'dashboard.view'
   );


-- ------------------------------------------------------------
-- 3. Tidy up
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS `add_column_if_missing`;


-- ------------------------------------------------------------
-- Verify
--
--   SELECT r.name,
--          COALESCE(r.landing_permission, '(automatic)') AS lands_on,
--          COUNT(rp.permission_id) AS permissions
--     FROM roles r
--     LEFT JOIN role_permissions rp ON rp.role_id = r.id
--    GROUP BY r.id
--    ORDER BY r.name;
-- ------------------------------------------------------------
