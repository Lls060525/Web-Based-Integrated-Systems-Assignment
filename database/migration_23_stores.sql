-- ============================================================
-- Mobile2U - 门市据点（Google Maps Integration）
--
-- phpMyAdmin 遇到第一个错误就会停，所以这是独立档案。
-- ============================================================

USE `mobile2u`;

-- ------------------------------------------------------------
-- 门市
--
-- latitude / longitude 用 DECIMAL 不用 FLOAT。
-- FLOAT 是二进位浮点数，存 3.139003 会变成 3.1390029999...，
-- 而且比大小时会出现「明明一样却不相等」。座标是要拿来算距离和
-- 比对的资料，不能有这种误差。
--
-- DECIMAL(10,7)：整数部分最多 3 位（经度到 180），小数 7 位。
-- 小数第 7 位大约是 1 公分，对门市定位远远够用。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stores` (
    `id`             INT(11)        NOT NULL AUTO_INCREMENT,
    `name`           VARCHAR(120)   NOT NULL,
    `code`           VARCHAR(30)    NOT NULL,
    `address_line1`  VARCHAR(150)   NOT NULL,
    `address_line2`  VARCHAR(150)   NULL DEFAULT NULL,
    `city`           VARCHAR(80)    NOT NULL,
    `state`          VARCHAR(80)    NOT NULL,
    `postcode`       VARCHAR(12)    NOT NULL,
    `country`        VARCHAR(60)    NOT NULL DEFAULT 'Malaysia',
    `latitude`       DECIMAL(10,7)  NULL DEFAULT NULL,
    `longitude`      DECIMAL(10,7)  NULL DEFAULT NULL,
    `phone`          VARCHAR(30)    NULL DEFAULT NULL,
    `email`          VARCHAR(100)   NULL DEFAULT NULL,
    `opening_hours`  VARCHAR(500)   NULL DEFAULT NULL,
    `is_active`      TINYINT(1)     NOT NULL DEFAULT 1,
    `is_primary`     TINYINT(1)     NOT NULL DEFAULT 0,
    `sort_order`     INT(11)        NOT NULL DEFAULT 100,
    `created_at`     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_store_code` (`code`),
    KEY `idx_store_active` (`is_active`, `sort_order`),

    -- 「找最近的门市」会用範围条件先粗筛，这个索引让它不用整表扫描。
    KEY `idx_store_coords` (`latitude`, `longitude`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci;


-- ------------------------------------------------------------
-- 範例门市（真实座标，demo 时地图会有东西可看）
-- ------------------------------------------------------------
INSERT INTO `stores`
    (`name`, `code`, `address_line1`, `city`, `state`, `postcode`,
     `latitude`, `longitude`, `phone`, `opening_hours`, `is_primary`, `sort_order`)
SELECT * FROM (
    SELECT 'Mobile2U Kuala Lumpur' AS name, 'M2U-KL' AS code,
           'Lot 3.10, Suria KLCC' AS a1, 'Kuala Lumpur' AS city,
           'Wilayah Persekutuan' AS state, '50088' AS postcode,
           3.1578000 AS lat, 101.7117000 AS lng,
           '03-2382 1000' AS phone,
           'Mon-Sun 10:00 - 22:00' AS hours, 1 AS is_primary, 10 AS sort_order
    UNION ALL SELECT 'Mobile2U Petaling Jaya', 'M2U-PJ',
           'Lot G-22, 1 Utama Shopping Centre', 'Petaling Jaya',
           'Selangor', '47800', 3.1500000, 101.6158000,
           '03-7726 2020', 'Mon-Sun 10:00 - 22:00', 0, 20
    UNION ALL SELECT 'Mobile2U Penang', 'M2U-PG',
           'Lot 170, Gurney Plaza', 'George Town',
           'Pulau Pinang', '10250', 5.4370000, 100.3090000,
           '04-227 3030', 'Mon-Sun 10:00 - 22:00', 0, 30
    UNION ALL SELECT 'Mobile2U Johor Bahru', 'M2U-JB',
           'Lot K-12, Johor Bahru City Square', 'Johor Bahru',
           'Johor', '80000', 1.4610000, 103.7620000,
           '07-222 4040', 'Mon-Sun 10:00 - 22:00', 0, 40
    UNION ALL SELECT 'Mobile2U Kota Kinabalu', 'M2U-KK',
           'Lot 1-23, Suria Sabah', 'Kota Kinabalu',
           'Sabah', '88000', 5.9840000, 116.0740000,
           '088-260 505', 'Mon-Sun 10:00 - 21:00', 0, 50
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `stores` s WHERE s.code = seed.code);


-- ------------------------------------------------------------
-- 确认
-- ------------------------------------------------------------
SELECT COUNT(*) AS stores_ready,
       SUM(`latitude` IS NOT NULL) AS with_coordinates
  FROM `stores`;
