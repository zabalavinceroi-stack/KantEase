<?php

declare(strict_types=1);

namespace KantEase;

use Throwable;

/**
 * Turns exceptions into safe, readable pages.
 *
 * The rule: a KantEaseException was written to be read by a human, so its
 * message is shown. Anything else is a bug, so only a generic sentence is
 * shown and the real detail goes to the PHP error log where an administrator
 * can find it.
 */
final class ErrorHandler
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        set_exception_handler([self::class, 'handle']);
    }

    public static function handle(Throwable $exception): void
    {
        self::render($exception);
    }

    /**
     * Render any throwable as an error page and stop.
     */
    public static function render(Throwable $exception, ?int $status = null): never
    {
        if (! headers_sent()) {
            http_response_code($status ?? self::statusFor($exception));
        }

        $isApi = self::wantsJson();

        if ($isApi) {
            if (! headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
            }

            $payload = ['ok' => false, 'error' => self::publicMessage($exception)];

            if (Config::isDevelopment()) {
                $payload['debug'] = self::debugDetails($exception);
            }

            echo json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
            );

            exit;
        }

        self::renderHtml($exception);
        exit;
    }

    /**
     * Write the real problem to the PHP error log.
     */
    public static function log(Throwable $exception): void
    {
        $context = $exception instanceof KantEaseException ? $exception->context() : [];

        $line = sprintf(
            "[%s] KantEase %s: %s in %s:%d%s",
            gmdate('Y-m-d H:i:s') . ' UTC',
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $context === [] ? '' : ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES)
        );

        error_log($line);
    }

    private static function statusFor(Throwable $exception): int
    {
        return $exception instanceof KantEaseException ? $exception->statusCode() : 500;
    }

    /**
     * The text safe to put in front of a user.
     */
    public static function publicMessage(Throwable $exception): string
    {
        if ($exception instanceof KantEaseException) {
            return $exception->getMessage();
        }

        if ($exception instanceof \PDOException) {
            return 'KantEase could not reach the database. Please tell the canteen staff.';
        }

        return 'Something went wrong on our side. Please try again, and tell the canteen staff if it keeps happening.';
    }

    /**
     * A title for the error page.
     */
    private static function titleFor(Throwable $exception): string
    {
        return $exception instanceof KantEaseException
            ? $exception->title()
            : 'Something went wrong';
    }

    /**
     * @return array<string, mixed>
     */
    private static function debugDetails(Throwable $exception): array
    {
        $details = [
            'type'  => $exception::class,
            'where' => $exception->getFile() . ':' . $exception->getLine(),
        ];

        if ($exception instanceof KantEaseException) {
            $details['context'] = $exception->context();
        }

        return $details;
    }

    /**
     * Detect a JSON client: an explicit Accept header, or any Fetch call.
     */
    private static function wantsJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));

        if (str_contains($accept, 'application/json')) {
            return true;
        }

        if (str_contains(strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')), 'xmlhttprequest')) {
            return true;
        }

        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

        return str_contains($contentType, 'application/json');
    }

    /**
     * A minimal, self-contained error page.
     *
     * It carries its own inline <style>, which the Content-Security-Policy
     * allows through the same per-request nonce as every other page.
     */
    private static function renderHtml(Throwable $exception): void
    {
        self::log($exception);

        $status    = self::statusFor($exception);
        $title     = self::titleFor($exception);
        $message   = self::publicMessage($exception);
        $showDebug = Config::isDevelopment();
        $debug     = $showDebug ? self::debugDetails($exception) : [];
        $loginUrl  = e(url('/login.php'));
        $homeUrl   = e(url('/'));
        $nonce     = csp_nonce();

        if (! headers_sent()) {
            send_page_headers();
        }

        echo <<<HTML
        <!doctype html>
        <html lang="en" data-theme="light">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <title>{$title} — KantEase</title>
            <style nonce="{$nonce}">
                *, *::before, *::after { box-sizing: border-box; }
                body {
                    margin: 0;
                    min-height: 100vh;
                    display: grid;
                    place-items: center;
                    padding: 24px;
                    background: #f4f8fb;
                    color: #16324f;
                    font: 16px/1.55 "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                }
                .card {
                    width: min(560px, 100%);
                    padding: 32px;
                    border: 1px solid #d7e6f2;
                    border-radius: 14px;
                    background: #ffffff;
                    box-shadow: 0 10px 30px rgba(22, 50, 79, .10);
                }
                .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; color: #ff8a3d; font-weight: 700; }
                .mark { display: grid; place-items: center; width: 36px; height: 36px; border-radius: 10px; background: #ff8a3d; color: #332012; }
                h1 { margin: 0 0 10px; font-size: 22px; }
                p { margin: 0 0 20px; color: #526b82; }
                .status { display: inline-block; margin-bottom: 14px; padding: 4px 10px; border-radius: 999px; background: #ffe4d1; color: #8a3500; font-size: 12px; font-weight: 700; }
                .actions { display: flex; flex-wrap: wrap; gap: 10px; }
                a.btn {
                    display: inline-block;
                    padding: 11px 18px;
                    border-radius: 9px;
                    background: #ff8a3d;
                    color: #332012;
                    font-weight: 700;
                    text-decoration: none;
                }
                a.btn--ghost { border: 1px solid #d7e6f2; background: #fff; color: #16324f; }
                pre {
                    margin: 20px 0 0;
                    padding: 14px;
                    overflow-x: auto;
                    border-radius: 9px;
                    background: #0d1b2a;
                    color: #d7e6f2;
                    font-size: 13px;
                    white-space: pre-wrap;
                    word-break: break-word;
                }
            </style>
        </head>
        <body>
            <main class="card">
                <div class="brand"><span class="mark">K</span><span>KantEase</span></div>
                <span class="status">Error {$status}</span>
                <h1>{$title}</h1>
                <p>{$message}</p>
                <div class="actions">
                    <a class="btn" href="{$homeUrl}">Back to start</a>
                    <a class="btn btn--ghost" href="{$loginUrl}">Sign in</a>
                </div>
        HTML;

        if ($showDebug) {
            echo '<pre>' . e(json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
        }

        echo <<<HTML
            </main>
        </body>
        </html>
        HTML;
    }
}