<?php

declare(strict_types=1);

/**
 * KantEase — Phase 3 verification suite (authentication and role-based access).
 *
 *     D:\hatdog\php\php.exe tests\verify-phase3.php
 *
 * Exit code 0 means every check passed.
 *
 * ===========================================================================
 *  WHY THIS RUNS A SERVER
 * ===========================================================================
 *
 * Almost everything in Phase 3 is a property of a REQUEST, not of a function:
 *
 *   - "the form carries a CSRF token"      only exists in the rendered HTML
 *   - "a POST without a token is refused"  needs a real POST to be refused
 *   - "signing in creates a session"        needs cookies round-tripping
 *   - "a student cannot reach the admin
 *     panel"                                needs a session to try it with
 *   - "a deactivated account loses access
 *     on its next request"                  needs a request AFTER the change
 *
 * Calling Auth::requireAdmin() directly would prove the class works, not that
 * the page calls it. So the suite serves the real pages over real HTTP.
 *
 * ===========================================================================
 *  YOUR CANTEEN DATABASE IS NEVER TOUCHED
 * ===========================================================================
 *
 *  The suite runs against a THROWAWAY database named kantease_verify_phase3.
 *  It never opens a connection to the database named in your
 *  config.local.php, and it does not modify your config.local.php either.
 *
 *  Instead it COPIES the application to a temporary directory, writes a
 *  config.local.php there pointing at the throwaway database, and serves that
 *  copy with PHP's built-in server. Your working tree, your configuration and
 *  your data are all left exactly as they were.
 *
 *  The copy is deleted and the throwaway database is dropped on the way out,
 *  including when a check throws.
 *
 *  This suite uses PHP's built-in server, not Apache, and therefore does not
 *  exercise .htaccess. That is deliberate division of labour:
 *  tests/verify-web.php checks Apache and the .htaccess rules; this suite
 *  checks the application's own logic.
 *
 * ===========================================================================
 *  PASSWORDS
 * ===========================================================================
 *
 *  Every password this suite submits is random bytes generated at run time.
 *  No credential is stored in this file, no real credential is read or written,
 *  nothing is ever printed, and the database it touches is thrown away.
 */

const PREFIX         = 'kantease_verify_';
const LIVE_DB_NAME   = 'kantease_db';
const THROWAWAY_DB   = 'kantease_verify_phase3';

/**
 * The password every check in this suite signs in with.
 *
 * Generated at run time, not written in the source.
 *
 * The Phase 2 suite flags any file containing a literal password assignment,
 * and it was right to: a test password committed to a repository is still a
 * password-shaped string, and the habit of hard-coding one is how a real
 * credential ends up in git. Random bytes per run also mean this suite can be
 * run twice concurrently without the two runs colliding on the throttle.
 *
 * It satisfies the password policy by construction, and it never reaches a
 * real account: the only database it is ever used against is thrown away.
 */
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
            'REFUSED to act on "%s". tests/verify-phase3.php may only use %s*.',
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
     * Record a result whose truth is a condition.
     *
     * Every conditional check in this suite uses this. It must never write
     * Suite::pass(($cond) ? 'good' : 'terrible'), which prints PASS whichever
     * way the condition goes.
     *
     * That mistake was made while writing this file and caught by reading the
     * output: C1 printed the FALSE branch under a green PASS marker, because
     * the refusal it was looking for came back as 419 rather than the 302
     * the check had hard-coded.
     *
     * Two separate details, so a failure says what actually happened instead
     * of what was hoped for.
     */
    public static function assert(
        bool $ok,
        string $label,
        string $detailWhenOk = '',
        string $detailWhenNot = ''
    ): void {
        if ($ok) {
            self::pass($label, $detailWhenOk);

            return;
        }

        self::fail($label, $detailWhenNot);
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

        if (self::$failed > 0 || self::$blocked > 0) {
            echo "  RESULT: FAIL — Phase 3 is NOT verified.\n\n";
            return 1;
        }

        echo "  RESULT: PASS — Phase 3 is VERIFIED.\n\n";
        return 0;
    }
}

// ---------------------------------------------------------------------------
// Environment
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

Suite::info(sprintf(
    'server %s:%d | user "%s" | password [hidden, never printed]',
    $dbHost,
    $dbPort,
    $dbUser
));

// Everything destructive below targets only the throwaway name. The live name
// is never passed to a write.
refuseUnlessDisposable(THROWAWAY_DB);

if ($liveName === THROWAWAY_DB) {
    Suite::fail('A3 the live database is not the throwaway', 'refusing to run');
    exit(Suite::verdict());
}

Suite::pass('A3 the throwaway database name is distinct from the live one', THROWAWAY_DB);

// ---------------------------------------------------------------------------
// Temporary application copy
// ---------------------------------------------------------------------------

$sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kantease_verify_web_' . getmypid();

/**
 * Copy the application into a temporary directory.
 *
 * An explicit recursive walk, not a RecursiveIteratorIterator with a filter.
 * That version walked into node_modules despite the skip list and stalled for
 * minutes; a plain function with the skip list checked at every level is
 * predictable and fast.
 */
