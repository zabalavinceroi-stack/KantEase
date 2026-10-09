<?php

declare(strict_types=1);

/**
 * KantEase — Phase 4B verification suite (food and category management).
 *
 *     D:\hatdog\php\php.exe tests\verify-phase4b.php
 *
 * Exit code 0 means every check passed.
 *
 * ===========================================================================
 *  WHY A THROWAWAY DATABASE
 * ===========================================================================
 *
 * Food management WRITES. It creates products, moves stock, renames categories
 * and uploads files. Running those checks against kantease_db would corrupt a
 * real canteen's menu and leave uploaded images behind.
 *
 * So this suite does what tests/verify-phase3.php does: it builds a temporary
 * copy of the application, points a config.local.php inside THAT COPY at a
 * database named kantease_verify_phase4b, serves the copy with PHP's built-in
 * server, and drops the database on the way out. The working tree, your
 * configuration, your products and your uploads folder are never touched.
 *
 * The upload directory is inside the temporary copy too, so even a test that
 * deliberately tries to upload a PHP file leaves that file in a directory that
 * is deleted afterwards rather than in your real uploads/products.
 *
 * ===========================================================================
 *  WHAT IS PROVEN
 * ===========================================================================
 *
 *   A. Access control      the two pages are administrator-only, and every
 *                          mutation refuses a request with no CSRF token
 *   B. Categories          create, rename, uniqueness, ordering, activation,
 *                          product counts, deletion refused while in use
 *   C. Food                create, validation, price handling, category
 *                          assignment, activation, archive, restore
 *   D. Upload security     MIME, signature, extension, size, dimensions,
 *                          randomised filename, no execution, clean deletion
 *   E. Rendering           escaping, no invented figures, placeholders
 *   F. Navigation          both pages are now linked, not "Soon"
 *   G. Offline compliance  the new pages fetch nothing from the internet
 */

const PREFIX       = 'kantease_verify_';
const LIVE_DB_NAME = 'kantease_db';
const THROWAWAY_DB = 'kantease_verify_phase4b';

/** A fresh random password per run. Nothing here is a real credential. */
$testPassword = 'Ve' . bin2hex(random_bytes(12)) . '!7a';

$root = dirname(__DIR__);

// ---------------------------------------------------------------------------
// Isolation guards
// ---------------------------------------------------------------------------

function isDisposable(string $name): bool
{
    return preg_match('/^' . PREFIX . '[a-z0-9_]+$/', $name) === 1;
}

function refuseUnlessDisposable(string $name): void
{
    if (! isDisposable($name)) {
        throw new RuntimeException(sprintf(
            'REFUSED to act on "%s". tests/verify-phase4b.php may only use %s*.',
            $name,
            PREFIX
        ));
    }
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

    /**
     * Record a result that is the truth of a condition.
     *
     * Two details, so a failure says what actually happened.
     */
    public static function assert(
        bool $ok,
        string $label,
        string $detailWhenOk = '',
        string $detailWhenNot = ''
    ): void {
        $ok ? self::pass($label, $detailWhenOk) : self::fail($label, $detailWhenNot);
    }

    public static function fail(string $label, string $detail = ''): void
    {
        self::$failed++;
        self::$failures[] = self::$section . ' :: ' . $label . ($detail === '' ? '' : ' — ' . $detail);
        printf("  \033[31mFAIL\033[0m  %s%s\n", $label, $detail === '' ? '' : "  ({$detail})");
    }

    public static function blocked(string $label, string $reason): void
    {
        self::$blocked++;
        printf("  \033[33mBLOCK\033[0m  %s  (%s)\n", $label, $reason);
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

        if (self::$failed > 0 || self::$blocked > 0) {
            echo "  RESULT: FAIL — Phase 4B is NOT verified.\n\n";
            return 1;
        }

        echo "  RESULT: PASS — Phase 4B is VERIFIED.\n\n";
        return 0;
    }
}

// ---------------------------------------------------------------------------
// A. Environment
// ---------------------------------------------------------------------------

Suite::section('A. Environment');

require_once __DIR__ . '/HttpClient.php';

$configFile = $root . '/includes/config.local.php';

if (! is_file($configFile)) {
    Suite::fail('A1 includes/config.local.php exists', 'cannot continue without it');
    exit(Suite::verdict());
}

Suite::pass('A1 configuration found');

$credentials = require $configFile;

if (! is_array($credentials) || ! isset($credentials['db'])) {
    Suite::fail('A2 the configuration has a db section');
    exit(Suite::verdict());
}

Suite::pass('A2 db settings read');

$dbHost   = (string) $credentials['db']['host'];
$dbPort   = (int) $credentials['db']['port'];
$dbUser   = (string) $credentials['db']['user'];
$dbPass   = (string) $credentials['db']['password'];
$liveName = (string) $credentials['db']['name'];

refuseUnlessDisposable(THROWAWAY_DB);

if ($liveName === THROWAWAY_DB) {
    Suite::fail('A3 the live database is not the throwaway', 'refusing to run');
    exit(Suite::verdict());
}

Suite::pass('A3 the throwaway database name is distinct from the live one', THROWAWAY_DB);

// ---------------------------------------------------------------------------
// Temporary application copy
// ---------------------------------------------------------------------------

$sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kantease_verify_4b_' . getmypid();

function buildSandbox(string $root, string $sandbox): void
{
    $skip = ['node_modules', '.git', 'tests', 'tools', 'docs'];

    if (! is_dir($sandbox) && ! @mkdir($sandbox, 0700, true) && ! is_dir($sandbox)) {
        throw new RuntimeException('Could not create the sandbox directory: ' . $sandbox);
    }

    $rootReal = realpath($root);

    if ($rootReal === false) {
        throw new RuntimeException('The project root could not be resolved: ' . $root);
    }

    $rootPrefix = $rootReal . DIRECTORY_SEPARATOR;

    /** @var list<array{0: string, 1: string}> $stack */
    $stack = [[$rootReal, '']];

    while ($stack !== []) {
        [$directory, $relative] = array_pop($stack);

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $source = $directory . DIRECTORY_SEPARATOR . $entry;

            if (str_starts_with($source, $sandbox) || ! str_starts_with($source, $rootPrefix)) {
                continue;
            }

            $childRelative = $relative === '' ? $entry : $relative . DIRECTORY_SEPARATOR . $entry;
            $target        = $sandbox . DIRECTORY_SEPARATOR . $childRelative;

            if (is_dir($source)) {
                if (in_array($entry, $skip, true)) {
                    continue;
                }

                if (! is_dir($target) && ! @mkdir($target, 0700, true) && ! is_dir($target)) {
                    throw new RuntimeException('Could not create ' . $target);
                }

                $stack[] = [$source, $childRelative];

                continue;
            }

            if (! @copy($source, $target)) {
                throw new RuntimeException('Could not copy ' . $source);
            }
        }
    }
}

function destroySandbox(string $sandbox): void
{
    if (! is_dir($sandbox)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($sandbox);
}

$serverProcess = null;
$serverPipes   = [];

register_shutdown_function(static function () use (&$serverProcess, $sandbox, $dbHost, $dbPort, $dbUser, $dbPass): void {
    echo "\n  Cleaning up...\n";

    if (is_resource($serverProcess)) {
        $pid = proc_get_status($serverProcess)['pid'] ?? 0;

        if ($pid > 0 && strtoupper(PHP_OS) === 'WINNT') {
            @exec('taskkill /T /F /PID ' . $pid . ' 2>&1', $ignored, $status);
        }

        proc_terminate($serverProcess, 9);
        proc_close($serverProcess);
        echo "    stopped the test web server\n";
    }

    destroySandbox($sandbox);
    echo "    removed the temporary application copy (including any test uploads)\n";

    try {
        $cleanup = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $dbHost, $dbPort),
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $cleanup->exec(sprintf('DROP DATABASE IF EXISTS `%s`', THROWAWAY_DB));
        echo '    dropped ' . THROWAWAY_DB . "\n";
    } catch (Throwable $error) {
        echo '    could not drop ' . THROWAWAY_DB . ': ' . $error->getMessage() . "\n";
    }
});

try {
    $adminPdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $dbHost, $dbPort),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $adminPdo->exec(sprintf('DROP DATABASE IF EXISTS `%s`', THROWAWAY_DB));
    $adminPdo->exec(sprintf(
        'CREATE DATABASE `%s` DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_ci',
        THROWAWAY_DB
    ));

    Suite::pass('A4 the throwaway database was created', THROWAWAY_DB);
} catch (Throwable $exception) {
    Suite::fail('A4 could not create the throwaway database', $exception->getMessage());
    Suite::blocked('everything else', 'no throwaway database');
    exit(Suite::verdict());
}

buildSandbox($root, $sandbox);

