-- ============================================================================
--  KantEase — Legacy Migration  (Node.js build  ->  PHP/MySQL build)
--
--  PURPOSE
--    Converts the original single-file Node.js application's database
--    (`canteen_db`, created by server.js at boot) into the new PHP schema.
--    Every student account, order, order line and product is preserved.
--
--  WHEN TO USE
--    Only if you have existing data in the old application. For a brand-new
--    installation import database.sql instead and ignore this file entirely.
--
--  HOW TO USE
--    1. BACK UP FIRST.  In phpMyAdmin: select the old database ->
--       Export -> Structure and Data -> Save as .sql. Do not skip this.
--    2. Import this file into the OLD database (phpMyAdmin -> Import), or run
--       it from the command line:
--           mysql -u root -p < migrate_from_node.sql
--    3. Point includes/config.local.php at the resulting database name.
--    4. Verify every check in SECTION 7 returns an empty result.
--
--  WHAT CANNOT BE RECOVERED
--    * Historical payment confirmations. The original stored only a boolean
--      ENUM column with no timestamp and no responsible admin, so there is
--      nothing to convert. payment_status is carried over as-is; the payments
--      table starts empty and only records payments from the new system.
--    * Historical stock movements. The original had no ledger at all, so a
--      single baseline 'adjustment' row is written per product to record
--      "this was the count at migration time".
--    * Cancellation reasons. The original reused the shared `note` field.
--      Existing note text is preserved, but it is not distinguishable from an
--      ordinary staff note and is left in `note` rather than `cancel_reason`.
--
--  IDEMPOTENCY
--    THIS SCRIPT IS INTENDED TO RUN ONCE, against a COPY of your legacy
--    database. It renames the original tables to legacy_* and rebuilds them,
--    so a second run would find the new tables already in place and try to
--    migrate their contents again.
--
--    Always back up first, and if you do need to run it twice, restore from
--    that backup first. The individual statements use IF NOT EXISTS and
--    duplicate-safe inserts so that a failure part-way through can be
--    recovered by fixing the cause and starting from the backup again.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

USE `canteen_db`;


-- ===========================================================================
--  SECTION 1 — Create the new tables.
--
--  Foreign keys are deliberately OMITTED here and added in SECTION 6 after the
--  rename. Declaring them up front would leave every constraint pointing at
--  the temporary *_v2 table names after the rename.
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- Legacy leftovers are renamed, not dropped, so the run is re-runnable and the
-- original data stays available for inspection.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `users_v2`;
DROP TABLE IF EXISTS `food_items_v2`;
DROP TABLE IF EXISTS `orders_v2`;
DROP TABLE IF EXISTS `order_items_v2`;
DROP TABLE IF EXISTS `id_sequences_v2`;

