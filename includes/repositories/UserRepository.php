<?php

declare(strict_types=1);

namespace KantEase\Repositories;

use KantEase\Database;
use KantEase\NotFoundException;
use KantEase\Pagination;
use KantEase\SearchSpec;
use KantEase\UserRole;

use function KantEase\query_int;
use function KantEase\utc_now;

/**
 * Everything that reads or writes a `users` row.
 *
 * All SQL in KantEase lives in this namespace. Page files never contain a
 * query, which is what makes it possible to review data access on its own.
 */
final class UserRepository
{
    /** Columns that may be returned to a page. Passwords are never included. */
    private const PUBLIC_COLUMNS = 'id, user_code, full_name, email, role, is_active, created_at, updated_at, last_login_at';

    /**
     * The row Auth needs to make a decision.
     *
     * Includes password columns because this is the only place credentials are
     * read. It is never returned to a view.
     *
     * @return array<string, mixed>|null
     */
    public static function findAuthRow(int $userId): ?array
    {
        return Database::fetchOne(
            'SELECT id, user_code, full_name, email, password, password_legacy, role, is_active, created_at
               FROM users
              WHERE id = ?',
            [$userId]
        );
    }

    /**
     * Look up by User ID or email, exactly as the original login did.
     *
     * The User ID is compared upper-cased and the email lower-cased so that
     * "stu-0001" and "STU-0001", or "Name@School.PH" and "name@school.ph",
     * both work.
     *
     * @return array<string, mixed>|null
     */
    public static function findByLogin(string $identifier): ?array
    {
        return Database::fetchOne(
            'SELECT id, user_code, full_name, email, password, password_legacy, role, is_active, created_at, last_login_at
               FROM users
              WHERE user_code = ? OR email = ?
              LIMIT 1',
            [mb_strtoupper(trim($identifier)), mb_strtolower(trim($identifier))]
        );
    }

    /** @return array<string, mixed>|null */
    public static function findById(int $userId): ?array
    {
        return Database::fetchOne(
            sprintf('SELECT %s FROM users WHERE id = ?', self::PUBLIC_COLUMNS),
            [$userId]
        );
    }

    /** @return array<string, mixed>|null */
    public static function findByCode(string $userCode): ?array
    {
        return Database::fetchOne(
            sprintf('SELECT %s FROM users WHERE user_code = ?', self::PUBLIC_COLUMNS),
            [mb_strtoupper(trim($userCode))]
        );
    }

    /**
     * Whether an email is already taken by a different account.
     */
    public static function emailTaken(string $email, ?int $exceptUserId = null): bool
    {
        $sql    = 'SELECT 1 FROM users WHERE email = ?';
        $params = [mb_strtolower(trim($email))];

        if ($exceptUserId !== null) {
            $sql     .= ' AND id <> ?';
            $params[] = $exceptUserId;
        }

        return Database::fetchValue($sql, $params) !== null;
    }

    public static function userCodeTaken(string $userCode): bool
    {
        return Database::fetchValue(
            'SELECT 1 FROM users WHERE user_code = ?',
            [mb_strtoupper(trim($userCode))]
        ) !== null;
    }

    // -----------------------------------------------------------------------
    // Creation
    // -----------------------------------------------------------------------