$localConfig = sprintf(
    "<?php\n\n"
    . "// Written by tests/verify-phase4b.php. Points at a throwaway database.\n"
    . "// The real includes/config.local.php was NOT modified.\n\n"
    . "return [\n"
    . "    'db' => [\n"
    . "        'host'     => %s,\n"
    . "        'port'     => %d,\n"
    . "        'name'     => %s,\n"
    . "        'user'     => %s,\n"
    . "        'password' => %s,\n"
    . "        'charset'  => 'utf8mb4',\n"
    . "    ],\n"
    . "    'app' => [\n"
    . "        'base_path' => '',\n"
    . "        'dev_mode'  => false,\n"
    . "    ],\n"
    . "];\n",
    var_export($dbHost, true),
    $dbPort,
    var_export(THROWAWAY_DB, true),
    var_export($dbUser, true),
    var_export($dbPass, true)
);

file_put_contents($sandbox . '/includes/config.local.php', $localConfig);

@unlink($sandbox . '/database/.installed');

Suite::pass('A5 a temporary application copy was built', 'config.local.php rewritten there only');

// ---------------------------------------------------------------------------
// Start the test server on a port this run owns
// ---------------------------------------------------------------------------

$markerName = '.phase4b-marker-' . getmypid();
$markerText = 'sandbox ' . $sandbox;

file_put_contents($sandbox . '/' . $markerName, $markerText);

$phpBinary = PHP_BINARY;
$logFile   = sys_get_temp_dir() . '/kantease-verify-4b-server.log';
$baseUrl   = '';
$lastFailure = '';

foreach (range(8780, 8830) as $candidate) {
    $command = sprintf(
        '%s -d display_errors=0 -d error_reporting=0 -d allow_url_fopen=0'
            . ' -d allow_url_include=0 -S 127.0.0.1:%d -t %s',
        escapeshellarg($phpBinary),
        $candidate,
        escapeshellarg($sandbox)
    );

    $descriptors = [0 => ['file', 'NUL', 'r'], 1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'a']];
    $pipes      = [];
    $process    = proc_open($command, $descriptors, $pipes, $sandbox);

    if (! is_resource($process)) {
        $lastFailure = 'proc_open() refused the command';
        continue;
    }

    $url = "http://127.0.0.1:{$candidate}";
    $up  = false;

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $candidate, $errno, $errstr, 0.3);

        if ($socket !== false) {
            fclose($socket);
            $up = true;
            break;
        }

        usleep(100_000);
    }

    if (! $up) {
        proc_terminate($process, 9);
        proc_close($process);
        $lastFailure = "nothing listened on port {$candidate}";
        continue;
    }

    // Identity check. A bare 200 would be given by any server; only this run's
    // own sandbox can produce this marker.
    if (@file_get_contents($url . '/' . $markerName) !== $markerText) {
        $lastFailure = "port {$candidate} is owned by another process";
        proc_terminate($process, 9);
        proc_close($process);
        continue;
    }

    $baseUrl       = $url;
    $serverProcess = $process;

    break;
}

@unlink($sandbox . '/' . $markerName);

if ($baseUrl === '') {
    Suite::fail(
        'A6 a test web server could be started',
        $lastFailure . '. Close any leftover verification server (php -S 127.0.0.1:87xx) and run again.'
    );
    Suite::blocked('everything else', 'no usable server');
    exit(Suite::verdict());
}

Suite::pass('A6 a test web server is running on a port this run owns', $baseUrl);
Suite::pass('A7 the server proved its identity by serving this run\'s own copy');

// ---------------------------------------------------------------------------
// Install the schema and create the two accounts the checks need
// ---------------------------------------------------------------------------

$appPdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, THROWAWAY_DB),
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

putenv('KANTEASE_VERIFY=1');
require_once $root . '/includes/bootstrap.php';

try {
    $schema = (string) file_get_contents($sandbox . '/database/database.sql');
    $schema = str_replace('`' . LIVE_DB_NAME . '`', '`' . THROWAWAY_DB . '`', $schema);

    foreach (KantEase\SchemaInstaller::splitStatements($schema) as $statement) {
        if (preg_match('/^\s*CREATE\s+DATABASE\b/i', $statement) === 1) {
            continue;
        }

        $handle = $appPdo->prepare($statement);
        $handle->execute();

        if ($handle->columnCount() > 0) {
            $handle->fetchAll(PDO::FETCH_ASSOC);
        }

        $handle->closeCursor();
    }

    KantEase\Config::override('db.name', THROWAWAY_DB);
    KantEase\Installer::useLockPath($sandbox . '/database/.installed');

    KantEase\Installer::createFirstAdministrator(
        'Phase Four B Administrator',
        'admin@phase4b.invalid',
        $testPassword,
        $testPassword
    );

    // A student, so the access-control checks have someone to refuse.
    $appPdo->prepare(
        'INSERT INTO users (user_code, full_name, email, password, role, is_active)
              VALUES (?, ?, ?, ?, ?, 1)'
    )->execute([
        'STU-9002',
        'Phase Four B Student',
        'student@phase4b.invalid',
        password_hash($testPassword, PASSWORD_DEFAULT),
        'student',
    ]);

    Suite::pass('A8 the schema was installed and one administrator and one student created');
} catch (Throwable $exception) {
    Suite::fail('A8 install and seed', $exception->getMessage());
    Suite::blocked('everything else', 'no schema');
    exit(Suite::verdict());
}

@unlink($sandbox . '/database/.installed');

/**
 * Pull the CSRF token out of a rendered page.
 */
