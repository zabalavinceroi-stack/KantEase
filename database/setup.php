<?php

declare(strict_types=1);

/**
 * KantEase one-time installer — the screen.
 *
 * WHAT THIS IS FOR
 *   Creates the database, applies database/database.sql, and creates the FIRST
 *   administrator account.
 *
 * WHY IT EXISTS
 *   The original Node.js application let any visitor create an administrator
 *   from the public sign-up page using a secret code hardcoded in the source
 *   ("canteen2026"), which was also committed to git. That was the single most
 *   serious security hole in the project.
 *
 *   Here there is no admin sign-up at all. The first administrator is created
 *   by this script, and every administrator after that is created by an
 *   existing administrator from Admin -> Accounts.
 *
 * PROTECTIONS
 *   1. Refuses to run from anywhere except localhost.
 *   2. Refuses to run once database/.installed exists.
 *   3. Refuses to create an administrator when one already exists.
 *   4. CSRF token on every step, like every other form.
 *
 * ALL THE LOGIC LIVES IN includes/installer.php. This file only decides what to
 * show, so the whole sequence can be tested by tests/verify-phase2.php without
 * a browser and without touching the real database or the real lock file.
 *
 * AFTER SUCCESSFUL INSTALLATION
 *   Delete this file. database/.installed also disables it, but a file that is
 *   not there cannot be reached at all.
 */

use KantEase\Config;
use KantEase\Csrf;
use KantEase\ErrorHandler;
use KantEase\Installer;
use KantEase\SchemaInstaller;
use KantEase\ValidationException;

use function KantEase\csp_nonce;
use function KantEase\e;
use function KantEase\send_page_headers;

const KANTEASE_ROOT = __DIR__ . '/..';

require_once KANTEASE_ROOT . '/includes/exceptions.php';

$configFile = KANTEASE_ROOT . '/includes/config.local.php';

if (! is_file($configFile)) {
    render_unconfigured();
}

require_once KANTEASE_ROOT . '/includes/bootstrap.php';

// ---------------------------------------------------------------------------
// Guard 1 — localhost only.
// ---------------------------------------------------------------------------

if (! Installer::isLocalRequest()) {
    http_response_code(403);

    render_page(
        'Not allowed',
        '<p class="lead">The KantEase installer only runs on the computer that hosts it.</p>'
        . '<p class="lead">You reached it from <strong>' . e((string) ($_SERVER['REMOTE_ADDR'] ?? ''))
        . '</strong>. Open it on the server machine instead:</p>'
        . '<p class="lead"><code>http://localhost/KantEase/database/setup.php</code></p>'
        . '<p class="lead muted">To install from another computer on the school network, delete '
        . '<code>database/.installed</code> and remove the localhost check from '
        . '<code>includes/installer.php</code> first.</p>'
    );
}

// ---------------------------------------------------------------------------
// Guard 2 — the installer disables itself once used.
// ---------------------------------------------------------------------------

if (Installer::isLocked()) {
    http_response_code(403);

    render_page(
        'Already installed',
        '<p class="lead">KantEase has already been installed, so this installer is now disabled.</p>'
        . '<p class="lead">To install again from scratch, delete <code>database/.installed</code>.</p>'
        . '<p class="actions"><a class="btn" href="' . e(Config::basePath() . '/login.php') . '">'
        . 'Go to the sign-in page</a></p>'
    );
}

// ---------------------------------------------------------------------------
// Where are we?
// ---------------------------------------------------------------------------

$step    = Installer::STATE_UNKNOWN;
$errors  = [];
$notices = [];

$state = ['state' => Installer::STATE_UNKNOWN, 'missing' => [], 'detail' => ''];

try {
    $state = Installer::evaluate();
    $step  = $state['state'];
} catch (Throwable $exception) {
    ErrorHandler::log($exception);

    $errors[] = $exception instanceof KantEase\KantEaseException
        ? $exception->getMessage()
        : 'KantEase could not connect to MySQL. Check that it is running and that '
          . 'includes/config.local.php is correct.';

    $step = Installer::STATE_UNKNOWN;
}

