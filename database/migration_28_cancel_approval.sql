-- ============================================================
-- Mobile2U - Cancellation becomes a request, not an act
--
-- Cancelling used to be immediate: a member clicked Cancel, the order
-- flipped to cancelled and the stock came back. That is fine when one
-- person runs the whole shop and wrong as soon as money is involved --
-- a cancellation after the parcel is packed costs someone something,
-- and nobody was reviewing it.
--
-- Now anyone may ASK. An administrator decides.
--
-- WHY A TABLE AND NOT A STATUS
--
-- The obvious move is to add 'cancel_requested' to orders.status. It is
-- also wrong, for three reasons:
--
--   * The order's status would stop being true. An order awaiting a
--     decision is still genuinely processing -- it is still packed, it
--     still occupies stock -- and the warehouse should still see it as
--     processing.
--   * A rejected request would have nowhere to live. The order goes back
--     to what it was and the fact that somebody asked disappears, which
--     is exactly the thing you want on record when they ask again.
--   * Every status ENUM, badge, label and transition map in the codebase
--     would grow a case that is not really a stage of fulfilment.
--
-- A request is a separate fact ABOUT an order, so it gets its own row.
--
-- SAFE TO RE-RUN.
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 1. The requests
--
-- decided_by is nullable because a pending request has not been decided.
-- ON DELETE SET NULL rather than CASCADE: deleting the administrator who
-- approved a cancellation must not delete the record of the approval.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_cancel_requests` (
    `id`            INT(11)      NOT NULL AUTO_INCREMENT,
    `order_id`      INT(11)      NOT NULL,

    `status`        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',

    -- Who asked, and from where in the workflow. Storing the status at
    -- the time of asking means a later reader can see that the request
    -- was raised while the order was still pending, even though it was
    -- decided after it had moved on.
    `requested_by`  INT(11)          NULL DEFAULT NULL,
    `requested_role` ENUM('member','admin','system') NOT NULL DEFAULT 'member',
    `status_at_request` ENUM('pending','processing','shipped','delivered','cancelled')
                                 NOT NULL,
    `reason`        VARCHAR(60)      NULL DEFAULT NULL,
    `note`          VARCHAR(500)     NULL DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Who decided, and what they said about it.
    `decided_by`    INT(11)          NULL DEFAULT NULL,
    `decided_at`    DATETIME         NULL DEFAULT NULL,
    `decision_note` VARCHAR(500)     NULL DEFAULT NULL,

    PRIMARY KEY (`id`),

    -- The queue reads "pending, oldest first", which this serves exactly.
    KEY `idx_ocr_status_created` (`status`, `created_at`),
    KEY `idx_ocr_order` (`order_id`, `status`),
    KEY `idx_ocr_requester` (`requested_by`),

    CONSTRAINT `fk_ocr_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_ocr_requester`
        FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,

    CONSTRAINT `fk_ocr_decider`
        FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 2. One open request per order, enforced by the database
--
-- The application checks this too, but two people clicking Cancel at the
-- same moment both pass that check and both insert. A unique index is
-- the only version that actually holds.
--
-- The trick is that it must apply to PENDING rows only -- an order may
-- accumulate any number of rejected requests. MySQL has no partial
-- index, so a generated column carries the order id while the request is
-- pending and NULL once it is decided, and NULLs do not collide in a
-- unique index.
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS `add_open_request_guard`;

DELIMITER $$

CREATE PROCEDURE `add_open_request_guard`()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'order_cancel_requests'
           AND COLUMN_NAME  = 'open_order_id'
    ) THEN
        ALTER TABLE `order_cancel_requests`
            ADD COLUMN `open_order_id` INT(11)
                AS (CASE WHEN `status` = 'pending' THEN `order_id` ELSE NULL END) STORED;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'order_cancel_requests'
           AND INDEX_NAME   = 'uq_ocr_one_open'
    ) THEN
        ALTER TABLE `order_cancel_requests`
            ADD UNIQUE KEY `uq_ocr_one_open` (`open_order_id`);
    END IF;
END$$

DELIMITER ;

CALL add_open_request_guard();
DROP PROCEDURE IF EXISTS `add_open_request_guard`;


-- ------------------------------------------------------------
-- 3. Two new permissions
--
-- Asking and deciding are separate rights. A vendor or a driver may well
-- need to raise "customer refused delivery"; neither should be the one
-- who signs it off.
-- ------------------------------------------------------------
INSERT IGNORE INTO `permissions` (`code`, `label`, `area`, `description`, `sort_order`) VALUES
('orders.request_cancel', 'Request Cancellation', 'Fulfilment',
 'Ask for an order to be cancelled. Does not cancel it.', 50),

('orders.approve_cancel', 'Approve Cancellations', 'Fulfilment',
 'Decide cancellation requests. Approving returns the stock.', 60);


-- ------------------------------------------------------------
-- 4. Who gets them
--
-- Everyone who could previously cancel outright keeps the ability to
-- ASK. Approval goes only to roles that already hold orders.manage --
-- deciding is an office job, not a warehouse one.
-- ------------------------------------------------------------
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT rp.role_id, p.id
  FROM `role_permissions` rp
  JOIN `permissions` old ON old.id = rp.permission_id
                        AND old.code = 'orders.to_cancelled'
  CROSS JOIN `permissions` p
 WHERE p.code = 'orders.request_cancel';

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT rp.role_id, p.id
  FROM `role_permissions` rp
  JOIN `permissions` mgr ON mgr.id = rp.permission_id
                        AND mgr.code = 'orders.manage'
  CROSS JOIN `permissions` p
 WHERE p.code = 'orders.approve_cancel';

-- The two fulfilment roles may ask, and only ask.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
  FROM `roles` r
  JOIN `permissions` p ON p.code = 'orders.request_cancel'
 WHERE r.name IN ('Vendor', 'Delivery Man');

-- Super Admin picks up both.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
  FROM `roles` r
  CROSS JOIN `permissions` p
 WHERE r.name = 'Super Admin';


-- ------------------------------------------------------------
-- 5. orders.to_cancelled is retired
--
-- Nothing performs that transition directly any more -- approving a
-- request is what cancels an order. The permission is removed rather
-- than left dangling, because a permission that grants nothing is worse
-- than no permission: it reads like security while doing nothing.
-- ------------------------------------------------------------
DELETE FROM `permissions` WHERE `code` = 'orders.to_cancelled';


-- ------------------------------------------------------------
-- Verify
--
--   SELECT o.id, o.status, r.status AS request_status, r.reason,
--          asked.name AS asked_by, decided.name AS decided_by
--     FROM order_cancel_requests r
--     JOIN orders o        ON o.id = r.order_id
--     LEFT JOIN users asked   ON asked.id = r.requested_by
--     LEFT JOIN users decided ON decided.id = r.decided_by
--    ORDER BY r.created_at DESC;
-- ------------------------------------------------------------