function csrf(string $html): string
{
    return preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

function client(): HttpClient
{
    global $baseUrl;

    return new HttpClient($baseUrl);
}

/**
 * Sign in and return a client holding that session.
 */
function signIn(string $identifier): HttpClient
{
    global $testPassword;

    $http = client();
    $page = $http->get('/login.php');
    $token = csrf($page['body']);

    $http->post('/login.php', [
        '_csrf'     => $token,
        'identifier' => $identifier,
        'password'  => $testPassword,
    ]);

    return $http;
}

// ---------------------------------------------------------------------------
// B. Access control and CSRF
// ---------------------------------------------------------------------------

Suite::section('B. Access control and CSRF');

$anonymous = client();

foreach (['/admin/categories.php', '/admin/food.php'] as $path) {
    $response = $anonymous->get($path);

    Suite::assert(
        $response['status'] === 302 && str_contains($response['location'], 'login.php'),
        "B1 {$path} refuses an anonymous visitor",
        'HTTP ' . $response['status'] . ' -> ' . $response['location'],
        'HTTP ' . $response['status'] . ' -> ' . $response['location']
    );
}

$student = signIn('STU-9002');

foreach (['/admin/categories.php', '/admin/food.php'] as $path) {
    $response = $student->get($path);

    Suite::assert(
        $response['status'] === 302 && str_contains($response['location'], 'student/dashboard.php'),
        "B2 {$path} refuses a signed-in student",
        'redirected to their own dashboard',
        'HTTP ' . $response['status'] . ' -> ' . $response['location']
    );
}

$admin = signIn('ADM-0001');

$dashboard = $admin->get('/admin/dashboard.php');
Suite::assert(
    $dashboard['status'] === 200,
    'B3 the administrator session works',
    'HTTP 200',
    'HTTP ' . $dashboard['status']
);

// Every mutating action, without a token. Each must change nothing.
$categoriesPage = $admin->get('/admin/categories.php');
$adminCsrf      = csrf($categoriesPage['body']);

$before = (int) $appPdo->query('SELECT COUNT(*) FROM food_categories')->fetchColumn();

$noToken = $admin->post('/admin/categories.php', [
    'action' => 'create',
    'name'   => 'No Token Category',
]);

$after = (int) $appPdo->query('SELECT COUNT(*) FROM food_categories')->fetchColumn();

Suite::assert(
    $after === $before,
    'B4 creating a category without a CSRF token changes nothing',
    "still {$before} categories",
    "count went from {$before} to {$after}"
);

$wrongToken = $admin->post('/admin/categories.php', [
    '_csrf' => str_repeat('0', 64),
    'action' => 'create',
    'name'   => 'Wrong Token Category',
]);

$afterWrong = (int) $appPdo->query('SELECT COUNT(*) FROM food_categories')->fetchColumn();

Suite::assert(
    $afterWrong === $before,
    'B5 a wrong CSRF token changes nothing',
    "still {$before} categories",
    "count went from {$before} to {$afterWrong}"
);

Suite::assert(
    $wrongToken['status'] === 419 || $wrongToken['status'] === 302,
    'B6 a wrong CSRF token is refused with a recognisable status',
    'HTTP ' . $wrongToken['status'],
    'HTTP ' . $wrongToken['status']
);

// An unrecognised action must be refused rather than silently ignored.
$bogus = $admin->post('/admin/categories.php', [
    '_csrf'  => $adminCsrf,
    'action' => 'drop_everything',
    'name'   => 'Sneaky',
]);

Suite::assert(
    (int) $appPdo->query('SELECT COUNT(*) FROM food_categories')->fetchColumn() === $before,
    'B7 an unrecognised action is refused',
    'nothing was created or deleted',
    'the category count changed'
);

// ---------------------------------------------------------------------------
// C. Categories
// ---------------------------------------------------------------------------

Suite::section('C. Category management');

$categoriesPage = $admin->get('/admin/categories.php');
$token = csrf($categoriesPage['body']);

// -- Create ------------------------------------------------------------------

// is_active is sent because that is what the rendered form does: a checkbox
// that is ticked submits its value, and this form renders it ticked by default.
// Omitting it here would be testing a POST the interface never makes.
$created = $admin->post('/admin/categories.php', [
    '_csrf'     => $token,
    'action'    => 'create',
    'name'      => 'Rice Bowls',
    'is_active' => '1',
]);

$row = $appPdo->query("SELECT * FROM food_categories WHERE name = 'Rice Bowls'")->fetch();

Suite::assert(
    $row !== false,
    'C1 a category can be created',
    'HTTP ' . $created['status'],
    'no row found after the POST'
);

$riceBowlsId = $row === false ? 0 : (int) $row['id'];

Suite::assert(
    $row !== false && (int) $row['is_active'] === 1,
    'C2 a new category is active by default',
    'is_active = 1',
    'is_active = ' . ($row === false ? 'n/a' : (string) $row['is_active'])
);

// New categories must not jump to the top and reorder the menu.
$sortOrder = $row === false ? 0 : (int) $row['sort_order'];
$highest    = (int) $appPdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM food_categories')->fetchColumn();

Suite::assert(
    $sortOrder === $highest,
    'C3 a new category is appended rather than pushed to the top',
    "sort_order = {$sortOrder}, which is the highest",
    "sort_order = {$sortOrder}, highest is {$highest}"
);

Suite::assert(
    $row !== false
        && (int) $appPdo->query(
            "SELECT COUNT(*) FROM activity_logs WHERE action = 'category.create' AND entity_id = " . $riceBowlsId
        )->fetchColumn() === 1,
    'C4 creating a category writes an audit row',
    'category.create logged',
    'no category.create row in activity_logs'
);

// -- Uniqueness --------------------------------------------------------------

$duplicate = $admin->post('/admin/categories.php', [
    '_csrf'  => $token,
    'action' => 'create',
    'name'   => 'Rice Bowls',
]);

$count = (int) $appPdo->query(
    "SELECT COUNT(*) FROM food_categories WHERE name = 'Rice Bowls'"
)->fetchColumn();

Suite::assert(
    $count === 1,
    'C5 a duplicate category name is refused',
    'still exactly one row',
    "there are now {$count} rows named Rice Bowls"
);

$page = $admin->get('/admin/categories.php');

Suite::assert(
    str_contains($page['body'], 'already taken') || str_contains($page['body'], 'already a category'),
    'C6 the duplicate is explained to the administrator',
    'a readable message is shown',
    'no message about the duplicate name'
);

// -- Validation --------------------------------------------------------------

$blank = $admin->post('/admin/categories.php', [
    '_csrf'  => $token,
    'action' => 'create',
    'name'   => '   ',
]);

Suite::assert(
    (int) $appPdo->query("SELECT COUNT(*) FROM food_categories WHERE name = ''")->fetchColumn() === 0,
    'C7 a blank category name is refused',
    'nothing created',
    'an empty-name row was created'
);

// The message is the validator's own: "Category name is required." Asserting the
// exact wording keeps the check honest — a generic "something went wrong" banner
// would otherwise satisfy it.
$page = $admin->get('/admin/categories.php');

Suite::assert(
    str_contains($page['body'], 'Category name is required')
        && str_contains($page['body'], 'field__error'),
    'C8 the blank name is explained beside the field',
    'a per-field message is shown',
    'no per-field message about the required name'
);

// -- Rename ------------------------------------------------------------------

$admin->post('/admin/categories.php', [
    '_csrf'      => $token,
    'action'     => 'update',
    'id'         => $riceBowlsId,
    'name'       => 'Rice Bowls & Plates',
    'is_active'  => '1',
    'sort_order' => '3',
]);

$renamed = $appPdo->query('SELECT * FROM food_categories WHERE id = ' . $riceBowlsId)->fetch();

Suite::assert(
    $renamed !== false && (string) $renamed['name'] === 'Rice Bowls & Plates'
        && (int) $renamed['sort_order'] === 3,
    'C9 a category can be renamed and reordered',
    'name and sort_order both updated',
    'name = ' . ($renamed === false ? 'n/a' : (string) $renamed['name'])
        . ', sort_order = ' . ($renamed === false ? 'n/a' : (string) $renamed['sort_order'])
);

// A rename must not collide with another category.
$otherRow = $appPdo->query("SELECT id FROM food_categories WHERE name = 'Meals' LIMIT 1")->fetch();
$mealsId  = $otherRow === false ? 0 : (int) $otherRow['id'];

if ($mealsId > 0 && $mealsId !== $riceBowlsId) {
    $admin->post('/admin/categories.php', [
        '_csrf'      => $token,
        'action'     => 'update',
        'id'         => $riceBowlsId,
        'name'       => 'Meals',
        'is_active'  => '1',
        'sort_order' => '3',
    ]);

    $stillRice = $appPdo->query('SELECT name FROM food_categories WHERE id = ' . $riceBowlsId)->fetch();

    Suite::assert(
        $stillRice !== false && (string) $stillRice['name'] !== 'Meals',
        'C10 renaming onto another category\'s name is refused',
        'the original name was kept',
        'the rename went through'
    );
}

// -- Activation --------------------------------------------------------------

$admin->post('/admin/categories.php', [
    '_csrf'  => $token,
    'action' => 'deactivate',
    'id'     => $riceBowlsId,
]);

$hidden = $appPdo->query('SELECT is_active FROM food_categories WHERE id = ' . $riceBowlsId)->fetch();

Suite::assert(
    $hidden !== false && (int) $hidden['is_active'] === 0,
    'C11 a category can be switched off',
    'is_active = 0',
    'is_active = ' . ($hidden === false ? 'n/a' : (string) $hidden['is_active'])
);

$admin->post('/admin/categories.php', [
    '_csrf'  => $token,
    'action' => 'activate',
    'id'     => $riceBowlsId,
]);

$shown = $appPdo->query('SELECT is_active FROM food_categories WHERE id = ' . $riceBowlsId)->fetch();

Suite::assert(
    $shown !== false && (int) $shown['is_active'] === 1,
    'C12 a category can be switched back on',
    'is_active = 1',
    'is_active = ' . ($shown === false ? 'n/a' : (string) $shown['is_active'])
);

// -- Deletion refused while products exist -----------------------------------

$admin->post('/admin/categories.php', [
    '_csrf'      => $token,
    'action'     => 'create',
    'name'       => 'Temporary',
]);

$tempRow  = $appPdo->query("SELECT id FROM food_categories WHERE name = 'Temporary' LIMIT 1")->fetch();
$tempId   = $tempRow === false ? 0 : (int) $tempRow['id'];
$mealsRow = $appPdo->query("SELECT id FROM food_categories WHERE name = 'Meals' LIMIT 1")->fetch();
$mealsId  = $mealsRow === false ? 0 : (int) $mealsRow['id'];

// An empty category deletes.
if ($tempId > 0) {
    $admin->post('/admin/categories.php', [
        '_csrf'  => $token,
        'action' => 'delete',
        'id'     => $tempId,
    ]);

    Suite::assert(
        (int) $appPdo->query('SELECT COUNT(*) FROM food_categories WHERE id = ' . $tempId)->fetchColumn() === 0,
        'C13 an empty category can be deleted',
        'the row is gone',
        'the row is still there'
    );
}

// A category holding products cannot be.
$mealsProducts = (int) $appPdo->query(
    'SELECT COUNT(*) FROM food_items WHERE category_id = ' . $mealsId
)->fetchColumn();

if ($mealsId > 0 && $mealsProducts > 0) {
    $admin->post('/admin/categories.php', [
        '_csrf'  => $token,
        'action' => 'delete',
        'id'     => $mealsId,
    ]);

    Suite::assert(
        (int) $appPdo->query('SELECT COUNT(*) FROM food_categories WHERE id = ' . $mealsId)->fetchColumn() === 1,
        'C14 a category holding products cannot be deleted',
        "refused; it still has {$mealsProducts} products",
        'the category was deleted while products still referenced it'
    );

    $page = $admin->get('/admin/categories.php');
    Suite::assert(
        str_contains($page['body'], 'still has products'),
        'C15 the refusal is explained',
        'a readable message is shown',
        'no explanation was given'
    );
}

// -- Search and filtering ----------------------------------------------------

$searchPage = $admin->get('/admin/categories.php?q=' . rawurlencode('Rice'));

Suite::assert(
    str_contains($searchPage['body'], 'Rice Bowls &amp; Plates') || str_contains($searchPage['body'], 'Rice Bowls'),
    'C16 a search finds the category',
    'the category appears in the results',
    'the category was not found'
);

$searchPage = $admin->get('/admin/categories.php?q=zzzznothing');

Suite::assert(
    str_contains($searchPage['body'], 'No categories match this filter'),
    'C17 a search with no results shows an empty state, not a blank page',
    'an empty state is shown',
    'no empty state found'
);

// LIKE wildcards must be neutralised, or "%" would return every category.
$wildcard = $admin->get('/admin/categories.php?q=' . rawurlencode('%'));

$shownNames = substr_count($wildcard['body'], 'nav-item__label');

$allNames = (int) $appPdo->query('SELECT COUNT(*) FROM food_categories')->fetchColumn();

Suite::assert(
    ! str_contains($wildcard['body'], 'Meals</span>'),
    'C18 a % in the search box is treated as text, not a wildcard',
    'it matched nothing rather than everything',
    'it returned every category'
);

$statusPage = $admin->get('/admin/categories.php?status=inactive');

Suite::assert(
    $statusPage['status'] === 200,
    'C19 the availability filter is accepted',
    'HTTP 200',
    'HTTP ' . $statusPage['status']
);

// ---------------------------------------------------------------------------
// D. Food management
// ---------------------------------------------------------------------------

Suite::section('D. Food management');

$foodPage = $admin->get('/admin/food.php');
$token    = csrf($foodPage['body']);

Suite::assert(
    $foodPage['status'] === 200,
    'D1 the food management page loads for an administrator',
    'HTTP 200',
    'HTTP ' . $foodPage['status']
);

// -- Create ------------------------------------------------------------------

$admin->post('/admin/food.php', [
    '_csrf'           => $token,
    'action'          => 'create',
    'name'            => 'Beef Tapa Rice',
    'description'     => 'Served with fried egg.',
    'price'           => '89.50',
    'category_id'     => (string) $riceBowlsId,
    'is_available'    => '1',
    'low_stock_level' => '4',
    'stock'           => '12',
]);

$tapa = $appPdo->query("SELECT * FROM food_items WHERE name = 'Beef Tapa Rice'")->fetch();

Suite::assert(
    $tapa !== false,
    'D2 a product can be created',
    'HTTP ' . $created['status'],
    'no row found after the POST'
);

$tapaId = $tapa === false ? 0 : (int) $tapa['id'];

Suite::assert(
    $tapa !== false && (string) $tapa['price'] === '89.50',
    'D3 the price is stored exactly as entered',
    '89.50',
    'stored as ' . ($tapa === false ? 'n/a' : (string) $tapa['price'])
);

Suite::assert(
    $tapa !== false && (int) $tapa['stock'] === 12 && (int) $tapa['low_stock_level'] === 4,
    'D4 the opening stock and alert level are stored',
    'stock 12, alert at 4',
    'stock = ' . ($tapa === false ? 'n/a' : (string) $tapa['stock'])
);

Suite::assert(
    $tapa !== false && (int) $tapa['category_id'] === $riceBowlsId,
    'D5 the product is filed under the chosen category',
    'category_id = ' . $riceBowlsId,
    'category_id = ' . ($tapa === false ? 'n/a' : (string) $tapa['category_id'])
);

// The opening stock must appear in the ledger, or the count has no origin.
$movement = $appPdo->query(
    "SELECT * FROM inventory_movements WHERE food_id = " . $tapaId . " AND movement_type = 'create'"
)->fetch();

Suite::assert(
    $movement !== false
        && (int) $movement['quantity_change'] === 12
        && (int) $movement['stock_before'] === 0
        && (int) $movement['stock_after'] === 12,
    'D6 the opening stock is written to the inventory ledger',
    'create movement 0 -> 12',
    'no matching ledger row'
);

Suite::assert(
    $movement !== false && $movement['user_id'] !== null,
    'D7 the ledger records who added it',
    'user_id = ' . (string) $movement['user_id'],
    'user_id is null'
);

// -- Validation --------------------------------------------------------------

// Captured AFTER the valid creation above, so the expected value is unchanged:
// the invalid submissions that follow must add nothing at all.
$beforeFood = (int) $appPdo->query('SELECT COUNT(*) FROM food_items')->fetchColumn();

$admin->post('/admin/food.php', [
    '_csrf'       => $token,
    'action'      => 'create',
    'name'        => 'No Price Item',
    'price'       => '',
    'category_id' => (string) $riceBowlsId,
]);

Suite::assert(
    (int) $appPdo->query("SELECT COUNT(*) FROM food_items WHERE name = 'No Price Item'")->fetchColumn() === 0,
    'D8 a product with no price is refused',
    'nothing created',
    'a product with no price was created'
);

$admin->post('/admin/food.php', [
    '_csrf'       => $token,
    'action'      => 'create',
    'name'        => 'Negative Price',
    'price'       => '-5.00',
    'category_id' => (string) $riceBowlsId,
]);

Suite::assert(
    (int) $appPdo->query("SELECT COUNT(*) FROM food_items WHERE name = 'Negative Price'")->fetchColumn() === 0,
    'D9 a negative price is refused',
    'nothing created',
    'a product with a negative price was created'
);

$admin->post('/admin/food.php', [
    '_csrf'       => $token,
    'action'      => 'create',
    'name'        => 'Beef Tapa Rice',
    'price'       => '10.00',
    'category_id' => (string) $riceBowlsId,
]);

Suite::assert(
    (int) $appPdo->query("SELECT COUNT(*) FROM food_items WHERE name = 'Beef Tapa Rice'")->fetchColumn() === 1,
    'D10 a duplicate product name is refused',
    'still exactly one row',
    'a duplicate product was created'
);

$admin->post('/admin/food.php', [
    '_csrf'       => $token,
    'action'      => 'create',
    'name'        => 'Ghost Category Item',
    'price'       => '10.00',
    'category_id' => '999999',
]);

Suite::assert(
    (int) $appPdo->query("SELECT COUNT(*) FROM food_items WHERE name = 'Ghost Category Item'")->fetchColumn() === 0,
    'D11 a product in a non-existent category is refused',
    'nothing created',
    'a product was created against a category that does not exist'
);

Suite::assert(
    (int) $appPdo->query('SELECT COUNT(*) FROM food_items')->fetchColumn() === $beforeFood,
    'D12 none of the invalid submissions created anything',
    "still {$beforeFood} products",
    'the product count changed after invalid submissions'
);

$page = $admin->get('/admin/food.php');

Suite::assert(
    str_contains($page['body'], 'field__error') || str_contains($page['body'], 'alert--error'),
    'D13 validation problems are shown on the page',
    'a message is rendered',
    'no validation message found'
);

// -- Price handling ----------------------------------------------------------

// A price of 19.9 must become 19.90, not 19.9 read as a float and re-rounded.
$admin->post('/admin/food.php', [
    '_csrf'           => $token,
    'action'          => 'update',
    'id'              => $tapaId,
    'name'            => 'Beef Tapa Rice',
    'description'     => 'Served with fried egg.',
    'price'           => '19.9',
    'category_id'     => (string) $riceBowlsId,
    'is_available'    => '1',
    'low_stock_level' => '4',
]);

$repriced = $appPdo->query('SELECT price FROM food_items WHERE id = ' . $tapaId)->fetch();

Suite::assert(
    $repriced !== false && (string) $repriced['price'] === '19.90',
    'D14 a one-decimal price is stored with two',
    '19.90',
    'stored as ' . ($repriced === false ? 'n/a' : (string) $repriced['price'])
);

// -- Stock must not be editable from this screen -----------------------------

$stockBefore = (int) $appPdo->query('SELECT stock FROM food_items WHERE id = ' . $tapaId)->fetchColumn();

// A stock field posted to the update action must be ignored: stock moves only
// through Inventory, which writes the ledger.
$admin->post('/admin/food.php', [
    '_csrf'           => $token,
    'action'          => 'update',
    'id'              => $tapaId,
    'name'            => 'Beef Tapa Rice',
    'description'     => 'Served with fried egg.',
    'price'           => '89.50',
    'category_id'     => (string) $riceBowlsId,
    'is_available'    => '1',
    'low_stock_level' => '4',
    'stock'           => '999',
]);

$stockAfter = (int) $appPdo->query('SELECT stock FROM food_items WHERE id = ' . $tapaId)->fetchColumn();

Suite::assert(
    $stockAfter === $stockBefore,
    'D15 posting a stock value from the food form does not change stock',
    "stock is still {$stockBefore}",
    "stock went from {$stockBefore} to {$stockAfter}"
);

$editPage = $admin->get('/admin/food.php?edit=' . $tapaId);

Suite::assert(
    str_contains($editPage['body'], 'Adjust stock in Inventory'),
    'D16 the edit form says where stock is changed instead',
    'the explanation is shown',
    'no explanation found'
);

// -- Availability ------------------------------------------------------------

$admin->post('/admin/food.php', [
    '_csrf'  => $token,
    'action' => 'deactivate',
    'id'     => $tapaId,
]);

$off = $appPdo->query('SELECT is_available FROM food_items WHERE id = ' . $tapaId)->fetch();

Suite::assert(
    $off !== false && (int) $off['is_available'] === 0,
    'D17 a product can be marked out of stock for today',
    'is_available = 0',
    'is_available = ' . ($off === false ? 'n/a' : (string) $off['is_available'])
);

$admin->post('/admin/food.php', [
    '_csrf'  => $token,
    'action' => 'activate',
    'id'     => $tapaId,
]);

$on = $appPdo->query('SELECT is_available FROM food_items WHERE id = ' . $tapaId)->fetch();

Suite::assert(
    $on !== false && (int) $on['is_available'] === 1,
    'D18 a product can be put back on the menu',
    'is_available = 1',
    'is_available = ' . ($on === false ? 'n/a' : (string) $on['is_available'])
);

// Switching on a product with no stock must be refused, or a student gets a Buy
// button on something the canteen does not have.
//
// The product is switched OFF first, so the assertion is about the attempted
// activation rather than about a flag that was already set from an earlier
// check. Without that, a refusal would be invisible: is_available would read 1
// either way and the test would pass whether or not the guard exists.
$appPdo->exec('UPDATE food_items SET stock = 0, is_available = 0 WHERE id = ' . $tapaId);

$admin->post('/admin/food.php', [
    '_csrf'  => $token,
    'action' => 'activate',
    'id'     => $tapaId,
]);

$stillOff = $appPdo->query('SELECT is_available FROM food_items WHERE id = ' . $tapaId)->fetch();

Suite::assert(
    $stillOff !== false && (int) $stillOff['is_available'] === 0,
    'D19 a product with no stock cannot be put on the menu',
    'the server refused and left it off',
    'it was switched on despite having no stock'
);

$page = $admin->get('/admin/food.php')['body'];

Suite::assert(
    str_contains($page, 'no stock left') || str_contains($page, 'Restock it'),
    'D19b the refusal is explained to the administrator',
    'a readable message is shown',
    'no explanation was given'
);

$appPdo->exec('UPDATE food_items SET stock = 12 WHERE id = ' . $tapaId);

// -- Archive and restore -----------------------------------------------------

$admin->post('/admin/food.php', [
    '_csrf'  => $token,
    'action' => 'archive',
    'id'     => $tapaId,
]);

$archived = $appPdo->query('SELECT is_archived, is_available FROM food_items WHERE id = ' . $tapaId)->fetch();

Suite::assert(
    $archived !== false && (int) $archived['is_archived'] === 1 && (int) $archived['is_available'] === 0,
    'D20 archiving takes the product off the menu',
    'is_archived = 1, is_available = 0',
    'is_archived = ' . ($archived === false ? 'n/a' : (string) $archived['is_archived'])
);

// The list body only. The confirmation flash for the archive action names the
// product ("\"Beef Tapa Rice\" was removed from the menu."), so asserting on the
// whole page would match the message rather than the table row and pass even if
// the product were still listed.
$listBody = strip_tags(
    substr(
        (string) strstr($admin->get('/admin/food.php')['body'], '<table'),
        0,
        strpos((string) strstr($admin->get('/admin/food.php')['body'], '<table'), '</table>') ?: null
    ) ?: ''
);

Suite::assert(
    ! str_contains($listBody, 'Beef Tapa Rice'),
    'D21 an archived product is absent from the default list',
    'the table does not list it',
    'the table still lists it'
);

// ...and it is there when archived products are asked for.
$withArchived = $admin->get('/admin/food.php?archived=1');

Suite::assert(
    str_contains(strip_tags($withArchived['body']), 'Beef Tapa Rice'),
    'D21b the "show archived" filter brings it back',
    'it appears in the list',
    'it did not appear'
);

$admin->post('/admin/food.php', [
    '_csrf'  => $token,
    'action' => 'restore',
    'id'     => $tapaId,
]);

$restored = $appPdo->query('SELECT is_archived FROM food_items WHERE id = ' . $tapaId)->fetch();

Suite::assert(
    $restored !== false && (int) $restored['is_archived'] === 0,
    'D22 a product can be restored',
    'is_archived = 0',
    'is_archived = ' . ($restored === false ? 'n/a' : (string) $restored['is_archived'])
);

// -- Search and filters ------------------------------------------------------

$found = $admin->get('/admin/food.php?q=' . rawurlencode('Tapa'));

Suite::assert(
    str_contains($found['body'], 'Beef Tapa Rice'),
    'D23 a search finds the product',
    'the product appears in the results',
    'the product was not found'
);

// Search by price, using the price the product actually carries at this point.
// D15 posted 89.50 back as part of its stock test, so the earlier 19.90 is no
// longer the value to look for.
$currentPrice = (string) $appPdo->query('SELECT price FROM food_items WHERE id = ' . $tapaId)->fetchColumn();

$byPrice = $admin->get('/admin/food.php?q=' . rawurlencode($currentPrice));

Suite::assert(
    str_contains($byPrice['body'], 'Beef Tapa Rice'),
    'D24 a search by price works',
    "searching for {$currentPrice} found it",
    'the product was not found by price'
);

$filtered = $admin->get('/admin/food.php?category=' . $riceBowlsId);

Suite::assert(
    str_contains($filtered['body'], 'Beef Tapa Rice')
        && str_contains($filtered['body'], 'Rice Bowls'),
    'D25 the category filter narrows the list',
    'only the chosen category is shown',
    'the filter had no effect'
);

$emptyResult = $admin->get('/admin/food.php?q=zzzznothing');

Suite::assert(
    str_contains($emptyResult['body'], 'No products match this filter'),
    'D26 a search with no results shows an empty state',
    'an empty state is shown',
    'no empty state found'
);

// -- Escaping ----------------------------------------------------------------

$xssName = '<script>alert(1)</script>';
$xssDesc = '"><img src=x onerror=alert(2)>';

$admin->post('/admin/food.php', [
    '_csrf'       => $token,
    'action'      => 'create',
    'name'        => $xssName,
    'description' => $xssDesc,
    'price'       => '5.00',
    'category_id' => (string) $riceBowlsId,
]);

$xssStatement = $appPdo->prepare('SELECT id FROM food_items WHERE name = ? LIMIT 1');
$xssStatement->execute([$xssName]);
$xssRow = $xssStatement->fetch();
$xssId  = $xssRow === false ? 0 : (int) $xssRow['id'];

// The assertions look for the OPENING TAG, not for the payload text.
//
// Escaping turns `"><img src=x onerror=alert(2)>` into
// `&quot;&gt;&lt;img src=x onerror=alert(2)&gt;` — the substring "onerror=alert(2)"
// is still in the document, as harmless text. Asserting on it would fail even
// though the escaping is correct, and worse, it would pass if the whole page
// were never checked at all. What must not appear is a real `<` starting a tag.
$xssPage = $admin->get('/admin/food.php?q=' . rawurlencode('script'));

Suite::assert(
    ! str_contains($xssPage['body'], '<script>alert(1)</script>'),
    'D27 a product name containing markup is escaped, not executed',
    'the tag was rendered as text',
    'the script tag appears unescaped in the HTML'
);

Suite::assert(
    ! str_contains($xssPage['body'], '<img src=x'),
    'D28 a description containing markup is escaped',
    'the opening tag was neutralised',
    'the <img tag appears unescaped in the HTML'
);

Suite::assert(
    str_contains($xssPage['body'], '&lt;img src=x onerror=alert(2)&gt;'),
    'D28b the escaped description is still shown to the administrator',
    'the text is readable, just inert',
    'the escaped form was not found — the description may have been dropped'
);

if ($xssId > 0) {
    $admin->post('/admin/food.php', [
        '_csrf'  => $token,
        'action' => 'archive',
        'id'     => $xssId,
    ]);
}

// ---------------------------------------------------------------------------
// E. Upload security
// ---------------------------------------------------------------------------

Suite::section('E. Product image upload security');

$uploadDir = $sandbox . '/uploads/products';

/**
 * Post the food form with a file attached.
 *
 * CURLFile carries a spoofed filename and a spoofed Content-Type, because that
 * is exactly what an attacker controls and exactly what the server must not
 * trust. The bytes on disk are the only thing that decides acceptance.
 *
 * @param array<string, string> $fields
 */
function postFoodWithFile(HttpClient $http, string $action, array $fields, string $fieldName, string $path, string $sentName, string $type): array
{
    $fields['_csrf']  = $fields['_csrf'] ?? '';
    $fields['action'] = $action;

    foreach ($fields as $key => $value) {
        $fields[$key] = (string) $value;
    }

    $fields[$fieldName] = new CURLFile($path, $type, $sentName);

    return $http->postMultipart('/admin/food.php', $fields);
}

// The smallest structurally valid PNG. Used as the prefix of the oversized
// payload below: real signature, real header, then trailing padding that pushes
// the byte count past the configured cap.
$pngBytes = (string) base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAKklEQVR4nO3BAQ0AAADCoPdPbQ8H'
    . 'FAAAAAAAAAAAAAAAAAAAAAAAAP7b4gVAAAAAElFTkSuQmCC'
);

