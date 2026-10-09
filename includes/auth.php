<?php

declare(strict_types=1);

namespace KantEase;

use KantEase\Repositories\AuditRepository;
use KantEase\Repositories\LoginThrottle;
use KantEase\Repositories\UserRepository;
use PDO;
use Throwable;

/**
 * Session-backed authentication and authorisation.
 *
 * Design notes, all of which address findings from the Phase 1 audit:
 *
 *   VULN-6  The Node.js build cached `role` inside the session and never
 *           re-read the database, so a demoted or deleted account kept full
 *           access until its cookie expired. Every request here reloads the
 *           user row and re-checks role and is_active. A stale session cannot
 *           outlive a change made in Admin -> Accounts.
 *
 *   VULN-7  Sessions were an in-memory Map, lost on every restart. Native PHP
 *           sessions persist, and idle plus absolute timeouts are enforced
 *           server-side rather than trusted to the browser.
 *
 *   VULN-4  There was no throttle on /api/login. Failed attempts are recorded
 *           per account identifier and lock it for a configurable window.
 *
 *   D-4     A password carried in the old scrypt format is verified and then
 *           immediately replaced with password_hash() output.
 */
final class Auth
{
    private const SESSION_KEY      = 'kantease_auth';
    private const FINGERPRINT_KEY  = '_fingerprint';

    /** Per-request cache so a page touching Auth a dozen times still hits the database once. */
    private static ?array $cachedUser = null;
    private static bool $userLoaded  = false;

    // -----------------------------------------------------------------------
    // Session lifecycle
    // -----------------------------------------------------------------------

    /**
     * Start the session with cookies configured for a local HTTP school LAN.
     *
     * `secure` is deliberately off: KantEase is served over plain HTTP on
     * localhost or a LAN and has no certificate, so a Secure cookie would
     * never be sent back and nobody could sign in. If KantEase is ever put
     * behind HTTPS, set session.cookie_secure to true in php.ini.
     */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // use_strict_mode rejects any client-supplied session id that the
        // server did not issue, which closes session fixation at the door.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.gc_maxlifetime', (string) Config::int('app.session_absolute_timeout', 43200));

        session_name('KANTEASE');

        session_start([
            'cookie_path'     => Config::basePath() === '' ? '/' : Config::basePath() . '/',
            'cookie_lifetime' => 0,
        ]);

