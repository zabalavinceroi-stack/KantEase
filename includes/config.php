<?php

declare(strict_types=1);

namespace KantEase;

/**
 * Loads and validates the local configuration.
 *
 * The real values live in includes/config.local.php, which is git-ignored and
 * blocked by includes/.htaccess. This class merges it over
 * config.sample.php so that a key added to the sample later does not break a
 * config.local.php written earlier.
 */
final class Config
{
    public const LOCAL_FILE = __DIR__ . '/config.local.php';
    public const SAMPLE_FILE = __DIR__ . '/config.sample.php';

    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /**
     * Load, merge and validate the configuration exactly once per request.
     *
     * @throws ConfigurationException when config.local.php is missing or invalid.
     */
    public static function load(): void
    {
        if (self::$data !== null) {
            return;
        }

        if (! is_file(self::LOCAL_FILE)) {
            throw new ConfigurationException(
                'KantEase is not configured yet.'
                . ' Copy includes/config.sample.php to includes/config.local.php,'
                . ' then enter your database details in it.'
            );
        }

        /** @var mixed $local */
        $local = require self::LOCAL_FILE;
        /** @var mixed $sample */
        $sample = require self::SAMPLE_FILE;

        if (! is_array($local) || ! is_array($sample)) {
            throw new ConfigurationException(
                'includes/config.local.php must return an array.'
            );
        }

        self::$data = self::mergeRecursive($sample, $local);
        self::validate(self::$data);
    }

    /**
     * Replace one configuration value at runtime.
     *
     * EXISTS FOR THE TEST SUITE ONLY. The verification suite must run against
     * a disposable database rather than the canteen's real one, and it needs to
     * point the application at that database without editing the operator's
     * config.local.php.
     *
     * It refuses once a database connection has been opened. That guarantee is
     * what makes this safe: a connection can only exist if the application has
     * already used the configured database, so there is no window in which a
     * live query could be redirected. A page file that misused this would
     * receive a LogicException rather than silently writing to the wrong
     * database.
     *
     * @throws \LogicException if called after a connection exists.
     */
    public static function override(string $key, mixed $value): void
    {
        self::load();

        if (Database::hasConnection()) {
            throw new \LogicException(
                'Config::override() must be called before any database connection is opened. '
                . 'Refusing to redirect an in-use connection to a different database.'
            );
        }

        if (self::$data === null) {
            self::$data = [];
        }

        self::setPath(self::$data, explode('.', $key), $value);
    }

    /**
     * @param array<string, mixed> $target
     * @param list<string>          $segments
     */
    private static function setPath(array &$target, array $segments, mixed $value): void
    {
        $head = array_shift($segments);

        if ($segments === []) {
            $target[$head] = $value;

            return;
        }

        if (! isset($target[$head]) || ! is_array($target[$head])) {
            $target[$head] = [];
        }

        self::setPath($target[$head], $segments, $value);
    }

    /**
     * Read a configuration value with dot notation.
     *
     * Example: Config::get('db.host'), Config::get('orders.max_quantity_per_item')
     *
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();

        $value = self::$data;
        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** Read a configuration value and require it to be a string. */
    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /** Read a configuration value and require it to be a positive integer. */
    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /** Read a configuration value and require it to be a boolean. */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);

        return is_bool($value) ? $value : $default;
    }

    /** True when detailed errors should be printed instead of only logged. */
    public static function isDevelopment(): bool
    {
        return self::bool('app.dev_mode', false);
    }

    /**
     * Application root URL path, always with a leading slash and no trailing
     * slash. '' when KantEase is served from the document root itself.
     */
    public static function basePath(): string
    {
        $path = trim(self::string('app.base_path', ''), '/');

        return $path === '' ? '' : '/' . $path;
    }

    /**
     * Merge $override onto $base. Scalars and nulls from $override win;
     * nested arrays are merged key by key.
     *
     * @param  array<string, mixed> $base
     * @param  array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function mergeRecursive(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                /** @var array<string, mixed> $baseChild */
                $baseChild = $base[$key];
                $base[$key] = self::mergeRecursive($baseChild, $value);
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * Fail loudly on a configuration that would otherwise surface later as a
     * confusing SQL or PDO error.
     *
     * @param array<string, mixed> $data
     */
    private static function validate(array $data): void
    {
        foreach (['db', 'app', 'security', 'orders'] as $section) {
            if (! isset($data[$section]) || ! is_array($data[$section])) {
                throw new ConfigurationException(
                    "Configuration is missing the [{$section}] section."
                );
            }
        }

        foreach (['host', 'name', 'user'] as $key) {
            $value = $data['db'][$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                throw new ConfigurationException(
                    "Configuration is missing db.{$key}."
                );
            }
        }

        // The password may legitimately be empty for a default XAMPP root
        // account, so it is deliberately not validated here.

        $port = $data['db']['port'] ?? 3306;
        if (! is_int($port) || $port < 1 || $port > 65_535) {
            throw new ConfigurationException('Configuration db.port is not a valid port number.');
        }

        $timezone = $data['app']['timezone'] ?? null;
        if (! is_string($timezone) || ! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new ConfigurationException(
                'Configuration app.timezone must be a valid PHP timezone identifier.'
            );
        }

        $idle = $data['app']['session_idle_timeout'] ?? null;
        if (! is_int($idle) || $idle < 300) {
            throw new ConfigurationException(
                'Configuration app.session_idle_timeout must be an integer of at least 300 seconds.'
            );
        }

        $maxAttempts = $data['security']['login_max_attempts'] ?? null;
        if (! is_int($maxAttempts) || $maxAttempts < 1) {
            throw new ConfigurationException(
                'Configuration security.login_max_attempts must be a positive integer.'
            );
        }

        $policy = $data['orders']['stock_policy'] ?? null;
        if (! is_string($policy) || ! in_array($policy, ['deduct_on_place', 'reserve_on_place'], true)) {
            throw new ConfigurationException(
                'Configuration orders.stock_policy must be deduct_on_place or reserve_on_place.'
            );
        }

        $extensions = $data['security']['upload_allowed_extensions'] ?? null;
        $mimes = $data['security']['upload_allowed_mimes'] ?? null;
        if (! is_array($extensions) || $extensions === [] || ! is_array($mimes)) {
            throw new ConfigurationException(
                'Configuration security.upload_allowed_extensions and upload_allowed_mimes must be non-empty arrays.'
            );
        }
    }
}