/**
 * Build a valid greyscale PNG of exactly $size x $size, by hand.
 *
 * GD is not a requirement of KantEase, so the test cannot rely on it. A PNG is
 * easy enough to assemble: signature, IHDR, one zlib-compressed IDAT of
 * filtered scanlines, IEND. 8-bit greyscale keeps the row arithmetic trivial.
 */
function makePng(int $size = 64): string
{
    $path = tempnam(sys_get_temp_dir(), 'ke-png-') . '.png';

    $raw = '';

    for ($y = 0; $y < $size; $y++) {
        $raw .= "\x00";                       // filter type 0: none
        $raw .= str_repeat("\x80", $size);    // mid grey
    }

    $chunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };

    $png = "\x89PNG\r\n\x1A\n"
        . $chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 0, 0, 0, 0))
        . $chunk('IDAT', (string) gzcompress($raw, 9))
        . $chunk('IEND', '');

    file_put_contents($path, $png);

    return $path;
}

/**
 * Build a valid baseline JPEG of $size x $size showing a flat mid-grey.
 *
 * WHY THIS EXISTS INSTEAD OF CALLING GD
 *
 * GD is not installed on this XAMPP build, and it is not a requirement of
 * KantEase — the application never calls it. But "the JPEG upload path is
 * untested because a dev tool was missing" is a weaker answer than making a
 * real JPEG, so this constructs one directly.
 *
 * It is tractable because a FLAT image is the easy case for JPEG:
 *
 *   - Level shifting subtracts 128, so a uniform 128 block is all zeros.
 *   - The DCT of a constant block is a single non-zero DC term, and here that
 *     is zero too, so every coefficient is 0.
 *   - Therefore every 8x8 block encodes as "DC difference = 0, then end of
 *     block" — two Huffman codes and nothing else.
 *
 * Both Huffman tables are declared with exactly one symbol (0x00) of length 1,
 * which the canonical code assignment turns into the single-bit code '0'. So
 * each block is the two bits "00", and the whole scan is 2 bits per block.
 *
 * GD is still preferred when present, because a library-produced file is a
 * stronger test than a hand-built one.
 */
