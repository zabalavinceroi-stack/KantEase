<?php

declare(strict_types=1);

/**
 * KantEase — web access and header verification.
 *
 * Proves that the sensitive parts of the application cannot be fetched
 * through a browser, and that the security headers are actually being sent.
 * An .htaccess rule can look perfect in a file and do nothing; this checks the
 * real HTTP responses from a running Apache.
 *
 *     C:\xampp\php\php.exe tests\verify-web.php
 *
 * Optional: pass a base URL explicitly
 *     C:\xampp\php\php.exe tests\verify-web.php http://localhost/KantEase
 *
 * Exit code 0 = no sensitive file is readable AND every header is present.
 *
 * The base URL is derived from includes/config.local.php (app.base_path) when
 * you do not pass one, so the suite always tests the address the application
 * is actually served from rather than a hardcoded guess.
 */

/**
 * Loading the configuration needs three files, in this order:
 *
 *   exceptions.php  declares ConfigurationException, which Config::load() throws
 *   config.php      declares the Config class itself
 *
 * bootstrap.php would also work, but it opens a database connection's worth of
 * setup, starts a PHP session and registers the HTML error handler. None of
 * that is wanted in a script whose whole job is to issue HTTP GETs, and
 * starting a session here would write a cookie file for no reason.
 *
 * The earlier version required exceptions.php and enums.php but NOT
 * config.php, so `use KantEase\Config` resolved to a name that had never been
 * declared:
 *
 *     Fatal error: Uncaught Error: Class "KantEase\AuditRepository" not found
 *     (same shape: a `use` statement is only an alias, it loads nothing)
 */
require_once __DIR__ . '/../includes/exceptions.php';
require_once __DIR__ . '/../includes/config.php';

use KantEase\Config;

/**
 * Perform a GET and capture the status line and headers.
 *
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function fetch(string $url): array
{
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => 'KantEaseVerifyWeb/1.0',
        ]);

        $raw    = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $size   = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        $parsed = parseHeaders(substr($raw, 0, $size));

        return [
            'status'   => $status,
            'headers'  => $parsed,
            'body'     => substr($raw, $size),
            // Several checks are about WHERE a request was sent, not merely
            // whether it succeeded. Reading $parsed['location'] directly would
            // raise an undefined-key warning when there is no redirect at all,
            // so it is normalised once, here.
            'location' => $parsed['location'] ?? '',
        ];
    }

    $context = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0],
    ]);

    $body = @file_get_contents($url, false, $context);
    $raw  = '';

    foreach ($http_response_header ?? [] as $header) {
        $raw .= $header . "\n";
    }

    $status = 0;

    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $raw, $matches) === 1) {
        $status = (int) $matches[1];
    }

    $parsed = parseHeaders($raw);

    return [
        'status'   => $status,
        'headers'  => $parsed,
        'body'     => (string) $body,
        'location' => $parsed['location'] ?? '',
    ];
}

/**
 * @return array<string, string> lower-cased header name => value
 */
function parseHeaders(string $raw): array
{
    $headers = [];

    foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
        if (! str_contains($line, ':')) {
            continue;
        }

        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }

    return $headers;
}

/**
 * Is this status one that means "you are not getting the file"?
 *
 * 200 is the only dangerous answer. 401/403/404 are all refusals, 3xx means
 * Apache redirected us away rather than serving it.
 *
 * IMPORTANT: this is only meaningful once the deployment gate has confirmed the
 * application is actually being served. Without that, "404" is indistinguishable
 * from "nothing is deployed", and every refusal below would be a false pass.
 */
function isRefused(int $status): bool
{
    return $status !== 200 && $status !== 206;
}

/**
 * The per-request timeout, read from fetch() so the failure message quotes the
 * same number the request actually used.
 */
function self_timeout(): int
{
    return 10;
}

// ---------------------------------------------------------------------------
// Base URL
// ---------------------------------------------------------------------------

$configFile = __DIR__ . '/../includes/config.local.php';

