/**
 * KantEase — MariaDB/MySQL schema validation (development tool, not shipped).
 *
 * Executes database/database.sql and database/migrate_from_node.sql against a
 * REAL server using the same statement splitter as the PHP installer, then
 * verifies the results. This is how the `transaction_isolation` incompatibility
 * was found: PHP was not available, but the MariaDB server was.
 *
 * Usage:  node tools/check-schema-live.js
 *
 * Requires credentials; it tries the XAMPP defaults and never prints a
 * password.
 */

const fs = require('fs');
const path = require('path');
const mariadb = require('mariadb');
const { splitStatements } = require('./lib/sql-split.js');

const ROOT = path.resolve(__dirname, '..');
const TMP = 'kantease_verify_schema';

/* ------------------------------------------------------------------ */

let pass = 0, fail = 0;
const problems = [];

function ok(label, detail) {
  pass++;
  console.log('  \x1b[32mPASS\x1b[0m  ' + label + (detail ? '  (' + detail + ')' : ''));
}
function bad(label, detail) {
  fail++;
  problems.push(label + ' — ' + detail);
  console.log('  \x1b[31mFAIL\x1b[0m  ' + label + '  (' + detail + ')');
}
function check(cond, label, detail) {
  cond ? ok(label, detail) : bad(label, detail);
}

/**
 * COUNT(*) comes back from the driver as a string on some builds, so every
 * numeric comparison goes through here. A previous version of this harness used
 * a strict === and reported four false failures.
 */
function count(row) {
  return Number(row && row.n !== undefined ? row.n : 0);
}

async function connect(overrides) {
  const creds = [
    { user: 'root', password: '' },
    { user: 'root', password: 'root' },
    { user: 'kantease_app', password: '' },
  ];

  for (const c of creds) {
    try {
      return await mariadb.createConnection(Object.assign({
        host: '127.0.0.1', connectTimeout: 4000, ...(overrides || {}),
      }, c));
    } catch (e) { /* try the next default */ }
  }
  throw new Error('Could not connect to MariaDB with any known default credential.');
}