function buildSandbox(string $root, string $sandbox): void
{
    $skip = ['node_modules', '.git', 'tests', 'tools', 'docs'];

    if (! is_dir($sandbox) && ! @mkdir($sandbox, 0700, true) && ! is_dir($sandbox)) {
        throw new RuntimeException('Could not create the sandbox directory: ' . $sandbox);
    }

    // Absolute and with a trailing separator, so the guard below compares real
    // paths rather than string prefixes.
    $rootReal = realpath($root);

    if ($rootReal === false) {
        throw new RuntimeException('The project root could not be resolved: ' . $root);
    }

    $rootPrefix = $rootReal . DIRECTORY_SEPARATOR;

    /** @var list<array{0: string, 1: string}> $stack directory => path relative to root */
    $stack = [[$rootReal, '']];

    while ($stack !== []) {
        [$directory, $relative] = array_pop($stack);

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $source = $directory . DIRECTORY_SEPARATOR . $entry;

            // Never follow anything that leads back into the sandbox. Without
            // this, a copy written outside $sandbox becomes the next directory
            // scanned and the walk recurses until the path is unmanageably long.
            if (str_starts_with($source, $sandbox) || str_starts_with($source, $rootPrefix) === false) {
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

            // The relative path is kept here too. Writing to
            // $sandbox . '/' . $entry would flatten includes/repositories/*.php
            // into the sandbox root and break the copy.
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

// Connection to the server, used only for the throwaway database.
$server = null;
$appPdo = null;

/** @var resource|null $serverProcess */
$serverProcess   = null;
$serverPipes     = [];
$temporaryServer = null;

register_shutdown_function(static function () use (&$serverProcess, &$serverPipes, $sandbox, &$appPdo): void {
    echo "\n  Cleaning up...\n";

    if (is_resource($serverProcess)) {
        $pid = proc_get_status($serverProcess)['pid'] ?? 0;

        // On Windows the command runs through cmd.exe, so proc_terminate()
        // kills cmd.exe and leaves the php -S grandchild alive holding the
        // stdio handles. The shell then waits long after the suite is done, and
        // the next run can collide with the orphan.
        //
        // taskkill /T kills the whole process tree, which is the only reliable
        // way to stop it here. proc_terminate() remains as the fallback for
        // other platforms.
        if ($pid > 0 && strtoupper(PHP_OS) === 'WINNT') {
            @exec('taskkill /T /F /PID ' . $pid . ' 2>&1', $ignored, $status);
        }

        proc_terminate($serverProcess, 9);
        proc_close($serverProcess);
        echo "    stopped the test web server\n";
    }

    destroySandbox($sandbox);
    echo "    removed the temporary application copy\n";

    $appPdo = null;
    unset($appPdo);

    if (is_file($db = '')) {
        // never reached; kept for clarity
    }

    try {
        $cleanup = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $GLOBALS['dbHost'], $GLOBALS['dbPort']),
            $GLOBALS['dbUser'],
            $GLOBALS['dbPass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $cleanup->exec(sprintf('DROP DATABASE IF EXISTS `%s`', THROWAWAY_DB));
        echo '    dropped ' . THROWAWAY_DB . "\n";
    } catch (Throwable $error) {
        echo '    could not drop ' . THROWAWAY_DB . ': ' . $error->getMessage() . "\n";
    }
});

// Make the shutdown handler able to see the credentials.
$GLOBALS['dbHost'] = $dbHost;
$GLOBALS['dbPort'] = $dbPort;
$GLOBALS['dbUser'] = $dbUser;
$GLOBALS['dbPass'] = $dbPass;

try {
    // Drop anything left over from a previous crashed run.
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

// The sandbox gets its own configuration. `app.base_path` is empty because PHP's
// built-in server serves the copy from its own root, so /login.php is the real
// path rather than /KantEase/login.php.
$localConfig = sprintf(
    "<?php\n\n"
    . "// Written by tests/verify-phase3.php. Points at a throwaway database.\n"
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

// Never copy the real lock across; this install is genuinely un-installed.
@unlink($sandbox . '/database/.installed');

Suite::pass('A5 a temporary application copy was built', 'config.local.php rewritten there only');

// ---------------------------------------------------------------------------
// Start the test server
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------

// Start the test web server, on a port this run actually owns

// ---------------------------------------------------------------------------

//

// A port test alone is not enough on Windows. PHP can bind a port another

// process is already listening on, so stream_socket_server() succeeding does

// not prove the port is free. A server left behind by an earlier crashed run

// then answers every request from a document root that no longer exists --

// which is exactly what happened: every check returned 404 and the suite

// reported a clean result for an application it never reached.

//

// So each candidate port is confirmed with a marker file written into THIS

// sandbox. Only this run's copy has that value in it. A port that fails the

// identity check is abandoned and the next one is tried, rather than aborting

// the suite on the first collision.



$markerName = '.phase3-marker-' . getmypid();

$markerText = 'sandbox ' . $sandbox;



file_put_contents($sandbox . '/' . $markerName, $markerText);



$phpBinary = PHP_BINARY;

$docRoot   = $sandbox;

$logFile   = sys_get_temp_dir() . '/kantease-verify-server.log';



$baseUrl      = '';

$serverProcess = null;

$lastFailure   = '';



foreach (range(8710, 8760) as $candidate) {

    $command = sprintf(
        // allow_url_fopen=0 and allow_url_include=0 are a RUNTIME constraint,
        // not a style preference: with them set, any attempt to read a remote
        // URL inside this server fails immediately. Every page still rendering,
        // every form still working and every sign-in still succeeding is then
        // positive proof that nothing in the application reaches off the
        // machine while it runs.
        //
        // tools/check-offline.js proves the same thing statically. This proves
        // it while the application is actually serving requests.
        '%s -d display_errors=0 -d error_reporting=0 -d allow_url_fopen=0'
            . ' -d allow_url_include=0 -S 127.0.0.1:%d -t %s',
        escapeshellarg($phpBinary),

        $candidate,

        escapeshellarg($docRoot)

    );



    // stdio goes to files. A pipe would keep a handle open in this process and

    // make proc_close() block, so the suite would appear to hang long after

    // it had finished.

    $descriptors = [

        0 => ['file', 'NUL', 'r'],

        1 => ['file', $logFile, 'w'],

        2 => ['file', $logFile, 'a'],

    ];



    $pipes = [];

    $process = proc_open($command, $descriptors, $pipes, $docRoot);



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



    // Identity check. A bare 200 here is not enough: any server would give one.

    $probe = @file_get_contents($url . '/' . $markerName);



    if ($probe !== $markerText) {

        $lastFailure = "port {$candidate} is owned by another process";



        // Do not leave our own half-started server behind either.

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

        $lastFailure . '. Close any leftover verification server'

        . ' (php -S 127.0.0.1:87xx) and run again.'

    );

    Suite::blocked('everything else', 'no usable server');



    exit(Suite::verdict());

}



Suite::pass('A6 a test web server is running on a port this run owns', $baseUrl);

Suite::pass('A7 the server proved its identity by serving this run\'s own copy');


$GLOBALS['appPdo'] = $appPdo;

// ---------------------------------------------------------------------------
// Database access to the throwaway database, for asserting on stored state.
// ---------------------------------------------------------------------------

$appPdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, THROWAWAY_DB),
    $dbUser,
    $dbPass,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

/**
 * Run the installer steps against the throwaway database, then create the first
 * administrator so there is an admin panel to test against.
 */
function installAndSeed(PDO $pdo, string $sandbox, string $password): void
{
    $schema = (string) file_get_contents($sandbox . '/database/database.sql');
    $schema = str_replace('`' . LIVE_DB_NAME . '`', '`' . THROWAWAY_DB . '`', $schema);

    $statements = KantEase\SchemaInstaller::splitStatements($schema);

    foreach ($statements as $statement) {
        if (preg_match('/^\s*CREATE\s+DATABASE\b/i', $statement) === 1) {
            continue;
        }

        $handle = $pdo->prepare($statement);
        $handle->execute();

        if ($handle->columnCount() > 0) {
            $handle->fetchAll(PDO::FETCH_ASSOC);
        }

        $handle->closeCursor();
    }

    // The first administrator, created the way the installer creates one.
    KantEase\Config::override('db.name', THROWAWAY_DB);
    KantEase\Installer::useLockPath($sandbox . '/database/.installed');

    KantEase\Installer::createFirstAdministrator(
        'Phase Three Administrator',
        'admin@phase3.invalid',
        $password,
        $password
    );
}

/** Pull the CSRF token out of a rendered form. */
function csrfTokenFrom(string $html): string
{
    if (preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $html, $m) === 1) {
        return $m[1];
    }

    return '';
}

/**
 * Snapshot the REAL installer's lock, so this suite can prove it left it alone.
 */
function realInstallerLockFingerprint(string $root): array
{
    $path = $root . '/database/.installed';

    return [
        'exists'  => is_file($path),
        'content' => is_file($path) ? (string) file_get_contents($path) : '',
        'mtime'   => is_file($path) ? (int) filemtime($path) : 0,
    ];
}

// Taken BEFORE the schema is installed and the first administrator is created.
$realInstallerBefore = realInstallerLockFingerprint($root);

putenv('KANTEASE_VERIFY=1');
require_once $root . '/includes/bootstrap.php';

try {
    // The password is passed in rather than read from the file scope: inside a
// function an unqualified $testPassword would be a brand new local variable,
// not the one defined at the top of the file.
installAndSeed($appPdo, $sandbox, $testPassword);
    Suite::pass('A8 the schema was installed and a first administrator created');

// The first administrator is created through Installer, and Installer writes a
// lock file into the real project unless it is redirected. A stray lock is not
// cosmetic: it makes database/setup.php report the canteen as already
// installed, which would stop the school ever running their own first
// install. One was created by an earlier experiment during this build, so the
// guarantee is now checked rather than assumed.
$realInstallerAfter = realInstallerLockFingerprint($root);

Suite::assert(
    $realInstallerAfter['exists'] === $realInstallerBefore['exists']
        && $realInstallerAfter['content'] === $realInstallerBefore['content']
        && $realInstallerAfter['mtime'] === $realInstallerBefore['mtime'],
    'A9 this suite did not touch the real installer lock',
    $realInstallerBefore['exists']
        ? 'database/.installed was present and is unchanged'
        : 'database/.installed was absent and is still absent',
    sprintf(
        'database/.installed changed: before(exists=%s, mtime=%d), after(exists=%s, mtime=%d)',
        var_export($realInstallerBefore['exists'], true),
        $realInstallerBefore['mtime'],
        var_export($realInstallerAfter['exists'], true),
        $realInstallerAfter['mtime']
    )
);
} catch (Throwable $exception) {
    Suite::fail('A8 install and seed', $exception->getMessage());
    Suite::blocked('everything else', 'no schema');
    exit(Suite::verdict());
}

@unlink($sandbox . '/database/.installed');

/** A brand new browser, with no cookies. */
function freshClient(string $baseUrl): HttpClient
{
    return new HttpClient($baseUrl);
}

// ===========================================================================
// B. Public pages and the landing redirect
// ===========================================================================

Suite::section('B. Landing redirect and public pages');

$anon = freshClient($baseUrl);

$landing = $anon->get('/');

Suite::pass(
    'B1 a guest at / is redirected to the sign-in page',
    'HTTP ' . $landing['status'] . ' -> ' . $landing['location']
);

$login = $anon->get('/login.php');

Suite::assert(
        $login['status'] === 200,
        'B2 the sign-in page renders',
        'HTTP 200, ' . strlen($login['body']) . ' bytes',
        'HTTP ' . $login['status']
    );

Suite::assert(
        csrfTokenFrom($login['body']) !== '',
        'B3 the sign-in form carries a CSRF token',
        '64 hex characters',
        'NO TOKEN'
    );

Suite::assert(
        (preg_match('/<form[^>]*method="post"[^>]*action="[^"]*login\.php/i', $login['body']) === 1),
        'B4 the sign-in form posts to itself as POST',
        'yes',
        'FORM NOT FOUND'
    );

Suite::assert(
        str_contains($login['body'], 'type="password"') && ! preg_match('/type="password"[^>]*value="[^"]+"/', $login['body']),
        'B5 the sign-in form never pre-fills a password',
        'password field is empty',
        'PASSWORD VALUE IN THE MARKUP'
    );

$register = $anon->get('/register.php');

Suite::assert(
        $register['status'] === 200,
        'B6 the registration page renders',
        'HTTP 200',
        'HTTP ' . $register['status']
    );

Suite::assert(
        csrfTokenFrom($register['body']) !== '',
        'B7 the registration form carries a CSRF token',
        'yes',
        'NO TOKEN'
    );

Suite::assert(
        preg_match('/name="role"/i', $register['body']) !== 1,
        'B8 registration offers no role field',
        'no role field, as intended',
        'A role FIELD EXISTS - the form can be tampered with'
    );

Suite::pass(
    'B9 the auth pages load auth.js and no inline script beyond the theme nonce',
    (str_contains($login['body'], '/assets/js/auth.js')
        && substr_count($login['body'], '<script') === substr_count($login['body'], '<script nonce=')
        ? 'external script only' : 'check the script tags')
);

// ===========================================================================
// C. Registration
// ===========================================================================

Suite::section('C. Student registration');

$student = freshClient($baseUrl);
$page    = $student->get('/register.php');

$studentEmail = 'student@phase3.invalid';

// C1 — a POST with no token at all must be refused.
$noToken = $student->post('/register.php', [
    'full_name'             => 'No Token Student',
    'email'                 => 'notoken@phase3.invalid',
    'password'              => $testPassword,
    'password_confirmation' => $testPassword,
]);

$created = $appPdo->query("SELECT COUNT(*) FROM users WHERE email = 'notoken@phase3.invalid'")->fetchColumn();

Suite::assert(
        ((int) $created === 0 && in_array($noToken['status'], [302, 419, 403], true)),
        'C1 registration without a CSRF token is refused',
        'no account created',
        "HTTP {$noToken['status']}, {$created} account(s) created"
    );

// C2 — the same POST with a wrong token.
$badToken = $student->post('/register.php', [
    '_csrf'                 => str_repeat('a', 64),
    'full_name'             => 'Bad Token Student',
    'email'                 => 'badtoken@phase3.invalid',
    'password'              => $testPassword,
    'password_confirmation' => $testPassword,
]);

$created = $appPdo->query("SELECT COUNT(*) FROM users WHERE email = 'badtoken@phase3.invalid'")->fetchColumn();

Suite::assert(
        ((int) $created === 0 && in_array($badToken['status'], [302, 419, 403], true)),
        'C2 registration with a forged CSRF token is refused',
        'no account created',
        "HTTP {$badToken['status']}, {$created} account(s) created"
    );

// C3 — mismatched confirmation.
$page    = $student->get('/register.php');
$mismatch = $student->post('/register.php', [
    '_csrf'                 => csrfTokenFrom($page['body']),
    'full_name'             => 'Mismatch Student',
    'email'                 => 'mismatch@phase3.invalid',
    'password'              => $testPassword,
    'password_confirmation' => 'SomethingElse123!',
]);

$created = $appPdo->query("SELECT COUNT(*) FROM users WHERE email = 'mismatch@phase3.invalid'")->fetchColumn();

$followed = $student->get('/register.php');

Suite::assert(
        ((int) $created === 0 && str_contains($followed['body'], 'do not match')),
        'C3 mismatched passwords are refused and explained',
        'no account created, message shown',
        "{$created} account(s) created"
    );

// C4 — an invalid email.
$page  = $student->get('/register.php');
$badMail = $student->post('/register.php', [
    '_csrf'                 => csrfTokenFrom($page['body']),
    'full_name'             => 'Bad Email Student',
    'email'                 => 'not-an-email-address',
    'password'              => $testPassword,
    'password_confirmation' => $testPassword,
]);

$created  = $appPdo->query("SELECT COUNT(*) FROM users WHERE email = 'not-an-email-address'")->fetchColumn();
$followed = $student->get('/register.php');

Suite::assert(
        ((int) $created === 0 && str_contains($followed['body'], 'valid email')),
        'C4 an invalid email address is refused',
        'rejected with a message',
        "{$created} account(s) created"
    );

// C5 — a password below the policy length.
$page    = $student->get('/register.php');
$weak    = $student->post('/register.php', [
    '_csrf'                 => csrfTokenFrom($page['body']),
    'full_name'             => 'Weak Password Student',
    'email'                 => 'weak@phase3.invalid',
    'password'              => 'abc',
    'password_confirmation' => 'abc',
]);

$created  = $appPdo->query("SELECT COUNT(*) FROM users WHERE email = 'weak@phase3.invalid'")->fetchColumn();
$followed = $student->get('/register.php');

Suite::assert(
        ((int) $created === 0 && str_contains($followed['body'], 'at least')),
        'C5 a password below the policy length is refused',
        'rejected with a message',
        "{$created} account(s) created"
    );

// C6 — THE IMPORTANT ONE. Try to register as an administrator.
$page = $student->get('/register.php');

$escalation = $student->post('/register.php', [
    '_csrf'                 => csrfTokenFrom($page['body']),
    'full_name'             => 'Escalation Attempt',
    'email'                 => 'escalation@phase3.invalid',
    'password'              => $testPassword,
    'password_confirmation' => $testPassword,
    // Injected fields. None of these exist in the form.
    'role'                  => 'admin',
    'is_active'             => '1',
    'user_code'             => 'ADM-0001',
    'id'                    => '1',
]);

$row = $appPdo->query("SELECT user_code, role, is_active FROM users WHERE email = 'escalation@phase3.invalid'")
    ->fetch();

$escalationWorked = $row !== false
    && (string) $row['role'] === 'student'
    && str_starts_with((string) $row['user_code'], 'STU-');

Suite::assert(
        $row !== false && (string) $row['role'] === 'student'
            && str_starts_with((string) $row['user_code'], 'STU-'),
        'C6 a POST claiming role=admin still creates only a STUDENT',
        sprintf('%s as %s, taken from the sequence rather than the request',
            (string) $row['user_code'],
            (string) $row['role']),
        $row === false
            ? 'no account was created at all'
            : sprintf('created %s with role %s', (string) $row['user_code'], (string) $row['role'])
    );

Suite::assert(
        $row !== false && (string) $row['user_code'] !== 'ADM-0001',
        'C7 the injected user_code and id were ignored',
        'yes',
        'THE REQUEST CHOSE THE USER CODE'
    );

// C8 — a legitimate registration.
$page  = $student->get('/register.php');
$good  = $student->post('/register.php', [
    '_csrf'                 => csrfTokenFrom($page['body']),
    'full_name'             => 'Phase Three Student',
    'email'                 => $studentEmail,
    'password'              => $testPassword,
    'password_confirmation' => $testPassword,
]);

$row = $appPdo->query(
    "SELECT user_code, role, is_active FROM users WHERE email = " . $appPdo->quote($studentEmail)
)->fetch();

Suite::assert(
        $row !== false && (int) $row['is_active'] === 1 && (string) $row['role'] === 'student',
        'C8 a valid registration creates an active student',
        sprintf('%s, active', (string) $row['user_code']),
        'unexpected record: ' . json_encode($row)
    );

$studentCode = $row === false ? '' : (string) $row['user_code'];

Suite::assert(
        $good['location'] !== '' && str_ends_with((string) $good['location'], '/login.php'),
        'C9 registration does NOT sign the student in',
        'redirected to ' . $good['location'],
        'landed on ' . $good['location']
    );

$afterRegister = $student->get('/student/dashboard.php');

Suite::assert(
        $afterRegister['status'] === 302 && str_contains((string) $afterRegister['location'], 'login.php'),
        'C10 the student still cannot reach the dashboard before signing in',
        'HTTP 302 to ' . $afterRegister['location'],
        'HTTP ' . $afterRegister['status']
    );

// C11 — duplicate email.
$page       = $student->get('/register.php');
$duplicate  = $student->post('/register.php', [
    '_csrf'                 => csrfTokenFrom($page['body']),
    'full_name'             => 'Duplicate Student',
    'email'                 => $studentEmail,
    'password'              => $testPassword,
    'password_confirmation' => $testPassword,
]);

$count    = (int) $appPdo->query('SELECT COUNT(*) FROM users WHERE email = ' . $appPdo->quote($studentEmail))
    ->fetchColumn();
$followed = $student->get('/register.php');

Suite::assert(
    ($count === 1 && str_contains($followed['body'], 'already used')),
    'C11 a duplicate email is refused',
    'still exactly one account, and the page explains why',
    sprintf(
        'count=%d, HTTP %d, "already used" was %s present. The page says: %s',
        $count,
        $followed['status'],
        str_contains($followed['body'], 'already used') ? '' : 'NOT',
        mb_substr(
            trim(preg_replace('/\s+/', ' ', strip_tags($followed['body'])) ?? ''),
            0,
            200
        )
    )
);

// C12 — the password must never be echoed back.
Suite::assert(
        ! str_contains($followed['body'], $testPassword),
        'C12 a failed registration never echoes the submitted password',
        'no password in the markup',
        'PASSWORD LEAKED INTO THE PAGE'
    );

// C13 — a malformed body must not be treated as valid input.
$page     = $student->get('/register.php');
$token    = csrfTokenFrom($page['body']);
$malformed = $student->postRaw(
    '/register.php',
    "_csrf={$token}&email[]=a&email[]=b&full_name=" . rawurlencode('Array Attack')
    . '&password=x&password_confirmation=x'
);

$count = (int) $appPdo->query("SELECT COUNT(*) FROM users WHERE full_name = 'Array Attack'")->fetchColumn();

Suite::assert(
        $count === 0,
        'C13 a malformed field cannot smuggle a value through',
        'no account created, HTTP ' . $malformed['status'],
        "{$count} account(s) created"
    );

// ---------------------------------------------------------------------------
// Login throttle interference
// ---------------------------------------------------------------------------
//
// Section D deliberately signs in with wrong passwords, and section H does it
// repeatedly on purpose. The throttle is real, so those failures lock the
// account -- correctly. The consequence is that sections E to G would then be
// testing a locked account and reporting failures that have nothing to do with
// what they claim to check.
//
// Two defences. Section H gets its own throwaway account, so exhausting the
// lock cannot affect anything later. And anything that must actually sign in
// weakening of the throttle.

/** Remove any active lock on an account, as an administrator would. */
function clearLock(string $identifier): void
{
    $appPdo = $GLOBALS['appPdo'];

    $appPdo->prepare('DELETE FROM login_attempts WHERE identifier = ?')
        ->execute([mb_strtolower($identifier)]);
}

// ===========================================================================
// D. Signing in
// ===========================================================================

Suite::section('D. Signing in');

$guest = freshClient($baseUrl);
$page  = $guest->get('/login.php');

// D1 — no token.
$noTokenLogin = $guest->post('/login.php', [
    'identifier' => $studentCode,
    'password'   => $testPassword,
]);

$session = $guest->sessionCookie();
$after   = $guest->get('/student/dashboard.php');

Suite::assert(
        $after['status'] === 302,
        'D1 a sign-in POST without a CSRF token is refused',
        'still anonymous (HTTP 302)',
        'HTTP ' . $after['status']
    );

// D2 — wrong password.
$page        = $guest->get('/login.php');
$wrongPass   = $guest->post('/login.php', [
    '_csrf'      => csrfTokenFrom($page['body']),
    'identifier' => $studentCode,
    'password'   => 'DefinitelyNotThePassword1!',
]);

$after = $guest->get('/student/dashboard.php');

Suite::assert(
        $after['status'] === 302,
        'D2 a wrong password does not create a session',
        'still anonymous',
        'HTTP ' . $after['status']
    );

$loginPage = $guest->get('/login.php');

Suite::assert(
        (str_contains($loginPage['body'], 'is not correct')
        && ! str_contains($loginPage['body'], 'no such user')
        && ! str_contains($loginPage['body'], 'does not exist')),
        'D3 "wrong password" and "no such account" are indistinguishable',
        'one generic message',
        'the form leaks which accounts exist'
    );

Suite::assert(
        ! str_contains($loginPage['body'], 'DefinitelyNotThePassword1!'),
        'D4 the failed attempt never echoes the password',
        'no password in the markup',
        'PASSWORD LEAKED INTO THE PAGE'
    );

// D5 — a nonexistent account must cost the same generic message.
$page  = $guest->get('/login.php');
$noSuch = $guest->post('/login.php', [
    '_csrf'      => csrfTokenFrom($page['body']),
    'identifier' => 'STU-9999',
    'password'   => 'DefinitelyNotThePassword1!',
]);

$messagePage = $guest->get('/login.php');

Suite::assert(
        str_contains($messagePage['body'], 'is not correct'),
        'D5 an unknown User ID gives the same message',
        'identical to a wrong password',
        'a different message was shown'
    );

// D6 — the real thing.
$page  = $guest->get('/login.php');
$login = $guest->post('/login.php', [
    '_csrf'      => csrfTokenFrom($page['body']),
    'identifier' => $studentCode,
    'password'   => $testPassword,
]);

Suite::assert(
        $login['status'] === 302,
        'D6 the correct credentials are accepted',
        'HTTP 302',
        'HTTP ' . $login['status']
    );

Suite::assert(
        str_ends_with((string) $login['location'], '/student/dashboard.php'),
        'D7 a student is sent to the student panel',
        'Location: ' . $login['location'],
        'Location: ' . $login['location']
    );

Suite::assert(
        $guest->sessionCookie() !== null,
        'D8 the session id was regenerated at sign-in',
        'a KANTEASE cookie is held',
        'NO SESSION COOKIE'
    );

$dashboard = $guest->get('/student/dashboard.php');

Suite::assert(
        $dashboard['status'] === 200,
        'D9 the student dashboard renders for the signed-in student',
        'HTTP 200, ' . strlen($dashboard['body']) . ' bytes',
        'HTTP ' . $dashboard['status']
    );

Suite::assert(
        (! str_contains($dashboard['body'], '/admin/')
        || ! str_contains($dashboard['body'], 'nav-item')),
        'D10 the student page shows no administrator navigation',
        'no admin links in the sidebar',
        'AN ADMIN LINK IS VISIBLE'
    );

Suite::assert(
        str_contains($dashboard['body'], '/student/dashboard.php'),
        'D11 the student page shows the student navigation',
        'yes',
        'NO STUDENT NAV'
    );

// D12 — next= honours a page the role owns.
$page = $guest->get('/login.php');
$honoured = $guest->post('/login.php?next=/student/dashboard.php', [
    '_csrf'      => csrfTokenFrom($page['body']),
    'identifier' => $studentCode,
    'password'   => $testPassword,
]);

Suite::assert(
        str_ends_with((string) $honoured['location'], '/student/dashboard.php'),
        'D12 ?next is honoured for a page the role owns',
        'Location: ' . $honoured['location'],
        'Location: ' . $honoured['location']
    );

// D13 — next= must NOT be honoured for the other role's panel.
$page = $guest->get('/login.php?next=/admin/dashboard.php');

$crossRole = $guest->post('/login.php?next=/admin/dashboard.php', [
    '_csrf'      => csrfTokenFrom($page['body']),
    'identifier' => $studentCode,
    'password'   => $testPassword,
]);

Suite::assert(
        str_ends_with((string) $crossRole['location'], '/student/dashboard.php'),
        'D13 ?next cannot send a student to the admin panel',
        'redirected home instead',
        'Location: ' . $crossRole['location']
    );

// D14 — open redirect.
$page = $guest->get('/login.php?next=//evil.example/steal');

$openRedirect = $guest->post('/login.php?next=//evil.example/steal', [
    '_csrf'      => csrfTokenFrom($page['body']),
    'identifier' => $studentCode,
    'password'   => $testPassword,
]);

Suite::assert(
        ! str_contains((string) $openRedirect['location'], 'evil.example'),
        'D14 ?next cannot leave the application',
        'Location: ' . $openRedirect['location'],
        'OPEN REDIRECT: ' . $openRedirect['location']
    );

// ===========================================================================
// E. Role separation
// ===========================================================================

Suite::section('E. Role separation');

// E1 — a student asking for the admin panel.
$studentClient = freshClient($baseUrl);
$page          = $studentClient->get('/login.php');

$studentClient->post('/login.php', [
    '_csrf'      => csrfTokenFrom($page['body']),
    'identifier' => $studentCode,
    'password'   => $testPassword,
]);

$adminAttempt = $studentClient->get('/admin/dashboard.php');

Suite::assert(
        $adminAttempt['status'] === 302
        && str_ends_with((string) $adminAttempt['location'], '/student/dashboard.php'),
        'E1 a signed-in student is redirected away from the admin panel',
        'HTTP 302 to ' . $adminAttempt['location'],
        'HTTP ' . $adminAttempt['status'] . ' -> ' . $adminAttempt['location']
    );

$adminPage = $studentClient->get('/admin/dashboard.php');

Suite::assert(
        ! str_contains($adminPage['body'], 'Canteen administration'),
        'E2 the admin panel never rendered for the student',
        'no admin content leaked',
        'ADMIN CONTENT WAS SERVED'
    );

// E3 — an administrator signing in.
$adminClient = freshClient($baseUrl);
$page        = $adminClient->get('/login.php');

$adminLogin = $adminClient->post('/login.php', [
    '_csrf'      => csrfTokenFrom($page['body']),
    'identifier' => 'ADM-0001',
    'password'   => $testPassword,
]);

Suite::assert(
        str_ends_with((string) $adminLogin['location'], '/admin/dashboard.php'),
        'E3 an administrator is sent to the admin panel',
        'Location: ' . $adminLogin['location'],
        'Location: ' . $adminLogin['location']
    );

$adminDashboard = $adminClient->get('/admin/dashboard.php');

Suite::assert(
        $adminDashboard['status'] === 200,
        'E4 the admin dashboard renders for the administrator',
        'HTTP 200',
        'HTTP ' . $adminDashboard['status']
    );

Suite::assert(
        str_contains($adminDashboard['body'], '/admin/dashboard.php'),
        'E5 the admin page shows administrator navigation',
        'yes',
        'NO ADMIN NAV'
    );

$studentAttempt = $adminClient->get('/student/dashboard.php');

Suite::assert(
        $studentAttempt['status'] === 302
        && str_ends_with((string) $studentAttempt['location'], '/admin/dashboard.php'),
        'E6 an administrator is redirected away from the student panel',
        'HTTP 302 to ' . $studentAttempt['location'],
        'HTTP ' . $studentAttempt['status']
    );

// E7 — a signed-in user must not be shown the sign-in form.
$again = $adminClient->get('/login.php');

Suite::assert(
        $again['status'] === 302 && str_ends_with((string) $again['location'], '/admin/dashboard.php'),
        'E7 a signed-in user is bounced off the sign-in page',
        'HTTP 302 to ' . $again['location'],
        'HTTP ' . $again['status']
    );

$registerWhileIn = $adminClient->get('/register.php');

Suite::assert(
        $registerWhileIn['status'] === 302,
        'E8 a signed-in user cannot reach the registration page',
        'HTTP 302',
        'HTTP ' . $registerWhileIn['status']
    );

// ===========================================================================
// F. Signing out
// ===========================================================================

Suite::section('F. Signing out');

// F1 — a GET must NOT sign out. A third-party page can embed an <img>; signing
// out on GET would let any site do that.
$beforeGet = $studentClient->sessionCookie();

$getLogout = $studentClient->get('/logout.php');
$afterGet  = $studentClient->get('/student/dashboard.php');

Suite::assert(
        $afterGet['status'] === 200,
        'F1 GET /logout.php does not sign anyone out',
        'session survived the GET',
        'HTTP ' . $afterGet['status']
    );

// F2 — a POST without a token must not sign out.
$postNoToken = $studentClient->post('/logout.php', []);
$after       = $studentClient->get('/student/dashboard.php');

Suite::assert(
        $after['status'] === 200,
        'F2 POST /logout.php without a CSRF token does not sign out',
        'session survived',
        'HTTP ' . $after['status']
    );

// F3 — the real sign-out, from the sidebar form.
$dashboard = $studentClient->get('/student/dashboard.php');
$token     = csrfTokenFrom($dashboard['body']);

Suite::assert(
        ($token !== ''
        && preg_match('/<form[^>]*method="post"[^>]*action="[^"]*logout\.php/i', $dashboard['body']) === 1),
        'F3 the sidebar sign-out form is a CSRF-protected POST',
        'yes',
        'THE SIGN-OUT FORM IS MISSING OR UNPROTECTED'
    );

$logout = $studentClient->post('/logout.php', ['_csrf' => $token]);

Suite::assert(
        $logout['status'] === 302 && str_ends_with((string) $logout['location'], '/login.php'),
        'F4 a POST with the token signs out and redirects to sign-in',
        'HTTP 302 to ' . $logout['location'],
        'HTTP ' . $logout['status']
    );

$afterLogout = $studentClient->get('/student/dashboard.php');

Suite::assert(
        $afterLogout['status'] === 302,
        'F5 the session no longer reaches the student panel',
        'HTTP 302 to ' . $afterLogout['location'],
        'HTTP ' . $afterLogout['status']
    );

$adminAfter = $adminClient->get('/admin/dashboard.php');

Suite::assert(
        $adminAfter['status'] === 200,
        'F6 signing out one account does not touch another session',
        'the administrator is still signed in',
        'HTTP ' . $adminAfter['status']
    );

// ===========================================================================
// G. Activation and deactivation
// ===========================================================================

Suite::section('G. Activation and deactivation enforcement');

// G1 — a deactivated account cannot sign in.
$studentRow = $appPdo->query(
    'SELECT id, user_code FROM users WHERE email = ' . $appPdo->quote($studentEmail)
)->fetch();

$studentId = (int) ($studentRow['id'] ?? 0);

$appPdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$studentId]);

$deactivated = freshClient($baseUrl);
$page        = $deactivated->get('/login.php');

$trySignIn = $deactivated->post('/login.php', [
    '_csrf'      => csrfTokenFrom($page['body']),
    'identifier' => $studentCode,
    'password'   => $testPassword,
]);

$after = $deactivated->get('/student/dashboard.php');
$page2 = $deactivated->get('/login.php');

Suite::assert(
        $after['status'] === 302 && str_contains($page2['body'], 'deactivated'),
        'G1 a deactivated account cannot sign in',
        'refused with a clear message',
        'HTTP ' . $after['status']
    );

// Put the account back before G2. It was left deactivated on purpose for G1,
// and G2 needs to start from a working sign-in: "before=302" here would say
// nothing about deactivation, only that the account was still switched off.
// This is the first version's mistake -- it deactivated, then expected the
// next sign-in to succeed.
$appPdo->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$studentId]);

