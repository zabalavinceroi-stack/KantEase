<?php

declare(strict_types=1);

namespace KantEase;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Shared helpers: escaping, money, URLs, redirects, flash messages, CSV.
 *
 * This is the ONLY place HTML escaping happens. Every dynamic value printed
 * into a page goes through e(), which is what keeps the original build's
 * "textContent everywhere" safety property once the markup is server-rendered.
 */

// ---------------------------------------------------------------------------
// Output escaping
// ---------------------------------------------------------------------------

/**
 * Escape a value for HTML text and quoted attributes.
 *
 * ENT_QUOTES covers both quote styles, ENT_SUBSTITUTE stops invalid UTF-8
 * from producing an empty string, and ENT_HTML5 keeps the output valid under
 * the HTML5 doctype the pages use.
 *
 * @param mixed $value
 */
function e(mixed $value): string
{
    if ($value === null || is_bool($value)) {
        return '';
    }

    if (is_array($value)) {
        $value = implode(', ', array_map(
            static fn (mixed $item): string => is_scalar($item) ? (string) $item : '',
            $value
        ));
    }

    if (! is_scalar($value) && ! $value instanceof \Stringable) {
        return '';
    }

    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/**
 * Escape a value for embedding inside a JavaScript string or a data-* attribute.
 *
 * Used only where a value genuinely must reach JavaScript, such as a chart
 * data point. Everything else should be rendered as HTML instead.
 */
function ejs(mixed $value): string
{
    return json_encode(
        $value,
        JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    );
}

// ---------------------------------------------------------------------------
// Money
//
// Every peso amount in KantEase is handled as an integer number of centavos
// during calculation. DECIMAL(10,2) values arrive from MySQL as strings and
// are parsed digit by digit, so no binary floating point error can ever enter
// a total. This is the same discipline the original Node.js build used with
// Math.round(price * 100) and it is preserved exactly.
// ---------------------------------------------------------------------------

/**
 * Convert a pesos amount to integer centavos without float rounding.
 *
 * @throws \InvalidArgumentException when the value is not a decimal number.
 */
function to_cents(int|float|string $amount): int
{
    if (is_int($amount)) {
        return $amount * 100;
    }

    if (is_float($amount)) {
        // Render the float as a plain decimal string first so the digit
        // parser below, not IEEE-754, decides the result.
        $text = rtrim(rtrim(number_format($amount, 6, '.', ''), '0'), '.');
        if ($text === '' || $text === '-') {
            $text = '0';
        }
    } else {
        $text = trim($amount);
    }

    if (preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $text, $matches) !== 1) {
        throw new \InvalidArgumentException(sprintf('"%s" is not a valid amount.', $amount));
    }

    $sign    = $matches[1] === '-' ? -1 : 1;
    $whole   = $matches[2] === '' ? '0' : $matches[2];
    $fraction = $matches[3] ?? '';

    // Anything past two decimal places is truncated, matching DECIMAL(10,2).
    $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);

    $cents = ((int) $whole * 100) + (int) $fraction;

    return $sign * $cents;
}

/** Format integer centavos back into a DECIMAL(10,2)-safe string. */
function cents_to_amount(int $cents): string
{
    $sign  = $cents < 0 ? '-' : '';
    $cents = abs($cents);

    return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
}

/**
 * Render an amount for display: ₱1,234.56
 *
 * @param int|float|string|null $amount pesos, or integer centavos when
 *                                        $alreadyInCents is true
 */
function peso(int|float|string|null $amount = null, bool $alreadyInCents = false): string
{
    if ($amount === null) {
        return '₱0.00';
    }

    $cents = $alreadyInCents ? (int) $amount : to_cents($amount);
    $text  = cents_to_amount($cents);

    [$whole, $fraction] = explode('.', $text, 2);

    return '₱' . number_format((float) $whole, 0, '.', ',') . '.' . $fraction;
}

// ---------------------------------------------------------------------------
// Dates
// ---------------------------------------------------------------------------