function makeJpeg(int $size = 64): ?string
{
    $size = max(32, $size - ($size % 8));

    if (function_exists('imagecreatetruecolor') && function_exists('imagejpeg')) {
        $image = imagecreatetruecolor($size, $size);

        if ($image !== false) {
            imagefill($image, 0, 0, (int) imagecolorallocate($image, 90, 160, 220));

            $gdPath = tempnam(sys_get_temp_dir(), 'ke-jpg-') . '.jpg';

            imagejpeg($image, $gdPath, 80);
            imagedestroy($image);

            if (is_file($gdPath)) {
                return $gdPath;
            }
        }
    }

    // Quantisation table: all 16s. Irrelevant to the coefficients below, which
    // are all zero, but a table has to exist.
    $quantTable  = str_repeat("\x10", 64);
    $dqt         = "\xFF\xDB" . pack('n', 2 + 1 + 64) . "\x00" . $quantTable;

    // Huffman tables. BITS = [1, 0, 0, ... ] means one code of length 1;
    // HUFFVAL = [0x00] is the only symbol in it. Canonical assignment gives
    // that symbol the code '0'.
    $bits   = array_merge([1], array_fill(0, 15, 0));
    $counts = implode('', array_map('chr', $bits));

    $dcTable = "\xFF\xC4" . pack('n', 2 + 1 + 16 + 1) . "\x00" . $counts . "\x00";
    $acTable = "\xFF\xC4" . pack('n', 2 + 1 + 16 + 1) . "\x10" . $counts . "\x00";

    // SOF0: 8-bit precision, one greyscale component at 1x1 sampling.
    $sof = "\xFF\xC0" . pack('n', 8 + 3 * 1) . "\x08"
        . pack('n', $size) . pack('n', $size)
        . "\x01"          // one component
        . "\x01" . "\x11\x00";

    // SOS: one component, DC table 0 / AC table 0, baseline spectral selection.
    $sos = "\xFF\xDA" . pack('n', 6 + 2 * 1) . "\x01" . "\x01\x00"
        . "\x00" . "\x3F" . "\x00";

    // Entropy-coded data: two bits per block, '0' for the DC difference and '0'
    // for end-of-block, then pad the final byte with 1-bits as the standard
    // requires.
    $blocks = intdiv($size, 8) * intdiv($size, 8);
    $bitsOut = str_repeat('0', $blocks * 2);
    $padded  = str_pad($bitsOut, intdiv(strlen($bitsOut) + 7, 8) * 8, '1');

    $scan = '';

    foreach (str_split($padded, 8) as $byte) {
        $scan .= chr(bindec($byte));
    }

    // Byte stuffing: any 0xFF in the entropy stream must be followed by 0x00.
    $scan = str_replace("\xFF", "\xFF\x00", $scan);

    $app0 = "\xFF\xE0" . pack('n', 2 + 14) . "JFIF\x00\x01\x01\x00"
        . pack('n', 1) . pack('n', 1) . "\x00\x00";

    $file = "\xFF\xD8" . $app0 . $dqt . $dcTable . $acTable . $sof . $sos . $scan . "\xFF\xD9";

    $path = tempnam(sys_get_temp_dir(), 'ke-jpg-') . '.jpg';

    file_put_contents($path, $file);

    // Only return it if something independent agrees it is a JPEG of the right
    // size. If that fails, the caller reports BLOCKED rather than testing
    // against a file that was never valid.
    $info = @getimagesize($path);

    if ($info === false || (int) $info[0] !== $size || (int) $info[1] !== $size) {
        @unlink($path);

        return null;
    }

    return $path;
}