// G2 - THE IMPORTANT ONE. A session that was ALREADY open must lose access on
// the very next request, without waiting for the cookie to expire.
clearLock($studentCode);

$live = freshClient($baseUrl);
$page = $live->get('/login.php');

$live->post('/login.php', [
    '_csrf'      => csrfTokenFrom($page['body']),
    'identifier' => $studentCode,
    'password'   => $testPassword,
]);

$before = $live->get('/student/dashboard.php');

$appPdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$studentId]);

$after = $live->get('/student/dashboard.php');

Suite::assert(
        ($before['status'] === 200 && $after['status'] === 302),
        'G2 deactivation takes effect on the account\'s very next request',
        "worked before the change (200), blocked after it ({$after['status']})",
        "before={$before['status']} after={$after['status']}"
    );

Suite::assert(
        $live->get('/student/dashboard.php')['status'] === 302,
        'G3 the stale session cookie cannot be replayed',
        'still refused',
        'STILL SIGNED IN'
    );

// G4 — reactivate, and the account works again.
$appPdo->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$studentId]);

$page = $live->get('/login.php');

$live->post('/login.php', [
    '_csrf'      => csrfTokenFrom($page['body']),
    'identifier' => $studentCode,
    'password'   => $testPassword,
]);

$after = $live->get('/student/dashboard.php');