(async () => {
  const root = await connect();

  const version = await root.query('SELECT VERSION() AS v');
  console.log('\n' + '='.repeat(74));
  console.log('  KantEase — live schema validation');
  console.log('='.repeat(74));
  console.log('\n  Server: ' + version[0].v + '\n');

  // ----------------------------------------------------------------
  console.log('  --- Session settings used by includes/database.php ----------\n');

  const SESSION_STATEMENTS = [
    ["sql_mode", "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"],
    ["time_zone", "SET SESSION time_zone = '+00:00'"],
    ["isolation", "SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED"],
  ];

  for (const [label, sql] of SESSION_STATEMENTS) {
    try {
      await root.query(sql);
      ok('SET ' + label, 'accepted');
    } catch (e) {
      bad('SET ' + label, '[' + e.code + '] ' + e.sqlMessage);
    }
  }

  const iso = await root.query("SHOW VARIABLES LIKE 'tx_isolation'");
  check(
    iso.length > 0 && iso[0].Value === 'READ-COMMITTED',
    'isolation level actually took effect',
    iso.length ? iso[0].Value : 'not reported'
  );

  // The statement that broke MariaDB, for the record.
  try {
    await root.query('SET SESSION transaction_isolation = "READ-COMMITTED"');
    bad('old MySQL-only form', 'unexpectedly accepted — is this really MariaDB?');
  } catch (e) {
    ok('old MySQL-only form correctly absent', '[' + e.code + '] as expected on MariaDB');
  }

  // ----------------------------------------------------------------
  console.log('\n  --- database.sql against a throwaway database --------------\n');

  await root.query('DROP DATABASE IF EXISTS `' + TMP + '`');
  await root.query('CREATE DATABASE `' + TMP + '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

  // Prove the connection really is pointed at the throwaway database, using a
  // connection that was opened with that database rather than a query option.
  const appConn = await connect({ database: TMP });
  const app = await appConn.query('SELECT DATABASE() AS d');
  check(app[0].d === TMP, 'throwaway database selected', app[0].d);
  await appConn.end();

  let script = fs.readFileSync(path.join(ROOT, 'database', 'database.sql'), 'utf8');
  script = script.split('`kantease_db`').join('`' + TMP + '`');

  let executed = 0;
  let installError = null;

  for (const stmt of splitStatements(script)) {
    if (/^\s*CREATE\s+DATABASE\b/i.test(stmt)) continue;
    try {
      await root.query({ database: TMP, sql: stmt });
      executed++;
    } catch (e) {
      installError = { stmt: stmt.slice(0, 110), code: e.code, message: e.sqlMessage };
      break;
    }
  }

  if (installError) {
    bad('database.sql installed', '[' + installError.code + '] ' + installError.message);
    console.log('        statement: ' + installError.stmt);
  } else {
    ok('database.sql installed', executed + ' statements');
  }

  if (!installError) {
    const t = await root.query(
      "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?", [TMP]);
    check(count(t[0]) >= 11, 'tables created', count(t[0]) + ' tables');

    const fk = await root.query(
      "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'", [TMP]);
    check(count(fk[0]) >= 8, 'foreign keys created', count(fk[0]) + ' constraints');

    for (const [label, sql] of [
      ['food_categories seeded', 'SELECT COUNT(*) AS n FROM food_categories'],
      ['food_items seeded', 'SELECT COUNT(*) AS n FROM food_items'],
      ['inventory ledger seeded', 'SELECT COUNT(*) AS n FROM inventory_movements'],
      ['id_sequences seeded', 'SELECT COUNT(*) AS n FROM id_sequences'],
    ]) {
      const r = await root.query({ database: TMP, sql });
      check(count(r[0]) > 0, label, count(r[0]) + ' rows');
    }

    // Foreign key enforcement must be real.
    await root.query({ database: TMP, sql: "INSERT INTO users (user_code, full_name, email, password, role) VALUES ('FK-T','T','fk@k.invalid','h','student')" });
    let fkBlocked = false;
    try {
      await root.query({ database: TMP, sql: "INSERT INTO orders (order_number, user_id, total_amount) VALUES ('FK-O', 999999, 1.00)" });
    } catch (e) { fkBlocked = true; }
    check(fkBlocked, 'foreign keys enforced', fkBlocked ? 'orphan insert refused' : 'ORPHAN ACCEPTED');
    await root.query({ database: TMP, sql: "DELETE FROM users WHERE user_code = 'FK-T'" });
  }

  // ----------------------------------------------------------------
  console.log('\n  --- Queries the repositories actually issue ----------------\n');

  if (!installError) {
    const REPO_QUERIES = [
      ['studentSummary', `SELECT COUNT(*) AS total_orders,
              SUM(status = 'Pending') AS pending,
              SUM(status = 'Preparing') AS preparing,
              SUM(status = 'Ready') AS ready,
              SUM(status = 'Completed') AS completed,
              SUM(status = 'Cancelled') AS cancelled,
              COALESCE(SUM(CASE WHEN status <> 'Cancelled' THEN total_amount ELSE 0 END), 0) AS order_value,
              COALESCE(SUM(CASE WHEN status <> 'Cancelled' AND payment_status = 'Paid' THEN total_amount ELSE 0 END), 0) AS paid_total,
              COALESCE(SUM(payment_status = 'Paid' AND status <> 'Cancelled'), 0) AS paid_orders,
              MAX(order_date) AS last_order_at
         FROM orders WHERE user_id = 1`],
      ['statusCounts', `SELECT SUM(status='Pending') AS pending, SUM(status='Preparing') AS preparing,
              SUM(status='Ready') AS ready, SUM(status='Completed') AS completed,
              SUM(status='Cancelled') AS cancelled, COUNT(*) AS total FROM orders`],
      ['adminListTotals', `SELECT COUNT(*) AS orders,
              SUM(status = 'Pending') AS pending,
              SUM(status = 'Completed') AS completed,
              SUM(payment_status = 'Paid') AS paid_orders,
              COALESCE(SUM(CASE WHEN status <> 'Cancelled' AND payment_status = 'Paid' THEN total_amount ELSE 0 END), 0) AS revenue_cents,
              COALESCE(SUM(CASE WHEN status <> 'Cancelled' THEN total_amount ELSE 0 END), 0) AS value_cents
         FROM orders o WHERE 1=1`],
      ['stock lock (FOR UPDATE)', `SELECT id, name, price, stock, low_stock_level, is_available, is_archived, category_id
         FROM food_items WHERE id IN (1,2) ORDER BY id FOR UPDATE`],
      ['guarded stock decrement', `UPDATE food_items SET stock = stock + ? WHERE id = ? AND stock + ? >= 0`, [0, 1, 0]],
      ['negative stock impossible', `SELECT COUNT(*) AS n FROM food_items WHERE stock < 0`],
      ['admin order search (GROUP_CONCAT subquery)', `SELECT o.id,
              (SELECT GROUP_CONCAT(oi.item_name ORDER BY oi.id SEPARATOR ', ') FROM order_items oi WHERE oi.order_id = o.id) AS item_summary
         FROM orders o JOIN users u ON u.id = o.user_id WHERE 1=1
         ORDER BY o.order_date DESC LIMIT 5 OFFSET 0`],
      ['lock admin rows then count (fixed)', `SELECT id FROM users WHERE role = ? AND is_active = 1 ORDER BY id FOR UPDATE`, ['admin']],
      ['daily sales trend', `SELECT DATE(order_date) AS period,
              COALESCE(SUM(CASE WHEN status <> 'Cancelled' AND payment_status = 'Paid' THEN total_amount ELSE 0 END), 0) AS revenue
         FROM orders WHERE order_date >= ? AND order_date <= ? GROUP BY period ORDER BY period`,
        ['2000-01-01 00:00:00', '2099-12-31 23:59:59']],
      ['weekly trend (WEEKDAY offset)', `SELECT DATE_FORMAT(order_date - INTERVAL WEEKDAY(order_date) DAY, '%Y-%m-%d') AS period,
              COUNT(*) AS orders FROM orders GROUP BY period`],
      ['best sellers', `SELECT oi.item_name, SUM(oi.quantity) AS units, c.name AS category
         FROM order_items oi JOIN orders o ON o.id = oi.order_id
         LEFT JOIN food_items f ON f.id = oi.food_id
         LEFT JOIN food_categories c ON c.id = f.category_id
        WHERE o.status <> 'Cancelled' GROUP BY oi.item_name, c.name
        ORDER BY units DESC LIMIT 10`],
      ['order total invariant', `SELECT o.id FROM orders o LEFT JOIN order_items oi ON oi.order_id = o.id
         GROUP BY o.id, o.total_amount
        HAVING ABS(o.total_amount - COALESCE(SUM(oi.subtotal),0)) > 0.005`],
      ['id_sequences lock', `SELECT next_number FROM id_sequences WHERE sequence_name = 'order' FOR UPDATE`],
      ['tableExists probe', `SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?`, ['users']],
      ['pagination clause', `SELECT id FROM orders LIMIT 25 OFFSET 0`],
    ];

    for (const [label, sql, params] of REPO_QUERIES) {
      try {
        await root.query({ database: TMP, sql, ...(params ? { namedPlaceholders: false, values: params } : {}) });
        ok('query: ' + label);
      } catch (e) {
        bad('query: ' + label, '[' + e.code + '] ' + e.sqlMessage);
      }
    }
  }

  // ----------------------------------------------------------------
  console.log('\n  --- migrate_from_node.sql against a synthetic legacy DB ------\n');

  const LEGACY = 'kantease_verify_legacy';

  await root.query('DROP DATABASE IF EXISTS `' + LEGACY + '`');
  await root.query('CREATE DATABASE `' + LEGACY + '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

  const legacy = await connect({ database: LEGACY });
  ok('throwaway legacy connection opened');

  const legacySchema = [
    `CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, user_code VARCHAR(20) NOT NULL UNIQUE,
      full_name VARCHAR(120) NOT NULL, email VARCHAR(254) NOT NULL UNIQUE, password VARCHAR(200) NOT NULL,
      role ENUM('student','admin') NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB`,
    `CREATE TABLE food_items (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL,
      price DECIMAL(10,2) NOT NULL, stock INT NOT NULL DEFAULT 0, low_stock_level INT NOT NULL DEFAULT 5,
      category VARCHAR(30) NOT NULL DEFAULT 'Meals') ENGINE=InnoDB`,
    `CREATE TABLE student_orders (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
      item_name VARCHAR(120) NOT NULL, quantity INT NOT NULL, food_id INT NULL,
      total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB`,
    `CREATE TABLE orders (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL,
      total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      order_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      status ENUM('Pending','Preparing','Ready','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
      payment_status ENUM('Unpaid','Paid') NOT NULL DEFAULT 'Unpaid', note VARCHAR(200) NULL,
      updated_at DATETIME NULL) ENGINE=InnoDB`,
    `CREATE TABLE order_items (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, food_id INT NULL,
      item_name VARCHAR(120) NOT NULL, quantity INT NOT NULL, unit_price DECIMAL(10,2) NOT NULL,
      subtotal DECIMAL(10,2) NOT NULL,
      FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
      FOREIGN KEY (food_id) REFERENCES food_items(id) ON DELETE SET NULL) ENGINE=InnoDB`,
    `CREATE TABLE id_sequences (role ENUM('student','admin') PRIMARY KEY, next_number INT NOT NULL) ENGINE=InnoDB`,
  ];

  for (const sql of legacySchema) await legacy.query(sql);

  await legacy.query(`INSERT INTO users (user_code, full_name, email, password, role) VALUES
    ('STU-0001','Ana Reyes','ana@school.ph','0123456789abcdef0123456789abcdef:abc','student'),
    ('STU-0002','Ben Cruz','ben@school.ph','fedcba9876543210fedcba9876543210:abc','student'),
    ('ADM-0001','Canteen Admin','admin@school.ph','a1b2c3d4e5f60718293a4b5c6d7e8f90:abc','admin')`);
  await legacy.query(`INSERT INTO food_items (id, name, price, stock, low_stock_level, category) VALUES
    (1,'Chicken Rice',75.00,20,5,'Meals'), (2,'Siomai',35.00,25,5,'Snacks'), (3,'Sold Out',15.00,0,5,'Meals')`);
  await legacy.query("INSERT INTO student_orders (id, user_id, item_name, quantity, food_id, total_amount) VALUES (500,1,'Siomai',2,2,70.00)");
  await legacy.query(`INSERT INTO orders (id, user_id, total_amount, order_date, status, payment_status) VALUES
    (100,1,110.00,'2026-09-01 10:00:00','Completed','Paid'),
    (101,2,35.00,'2026-09-02 11:00:00','Cancelled','Unpaid'),
    (102,NULL,75.00,'2026-09-03 12:00:00','Pending','Unpaid')`);
  await legacy.query(`INSERT INTO order_items (order_id, food_id, item_name, quantity, unit_price, subtotal) VALUES
    (100,1,'Chicken Rice',1,75.00,75.00), (100,2,'Siomai',1,35.00,35.00)`);
  await legacy.query("INSERT INTO id_sequences (role, next_number) VALUES ('student',3),('admin',2)");

  ok('synthetic legacy database built');

  const migration = fs.readFileSync(path.join(ROOT, 'database', 'migrate_from_node.sql'), 'utf8')
    .split('`canteen_db`').join('`' + LEGACY + '`');

  let mExec = 0, mError = null;
  for (const stmt of splitStatements(migration)) {
    try {
      await legacy.query(stmt);
      mExec++;
    } catch (e) {
      mError = { stmt: stmt.slice(0, 120), code: e.code, message: e.sqlMessage };
      break;
    }
  }

  if (mError) {
    bad('migrate_from_node.sql ran', '[' + mError.code + '] ' + mError.message);
    console.log('        statement: ' + mError.stmt);
  } else {
    ok('migrate_from_node.sql ran', mExec + ' statements');

    const orphan = await legacy.query(`SELECT u.user_code FROM orders o JOIN users u ON u.id = o.user_id
      WHERE o.order_number = 'KE-LEGACY-102'`);
    check(orphan.length === 1 && orphan[0].user_code === 'LEGACY-0001',
      'order with no owner preserved', orphan.length ? orphan[0].user_code : 'LOST');

    const m500 = await legacy.query("SELECT COUNT(*) AS n FROM orders WHERE order_number = 'KE-LEGACY-500'");
    check(count(m500[0]) === 1, 'legacy one-item order migrated', count(m500[0]) + ' row');

    const legacyHashes = await legacy.query("SELECT COUNT(*) AS n FROM users WHERE password_legacy IS NOT NULL AND password_legacy <> ''");
    check(count(legacyHashes[0]) === 3, 'legacy credentials parked', count(legacyHashes[0]) + ' of 3');

    const mism = await legacy.query(`SELECT o.id FROM orders o LEFT JOIN order_items oi ON oi.order_id = o.id
      GROUP BY o.id, o.total_amount HAVING ABS(o.total_amount - COALESCE(SUM(oi.subtotal),0)) > 0.005`);
    check(mism.length === 0, 'every order total equals its lines', mism.length ? mism.length + ' MISMATCH' : 'consistent');

    const cats = await legacy.query('SELECT COUNT(*) AS n FROM food_categories');
    check(count(cats[0]) >= 2, 'categories carried across', count(cats[0]) + ' categories');

    const lfk = await legacy.query(
      "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'", [LEGACY]);
    check(count(lfk[0]) >= 8, 'foreign keys added after the rename', count(lfk[0]) + ' constraints');

    const legacyTables = await legacy.query(
      "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE 'legacy\\_%'", [LEGACY]);
    check(count(legacyTables[0]) === 6, 'original tables preserved as legacy_*', count(legacyTables[0]) + ' tables');
  }

  // ----------------------------------------------------------------
  console.log('\n  --- cleanup ---------------------------------------------------\n');

  for (const name of [TMP, LEGACY]) {
    await root.query('DROP DATABASE IF EXISTS `' + name + '`');
    ok('dropped ' + name);
  }

  console.log('\n' + '='.repeat(74));
  console.log('  ' + pass + ' passed, ' + fail + ' failed');

  if (problems.length) {
    console.log('\n  Problems:');
    problems.forEach(p => console.log('    - ' + p));
  }

  console.log('\n  RESULT: ' + (fail === 0 ? 'PASS' : 'FAIL') + '\n');

  await root.end();
  process.exit(fail === 0 ? 0 : 1);
})().catch(e => {
  console.error('\nHARNESS ERROR: ' + e.message + '\n');
  process.exit(1);
});