<?php

declare(strict_types=1);

namespace KantEase;

use KantEase\Repositories\UserRepository;

/**
 * The installer refused to act.
 *
 * A dedicated type rather than a generic RuntimeException, so the verification
 * suite can tell "refused on purpose" apart from "crashed for some other
 * reason". A test that passes because of an unrelated crash is worse than no
 * test at all, so the distinction has to be expressible in the type system.
 */
final class InstallerLockedException extends \RuntimeException
{
}

/**
 * The one-time installer's decision logic.
 *
 * database/setup.php is only the screen. Everything that decides *what* is
 * wrong and *what* may be done next lives here, so the whole sequence can be
 * exercised by the test suite without a browser and — crucially — without
 * writing to the real canteen database or removing the real lock file.
 *
 * THE SEQUENCE, IN ORDER
 *
 *   1. unconfigured  includes/config.local.php does not exist
 *   2. no_database   the configured database does not exist
 *   3. no_schema     the database exists but the tables do not
 *   4. needs_admin   the tables are in place but nobody can sign in yet
 *   5. done          an administrator exists
 *
 * An administrator may be created at step 4 and ONLY at step 4. Once step 5 is
 * reached this class refuses forever: the installer has no path that creates a
 * second administrator, and no path that removes the lock.
 */
final class Installer
{
    /** Configuration is missing. */
    public const STATE_UNCONFIGURED = 'unconfigured';

    /** The database itself does not exist. */
    public const STATE_NO_DATABASE = 'no_database';

    /** The database exists but the schema does not. */
    public const STATE_NO_SCHEMA = 'no_schema';

    /** Tables are ready; the first administrator has not been created. */
    public const STATE_NEEDS_ADMIN = 'needs_admin';

    /** Installation is complete. */
    public const STATE_DONE = 'done';

    /** The state could not be determined, usually a connection failure. */
    public const STATE_UNKNOWN = 'unknown';

    /**
     * Lock file path. Overridable so the test suite can install into a
     * throwaway location instead of removing the operator's real lock.
     */
    private static ?string $lockPath = null;

    public static function lockPath(): string
    {
        return self::$lockPath ?? dirname(__DIR__) . '/database/.installed';
    }

    /**
     * Point the lock somewhere else.
     *
     * Test-only. Accepts any path, which is safe precisely because it is only
     * ever called from tests/verify-phase2.php and never from request handling.
     */
    public static function useLockPath(string $path): void
    {
        self::$lockPath = $path;
    }

    /**
     * Is the installer permanently disabled?
     */
    public static function isLocked(): bool
    {
        return is_file(self::lockPath());
    }

