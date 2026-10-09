<?php

declare(strict_types=1);

namespace KantEase;

use RuntimeException;
use Throwable;

/**
 * Base class for every error KantEase raises deliberately.
 *
 * Anything extending this is safe to show a user. Anything extending plain
 * RuntimeException is treated as a bug and reported generically, with the real
 * message written to the PHP error log only.
 */
abstract class KantEaseException extends RuntimeException
{
    /** @var array<string, mixed> */
    private array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $message, array $context = [], int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);

        $this->context = $context;
    }

    /**
     * Extra detail for the developer-facing log line. Never rendered to a page.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /** HTTP status this error should be reported with. */
    abstract public function statusCode(): int;

    /**
     * Short, safe title for an error page.
     */
    public function title(): string
    {
        return 'Something went wrong';
    }
}

/**
 * includes/config.local.php is missing or unusable.
 *
 * This is handled specially by the bootstrap because the error page itself
 * needs configuration to render.
 */
final class ConfigurationException extends KantEaseException
{
    public function statusCode(): int
    {
        return 500;
    }

    public function title(): string
    {
        return 'KantEase needs to be configured';
    }
}

/**
 * Input failed validation. The message is written to be shown to the user
 * exactly as it is, so it must never echo back raw request content.
 */
final class ValidationException extends KantEaseException
{
    /** @var array<string, string> */
    private array $errors;

    /**
     * @param array<string, string> $errors Field name => message.
     */
    public function __construct(array $errors, string $message = '')
    {
        $this->errors = $errors;

        parent::__construct(
            $message !== '' ? $message : (reset($errors) ?: 'Please check the highlighted fields.')
        );
    }

    public function statusCode(): int
    {
        return 422;
    }

    public function title(): string
    {
        return 'Please check your details';
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** First error for one field, if any. */
    public function errorFor(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }
}

/**
 * A request was well-formed but breaks a business rule: not enough stock,
 * an illegal status transition, cancelling an already-cancelled order.
 */
final class BusinessRuleException extends KantEaseException
{
    public function statusCode(): int
    {
        return 409;
    }

    public function title(): string
    {
        return 'That action cannot be completed';
    }
}

/**
 * The caller is not signed in. Send them to the login page.
 */
final class AuthenticationException extends KantEaseException
{
    public function statusCode(): int
    {
        return 401;
    }

    public function title(): string
    {
        return 'Please sign in';
    }
}

/**
 * The caller is signed in but lacks the role this area requires.
 */
final class AuthorizationException extends KantEaseException
{
    public function statusCode(): int
    {
        return 403;
    }

    public function title(): string
    {
        return 'You do not have access to this area';
    }
}

/**
 * The requested record does not exist.
 *
 * Returning the same response for "does not exist" and "not yours" is
 * deliberate: it stops a student from discovering which order numbers are
 * real by watching for a different error.
 */
final class NotFoundException extends KantEaseException
{
    public function statusCode(): int
    {
        return 404;
    }

    public function title(): string
    {
        return 'Not found';
    }
}

/**
 * A state-changing request arrived without a valid CSRF token.
 */
final class CsrfException extends KantEaseException
{
    public function statusCode(): int
    {
        return 419;
    }

    public function title(): string
    {
        return 'Your session expired';
    }
}

/**
 * The form was submitted too many times in a short window.
 */
final class RateLimitException extends KantEaseException
{
    private int $retryAfterSeconds;

    public function __construct(string $message, int $retryAfterSeconds)
    {
        $this->retryAfterSeconds = $retryAfterSeconds;

        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return 429;
    }

    public function title(): string
    {
        return 'Too many attempts';
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}

/**
 * A database operation failed unexpectedly.
 *
 * The original message is kept for the log but is never shown, because SQL
 * errors reveal table and column names.
 */
final class DatabaseException extends KantEaseException
{
    public function statusCode(): int
    {
        return 500;
    }

    public function title(): string
    {
        return 'The database is unavailable';
    }
}