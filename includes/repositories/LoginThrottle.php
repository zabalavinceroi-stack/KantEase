<?php

declare(strict_types=1);

namespace KantEase\Repositories;

use KantEase\Config;
use KantEase\Database;

use function KantEase\client_ip;

/**
 * Brute-force protection for the sign-in form.
 *
 * The original Node.js /api/login had no throttling whatsoever: any account
 * could be attacked at unlimited speed, which matters for a school system
 * where Student IDs are sequential and trivially guessable.
 *
 * Failures are counted per identifier (User ID or email, whichever was typed)
 * rather than per IP, because a whole class often shares one address behind
 * the school's NAT. Locking an address would lock out every student on that
 * network; locking an account only inconveniences the account being attacked.
 */
final class LoginThrottle
{
    /**
     * Record one attempt.
     *
     * @param string $identifier Normalised by Auth before it reaches here.
     */
    public static function record(string $identifier, bool $success): void
    {
        Database::execute(
            'INSERT INTO login_attempts (identifier, ip_address, was_successful)
                  VALUES (?, ?, ?)',
            [mb_strtolower($identifier), client_ip(), $success ? 1 : 0]
        );
    }

    /**
     * Seconds the identifier must wait before another attempt is allowed.
     *
     * Zero means it is not locked.
     */
    public static function remainingLockSeconds(string $identifier): int
    {
        $maxAttempts = Config::int('security.login_max_attempts', 5);
        $lockSeconds = Config::int('security.login_lockout_seconds', 300);

        if ($maxAttempts < 1 || $lockSeconds < 1) {
            return 0;
        }

        // Count every recent failure, with no lower time bound.
        //
        // The window belongs to the LOCK, not to the counting. An earlier
        // version filtered the count by `attempted_at >= now - lockSeconds`,
        // which meant the sixth attempt at the very end of the window saw only
        // the failures still inside it, fell below the threshold, and never
        // locked at all. Counting all of them and then measuring the wait from
        // the newest failure is both simpler and correct.
        $failures = Database::fetchValue(
            'SELECT COUNT(*)
               FROM login_attempts
              WHERE identifier = ?
                AND was_successful = 0
                AND attempted_at >= ?',
            [mb_strtolower($identifier), self::utcTimestamp(-$lockSeconds)]
        );

        if ((int) $failures < $maxAttempts) {
            return 0;
        }

        // The lock runs from the most recent failure, not the first, so the
        // wait starts over each time someone tries again.
        $lastFailure = Database::fetchValue(
            'SELECT attempted_at
               FROM login_attempts
              WHERE identifier = ? AND was_successful = 0
              ORDER BY attempted_at DESC
              LIMIT 1',
            [mb_strtolower($identifier)]
        );

        if (! is_string($lastFailure) || $lastFailure === '') {
            return 0;
        }

        $elapsed   = time() - strtotime($lastFailure . ' UTC');
        $remaining = $lockSeconds - $elapsed;

        return $remaining > 0 ? $remaining : 0;
    }

    /**
     * Failures remaining before the account locks. For the sign-in page hint.
     */
    public static function remainingAttempts(string $identifier): int
    {
        $maxAttempts = Config::int('security.login_max_attempts', 5);

        if ($maxAttempts < 1) {
            return $maxAttempts;
        }

        // Same reasoning as remainingLockSeconds(): the window bounds the lock,
        // not the tally of failures. See the note there.
        $threshold = self::utcTimestamp(-Config::int('security.login_lockout_seconds', 300));

        $failures = (int) Database::fetchValue(
            'SELECT COUNT(*)
               FROM login_attempts
              WHERE identifier = ? AND was_successful = 0 AND attempted_at >= ?',
            [mb_strtolower($identifier), $threshold]
        );

        return max(0, $maxAttempts - $failures);
    }

    /**
     * Delete rows older than the retention window.
     *
     * Called after a successful sign-in so the table stays small without
     * needing a background job.
     */
    public static function prune(): void
    {
        $hours = Config::int('security.login_attempt_retention_hours', 24);

        if ($hours < 1) {
            return;
        }

        try {
            Database::execute(
                'DELETE FROM login_attempts WHERE attempted_at < ?',
                [self::utcTimestamp(-$hours * 3600)]
            );
        } catch (\Throwable $error) {
            error_log('KantEase: could not prune login_attempts: ' . $error->getMessage());
        }
    }

    /**
     * Remove every recorded attempt for an account.
     *
     * Called when an administrator resets a password, so the student is not
     * still locked out by failures recorded before the reset.
     */
    public static function clear(string $identifier): void
    {
        Database::execute(
            'DELETE FROM login_attempts WHERE identifier = ?',
            [mb_strtolower($identifier)]
        );
    }

    /**
     * A UTC timestamp offset by N seconds, matching the DATETIME column type.
     *
     * Fully qualified because this class lives in KantEase\Repositories, where
     * an unqualified `gmdate()` would be looked up in this namespace first and
     * then fail.
     */
    private static function utcTimestamp(int $offsetSeconds = 0): string
    {
        return \gmdate('Y-m-d H:i:s', \time() + $offsetSeconds);
    }
}