if ($argv[1] ?? null) {
    // An explicit argument always wins, so the suite can be pointed at any host.
    $baseUrl = rtrim($argv[1], '/');
} elseif (is_file($configFile)) {
    try {
        Config::load();
        $baseUrl = 'http://localhost' . Config::basePath();
    } catch (KantEase\ConfigurationException $exception) {
        // config.local.php exists but is unusable. That is worth reporting
        // precisely rather than silently falling back to a guessed URL, which
        // would produce a run full of confusing "cannot reach" failures.
        fwrite(STDERR, PHP_EOL . 'KantEase configuration problem: ' . $exception->getMessage() . PHP_EOL);
        fwrite(STDERR, 'Pass a base URL explicitly to bypass it:' . PHP_EOL);
        fwrite(STDERR, '  php tests/verify-web.php http://localhost/KantEase' . PHP_EOL . PHP_EOL);
        exit(1);
    }
} else {
    $baseUrl = 'http://localhost/KantEase';
}

$host = parse_url($baseUrl, PHP_URL_HOST) ?: 'localhost';
$scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'http';

echo "\n" . str_repeat('=', 74) . "\n";
echo "  KantEase — web access and header verification\n";
echo str_repeat('=', 74) . "\n\n";
echo "  Base URL : {$baseUrl}\n";
echo "  Source   : " . (($argv[1] ?? null) ? 'command line' : (is_file($configFile) ? 'app.base_path in config.local.php' : 'default guess')) . "\n\n";

$passes = 0;
$failures = 0;
$problems = [];

/**
 * Record one result.
 *
 * $ok is a bool. It was declared `string`, so every call site passing a real
 * condition — the normal case — raised
 *
 *     TypeError: report(): Argument #1 ($ok) must be of type string, bool given
 *
 * on the first sensitive path it reached.
 */
function report(bool $ok, string $label, string $detail = ''): void
{
    global $passes, $failures, $problems;

    if ($ok) {
        $passes++;
        printf("  \033[32mPASS\033[0m  %-46s %s\n", $label, $detail);
        return;
    }

    $failures++;
    $problems[] = $label . ($detail === '' ? '' : ' - ' . $detail);
    printf("  \033[31mFAIL\033[0m  %-46s %s\n", $label, $detail);
}

// ---------------------------------------------------------------------------
// 0. Deployment gate — POSITIVE CONTROL
// ---------------------------------------------------------------------------
//
// Almost every check in this suite has the shape "this path must NOT be
// readable", and a 404 satisfies all of them. That is the trap: with Apache
// serving an empty document root, all twenty-two sensitive paths return 404
// for the boring reason that no such document exists anywhere, and the suite
// cheerfully reports a clean bill of health for an application that is not
// deployed at all.
//
// A security check that passes when the target is absent is worse than no
// check, because it manufactures false confidence. So the suite first proves
// the application IS being served, using a file that is required to be public
// and genuinely exists in the repository: assets/js/core.js.
//
// Only after that control succeeds can a 404 on config.local.php be read as
// evidence of protection rather than evidence of nothing.

echo "  --- Deployment gate (positive control) ------------------------------\n";

$controlPath = 'assets/js/core.js';
$control     = fetch($baseUrl . '/' . $controlPath);
$controlOk   = $control['status'] === 200;

// The application root itself. Kept here because the directory-listing check
// below reads it, and its response headers are what the header checks use.
$root = fetch($baseUrl . '/');

report(
    $controlOk,
    "/{$controlPath} is served (proves the app is deployed)",
    $controlOk
        ? 'HTTP 200, ' . strlen($control['body']) . ' bytes'
        : 'HTTP ' . $control['status'] . ' — the app is NOT reachable at this base URL'
);