// ---------------------------------------------------------------------------
// Handle a submitted step.
// ---------------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Csrf::verifyRequest();

        switch ((string) ($_POST['action'] ?? '')) {
            case 'create_database':
                Installer::createDatabase();
                $notices[] = 'Database created.';
                redirect_to_self();
                // no break — redirect_to_self() never returns

            case 'import_schema':
                $statements = Installer::installSchema();
                $notices[]  = sprintf('Schema installed (%d statements).', $statements);
                redirect_to_self();
                // no break

            case 'create_admin':
                $created = Installer::createFirstAdministrator(
                    (string) ($_POST['full_name'] ?? ''),
                    (string) ($_POST['email'] ?? ''),
                    (string) ($_POST['password'] ?? ''),
                    (string) ($_POST['confirm_password'] ?? '')
                );

                $notices[] = sprintf('Administrator created with User ID %s.', (string) $created['user_code']);
                $step      = Installer::STATE_DONE;
                break;

            default:
                $errors[] = 'That action is not recognised.';
        }
    } catch (ValidationException $exception) {
        $errors = array_merge($errors, array_values($exception->errors()));
    } catch (Throwable $exception) {
        ErrorHandler::log($exception);

        // A deliberate RuntimeException carries its own readable message (the
        // installer refusing an out-of-order step); anything else is a bug and
        // gets a generic line.
        $errors[] = $exception instanceof RuntimeException && $exception->getMessage() !== ''
            ? $exception->getMessage()
            : 'The installer could not complete that step. The details are in the PHP error log.';
    }
}

// ---------------------------------------------------------------------------
// Output.
// ---------------------------------------------------------------------------

$body = '';

foreach ($notices as $notice) {
    $body .= '<p class="alert alert--ok">' . e($notice) . '</p>';
}

foreach ($errors as $error) {
    $body .= '<p class="alert alert--bad">' . e($error) . '</p>';
}

switch ($step) {
    case Installer::STATE_UNCONFIGURED:
        $body .= '<p class="lead">KantEase is not configured yet, so it cannot install anything.</p>'
            . '<p class="lead muted">Copy <code>includes/config.sample.php</code> to '
            . '<code>includes/config.local.php</code>, enter your MySQL credentials there, then reload this page.</p>'
            . '<p class="actions"><a class="btn" href="">Reload</a></p>';
        break;

    case Installer::STATE_NO_DATABASE:
        $body .= '<h2>Step 1 of 3 — Create the database</h2>'
            . '<p class="lead">KantEase is configured to use <code>' . e(Config::string('db.name'))
            . '</code>, but that database does not exist yet.</p>'
            . '<form method="post" class="form">'
            .   Csrf::field()
            .   '<input type="hidden" name="action" value="create_database">'
            .   '<button class="btn" type="submit">Create the database</button>'
            . '</form>'
            . '<p class="lead muted">This runs <code>CREATE DATABASE IF NOT EXISTS</code>. '
            . 'Nothing existing is touched.</p>';
        break;

    case Installer::STATE_NO_SCHEMA:
        $missing = SchemaInstaller::missingTables();

        $body .= '<h2>Step 2 of 3 — Install the tables</h2>'
            . '<p class="lead">' . e($state['detail']) . '</p>'
            . ($missing === []
                ? ''
                : '<p class="lead muted">Missing: <code>' . e(implode(', ', $missing)) . '</code></p>')
            . '<form method="post" class="form">'
            .   Csrf::field()
            .   '<input type="hidden" name="action" value="import_schema">'
            .   '<button class="btn" type="submit">Install database.sql</button>'
            . '</form>'
            . '<p class="alert alert--warn"><strong>Warning:</strong> database.sql begins by dropping the '
            . 'KantEase tables. Only continue if this database is empty, or holds nothing you need.</p>';
        break;

    case Installer::STATE_NEEDS_ADMIN:
        $body .= '<h2>Step 3 of 3 — Create the first administrator</h2>'
            . '<p class="lead">The tables are installed. This account will run the canteen.</p>'
            . '<p class="lead muted">This is the only account that can be created here. Every later '
            . 'administrator is created from Admin &rarr; Accounts once you are signed in.</p>'
            . render_admin_form();
        break;

    case Installer::STATE_UNKNOWN:
    default:
        $body .= '<p class="lead">KantEase could not work out where it is in the setup.</p>'
            . '<p class="lead muted">This usually means MySQL is not running, or the credentials in '
            . '<code>includes/config.local.php</code> are wrong. Check the XAMPP Control Panel, then reload.</p>'
            . '<p class="actions"><a class="btn" href="">Try again</a></p>';
        break;

    case Installer::STATE_DONE:
        $body .= '<p class="alert alert--ok">KantEase is installed.</p>'
            . '<h2>Last step — close the installer</h2>'
            . '<ol class="steps">'
            . '<li>Delete <code>database/setup.php</code>. It is not needed any more.</li>'
            . '<li>Sign in at <code>' . e(Config::basePath() . '/login.php') . '</code> and change the '
            . 'administrator password from your profile page.</li>'
            . '<li>Register your students and add your menu.</li>'
            . '</ol>'
            . '<p class="actions"><a class="btn" href="' . e(Config::basePath() . '/login.php') . '">'
            . 'Go to the sign-in page</a></p>'
            . '<p class="lead muted">The file <code>database/.installed</code> has been written, so even if '
            . 'you forget to delete setup.php it will refuse to run again.</p>';
        break;
}

