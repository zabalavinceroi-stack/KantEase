<?php

declare(strict_types=1);

namespace KantEase;

/**
 * Server-side input validation.
 *
 * Nothing reaches the database without passing through here. Client-side
 * validation in the browser is a convenience for the user; this is the actual
 * gate, exactly as the original build's `inventoryItemFrom()` and inline route
 * checks were.
 */
final class Validator
{
    /** @var array<string, string> field => first problem found */
    private array $errors = [];

    /** @var array<string, mixed> cleaned values, keyed by field */
    private array $clean = [];

    /**
     * @param array<string, mixed> $source
     */
    private function __construct(private readonly array $source)
    {
    }

    /**
     * Begin validating a payload.
     *
     * @param array<string, mixed>|null $source Defaults to the request payload.
     */
    public static function for(?array $source = null): self
    {
        return new self($source ?? request_payload());
    }

    /**
     * @throws ValidationException when any rule failed.
     */
    public function validate(): array
    {
        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }

        return $this->clean;
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    private function addError(string $field, string $message): void
    {
        // Keep only the first message per field: a stack of complaints on one
        // input is more confusing than the single thing that needs fixing.
        $this->errors[$field] ??= $message;
    }

    private function raw(string $field): mixed
    {
        return $this->source[$field] ?? null;
    }

    // -----------------------------------------------------------------------
    // Primitives
    // -----------------------------------------------------------------------

    /**
     * A trimmed, length-checked, invisible-character-stripped string.
     */
    public function text(string $field, string $label, int $min = 1, int $max = 255): self
    {
        $value = $this->raw($field);

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            if ($this->raw($field) === null) {
                $this->addError($field, sprintf('%s is required.', $label));
            } else {
                $this->addError($field, sprintf('%s must be text.', $label));
            }

            return $this;
        }