if (! $controlOk) {
    echo "\n";
    echo "  \033[31mSTOP\033[0m  Without this control every \"not readable\" check below\n";
    echo "         would pass for the wrong reason, so none of them were run.\n\n";
    echo "         Apache is answering, but it is not serving KantEase from that address.\n\n";
    echo "         Fix the deployment, then run this again:\n\n";
    echo "           1. Start Apache.\n";
    echo "           2. Make the project visible inside the DocumentRoot. Either copy\n";
    echo "              it to <DocumentRoot>\\KantEase, or create a link:\n\n";
    echo "                 mklink /J \"<DocumentRoot>\\KantEase\" \"" . str_replace('/', '\\', dirname(__DIR__)) . "\"\n\n";
    echo "           3. Check the DocumentRoot and this folder agree:\n";
    echo "                 grep -i \"^DocumentRoot\" <apache>\\conf\\httpd.conf\n\n";
    echo "         Or bypass discovery and name the address explicitly:\n\n";
    echo "           php tests/verify-web.php http://localhost/KantEase\n\n";
    echo "  RESULT: FAIL — the application is not deployed, so nothing was verified.\n\n";
    exit(1);
}

echo "\n  Every request below is a real HTTP request against that address.\n";
echo "  With the control above confirmed, a 404 on a sensitive path means the\n";
echo "  server actively refused it, not that the app is missing.\n\n";

// ---------------------------------------------------------------------------
// 1. Sensitive paths must not be readable
// ---------------------------------------------------------------------------

echo "  --- Sensitive files ---------------------------------------------------\n";

$sensitive = [
    'includes/config.local.php'          => 'the database credentials',
    'includes/config.php'                => 'the configuration loader',
    'includes/config.sample.php'         => 'the config template',
    'includes/database.php'              => 'connection settings',
    'includes/auth.php'                  => 'authentication logic',
    'includes/bootstrap.php'             => 'the application entry point',
    'includes/repositories/'             => 'every SQL statement',
    'includes/layout/'                   => 'the page shells',
    'docs/PHASE-1-AUDIT.md'              => 'the internal audit report',
    'docs/SETUP-LOCAL.md'                => 'the setup guide',
    'database/database.sql'              => 'the schema',
    'database/migrate_from_node.sql'     => 'the migration script',
    'database/.installed'                => 'the installer lock',
    '.htaccess'                          => 'the server configuration',
    '.gitignore'                         => 'the ignore rules',
    'includes/.htaccess'                 => 'access rules',
    'uploads/products/'                  => 'the uploads listing',
    'server.js'                          => 'the legacy source, which holds a credential',
    'package.json'                       => 'the legacy dependency manifest',
    'node_modules/mariadb/package.json'  => 'the dependency tree',
    'tests/verify-phase2.php'            => 'the verification suite',
];

foreach ($sensitive as $path => $reason) {
    $response = fetch($baseUrl . '/' . ltrim($path, '/'));

    // Status 0 means no response at all: a timeout, a reset, or a script that
    // is still running. For a path that must not be readable, "I could not
    // find out" is NOT a pass, and it is not a skip either.
    //
    // Reporting it as SKIP is what hid the tests/ hole: the request timed out
    // because Apache was EXECUTING verify-phase2.php rather than refusing it,
    // and the suite moved on as though nothing had been learned. An
    // inconclusive security result has to be a failure until proven otherwise.
    if ($response['status'] === 0) {
        report(
            false,
            '/' . $path,
            'no response — the server did not refuse it within '
            . self_timeout() . 's. A refusal is instant; a timeout usually means'
            . ' the file is being EXECUTED, not blocked. (' . $reason . ')'
        );
        continue;
    }

    report(
        isRefused($response['status']),
        '/' . $path,
        'HTTP ' . $response['status'] . ' (' . $reason . ')'
    );
}

// ---------------------------------------------------------------------------
// 2. The root must not be a directory listing
// ---------------------------------------------------------------------------

echo "\n  --- Directory listing ----------------------------------------------\n";

$listingSigns = ['Index of', 'Directory listing for', 'Parent Directory'];

$isListing = false;
foreach ($listingSigns as $sign) {
    if (str_contains($root['body'], $sign)) {
        $isListing = true;
    }
}

