<?php

declare(strict_types=1);

namespace KantEase;

/**
 * Cross-Site Request Forgery protection.
 *
 * The original build had no CSRF token at all. It was protected only by
 * SameSite=Strict on the session cookie plus the fact that its JSON endpoints
 * required a Content-Type a cross-site HTML form cannot send. That is a real
 * barrier, but it is a side effect of the request format rather than a
 * deliberate control, and it fails open for any endpoint that does not parse a
 * body. This class makes the protection explicit and auditable.
 *
 * Token handling:
 *   - One random 256-bit token per session, stored server-side only.
 *   - Rotated whenever the session identity changes (login, logout).
 *   - Compared with hash_equals() so a mismatch leaks no timing information.
 *   - Accepted from a hidden form field or the X-CSRF-Token header, which lets
 *     the same token serve both classic form posts and Fetch API calls.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';
    private const HEADER      = 'X-CSRF-Token';
    private const FIELD       = '_csrf';

    /**
     * The session's current token, generated on first use.
     */
    public static function token(): string
    {
        if (! isset($_SESSION) || ! is_array($_SESSION)) {
            throw new \RuntimeException('CSRF called before the session was started.');
        }

        $token = $_SESSION[self::SESSION_KEY] ?? null;

        if (! is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $token;
        }

        return $token;
    }

    /**
     * Discard the current token and issue a new one.
     *
     * Called on login and logout. Rotation matters because a token that
     * survived a privilege change would let a page loaded before sign-in
     * perform an action afterwards.
     */
    public static function rotate(): string
    {
        $token = bin2hex(random_bytes(32));

        if (isset($_SESSION) && is_array($_SESSION)) {
            $_SESSION[self::SESSION_KEY] = $token;
        }

        return $token;
    }

    /**
     * A ready-made hidden input for a form.
     *
     * Used as: <form ...><?= csrf_field() ?></form>
     */
    public static function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD,
            e(self::token())
        );
    }

    /**
     * Constant-time comparison of a candidate against the session token.
     */
    public static function check(mixed $candidate): bool
    {
        if (! is_string($candidate) || $candidate === '') {
            return false;
        }

        $token = self::token();

        return hash_equals($token, $candidate);
    }

    /**
     * The token supplied with this request, from either the form field or the
     * request header.
     */
    public static function fromRequest(): ?string
    {
        $fromField = $_POST[self::FIELD] ?? null;
        if (is_string($fromField) && $fromField !== '') {
            return $fromField;
        }

        // JSON bodies are not in $_POST, so the header is used instead.
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (is_string($header) && $header !== '') {
            return $header;
        }

        $payload = request_payload();
        $fromBody = $payload[self::FIELD] ?? null;

        return is_string($fromBody) && $fromBody !== '' ? $fromBody : null;
    }

    /**
     * Verify the current request or abort with a CsrfException.
     *
     * Only state-changing methods are checked. GET, HEAD and OPTIONS are
     * required by the HTTP specification to be safe, and rejecting a
     * perfectly safe prefetch would break the browser's back button.
     *
     * @throws CsrfException
     */
    public static function verifyRequest(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        if (! self::check(self::fromRequest())) {
            throw new CsrfException(
                'This form has expired. Please reload the page and try again.'
            );
        }
    }

    /**
     * The header name browsers should use when calling the JSON API.
     */
    public static function headerName(): string
    {
        return self::HEADER;
    }

    /**
     * The form field name.
     */
    public static function fieldName(): string
    {
        return self::FIELD;
    }
}