<?php

declare(strict_types=1);

namespace KantEase;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * The single database gateway.
 *
 * Every query in KantEase goes through here. That is what guarantees the two
 * properties the whole ordering flow depends on:
 *
 *   1. Real prepared statements. PDO::ATTR_EMULATE_PREPARES is false, so
 *      MySQL receives the statement and the parameters separately. A bound
 *      integer stays an integer in `stock = stock - ?`, which is why the
 *      oversell guard works.
 *   2. One connection per request. A transaction opened through this class
 *      uses the same PDO handle the surrounding code is already using, so a
 *      COMMIT genuinely commits everything done inside the callback.
 */
final class Database
{
    private static ?PDO $pdo = null;

    /** Statements prepared more than once in a request are reused. */
    private static array $statementCache = [];

    private function __construct()
    {
    }

    /**
     * Has a shared connection been opened yet?
     *
     * Read by Config::override() so configuration cannot be redirected after
     * the application has already started talking to a database.
     */
    public static function hasConnection(): bool
    {
        return self::$pdo instanceof PDO;
    }

    /**
     * The shared PDO handle, opened on first use.
     *
     * @throws DatabaseException when the server or database is unreachable.
     */
    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        Config::load();

        $host    = Config::string('db.host');
        $port    = Config::int('db.port', 3306);
        $name    = Config::string('db.name');
        $user    = Config::string('db.user');
        $charset = Config::string('db.charset', 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            self::$pdo = new PDO($dsn, $user, Config::string('db.password'), [
                // Surface every problem as an exception instead of a false.
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::ATTR_PERSISTENT         => false,
                // Buffer results client-side. Without this, a SELECT that is
                // not fully drained holds the connection and the next query
                // fails with "2014 Cannot execute queries while other
                // unbuffered queries are active". Server-side row locks taken
                // by FOR UPDATE are unaffected by client-side buffering.
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]);
        } catch (PDOException $exception) {
            throw new DatabaseException(
                self::connectionMessage($exception, $name),
                ['dsn' => sprintf('mysql:host=%s;port=%d;dbname=%s', $host, $port, $name)],
                0,
                $exception
            );
        }

        self::configureSession();