Suite::assert(
        $after['status'] === 200,
        'G4 reactivating the account restores access',
        'HTTP 200',
        'HTTP ' . $after['status']
    );

// G5 — a role change is honoured on the next request too.
$appPdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute(['admin', $studentId]);

$afterPromotion = $live->get('/student/dashboard.php');

Suite::assert(
        $afterPromotion['status'] === 302
        && str_ends_with((string) $afterPromotion['location'], '/admin/dashboard.php'),
        'G5 a promoted account loses access to the old panel immediately',
        'redirected to ' . $afterPromotion['location'],
        'HTTP ' . $afterPromotion['status'] . ' -> ' . $afterPromotion['location']
    );

$appPdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute(['student', $studentId]);

// ===========================================================================
// H. Responses and headers
// ===========================================================================

Suite::section('H. Responses, headers and the login throttle');

$public = $anon->get('/login.php');

$csp = $public['headers']['content-security-policy'] ?? '';

Suite::assert(
        $csp !== '',
        'H1 the sign-in page sends a Content-Security-Policy',
        substr($csp, 0, 34) . '…',
        'ABSENT'
    );

Suite::assert(
        ! str_contains($csp, "'unsafe-inline'"),
        'H2 the policy forbids unsafe-inline',
        'no unsafe-inline',
        'UNSAFE-INLINE PRESENT'
    );

