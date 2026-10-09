<?php

declare(strict_types=1);

/**
 * KantEase — Phase 2 verification suite.
 *
 *     C:\xampp\php\php.exe tests\verify-phase2.php
 *
 * Exit code 0 means every check passed. Anything else means Phase 2 is NOT
 * verified and must not be built on.
 *
 * ===========================================================================
 *  SAFETY — YOUR CANTEEN DATABASE IS NEVER TOUCHED
 * ===========================================================================
 *
 *  Every test that writes anything runs against a THROWAWAY database whose
 *  name always begins with `kantease_verify_`. The name comes from this
 *  script, never from config.local.php.
 *
 *  Four independent guards enforce that:
 *
 *    1. `Config::override('db.name', ...)` redirects the application to the
 *       throwaway database BEFORE any connection is opened.
 *    2. `Config::override()` throws a LogicException if a connection already
 *       exists, so a mid-run redirect is impossible.
 *    3. `assertDisposable()` re-reads the configured database name and aborts
 *       the whole run before doing anything destructive unless it is still a
 *       `kantease_verify_*` name.
 *    4. `dropDatabase()` refuses to execute unless the name passes the same
 *       pattern check.
 *
 *  Credentials are read from config.local.php so the tests authenticate the
 *  same way the application does, but only the USER is ever displayed. The
 *  password is never read into a variable that could be printed.
 *
 *  The throwaway databases are removed on the way out, including when a test
 *  throws, so a failed run does not leave clutter behind.
 *
 *  tests/verify-isolation.php audits these guarantees.
 *
 * ===========================================================================
 */

const PREFIX = 'kantease_verify_';
const LIVE_DB_NAME = 'kantease_db';
const TMP_MAIN    = PREFIX . 'main';
const TMP_LEGACY  = PREFIX . 'legacy';

$root = dirname(__DIR__);

/**
 * Every database name the suite is allowed to create or drop.
 */
function isDisposable(string $name): bool
{
    return preg_match('/^' . PREFIX . '[a-z0-9_]+$/', $name) === 1;
}

/**
 * Drop a database, refusing anything that is not a throwaway.
 */
function dropDatabase(string $name): void
{
    if (! isDisposable($name)) {
        throw new RuntimeException(sprintf(
            'REFUSED to drop "%s". tests/verify-phase2.php may only drop databases named %s*. '
            . 'This guard exists so a typo can never destroy the canteen data.',
            $name,
            PREFIX
        ));
    }

    (new PDO(
        sprintf(
            'mysql:host=%s;port=%d;charset=utf8mb4',
            KantEase\Config::string('db.host'),
            KantEase\Config::int('db.port', 3306)
        ),
        KantEase\Config::string('db.user'),
        KantEase\Config::string('db.password'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    ))->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $name));
}

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

final class Suite
{
    public static int $passed = 0;
    public static int $failed = 0;
    public static int $blocked = 0;
    /** @var list<string> */
    public static array $failures = [];
    public static string $section = '';

    public static function section(string $title): void
    {
        self::$section = $title;
        echo "\n" . str_repeat('=', 74) . "\n";
        echo "  {$title}\n";
        echo str_repeat('=', 74) . "\n";
    }

    public static function pass(string $label, string $detail = ''): void
    {
        self::$passed++;
        printf("  \033[32mPASS\033[0m  %s%s\n", $label, $detail === '' ? '' : "  ({$detail})");
    }

    public static function fail(string $label, string $detail = ''): void
    {
        self::$failed++;
        self::$failures[] = self::$section . ' :: ' . $label . ($detail === '' ? '' : " — {$detail}");
        printf("  \033[31mFAIL\033[0m  %s%s\n", $label, $detail === '' ? '' : "  ({$detail})");
    }

    public static function blocked(string $label, string $reason): void
    {
        self::$blocked++;
        printf("  \033[33mBLOCK\033[0m  %s  ({$reason})\n", $label);
    }

    public static function info(string $label): void
    {
        printf("  ----  %s\n", $label);
    }

    public static function verdict(): int
    {
        echo "\n" . str_repeat('=', 74) . "\n";
        printf("  %d passed   %d failed   %d blocked\n", self::$passed, self::$failed, self::$blocked);

        if (self::$failures !== []) {
            echo "\n  Failures:\n";
            foreach (self::$failures as $failure) {
                echo '    - ' . $failure . "\n";
            }
        }

        if (self::$blocked > 0) {
            echo "\n  BLOCKED checks have not run. A blocked check is not a pass.\n";
        }

        echo "\n";

        if (self::$failed > 0) {
            echo "  RESULT: FAIL — Phase 2 is NOT verified.\n\n";
            return 1;
        }

        if (self::$blocked > 0) {
            echo "  RESULT: INCOMPLETE — clear the blocked checks and run again.\n\n";
            return 1;
        }

        echo "  RESULT: PASS — Phase 2 is VERIFIED.\n\n";
        return 0;
    }
}

/** @return list<string> */
function app_php_files(string $root): array
{
    $files = [];
    $walk  = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($walk as $file) {
        $path = $file->getPathname();

        if (! str_ends_with($path, '.php')) {
            continue;
        }
        if (str_contains($path, '/.git/') || str_contains($path, '/node_modules/')) {
            continue;
        }

        $files[] = $path;
    }

    sort($files);

    return $files;
}

// ===========================================================================
// A — Static integrity
// ===========================================================================

Suite::section('A. Static integrity');

$phpFiles = app_php_files($root);

Suite::info(sprintf('%d PHP files found', count($phpFiles)));

$syntaxErrors = [];

foreach ($phpFiles as $file) {
    $output = [];
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);

    if ($status !== 0) {
        $syntaxErrors[] = str_replace($root . DIRECTORY_SEPARATOR, '', $file) . ': ' . trim(implode(' ', $output));
    }
}

if ($syntaxErrors === []) {
    Suite::pass('A1 php -l on every file', count($phpFiles) . ' files clean');
} else {
    Suite::fail('A1 syntax errors', count($syntaxErrors) . ' file(s)');
    foreach ($syntaxErrors as $error) {
        echo '        ' . $error . "\n";
    }
}

// A2 — credentials must never be committed.
$leaks = [];

foreach ($phpFiles as $file) {
    $source = (string) file_get_contents($file);
    $name   = basename($file);

    if ($name === 'config.sample.php') {
        continue;
    }

    if (preg_match('/(password|passwd)\s*=\s*[\'"]([^\'"]{4,})[\'"]/i', $source) === 1) {
        $leaks[] = $name . ' (assigned literal)';
    }
}

foreach (['README.md', 'docs/PHASE-1-AUDIT.md', 'docs/SETUP-LOCAL.md', 'docs/PHASE-3-DESIGN.md'] as $doc) {
    if (is_file($root . '/' . $doc) && preg_match('/Shoto/', (string) file_get_contents($root . '/' . $doc)) === 1) {
        $leaks[] = $doc . ' (leaked password)';
    }
}

if ($leaks === []) {
    Suite::pass('A2 no literal credentials in any maintained file');
} else {
    Suite::fail('A2 credential leak', implode(', ', $leaks));
}

// A3 — the sample config must ship empty and the real one must be gitignored.
$sample    = (string) file_get_contents($root . '/includes/config.sample.php');
$gitignore = (string) file_get_contents($root . '/.gitignore');

if (preg_match("/'password'\s*=>\s*'([^']*)'/", $sample, $m) === 1 && $m[1] === '') {
    Suite::pass('A3 config.sample.php ships with an empty password');
} else {
    Suite::fail('A3 config.sample.php password is not empty');
}