    /**
     * Has this request come from the machine hosting KantEase?
     *
     * The installer can create an administrator, so it must not be open to the
     * school network.
     */
    public static function isLocalRequest(): bool
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return in_array($remote, ['127.0.0.1', '::1', ''], true);
    }

    /**
     * Work out which step we are on.
     *
     * @return array{state: string, missing: list<string>, detail: string}
     */
    public static function evaluate(): array
    {
        if (! is_file(Config::LOCAL_FILE)) {
            return [
                'state'   => self::STATE_UNCONFIGURED,
                'missing' => [],
                'detail'  => 'includes/config.local.php does not exist.',
            ];
        }

        if (self::isLocked()) {
            return [
                'state'   => self::STATE_DONE,
                'missing' => [],
                'detail'  => 'A lock file exists, so the installer has already run.',
            ];
        }

        if (! SchemaInstaller::databaseExists()) {
            return [
                'state'   => self::STATE_NO_DATABASE,
                'missing' => [],
                'detail'  => sprintf('The database "%s" does not exist.', Config::string('db.name')),
            ];
        }

        if (! SchemaInstaller::schemaPresent()) {
            return [
                'state'   => self::STATE_NO_SCHEMA,
                'missing' => SchemaInstaller::missingTables(),
                'detail'  => 'The database exists but the KantEase tables do not.',
            ];
        }

        if (UserRepository::adminExists()) {
            return [
                'state'   => self::STATE_DONE,
                'missing' => [],
                'detail'  => 'An administrator already exists.',
            ];
        }

        return [
            'state'   => self::STATE_NEEDS_ADMIN,
            'missing' => [],
            'detail'  => 'The tables are ready and no administrator exists yet.',
        ];
    }

    /**
     * Step 1 — create the database.
     *
     * @throws DatabaseException
     * @throws ConfigurationException
     */
    public static function createDatabase(): void
    {
        self::assertNotLocked();
        self::assertStateIs(self::STATE_NO_DATABASE);

        SchemaInstaller::createDatabase();
    }

    /**
     * Step 2 — apply database/database.sql.
     *
     * @return int Number of statements executed.
     * @throws DatabaseException
     */
    public static function installSchema(?string $schemaPath = null): int
    {
        self::assertNotLocked();
        self::assertStateIs(self::STATE_NO_SCHEMA);

        $schemaPath ??= dirname(__DIR__) . '/database/database.sql';

        if (! is_file($schemaPath)) {
            throw new \RuntimeException('database/database.sql could not be found.');
        }

        $statements = SchemaInstaller::run((string) file_get_contents($schemaPath));

        if (! SchemaInstaller::schemaPresent()) {
            throw new \RuntimeException(
                'The schema ran, but these tables are still missing: '
                . implode(', ', SchemaInstaller::missingTables())
            );
        }

        return $statements;
    }

    /**
     * Step 3 — create the very first administrator, then lock the installer.
     *
     * This is the only path in the entire application that can create an
     * administrator. After it runs, isLocked() is permanently true.
     *
     * @return array<string, mixed> the created account
     * @throws ValidationException
     * @throws RuntimeException
     */
    public static function createFirstAdministrator(
        string $fullName,
        string $email,
        string $password,
        string $confirmation,
    ): array {
        self::assertNotLocked();
        self::assertStateIs(self::STATE_NEEDS_ADMIN);

        $fullName  = trim($fullName);
        $email     = trim($email);
        $problems  = [];

        if ($fullName === '' || mb_strlen($fullName) > 120) {
            $problems[] = 'Enter the administrator\'s full name.';
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            $problems[] = 'Enter a valid email address.';
        }

        if ($password !== $confirmation) {
            $problems[] = 'The two passwords do not match.';
        }

        foreach (Passwords::policyProblems($password) as $problem) {
            $problems[] = $problem;
        }

        if ($problems !== []) {
            throw new ValidationException($problems);
        }

        $created = UserRepository::create(
            $fullName,
            $email,
            Passwords::hash($password),
            UserRole::Admin
        );

        self::lock((string) $created['user_code']);

        return $created;
    }

    /**
     * Write the lock file.
     *
     * Written AFTER the account exists, so a crash in between leaves the
     * installer usable for a retry rather than locking the canteen out of its
     * own system.
     */
    public static function lock(string $adminCode = 'unknown'): void
    {
        file_put_contents(self::lockPath(), sprintf(
            "KantEase installed at %s UTC.\nDatabase: %s\nFirst administrator: %s\n\n"
            . "Delete database/setup.php. To reinstall, delete this file too.\n",
            gmdate('Y-m-d H:i:s'),
            Config::string('db.name'),
            $adminCode
        ));
    }

    /**
     * Refuse every step once the installer has been used.
     *
     * @throws InstallerLockedException always, while locked
     */
    private static function assertNotLocked(): void
    {
        if (self::isLocked()) {
            throw new InstallerLockedException(
                'The KantEase installer has already run and is disabled. '
                . 'Delete database/.installed only if you really intend to install again.'
            );
        }
    }

    /**
     * @throws InstallerLockedException
     */
    private static function assertStateIs(string $expected): void
    {
        $current = self::evaluate();

        if ($current['state'] !== $expected) {
            throw new InstallerLockedException(sprintf(
                'That step cannot run now. Expected state "%s" but the installer is at "%s".',
                $expected,
                $current['state']
            ));
        }
    }
}