report(! $isListing, 'GET /', $isListing ? 'RETURNS A DIRECTORY LISTING' : 'not a file listing');

// Say WHY it is not a listing. A 403 from `Options -Indexes` is a refusal and
// fine; a 200 that merely happens not to contain the word "Index of" would be
// passing for the wrong reason, and the reader deserves to know which they got.
if ($root['status'] === 403 || $root['status'] === 401) {
    echo "  ----  HTTP {$root['status']}: 'Options -Indexes' is refusing the listing. Good.\n";
} elseif ($root['status'] === 200) {
    echo "  ----  HTTP 200: a page rendered here. The listing check still applies.\n";
} else {
    echo "  ----  HTTP {$root['status']}. Expected until Phase 3 adds index.php.\n";
}

// ---------------------------------------------------------------------------
// 3. The installer must be reachable before it is used, and gone afterwards
// ---------------------------------------------------------------------------

echo "\n  --- Installer -------------------------------------------------------\n";

$lockExists = is_file(__DIR__ . '/../database/.installed');
$setup      = fetch($baseUrl . '/database/setup.php');

if ($setup['status'] === 200) {
    if ($lockExists) {
        report(
            false,
            'GET /database/setup.php',
            'HTTP 200 but database/.installed exists — the lock file was not honoured. Delete setup.php.'
        );
    } else {
        report(
            true,
            'GET /database/setup.php',
            'HTTP 200 and not yet installed — the installer is available'
        );
    }
} elseif ($lockExists && in_array($setup['status'], [403, 404], true)) {
    report(true, 'GET /database/setup.php', 'HTTP ' . $setup['status'] . ' — installer disabled, as intended');
} else {
    report(
        in_array($setup['status'], [403, 404], true),
        'GET /database/setup.php',
        'HTTP ' . $setup['status']
    );
}

// ---------------------------------------------------------------------------
// 4. Security headers
// ---------------------------------------------------------------------------

echo "\n  --- Security headers ------------------------------------------------\n";

// Headers must be read from a page PHP actually rendered.
//
// The earlier version read them from GET /, which returns 403 because
// `Options -Indexes` plus a missing index.php means Apache serves its own error
// document and no PHP runs. Every mod_headers rule still applied there, so the
// checks looked healthy — but PHP-generated headers were never tested at all,
// and the Content-Security-Policy is emitted by PHP (it carries a per-request
// nonce, so it cannot live in .htaccess).
//
// That blind spot hid two real defects: no CSP on any page, and
// `X-Powered-By: PHP/8.2.12` in the wild.
//
// Preference order, most reliable first.
//
//   /login.php           always renders for a guest, and goes through the same
//                        Layout path as every other application page
//   /database/setup.php  the installer, while it is still uninstalled
//   /                    last resort, and almost always a 302
//
// Falling back to / used to hide a real defect. A 302 is not a 200, so the
// check failed with 'PHP-generated headers could not be tested' and nothing
// was learned -- while the underlying cause, that redirect() sent no headers
// at all, went unremarked. redirect() sends them now. Measuring a page that
// actually renders is the stronger test either way.
$headerTarget = null;

foreach (['/login.php', '/database/setup.php', '/'] as $candidate) {
    $candidateResponse = $candidate === '/' ? $root : fetch($baseUrl . $candidate);

    if ($candidateResponse['status'] === 200) {
        $headerTarget = ['url' => $candidate, 'response' => $candidateResponse];

        break;
    }
}

if ($headerTarget === null) {
    $headerTarget = ['url' => '/', 'response' => $root];
}

$probe      = $headerTarget['response'];
$probeName  = $headerTarget['url'];

echo "  ----  measured on {$probeName} (HTTP {$probe['status']})\n";

if ($probe['status'] !== 200) {
    report(
        false,
        'a PHP-rendered page is available to test',
        "{$probeName} returned HTTP {$probe['status']}, so PHP-generated headers could not be tested"
    );
}

