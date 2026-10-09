-- ============================================================================
--  KantEase — School Canteen Ordering and Management System
--  Schema for MySQL 8.0 / MariaDB 10.4+ (XAMPP)
--
--  TARGET      : PHP 8.2+ / Apache (XAMPP) / MySQL-MariaDB
--  ENCODING    : utf8mb4 throughout (supports the ₱ peso sign and full Unicode)
--  ENGINE      : InnoDB everywhere — required for foreign keys and row locking
--  FILE        : Fresh-install schema. Contains no credentials.
--
--  HOW TO USE
--    Option A (phpMyAdmin): create nothing by hand — importing this file
--            creates the `kantease_db` database, all tables, indexes and the
--            starter menu.
--    Option B (browser)   : open http://localhost/KantEase/database/setup.php
--            which runs this exact file for you and then creates the first
--            administrator.
--
--  NOTE ON THE FIRST ADMINISTRATOR
--    This file deliberately creates NO accounts. Admin accounts are created
--    either by database/setup.php (first run only) or by an existing admin
--    from Admin -> Accounts. There is no public admin sign-up. This closes
--    the privilege-escalation hole in the original Node.js build.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE DATABASE IF NOT EXISTS `kantease_db`
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE `kantease_db`;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- Drop in reverse-dependency order so the file is safely re-runnable during
-- development. Remove this block before importing into a database that holds
-- real orders.
-- ---------------------------------------------------------------------------
DROP VIEW IF EXISTS `users_view`;
DROP TABLE IF EXISTS `activity_logs`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `inventory_movements`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `order_status_history`;
DROP TABLE IF EXISTS `order_items`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `food_items`;
DROP TABLE IF EXISTS `food_categories`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `id_sequences`;

SET FOREIGN_KEY_CHECKS = 1;