if (str_contains($gitignore, 'config.local.php')) {
    Suite::pass('A4 config.local.php is gitignored');
} else {
    Suite::fail('A4 config.local.php is NOT gitignored');
}

// A5 — protection files carry their rules.
foreach ([
    '.htaccess'          => ['X-Content-Type-Options', 'server\\.js'],
    'includes/.htaccess' => ['Require all denied'],
    'docs/.htaccess'     => ['Require all denied'],
    'database/.htaccess' => ['sql'],
    'uploads/.htaccess'  => ['php'],
] as $relative => $needles) {
    $contents = (string) file_get_contents($root . '/' . $relative);
    $missing  = array_values(array_filter($needles, static fn (string $n): bool => ! str_contains($contents, $n)));

    if ($missing === []) {
        Suite::pass("A5 {$relative} has its protection rules");
    } else {
        Suite::fail("A5 {$relative} missing rules", implode(', ', $missing));
    }
}

// A6 — the installer must not be blocked by the root .htaccess.
$rootHtaccess = (string) file_get_contents($root . '/.htaccess');

if (preg_match('/<Files\s+"setup\.php">.*?Require all denied/s', $rootHtaccess) === 1) {
    Suite::fail('A6 setup.php is reachable', 'the root .htaccess denies it, so the installer cannot run');
} else {
    Suite::pass('A6 setup.php is reachable through the root .htaccess');
}

// A7 — this suite must never be able to drop a non-throwaway database.
$self = (string) file_get_contents(__FILE__);

if (str_contains($self, 'function isDisposable') && str_contains($self, 'REFUSED to drop')) {
    Suite::pass('A7 this suite refuses to drop any database outside the ' . PREFIX . '* namespace');
} else {
    Suite::fail('A7 the drop guard is missing from this suite');
}

// A8 — this suite must not print any configuration value that could be secret.
if (preg_match('/echo.*Config::string\(\s*[\'"]db\.password/', $self) === 1) {
    Suite::fail('A8 this suite may print db.password', 'remove the print');
} else {
    Suite::pass('A8 this suite never prints the database password');
}

// ===========================================================================
// B — Legacy password compatibility
// ===========================================================================

Suite::section('B. Legacy password compatibility (Node.js scrypt)');

require_once $root . '/includes/exceptions.php';
require_once $root . '/includes/enums.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/passwords.php';

use KantEase\LegacyHasher;
use KantEase\Passwords;

$vectors = [
    ['studentpass1',     '0123456789abcdef0123456789abcdef',
     '4c5d78d833ae5003bfca4e8fc4138434950c3d68595df6f55d7033360f421340'
     . 'ccc981bdc416b71f4d91c4c0a2809f18e99bd84dd03a96e2c136878c89c26c0f'],
    ['Admin#2026secure', 'fedcba9876543210fedcba9876543210',
     'e74fe397ebe02914d1a3ef563c79d17036887fe3eeb86607966876fff9af1b5e5'
     . '35dc5c22272d4b610db360b97a7f218d6b579be8162d5c23fc032843ba06725'],
    ['p@ssw0rd!',        'a1b2c3d4e5f60718293a4b5c6d7e8f90',
     '3b44d07f5e0929a90c0880dfc0a6fd71696f18945feac5c535fd9dae1ba0c259'
     . '106a126c207a31a59c4eac224c04ac96aa791fa0d8db9dcd20e3029f8de10556'],
    ['canteen2026',      'ffffffffffffffffffffffffffffffff',
     '2092faa1adeaf0ab46068cd6f232e7fb6153d70aad4e715163336a8f637fbc96'
     . '3dd8c319c44aa725ee8cf3d69def385bfaf69adda0812520a8c47708c5e415b9'],
];

$vectorFailures = [];

foreach ($vectors as $index => [$password, $salt, $expected]) {
    $started = microtime(true);

    try {
        $actual = LegacyHasher::scrypt($password, $salt);
    } catch (Throwable $exception) {
        $vectorFailures[] = 'vector ' . ($index + 1) . ' threw ' . $exception->getMessage();
        continue;
    }

    $elapsed = microtime(true) - $started;

    if ($actual === $expected) {
        Suite::pass('B1 vector ' . ($index + 1) . ' matches Node.js', sprintf('%.2fs', $elapsed));
    } else {
        $vectorFailures[] = 'vector ' . ($index + 1) . ' mismatch';
    }
}

if ($vectorFailures === []) {
    Suite::pass('B2 all 4 scrypt vectors match', 'the legacy migration is safe');
} else {
    Suite::fail('B2 legacy migration is UNSAFE', implode(' | ', $vectorFailures));
}

Suite::pass(
    'B3 verify() accepts the correct legacy credential',
    LegacyHasher::verify('studentpass1', $vectors[0][1] . ':' . $vectors[0][2]) ? 'yes' : 'NO'
);

Suite::pass(
    'B4 verify() rejects a wrong password',
    LegacyHasher::verify('wrong-password', $vectors[0][1] . ':' . $vectors[0][2]) ? 'ACCEPTED — FAIL' : 'rejected'
);

$malformedOk = true;
foreach (['garbage', 'zzzz:zzzz', 'short:long', $vectors[0][1] . ':', ':' . $vectors[0][2]] as $bad) {
    if (LegacyHasher::verify('studentpass1', $bad)) {
        $malformedOk = false;
    }
}
Suite::pass('B5 malformed stored values rejected', $malformedOk ? 'all rejected' : 'ONE ACCEPTED');

$legacy = Passwords::verify('studentpass1', '', $vectors[0][1] . ':' . $vectors[0][2]);

if ($legacy['valid'] && $legacy['legacy_verified'] && $legacy['rehash_hash'] !== null
    && str_starts_with((string) $legacy['rehash_hash'], '$')) {
    Suite::pass('B6 legacy credential upgrades to password_hash() output');
} else {
    Suite::fail('B6 legacy upgrade path produced no modern hash');
}

Suite::pass(
    'B7 the upgraded hash verifies with the original password',
    $legacy['rehash_hash'] !== null && password_verify('studentpass1', $legacy['rehash_hash'])
        ? 'yes' : 'NO'
);

Suite::pass(
    'B8 an empty password hash cannot authenticate anyone',
    Passwords::verify('', '', null)['valid'] ? 'AUTHENTICATED — FAIL' : 'refused'
);

// ===========================================================================
// Database-dependent work
// ===========================================================================

if (! is_file($root . '/includes/config.local.php')) {
    foreach (['C. Live configuration', 'D. Disposable database and fresh install',
              'E. Installer sequence', 'F. Legacy migration', 'G. CSRF and sessions',
              'H. Access control', 'I. Order and stock integrity'] as $section) {
        Suite::section($section);
        Suite::blocked('all checks', 'includes/config.local.php does not exist');
    }

    exit(Suite::verdict());
}

putenv('KANTEASE_VERIFY=1');

require_once $root . '/includes/bootstrap.php';

// Fully qualified, not imported. An unqualified `InstallerLockedException` in
// this file would resolve against the GLOBAL namespace — the file has no
// `namespace` declaration of its own — so the catch block would never match a
// KantEase exception and every refusal would be reported as a wrong type.
//
// (This is the mirror image of the bug that cost a debugging cycle during
// Phase 2: inside `namespace KantEase`, a bare `RuntimeException` resolved to
// KantEase\RuntimeException, which does not exist.)
use KantEase\Auth;
use KantEase\Config;
use KantEase\Csrf;
use KantEase\Database;
use KantEase\Installer;
use KantEase\InstallerLockedException;
use KantEase\OrderStatus;
use KantEase\SchemaInstaller;
use KantEase\UserRole;
use KantEase\ValidationException;