render_page('KantEase setup', $body);

// ===========================================================================
// Functions
// ===========================================================================

/**
 * The first-administrator form, re-populated after a failed attempt.
 */
function render_admin_form(): string
{
    $name  = e((string) ($_POST['full_name'] ?? ''));
    $email = e((string) ($_POST['email'] ?? ''));
    $csrf  = Csrf::field();

    return <<<HTML
        <form method="post" class="form" autocomplete="off">
            {$csrf}
            <input type="hidden" name="action" value="create_admin">

            <label for="full_name">Full name</label>
            <input id="full_name" name="full_name" value="{$name}" maxlength="120" required>

            <label for="email">Email address</label>
            <input id="email" name="email" type="email" value="{$email}" maxlength="254" required>

            <label for="password">Password</label>
            <input id="password" name="password" type="password" minlength="8" maxlength="128" required>
            <p class="hint">At least 8 characters. This account runs the canteen.</p>

            <label for="confirm">Confirm password</label>
            <input id="confirm" name="confirm_password" type="password" minlength="8" maxlength="128" required>

            <button class="btn" type="submit">Create administrator</button>
        </form>
    HTML;
}

/**
 * Reload, so a freshly created database or schema is picked up.
 */
function redirect_to_self(): never
{
    $target = (string) ($_SERVER['REQUEST_URI'] ?? '/database/setup.php');
    $path   = (string) (parse_url($target, PHP_URL_PATH) ?: '');

    // Only ever redirect back to this script's own path.
    if ($path === '' || ! str_ends_with($path, '/setup.php')) {
        $path = '/database/setup.php';
    }

    header('Location: ' . $path, true, 303);
    exit;
}

/**
 * Shown before includes/config.local.php exists.
 */
function render_unconfigured(): never
{
    render_page(
        'KantEase needs configuration',
        '<h2>One more step before you start</h2>'
        . '<p class="lead">KantEase needs to know how to reach your local MySQL database. '
        . 'No password is stored in the project files &mdash; you supply it here.</p>'
        . '<ol class="steps">'
        . '<li>Copy <code>includes/config.sample.php</code> to <code>includes/config.local.php</code>.</li>'
        . '<li>Open <code>includes/config.local.php</code> in a text editor.</li>'
        . '<li>Set <code>db.user</code> and <code>db.password</code> to your MySQL account. '
        . 'For a default XAMPP install that is user <code>root</code> with an empty password.</li>'
        . '<li>Save the file, then reload this page.</li>'
        . '</ol>'
        . '<p class="lead muted"><code>config.local.php</code> is listed in <code>.gitignore</code> and '
        . 'blocked by <code>includes/.htaccess</code>, so your password never leaves this machine.</p>'
    );
}