$expected = [
    'x-content-type-options'  => 'nosniff',
    'x-frame-options'         => 'SAMEORIGIN',
    'referrer-policy'         => 'same-origin',
];

foreach ($expected as $header => $value) {
    $actual = $probe['headers'][$header] ?? '(absent)';

    report(
        strcasecmp($actual, $value) === 0,
        'header ' . $header,
        $actual === '(absent)' ? 'ABSENT' : $actual
    );
}

// Exactly one value, not two.
//
// These headers are emitted by BOTH the root .htaccess and, previously, by PHP.
// mod_headers appends to whatever PHP already sent, so the wire carried
// "nosniff,nosniff" — which still contains "nosniff" and therefore still passed
// a naive substring check. A duplicated header is a real defect: parsers are
// free to take either value, so the stronger one is not guaranteed to win.
$duplicated = [];

foreach (array_keys($expected) as $header) {
    $actual = (string) ($probe['headers'][$header] ?? '');

    if ($actual !== '' && str_contains($actual, ',')) {
        $duplicated[] = $header . ' = ' . $actual;
    }
}

report(
    $duplicated === [],
    'no duplicated headers',
    $duplicated === [] ? 'each header appears exactly once' : implode('; ', $duplicated)
);

$csp = $probe['headers']['content-security-policy'] ?? '';

report(
    $csp !== '',
    'header content-security-policy',
    $csp === '' ? 'ABSENT' : substr($csp, 0, 46) . '…'
);

if ($csp !== '') {
    report(
        str_contains($csp, "default-src 'self'") && ! str_contains($csp, "'unsafe-inline'"),
        'csp has no unsafe-inline and defaults to self',
        str_contains($csp, "'unsafe-inline'") ? "unsafe-inline PRESENT" : 'strict'
    );

    // A nonce is only meaningful if it is actually attached to the markup it
    // authorises. setup.php mints one and puts it on its inline <style>.
    if (str_contains($csp, "'nonce-")) {
        $headerNonce = '';
        if (preg_match("/'nonce-([^']+)'/", $csp, $m) === 1) {
            $headerNonce = $m[1];
        }

        if ($headerNonce !== '' && preg_match('/<style nonce="([^"]+)"/', $probe['body'], $m) === 1) {
            report(
                hash_equals($headerNonce, $m[1]),
                'the inline stylesheet carries the CSP nonce',
                $headerNonce === $m[1] ? 'match' : 'MISMATCH: ' . $m[1]
            );
        }
    }
}

report(
    ! isset($probe['headers']['x-powered-by']) || $probe['headers']['x-powered-by'] === '',
    'header x-powered-by is suppressed',
    $probe['headers']['x-powered-by'] ?? 'absent'
);

// ---------------------------------------------------------------------------

// 5. Redirect behaviour for a protected page

// ---------------------------------------------------------------------------



echo "\n  --- Panel access ----------------------------------------------------\n";



// Phase 3 pages. Once they exist an anonymous visit must be a redirect to the

// sign-in page, never a 200.

foreach (['/student/dashboard.php', '/admin/dashboard.php'] as $panelPath) {

    $response = fetch($baseUrl . $panelPath);

    $allowed  = [302, 303, 401, 403, 404];

    $inArray  = in_array($response['status'], $allowed, true);



    report(

        $inArray,

        'GET ' . $panelPath,

        'HTTP ' . $response['status'] . ($inArray ? '' : ' - must be a redirect or a refusal')

    );

}



// ---------------------------------------------------------------------------

// 6. Phase 3 pages, served by real Apache from the XAMPP subdirectory

// ---------------------------------------------------------------------------

//

// This is the check that the application really works at

// http://localhost/KantEase/ and not merely inside a harness that copied it

// somewhere else. The subdirectory routing, the .htaccess rules and the PHP

// handler all belong to Apache, and only Apache can prove them.

//

// It runs before the first installation, so no account exists. That is

// deliberate: every check here must hold on a brand-new canteen system and

// none of them may depend on a database being present.



echo "\n  --- Phase 3 pages under Apache -------------------------------------\n";