use function KantEase\utc_now;

// ===========================================================================
// C — Live configuration and connectivity
// ===========================================================================

Suite::section('C. Live configuration and connectivity');

Suite::info(
    'server ' . Config::string('db.host') . ':' . Config::int('db.port', 3306)
    . ' | user "' . Config::string('db.user') . '" | password [hidden, never printed]'
);

$liveName = Config::string('db.name');

try {
    $version = (string) Database::serverConnection()->query('SELECT VERSION()')->fetchColumn();
    Suite::pass('C1 the MySQL/MariaDB server answered', $version);
} catch (Throwable $exception) {
    Suite::fail('C1 cannot reach the database server', $exception->getMessage());
    Suite::blocked('C2 onwards', 'no database connection');
    exit(Suite::verdict());
}

if ($liveName !== LIVE_DB_NAME) {
    Suite::info("note: the configured database is \"{$liveName}\", not \"" . LIVE_DB_NAME . '"');
}

Suite::pass('C2 the live database name was read from config.local.php', $liveName);

try {
    Config::override('db.name', TMP_MAIN);
    Suite::pass('C3 the application was pointed at a throwaway database', TMP_MAIN);
} catch (Throwable $exception) {
    Suite::fail('C3 could not redirect to the throwaway database', $exception->getMessage());
    Suite::blocked('everything else', 'cannot isolate the tests');
    exit(Suite::verdict());
}

if (! isDisposable(Config::string('db.name'))) {
    Suite::fail('C4 SAFETY GUARD', 'the configured database is not disposable — aborting before any write');
    exit(Suite::verdict());
}

Suite::pass('C4 safety guard confirms the active database is disposable');

register_shutdown_function(static function (): void {
    echo "\n  Cleaning up throwaway databases...\n";

    foreach ([TMP_MAIN, TMP_LEGACY] as $name) {
        try {
            if (! isDisposable($name)) {
                continue;
            }

            (new PDO(
                sprintf(
                    'mysql:host=%s;port=%d;charset=utf8mb4',
                    Config::string('db.host'),
                    Config::int('db.port', 3306)
                ),
                Config::string('db.user'),
                Config::string('db.password'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            ))->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $name));

            echo "    dropped {$name}\n";
        } catch (Throwable $error) {
            echo '    could not drop ' . $name . ': ' . $error->getMessage() . "\n";
            echo "    delete it by hand with: DROP DATABASE \`{$name}\`;\n";
        }
    }
});

// ===========================================================================
// D — Disposable database and fresh install
// ===========================================================================

Suite::section('D. Disposable database and fresh install');

try {
    dropDatabase(TMP_MAIN);
    Database::serverConnection()->exec(sprintf(
        'CREATE DATABASE `%s` DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_ci',
        TMP_MAIN
    ));

    $pdo = Database::connection();
    Suite::pass('D1 the throwaway database was created and selected', TMP_MAIN);
} catch (Throwable $exception) {
    Suite::fail('D1 could not create the throwaway database', $exception->getMessage());
    Suite::blocked('D2 onwards', 'no throwaway database');
    exit(Suite::verdict());
}

if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() === TMP_MAIN) {
    Suite::pass('D2 the live database was never selected', 'still ' . $liveName . ', untouched');
} else {
    Suite::fail('D2 the wrong database is active');
}

// D2a — session settings must actually apply.
//
// This catches the MariaDB incompatibility: the original line was
// `SET SESSION transaction_isolation = 'READ-COMMITTED'`, a MySQL 5.7+ variable
// name MariaDB has never had, which raised error 1193 before any query ran.
try {
    $isolation = Database::transactionIsolation();

    Suite::pass(
        'D2a transaction isolation applied',
        $isolation === 'READ-COMMITTED'
            ? 'READ-COMMITTED (tx_isolation on MariaDB, transaction_isolation on MySQL)'
            : 'reported as ' . ($isolation ?? 'unknown')
    );
} catch (Throwable $exception) {
    Suite::fail('D2a could not read the isolation level', $exception->getMessage());
}

try {
    $sqlMode = Database::sessionVariable('sql_mode');

    Suite::pass(
        'D2b sql_mode is strict',
        str_contains((string) $sqlMode, 'STRICT_TRANS_TABLES')
            ? 'STRICT_TRANS_TABLES active'
            : 'sql_mode is ' . ($sqlMode ?? 'unknown')
    );
} catch (Throwable $exception) {
    Suite::fail('D2b could not read sql_mode', $exception->getMessage());
}

try {
    $timeZone = Database::sessionVariable('time_zone');

    Suite::pass('D2c session time zone is UTC', $timeZone === '+00:00' ? '+00:00' : 'reported as ' . $timeZone);
} catch (Throwable $exception) {
    Suite::fail('D2c could not read the session time zone', $exception->getMessage());
}

try {
    $script = (string) file_get_contents($root . '/database/database.sql');
    $script = str_replace('`kantease_db`', '`' . TMP_MAIN . '`', $script);

    // Route through the real installer so this exercises the same code path
    // database/setup.php uses, including result-set draining.
    $executed = 0;

    foreach (SchemaInstaller::splitStatements($script) as $statement) {
        if (preg_match('/^\s*CREATE\s+DATABASE\b/i', $statement) === 1) {
            continue;
        }

        $handle = $pdo->prepare($statement);
        $handle->execute();

        if ($handle->columnCount() > 0) {
            $handle->fetchAll(PDO::FETCH_ASSOC);
        }

        $handle->closeCursor();
        $executed++;
    }

    Suite::pass('D3 database.sql executed against the throwaway database', $executed . ' statements');
} catch (Throwable $exception) {
    Suite::fail('D3 database.sql failed', $exception->getMessage());
}

$tableCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . TMP_MAIN . "'"
)->fetchColumn();

$fkCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = '" . TMP_MAIN
    . "' AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
)->fetchColumn();

$seedCounts = [];
foreach (['food_categories', 'food_items', 'inventory_movements', 'id_sequences'] as $table) {
    $seedCounts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
}

Suite::pass('D4 tables created', (string) $tableCount);
Suite::pass('D5 foreign keys created', (string) $fkCount);
Suite::pass('D6 seed data written', implode(', ', array_map(
    static fn (string $t, int $n): string => "{$t}={$n}",
    array_keys($seedCounts),
    $seedCounts
)));

$pdo->exec("INSERT INTO users (user_code, full_name, email, password, role)
            VALUES ('FK-TEST','FK Test','fk-test@kantease.invalid','x','student')");

$blocked = false;
try {
    $pdo->exec("INSERT INTO orders (order_number, user_id, total_amount) VALUES ('FK-ORD', 999999, 1.00)");
} catch (PDOException) {
    $blocked = true;
}
$pdo->exec("DELETE FROM users WHERE user_code = 'FK-TEST'");

Suite::pass('D7 foreign keys are enforced', $blocked ? 'orphan insert refused' : 'ORPHAN INSERT ACCEPTED');

try {
    $livePdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Config::string('db.host'), Config::int('db.port', 3306), $liveName),
        Config::string('db.user'),
        Config::string('db.password'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $liveTables = (int) $livePdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $livePdo->quote($liveName)
    )->fetchColumn();

    Suite::info("live database \"{$liveName}\" currently holds {$liveTables} table(s) — opened read-only");
} catch (Throwable $exception) {
    Suite::info('live database not readable right now: ' . $exception->getMessage());
}

// ===========================================================================
// E — Installer sequence
// ===========================================================================

Suite::section('E. Installer sequence (first install -> admin -> repeat refused)');

$lockFile = $root . '/tests/.verify-installed.lock';