$validPng = makePng(96);
$validJpg = makeJpeg(96);

Suite::pass('E0 a valid test PNG was generated', basename($validPng));

if ($validJpg === null) {
    Suite::blocked(
        'E5 a real JPEG is accepted',
        'the GD extension is not installed, so no valid JPEG can be produced for the test'
    );
} else {
    Suite::info('a valid test JPEG was generated: ' . basename($validJpg));
}

// -- A real image is accepted ------------------------------------------------

$token = csrf($admin->get('/admin/food.php')['body']);

$photoBefore = count(array_filter(scandir($uploadDir) ?: [], static fn (string $f): bool => $f !== '.' && $f !== '..'));

$withPhoto = postFoodWithFile($admin, 'create', [
    '_csrf'           => $token,
    'name'            => 'Pancit Canton',
    'description'     => 'With egg.',
    'price'           => '52.00',
    'category_id'     => (string) $riceBowlsId,
    'is_available'    => '1',
    'low_stock_level' => '3',
    'stock'           => '7',
], 'photo', $validPng, 'menu photo.png', 'image/png');

$photoRow = $appPdo->query("SELECT * FROM food_items WHERE name = 'Pancit Canton'")->fetch();

/**
 * Whatever the application said about the last action, as one line.
 *
 * Used in failure details so a rejected upload reports WHY it was rejected
 * rather than only that it was.
 */
function lastMessage(HttpClient $http, array $response = []): string
{
    $body = $http->get('/admin/food.php')['body'];

    if (preg_match_all('#<div class="alert alert--\w+">.*?<span>(.*?)</span>#s', $body, $matches) > 0) {
        $first = strip_tags((string) $matches[1][0]);

        return trim(preg_replace('/\s+/', ' ', $first) ?: $first);
    }

    $where = $response['location'] ?? '';

    return 'no alert was shown'
        . ($where === '' ? '' : ', redirected to ' . $where)
        . ' | cookies held: ' . implode(',', $http->cookieNames())
        . ' | session: ' . substr((string) $http->sessionCookie(), 0, 12);
}

Suite::assert(
    $photoRow !== false && $photoRow['image_path'] !== null,
    'E1 a real PNG is accepted and stored',
    'image_path = ' . ($photoRow === false ? 'n/a' : (string) $photoRow['image_path']),
    'row=' . ($photoRow === false ? 'absent' : 'image_path null')
        . ', HTTP ' . $withPhoto['status']
        . ', app said: ' . lastMessage($admin, $withPhoto)
);

$storedPath = $photoRow === false ? null : (string) $photoRow['image_path'];