$index = fetch($baseUrl . '/');



report(

    $index['status'] === 302 && str_ends_with($index['location'], '/login.php'),

    'GET / redirects a guest to sign-in',

    'HTTP ' . $index['status'] . ($index['location'] !== '' ? ' -> ' . $index['location'] : '')

);



$loginPage = fetch($baseUrl . '/login.php');



report($loginPage['status'] === 200, 'GET /login.php renders under Apache', 'HTTP ' . $loginPage['status']);



$hasToken = preg_match('/name="_csrf" value="[a-f0-9]{64}"/', $loginPage['body']) === 1;



report($hasToken, 'the sign-in form carries a CSRF token in Apache output', $hasToken ? 'yes' : 'NO TOKEN');



// Every asset the page references must be a path inside this application.

$assetPaths = [];



if (preg_match_all('/(?:src|href)="([^"]+)"/i', $loginPage['body'], $m) > 0) {

    foreach ($m[1] as $reference) {

        $assetPaths[] = $reference;

    }

}



$external = array_values(array_filter(

    $assetPaths,

    static fn (string $r): bool => preg_match('#^(?:https?:)?//#i', $r) === 1

));



report(

    $external === [],

    'the sign-in page references no external asset',

    $external === []

        ? count($assetPaths) . ' local references'

        : 'EXTERNAL: ' . implode(', ', $external)

);



$registerPage = fetch($baseUrl . '/register.php');



report($registerPage['status'] === 200, 'GET /register.php renders under Apache', 'HTTP ' . $registerPage['status']);



$hasRoleField = preg_match('/name="role"/i', $registerPage['body']) === 1;



report(! $hasRoleField, 'registration exposes no role field', $hasRoleField ? 'A role FIELD EXISTS' : 'the role is fixed in code');



// A GET must not sign anyone out. Needs only a request, no session.

$logoutGet    = fetch($baseUrl . '/logout.php');
$basePath    = parse_url($baseUrl, PHP_URL_PATH) ?: '/';



report(

    // The redirect target does not have to be /login.php. logout.php sends a
    // guest to the landing page and index.php then sends them to sign-in, so
    // two legitimate redirects happen in a row. What matters is that the
    // redirect stays inside KantEase and no session was destroyed.
    $logoutGet['status'] === 302
        && $logoutGet['location'] !== ''
        && str_starts_with($logoutGet['location'], $basePath),

    'GET /logout.php refuses rather than signing out',

    'HTTP ' . $logoutGet['status'] . ($logoutGet['location'] !== '' ? ' -> ' . $logoutGet['location'] : '')

);



$coreJs = fetch($baseUrl . '/assets/js/core.js');



report(

    $coreJs['status'] === 200,

    'assets/js/core.js is served by Apache',

    'HTTP ' . $coreJs['status'] . ', ' . strlen($coreJs['body']) . ' bytes'

);



$authJs = fetch($baseUrl . '/assets/js/auth.js');



report(

    $authJs['status'] === 200,

    'assets/js/auth.js is served by Apache',

    'HTTP ' . $authJs['status'] . ', ' . strlen($authJs['body']) . ' bytes'

);

// ---------------------------------------------------------------------------
// Verdict
// ---------------------------------------------------------------------------

echo "\n" . str_repeat('=', 74) . "\n";
printf("  %d passed, %d failed\n", $passes, $failures);
echo "  Testing {$scheme}://{$host}" . "\n";

if ($problems !== []) {
    echo "\n  Problems:\n";
    foreach ($problems as $problem) {
        echo '    - ' . $problem . "\n";
    }

    echo "\n  RESULT: FAIL\n";
    echo "  If a sensitive path returned HTTP 200, check that AllowOverride is set\n";
    echo "  to All for htdocs in C:\\xampp\\apache\\conf\\httpd.conf, then restart Apache.\n\n";
    exit(1);
}

echo "\n  RESULT: PASS — nothing sensitive is reachable through a browser.\n\n";
exit(0);