        return self::$pdo;
    }

    /**
     * Apply the session settings KantEase relies on.
     *
     * ORDERING NOTE — this is the line that broke MariaDB:
     *
     *   SET SESSION transaction_isolation = 'READ-COMMITTED'   <-- FAILS on MariaDB
     *
     * MySQL 5.7.5 renamed the `tx_isolation` system variable to
     * `transaction_isolation`. MariaDB never adopted the MySQL name and still
     * exposes only `tx_isolation`, so the MySQL form raises
     *
     *     ERROR 1193 (HY000): Unknown system variable 'transaction_isolation'
     *
     * before a single query runs. The portable replacement is the SQL-standard
     * statement below, which every supported server accepts:
     *
     *   MySQL 5.7 / 8.x  -- accepts the standard form
     *   MariaDB 10.2+    -- accepts the standard form
     *
     * Verified against MariaDB 10.4.32, which reports only `tx_isolation`.
     *
     * WHY EACH SETTING IS NOT CRITICAL
     *
     * Isolation level is a preference here, not a guarantee. The stock-locking
     * correctness the application depends on comes from two explicit,
     * database-level controls that are unaffected by this setting:
     *
     *   SELECT ... FROM food_items WHERE id IN (...) ORDER BY id FOR UPDATE
     *   UPDATE food_items SET stock = stock + ? WHERE id = ? AND stock + ? >= 0
     *
     * The row lock serialises competing orders, and the guarded UPDATE refuses
     * to write when the arithmetic would go negative. If this method fails for
     * any reason the application still cannot oversell; it would merely run at
     * the server's default isolation. That is why each step is allowed to fail
     * on its own rather than taking the whole site down at connection time.
     */
    private static function configureSession(): void
    {
        self::trySessionSetting(
            "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,"
            . "ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
            'sql_mode'
        );

        // Stored and compared in UTC. Only the moment of formatting converts to
        // the canteen's timezone, so a server in another zone cannot shift an
        // order into the wrong day.
        self::trySessionSetting("SET SESSION time_zone = '+00:00'", 'time_zone');

        // READ COMMITTED narrows gap locking around the hot food_items rows,
        // which reduces deadlocks when several students order the same item at
        // once. Database::transaction() retries on deadlock regardless.
        self::trySessionSetting(
            'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED',
            'transaction isolation'
        );
    }

    /**
     * Apply one session setting, logging rather than throwing if the server
     * refuses it.
     *
     * A refused session preference must not take down a canteen that is
     * otherwise working, so each one is independent and none is load-bearing.
     */
    private static function trySessionSetting(string $sql, string $label): void
    {
        try {
            self::$pdo?->exec($sql);
        } catch (PDOException $exception) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);

            error_log(sprintf(
                'KantEase: could not set %s (driver %d). Continuing with the server default.',
                $label,
                $driverCode
            ));
        }
    }

    /**
     * The session's transaction isolation level.
     *
     * MySQL 8 exposes it as `transaction_isolation`; MariaDB only ever had
     * `tx_isolation`. Both are tried so diagnostics work on either server.
     *
     * @throws DatabaseException when neither name exists.
     */
    public static function transactionIsolation(): ?string
    {
        foreach (['tx_isolation', 'transaction_isolation'] as $variable) {
            try {
                return self::sessionVariable($variable);
            } catch (PDOException) {
                // Unknown variable on this server; try the other name.
            }
        }

        return null;
    }

    /**
     * Read a session variable, for the verification suite.
     *
     * @throws DatabaseException
     */
    public static function sessionVariable(string $name): ?string
    {
        try {
            $statement = self::connection()->prepare('SELECT @@SESSION.' . self::quoteIdentifier($name));
            $statement->execute();

            $value = $statement->fetchColumn();
        } catch (PDOException $exception) {
            throw self::wrap($exception, 'SELECT @@SESSION.' . $name);
        }

        return $value === false ? null : (string) $value;
    }

    /**
     * Open a connection to the server itself, ignoring the configured
     * database name. Used by the installer to check the server is alive
     * before deciding whether the database needs creating.
     *
     * @throws DatabaseException
     */
    public static function serverConnection(): PDO
    {
        Config::load();

        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            Config::string('db.host'),
            Config::int('db.port', 3306),
            Config::string('db.charset', 'utf8mb4')
        );

        try {
            return new PDO(
                $dsn,
                Config::string('db.user'),
                Config::string('db.password'),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                ]
            );
        } catch (PDOException $exception) {
            throw new DatabaseException(
                self::connectionMessage($exception, Config::string('db.name')),
                [],
                0,
                $exception
            );
        }
    }

    /**
     * Turn a driver failure into something a school administrator can act on
     * without exposing hostnames, paths or SQL to a student.
     */
    private static function connectionMessage(PDOException $exception, string $database): string
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return match (true) {
            $driverCode === 1045 => 'MySQL rejected the username or password in includes/config.local.php.',
            $driverCode === 1049 => sprintf('The database "%s" does not exist. Import database/database.sql first.', $database),
            $driverCode === 2002 => 'MySQL is not running. Open the XAMPP Control Panel and start MySQL.',
            $driverCode === 2003 => 'KantEase cannot reach MySQL. Check that MySQL is running and that the host and port in includes/config.local.php are correct.',
            $driverCode === 1044 => sprintf('The MySQL account has no permission to use the database "%s".', $database),
            $driverCode === 1271 => 'The MySQL account cannot connect from this address. Check the account privileges in phpMyAdmin.',
            default              => 'KantEase cannot connect to the database. Check that MySQL is running and that includes/config.local.php is correct.',
        };
    }

    /**
     * Prepare, execute and return the statement.
     *
     * @param array<int|string, mixed> $params
     * @throws DatabaseException
     */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        try {
            $statement = self::connection()->prepare($sql);
            $statement->execute($params);

            return $statement;
        } catch (PDOException $exception) {
            throw self::wrap($exception, $sql);
        }
    }

    /**
     * @param  array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     * @throws DatabaseException
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = self::run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @param  array<int|string, mixed> $params
     * @return array<string, mixed>|null
     * @throws DatabaseException
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * First column of the first row, or null.
     *
     * @param array<int|string, mixed> $params
     * @throws DatabaseException
     */
    public static function fetchValue(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param  array<int|string, mixed> $params
     * @return list<mixed>
     * @throws DatabaseException
     */
    public static function fetchColumnAll(string $sql, array $params = []): array
    {
        /** @var list<mixed> $values */
        $values = self::run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);

        return $values;
    }

    /**
     * Execute and report how many rows changed.
     *
     * The oversell guard depends on this returning exactly 0 when the
     * `AND stock >= ?` condition did not match.
     *
     * @param array<int|string, mixed> $params
     * @throws DatabaseException
     */
    public static function execute(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    /**
     * Run a callback inside a transaction, committing on success and rolling
     * back on any throwable.
     *
     * A deadlock or lock-wait timeout is retried a few times: two students
     * ordering the last portion of the same item legitimately collide on the
     * food_items row lock, and the loser should simply try again rather than
     * see a server error.
     *
     * The callback receives the shared PDO handle so every repository call
     * inside it joins the same transaction.
     *
     * @template T
     * @param  callable(PDO): T $callback
     * @return T
     * @throws Throwable
     */
    public static function transaction(callable $callback, int $maxAttempts = 3): mixed
    {
        $attempt = 0;

        while (true) {
            $attempt++;
            $pdo = self::connection();

            // Never open a nested transaction: a Laravel-style "savepoint"
            // illusion would silently commit the outer work early.
            if ($pdo->inTransaction()) {
                return $callback($pdo);
            }

            try {
                $pdo->beginTransaction();
            } catch (PDOException $exception) {
                throw self::wrap($exception, 'BEGIN');
            }

            try {
                $result = $callback($pdo);
                $pdo->commit();

                return $result;
            } catch (Throwable $exception) {
                self::safeRollBack($pdo);

                if (self::isRetryable($exception) && $attempt < $maxAttempts) {
                    // Brief, growing pause so the two transactions stop
                    // colliding immediately.
                    usleep(50_000 * $attempt);
                    continue;
                }

                throw $exception;
            }
        }
    }

    public static function inTransaction(): bool
    {
        return self::$pdo instanceof PDO && self::$pdo->inTransaction();
    }

    /** Roll back without letting a rollback failure mask the original error. */
    private static function safeRollBack(PDO $pdo): void
    {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable) {
            // The server already dropped the transaction. Nothing to do.
        }
    }

    /** MySQL deadlock (1213) and lock wait timeout (1205). */
    private static function isRetryable(Throwable $exception): bool
    {
        if (! $exception instanceof DatabaseException) {
            return false;
        }

        $driverCode = $exception->context()['driver_code'] ?? null;

        return in_array($driverCode, [1213, 1205], true);
    }

    /**
     * Wrap a PDOException, keeping the SQL and driver code for the log but
     * never for the page.
     */
    public static function wrap(PDOException $exception, string $sql): DatabaseException
    {
        $info = $exception->errorInfo;

        return new DatabaseException(
            'The database rejected a KantEase query.',
            [
                'sql'         => $sql,
                'driver_code' => (int) ($info[1] ?? 0),
                'driver_state'=> (string) ($info[2] ?? ''),
            ],
            0,
            $exception
        );
    }

    /**
     * Check whether a table exists in the configured database.
     *
     * @throws DatabaseException
     */
    public static function tableExists(string $table): bool
    {
        $safeTable = self::quoteIdentifier($table);

        $found = self::fetchValue(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );

        unset($safeTable);

        return (int) $found > 0;
    }

    /**
     * Quote an identifier for safe interpolation.
     *
     * Only ever call this with a literal defined in this codebase, never with
     * anything derived from a request. Sort-column allow-lists in the
     * repositories feed this method, which is the same defence the original
     * build used: a request value may select between pre-written fragments but
     * can never become SQL of its own.
     */
    public static function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new \InvalidArgumentException(
                sprintf('Refusing to quote the unsafe identifier "%s".', $identifier)
            );
        }

        return '`' . $identifier . '`';
    }

    /**
     * Build a LIMIT clause.
     *
     * LIMIT cannot be bound as a parameter in MySQL, so it must be
     * interpolated. This clamps to a sane range and returns an integer, so
     * the result is always safe to concatenate.
     *
     * IMPORTANT: the returned string ALREADY CONTAINS the "LIMIT" keyword.
     * Write your SQL as
     *
     *     '... ORDER BY o.id ' . Database::limitClause(25)
     *
     * not '... LIMIT ' . Database::limitClause(25), which produces the
     * syntax error "LIMIT LIMIT 25". That mistake cost a debugging cycle
     * during Phase 2 verification, so the trap is called out here.
     */
    public static function limitClause(int $limit, int $offset = 0): string
    {
        $limit  = max(1, min($limit, 1000));
        $offset = max(0, min($offset, 10_000_000));

        return sprintf('LIMIT %d OFFSET %d', $limit, $offset);
    }

    /**
     * Placeholder string for an IN () list: 3 placeholders -> "?,?,?".
     *
     * @param list<int|string> $values
     */
    public static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, max(1, count($values)), '?'));
    }
}