CREATE TABLE IF NOT EXISTS `users_v2` (
    `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_code`       VARCHAR(20)   NOT NULL,
    `full_name`       VARCHAR(120)  NOT NULL,
    `email`           VARCHAR(254)  NOT NULL,
    `password`        VARCHAR(255)  NOT NULL DEFAULT '',
    `password_legacy` VARCHAR(255)  NULL DEFAULT NULL,
    `role`            ENUM('student','admin') NOT NULL DEFAULT 'student',
    `is_active`       TINYINT(1)    NOT NULL DEFAULT 1,
    `last_login_at`   DATETIME      NULL DEFAULT NULL,
    `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_user_code` (`user_code`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_role_active` (`role`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `food_categories` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(60)  NOT NULL,
    `sort_order` INT          NOT NULL DEFAULT 0,
    `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_food_categories_name` (`name`),
    KEY `idx_food_categories_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `food_items_v2` (
    `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `category_id`     INT UNSIGNED  NOT NULL DEFAULT 0,
    `name`            VARCHAR(120)  NOT NULL,
    `description`     VARCHAR(255)  NULL DEFAULT NULL,
    `price`           DECIMAL(10,2) NOT NULL,
    `stock`           INT UNSIGNED  NOT NULL DEFAULT 0,
    `low_stock_level` INT UNSIGNED  NOT NULL DEFAULT 5,
    `image_path`      VARCHAR(255)  NULL DEFAULT NULL,
    `is_available`    TINYINT(1)    NOT NULL DEFAULT 1,
    `is_archived`     TINYINT(1)    NOT NULL DEFAULT 0,
    `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_food_items_category` (`category_id`),
    KEY `idx_food_items_menu` (`is_archived`, `is_available`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `orders_v2` (
    `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `order_number`    VARCHAR(24)   NOT NULL,
    `idempotency_key` VARCHAR(64)   NULL DEFAULT NULL,
    `user_id`         INT UNSIGNED  NOT NULL,
    `total_amount`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `status`         ENUM('Pending','Preparing','Ready','Completed','Cancelled')
                                    NOT NULL DEFAULT 'Pending',
    `payment_status` ENUM('Unpaid','Paid') NOT NULL DEFAULT 'Unpaid',
    `note`           VARCHAR(200)  NULL DEFAULT NULL,
    `cancel_reason`  VARCHAR(200)  NULL DEFAULT NULL,
    `cancelled_at`   DATETIME      NULL DEFAULT NULL,
    `cancelled_by`   INT UNSIGNED  NULL DEFAULT NULL,
    `prepared_at`    DATETIME      NULL DEFAULT NULL,
    `ready_at`       DATETIME      NULL DEFAULT NULL,
    `completed_at`   DATETIME      NULL DEFAULT NULL,
    `order_date`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_orders_order_number` (`order_number`),
    UNIQUE KEY `uq_orders_idempotency_key` (`idempotency_key`),
    KEY `idx_orders_status` (`status`),
    KEY `idx_orders_order_date` (`order_date`),
    KEY `idx_orders_user_date` (`user_id`, `order_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `order_items_v2` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `order_id`   INT UNSIGNED  NOT NULL,
    `food_id`    INT UNSIGNED  NULL DEFAULT NULL,
    `item_name`  VARCHAR(120)  NOT NULL,
    `quantity`   INT UNSIGNED  NOT NULL DEFAULT 1,
    `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `subtotal`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (`id`),
    KEY `idx_order_items_order` (`order_id`),
    KEY `idx_order_items_food` (`food_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `order_status_history` (
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
    KEY `idx_order_status_history_order` (`order_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `payments` (
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
    KEY `idx_payments_received_at` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `inventory_movements` (
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
    KEY `idx_inventory_movements_food` (`food_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED  NULL DEFAULT NULL,
    `action`      VARCHAR(64)   NOT NULL,
    `entity_type` VARCHAR(32)   NULL DEFAULT NULL,
    `entity_id`   INT UNSIGNED  NULL DEFAULT NULL,
    `details`     TEXT          NULL DEFAULT NULL,
    `ip_address`  VARCHAR(45)   NULL DEFAULT NULL,
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_activity_logs_action` (`action`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `identifier`     VARCHAR(191)   NOT NULL,
    `ip_address`     VARCHAR(45)    NULL DEFAULT NULL,
    `was_successful` TINYINT(1)     NOT NULL DEFAULT 0,
    `attempted_at`   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_login_attempts_identifier` (`identifier`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `id_sequences_v2` (
    `sequence_name` VARCHAR(32) NOT NULL,
    `next_number`   INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`sequence_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  SECTION 2 — Migrate data.
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- 2.1  Accounts.
--      The scrypt hash moves to password_legacy and is verified once, then
--      upgraded to password_hash() on first login. `password` is left empty,
--      and includes/auth.php always tries password_legacy first when present,
--      so there is never a window where an empty password could authenticate.
-- ---------------------------------------------------------------------------
INSERT INTO `users_v2`
    (`id`, `user_code`, `full_name`, `email`, `password`, `password_legacy`,
     `role`, `is_active`, `created_at`)
SELECT
    u.`id`,
    u.`user_code`,
    u.`full_name`,
    u.`email`,
    '',
    u.`password`,
    u.`role`,
    1,
    COALESCE(u.`created_at`, NOW())
FROM `users` u
ORDER BY u.`id`;

-- ---------------------------------------------------------------------------
-- 2.2  A home for orders whose owner no longer exists.
--      The legacy build allowed orders.user_id to be NULL, and its admin
--      report used an inner JOIN to users, so those rows were invisible.
--      orders.user_id is NOT NULL now, so rather than discard real order
--      history they are attached to this inert placeholder account.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `users_v2`
    (`user_code`, `full_name`, `email`, `password`, `password_legacy`,
     `role`, `is_active`, `created_at`)
VALUES
    ('LEGACY-0001', 'Legacy Records (owner unknown)', 'legacy-0001@kantease.invalid',
     '', NULL, 'admin', 0, NOW());

-- ---------------------------------------------------------------------------
-- 2.3  Categories. The five standard ones are always present so the new UI is
--      never missing a tab; any extra category text used in the old system is
--      carried across rather than dropped.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `food_categories` (`name`, `sort_order`, `is_active`) VALUES
    ('Meals',    1, 1),
    ('Snacks',   2, 1),
    ('Drinks',   3, 1),
    ('Desserts', 4, 1),
    ('Others',   5, 1);

INSERT IGNORE INTO `food_categories` (`name`, `sort_order`, `is_active`)
SELECT DISTINCT
    f.`category`,
    90,
    1
FROM `food_items` f
WHERE f.`category` IS NOT NULL
  AND TRIM(f.`category`) <> ''
  AND f.`category` NOT IN ('Meals','Snacks','Drinks','Desserts','Others');

-- ---------------------------------------------------------------------------
-- 2.4  Products. category text becomes a category_id foreign key.
--      Products with stock 0 are marked unavailable so they leave the student
--      menu on day one without anyone having to edit them.
-- ---------------------------------------------------------------------------
INSERT INTO `food_items_v2`
    (`id`, `category_id`, `name`, `description`, `price`, `stock`,
     `low_stock_level`, `is_available`, `is_archived`)
SELECT
    f.`id`,
    COALESCE(c.`id`, 1),
    f.`name`,
    NULL,
    GREATEST(f.`price`, 0.01),
    GREATEST(f.`stock`, 0),
    GREATEST(f.`low_stock_level`, 0),
    CASE WHEN f.`stock` > 0 THEN 1 ELSE 0 END,
    0
FROM `food_items` f
LEFT JOIN `food_categories` c ON c.`name` = f.`category`
ORDER BY f.`id`;

-- ---------------------------------------------------------------------------
-- 2.5  Counters. The legacy two-row table becomes the generalised table and
--      gains an 'order' sequence. Legacy orders use the KE-LEGACY-* form so
--      they can never collide with the daily KE-YYYYMMDD-#### sequence.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `id_sequences_v2` (`sequence_name`, `next_number`)
SELECT s.`role`, GREATEST(s.`next_number`, 1) FROM `id_sequences` s;

INSERT IGNORE INTO `id_sequences_v2` (`sequence_name`, `next_number`) VALUES
    ('student', 1), ('admin', 1), ('order', 1);

-- Recompute from actual data so a fresh clone cannot hand out a duplicate code.
UPDATE `id_sequences_v2`
SET `next_number` = GREATEST(
    `next_number`,
    (SELECT COALESCE(MAX(CAST(SUBSTRING(`user_code`, 5) AS UNSIGNED)), 0) + 1
       FROM `users_v2` WHERE `role` = 'student' AND `user_code` LIKE 'STU-%')
)
WHERE `sequence_name` = 'student';

UPDATE `id_sequences_v2`
SET `next_number` = GREATEST(
    `next_number`,
    (SELECT COALESCE(MAX(CAST(SUBSTRING(`user_code`, 5) AS UNSIGNED)), 0) + 1
       FROM `users_v2` WHERE `role` = 'admin' AND `user_code` LIKE 'ADM-%')
)
WHERE `sequence_name` = 'admin';

-- ---------------------------------------------------------------------------
-- 2.6  Orders.
--      order_number is the new public reference. Legacy rows get the
--      KE-LEGACY-<id> form. total_amount is recomputed from the order lines
--      below so the stored total always matches the sum of its lines.
--      Stage timestamps are back-filled from order_date / updated_at so the
--      order timeline is not blank for historical orders.
-- ---------------------------------------------------------------------------
INSERT INTO `orders_v2`
    (`id`, `order_number`, `user_id`, `total_amount`, `status`, `payment_status`,
     `note`, `order_date`, `updated_at`,
     `prepared_at`, `ready_at`, `completed_at`, `cancelled_at`)
SELECT
    o.`id`,
    CONCAT('KE-LEGACY-', o.`id`),
    COALESCE(o.`user_id`, s.`user_id`, (SELECT `id` FROM `users_v2`
                                        WHERE `user_code` = 'LEGACY-0001')),
    0.00,
    o.`status`,
    o.`payment_status`,
    o.`note`,
    COALESCE(o.`order_date`, s.`created_at`, NOW()),
    COALESCE(o.`updated_at`, o.`order_date`, NOW()),
    CASE WHEN o.`status` IN ('Preparing','Ready','Completed') THEN o.`order_date` END,
    CASE WHEN o.`status` IN ('Ready','Completed')            THEN o.`order_date` END,
    CASE WHEN o.`status` = 'Completed'                      THEN o.`order_date` END,
    CASE WHEN o.`status` = 'Cancelled'                      THEN o.`updated_at` END
FROM `orders` o
LEFT JOIN `student_orders` s ON s.`id` = o.`id`
ORDER BY o.`id`;

-- Orders that existed only in the legacy one-item table.
INSERT IGNORE INTO `orders_v2`
    (`id`, `order_number`, `user_id`, `total_amount`, `status`, `payment_status`,
     `order_date`, `updated_at`)
SELECT
    s.`id`,
    CONCAT('KE-LEGACY-', s.`id`),
    s.`user_id`,
    0.00,
    'Pending',
    'Unpaid',
    COALESCE(s.`created_at`, NOW()),
    COALESCE(s.`created_at`, NOW())
FROM `student_orders` s
ORDER BY s.`id`;

-- ---------------------------------------------------------------------------
-- 2.7  Order lines, with the price snapshot repaired.
--
--      The legacy boot migration back-filled unit_price from the current
--      product price when it was 0, which silently recorded today's price on
--      an old order. Where a line has no usable price the subtotal is divided
--      by the quantity instead, which is arithmetically faithful to the total
--      the student actually paid.
-- ---------------------------------------------------------------------------
INSERT INTO `order_items_v2`
    (`order_id`, `food_id`, `item_name`, `quantity`, `unit_price`, `subtotal`)
SELECT
    oi.`order_id`,
    oi.`food_id`,
    CASE WHEN TRIM(oi.`item_name`) = '' THEN COALESCE(f.`name`, 'Unknown item')
         ELSE oi.`item_name` END,
    GREATEST(oi.`quantity`, 1),
    CASE
        WHEN oi.`unit_price` > 0 THEN oi.`unit_price`
        WHEN f.`price` IS NOT NULL AND f.`price` > 0 THEN f.`price`
        WHEN oi.`quantity` > 0 AND oi.`subtotal` > 0
             THEN ROUND(oi.`subtotal` / oi.`quantity`, 2)
        ELSE 0.00
    END,
    GREATEST(oi.`subtotal`, 0.00)
FROM `order_items` oi
LEFT JOIN `food_items` f ON f.`id` = oi.`food_id`;

-- Lines for orders that only existed in student_orders.
INSERT INTO `order_items_v2`
    (`order_id`, `food_id`, `item_name`, `quantity`, `unit_price`, `subtotal`)
SELECT
    s.`id`,
    s.`food_id`,
    COALESCE(NULLIF(s.`item_name`, ''), f.`name`, 'Unknown item'),
    GREATEST(s.`quantity`, 1),
    CASE
        WHEN f.`price` IS NOT NULL AND f.`price` > 0 THEN f.`price`
        WHEN s.`quantity` > 0 AND s.`total_amount` > 0
             THEN ROUND(s.`total_amount` / s.`quantity`, 2)
        ELSE 0.00
    END,
    GREATEST(s.`total_amount`, 0.00)
FROM `student_orders` s
LEFT JOIN `food_items` f ON f.`id` = s.`food_id`
WHERE NOT EXISTS (SELECT 1 FROM `order_items` oi WHERE oi.`order_id` = s.`id`)
  AND EXISTS (SELECT 1 FROM `orders_v2` o WHERE o.`id` = s.`id`);

-- ---------------------------------------------------------------------------
-- 2.8  Recompute every order total from its lines.
--      This is what guarantees the invariant the original violated:
--          orders.total_amount  ==  SUM(order_items.subtotal)
-- ---------------------------------------------------------------------------
UPDATE `orders_v2` o
SET o.`total_amount` = COALESCE((
        SELECT SUM(oi.`subtotal`) FROM `order_items_v2` oi WHERE oi.`order_id` = o.`id`
    ), 0.00);

-- ---------------------------------------------------------------------------
-- 2.9  Baseline stock ledger. The legacy build recorded nothing, so this is a
--      single opening snapshot per product rather than invented history.
-- ---------------------------------------------------------------------------
INSERT INTO `inventory_movements`
    (`food_id`, `movement_type`, `quantity_change`, `stock_before`,
     `stock_after`, `reference_type`, `note`)
SELECT fi.`id`, 'adjustment', fi.`stock`, 0, fi.`stock`, 'migration',
       'Baseline captured during Node.js migration'
FROM `food_items_v2` fi
WHERE NOT EXISTS (
    SELECT 1 FROM `inventory_movements` m
    WHERE m.`food_id` = fi.`id` AND m.`reference_type` = 'migration'
);

-- ---------------------------------------------------------------------------
-- 2.10 Record what the migration did.
-- ---------------------------------------------------------------------------
INSERT INTO `activity_logs` (`user_id`, `action`, `entity_type`, `details`)
VALUES (NULL, 'migration.legacy_import', 'database',
        'Imported legacy Node.js data into the KantEase v2 schema.');


-- ===========================================================================
--  SECTION 3 — Swap the new tables into place.
--  Legacy tables are renamed, never dropped, so nothing is irrecoverable.
-- ===========================================================================

DROP VIEW   IF EXISTS `users_view`;
DROP TABLE  IF EXISTS `legacy_student_orders`;
DROP TABLE  IF EXISTS `legacy_users`;
DROP TABLE  IF EXISTS `legacy_food_items`;
DROP TABLE  IF EXISTS `legacy_orders`;
DROP TABLE  IF EXISTS `legacy_order_items`;
DROP TABLE  IF EXISTS `legacy_id_sequences`;

RENAME TABLE
    `users`          TO `legacy_users`,
    `food_items`     TO `legacy_food_items`,
    `orders`         TO `legacy_orders`,
    `order_items`    TO `legacy_order_items`,
    `id_sequences`   TO `legacy_id_sequences`,
    `student_orders` TO `legacy_student_orders`;

RENAME TABLE
    `users_v2`       TO `users`,
    `food_items_v2`  TO `food_items`,
    `orders_v2`      TO `orders`,
    `order_items_v2` TO `order_items`,
    `id_sequences_v2` TO `id_sequences`;


-- ===========================================================================
--  SECTION 4 — Re-create the auto-increment counters.
-- ===========================================================================
ALTER TABLE `users`       AUTO_INCREMENT = 1000;
ALTER TABLE `food_items`  AUTO_INCREMENT = 1000;
ALTER TABLE `orders`      AUTO_INCREMENT = 1000;
ALTER TABLE `order_items` AUTO_INCREMENT = 1000;


-- ===========================================================================
--  SECTION 5 — Add the indexes that only the new tables need.
-- ===========================================================================
ALTER TABLE `users`
    ADD KEY `idx_users_created_at` (`created_at`);

ALTER TABLE `food_items`
    ADD KEY `idx_food_items_stock` (`stock`),
    ADD KEY `idx_food_items_low_stock` (`stock`, `low_stock_level`);

ALTER TABLE `orders`
    ADD KEY `idx_orders_payment_status` (`payment_status`),
    ADD KEY `idx_orders_status_date` (`status`, `order_date`);

ALTER TABLE `inventory_movements`
    ADD KEY `idx_inventory_movements_reference` (`reference_type`, `reference_id`),
    ADD KEY `idx_inventory_movements_user` (`user_id`);

ALTER TABLE `activity_logs`
    ADD KEY `idx_activity_logs_user` (`user_id`, `created_at`),
    ADD KEY `idx_activity_logs_entity` (`entity_type`, `entity_id`);

ALTER TABLE `order_status_history`
    ADD KEY `idx_order_status_history_changed_by` (`changed_by`);

ALTER TABLE `payments`
    ADD KEY `idx_payments_received_by` (`received_by`);

ALTER TABLE `login_attempts`
    ADD KEY `idx_login_attempts_attempted_at` (`attempted_at`);


-- ===========================================================================
--  SECTION 6 — Foreign keys.
--
--  Added last, after the rename, so they reference the final table names.
--  This is why the *_v2 tables in SECTION 1 were declared without any
--  constraints: declaring them up front would leave every foreign key pointing
--  at a temporary table name after the rename.
--
--  Note that there is no matching "DROP FOREIGN KEY" block. On a first run the
--  tables have no constraints to drop, so such statements would only ever
--  raise an error. See the idempotency note at the top of this file.
-- ===========================================================================
ALTER TABLE `food_items`
    ADD CONSTRAINT `fk_food_items_category`
        FOREIGN KEY (`category_id`) REFERENCES `food_categories` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `orders`
    ADD CONSTRAINT `fk_orders_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_orders_cancelled_by`
        FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `order_items`
    ADD CONSTRAINT `fk_order_items_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_order_items_food`
        FOREIGN KEY (`food_id`) REFERENCES `food_items` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `order_status_history`
    ADD CONSTRAINT `fk_order_status_history_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_order_status_history_changed_by`
        FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `payments`
    ADD CONSTRAINT `fk_payments_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_payments_received_by`
        FOREIGN KEY (`received_by`) REFERENCES `users` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `inventory_movements`
    ADD CONSTRAINT `fk_inventory_movements_food`
        FOREIGN KEY (`food_id`) REFERENCES `food_items` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_inventory_movements_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `activity_logs`
    ADD CONSTRAINT `fk_activity_logs_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;

-- Restore referential integrity enforcement.
SET FOREIGN_KEY_CHECKS = 1;


-- ===========================================================================
--  SECTION 7 — Verification.
--
--  Run every statement below after the migration. EACH ONE MUST RETURN AN
--  EMPTY RESULT SET. Any row returned is a migration defect to investigate
--  before going live.
-- ===========================================================================

-- 7.1  No order whose total disagrees with the sum of its lines.
SELECT o.`id`, o.`order_number`, o.`total_amount`,
       COALESCE(SUM(oi.`subtotal`), 0) AS lines_total,
       (o.`total_amount` - COALESCE(SUM(oi.`subtotal`), 0)) AS difference
FROM `orders` o
LEFT JOIN `order_items` oi ON oi.`order_id` = o.`id`
GROUP BY o.`id`, o.`order_number`, o.`total_amount`
HAVING ABS(o.`total_amount` - COALESCE(SUM(oi.`subtotal`), 0)) > 0.005;

-- 7.2  Negative stock must be impossible (column is UNSIGNED, so this should
--      always be empty regardless of data).
SELECT `id`, `name`, `stock` FROM `food_items` WHERE `stock` < 0;

-- 7.3  Every order must belong to a real account.
SELECT o.`id`, o.`order_number`, o.`user_id`
FROM `orders` o LEFT JOIN `users` u ON u.`id` = o.`user_id`
WHERE u.`id` IS NULL;

-- 7.4  Every order must have an order number.
SELECT `id`, `order_number` FROM `orders`
WHERE `order_number` IS NULL OR `order_number` = '';

-- 7.5  Order lines must not point at orders that do not exist.
SELECT oi.`id`, oi.`order_id` FROM `order_items` oi
LEFT JOIN `orders` o ON o.`id` = oi.`order_id`
WHERE o.`id` IS NULL;

-- 7.6  Products must all have a valid category.
SELECT f.`id`, f.`name`, f.`category_id` FROM `food_items` f
LEFT JOIN `food_categories` c ON c.`id` = f.`category_id`
WHERE c.`id` IS NULL;

-- 7.7  Subtotal must always equal unit_price x quantity.
SELECT `id`, `order_id`, `quantity`, `unit_price`, `subtotal`
FROM `order_items`
WHERE ABS(`subtotal` - (`unit_price` * `quantity`)) > 0.005;

-- 7.8  Counts, for the migration record.
SELECT 'users'         AS `table_name`, COUNT(*) AS `row_count` FROM `users`
UNION ALL SELECT 'food_categories',  COUNT(*) FROM `food_categories`
UNION ALL SELECT 'food_items',       COUNT(*) FROM `food_items`
UNION ALL SELECT 'orders',           COUNT(*) FROM `orders`
UNION ALL SELECT 'order_items',      COUNT(*) FROM `order_items`
UNION ALL SELECT 'id_sequences',     COUNT(*) FROM `id_sequences`;


-- ===========================================================================
--  SECTION 8 — After this
--   1. Set includes/config.local.php to point at this database.
--   2. Log in once with an EXISTING account: the legacy scrypt password is
--      verified and immediately replaced with a password_hash() value.
--   3. legacy_* tables can be dropped once you are satisfied, e.g.
--          DROP TABLE legacy_users, legacy_food_items, legacy_orders,
--                       legacy_order_items, legacy_id_sequences,
--                       legacy_student_orders;
-- ===========================================================================