Suite::assert(
    $storedPath !== null
        && preg_match('#^/uploads/products/[a-f0-9]{32}\.png$#', $storedPath) === 1,
    'E2 the stored filename is randomised, not the submitted one',
    '32 hex characters plus .png',
    'stored as ' . ($storedPath ?? 'n/a')
);

Suite::assert(
    $storedPath !== null && is_file($sandbox . $storedPath),
    'E3 the file exists inside the sandbox uploads directory',
    'the file is on disk',
    'the file is missing'
);

$photoAfter = count(array_filter(scandir($uploadDir) ?: [], static fn (string $f): bool => $f !== '.' && $f !== '..'));

Suite::assert(
    $photoAfter === $photoBefore + 1,
    'E4 exactly one file was written',
    'one new file',
    "went from {$photoBefore} to {$photoAfter}"
);

// A real JPEG is accepted too, with a .jpg extension derived from its content.
if ($validJpg !== null) {
    $withJpg = postFoodWithFile($admin, 'create', [
        '_csrf'           => $token,
        'name'            => 'Iced Tea',
        'price'           => '20.00',
        'category_id'     => (string) $riceBowlsId,
        'is_available'    => '1',
        'low_stock_level' => '5',
        'stock'           => '30',
    ], 'photo', $validJpg, 'drink.jpg', 'image/jpeg');

    $jpgRow = $appPdo->query("SELECT * FROM food_items WHERE name = 'Iced Tea'")->fetch();

    Suite::assert(
        $jpgRow !== false
            && $jpgRow['image_path'] !== null
            && str_ends_with((string) $jpgRow['image_path'], '.jpg'),
        'E5 a real JPEG is accepted',
        'stored with a .jpg extension',
        'stored as ' . ($jpgRow === false ? 'n/a' : (string) ($jpgRow['image_path'] ?? 'null'))
    );
}

// -- A renamed PHP file is refused ------------------------------------------

$phpPayload = tempnam(sys_get_temp_dir(), 'ke-php-') . '.php';

file_put_contents(
    $phpPayload,
    "<?php echo 'CANTEXEC' . 'UTE'; ?>\n" . str_repeat('A', 64)
);

$withPhp = postFoodWithFile($admin, 'create', [
    '_csrf'           => $token,
    'name'            => 'Shell Attempt',
    'price'           => '1.00',
    'category_id'     => (string) $riceBowlsId,
    'is_available'    => '1',
    'low_stock_level' => '1',
    'stock'           => '1',
], 'photo', $phpPayload, 'shell.php', 'image/png');

$phpRow = $appPdo->query("SELECT * FROM food_items WHERE name = 'Shell Attempt'")->fetch();

// Two separate things must hold, and they are checked separately so a failure
// says which one broke: the upload must be refused, AND the whole product must
// not be created, because the page reports the upload error per-field and
// refuses the save rather than storing a product with no photo.
Suite::assert(
    $phpRow === false,
    'E6 a PHP file renamed to .png is refused and no product is saved',
    'nothing was created',
    $phpRow === false
        ? 'no row'
        : 'a product was created (image_path = ' . var_export($phpRow['image_path'], true) . ')'
);

$filesAfterPhp = array_filter(
    scandir($uploadDir) ?: [],
    static fn (string $file): bool => str_ends_with(strtolower($file), '.php')
);

Suite::assert(
    $filesAfterPhp === [],
    'E6b nothing executable was written for the PHP payload',
    'the uploads directory holds no PHP',
    'found: ' . implode(', ', $filesAfterPhp)
);

Suite::assert(
    ! str_contains($withPhp['body'], 'CANTEXEC'),
    'E7 the payload was never written anywhere the server could run it',
    'no PHP file was created',
    'the payload appears to have been written'
);

$phpInUploads = array_filter(
    scandir($uploadDir) ?: [],
    static fn (string $file): bool => str_ends_with(strtolower($file), '.php')
);

Suite::assert(
    $phpInUploads === [],
    'E8 no .php file exists in the uploads directory',
    'the directory holds no PHP',
    'found: ' . implode(', ', $phpInUploads)
);

// -- A text file is refused --------------------------------------------------

$textPayload = tempnam(sys_get_temp_dir(), 'ke-txt-') . '.txt';

file_put_contents($textPayload, str_repeat("just text, not an image\n", 40));

postFoodWithFile($admin, 'create', [
    '_csrf'           => $token,
    'name'            => 'Text Attempt',
    'price'           => '1.00',
    'category_id'     => (string) $riceBowlsId,
    'is_available'    => '1',
    'low_stock_level' => '1',
    'stock'           => '1',
], 'photo', $textPayload, 'notes.txt', 'text/plain');

Suite::assert(
    (int) $appPdo->query("SELECT COUNT(*) FROM food_items WHERE name = 'Text Attempt'")->fetchColumn() === 0,
    'E9 a text file is refused',
    'the product was not created with it',
    'the product was created'
);

// -- An image exceeding the size cap is refused -------------------------------

$bigPayload = tempnam(sys_get_temp_dir(), 'ke-big-') . '.png';

file_put_contents($bigPayload, $pngBytes . str_repeat("\x00", 3_000_000));

$filesBefore = count(array_filter(scandir($uploadDir) ?: [], static fn (string $f): bool => $f !== '.' && $f !== '..'));

postFoodWithFile($admin, 'create', [
    '_csrf'           => $token,
    'name'            => 'Oversized Attempt',
    'price'           => '1.00',
    'category_id'     => (string) $riceBowlsId,
    'is_available'    => '1',
    'low_stock_level' => '1',
    'stock'           => '1',
], 'photo', $bigPayload, 'huge.png', 'image/png');

$filesAfter = count(array_filter(scandir($uploadDir) ?: [], static fn (string $f): bool => $f !== '.' && $f !== '..'));

Suite::assert(
    (int) $appPdo->query("SELECT COUNT(*) FROM food_items WHERE name = 'Oversized Attempt'")->fetchColumn() === 0
        && $filesAfter === $filesBefore,
    'E10 an oversized image is refused and nothing is written',
    'no product, no file',
    "products: " . (int) $appPdo->query("SELECT COUNT(*) FROM food_items WHERE name = 'Oversized Attempt'")->fetchColumn()
        . ", files: {$filesBefore} -> {$filesAfter}"
);

// -- Deleting an image -------------------------------------------------------

$photoProductId = $photoRow === false ? 0 : (int) $photoRow['id'];
$photoFilePath  = $sandbox . (string) ($storedPath ?? '');

if ($photoProductId > 0 && $storedPath !== null) {
    Suite::assert(
        is_file($photoFilePath),
        'E11 the uploaded file is present before removal',
        basename($photoFilePath),
        'already missing'
    );

    $removalResponse = $admin->post('/admin/food.php', [
        '_csrf' => $token,
        'action' => 'remove_image',
        'id' => $photoProductId,
    ]);

    $afterRemoval = $appPdo->query('SELECT image_path FROM food_items WHERE id = ' . $photoProductId)->fetch();

    Suite::assert(
        $afterRemoval !== false && $afterRemoval['image_path'] === null,
        'E12 removing a photo clears it from the product',
        'image_path is null',
        'image_path = ' . ($afterRemoval === false ? 'n/a' : (string) $afterRemoval['image_path'])
    );

    // clearstatcache() before the check, and this is not optional.
    //
    // PHP caches the result of is_file() for the life of the process. E11
    // above called is_file($photoFilePath) and cached "yes". The web server
    // then deleted the file in a DIFFERENT process, but this process still
    // believes the cached answer — so the assertion reported a file that had
    // already been removed as still on disk, and the server-side probe
    // confirmed the unlink had succeeded.
    clearstatcache(true, $photoFilePath);

    $afterRemovalFiles = array_filter(
        scandir($uploadDir) ?: [],
        static fn (string $file): bool => $file !== '.' && $file !== '..'
    );

    Suite::assert(
        ! is_file($photoFilePath),
        'E13 removing a photo deletes the file',
        'the file is gone; ' . count($afterRemovalFiles) . ' file(s) remain in uploads/products',
        'the file is still on disk; HTTP ' . $removalResponse['status']
            . ' -> ' . $removalResponse['location']
            . ', app said: ' . lastMessage($admin, $removalResponse)
    );
}

// -- Placeholder used when there is no photo --------------------------------

$listPage = $admin->get('/admin/food.php')['body'];

Suite::assert(
    str_contains($listPage, '/assets/images/product-placeholder.svg'),
    'E14 products with no photo use the local placeholder',
    'the placeholder is referenced',
    'no placeholder found'
);

$placeholder = client()->get('/assets/images/product-placeholder.svg');

Suite::assert(
    $placeholder['status'] === 200,
    'E15 the placeholder image is served locally',
    'HTTP 200',
    'HTTP ' . $placeholder['status']
);

// -- isManaged refuses a traversal-shaped path ------------------------------