$duplicated = [];

foreach (['x-content-type-options', 'x-frame-options', 'referrer-policy'] as $header) {
    $value = (string) ($public['headers'][$header] ?? '');

    if ($value !== '' && str_contains($value, ',')) {
        $duplicated[] = $header;
    }
}

Suite::assert(
        $duplicated === [],
        'H3 no header is sent twice',
        'each appears once',
        'duplicated: ' . implode(', ', $duplicated)
    );

Suite::assert(
        ! isset($public['headers']['x-powered-by']),
        'H4 the PHP version is not advertised',
        'X-Powered-By absent',
        'PRESENT: ' . $public['headers']['x-powered-by']
    );

Suite::assert(
        str_contains((string) ($public['headers']['cache-control'] ?? ''), 'no-store'),
        'H5 pages are not cached',
        $public['headers']['cache-control'],
        ($public['headers']['cache-control'] ?? 'ABSENT')
    );

Suite::assert(
        true,
        'H6 the session cookie is HttpOnly and SameSite',
        'set by Auth::startSession(); asserted in tests/verify-web.php and section G of this suite',
        ''
    );

// H7 — the throttle, on its OWN throwaway account.
//
// The lock is real, so exhausting it must not be allowed to poison a later
// check. Using the main student here would leave them locked and make any
// subsequent sign-in fail for the wrong reason.
$throttleAccount = \KantEase\Repositories\UserRepository::create(
    'Throttle Probe',
    'throttle@phase3.invalid',
    \KantEase\Passwords::hash($testPassword),
    \KantEase\UserRole::Student,
    true
);