        // Strip zero-width and directional characters. They are invisible in a
        // browser but would let "Admin" and "Admin<ZWSP>" look identical on
        // screen while comparing unequal in the database.
        $value = (string) $value;
        $value = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]/u', '', $value) ?? $value;
        $value = trim($value);

        if (mb_strlen($value) < $min) {
            $this->addError($field, $min === 1
                ? sprintf('%s is required.', $label)
                : sprintf('%s must be at least %d characters.', $label, $min));

            return $this;
        }

        if (mb_strlen($value) > $max) {
            $this->addError($field, sprintf('%s must be %d characters or fewer.', $label, $max));

            return $this;
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            $this->addError($field, sprintf('%s contains characters that are not allowed.', $label));

            return $this;
        }

        $this->clean[$field] = $value;

        return $this;
    }

    /**
     * A password. Never trimmed and never echoed back into old input.
     */
    public function password(string $field, string $label): self
    {
        $value = $this->raw($field);

        if (! is_string($value)) {
            $this->addError($field, sprintf('%s is required.', $label));

            return $this;
        }

        if ($value === '') {
            $this->addError($field, sprintf('%s is required.', $label));

            return $this;
        }

        foreach (Passwords::policyProblems($value) as $problem) {
            $this->addError($field, $problem);
        }

        $this->clean[$field] = $value;

        return $this;
    }

    /**
     * A password with no minimum length, used when an administrator is setting
     * somebody else's password.
     */
    public function administrativePassword(string $field, string $label): self
    {
        $value = $this->raw($field);

        if (! is_string($value) || $value === '') {
            $this->addError($field, sprintf('%s is required.', $label));

            return $this;
        }

        foreach (Passwords::policyProblems($value) as $problem) {
            $this->addError($field, $problem);
        }

        $this->clean[$field] = $value;

        return $this;
    }

    public function email(string $field, string $label = 'Email'): self
    {
        $value = $this->raw($field);

        if (! is_string($value)) {
            $this->addError($field, sprintf('%s is required.', $label));

            return $this;
        }

        $value = trim($value);

        if ($value === '') {
            $this->addError($field, sprintf('%s is required.', $label));

            return $this;
        }

        if (mb_strlen($value) > 254) {
            $this->addError($field, sprintf('%s must be 254 characters or fewer.', $label));

            return $this;
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->addError($field, sprintf('%s must be a valid email address.', $label));

            return $this;
        }

        $this->clean[$field] = mb_strtolower($value);

        return $this;
    }

    /**
     * A whole number within an inclusive range.
     */
    public function integer(
        string $field,
        string $label,
        int $min = PHP_INT_MIN,
        int $max = PHP_INT_MAX,
    ): self {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            $this->addError($field, sprintf('%s is required.', $label));

            return $this;
        }

        if (is_bool($value) || (! is_int($value) && ! is_string($value) && ! is_float($value))) {
            $this->addError($field, sprintf('%s must be a whole number.', $label));

            return $this;
        }

        $text = is_string($value) ? trim($value) : (string) $value;

        if (preg_match('/^-?\d+$/', $text) !== 1) {
            $this->addError($field, sprintf('%s must be a whole number.', $label));

            return $this;
        }

        $number = (int) $text;

        if ($number < $min || $number > $max) {
            $this->addError(
                $field,
                $min === $max
                    ? sprintf('%s must be %d.', $label, $min)
                    : sprintf('%s must be between %d and %d.', $label, $min, $max)
            );

            return $this;
        }

        $this->clean[$field] = $number;

        return $this;
    }

    /**
     * A money amount in pesos, validated in integer centavos.
     *
     * The cleaned value is the cents integer, so no later code has to touch a
     * float again.
     */
    public function money(string $field, string $label, int $minCents = 1, int $maxCents = 9_999_999_999): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            $this->addError($field, sprintf('%s is required.', $label));

            return $this;
        }

        if (is_bool($value) || (! is_string($value) && ! is_int($value) && ! is_float($value))) {
            $this->addError($field, sprintf('%s must be a number.', $label));

            return $this;
        }

        $text = is_string($value) ? trim($value) : (string) $value;

        if (preg_match('/^\d{1,7}(\.\d{1,2})?$/', $text) !== 1) {
            $this->addError($field, sprintf('%s must be an amount such as 75.00.', $label));

            return $this;
        }

        $cents = to_cents($text);

        if ($cents < $minCents) {
            $this->addError($field, $minCents === 1
                ? sprintf('%s must be greater than zero.', $label)
                : sprintf('%s must be at least %s.', $label, peso($minCents, true)));

            return $this;
        }

        if ($cents > $maxCents) {
            $this->addError($field, sprintf('%s is too large.', $label));

            return $this;
        }

        $this->clean[$field] = $cents;

        return $this;
    }

    /**
     * One of a fixed set of strings.
     *
     * @param list<string> $allowed
     */
    public function choice(string $field, string $label, array $allowed): self
    {
        $value = $this->raw($field);

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            $this->addError($field, sprintf('Choose a valid %s.', $label));

            return $this;
        }

        $this->clean[$field] = $value;

        return $this;
    }

    /**
     * A checkbox that must be ticked, for terms acceptance.
     */
    public function accepted(string $field, string $message): self
    {
        $value = $this->raw($field);

        $isTrue = $value === true || $value === '1' || $value === 1 || $value === 'on' || $value === 'true';

        if (! $isTrue) {
            $this->addError($field, $message);
        }

        $this->clean[$field] = $isTrue;

        return $this;
    }

    /**
     * A date in YYYY-MM-DD, checked against the calendar so 2026-02-31 fails.
     */
    public function date(string $field, string $label, bool $required = true): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            if ($required) {
                $this->addError($field, sprintf('%s is required.', $label));
            }

            return $this;
        }

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            $this->addError($field, sprintf('%s must be a valid date.', $label));

            return $this;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        if (! checkdate($month, $day, $year)) {
            $this->addError($field, sprintf('%s must be a valid date.', $label));

            return $this;
        }

        $this->clean[$field] = $value;

        return $this;
    }

    /**
     * An optional ISO-8601 timestamp produced by <input type="datetime-local">.
     */
    public function dateTime(string $field, string $label): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            return $this;
        }

        if (! is_string($value) || strtotime($value) === false) {
            $this->addError($field, sprintf('%s must be a valid date and time.', $label));

            return $this;
        }

        $this->clean[$field] = $value;

        return $this;
    }

    /**
     * Optional free-text note with a hard cap.
     */
    public function optionalNote(string $field, string $label, int $max = 200): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            return $this;
        }

        if (! is_string($value)) {
            $this->addError($field, sprintf('%s must be text.', $label));

            return $this;
        }

        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '');

        if (mb_strlen($value) > $max) {
            $this->addError($field, sprintf('%s must be %d characters or fewer.', $label, $max));

            return $this;
        }

        $this->clean[$field] = $value;

        return $this;
    }

    /**
     * A positive integer id.
     */
    public function id(string $field, string $label = 'ID'): self
    {
        return $this->integer($field, $label, 1, PHP_INT_MAX);
    }

    /**
     * A URL path that must stay inside this application.
     */
    public function internalPath(string $field, string $label): self
    {
        $value = $this->raw($field);

        if (! is_string($value)) {
            $this->addError($field, sprintf('%s is required.', $label));

            return $this;
        }

        $safe = safe_path($value, '');

        if ($safe === '') {
            $this->addError($field, sprintf('%s is not a valid destination.', $label));

            return $this;
        }

        $this->clean[$field] = $safe;

        return $this;
    }

    /**
     * Record a problem discovered outside a field rule.
     */
    public function reject(string $field, string $message): self
    {
        $this->addError($field, $message);

        return $this;
    }
}