        self::enforceTimeouts();
    }

    /**
     * Drop the session if it has been idle too long or has run past its
     * absolute lifetime.
     */
    private static function enforceTimeouts(): void
    {
        $auth = $_SESSION[self::SESSION_KEY] ?? null;

        if (! is_array($auth) || ! isset($auth['user_id'], $auth['auth_time'], $auth['last_activity'])) {
            return;
        }

        $now       = time();
        $idle      = Config::int('app.session_idle_timeout', 28800);
        $absolute  = Config::int('app.session_absolute_timeout', 43200);

        $idleSince = (int) $auth['last_activity'];
        $startedAt = (int) $auth['auth_time'];

        if ($now - $idleSince > $idle || $now - $startedAt > $absolute) {
            self::logout();

            return;
        }

        // A session id reused from a different browser is treated as stolen.
        $fingerprint = self::fingerprint();
        if (isset($auth['fingerprint']) && is_string($auth['fingerprint'])
            && ! hash_equals($auth['fingerprint'], $fingerprint)) {
            self::logout();
        }
    }

    /**
     * A coarse, non-identifying binding for the session.
     *
     * Built from the user agent and the LAN address. It is a tripwire for a
     * copied cookie, not a substitute for the database re-check, and it never
     * stores the raw values.
     */
    private static function fingerprint(): string
    {
        $raw = ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . ($_SERVER['REMOTE_ADDR'] ?? '');

        return hash('sha256', $raw);
    }

    // -----------------------------------------------------------------------
    // Current user
    // -----------------------------------------------------------------------

    /**
     * The signed-in user, re-read from the database on every request.
     *
     * Returns null when there is no valid session, when the account has been
     * deactivated, or when the account no longer exists.
     *
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        if (self::$userLoaded) {
            return self::$cachedUser;
        }

        self::$userLoaded = true;
        self::$cachedUser = null;

        $auth = $_SESSION[self::SESSION_KEY] ?? null;
        if (! is_array($auth) || ! isset($auth['user_id'])) {
            return null;
        }

        $userId = (int) $auth['user_id'];
        if ($userId < 1) {
            self::logout();

            return null;
        }

        try {
            $user = UserRepository::findAuthRow($userId);
        } catch (Throwable) {
            // A database hiccup must not silently look like "signed out", but
            // it also must not grant access. Fail closed.
            self::logout();

            throw new DatabaseException(
                'KantEase could not verify your session. Please try again.'
            );
        }

        if ($user === null || (int) $user['is_active'] !== 1) {
            // The account was deleted or switched off while the session lived.
            self::logout();

            return null;
        }

        self::$cachedUser = $user;

        // Refresh the idle timer. This is a write on every request, so it only
        // happens at most once per 60 seconds.
        $lastActivity = (int) ($_SESSION[self::SESSION_KEY]['last_activity'] ?? 0);
        if (time() - $lastActivity >= 60) {
            $_SESSION[self::SESSION_KEY]['last_activity'] = time();
        }

        return $user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    public static function role(): ?UserRole
    {
        $user = self::user();
        if ($user === null) {
            return null;
        }

        return UserRole::tryFrom((string) $user['role']);
    }

    public static function isAdmin(): bool
    {
        return self::role() === UserRole::Admin;
    }

    public static function isStudent(): bool
    {
        return self::role() === UserRole::Student;
    }

    // -----------------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------------

    /**
     * Require any signed-in user, or send them to the login page.
     *
     * @return array<string, mixed>
     * @throws AuthenticationException
     */
    public static function requireLogin(): array
    {
        $user = self::user();

        if ($user === null) {
            throw new AuthenticationException('Please sign in to continue.');
        }

        return $user;
    }

    /**
     * Require a specific role.
     *
     * The check is server-side and unconditional. Hiding a button in the
     * interface is a usability courtesy, never the control itself.
     *
     * @return array<string, mixed>
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    public static function requireRole(UserRole $role): array
    {
        $user = self::requireLogin();

        $actual = UserRole::tryFrom((string) $user['role']);

        if ($actual !== $role) {
            throw new AuthorizationException(
                $actual === UserRole::Admin
                    ? 'You are signed in as an administrator. That area is for students.'
                    : 'You are signed in as a student. That area is for canteen staff.'
            );
        }

        return $user;
    }

    /** @return array<string, mixed> */
    public static function requireAdmin(): array
    {
        return self::requireRole(UserRole::Admin);
    }

    /** @return array<string, mixed> */
    public static function requireStudent(): array
    {
        return self::requireRole(UserRole::Student);
    }

    // -----------------------------------------------------------------------
    // Sign in / sign out
    // -----------------------------------------------------------------------

    /**
     * Verify credentials and establish a session.
     *
     * @return array{user: array<string, mixed>, upgraded: bool}
     * @throws ValidationException  when the identifier or password is malformed
     * @throws RateLimitException   when the account is temporarily locked
     * @throws AuthenticationException when the credentials are simply wrong
     */
    public static function attempt(string $identifier, string $password): array
    {
        $identifier = trim($identifier);

        if ($identifier === '' || $password === '') {
            throw new AuthenticationException('Enter your User ID or email and your password.');
        }

        if (mb_strlen($identifier) > 254) {
            throw new AuthenticationException('Enter your User ID or email and your password.');
        }

        // Uniform message for "no such account" and "wrong password" so the
        // form cannot be used to discover which IDs exist.
        $genericFailure = new AuthenticationException(
            'That User ID or email, or the password, is not correct.'
        );

        $lockSeconds = LoginThrottle::remainingLockSeconds($identifier);

        if ($lockSeconds > 0) {
            throw new RateLimitException(
                sprintf(
                    'Too many failed attempts. Try again in %d minute%s, or ask the canteen staff to reset your password.',
                    (int) ceil($lockSeconds / 60),
                    $lockSeconds > 60 ? 's' : ''
                ),
                $lockSeconds
            );
        }

        $user = UserRepository::findByLogin($identifier);

        if ($user === null) {
            LoginThrottle::record($identifier, success: false);

            throw $genericFailure;
        }

        $result = Passwords::verify($password, (string) $user['password'], $user['password_legacy'] ?? null);

        if (! $result['valid']) {
            LoginThrottle::record($identifier, success: false);

            throw $genericFailure;
        }

        if ((int) $user['is_active'] !== 1) {
            LoginThrottle::record($identifier, success: false);

            throw new AuthenticationException(
                'This account has been deactivated. Please ask the canteen staff to reactivate it.'
            );
        }

        // D-4: upgrade a legacy credential the moment it is proven correct.
        if ($result['rehash_hash'] !== null) {
            UserRepository::upgradePasswordHash((int) $user['id'], $result['rehash_hash']);
            $upgraded = true;
        } else {
            $upgraded = $result['legacy_verified'];
        }

        self::login($user);
        LoginThrottle::record($identifier, success: true);
        LoginThrottle::prune();

        AuditRepository::log((int) $user['id'], 'auth.login', 'user', (int) $user['id']);

        return ['user' => $user, 'upgraded' => $upgraded];
    }

    /**
     * Establish a session for a verified user.
     *
     * @param array<string, mixed> $user
     */
    public static function login(array $user): void
    {
        // Regenerate before writing anything, so a session id fixed by an
        // attacker before sign-in is never the one that becomes privileged.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION[self::SESSION_KEY] = [
            'user_id'       => (int) $user['id'],
            'role'          => (string) $user['role'],
            'auth_time'     => time(),
            'last_activity' => time(),
            'fingerprint'   => self::fingerprint(),
        ];
        $_SESSION[self::FINGERPRINT_KEY] = self::fingerprint();

        Csrf::rotate();

        self::$cachedUser = null;
        self::$userLoaded = true;

        UserRepository::recordLogin((int) $user['id']);
    }

    /**
     * End the session completely.
     *
     * The id is regenerated as well as cleared, so a cookie captured before
     * logout cannot be replayed afterwards.
     */
    public static function logout(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            if (PHP_VERSION_ID >= 70400) {
                session_regenerate_id(true);
            } else {
                session_destroy();
            }
        }

        self::$cachedUser = null;
        self::$userLoaded = true;

        if (! headers_sent()) {
            $params = [
                'expires'  => time() - 42000,
                'path'     => Config::basePath() === '' ? '/' : Config::basePath() . '/',
                'domain'   => '',
                'secure'   => false,
                'httponly' => true,
                'samesite' => 'Lax',
            ];
            setcookie(session_name(), '', $params);
        }
    }

    /**
     * Send the caller to their own panel home page.
     */
    public static function redirectToOwnHome(): never
    {
        $role = self::role();

        redirect($role?->homePath() ?? '/login.php');
    }

    /**
     * Convenience for page guards that want a redirect instead of an error.
     *
     * @return array<string, mixed>|null
     */
    public static function loginOrRedirect(?string $returnTo = null): ?array
    {
        $user = self::user();

        if ($user === null) {
            flash_set('error', 'Please sign in to continue.');
            redirect('/login.php' . ($returnTo !== null ? with_query(['next' => $returnTo]) : ''));
        }

        return $user;
    }

    /**
     * Drop the per-request user cache. Only needed by tests or a long-running
     * process that performs several logins in one request.
     */
    public static function resetRequestCache(): void
    {
        self::$cachedUser = null;
        self::$userLoaded  = false;
    }

    /**
     * Run a callback inside the caller's current database transaction.
     *
     * Repositories call this so a nested write joins the transaction the page
     * already opened instead of starting a second one.
     *
     * @template T
     * @param  callable(PDO): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        return Database::transaction($callback);
    }
}