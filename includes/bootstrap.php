<?php

declare(strict_types=1);

/**
 * KantEase application bootstrap.
 *
 * Every entry point — pages, API endpoints and the installer — starts here.
 * Requiring this file is the only thing an entry point needs to do before it
 * can use the database, sessions, CSRF protection or any repository.
 *
 *     require_once __DIR__ . '/includes/bootstrap.php';
 */

if (defined('KANTEASE_BOOTSTRAPPED')) {
    return;
}

define('KANTEASE_BOOTSTRAPPED', true);
define('KANTEASE_ROOT', dirname(__DIR__));

// ---------------------------------------------------------------------------
// Error reporting.
//
// Anything that is NOT a KantEaseException is treated as a bug: the message
// goes to the PHP error log and the visitor sees a generic page. A student
// must never be shown a stack trace, a file path or a SQL fragment.
// ---------------------------------------------------------------------------

require_once KANTEASE_ROOT . '/includes/exceptions.php';
require_once KANTEASE_ROOT . '/includes/config.php';

try {
    \KantEase\Config::load();
} catch (\KantEase\ConfigurationException $exception) {
    // Config could not be read, so nothing else in this file can run — not
    // even the error handler, which needs configuration to render a page.
    // Emit a self-contained explanation and stop.
    http_response_code(500);
    $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $root    = htmlspecialchars(KANTEASE_ROOT, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo <<<HTML
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>KantEase needs configuration</title>
        <style>
            body { margin:0; min-height:100vh; display:grid; place-items:center; padding:24px;
                   background:#f4f8fb; color:#16324f;
                   font:16px/1.6 "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
            .card { width:min(620px,100%); padding:32px; border:1px solid #d7e6f2; border-radius:14px;
                    background:#fff; box-shadow:0 10px 30px rgba(22,50,79,.10); }
            h1 { margin:0 0 12px; font-size:24px; }
            code { padding:2px 6px; border-radius:5px; background:#eef4f9; font-size:13px; word-break:break-all; }
            li { margin-bottom:8px; }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>KantEase needs to be configured</h1>
            <p>{$message}</p>
            <ol>
                <li>Copy <code>{$root}/includes/config.sample.php</code> to
                    <code>{$root}/includes/config.local.php</code></li>
                <li>Set <code>db.user</code> and <code>db.password</code> in the copy</li>
                <li>Reload this page</li>
            </ol>
        </div>
    </body>
    </html>
    HTML;

    exit;
}

\KantEase\Config::isDevelopment()
    ? error_reporting(E_ALL)
    : error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

ini_set('display_errors', \KantEase\Config::isDevelopment() ? '1' : '0');
ini_set('log_errors', '1');

// The CLI verification suite runs in-process, so an uncaught error would
// otherwise print the styled HTML error page straight into the middle of the
// test output and the run would look like it simply stopped. This is gated
// behind an environment variable the suite sets, so a browser request can never
// reach it and no internal detail is ever exposed to a student.
if (\PHP_SAPI === 'cli' && getenv('KANTEASE_VERIFY') === '1') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);

    set_exception_handler(static function (Throwable $exception): void {
        fwrite(STDERR, PHP_EOL . 'UNCAUGHT ' . $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
        fwrite(STDERR, '  at ' . $exception->getFile() . ':' . $exception->getLine() . PHP_EOL);
        fwrite(STDERR, '  ' . str_replace("\n", "\n  ", $exception->getTraceAsString()) . PHP_EOL);
        exit(1);
    });
}

// While developing it is easier to read a trace than to tail a log file.
if (\KantEase\Config::isDevelopment() && PHP_SAPI !== 'cli') {
    ini_set('display_startup_errors', '1');
}

date_default_timezone_set(\KantEase\Config::string('app.timezone', 'UTC'));

// ---------------------------------------------------------------------------
// Core classes.
// ---------------------------------------------------------------------------

require_once KANTEASE_ROOT . '/includes/enums.php';
require_once KANTEASE_ROOT . '/includes/database.php';
require_once KANTEASE_ROOT . '/includes/functions.php';
require_once KANTEASE_ROOT . '/includes/pagination.php';
require_once KANTEASE_ROOT . '/includes/search.php';
require_once KANTEASE_ROOT . '/includes/csrf.php';
require_once KANTEASE_ROOT . '/includes/passwords.php';
require_once KANTEASE_ROOT . '/includes/validation.php';
require_once KANTEASE_ROOT . '/includes/error_handler.php';
require_once KANTEASE_ROOT . '/includes/schema_installer.php';
require_once KANTEASE_ROOT . '/includes/installer.php';
require_once KANTEASE_ROOT . '/includes/auth.php';
require_once KANTEASE_ROOT . '/includes/routing.php';

// ---------------------------------------------------------------------------
// Repositories. Every SQL statement in KantEase lives under this directory;
// no page or API file contains a query.
// ---------------------------------------------------------------------------

require_once KANTEASE_ROOT . '/includes/repositories/AuditRepository.php';
require_once KANTEASE_ROOT . '/includes/repositories/LoginThrottle.php';
require_once KANTEASE_ROOT . '/includes/repositories/CategoryRepository.php';
require_once KANTEASE_ROOT . '/includes/repositories/FoodRepository.php';
require_once KANTEASE_ROOT . '/includes/repositories/OrderRepository.php';
require_once KANTEASE_ROOT . '/includes/repositories/SalesRepository.php';
require_once KANTEASE_ROOT . '/includes/repositories/UserRepository.php';

// ---------------------------------------------------------------------------
// Presentation. One place that knows how the two page shells are built.
// ---------------------------------------------------------------------------

require_once KANTEASE_ROOT . '/includes/layout/Layout.php';

// ---------------------------------------------------------------------------
// Error handling.
//
// The CLI verification suite installs its own handler (above) so an uncaught
// error prints a readable trace rather than a styled HTML page in the middle
// of the test output. Registering ErrorHandler afterwards would overwrite it.
// ---------------------------------------------------------------------------

if (! (\PHP_SAPI === 'cli' && getenv('KANTEASE_VERIFY') === '1')) {
    \KantEase\ErrorHandler::register();
}

// ---------------------------------------------------------------------------
// Session.
//
// The installer runs before any account exists and must not start a session,
// so start-up is opt-out for that one script.
// ---------------------------------------------------------------------------

if (\PHP_SAPI !== 'cli' && \KantEase\Config::bool('app.start_session', true)) {
    \KantEase\Auth::startSession();
}