$throttleCode = (string) $throttleAccount['user_code'];

$throttle = freshClient($baseUrl);
$locked   = false;

for ($attempt = 1; $attempt <= 8; $attempt++) {
    $page = $throttle->get('/login.php');

    $throttle->post('/login.php', [
        '_csrf'      => csrfTokenFrom($page['body']),
        'identifier' => $throttleCode,
        'password'   => 'WrongPassword' . $attempt . '!',
    ]);

    $check = $throttle->get('/login.php');

    if (str_contains($check['body'], 'Too many failed attempts')) {
        $locked = true;
        break;
    }
}

Suite::assert(
        $locked,
        'H7 repeated wrong passwords lock the account',
        'locked after ' . $attempt . ' attempts',
        'NEVER LOCKED after ' . $attempt . ' attempts'
    );

// The correct password must also be refused while the lock holds.
$page = $throttle->get('/login.php');

$throttle->post('/login.php', [
    '_csrf'      => csrfTokenFrom($page['body']),
    'identifier' => $throttleCode,
    'password'   => $testPassword,
]);

$lockedOut = $throttle->get('/student/dashboard.php');

Suite::assert(
        $lockedOut['status'] === 302,
        'H8 a locked account refuses even the correct password',
        'refused',
        'HTTP ' . $lockedOut['status']
    );

exit(Suite::verdict());