/**
 * The installer's self-contained page.
 *
 * It carries its own stylesheet so it works before any of the application's
 * assets exist, and the nonce keeps that stylesheet legal under the same
 * Content-Security-Policy every other page uses.
 *
 * send_page_headers() MUST be called before this. It calls csp_nonce(), which
 * memoises one value per request, so the nonce in the header and the nonce on
 * the <style> element below are guaranteed to be the same string.
 *
 * Without that call the installer emitted a nonce nobody enforced: no CSP
 * header was ever sent, the inline stylesheet was unprotected, and the one
 * page reachable before installation had no policy at all. The web suite
 * found this by checking headers on a real PHP response rather than on an
 * Apache error page.
 */
function render_page(string $title, string $body): never
{
    send_page_headers();

    $nonce     = csp_nonce();
    $safeTitle = e($title);

    echo <<<HTML
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{$safeTitle} — KantEase</title>
        <style nonce="{$nonce}">
            *, *::before, *::after { box-sizing: border-box; }
            body {
                margin: 0;
                min-height: 100vh;
                padding: 40px 20px;
                background: #f4f8fb;
                color: #14304c;
                font: 16px/1.6 "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            }
            main { width: min(680px, 100%); margin: 0 auto; }
            .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 24px; font-size: 20px; font-weight: 700; color: #ff8a3d; }
            .mark { display: grid; place-items: center; width: 38px; height: 38px; border-radius: 10px; background: #ff8a3d; color: #332012; }
            .card { padding: 32px; border: 1px solid #d9e3ed; border-radius: 14px; background: #fff; box-shadow: 0 10px 30px rgba(20,48,76,.10); }
            h1 { margin: 0 0 8px; font-size: 26px; }
            h2 { margin: 24px 0 12px; font-size: 19px; }
            .lead { margin: 0 0 14px; color: #4a647d; }
            .muted { color: #7b91a7; font-size: 14px; }
            code { padding: 2px 6px; border-radius: 5px; background: #eef4f9; font-size: 13px; word-break: break-all; }
            .alert { margin: 0 0 16px; padding: 12px 14px; border-radius: 9px; font-size: 14px; }
            .alert--ok { background: #e2f4e9; color: #12603c; }
            .alert--bad { background: #fdeceb; color: #97231c; }
            .alert--warn { background: #fff3d6; color: #8a5d00; }
            .form { display: grid; gap: 6px; margin-top: 18px; }
            label { margin-top: 10px; font-weight: 600; font-size: 14px; }
            input { width: 100%; padding: 11px 12px; border: 1px solid #b9cbdc; border-radius: 8px; background: #fff; color: #14304c; font: inherit; }
            input:focus { outline: 2px solid #ff8a3d; border-color: #ff8a3d; }
            .hint { margin: 2px 0 0; color: #7b91a7; font-size: 13px; }
            .btn {
                display: inline-block;
                margin-top: 18px;
                padding: 12px 20px;
                border: 0;
                border-radius: 9px;
                background: #ff8a3d;
                color: #332012;
                font: inherit;
                font-weight: 700;
                cursor: pointer;
                text-decoration: none;
            }
            .btn:hover { background: #f07a2c; }
            .actions { margin-top: 8px; }
            .steps { margin: 12px 0 0; padding-left: 22px; color: #4a647d; }
            .steps li { margin-bottom: 10px; }
            footer { margin-top: 22px; color: #7b91a7; font-size: 13px; text-align: center; }
        </style>
    </head>
    <body>
        <main>
            <div class="brand"><span class="mark">K</span><span>KantEase setup</span></div>
            <div class="card">
                <h1>{$safeTitle}</h1>
                {$body}
            </div>
            <footer>KantEase runs entirely on this machine. No internet connection is required.</footer>
        </main>
    </body>
    </html>
    HTML;

    exit;
}