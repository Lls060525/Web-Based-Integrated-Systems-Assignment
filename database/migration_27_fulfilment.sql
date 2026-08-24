-- ============================================================
-- Mobile2U - Who may move an order forward, and proof that they did
--
-- Until now anyone holding orders.manage could drag an order through
-- the whole workflow. In a real shop those are different jobs done by
-- different people: the vendor packs it, the driver delivers it, and
-- neither should be able to do the other's step.
--
-- WHY THESE ARE PERMISSIONS AND NOT ROLE NAMES
--
-- The obvious shortcut is to check the role name -- if ($role === 'Vendor').
-- That breaks the moment somebody renames a role, and it means adding a
-- second delivery company needs a code change. Permissions keep the rule
-- in the database where the rest of the model already lives:
--
--   orders.to_processing   accept the order and start packing it
--   orders.to_shipped      hand it to the courier
--   orders.to_delivered    confirm it reached the customer
--   orders.to_cancelled    call the whole thing off
--
-- "Vendor" and "Delivery Man" then become ordinary roles that happen to
-- hold one of these, and the shop can invent as many as it likes.
--
-- EVIDENCE
--
-- shipped and delivered are the two steps a customer might later
-- dispute -- "it never arrived", "the box was already open". Both now
-- require a photograph, stored against the history row that records the
-- change, so the proof and the claim cannot be separated.
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
-- 1. Somewhere to put the photograph
--
-- Only the filename is stored, the same way products and avatars do it.
-- The file itself lands in assets/uploads/evidence/, which carries its
-- own .htaccess refusing to execute anything.
-- ------------------------------------------------------------
CALL add_column_if_missing(
    'order_status_history',
    'evidence_photo',
    "VARCHAR(120) NULL DEFAULT NULL COMMENT 'Filename of the proof photo for this step' AFTER `note`"
);


-- ------------------------------------------------------------
-- 2. One permission per transition
--
-- sort_order continues from the existing Sales block so these sit
-- underneath "Orders" on the role form rather than above it.
-- ------------------------------------------------------------
INSERT IGNORE INTO `permissions` (`code`, `label`, `area`, `description`, `sort_order`) VALUES
('orders.to_processing', 'Start Processing', 'Fulfilment',
 'Accept a pending order and begin packing it.', 10),

('orders.to_shipped',    'Mark as Shipped',   'Fulfilment',
 'Hand a packed order to the courier. Requires a photograph.', 20),

('orders.to_delivered',  'Mark as Delivered', 'Fulfilment',
 'Confirm the order reached the customer. Requires a photograph.', 30),

('orders.to_cancelled',  'Cancel Orders',     'Fulfilment',
 'Cancel an order and return its stock.', 40);


-- ------------------------------------------------------------
-- 3. Existing roles keep what they had
--
-- Anyone who already holds orders.manage could previously make every
-- transition, so they are granted all four. Without this the migration
-- would quietly take away an ability people are using.
-- ------------------------------------------------------------
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT rp.role_id, p.id
  FROM `role_permissions` rp
  JOIN `permissions` existing ON existing.id = rp.permission_id
                             AND existing.code = 'orders.manage'
  CROSS JOIN `permissions` p
 WHERE p.code IN ('orders.to_processing', 'orders.to_shipped',
                  'orders.to_delivered', 'orders.to_cancelled');


-- ------------------------------------------------------------
-- 4. The two new roles
--
-- Both get qr.scan as their main tool and land on the scanner, because
-- scanning the receipt is how they will identify the order. Neither gets
-- orders.manage: they do not need to browse the order list, only to act
-- on the one in front of them.
-- ------------------------------------------------------------
INSERT IGNORE INTO `roles` (`name`, `description`, `is_system`) VALUES
('Vendor',
 'Packs orders. Scans a receipt and moves it from Pending to Processing.', 0),

('Delivery Man',
 'Delivers orders. Scans a receipt and marks it Shipped or Delivered, with a photo.', 0);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
  FROM `roles` r
  JOIN `permissions` p
    ON p.code IN ('qr.scan', 'orders.to_processing')
 WHERE r.name = 'Vendor';

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
  FROM `roles` r
  JOIN `permissions` p
    ON p.code IN ('qr.scan', 'orders.to_shipped', 'orders.to_delivered')
 WHERE r.name = 'Delivery Man';


-- ------------------------------------------------------------
-- 5. Both land on the scanner
--
-- Guarded on the column existing, so this file does not depend on the
-- order migration 26 was run in.
-- ------------------------------------------------------------
UPDATE `roles`
   SET `landing_permission` = 'qr.scan'
 WHERE `name` IN ('Vendor', 'Delivery Man')
   AND EXISTS (
       SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'roles'
          AND COLUMN_NAME  = 'landing_permission'
   );


-- ------------------------------------------------------------
-- 6. Super Admin picks up the new permissions
-- ------------------------------------------------------------
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
  FROM `roles` r
  CROSS JOIN `permissions` p
 WHERE r.name = 'Super Admin';


-- ------------------------------------------------------------
-- 7. Tidy up
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS `add_column_if_missing`;


-- ------------------------------------------------------------
-- Verify
--
--   SELECT r.name, GROUP_CONCAT(p.code ORDER BY p.code SEPARATOR ', ') AS can_do
--     FROM roles r
--     JOIN role_permissions rp ON rp.role_id = r.id
--     JOIN permissions p       ON p.id = rp.permission_id
--    WHERE p.area = 'Fulfilment' OR p.code = 'qr.scan'
--    GROUP BY r.id
--    ORDER BY r.name;
-- ------------------------------------------------------------
