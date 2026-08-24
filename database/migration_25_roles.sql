-- ============================================================
-- Mobile2U - Roles and permissions (Manage Roles module)
--
-- Until now authorisation was one ENUM column: users.role is either
-- 'admin' or 'member', and is_admin() decides everything. That is fine
-- for two kinds of user and useless the moment you want a stock clerk
-- who can adjust inventory but must not touch member accounts.
--
-- This adds proper role-based access control on the ADMIN side only:
--
--   roles              a named set of permissions
--   permissions        the catalogue of things that can be permitted
--   role_permissions   which role has which permission
--   users.role_id      which role an admin account carries
--
-- users.role is deliberately LEFT IN PLACE. It still answers the coarse
-- question "is this person staff or a customer", which the storefront
-- asks on every request. role_id answers the finer question "what may
-- this member of staff do", and only matters for admins. Replacing the
-- ENUM would have meant touching is_admin(), require_member() and
-- home_url_for_role() for no gain -- a customer has no use for granular
-- permissions in a shop.
--
-- SAFE TO RE-RUN. Same information_schema guards as migration 24.
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 0. Helpers
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS `add_column_if_missing`;
DROP PROCEDURE IF EXISTS `add_index_if_missing`;

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

CREATE PROCEDURE `add_index_if_missing`(
    IN p_table   VARCHAR(64),
    IN p_index   VARCHAR(64),
    IN p_columns VARCHAR(255)
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
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (', p_columns, ')');
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;


-- ------------------------------------------------------------
-- 1. Roles
--
-- is_system marks the roles the application depends on. Super Admin is
-- the only one, and it exists so there is always something holding every
-- permission. It cannot be deleted and its permissions cannot be edited
-- away -- otherwise one careless save leaves nobody able to reach the
-- role screen to undo it.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `roles` (
    `id`          INT(11)      NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(60)  NOT NULL,
    `description` VARCHAR(255)     NULL DEFAULT NULL,
    `is_system`   TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME         NULL DEFAULT NULL
                                   ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_roles_name` (`name`),
    KEY `idx_roles_system` (`is_system`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 2. Permissions
--
-- The catalogue is seeded here rather than being editable in the UI,
-- because a permission only means something if code checks for it.
-- Letting an admin invent "products.superdelete" would create a row that
-- controls nothing, which is worse than not having it: it looks like
-- security while doing nothing.
--
-- `code` is what require_permission() is called with. `area` groups them
-- for display. `sort_order` keeps the checkbox list in the same order as
-- the sidebar, so the screen reads like the menu it governs.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `permissions` (
    `id`          INT(11)      NOT NULL AUTO_INCREMENT,
    `code`        VARCHAR(60)  NOT NULL,
    `label`       VARCHAR(100) NOT NULL,
    `area`        VARCHAR(40)  NOT NULL,
    `description` VARCHAR(255)     NULL DEFAULT NULL,
    `sort_order`  INT(11)      NOT NULL DEFAULT 100,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_permissions_code` (`code`),
    KEY `idx_permissions_area` (`area`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 3. Which role has which permission
--
-- ON DELETE CASCADE on both sides: deleting a role should take its
-- grants with it, and a permission that no longer exists must not leave
-- rows pointing at nothing.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `role_permissions` (
    `role_id`       INT(11) NOT NULL,
    `permission_id` INT(11) NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    KEY `idx_rp_permission` (`permission_id`),
    CONSTRAINT `fk_rp_role`
        FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_rp_permission`
        FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 4. Link an admin account to a role
--
-- NULL is allowed and means "no role assigned yet". Such an account can
-- sign in and reach its own profile but nothing else, which is the right
-- default: a new staff account should start with nothing and be given
-- what it needs, not start with everything and be trimmed back.
--
-- ON DELETE SET NULL rather than CASCADE. Deleting a role must never
-- delete the people who held it.
-- ------------------------------------------------------------
CALL add_column_if_missing('users', 'role_id', 'INT(11) NULL DEFAULT NULL AFTER `role`');
CALL add_index_if_missing('users', 'idx_users_role_id', '`role_id`');


-- ------------------------------------------------------------
-- 5. The permission catalogue
--
-- One permission per admin area, matching the sidebar in
-- includes/admin_header.php. Kept deliberately coarse: a separate
-- "view" and "edit" permission for each of fifteen areas would be thirty
-- checkboxes, and nothing in this project distinguishes the two.
--
-- INSERT IGNORE relies on the unique key over `code`, so re-running adds
-- only what is missing.
-- ------------------------------------------------------------
INSERT IGNORE INTO `permissions` (`code`, `label`, `area`, `description`, `sort_order`) VALUES
-- Catalogue
('products.manage',   'Products',        'Catalogue', 'Add, edit and remove products, photos and variants.', 10),
('categories.manage', 'Categories',      'Catalogue', 'Add, edit and remove product categories.',            20),
('specs.manage',      'Specifications',  'Catalogue', 'Define the spec attributes products can carry.',      30),
('stock.manage',      'Stock',           'Catalogue', 'Adjust stock levels and view movement history.',      40),
('batch.manage',      'Batch Tools',     'Catalogue', 'Bulk import, bulk price changes and bulk deletion.',  50),

-- Sales
('orders.manage',     'Orders',          'Sales',     'View orders and change their status.',                10),
('vouchers.manage',   'Vouchers',        'Sales',     'Create and withdraw discount vouchers.',              20),
('reviews.manage',    'Reviews',         'Sales',     'Publish or hide customer reviews.',                   30),
('qr.scan',           'Scan QR',         'Sales',     'Look up an order by scanning its QR code.',           40),

-- People
('members.manage',    'Members',         'People',    'View member accounts, block them, adjust points.',    10),
('admins.manage',     'Admin Accounts',  'People',    'Create and edit administrator accounts.',             20),
('roles.manage',      'Roles',           'People',    'Create roles and decide what each one may do.',       30),

-- System
('dashboard.view',    'Dashboard',       'System',    'See the sales summary and headline figures.',         10),
('stores.manage',     'Stores',          'System',    'Maintain branch locations shown on the map.',         20),
('security.view',     'Login Security',  'System',    'Review sign-in attempts and unlock accounts.',        30),
('mail.test',         'Mail & PDF',      'System',    'Run the mail and PDF diagnostics, send test mail.',   40);


-- ------------------------------------------------------------
-- 6. Two starting roles
--
-- Super Admin holds everything and is protected. Stock Clerk exists as a
-- worked example of a limited role -- it is also the clearest thing to
-- demonstrate, because signing in as one visibly shortens the sidebar.
-- ------------------------------------------------------------
INSERT IGNORE INTO `roles` (`name`, `description`, `is_system`) VALUES
('Super Admin',  'Full access to every part of the admin panel.', 1),
('Stock Clerk',  'Keeps the catalogue and inventory up to date. No access to people or money.', 0),
('Order Support','Handles orders, reviews and customer accounts. Cannot change the catalogue.',  0);

-- Super Admin gets every permission, including any added by a later
-- migration -- this SELECT is why it is written as a join rather than a
-- fixed list.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
  FROM `roles` r
  CROSS JOIN `permissions` p
 WHERE r.name = 'Super Admin';

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
  FROM `roles` r
  JOIN `permissions` p
    ON p.code IN ('dashboard.view', 'products.manage', 'categories.manage',
                  'specs.manage', 'stock.manage', 'batch.manage')
 WHERE r.name = 'Stock Clerk';

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
  FROM `roles` r
  JOIN `permissions` p
    ON p.code IN ('dashboard.view', 'orders.manage', 'reviews.manage',
                  'members.manage', 'vouchers.manage', 'qr.scan')
 WHERE r.name = 'Order Support';


-- ------------------------------------------------------------
-- 7. Every existing admin becomes a Super Admin
--
-- Without this the migration would lock every current administrator out
-- of everything the moment the permission checks go live. Existing
-- accounts keep exactly the access they had; new ones start with none.
-- ------------------------------------------------------------
UPDATE `users`
   SET `role_id` = (SELECT `id` FROM `roles` WHERE `name` = 'Super Admin')
 WHERE `role` = 'admin'
   AND `role_id` IS NULL;


-- ------------------------------------------------------------
-- 8. The foreign key, added last
--
-- After the UPDATE above, so it cannot fail on rows that were still
-- NULL, and guarded because ADD CONSTRAINT has no IF NOT EXISTS.
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS `add_role_fk`;

DELIMITER $$

CREATE PROCEDURE `add_role_fk`()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME        = 'users'
           AND CONSTRAINT_NAME   = 'fk_users_role'
    ) THEN
        ALTER TABLE `users`
            ADD CONSTRAINT `fk_users_role`
                FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
END$$

DELIMITER ;

CALL add_role_fk();


-- ------------------------------------------------------------
-- 9. Tidy up
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS `add_column_if_missing`;
DROP PROCEDURE IF EXISTS `add_index_if_missing`;
DROP PROCEDURE IF EXISTS `add_role_fk`;


-- ------------------------------------------------------------
-- Verify
--
--   SELECT r.name, r.is_system, COUNT(rp.permission_id) AS permissions,
--          (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS accounts
--     FROM roles r
--     LEFT JOIN role_permissions rp ON rp.role_id = r.id
--    GROUP BY r.id
--    ORDER BY r.is_system DESC, r.name;
-- ------------------------------------------------------------