$appPdo->exec(
    "UPDATE food_items SET image_path = '../../includes/config.local.php' WHERE id = "
    . ($photoRow === false ? 0 : (int) $photoRow['id'])
);

$traversalPage = $admin->get('/admin/food.php')['body'];

Suite::assert(
    ! str_contains($traversalPage, 'includes/config.local.php"'),
    'E16 a hand-edited image path outside uploads is never turned into a link',
    'the placeholder was used instead',
    'the stored path was rendered as a URL'
);

$appPdo->exec(
    'UPDATE food_items SET image_path = NULL WHERE id = ' . ($photoRow === false ? 0 : (int) $photoRow['id'])
);

// -- Upload directory cannot execute code -----------------------------------

$uploadHtaccess = $sandbox . '/uploads/.htaccess';

Suite::assert(
    is_file($uploadHtaccess)
        && str_contains((string) file_get_contents($uploadHtaccess), 'php_flag engine off'),
    'E17 the uploads directory disables PHP execution',
    'the rule is present',
    'the rule is missing'
);

$probeName = 'probe' . getmypid() . '.php';

$probe = $admin->get('/uploads/products/' . $probeName);

Suite::assert(
    $probe['status'] === 403 || $probe['status'] === 404,
    'E18 requesting a PHP file from uploads is refused',
    'HTTP ' . $probe['status'],
    'HTTP ' . $probe['status'] . ' — the uploads directory is not protected'
);

// ---------------------------------------------------------------------------
// F. Rendering integrity
// ---------------------------------------------------------------------------

Suite::section('F. Rendering integrity');

$foodPage = $admin->get('/admin/food.php');
$foodHtml = $foodPage['body'];

// A shared diagnostic, so a failure here says what the page actually was rather
// than just "the marker was missing".
$foodStatus = 'HTTP ' . $foodPage['status']
    . ', ' . strlen($foodHtml) . ' bytes'
    . (str_contains($foodHtml, 'Something went wrong on our side') ? ', ERROR PAGE' : '');

Suite::assert(
    isset($foodPage['headers']['content-security-policy']),
    'F1 the food page sends a Content-Security-Policy',
    substr((string) ($foodPage['headers']['content-security-policy'] ?? ''), 0, 46) . '…',
    $foodStatus . ', header = ' . var_export($foodPage['headers']['content-security-policy'] ?? null, true)
);

$foodCsp = (string) ($foodPage['headers']['content-security-policy'] ?? '');

Suite::assert(
    ! str_contains($foodCsp, 'unsafe-inline'),
    'F2 the policy still forbids unsafe-inline',
    'no unsafe-inline',
    'unsafe-inline is allowed'
);

Suite::assert(
    str_contains($foodHtml, 'On the menu'),
    'F3 the page labels its counts',
    'the tile is present',
    $foodStatus
);

// F3 is asserted above, immediately after the page is fetched, so that a
// failure there reports the same diagnostic rather than a bare "missing".

Suite::assert(
    str_contains($foodHtml, 'Beef Tapa Rice'),
    'F4 a product this suite created appears in the list',
    'it is listed',
    $foodStatus . ' — the list did not show it'
);

Suite::assert(
    ! str_contains($foodHtml, '₱0.00</span>'),
    'F5 no zero-value tiles are invented',
    'no ₱0.00 placeholder tile',
    'a zero-value tile is present'
);

// An empty database must render zeros, not blanks and not an error.
$appPdo->exec('UPDATE food_items SET is_archived = 1');

$allArchived = $admin->get('/admin/food.php');

Suite::assert(
    $allArchived['status'] === 200
        && str_contains($allArchived['body'], 'No products yet'),
    'F6 with nothing on the menu the page shows an empty state',
    'HTTP 200 with an empty state',
    'HTTP ' . $allArchived['status'] . ', ' . strlen($allArchived['body']) . ' bytes'
);

$appPdo->exec('UPDATE food_items SET is_archived = 0');

$categoriesNow = $admin->get('/admin/categories.php');

Suite::assert(
    $categoriesNow['status'] === 200
        && str_contains($categoriesNow['body'], 'Rice Bowls')
        && ! str_contains($categoriesNow['body'], 'No categories yet'),
    'F7 the categories page lists real categories',
    'the seeded category is listed',
    'the list did not show the seeded category'
);

// -- Accessibility -----------------------------------------------------------

$formHtml = $admin->get('/admin/food.php')['body'];

Suite::assert(
    substr_count($formHtml, '<label class="field__label"') >= 6,
    'F8 every product field has a visible label',
    substr_count($formHtml, '<label class="field__label"') . ' labels',
    'only ' . substr_count($formHtml, '<label class="field__label"') . ' labels found'
);

Suite::assert(
    ! preg_match('/<label class="field__label"[^>]*>\s*<\/label>/', $formHtml),
    'F9 no field has an empty label',
    'every label has text',
    'an empty label was found'
);

Suite::assert(
    str_contains($formHtml, 'aria-describedby'),
    'F10 fields are wired to their hints and errors',
    'aria-describedby is used',
    'no aria-describedby'
);

Suite::assert(
    ! preg_match('/<select(?![^>]*id=)/', $formHtml) && ! preg_match('/<input type="file"(?![^>]*id=)/', $formHtml),
    'F11 every select and file input has an id for its label to point at',
    'all are identified',
    'an unlabelled control was found'
);

// ---------------------------------------------------------------------------
// G. Navigation
// ---------------------------------------------------------------------------

Suite::section('G. Navigation');

$navHtml = $admin->get('/admin/dashboard.php')['body'];

foreach (['Food Management', 'Categories'] as $label) {
    $linkOk = preg_match(
        '#<a class="nav-item" href="[^"]*/admin/(food|categories)\.php"#',
        $navHtml
    ) === 1;

    Suite::assert(
        $linkOk,
        "G1 \"{$label}\" is now a link in the sidebar, not a disabled row",
        'the sidebar links to it',
        'the sidebar still shows it as unavailable'
    );
}

// The administrator navigation model holds nine pages; Dashboard, Food
// Management and Categories now exist, so six remain disabled. The count is
// asserted exactly rather than as "at most" so that an entry silently dropped
// from the model, or one flipped to available before its page exists, is caught
// here instead of being discovered by a user clicking a dead link.
$disabledRows = substr_count($navHtml, 'class="nav-item nav-item--disabled"');

Suite::assert(
    $disabledRows === 6,
    'G2 exactly the six unbuilt admin sections remain disabled',
    '6 disabled rows',
    $disabledRows . ' disabled rows'
);

// The active row must be marked on the page being viewed.
$foodNav = $admin->get('/admin/food.php')['body'];

Suite::assert(
    preg_match('#<a class="nav-item" href="[^"]*admin/food\.php" aria-current="page"#', $foodNav) === 1,
    'G3 the food page marks itself as the current page',
    'aria-current="page" is present',
    'the active item is not marked'
);

$categoryNav = $admin->get('/admin/categories.php')['body'];

Suite::assert(
    preg_match('#<a class="nav-item" href="[^"]*admin/categories\.php" aria-current="page"#', $categoryNav) === 1,
    'G4 the categories page marks itself as the current page',
    'aria-current="page" is present',
    'the active item is not marked'
);

// Students must not gain any admin navigation by having these pages exist.
$studentNav = $student->get('/student/dashboard.php');

Suite::assert(
    ! str_contains($studentNav['body'], '/admin/food.php')
        && ! str_contains($studentNav['body'], '/admin/categories.php'),
    'G5 the student sidebar still has no administrator links',
    'no admin links are shown to a student',
    'an administrator link leaked into the student panel'
);

// ---------------------------------------------------------------------------
// H. Offline compliance
// ---------------------------------------------------------------------------

Suite::section('H. Offline compliance');

foreach (['/admin/food.php', '/admin/categories.php', '/admin/food.php?edit=' . $tapaId] as $path) {
    $page = $admin->get($path);

    $external = [];

    if (preg_match_all('#(?:src|href)="(https?:)?//[^"]+"#', $page['body'], $matches) > 0) {
        $external = $matches[0];
    }

    Suite::assert(
        $external === [],
        "H1 {$path} references nothing off the machine",
        'every src and href is relative',
        'external: ' . implode(', ', $external)
    );
}

$coreJs = client()->get('/assets/js/core.js');

Suite::assert(
    $coreJs['status'] === 200,
    'H2 the front-end runtime is served locally',
    'HTTP 200',
    'HTTP ' . $coreJs['status']
);

$placeholderSvg = client()->get('/assets/images/product-placeholder.svg');

Suite::assert(
    $placeholderSvg['status'] === 200
        && ! preg_match('#https?://(?!www\.w3\.org)#', $placeholderSvg['body']),
    'H3 the placeholder image is a local asset with no remote reference',
    'no remote reference',
    'the SVG points somewhere off the machine'
);

exit(Suite::verdict());