function appTimezone(): DateTimeZone
{
    static $timezone = null;

    if ($timezone instanceof DateTimeZone) {
        return $timezone;
    }

    try {
        $timezone = new DateTimeZone(Config::string('app.timezone', 'UTC'));
    } catch (Throwable) {
        $timezone = new DateTimeZone('UTC');
    }

    return $timezone;
}

/**
 * Format a DATETIME from the database for display.
 *
 * Times are stored and compared in UTC; only the moment of formatting applies
 * the canteen's timezone.
 */
function format_datetime(?string $value, string $format = 'M j, Y g:i A'): string
{
    $date = parse_datetime($value);

    return $date?->format($format) ?? '—';
}

function format_date(?string $value, string $format = 'M j, Y'): string
{
    $date = parse_datetime($value);

    return $date?->format($format) ?? '—';
}

/** Interpret a database DATETIME as an instant, or null if absent/unparseable. */
function parse_datetime(?string $value): ?DateTimeImmutable
{
    if ($value === null || trim($value) === '' || str_starts_with($value, '0000-00-00')) {
        return null;
    }

    try {
        return (new DateTimeImmutable($value, new \DateTimeZone('UTC')))
            ->setTimezone(appTimezone());
    } catch (Throwable) {
        return null;
    }
}

/** Current instant, in the database's UTC storage zone. */
function utc_now(): string
{
    return (new DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

function today_utc(): string
{
    return (new DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
}

// ---------------------------------------------------------------------------
// URLs, redirects and requests
// ---------------------------------------------------------------------------

/** Build an application URL from a root-relative path. */
function url(string $path = '/'): string
{
    $base = Config::basePath();
    if ($path === '' || $path === '/') {
        return $base === '' ? '/' : $base . '/';
    }

    return $base . '/' . ltrim($path, '/');
}

/** Path only, for building hrefs and form actions. */
function path_to(string $path): string
{
    return url($path);
}

/**
 * Send the browser somewhere else and stop.
 *
 * The destination is always constrained to this application. An attacker
 * cannot use a ?next=https://evil.example link to turn KantEase's login page
 * into an open redirect.
 *
 * @throws \KantEase\ValidationException never; it either redirects or fails
 */
function redirect(string $destination): never
{
    $safe = safe_path($destination);
    $base = Config::basePath();

    // Prefix app-relative destinations with the configured base path.
    // Avoid adding the prefix twice.
    if ($base !== '' && $safe !== $base && !str_starts_with($safe, $base . '/')) {
        $safe = $base . $safe;
    }

    if (!headers_sent()) {
        // A redirect is still an HTTP response, and whatever receives it can
        // still act on the headers it carries. index.php and logout.php
        // redirect before any Layout output exists, so those responses were
        // going out with no Content-Security-Policy, no X-Content-Type-Options
        // and no X-Frame-Options at all.
        //
        // The Phase 3 suite surfaced this while hunting for a page to measure
        // headers on: every candidate it could reach was a redirect or an error,
        // so nothing proved the headers were ever sent.
        send_page_headers();
    }

    if (!headers_sent()) {
        header('Location: ' . $safe, true, 302);
    }

    exit;
}

/**
 * Reduce a caller-supplied destination to a safe in-app path.
 *
 * Anything absolute, protocol-relative or containing a backslash is discarded
 * in favour of the dashboard.
 */
function safe_path(mixed $candidate, string $fallback = '/'): string
{
    if (! is_string($candidate)) {
        return $fallback;
    }

    $candidate = trim($candidate);
    if ($candidate === '') {
        return $fallback;
    }

    // Reject scheme-relative ("//evil.com") and absolute ("https://evil.com").
    if (str_starts_with($candidate, '//') || preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $candidate) === 1) {
        return $fallback;
    }

    // Backslashes are normalised to "/" by some browsers, so "//" can appear
    // after normalisation even when the raw value looked fine.
    if (str_contains(str_replace('\\', '/', $candidate), '//')) {
        return $fallback;
    }

    if (! str_starts_with($candidate, '/')) {
        return $fallback;
    }

    return $candidate;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_ajax(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

/**
 * Merged request payload: JSON body when present, otherwise form fields.
 *
 * @return array<string, mixed>
 */
function request_payload(): array
{
    static $payload = null;

    if (is_array($payload)) {
        return $payload;
    }

    $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');

    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');

        if ($raw === false || trim($raw) === '') {
            return $payload = [];
        }

        if (strlen($raw) > 262_144) {
            throw new ValidationException(
                ['body' => 'The submitted data is too large.'],
                'The submitted data is too large.'
            );
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException(
                ['body' => 'The submitted data could not be read. Please try again.'],
                'The submitted data could not be read. Please try again.'
            );
        }

        return $payload = is_array($decoded) ? $decoded : [];
    }

    return $payload = $_POST;
}

/**
 * Read one value from the request payload.
 *
 * Arrays are never accepted for a scalar field: accepting them would let a
 * crafted request slip an array into a function typed for a string and turn a
 * validation message into a type error.
 */
function input(string $key, mixed $default = null): mixed
{
    $payload = request_payload();

    return array_key_exists($key, $payload) ? $payload[$key] : $default;
}

function input_string(string $key, string $default = ''): string
{
    $value = input($key, $default);

    if (is_string($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return $default;
}

function input_int(string $key, int $default = 0): int
{
    $value = input($key, $default);

    return is_numeric($value) ? (int) $value : $default;
}

function input_array(string $key): array
{
    $value = input($key, []);

    return is_array($value) ? $value : [];
}

/** Read a query-string parameter as a trimmed string. */
function query_string(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;

    if (is_string($value)) {
        return trim($value);
    }

    return $default;
}

function query_int(string $key, int $default = 0): int
{
    $value = $_GET[$key] ?? $default;

    return is_string($value) && is_numeric($value) ? (int) $value : $default;
}

/**
 * Best-effort client address, for the login-attempt log only.
 *
 * A school LAN may sit behind a router, so the forwarded header is preferred
 * when present. The value is length-capped and validated before storage so a
 * hostile header cannot inject anything.
 */
function client_ip(): string
{
    $candidate = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

    if (! is_string($candidate)) {
        return '0.0.0.0';
    }

    $first = trim(explode(',', $candidate, 2)[0]);

    return filter_var($first, FILTER_VALIDATE_IP) !== false ? $first : '0.0.0.0';
}

/** Truncate for an HTTP header so user input can never inject one. */
function header_safe(string $value, int $maxLength = 100): string
{
    $clean = preg_replace('/[\r\n\t\0]/', ' ', $value) ?? '';

    return mb_substr($clean, 0, $maxLength);
}

// ---------------------------------------------------------------------------
// Flash messages and old form input
// ---------------------------------------------------------------------------

function flash_set(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * @return list<array{type: string, message: string}>
 */
function flash_take(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);

    if (! is_array($messages)) {
        return [];
    }

    $clean = [];
    foreach ($messages as $message) {
        if (is_array($message) && isset($message['type'], $message['message'])) {
            $clean[] = [
                'type'    => in_array($message['type'], ['success', 'error', 'info', 'warning'], true)
                    ? $message['type']
                    : 'info',
                'message' => (string) $message['message'],
            ];
        }
    }

    return $clean;
}

function remember_old_input(array $values): void
{
    unset($values['_csrf'], $values['password'], $values['current_password'],
        $values['new_password'], $values['confirm_password'], $values['admin_code']);

    $_SESSION['_old'] = $values;
}

/** Previously submitted value for repopulating a form after a failed post. */
function old(string $key, mixed $default = ''): mixed
{
    $old = $_SESSION['_old'] ?? [];

    if (! is_array($old)) {
        return $default;
    }

    return array_key_exists($key, $old) ? $old[$key] : $default;
}

function forget_old_input(): void
{
    unset($_SESSION['_old']);
}

/**
 * Per-field validation messages, carried across one redirect.
 *
 * A form that fails validation is re-rendered after a redirect, so its errors
 * have to survive that hop. They are stored under the same field names that
 * Validator::errors() returns, which is why Layout::field() can accept the
 * validator's own output with no translation.
 *
 * Only messages are stored — never the values that produced them. Passwords are
 * never kept at all.
 */
function field_errors_set(array $errors): void
{
    $clean = [];

    foreach ($errors as $field => $message) {
        if (! is_string($field) || ! is_string($message)) {
            continue;
        }

        $clean[$field] = mb_substr($message, 0, 300);
    }

    $_SESSION['_field_errors'] = $clean;
}

/**
 * Read and clear the pending per-field messages.
 *
 * @return array<string, string> field name => message
 */
function field_errors_take(): array
{
    $errors = $_SESSION['_field_errors'] ?? [];
    unset($_SESSION['_field_errors']);

    return is_array($errors) ? $errors : [];
}

// ---------------------------------------------------------------------------
// Content Security Policy nonce
// ---------------------------------------------------------------------------

/**
 * A per-request nonce for the one inline script that stops the theme flashing.
 *
 * Because the nonce changes on every request, the policy can keep
 * script-src 'self' with no 'unsafe-inline' — inline scripts are permitted
 * only when they carry this exact nonce.
 */
function csp_nonce(): string
{
    static $nonce = null;

    if (is_string($nonce)) {
        return $nonce;
    }

    $nonce = base64_encode(random_bytes(18));

    return $nonce;
}

/**
 * Send the response headers for a normal HTML page.
 *
 * DIVISION OF RESPONSIBILITY — please read before adding a header.
 *
 * The root .htaccess owns the static security headers (X-Content-Type-Options,
 * X-Frame-Options, Referrer-Policy, Permissions-Policy,
 * Cross-Origin-Opener-Policy, X-Permitted-Cross-Domain-Policies). That matters
 * because those rules also apply to Apache's OWN error pages — a 403 or a 404
 * that no PHP ever touched. Sending them from here as well produced
 * "nosniff,nosniff" on the wire: mod_headers APPENDS to a header PHP already
 * set rather than replacing it, and `Header always unset` does not collapse the
 * pair either.
 *
 * PHP owns only what Apache cannot know: the Content-Security-Policy, because
 * it carries a per-request nonce and so has to be built at runtime.
 *
 * If KantEase is ever run without .htaccess, tests/verify-web.php will fail the
 * "header x-content-type-options" check rather than let the gap pass unnoticed.
 * That is deliberate: the test is the guarantee.
 *
 * @param array<string, string> $extraHeaders
 */
function send_page_headers(array $extraHeaders = []): void
{
    if (headers_sent()) {
        return;
    }

    // PHP adds X-Powered-By before a script ever runs, and it names the exact
    // version in use. The .htaccess directive `php_flag expose_php off` is the
    // usual fix, but it only takes effect under mod_php AND is silently ignored
    // by every other SAPI — including FastCGI, which is how many XAMPP-style
    // stacks are configured. The web suite caught that on a live response:
    // "X-Powered-By: PHP/8.2.12". Removing it here is unconditional.
    header_remove('X-Powered-By');

    $nonce = csp_nonce();
    $csp   = implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'nonce-{$nonce}'",
        "style-src 'self' 'nonce-{$nonce}'",
        "img-src 'self' data:",
        "font-src 'self'",
        "connect-src 'self'",
        "form-action 'self'",
        "base-uri 'self'",
        "object-src 'none'",
        "frame-ancestors 'self'",
    ]);

    $headers = [
        'Content-Type'            => 'text/html; charset=UTF-8',
        'Content-Security-Policy' => $csp,
    ];

    foreach ([...$headers, ...$extraHeaders] as $name => $value) {
        header($name . ': ' . $value);
    }
}

// ---------------------------------------------------------------------------
// CSV
// ---------------------------------------------------------------------------

/**
 * Prefix a CSV cell that a spreadsheet would otherwise treat as a formula.
 *
 * Preserved from the original build. A student name of "=cmd|'/c calc'!A1"
 * must not execute when a canteen administrator opens the export.
 */
function csv_cell(mixed $value): string
{
    if ($value === null || is_bool($value)) {
        $text = $value === true ? 'Yes' : ($value === false ? 'No' : '');
    } elseif (is_array($value)) {
        $text = implode('; ', array_map(static fn ($item): string => (string) $item, $value));
    } else {
        $text = (string) $value;
    }

    if ($text !== '' && preg_match('/^[=+\-@\t\r]/', $text) === 1) {
        $text = "'" . $text;
    }

    return '"' . str_replace('"', '""', $text) . '"';
}

/**
 * Render a CSV document, including the UTF-8 BOM.
 *
 * The BOM is what makes Excel open peso signs and accented names correctly.
 *
 * @param list<list<mixed>> $rows
 */
function csv_render(array $rows): string
{
    $lines = array_map(
        static fn (array $row): string => implode(',', array_map('KantEase\csv_cell', $row)),
        $rows
    );

    return "\u{FEFF}" . implode("\r\n", $lines);
}

/**
 * Stream a CSV download to the browser and stop.
 *
 * @param list<list<mixed>> $rows
 */
function send_csv_download(string $filenamePrefix, array $rows): never
{
    $filename = sprintf(
        'kantease-%s-%s.csv',
        preg_replace('/[^a-z0-9-]+/i', '-', $filenamePrefix) ?? 'export',
        date('Ymd-His')
    );

    if (! headers_sent()) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . header_safe($filename, 80) . '"');
        header('Content-Length: ' . (string) strlen(csv_render($rows)));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }

    echo csv_render($rows);
    exit;
}

// ---------------------------------------------------------------------------
// Pagination
// ---------------------------------------------------------------------------

/**
 * Compute the numbers a paginated list needs.
 *
 * @return array{page: int, per_page: int, total: int, total_pages: int, offset: int, from: int, to: int}
 */
function paginate(int $total, int $page, int $perPage): array
{
    $perPage    = max(1, min($perPage, 200));
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page       = max(1, min($page, $totalPages));
    $offset     = ($page - 1) * $perPage;

    return [
        'page'       => $page,
        'per_page'   => $perPage,
        'total'      => $total,
        'total_pages'=> $totalPages,
        'offset'     => $offset,
        'from'       => $total === 0 ? 0 : $offset + 1,
        'to'         => min($offset + $perPage, $total),
    ];
}

// ---------------------------------------------------------------------------
// Misc
// ---------------------------------------------------------------------------

/**
 * Compare two values for sorting, tolerating nulls and mixed types.
 *
 * @param array<string, mixed> $rowA
 * @param array<string, mixed> $rowB
 */
function compare_rows(array $rowA, array $rowB, string $key, bool $descending = false): int
{
    $a = $rowA[$key] ?? null;
    $b = $rowB[$key] ?? null;

    if ($a === $b) {
        return 0;
    }

    if ($a === null) {
        return 1;
    }

    if ($b === null) {
        return -1;
    }

    $result = is_numeric($a) && is_numeric($b)
        ? ((float) $a <=> (float) $b)
        : strcasecmp((string) $a, (string) $b);

    return $descending ? -$result : $result;
}

/**
 * Truncate for a fixed-width table cell.
 */
function truncate(string $value, int $length = 60): string
{
    return mb_strlen($value) <= $length
        ? $value
        : mb_substr($value, 0, $length - 1) . '…';
}

/** Build a query string that preserves the current page's filters. */
function with_query(array $overrides, array $keep = []): string
{
    $params = [];

    foreach ($keep as $key => $value) {
        if ($value !== null && $value !== '') {
            $params[$key] = (string) $value;
        }
    }

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
            continue;
        }
        $params[$key] = (string) $value;
    }

    return $params === [] ? '' : '?' . http_build_query($params);
}