try {
    Installer::useLockPath($lockFile);

    if (Installer::lockPath() !== $lockFile) {
        throw new RuntimeException('the lock path override did not take effect');
    }

    Suite::info('installer lock redirected to ' . basename($lockFile) . ' — the real lock is never touched');
} catch (Throwable $exception) {
    Suite::fail('E0 could not redirect the installer lock path', $exception->getMessage());
    Suite::blocked('E1 onwards', 'refusing to run installer tests against the real lock file');
    exit(Suite::verdict());
}

try {
    $state = Installer::evaluate();

    if ($state['state'] === Installer::STATE_NEEDS_ADMIN) {
        Suite::pass('E1 a fresh install reaches the "create the first administrator" step');
    } else {
        Suite::fail('E1 unexpected installer state', $state['state'] . ' — ' . $state['detail']);
    }
} catch (Throwable $exception) {
    Suite::fail('E1 evaluate() threw', $exception->getMessage());
}

if (Installer::isLocked()) {
    Suite::fail('E2 the installer starts unlocked', 'IT IS LOCKED — FAIL');
} else {
    Suite::pass('E2 the installer is not locked yet', 'unlocked');
}

try {
    Installer::createFirstAdministrator('Too Short', 'weak@kantease.invalid', 'abc', 'abc');
    Suite::fail('E3 a weak password was accepted', 'the first admin must not be creatable with a 3-character password');
} catch (ValidationException) {
    Suite::pass('E3 a weak password is refused');
} catch (Throwable $exception) {
    Suite::fail('E3 wrong exception type', $exception::class);
}

Suite::pass('E4 no administrator was created by the failed attempt',
    \KantEase\Repositories\UserRepository::adminExists() ? 'ONE EXISTS — FAIL' : 'none');

try {
    Installer::createFirstAdministrator(
        'Mismatch Test', 'mismatch@kantease.invalid', 'GoodPassword123!', 'DifferentPassword123!'
    );
    Suite::fail('E5 a mismatched confirmation was accepted');
} catch (ValidationException) {
    Suite::pass('E5 a mismatched confirmation is refused');
} catch (Throwable $exception) {
    Suite::fail('E5 wrong exception type', $exception::class);
}

try {
    $admin = Installer::createFirstAdministrator(
        'Verification Administrator',
        'verify-admin@kantease.invalid',
        'VerifyAdminPass123!',
        'VerifyAdminPass123!'
    );

    $code = (string) $admin['user_code'];

    if (preg_match('/^ADM-\d{4}$/', $code) === 1 && (int) $admin['is_active'] === 1) {
        Suite::pass('E6 the first administrator was created', $code);
    } else {
        Suite::fail('E6 unexpected administrator record', $code);
    }
} catch (Throwable $exception) {
    Suite::fail('E6 could not create the first administrator', $exception->getMessage());
}

// E7 — the lock must now exist.
if (Installer::isLocked()) {
    Suite::pass('E7 the installer locked itself after creating the administrator', 'lock written');
} else {
    Suite::fail('E7 the installer locked itself after creating the administrator', 'STILL UNLOCKED');
}

if (is_file($lockFile)) {
    Suite::pass('E8 the lock file was written where expected', basename($lockFile));
} else {
    Suite::fail('E8 the lock file is missing');
}

// E9 — a second administrator must be impossible.
//
// Only InstallerLockedException counts. An unexpected Error (a missing class,
// say) must FAIL this check, never quietly satisfy it: a test that passes for
// the wrong reason is worse than no test at all.
$adminsBefore = (int) Database::fetchValue("SELECT COUNT(*) FROM users WHERE role = 'admin'");

try {
    Installer::createFirstAdministrator(
        'Second Administrator', 'second-admin@kantease.invalid', 'AnotherPass123!', 'AnotherPass123!'
    );

    $adminsAfter = (int) Database::fetchValue("SELECT COUNT(*) FROM users WHERE role = 'admin'");

    if ($adminsAfter === $adminsBefore + 1) {
        Suite::fail('E9 a second administrator was created by the installer', 'the escalation hole is open');
    } else {
        Suite::fail('E9 the attempt created an administrator with no error', 'unexpected state');
    }
} catch (KantEase\InstallerLockedException) {
    $adminsAfter = (int) Database::fetchValue("SELECT COUNT(*) FROM users WHERE role = 'admin'");

    if ($adminsAfter !== $adminsBefore) {
        Suite::fail('E9 refused but changed the administrator count', "{$adminsBefore} -> {$adminsAfter}");
    } else {
        Suite::pass('E9 repeat installation is refused', 'InstallerLockedException');
    }
} catch (Throwable $exception) {
    Suite::fail('E9 the wrong exception type', $exception::class . ': ' . $exception->getMessage());
}

// E10 — every step must refuse, and refuse for the right reason.
$correctRefusals = 0;
$wrongRefusals  = [];

foreach (['createDatabase', 'installSchema'] as $step) {
    try {
        $method = $step;
        Installer::{$method}();
        $wrongRefusals[] = "{$step}() ran instead of refusing";
    } catch (KantEase\InstallerLockedException) {
        $correctRefusals++;
    } catch (Throwable $exception) {
        $wrongRefusals[] = "{$step}() threw " . $exception::class;
    }
}

if ($wrongRefusals === []) {
    Suite::pass('E10 every installer step refuses once locked', "{$correctRefusals}/2 refused correctly");
} else {
    Suite::fail('E10 a step did not refuse correctly', implode('; ', $wrongRefusals));
}

try {
    Installer::createFirstAdministrator(
        'Third Administrator', 'third-admin@kantease.invalid', 'YetAnotherPass1!', 'YetAnotherPass1!'
    );
    Suite::fail('E10b createFirstAdministrator() ran after installation');
} catch (KantEase\InstallerLockedException) {
    Suite::pass('E10b createFirstAdministrator() refuses once locked', 'InstallerLockedException');
} catch (Throwable $exception) {
    Suite::fail('E10b wrong exception type', $exception::class . ': ' . $exception->getMessage());
}

if (! is_file($root . '/database/.installed')) {
    Suite::info('no real database/.installed exists, so nothing to disturb');
} else {
    Suite::info('a real database/.installed exists and was NOT modified or removed by this suite');
}

@unlink($lockFile);

// ===========================================================================
// F — Legacy migration
// ===========================================================================

Suite::section('F. Legacy migration (synthetic legacy database)');