-- ===========================================================================
--  users
-- ===========================================================================
--  user_code      Human-facing ID shown to the student (STU-0001 / ADM-0001).
--  password       Standard PHP password_hash() output.
--  password_legacy Temporary holding cell for the old Node.js scrypt hash
--                  ("<32 hex salt>:<128 hex key>"). It is verified once and
--                  then immediately replaced by a password_hash() value.
--                  NULL for every account created in the new system.
--  is_active      Soft account switch used by Admin -> Accounts.
-- ===========================================================================
CREATE TABLE `users` (
    `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_code`      VARCHAR(20)     NOT NULL,
    `full_name`      VARCHAR(120)    NOT NULL,
    `email`          VARCHAR(254)    NOT NULL,
    `password`       VARCHAR(255)    NOT NULL,
    `password_legacy` VARCHAR(255)   NULL DEFAULT NULL,
    `role`           ENUM('student','admin') NOT NULL DEFAULT 'student',
    `is_active`      TINYINT(1)      NOT NULL DEFAULT 1,
    `last_login_at`  DATETIME        NULL DEFAULT NULL,
    `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_user_code` (`user_code`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_role_active` (`role`, `is_active`),
    KEY `idx_users_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  food_categories
-- ===========================================================================
--  Replaces the hardcoded FOOD_CATEGORIES array that lived in server.js and
--  was duplicated in the browser. Adding a category is now a data change,
--  not a code change.
-- ===========================================================================
CREATE TABLE `food_categories` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(60)  NOT NULL,
    `sort_order` INT          NOT NULL DEFAULT 0,
    `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_food_categories_name` (`name`),
    KEY `idx_food_categories_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  food_items
-- ===========================================================================
--  stock            Physical units on hand. UNSIGNED means the database itself
--                   refuses a negative count even if application logic slips.
--  low_stock_level  Threshold that drives the Low Stock alerts.
--  is_available     Manual "off the menu today" switch, independent of stock.
--  is_archived      Soft removal. Order history stays intact when set.
--  image_path       Relative path under uploads/products/. NULL = placeholder.
--
--  Order lines never depend on this row: order_items snapshots item_name and
--  unit_price and uses ON DELETE SET NULL, so deleting a product can never
--  rewrite or orphan a historical order.
-- ===========================================================================
CREATE TABLE `food_items` (
    `id`               INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `category_id`      INT UNSIGNED   NOT NULL,
    `name`             VARCHAR(120)   NOT NULL,
    `description`      VARCHAR(255)   NULL DEFAULT NULL,
    `price`            DECIMAL(10,2)  NOT NULL,
    `stock`            INT UNSIGNED   NOT NULL DEFAULT 0,
    `low_stock_level`  INT UNSIGNED   NOT NULL DEFAULT 5,
    `image_path`       VARCHAR(255)   NULL DEFAULT NULL,
    `is_available`     TINYINT(1)     NOT NULL DEFAULT 1,
    `is_archived`      TINYINT(1)     NOT NULL DEFAULT 0,
    `created_at`       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_food_items_category` (`category_id`),
    KEY `idx_food_items_menu` (`is_archived`, `is_available`, `name`),
    KEY `idx_food_items_stock` (`stock`),
    KEY `idx_food_items_low_stock` (`stock`, `low_stock_level`),
    CONSTRAINT `fk_food_items_category`
        FOREIGN KEY (`category_id`) REFERENCES `food_categories` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  orders
-- ===========================================================================
--  order_number     Public reference, KE-YYYYMMDD-0001. Unique. Replaces the
--                    auto-increment id being used as an order number, which
--                    leaked total order volume and was enumerable.
--  idempotency_key  A one-time key sent by the browser when it submits the
--                    checkout form. UNIQUE, so a double-click, a browser retry
--                    or a replayed request returns the order that already
--                    exists instead of placing a second one (audit DEF-3).
--                    NULL for orders created by the API or the installer.
--  total_amount     Always equals SUM(order_items.subtotal). recomputed
--                    server-side on every write; never trusted from a client.
--  status           Pending -> Preparing -> Ready -> Completed, or Cancelled.
--                    Completed and Cancelled are terminal. Enforced in
--                    includes/enums.php (OrderStatus::canTransitionTo).
--  payment_status   Set to Paid only by Admin -> Orders. Writes a row into
--                    `payments` with a UNIQUE reference, so a payment can be
--                    recorded exactly once.
--  cancel_reason    Dedicated column. The original reused the shared `note`
--                    field, which was destroyed by the next edit.
--  *_at columns     Per-stage timestamps, required by spec 6.B.
--
--  user_id is NOT NULL. The legacy build allowed NULL, and the admin order
--  list used an inner JOIN, so those rows silently vanished from the report.
-- ===========================================================================
CREATE TABLE `orders` (
    `id`             INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `order_number`   VARCHAR(24)    NOT NULL,
    `idempotency_key` VARCHAR(64)    NULL DEFAULT NULL,
    `user_id`        INT UNSIGNED   NOT NULL,
    `total_amount`   DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    `status`         ENUM('Pending','Preparing','Ready','Completed','Cancelled')
                                     NOT NULL DEFAULT 'Pending',
    `payment_status` ENUM('Unpaid','Paid') NOT NULL DEFAULT 'Unpaid',
    `note`           VARCHAR(200)   NULL DEFAULT NULL,
    `cancel_reason`  VARCHAR(200)   NULL DEFAULT NULL,
    `cancelled_at`   DATETIME       NULL DEFAULT NULL,
    `cancelled_by`   INT UNSIGNED   NULL DEFAULT NULL,
    `prepared_at`    DATETIME       NULL DEFAULT NULL,
    `ready_at`       DATETIME       NULL DEFAULT NULL,
    `completed_at`   DATETIME       NULL DEFAULT NULL,
    `order_date`     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_orders_order_number` (`order_number`),
    UNIQUE KEY `uq_orders_idempotency_key` (`idempotency_key`),
    KEY `idx_orders_status` (`status`),
    KEY `idx_orders_payment_status` (`payment_status`),
    KEY `idx_orders_order_date` (`order_date`),
    KEY `idx_orders_user_date` (`user_id`, `order_date`),
    KEY `idx_orders_status_date` (`status`, `order_date`),
    CONSTRAINT `fk_orders_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_orders_cancelled_by`
        FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  order_items
-- ===========================================================================
--  item_name / unit_price are HISTORICAL SNAPSHOTS taken at order time.
--  Changing a product's price or deleting the product never rewrites a past
--  order. food_id may become NULL (product deleted) while the snapshot stays.
-- ===========================================================================
CREATE TABLE `order_items` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `order_id`   INT UNSIGNED  NOT NULL,
    `food_id`    INT UNSIGNED  NULL DEFAULT NULL,
    `item_name`  VARCHAR(120)  NOT NULL,
    `quantity`   INT UNSIGNED  NOT NULL DEFAULT 1,
    `unit_price` DECIMAL(10,2) NOT NULL,
    `subtotal`   DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_order_items_order` (`order_id`),
    KEY `idx_order_items_food` (`food_id`),
    CONSTRAINT `fk_order_items_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_order_items_food`
        FOREIGN KEY (`food_id`) REFERENCES `food_items` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  order_status_history
-- ===========================================================================
--  Audit trail for every status transition. Needed for spec 6.C (prevent
--  duplicate completion) and spec 6.B (display timestamps). A UNIQUE key on
--  (order_id, to_status, completed_at-style marker) is deliberately NOT used:
--  an order legitimately visits each status at most once, so this table is
--  append-only and the terminal-state rule in OrderStatus prevents repeats.
-- ===========================================================================
CREATE TABLE `order_status_history` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`    INT UNSIGNED NOT NULL,
    `from_status` ENUM('Pending','Preparing','Ready','Completed','Cancelled')
                              NULL DEFAULT NULL,
    `to_status`   ENUM('Pending','Preparing','Ready','Completed','Cancelled')
                              NOT NULL,
    `changed_by`  INT UNSIGNED NULL DEFAULT NULL,
    `note`        VARCHAR(200) NULL DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_order_status_history_order` (`order_id`, `created_at`),
    CONSTRAINT `fk_order_status_history_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_order_status_history_user`
        FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  payments
-- ===========================================================================
--  Cash-at-the-canteen only. No gateway, no card, no online capture.
--
--  `reference` holds the order_number and is UNIQUE. That single constraint is
--  what makes duplicate payment recording impossible at the storage layer, not
--  just in application code (audit finding DEF-2).
--  `received_by` records which administrator confirmed the money.
-- ===========================================================================
CREATE TABLE `payments` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `order_id`    INT UNSIGNED  NOT NULL,
    `reference`   VARCHAR(24)   NOT NULL,
    `amount`      DECIMAL(10,2) NOT NULL,
    `method`      ENUM('Cash')  NOT NULL DEFAULT 'Cash',
    `received_by` INT UNSIGNED  NOT NULL,
    `received_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `note`        VARCHAR(200)  NULL DEFAULT NULL,
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_payments_reference` (`reference`),
    KEY `idx_payments_order` (`order_id`),
    KEY `idx_payments_received_by` (`received_by`),
    KEY `idx_payments_received_at` (`received_at`),
    CONSTRAINT `fk_payments_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_payments_received_by`
        FOREIGN KEY (`received_by`) REFERENCES `users` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  inventory_movements
-- ===========================================================================
--  Ledger of every stock change. stock_before / stock_after make the current
--  food_items.stock value auditable at any point in time.
--
--  movement_type:
--    create       initial stock when a product is created
--    restock      admin increased stock
--    waste        admin decreased stock
--    adjustment   admin set stock to an explicit value
--    sale         deducted by a placed order          (negative)
--    sale_restore returned when an order is cancelled (positive)
--
--  The sale / sale_restore pair is the operational meaning of "reserved or
--  deducted according to a consistent inventory policy": KantEase deducts at
--  order placement and restores exactly once on cancellation.
-- ===========================================================================
CREATE TABLE `inventory_movements` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `food_id`         INT UNSIGNED NOT NULL,
    `movement_type`   ENUM('create','restock','waste','adjustment','sale','sale_restore')
                                      NOT NULL,
    `quantity_change` INT          NOT NULL,
    `stock_before`    INT UNSIGNED NOT NULL,
    `stock_after`     INT UNSIGNED NOT NULL,
    `reference_type`  VARCHAR(20)  NULL DEFAULT NULL,
    `reference_id`    INT UNSIGNED NULL DEFAULT NULL,
    `user_id`         INT UNSIGNED NULL DEFAULT NULL,
    `note`            VARCHAR(200) NULL DEFAULT NULL,
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_inventory_movements_food` (`food_id`, `created_at`),
    KEY `idx_inventory_movements_reference` (`reference_type`, `reference_id`),
    KEY `idx_inventory_movements_user` (`user_id`),
    CONSTRAINT `fk_inventory_movements_food`
        FOREIGN KEY (`food_id`) REFERENCES `food_items` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_inventory_movements_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  activity_logs
-- ===========================================================================
--  Who did what, when. Covers account changes, inventory edits, order
--  processing and logins — the "restricted administrator endpoint" evidence
--  trail required by spec 9.
-- ===========================================================================
CREATE TABLE `activity_logs` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED  NULL DEFAULT NULL,
    `action`      VARCHAR(64)   NOT NULL,
    `entity_type` VARCHAR(32)   NULL DEFAULT NULL,
    `entity_id`   INT UNSIGNED  NULL DEFAULT NULL,
    `details`     TEXT          NULL DEFAULT NULL,
    `ip_address`  VARCHAR(45)   NULL DEFAULT NULL,
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_activity_logs_user` (`user_id`, `created_at`),
    KEY `idx_activity_logs_action` (`action`, `created_at`),
    KEY `idx_activity_logs_entity` (`entity_type`, `entity_id`),
    CONSTRAINT `fk_activity_logs_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  login_attempts
-- ===========================================================================
--  Bounded brute-force protection (audit finding VULN-4). The original
--  /api/login had no throttling at all. Rows older than the retention window
--  are pruned opportunistically on write.
-- ===========================================================================
CREATE TABLE `login_attempts` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `identifier`   VARCHAR(191)   NOT NULL,
    `ip_address`   VARCHAR(45)    NULL DEFAULT NULL,
    `was_successful` TINYINT(1)   NOT NULL DEFAULT 0,
    `attempted_at` DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_login_attempts_identifier` (`identifier`, `attempted_at`),
    KEY `idx_login_attempts_attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  id_sequences
-- ===========================================================================
--  Gap-free counters for STU-####, ADM-#### and KE-YYYYMMDD-####.
--  Generalised from the original two-row (role ENUM PK) table so a third
--  sequence could be added without another schema change.
--
--  Allocation always runs inside a transaction with SELECT ... FOR UPDATE,
--  which is what makes the numbers gap-free and safe under concurrency.
-- ===========================================================================
CREATE TABLE `id_sequences` (
    `sequence_name` VARCHAR(32) NOT NULL,
    `next_number`   INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`sequence_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `id_sequences` (`sequence_name`, `next_number`) VALUES
    ('student', 1),
    ('admin',   1),
    ('order',   1);


-- ===========================================================================
--  Seed data
-- ===========================================================================
--  The five products from the original application, plus descriptions and the
--  new category link. Prices and stock match the original seed exactly so
--  existing behaviour is preserved.
-- ===========================================================================
INSERT INTO `food_categories` (`id`, `name`, `sort_order`, `is_active`) VALUES
    (1, 'Meals',    1, 1),
    (2, 'Snacks',   2, 1),
    (3, 'Drinks',   3, 1),
    (4, 'Desserts', 4, 1),
    (5, 'Others',   5, 1);

INSERT INTO `food_items`
    (`category_id`, `name`, `description`, `price`, `stock`, `low_stock_level`, `is_available`, `is_archived`)
VALUES
    (1, 'Chicken Rice',      'Steamed rice with chicken, the canteen staple.',        75.00, 20,  5, 1, 0),
    (1, 'Pancit',            'Stir-fried noodles with vegetables and meat.',          50.00, 15,  5, 1, 0),
    (2, 'Siomai',            'Steamed dumplings, six pieces per serving.',             35.00, 25,  5, 1, 0),
    (2, 'Cheese Sandwich',   'Grilled cheese on white bread.',                         40.00, 12,  5, 1, 0),
    (3, 'Bottled Water',     'Chilled 500 ml drinking water.',                         20.00, 30,  8, 1, 0);

-- Record the opening stock so the ledger starts balanced.
INSERT INTO `inventory_movements`
    (`food_id`, `movement_type`, `quantity_change`, `stock_before`, `stock_after`, `reference_type`, `note`)
SELECT `id`, 'create', `stock`, 0, `stock`, 'seed', 'Initial stock from database.sql'
FROM `food_items`;

-- ===========================================================================
--  Done. Next: open http://localhost/KantEase/database/setup.php to create the
--  first administrator, then DELETE database/setup.php.
-- ===========================================================================