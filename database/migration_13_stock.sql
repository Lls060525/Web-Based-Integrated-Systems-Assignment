-- ============================================================
-- Mobile2U - Product Stock Handling module
--
-- Its own file, because phpMyAdmin stops at the first error.
-- Usage: phpMyAdmin -> select mobile2u -> SQL tab -> paste -> Go
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 1. A per-product reorder level
--
-- "Low stock" used to be a hardcoded 5. The sensible level varies a lot:
-- a flagship phone may need restocking at 3, an accessory not until 20.
-- ------------------------------------------------------------
ALTER TABLE `products`
    ADD COLUMN `reorder_level` INT(11) NOT NULL DEFAULT 5;


-- ------------------------------------------------------------
-- 2. Stock movement ledger
--
-- NOTE: this is deliberately DIFFERENT from point_transactions.
--
-- The point balance is SUM(points) with no cached column.
-- Stock keeps products.stock as the authoritative value; the ledger is an audit trail.
--
-- Why the inconsistency? The concurrency requirements differ:
--   deducting stock must be an atomic conditional update
--     UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?
--   check-and-decrement in one step, so two buyers cannot both take the last unit.
--   Computing stock with SUM would mean read-then-write, with a gap in between.
--
-- Points do not have that problem: redemption is one user's action, serialised in a transaction.
--
-- The cost is that products.stock could in theory drift from the ledger sum,
-- so admin/stock.php runs a reconciliation check that reports any difference.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stock_movements` (
    `id`          INT(11)      NOT NULL AUTO_INCREMENT,
    `product_id`  INT(11)      NOT NULL,
    `type`        ENUM('sale','return','restock','adjust','damage','initial') NOT NULL,
    `quantity`    INT(11)      NOT NULL,
    `stock_after` INT(11)      NOT NULL,
    `order_id`    INT(11)      NULL DEFAULT NULL,
    `reason`      VARCHAR(200) NOT NULL,
    `created_by`  INT(11)      NULL DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_movements_product_time` (`product_id`, `created_at`),
    KEY `idx_movements_order` (`order_id`),
    KEY `idx_movements_type` (`type`),

    CONSTRAINT `fk_movements_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_movements_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,

    CONSTRAINT `fk_movements_admin`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci;


-- ------------------------------------------------------------
-- 3. An opening-stock entry for existing products, so no ledger is empty
-- Only for products with no movement yet, so re-running adds nothing twice.
-- ------------------------------------------------------------
INSERT INTO `stock_movements` (`product_id`, `type`, `quantity`, `stock_after`, `reason`)
SELECT p.`id`, 'initial', p.`stock`, p.`stock`, 'Opening stock recorded when the module was installed'
  FROM `products` p
 WHERE NOT EXISTS (
        SELECT 1 FROM `stock_movements` m WHERE m.`product_id` = p.`id`
      );


-- ------------------------------------------------------------
-- 4. Verify
-- ------------------------------------------------------------
SELECT p.`id`, p.`name`, p.`stock`, p.`reorder_level`,
       COALESCE(SUM(m.`quantity`), 0) AS ledger_total,
       CASE WHEN p.`stock` = COALESCE(SUM(m.`quantity`), 0) THEN 'OK' ELSE 'MISMATCH' END AS reconciled
  FROM `products` p
  LEFT JOIN `stock_movements` m ON m.`product_id` = p.`id`
 GROUP BY p.`id`, p.`name`, p.`stock`, p.`reorder_level`
 ORDER BY p.`id`;