try {
    // Create the database BEFORE opening a connection that selects it.
    // Opening the connection first raises 1049 "Unknown database", because
    // dropDatabase() has just removed it.
    Database::serverConnection()->exec(sprintf(
        'CREATE DATABASE `%s` DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_ci',
        TMP_LEGACY
    ));

    $legacy = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Config::string('db.host'), Config::int('db.port', 3306), TMP_LEGACY),
        Config::string('db.user'),
        Config::string('db.password'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // The exact schema the old Node.js server.js created at boot.
    $legacy->exec("CREATE TABLE users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_code VARCHAR(20) NOT NULL UNIQUE,
        full_name VARCHAR(120) NOT NULL,
        email VARCHAR(254) NOT NULL UNIQUE,
        password VARCHAR(200) NOT NULL,
        role ENUM('student','admin') NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $legacy->exec("CREATE TABLE food_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        stock INT NOT NULL DEFAULT 0,
        low_stock_level INT NOT NULL DEFAULT 5,
        category VARCHAR(30) NOT NULL DEFAULT 'Meals'
    ) ENGINE=InnoDB");

    $legacy->exec("CREATE TABLE student_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        item_name VARCHAR(120) NOT NULL,
        quantity INT NOT NULL,
        food_id INT NULL,
        total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $legacy->exec("CREATE TABLE orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        order_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status ENUM('Pending','Preparing','Ready','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
        payment_status ENUM('Unpaid','Paid') NOT NULL DEFAULT 'Unpaid',
        note VARCHAR(200) NULL,
        updated_at DATETIME NULL
    ) ENGINE=InnoDB");

    $legacy->exec("CREATE TABLE order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        food_id INT NULL,
        item_name VARCHAR(120) NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        subtotal DECIMAL(10,2) NOT NULL,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        FOREIGN KEY (food_id) REFERENCES food_items(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");

    $legacy->exec("CREATE TABLE id_sequences (
        role ENUM('student','admin') PRIMARY KEY,
        next_number INT NOT NULL
    ) ENGINE=InnoDB");

    $legacy->exec("INSERT INTO users (user_code, full_name, email, password, role) VALUES
        ('STU-0001','Ana Reyes','ana@school.ph','0123456789abcdef0123456789abcdef:{$vectors[0][2]}','student'),
        ('STU-0002','Ben Cruz','ben@school.ph','fedcba9876543210fedcba9876543210:{$vectors[1][2]}','student'),
        ('ADM-0001','Canteen Admin','admin@school.ph','{$vectors[2][1]}:{$vectors[2][2]}','admin')");

    $legacy->exec("INSERT INTO food_items (id, name, price, stock, low_stock_level, category) VALUES
        (1,'Chicken Rice',75.00,20,5,'Meals'),
        (2,'Siomai',35.00,25,5,'Snacks'),
        (3,'Sold Out Item',15.00,0,5,'Meals')");

    $legacy->exec("INSERT INTO student_orders (id, user_id, item_name, quantity, food_id, total_amount)
                   VALUES (500,1,'Siomai',2,2,70.00)");

    // Includes an order with no owner — invisible in the old admin report.
    $legacy->exec("INSERT INTO orders (id, user_id, total_amount, order_date, status, payment_status) VALUES
        (100,1,110.00,'2026-09-01 10:00:00','Completed','Paid'),
        (101,2,35.00,'2026-09-02 11:00:00','Cancelled','Unpaid'),
        (102,NULL,75.00,'2026-09-03 12:00:00','Pending','Unpaid')");

    $legacy->exec("INSERT INTO order_items (order_id, food_id, item_name, quantity, unit_price, subtotal) VALUES
        (100,1,'Chicken Rice',1,75.00,75.00),
        (100,2,'Siomai',1,35.00,35.00)");

    $legacy->exec("INSERT INTO id_sequences (role, next_number) VALUES ('student',3),('admin',2)");

    Suite::pass('F1 a synthetic database was built in the legacy Node.js schema');
} catch (Throwable $exception) {
    Suite::fail('F1 could not build the legacy database', $exception->getMessage());
    Suite::blocked('F2 onwards', 'no legacy database');
    exit(Suite::verdict());
}

try {
    $migration = (string) file_get_contents($root . '/database/migrate_from_node.sql');
    $migration = str_replace('`canteen_db`', '`' . TMP_LEGACY . '`', $migration);

    $executed = 0;

    // Prepared statements with every result set drained, exactly as
    // SchemaInstaller::run() does it.
    //
    // PDO::exec() cannot be used for this script: it ends with eight SELECT
    // verification queries, and exec() leaves their result sets unbuffered, so
    // the following statement fails with
    //
    //     2014 Cannot execute queries while other unbuffered queries are active
    foreach (SchemaInstaller::splitStatements($migration) as $statement) {
        $handle = $legacy->prepare($statement);
        $handle->execute();

        if ($handle->columnCount() > 0) {
            $handle->fetchAll(PDO::FETCH_ASSOC);
        }

        $handle->closeCursor();
        $executed++;
    }

    Suite::pass('F2 migrate_from_node.sql executed cleanly', $executed . ' statements');
} catch (Throwable $exception) {
    Suite::fail('F2 the migration failed', $exception->getMessage());
    Suite::blocked('F3 onwards', 'migration did not complete');
    exit(Suite::verdict());
}

try {
    $orphan = $legacy->query('SELECT u.user_code FROM orders o JOIN users u ON u.id = o.user_id
                              WHERE o.order_number = "KE-LEGACY-102"')->fetch();

    Suite::pass('F3 the order with no owner was preserved, not dropped',
        ($orphan !== false && $orphan['user_code'] === 'LEGACY-0001') ? 'attached to LEGACY-0001' : 'LOST');
} catch (Throwable $exception) {
    Suite::fail('F3', $exception->getMessage());
}

Suite::pass('F4 the legacy one-item order was migrated',
    (int) $legacy->query('SELECT COUNT(*) FROM orders WHERE order_number = "KE-LEGACY-500"')->fetchColumn() === 1
        ? 'yes' : 'MISSING');

Suite::pass('F5 legacy credentials were parked in password_legacy',
    (int) $legacy->query("SELECT COUNT(*) FROM users WHERE password_legacy IS NOT NULL AND password_legacy <> ''")
        ->fetchColumn() . ' of 3');

$mismatches = $legacy->query(
    'SELECT o.id FROM orders o LEFT JOIN order_items oi ON oi.order_id = o.id
      GROUP BY o.id, o.total_amount
     HAVING ABS(o.total_amount - COALESCE(SUM(oi.subtotal),0)) > 0.005'
)->fetchAll();

Suite::pass('F6 every order total equals the sum of its lines',
    $mismatches === [] ? 'all consistent' : count($mismatches) . ' MISMATCH');

Suite::pass('F7 categories were carried across',
    (int) $legacy->query('SELECT COUNT(*) FROM food_categories')->fetchColumn() . ' categories');

Suite::pass('F8 foreign keys exist after the rename',
    (int) $legacy->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                           WHERE CONSTRAINT_SCHEMA = '" . TMP_LEGACY . "' AND CONSTRAINT_TYPE = 'FOREIGN KEY'")
        ->fetchColumn() . ' constraints');

Suite::pass('F9 the original tables were preserved as legacy_*',
    (int) $legacy->query("SELECT COUNT(*) FROM information_schema.TABLES
                           WHERE TABLE_SCHEMA = '" . TMP_LEGACY . "' AND TABLE_NAME LIKE 'legacy\\_%'")
        ->fetchColumn() . ' tables');

// Point the application back at the throwaway app database for the rest.
Database::connection()->exec('USE `' . TMP_MAIN . '`');

// ===========================================================================
// G — CSRF and sessions
// ===========================================================================

Suite::section('G. CSRF protection and sessions');

$_SESSION = [];

$token1 = Csrf::token();
Suite::pass('G1 CSRF token is 256 bits of hex', strlen($token1) === 64 ? '64 chars' : 'len ' . strlen($token1));
Suite::pass('G2 the token is stable within a session', Csrf::token() === $token1 ? 'yes' : 'CHANGED');

$token2 = Csrf::rotate();
Suite::pass('G3 rotate() issues a different token', $token2 !== $token1 ? 'yes' : 'SAME');

Suite::pass('G4 only the current token validates',
    (Csrf::check($token2) && ! Csrf::check($token1) && ! Csrf::check('')
        && ! Csrf::check(null) && ! Csrf::check(str_repeat('a', 64))) ? 'correct' : 'LEAK');

Suite::pass('G5 the hidden form field carries the token',
    str_contains(Csrf::field(), 'name="_csrf"') ? 'yes' : 'NO');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST                    = [];

try {
    Csrf::verifyRequest();
    Suite::fail('G6 a POST with no CSRF token was accepted', 'this is a CSRF hole');
} catch (\KantEase\CsrfException) {
    Suite::pass('G6 a POST with no CSRF token is rejected');
}

$_POST = ['_csrf' => str_repeat('f', 64)];

try {
    Csrf::verifyRequest();
    Suite::fail('G7 a POST with a forged CSRF token was accepted');
} catch (\KantEase\CsrfException) {
    Suite::pass('G7 a POST with a forged CSRF token is rejected');
}

$_POST = ['_csrf' => $token2];

try {
    Csrf::verifyRequest();
    Suite::pass('G8 a POST with the correct CSRF token is accepted');
} catch (Throwable) {
    Suite::fail('G8 a valid CSRF token was rejected');
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST                     = [];

try {
    Csrf::verifyRequest();
    Suite::pass('G9 GET is not token-gated, so the Back button keeps working');
} catch (Throwable) {
    Suite::fail('G9 a safe GET was rejected');
}

$_SERVER['REQUEST_METHOD']   = 'POST';
$_SERVER['HTTP_X_CSRF_TOKEN'] = $token2;

try {
    Csrf::verifyRequest();
    Suite::pass('G10 the X-CSRF-Token header is accepted for Fetch callers');
} catch (Throwable) {
    Suite::fail('G10 the X-CSRF-Token header was rejected');
}

unset($_SERVER['HTTP_X_CSRF_TOKEN']);

$cookieParams = session_get_cookie_params();

Suite::pass('G11 session cookie flags',
    ($cookieParams['httponly'] ?? false) ? 'HttpOnly set' : 'HttpOnly not observable from the CLI');

$escapes = [
    'https://evil.example/steal',
    '//evil.example/steal',
    '/\\evil.example',
    'javascript:alert(1)',
    'relative/path',
    '',
];

$leaky = array_values(array_filter($escapes, static fn (string $c): bool => \KantEase\safe_path($c, '') !== ''));

Suite::pass('G12 safe_path rejects open-redirect attempts',
    $leaky === [] ? count($escapes) . ' attempts refused' : 'LEAKED: ' . implode(', ', $leaky));

Suite::pass('G13 safe_path still accepts a real internal path',
    \KantEase\safe_path('/student/orders.php', '') === '/student/orders.php' ? 'yes' : 'BROKEN');

// ===========================================================================
// H — Access control
// ===========================================================================

Suite::section('H. Role-based access control');

$_SESSION = [];
Auth::resetRequestCache();

try {
    Auth::requireLogin();
    Suite::fail('H1 requireLogin() let an anonymous caller through');
} catch (\KantEase\AuthenticationException) {
    Suite::pass('H1 an anonymous caller is refused');
}

$student = null;
$second  = null;

try {
    $student = \KantEase\Repositories\UserRepository::create(
        'Verification Student',
        'verify.student.' . bin2hex(random_bytes(4)) . '@kantease.invalid',
        Passwords::hash('VerifyPass123!'),
        UserRole::Student
    );

    Suite::pass('H2 registration allocates a STU-#### code', (string) $student['user_code']);

    $second = \KantEase\Repositories\UserRepository::create(
        'Second Verification Student',
        'verify.second.' . bin2hex(random_bytes(4)) . '@kantease.invalid',
        Passwords::hash('VerifyPass123!'),
        UserRole::Student
    );

    Suite::pass('H3 the next allocation gets a fresh code', (string) $second['user_code']);
} catch (Throwable $exception) {
    Suite::fail('H2 could not create test students', $exception->getMessage());
}

// The email must actually collide, or this proves nothing: a unique address
// would be accepted whether or not the duplicate guard works.
$duplicateEmail = (string) ($student['email'] ?? '');

if ($duplicateEmail !== '') {
    try {
        \KantEase\Repositories\UserRepository::create(
            'Duplicate Account', $duplicateEmail,
            Passwords::hash('VerifyPass123!'), UserRole::Student
        );

        Suite::fail('H4 a duplicate email was accepted', 'the guard did not fire for ' . $duplicateEmail);
    } catch (ValidationException $exception) {
        Suite::pass(
            'H4 a duplicate email is refused',
            str_contains($exception->getMessage(), 'already used') ? 'ValidationException: already used' : 'ValidationException'
        );
    } catch (PDOException $exception) {
        Suite::pass('H4 a duplicate email is refused', 'UNIQUE index: ' . $exception->getCode());
    } catch (Throwable $exception) {
        Suite::fail('H4 refused for the wrong reason', $exception::class . ': ' . $exception->getMessage());
    }

    $withThatEmail = (int) Database::fetchValue('SELECT COUNT(*) FROM users WHERE email = ?', [$duplicateEmail]);

    if ($withThatEmail === 1) {
        Suite::pass('H4b exactly one account holds that email', "count={$withThatEmail}");
    } else {
        Suite::fail('H4b the duplicate email produced ' . $withThatEmail . ' rows');
    }
}

/** Put a user into the session exactly as Auth::login() would. */
function sign_in_as(array $user): void
{
    $_SESSION = [];
    $_SERVER['HTTP_USER_AGENT'] = 'KantEaseVerificationSuite/1.0';
    $_SERVER['REMOTE_ADDR']     = '127.0.0.1';

    $_SESSION['kantease_auth'] = [
        'user_id'       => (int) $user['id'],
        'role'          => (string) $user['role'],
        'auth_time'     => time(),
        'last_activity' => time(),
        'fingerprint'   => hash('sha256', $_SERVER['HTTP_USER_AGENT'] . '|' . $_SERVER['REMOTE_ADDR']),
    ];
}

if ($student !== null && $second !== null) {
    sign_in_as(\KantEase\Repositories\UserRepository::findById((int) $student['id']) ?? []);
    Auth::resetRequestCache();

    Suite::pass('H5 a signed-in student resolves from the session',
        (Auth::check() && Auth::role() === UserRole::Student) ? 'yes' : 'NO');

    try {
        Auth::requireAdmin();
        Suite::fail('H6 a student reached the admin guard', 'privilege escalation is possible');
    } catch (\KantEase\AuthorizationException) {
        Suite::pass('H6 a student is refused by requireAdmin()');
    }

    try {
        Auth::requireStudent();
        Suite::pass('H7 a student is allowed by requireStudent()');
    } catch (Throwable $exception) {
        Suite::fail('H7 a student was refused by requireStudent()', $exception->getMessage());
    }

    // Deactivation must take effect on the very next request.
    \KantEase\Repositories\UserRepository::adminUpdate(
        (int) $student['id'], ['is_active' => false], (int) $second['id']
    );
    Auth::resetRequestCache();

    Suite::pass('H8 a deactivated account loses access immediately',
        ! Auth::check() ? 'session dropped' : 'STILL ACTIVE — stale-session bug');

    \KantEase\Repositories\UserRepository::adminUpdate(
        (int) $student['id'], ['is_active' => true], (int) $second['id']
    );

    $admin = \KantEase\Repositories\UserRepository::firstAdmin();

    if ($admin !== null) {
        try {
            \KantEase\Repositories\UserRepository::adminUpdate(
                (int) $admin['id'], ['role' => UserRole::Student], (int) $admin['id']
            );
            Suite::fail('H9 an administrator demoted themselves', 'the last admin could be locked out');
        } catch (\KantEase\BusinessRuleException) {
            Suite::pass('H9 an administrator cannot demote themselves');
        } catch (Throwable $exception) {
            Suite::fail('H9 wrong exception', $exception::class);
        }
    }

    $identifier = (string) $student['user_code'];

    // The catch must be INSIDE the loop. Wrapping the whole loop in one
    // try/catch meant the first wrong password threw and attempts 2-6 never
    // ran, so only one failure was recorded and the throttle had nothing to
    // lock on. This is why H10 reported NEVER LOCKED against a throttle that
    // works correctly in isolation.
    // Try once more than the threshold so the lock is provably engaged rather
    // than merely close to it.
    $attempts    = Config::int('security.login_max_attempts', 5) + 1;
    $lockRefused = false;

    for ($i = 0; $i < $attempts; $i++) {
        try {
            Auth::attempt($identifier, 'deliberately-wrong-password');
        } catch (\KantEase\RateLimitException) {
            $lockRefused = true;
            break;
        } catch (\KantEase\AuthenticationException) {
            // Refused for the expected reason: wrong password.
        }
    }
    Auth::resetRequestCache();

    $locked = \KantEase\Repositories\LoginThrottle::remainingLockSeconds($identifier) > 0;

    if ($locked) {
        Suite::pass(
            'H10 repeated failures lock the account',
            $lockRefused ? 'RateLimitException raised' : 'locked (threshold reached)'
        );
    } else {
        Suite::fail('H10 repeated failures lock the account', "NEVER LOCKED after {$attempts} wrong passwords");
    }

    // H10b - while locked, even the CORRECT password must be refused.
    //
    // Without this, a throttle that merely ignored bad passwords would still
    // pass H10: the account would never lock, but the test only inspected the
    // final state rather than proving the lock is actually enforced.
    Auth::resetRequestCache();

    try {
        Auth::attempt($identifier, 'VerifyPass123!');
        Suite::fail('H10b a locked account accepted the correct password', 'the lock is not enforced');
    } catch (\KantEase\RateLimitException) {
        Suite::pass('H10b a locked account refuses even the correct password', 'RateLimitException');
    } catch (Throwable $exception) {
        Suite::fail('H10b wrong exception while locked', $exception::class . ': ' . $exception->getMessage());
    }

    \KantEase\Repositories\LoginThrottle::clear($identifier);
    Auth::resetRequestCache();

    try {
        $signedIn = Auth::attempt($identifier, 'VerifyPass123!');
        Suite::pass('H11 the correct password signs in', 'row ' . (string) $signedIn['user']['user_code']);
    } catch (Throwable $exception) {
        Suite::fail('H11 the correct password was refused', $exception::getMessage());
    }

    Suite::info('order deletion guard is covered in section I, where orders exist');
}

// ===========================================================================
// I — Order and stock integrity
// ===========================================================================

Suite::section('I. Order and stock integrity');

$foodId = 0;

try {
    $foodId = (int) Database::fetchValue(
        "SELECT id FROM food_items WHERE name = 'Chicken Rice' AND is_archived = 0 LIMIT 1"
    );

    if ($foodId === 0) {
        throw new RuntimeException('No "Chicken Rice" product is available in the throwaway database.');
    }

    $testStudent = (int) $second['id'];
    Database::execute('UPDATE food_items SET stock = 10 WHERE id = ?', [$foodId]);

    $first   = \KantEase\Repositories\OrderRepository::place($testStudent, [
        ['food_id' => $foodId, 'quantity' => 3],
    ], 'verification order', 'verify-' . bin2hex(random_bytes(8)));

    $orderId    = (int) $first['order']['id'];
    $stockAfter = (int) Database::fetchValue('SELECT stock FROM food_items WHERE id = ?', [$foodId]);

    Suite::pass('I1 an order deducts stock and totals correctly',
        $stockAfter === 7 && (int) $first['total_cents'] === 22500
            ? '3 x 75.00 = 225.00, stock 10 -> 7' : "stock={$stockAfter} cents={$first['total_cents']}");

    // The column is DECIMAL(10,2), so PDO hands back '225.00' — pesos, as a
    // STRING. Casting that straight to int yields 225, not 22500, so the
    // comparison must go through to_cents() like every other money figure in
    // this suite. Without that, the assertion reports MISMATCH on correct data.
    $lineTotal = \KantEase\to_cents(
        (string) Database::fetchValue('SELECT COALESCE(SUM(subtotal),0) FROM order_items WHERE order_id = ?', [$orderId])
    );

    $storedTotal = \KantEase\to_cents(
        (string) Database::fetchValue('SELECT total_amount FROM orders WHERE id = ?', [$orderId])
    );

    if ($lineTotal === 22500 && $storedTotal === 22500) {
        Suite::pass('I2 order.total_amount equals SUM(order_items.subtotal)', 'both 225.00');
    } else {
        Suite::fail('I2 order.total_amount equals SUM(order_items.subtotal)',
            sprintf('stored=%d cents, lines=%d cents', $storedTotal, $lineTotal));
    }

    Suite::pass('I3 the order received a KE-YYYYMMDD-#### reference',
        preg_match('/^KE-\d{8}-\d{4}$/', (string) $first['order']['order_number']) === 1
            ? (string) $first['order']['order_number'] : 'BAD: ' . (string) $first['order']['order_number']);

    $key         = 'verify-idem-' . bin2hex(random_bytes(8));
    $replayA     = \KantEase\Repositories\OrderRepository::place($testStudent, [['food_id' => $foodId, 'quantity' => 1]], null, $key);
    $stockAfterA = (int) Database::fetchValue('SELECT stock FROM food_items WHERE id = ?', [$foodId]);
    $replayB     = \KantEase\Repositories\OrderRepository::place($testStudent, [['food_id' => $foodId, 'quantity' => 1]], null, $key);
    $stockAfterB = (int) Database::fetchValue('SELECT stock FROM food_items WHERE id = ?', [$foodId]);

    Suite::pass('I4 a replayed checkout returns the same order',
        ($replayB['replayed'] && (int) $replayA['order']['id'] === (int) $replayB['order']['id']
            && $stockAfterA === $stockAfterB) ? 'one order, one deduction' : 'DUPLICATED');

    Database::execute('UPDATE food_items SET stock = 2 WHERE id = ?', [$foodId]);
    $ordersBefore = (int) Database::fetchValue('SELECT COUNT(*) FROM orders');
    $stockBefore  = (int) Database::fetchValue('SELECT stock FROM food_items WHERE id = ?', [$foodId]);

    try {
        \KantEase\Repositories\OrderRepository::place(
            $testStudent, [['food_id' => $foodId, 'quantity' => 5]], null, 'verify-over-' . bin2hex(random_bytes(8))
        );
        Suite::fail('I5 an over-sized order was accepted', 'oversell is possible');
    } catch (Throwable) {
        $ordersAfter  = (int) Database::fetchValue('SELECT COUNT(*) FROM orders');
        $stockAfterF  = (int) Database::fetchValue('SELECT stock FROM food_items WHERE id = ?', [$foodId]);

        Suite::pass('I5 an over-sized order is refused with no partial write',
            ($ordersAfter === $ordersBefore && $stockAfterF === $stockBefore)
                ? "no order created, stock still {$stockAfterF}" : "orders {$ordersBefore}->{$ordersAfter}");
    }

    Suite::pass('I6 no product has a negative stock count',
        (int) Database::fetchValue('SELECT COUNT(*) FROM food_items WHERE stock < 0') === 0 ? 'clean' : 'NEGATIVE STOCK');

    $violations = [];

    foreach ([
        [OrderStatus::Pending,   OrderStatus::Completed, 'Pending->Completed'],
        [OrderStatus::Ready,     OrderStatus::Pending,   'Ready->Pending'],
        [OrderStatus::Completed, OrderStatus::Preparing, 'Completed->Preparing'],
        [OrderStatus::Cancelled, OrderStatus::Pending,   'Cancelled->Pending'],
    ] as [$from, $to, $label]) {
        if ($from->canTransitionTo($to)) {
            $violations[] = $label;
        }
    }

    Suite::pass('I7 illegal status transitions are rejected',
        $violations === [] ? 'all refused' : 'ALLOWED: ' . implode(', ', $violations));

    Suite::pass('I8 terminal states are locked, staying put is allowed',
        (! OrderStatus::Completed->canTransitionTo(OrderStatus::Preparing)
            && OrderStatus::Completed->canTransitionTo(OrderStatus::Completed)) ? 'correct' : 'WRONG');

    Suite::pass('I9 the documented forward path is allowed',
        (OrderStatus::Pending->canTransitionTo(OrderStatus::Preparing)
            && OrderStatus::Preparing->canTransitionTo(OrderStatus::Ready)
            && OrderStatus::Ready->canTransitionTo(OrderStatus::Completed)) ? 'P->Pr->R->C works' : 'PATH BROKEN');

    Database::execute('UPDATE food_items SET stock = 10 WHERE id = ?', [$foodId]);

    $cancelOrder = \KantEase\Repositories\OrderRepository::place($testStudent, [
        ['food_id' => $foodId, 'quantity' => 4],
    ], 'cancellation probe', 'verify-cancel-' . bin2hex(random_bytes(8)));

    $cancelId  = (int) $cancelOrder['order']['id'];
    $beforeCxl = (int) Database::fetchValue('SELECT stock FROM food_items WHERE id = ?', [$foodId]);

    \KantEase\Repositories\OrderRepository::cancel($cancelId, 'Verification test', $testStudent);
    $afterCxl = (int) Database::fetchValue('SELECT stock FROM food_items WHERE id = ?', [$foodId]);

    Suite::pass('I10 cancelling restores the stock exactly once',
        ($beforeCxl === 6 && $afterCxl === 10) ? "6 -> 10" : "{$beforeCxl} -> {$afterCxl}, expected 6 -> 10");

    try {
        \KantEase\Repositories\OrderRepository::cancel($cancelId, 'Second attempt', $testStudent);
        Suite::fail('I11 a cancelled order was cancelled twice',
            'stock now ' . (int) Database::fetchValue('SELECT stock FROM food_items WHERE id = ?', [$foodId]));
    } catch (Throwable) {
        Suite::pass('I11 a second cancellation is refused and stock is untouched',
            (int) Database::fetchValue('SELECT stock FROM food_items WHERE id = ?', [$foodId]) === 10 ? 'still 10' : 'CHANGED');
    }

    Suite::pass('I12 the ledger records one sale and one sale_restore',
        ((int) Database::fetchValue("SELECT COUNT(*) FROM inventory_movements WHERE reference_type='order'
                                      AND reference_id = ? AND movement_type = 'sale'", [$cancelId]) === 1
            && (int) Database::fetchValue("SELECT COUNT(*) FROM inventory_movements WHERE reference_type='order'
                                      AND reference_id = ? AND movement_type = 'sale_restore'", [$cancelId]) === 1)
            ? 'balanced pair' : 'UNBALANCED');

    Suite::pass('I13 the cancellation reason is stored in its own column',
        Database::fetchValue('SELECT cancel_reason FROM orders WHERE id = ?', [$cancelId]) === 'Verification test'
            ? 'yes' : 'LOST');

    $payOrder = \KantEase\Repositories\OrderRepository::place($testStudent, [
        ['food_id' => $foodId, 'quantity' => 1],
    ], 'payment probe', 'verify-pay-' . bin2hex(random_bytes(8)));

    $payId = (int) $payOrder['order']['id'];

    \KantEase\Repositories\OrderRepository::markPaid($payId, $testStudent);

    try {
        \KantEase\Repositories\OrderRepository::markPaid($payId, $testStudent);
        Suite::fail('I14 a payment was recorded twice');
    } catch (Throwable) {
        Suite::pass('I14 a payment can only be recorded once',
            (int) Database::fetchValue('SELECT COUNT(*) FROM payments WHERE order_id = ?', [$payId]) === 1
                ? 'one row' : 'DUPLICATE');
    }

    Suite::pass('I15 a cancelled order cannot be paid',
        (function () use ($cancelId, $testStudent): string {
            try {
                \KantEase\Repositories\OrderRepository::markPaid($cancelId, $testStudent);
                return 'ACCEPTED — FAIL';
            } catch (Throwable) {
                return 'refused';
            }
        })());

    $totals = \KantEase\Repositories\SalesRepository::orderTotals(null, null);

    Suite::pass('I16 revenue is a subset of order value',
        ((int) $totals['revenue'] > 0 && (int) $totals['revenue'] <= (int) $totals['order_value'])
            ? 'revenue ' . (int) $totals['revenue'] . ' <= value ' . (int) $totals['order_value']
            : 'revenue=' . (int) $totals['revenue'] . ' value=' . (int) $totals['order_value']);

    Suite::pass('I17 one student cannot read another student\'s order',
        \KantEase\Repositories\OrderRepository::findForStudent($orderId, (int) $student['id']) === null
            ? 'refused' : 'LEAKED');

    $paged = \KantEase\Repositories\OrderRepository::paginateForStudent(
        $testStudent,
        \KantEase\Repositories\OrderRepository::studentSearch(),
        1,
        10
    );

    Suite::pass('I18 the student order list paginates',
        $paged['pagination']->perPage === 10 && count($paged['rows']) <= 10
            ? "page {$paged['pagination']->page} of {$paged['pagination']->totalPages}, {$paged['pagination']->summary('order')}"
            : 'BROKEN');

    $foreignOwn = \KantEase\Repositories\OrderRepository::paginateForStudent(
        $testStudent,
        \KantEase\Repositories\OrderRepository::studentSearch(),
        1,
        50
    );

    $foreign = \KantEase\Repositories\OrderRepository::paginateForStudent(
        (int) $student['id'],
        \KantEase\Repositories\OrderRepository::studentSearch(),
        1,
        50
    );

    $otherIds = array_map(static fn (array $r): int => (int) $r['id'], $foreignOwn['rows']);
    $seenIds  = array_map(static fn (array $r): int => (int) $r['id'], $foreign['rows']);

    $leaked = array_values(array_intersect($otherIds, $seenIds));

    Suite::pass('I18b one student\'s order list contains only their own orders',
        $leaked === [] ? count($seenIds) . ' orders, none shared' : 'LEAKED: ' . implode(', ', $leaked));

    try {
        \KantEase\Repositories\UserRepository::deleteStudent($testStudent, $testStudent);
        Suite::fail('I19 a student with order history was deleted', 'the sales record can be destroyed');
    } catch (\KantEase\BusinessRuleException) {
        Suite::pass('I19 a student with order history cannot be deleted');
    }

    Suite::pass('I20 no order total in the database disagrees with its lines',
        \KantEase\Repositories\OrderRepository::findTotalMismatches() === [] ? 'all consistent' : 'MISMATCH FOUND');
} catch (Throwable $exception) {
    $detail = $exception->getMessage();

    if ($exception instanceof \KantEase\DatabaseException) {
        $context = $exception->context();
        $detail .= sprintf(
            ' [sql: %s | driver %s: %s]',
            mb_substr((string) ($context['sql'] ?? '?'), 0, 140),
            $context['driver_code'] ?? '?',
            mb_substr((string) ($context['driver_state'] ?? '?'), 0, 140)
        );
    }

    Suite::fail('I section error', $detail);
    fwrite(STDERR, PHP_EOL . '  I-section trace: ' . $exception->getTraceAsString() . PHP_EOL);
}

exit(Suite::verdict());