    /**
     * Create an account and allocate its User ID.
     *
     * The ID comes from a row locked FOR UPDATE, which is what makes
     * STU-0001 gap-free even when several students register at the same
     * moment. This is the original id_sequences mechanism, generalised.
     *
     * The whole thing runs in one transaction so a failed insert cannot burn
     * an ID.
     *
     * @return array<string, mixed> the created row
     */
    public static function create(
        string $fullName,
        string $email,
        string $passwordHash,
        UserRole $role,
        bool $isActive = true,
    ): array {
        if (self::emailTaken($email)) {
            throw new \KantEase\ValidationException(
                ['email' => 'That email address is already used by another account.'],
                'That email address is already used by another account.'
            );
        }

        return Database::transaction(static function () use ($fullName, $email, $passwordHash, $role, $isActive): array {
            $sequenceName = $role === UserRole::Admin ? 'admin' : 'student';

            $next = Database::fetchValue(
                'SELECT next_number FROM id_sequences WHERE sequence_name = ? FOR UPDATE',
                [$sequenceName]
            );

            if ($next === null) {
                throw new \KantEase\DatabaseException(
                    'The ID counter is missing. Import database/database.sql again.'
                );
            }

            $nextNumber = max(1, (int) $next);
            $userCode   = sprintf('%s%04d', $role->codePrefix(), $nextNumber);

            Database::execute(
                'UPDATE id_sequences SET next_number = ? WHERE sequence_name = ?',
                [$nextNumber + 1, $sequenceName]
            );

            Database::execute(
                'INSERT INTO users (user_code, full_name, email, password, role, is_active)
                      VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $userCode,
                    $fullName,
                    mb_strtolower($email),
                    $passwordHash,
                    $role->value,
                    $isActive ? 1 : 0,
                ]
            );

            $userId = (int) Database::connection()->lastInsertId();

            $created = self::findById($userId);

            if ($created === null) {
                throw new \KantEase\DatabaseException('The account was created but could not be read back.');
            }

            AuditRepository::log($userId, 'account.create', 'user', $userId, [
                'role'      => $role->value,
                'user_code' => $userCode,
            ]);

            return $created;
        });
    }

    /**
     * The first administrator, or null when none exists yet.
     *
     * The installer uses this to decide whether it has already run.
     *
     * @return array<string, mixed>|null
     */
    public static function firstAdmin(): ?array
    {
        return Database::fetchOne(
            sprintf('SELECT %s FROM users WHERE role = ? ORDER BY id LIMIT 1', self::PUBLIC_COLUMNS),
            [UserRole::Admin->value]
        );
    }

    public static function adminExists(): bool
    {
        return Database::fetchValue(
            'SELECT 1 FROM users WHERE role = ? LIMIT 1',
            [UserRole::Admin->value]
        ) !== null;
    }

    // -----------------------------------------------------------------------
    // Listing
    // -----------------------------------------------------------------------

    /**
     * Searchable, paginated account list for Admin -> Accounts.
     *
     * @param  array{role?: string, search?: SearchSpec, page?: int, per_page?: int} $options
     * @return array{rows: list<array<string, mixed>>, pagination: Pagination}
     */
    public static function paginate(array $options = []): array
    {
        $roleFilter = $options['role'] ?? 'all';
        $search     = $options['search'] ?? self::accountSearch();
        $pager      = paginate(
            (int) ($options['total'] ?? self::countFiltered($roleFilter, $search)),
            (int) ($options['page'] ?? query_int('page', 1)),
            (int) ($options['per_page'] ?? 25)
        );

        [$where, $params] = $search->build($roleFilter === 'all' ? '' : 'WHERE u.role = ?',
            $roleFilter === 'all' ? [] : [$roleFilter]);

        $sql = sprintf(
            'SELECT u.id, u.user_code, u.full_name, u.email, u.role, u.is_active, u.created_at, u.last_login_at
               FROM users u
               %s
              ORDER BY u.user_code ASC
              %s',
            $where,
            Database::limitClause($pager['per_page'], $pager['offset'])
        );

        return [
            'rows'       => Database::fetchAll($sql, $params),
            'pagination' => Pagination::fromArray($pager),
        ];
    }

    /**
     * Count for the same filter the list uses.
     */
    public static function countFiltered(string $roleFilter, SearchSpec $search): int
    {
        [$where, $params] = $search->build(
            $roleFilter === 'all' ? '' : 'WHERE u.role = ?',
            $roleFilter === 'all' ? [] : [$roleFilter]
        );

        return (int) Database::fetchValue(sprintf('SELECT COUNT(*) FROM users u %s', $where), $params);
    }

    /**
     * The searchable fields allowed on the Accounts page.
     *
     * These expressions are written here in PHP and cannot be influenced by a
     * request.
     */
    public static function accountSearch(): SearchSpec
    {
        return SearchSpec::make([
            'user_code'    => 'u.user_code',
            'full_name'    => 'u.full_name',
            'email'        => 'u.email',
            'role'         => 'u.role',
            'date_created' => ['column' => 'u.created_at', 'isDate' => true],
        ], 'u.created_at');
    }

    public static function countByRole(UserRole $role, bool $activeOnly = false): int
    {
        $sql    = 'SELECT COUNT(*) FROM users WHERE role = ?';
        $params = [$role->value];

        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }

        return (int) Database::fetchValue($sql, $params);
    }

    public static function countActiveStudents(): int
    {
        return (int) Database::fetchValue(
            'SELECT COUNT(*) FROM users WHERE role = ? AND is_active = 1',
            [UserRole::Student->value]
        );
    }

    // -----------------------------------------------------------------------
    // Updates
    // -----------------------------------------------------------------------

    /**
     * A user editing their own name and email.
     *
     * @throws NotFoundException
     * @throws \KantEase\ValidationException
     */
    public static function updateOwnProfile(int $userId, string $fullName, string $email): array
    {
        if (! self::emailTaken($email, $userId)) {
            Database::execute(
                'UPDATE users SET full_name = ?, email = ? WHERE id = ?',
                [$fullName, mb_strtolower($email), $userId]
            );

            AuditRepository::log($userId, 'profile.update', 'user', $userId);
        }

        $updated = self::findById($userId);

        if ($updated === null) {
            throw new NotFoundException('Your account could not be found. Please sign in again.');
        }

        return $updated;
    }

    /**
     * An administrator editing any account.
     *
     * Two guards are enforced here rather than in the page, because a missing
     * guard in a page is a missing guard:
     *
     *   1. An administrator cannot change their own role. Without this an
     *      admin could demote themselves and lock the canteen out.
     *   2. The last active administrator cannot be demoted or deactivated.
     *
     * @param array{
     *     full_name?: string,
     *     email?: string,
     *     role?: UserRole,
     *     is_active?: bool,
     *     password_hash?: string|null,
     *     reset_password_legacy?: string|null
     * } $changes
     * @return array<string, mixed>
     */
    public static function adminUpdate(int $targetUserId, array $changes, int $actingAdminId): array
    {
        return Database::transaction(static function () use ($targetUserId, $changes, $actingAdminId): array {
            $target = Database::fetchOne(
                'SELECT id, user_code, full_name, email, role, is_active FROM users WHERE id = ? FOR UPDATE',
                [$targetUserId]
            );

            if ($target === null) {
                throw new NotFoundException('That account no longer exists.');
            }

            $targetRole  = UserRole::from((string) $target['role']);
            $targetIsOn  = (int) $target['is_active'] === 1;

            $newRole = $changes['role'] ?? $targetRole;
            $newOn   = $changes['is_active'] ?? $targetIsOn;

            if ($targetUserId === $actingAdminId && $newRole !== UserRole::Admin) {
                throw new \KantEase\BusinessRuleException(
                    'You cannot change your own role.'
                );
            }

            if ($targetRole === UserRole::Admin && ($newRole !== UserRole::Admin || ! $newOn) && $targetIsOn) {
                // Lock the rows first, then count in PHP.
                //
                // `SELECT COUNT(*) ... FOR UPDATE` is rejected or silently
                // ignored depending on the server and version; locking the
                // actual id values and counting them is unambiguous everywhere
                // and does exactly the same job.
                $activeAdminIds = Database::fetchColumnAll(
                    'SELECT id FROM users WHERE role = ? AND is_active = 1 ORDER BY id FOR UPDATE',
                    [UserRole::Admin->value]
                );

                $remaining = count(array_filter(
                    array_map('intval', $activeAdminIds),
                    static fn (int $id): bool => $id !== $targetUserId
                ));

                if ($remaining === 0) {
                    throw new \KantEase\BusinessRuleException(
                        'This is the only active administrator. Create another one before changing this account.'
                    );
                }
            }

            $fullName = $changes['full_name'] ?? (string) $target['full_name'];
            $email    = mb_strtolower($changes['email'] ?? (string) $target['email']);

            if ($email !== mb_strtolower((string) $target['email']) && self::emailTaken($email, $targetUserId)) {
                throw new \KantEase\ValidationException(
                    ['email' => 'That email address is already used by another account.'],
                    'That email address is already used by another account.'
                );
            }

            $sets   = ['full_name = ?', 'email = ?', 'role = ?', 'is_active = ?'];
            $params = [$fullName, $email, $newRole->value, $newOn ? 1 : 0];

            // A password change clears any legacy hash, so the account can
            // never fall back to the old scrypt verifier afterwards.
            if (isset($changes['password_hash']) && is_string($changes['password_hash']) && $changes['password_hash'] !== '') {
                $sets[]              = 'password = ?';
                $params[]            = $changes['password_hash'];
                $sets[]              = 'password_legacy = NULL';
            }

            $params[] = $targetUserId;

            Database::execute(
                sprintf('UPDATE users SET %s WHERE id = ?', implode(', ', $sets)),
                $params
            );

            AuditRepository::log($actingAdminId, 'account.admin_update', 'user', $targetUserId, [
                'user_code' => $target['user_code'],
                'from_role' => $targetRole->value,
                'to_role'   => $newRole->value,
                'active'    => $newOn,
                'password'  => isset($changes['password_hash']) ? 'reset' : 'unchanged',
            ]);

            $updated = self::findById($targetUserId);

            if ($updated === null) {
                throw new NotFoundException('That account no longer exists.');
            }

            return $updated;
        });
    }

    /**
     * Replace a legacy credential with a password_hash() value.
     *
     * The migration path from the old Node.js scrypt hashes. Called once per
     * account, at the moment the owner next signs in successfully.
     */
    public static function upgradePasswordHash(int $userId, string $passwordHash): void
    {
        Database::execute(
            'UPDATE users SET password = ?, password_legacy = NULL WHERE id = ?',
            [$passwordHash, $userId]
        );

        AuditRepository::log($userId, 'password.upgraded', 'user', $userId, [
            'note' => 'Legacy credential upgraded to password_hash().',
        ]);
    }

    /**
     * How many accounts still carry a legacy credential.
     *
     * Used by the installer and the Phase 8 test run to confirm the migration
     * finished.
     */
    public static function countPendingLegacyHashes(): int
    {
        return (int) Database::fetchValue(
            'SELECT COUNT(*) FROM users WHERE password_legacy IS NOT NULL AND password_legacy <> \'\''
        );
    }

    /** A user changing their own password after proving the current one. */
    public static function changeOwnPassword(int $userId, string $newPasswordHash): void
    {
        Database::execute(
            'UPDATE users SET password = ?, password_legacy = NULL WHERE id = ?',
            [$newPasswordHash, $userId]
        );

        AuditRepository::log($userId, 'password.change', 'user', $userId);
    }

    public static function recordLogin(int $userId): void
    {
        Database::execute('UPDATE users SET last_login_at = ? WHERE id = ?', [utc_now(), $userId]);
    }

    /**
     * Delete a student account.
     *
     * Refused while the student has any order history, exactly as the
     * original did. A student's order record is part of the canteen's sales
     * history and must not disappear because an account was closed.
     *
     * @throws \KantEase\BusinessRuleException
     */
    public static function deleteStudent(int $userId, int $actingAdminId): void
    {
        Database::transaction(static function () use ($userId, $actingAdminId): void {
            $user = Database::fetchOne(
                'SELECT id, user_code, role FROM users WHERE id = ? FOR UPDATE',
                [$userId]
            );

            if ($user === null) {
                throw new NotFoundException('That account no longer exists.');
            }

            if ((string) $user['role'] !== UserRole::Student->value) {
                throw new \KantEase\BusinessRuleException(
                    'Only student accounts can be deleted. Administrator accounts can be deactivated instead.'
                );
            }

            $orderCount = OrderRepository::countForUser($userId);

            if ($orderCount > 0) {
                throw new \KantEase\BusinessRuleException(
                    sprintf(
                        'This student has %d order%s. Order history is kept for the sales record, so the account cannot be deleted. Deactivate it instead.',
                        $orderCount,
                        $orderCount === 1 ? '' : 's'
                    )
                );
            }

            Database::execute('DELETE FROM users WHERE id = ? AND role = ?', [$userId, UserRole::Student->value]);

            AuditRepository::log($actingAdminId, 'account.delete', 'user', $userId, [
                'user_code' => $user['user_code'],
            ]);
        });
    }

    /**
     * Does this student still have cancellable orders?
     *
     * Used before deactivating an account so the interface can warn before
     * the action is taken.
     */
    public static function cancellableOrderCount(int $userId): int
    {
        return (int) Database::fetchValue(
            "SELECT COUNT(*) FROM orders WHERE user_id = ? AND status IN ('Pending','Preparing','Ready')",
            [$userId]
        );
    }

    /**
     * The signed-in user's own profile, for the Profile page.
     *
     * @return array<string, mixed>|null
     */
    public static function ownProfile(): ?array
    {
        // Fully qualified because this class lives in KantEase\Repositories,
        // where an unqualified `Auth` would resolve to
        // KantEase\Repositories\Auth — which does not exist. The missing import
        // was found by tools/check-namespaces.js, not by the Phase 2 runtime
        // suite, because no Phase 2 check called ownProfile().
        $id = \KantEase\Auth::id();

        return $id === null ? null : self::findById($id);
    }
}