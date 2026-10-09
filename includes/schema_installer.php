<?php

declare(strict_types=1);

namespace KantEase;

/**
 * Runs database/database.sql from PHP.
 *
 * phpMyAdmin can import the file directly, but the installer needs to apply
 * it too so a first-time setup is one page rather than three manual steps.
 *
 * Splitting SQL on ";" is normally a mistake — a semicolon inside a string
 * literal or a comment would break the file. The splitter below is therefore
 * a small state machine that tracks quoting and comment state, rather than a
 * strpos() loop. It is written for the SQL this codebase produces, which uses
 * no stored routines and no DELIMITER blocks.
 */
final class SchemaInstaller
{
    /**
     * Split a SQL script into individual statements.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current    = '';
        $length     = strlen($sql);

        $inSingle  = false;
        $inDouble  = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            // ---- comments ------------------------------------------------
            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                    $current .= $char;
                }
                continue;
            }

            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }

            // ---- quoted literals ----------------------------------------
            if ($inSingle) {
                $current .= $char;

                if ($char === '\\' && $next !== '') {
                    $current .= $next;
                    $i++;
                    continue;
                }

                // A doubled quote is an escaped quote, not a terminator.
                if ($char === "'" && $next === "'") {
                    $current .= $next;
                    $i++;
                    continue;
                }

                if ($char === "'") {
                    $inSingle = false;
                }

                continue;
            }

            if ($inDouble) {
                $current .= $char;

                if ($char === '\\' && $next !== '') {
                    $current .= $next;
                    $i++;
                    continue;
                }

                if ($char === '"' && $next === '"') {
                    $current .= $next;
                    $i++;
                    continue;
                }

                if ($char === '"') {
                    $inDouble = false;
                }

                continue;
            }

            if ($inBacktick) {
                $current .= $char;

                if ($char === '`') {
                    $inBacktick = false;
                }

                continue;
            }

            // ---- unquoted state -----------------------------------------
            if ($char === '-' && $next === '-' && ($i + 2 >= $length || $sql[$i + 2] === ' ' || $sql[$i + 2] === "\t" || $sql[$i + 2] === "\n")) {
                $inLineComment = true;
                $i++;
                continue;
            }

            if ($char === '#') {
                $inLineComment = true;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }

            if ($char === "'") {
                $inSingle = true;
                $current .= $char;
                continue;
            }

            if ($char === '"') {
                $inDouble = true;
                $current .= $char;
                continue;
            }

            if ($char === '`') {
                $inBacktick = true;
                $current .= $char;
                continue;
            }

            if ($char === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return $statements;
    }

    /**
     * Execute a whole SQL script.
     *
     * Statements are run through a prepared statement and any result set is
     * fully consumed before the next one starts. PDO::exec() cannot do that:
     * when the statement happens to be a SELECT, exec() leaves an unbuffered
     * result set open on the connection and the following statement fails with
     *
     *     SQLSTATE[HY000]: General error: 2014 Cannot execute queries while
     *     other unbuffered queries are active
     *
     * This is not hypothetical here. migrate_from_node.sql ends with eight
     * SELECT verification queries, so every run reaches it.
     *
     * @return int Number of statements executed.
     * @throws DatabaseException
     */
    public static function run(string $sql): int
    {
        $pdo        = Database::connection();
        $statements = self::splitStatements($sql);
        $executed   = 0;

        foreach ($statements as $statement) {
            // The script ends with comments only; skip anything with no verb.
            if (self::isCommentOnly($statement)) {
                continue;
            }

            try {
                $handle = $pdo->prepare($statement);
                $handle->execute();

                // Drain the result set so the connection is free for the next
                // statement. columnCount() is 0 for DDL and DML.
                if ($handle->columnCount() > 0) {
                    $handle->fetchAll(\PDO::FETCH_ASSOC);
                }

                $handle->closeCursor();
            } catch (\PDOException $exception) {
                throw Database::wrap($exception, self::summarise($statement));
            }

            $executed++;
        }

        return $executed;
    }

    /**
     * Whether a statement is only comments and whitespace.
     */
    private static function isCommentOnly(string $statement): bool
    {
        $stripped = preg_replace('/--[^\n]*|#.*/', '', $statement) ?? $statement;
        $stripped = preg_replace('#/\*.*?\*/#s', '', $stripped) ?? $stripped;

        return trim($stripped) === '';
    }

    /**
     * The first few words of a statement, for an error message.
     */
    private static function summarise(string $statement): string
    {
        $flat = preg_replace('/\s+/', ' ', trim($statement)) ?? $statement;

        return strlen($flat) > 90 ? substr($flat, 0, 90) . '…' : $flat;
    }

    /**
     * Whether the configured database exists on the server.
     *
     * @throws DatabaseException
     */
    public static function databaseExists(): bool
    {
        $name = Config::string('db.name');

        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new ConfigurationException('Configuration db.name may only contain letters, digits and underscores.');
        }

        $pdo = Database::serverConnection();

        $found = $pdo
            ->query(sprintf(
                "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = %s",
                $pdo->quote($name)
            ))
            ?->fetchColumn();

        return (int) $found > 0;
    }

    /**
     * Create the configured database if it is missing.
     *
     * @throws DatabaseException
     * @throws ConfigurationException
     */
    public static function createDatabase(): void
    {
        $name = Config::string('db.name');

        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new ConfigurationException('Configuration db.name may only contain letters, digits and underscores.');
        }

        Database::serverConnection()->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_ci',
            $name
        ));
    }

    /**
     * Whether the KantEase schema is already present.
     *
     * @throws DatabaseException
     */
    public static function schemaPresent(): bool
    {
        foreach (['users', 'food_items', 'food_categories', 'orders', 'order_items'] as $table) {
            if (! Database::tableExists($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A short list of what is missing, for a helpful installer screen.
     *
     * @return list<string>
     */
    public static function missingTables(): array
    {
        $expected = [
            'users', 'food_categories', 'food_items', 'orders', 'order_items',
            'order_status_history', 'payments', 'inventory_movements',
            'activity_logs', 'login_attempts', 'id_sequences',
        ];

        return array_values(array_filter(
            $expected,
            static fn (string $table): bool => ! Database::tableExists($table)
        ));
    }
}