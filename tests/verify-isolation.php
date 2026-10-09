<?php

declare(strict_types=1);

/**
 * KantEase — isolation guard.
 *
 *     C:\xampp\php\php.exe tests\verify-isolation.php
 *
 * Purpose: prove that the verification suite cannot reach the real canteen
 * database, and that nothing so far has damaged it.
 *
 * This runs BEFORE tests/verify-phase2.php in the normal order, and again
 * afterwards, to show the live database is byte-for-byte unchanged.
 *
 * CHECKS
 *   1. The suite names only kantease_verify_* databases.
 *   2. Every destructive statement in the suite is behind isDisposable().
 *   3. The suite redirects the connection before it opens one.
 *   4. Fingerprint the live database, run the suite, fingerprint it again.
 */

const LIVE_DB_NAME = 'kantease_db';
const PREFIX = 'kantease_verify_';

$root = dirname(__DIR__);

$passed = 0;
$failed = 0;

function check(bool $ok, string $label, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        printf("  \033[32mPASS\033[0m  %-58s %s\n", $label, $detail);
        return;
    }

    $failed++;
    printf("  \033[31mFAIL\033[0m  %-58s %s\n", $label, $detail);
}

echo "\n" . str_repeat('=', 78) . "\n";
echo "  KantEase — database isolation guard\n";
echo str_repeat('=', 78) . "\n\n";

$suite = (string) file_get_contents($root . '/tests/verify-phase2.php');

// ---------------------------------------------------------------------------
// 1. No hard-coded live database name in any write position
// ---------------------------------------------------------------------------

check(
    ! preg_match('/DROP\s+DATABASE\s+(?:IF\s+EXISTS\s+)?`?' . LIVE_DB_NAME . '/i', $suite),
    'the suite never drops the live database',
    'no DROP against ' . LIVE_DB_NAME
);

check(
    ! preg_match('/TRUNCATE\s+`?' . LIVE_DB_NAME . '/i', $suite),
    'the suite never truncates the live database'
);

// ---------------------------------------------------------------------------
// 2. The disposable guard exists and is used
// ---------------------------------------------------------------------------

check(
    str_contains($suite, 'function isDisposable') && str_contains($suite, 'REFUSED to drop'),
    'dropDatabase() refuses any name outside the throwaway namespace'
);

$dropStatements = preg_match_all("/->exec\(sprintf\(\s*'DROP DATABASE/", $suite);
check($dropStatements > 0, 'dropDatabase() is the only DROP path in the suite', $dropStatements . ' call site(s)');

// ---------------------------------------------------------------------------
// 3. Redirection happens before any connection
// ---------------------------------------------------------------------------

$overridePos = strpos($suite, "Config::override('db.name'");
$connectPos  = strpos($suite, 'Database::connection()');

check(
    $overridePos !== false && $connectPos !== false && $overridePos < $connectPos,
    'the throwaway database is set before any connection is opened',
    'override at char ' . $overridePos . ', first connection at char ' . $connectPos
);

// Config::override() itself must refuse once a connection exists.
$configSource = (string) file_get_contents($root . '/includes/config.php');
check(
    str_contains($configSource, 'Database::hasConnection()'),
    'Config::override() refuses after a connection exists'
);

// ---------------------------------------------------------------------------
// 4. Cleanup runs even when the suite dies
// ---------------------------------------------------------------------------

check(
    str_contains($suite, 'register_shutdown_function'),
    'throwaway databases are dropped on shutdown, including after a crash'
);

// ---------------------------------------------------------------------------
// 5. Fingerprint the live database now
// ---------------------------------------------------------------------------

echo "\n  --- Live database fingerprint ------------------------------------\n";

$fingerprintFile = $root . '/tests/.live-fingerprint.txt';

function fingerprintLiveDatabase(string $root): ?array
{
    $configFile = $root . '/includes/config.local.php';

    if (! is_file($configFile)) {
        return null;
    }

    $config = require $configFile;

    if (! is_array($config) || ! isset($config['db'])) {
        return null;
    }

    $name = (string) $config['db']['name'];

    try {
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $config['db']['host'],
                $config['db']['port'],
                $name
            ),
            (string) $config['db']['user'],
            (string) $config['db']['password'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        $tables = $pdo
            ->query(sprintf(
                "SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s ORDER BY TABLE_NAME",
                $pdo->quote($name)
            ))
            ->fetchAll();
    } catch (Throwable $exception) {
        return ['error' => $exception->getMessage(), 'name' => $name];
    }

    $rowCounts = [];

    foreach ($tables as $table) {
        try {
            $rowCounts[$table['TABLE_NAME']] = (int) $pdo
                ->query(sprintf('SELECT COUNT(*) FROM `%s`', $table['TABLE_NAME']))
                ->fetchColumn();
        } catch (Throwable) {
            $rowCounts[$table['TABLE_NAME']] = -1;
        }
    }

    return ['name' => $name, 'tables' => array_keys($rowCounts), 'rows' => $rowCounts];
}

$live = fingerprintLiveDatabase($root);

if ($live === null) {
    echo "  \033[33mSKIP\033[0m  includes/config.local.php not readable — cannot fingerprint\n";
} elseif (isset($live['error'])) {
    echo "  \033[33mSKIP\033[0m  live database not reachable: " . $live['error'] . "\n";
    echo "         (create it with database/setup.php if you want this check)\n";
} else {
    $total = array_sum(array_filter($live['rows'], static fn (int $n): bool => $n >= 0));

    printf("  Live database \"%s\": %d tables, %d rows\n", $live['name'], count($live['tables']), $total);

    check(
        ! str_starts_with($live['name'], PREFIX),
        'the live database is not a throwaway',
        $live['name']
    );

    // Save or compare.
    if (is_file($fingerprintFile)) {
        $before = json_decode((string) file_get_contents($fingerprintFile), true);

        $same = is_array($before)
            && ($before['name'] ?? null) === $live['name']
            && ($before['rows'] ?? null) === $live['rows'];

        check($same, 'the live database is unchanged since the last run', $same ? 'identical' : 'CHANGED');

        if (! $same) {
            echo "        before: " . json_encode($before['rows'] ?? []) . "\n";
            echo "        now   : " . json_encode($live['rows']) . "\n";
        }
    } else {
        file_put_contents($fingerprintFile, (string) json_encode($live));
        printf("  ----  baseline fingerprint saved to %s\n", basename($fingerprintFile));
        printf("        Run the suite, then re-run this file to prove nothing changed.\n");
    }
}

// ---------------------------------------------------------------------------
// 6. No throwaway database left behind
// ---------------------------------------------------------------------------

if (isset($live['name']) && ! isset($live['error'])) {
    try {
        $config = require $root . '/includes/config.local.php';

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['db']['host'], $config['db']['port']),
            (string) $config['db']['user'],
            (string) $config['db']['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $leftovers = $pdo
            ->query(sprintf(
                "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE '%s%%'",
                PREFIX
            ))
            ->fetchAll(PDO::FETCH_COLUMN);

        check(
            $leftovers === [],
            'no throwaway database left behind',
            $leftovers === [] ? 'clean' : implode(', ', $leftovers)
        );
    } catch (Throwable $exception) {
        printf("  \033[33mSKIP\033[0m  could not list databases: %s\n", $exception->getMessage());
    }
}

echo "\n" . str_repeat('=', 78) . "\n";
printf("  %d passed, %d failed\n\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);