<?php

declare(strict_types=1);

namespace KantEase\Repositories;

use KantEase\BusinessRuleException;
use KantEase\Database;
use KantEase\NotFoundException;
use KantEase\Pagination;
use KantEase\SearchSpec;

use function KantEase\query_int;

/**
 * The `food_categories` table.
 *
 * In the original build a category was a bare VARCHAR constrained only by a
 * hardcoded array in server.js that was duplicated by hand in the browser. A
 * typo in one copy and the two disagreed, and adding a category meant editing
 * and redeploying code. It is a table now.
 */
final class CategoryRepository
{
    /** Longest name, matching the column. */
    public const NAME_MAX = 60;

    /**
     * Every category, active or not, for the admin inventory screen.
     *
     * item_count counts ALL products, archived included. A canteen deciding
     * whether it is safe to delete a category needs the real total: "0
     * products" must mean zero rows, not zero rows still on the menu.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return Database::fetchAll(
            'SELECT c.id, c.name, c.sort_order, c.is_active, c.created_at,
                    (SELECT COUNT(*) FROM food_items f WHERE f.category_id = c.id) AS item_count,
                    (SELECT COUNT(*) FROM food_items f
                      WHERE f.category_id = c.id AND f.is_archived = 0 AND f.is_available = 1) AS menu_count
               FROM food_categories c
              ORDER BY c.sort_order ASC, c.name ASC'
        );
    }

    /**
     * Categories a student can browse.
     *
     * A category is hidden when it is switched off, or when it holds no
     * products a student could actually buy — an empty tab is worse than no
     * tab.
     *
     * @return list<array<string, mixed>>
     */
    public static function visibleToStudents(): array
    {
        return Database::fetchAll(
            "SELECT c.id, c.name,
                    (SELECT COUNT(*) FROM food_items f
                      WHERE f.category_id = c.id
                        AND f.is_archived = 0
                        AND f.is_available = 1) AS item_count
               FROM food_categories c
              WHERE c.is_active = 1
              ORDER BY c.sort_order ASC, c.name ASC"
        );
    }

    /** @return array<string, mixed>|null */
    public static function findById(int $id): ?array
    {
        return Database::fetchOne(
            'SELECT id, name, sort_order, is_active FROM food_categories WHERE id = ?',
            [$id]
        );
    }

    /** @return array<string, mixed>|null */
    public static function findByName(string $name): ?array
    {
        return Database::fetchOne(
            'SELECT id, name, sort_order, is_active FROM food_categories WHERE name = ?',
            [trim($name)]
        );
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return Database::fetchColumnAll(
            'SELECT name FROM food_categories WHERE is_active = 1 ORDER BY sort_order ASC, name ASC'
        );
    }

    public static function exists(int $id): bool
    {
        return Database::fetchValue('SELECT 1 FROM food_categories WHERE id = ?', [$id]) !== null;
    }

    /**
     * Create a category. Returns null when the name is already used.
     *
     * @return array<string, mixed>|null
     */
    public static function create(string $name, int $sortOrder = 0, bool $isActive = true): ?array
    {
        try {
            Database::execute(
                'INSERT INTO food_categories (name, sort_order, is_active) VALUES (?, ?, ?)',
                [trim($name), $sortOrder, $isActive ? 1 : 0]
            );
        } catch (\KantEase\DatabaseException $error) {
            if (($error->context()['driver_code'] ?? null) === 1062) {
                return null;
            }

            throw $error;
        }

        return self::findById((int) Database::connection()->lastInsertId());
    }

    public static function rename(int $id, string $name, bool $isActive): bool
    {
        try {
            Database::execute(
                'UPDATE food_categories SET name = ?, is_active = ? WHERE id = ?',
                [trim($name), $isActive ? 1 : 0, $id]
            );
        } catch (\KantEase\DatabaseException $error) {
            if (($error->context()['driver_code'] ?? null) === 1062) {
                return false;
            }

            throw $error;
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // Administration
    //
    // Added in Phase 4B for Admin -> Categories. The read methods above were
    // written in Phase 3 for the menu; these back the management screen.
    // -----------------------------------------------------------------------

    /**
     * Searchable, filterable category list for Admin -> Categories.
     *
     * @param  string $status 'all' | 'active' | 'inactive'
     * @return array{rows: list<array<string, mixed>>, pagination: Pagination}
     */
    public static function paginate(SearchSpec $search, string $status = 'all', int $page = 1, int $perPage = 50): array
    {
        $extra = match ($status) {
            'active'  => 'c.is_active = 1',
            'inactive' => 'c.is_active = 0',
            default   => '',
        };

        [$where, $params] = $search->build($extra === '' ? '' : 'WHERE ' . $extra);

        $total = (int) Database::fetchValue(
            sprintf('SELECT COUNT(*) FROM food_categories c %s', $where),
            $params
        );

        $pager = Pagination::for($total, $page, $perPage);

        $rows = Database::fetchAll(
            sprintf(
                'SELECT c.id, c.name, c.sort_order, c.is_active, c.created_at,
                        (SELECT COUNT(*) FROM food_items f WHERE f.category_id = c.id) AS item_count,
                        (SELECT COUNT(*) FROM food_items f
                          WHERE f.category_id = c.id AND f.is_archived = 0 AND f.is_available = 1) AS menu_count
                   FROM food_categories c
                   %s
                  ORDER BY c.sort_order ASC, c.name ASC
                  %s',
                $where,
                Database::limitClause($pager->perPage, $pager->offset)
            ),
            $params
        );

        return ['rows' => $rows, 'pagination' => $pager];
    }

    /**
     * The searchable fields allowed on the Categories page.
     *
     * Declared here in PHP, so a request can choose WHICH field to search and
     * can never invent a new one. See SearchSpec for why that matters.
     */
    public static function categorySearch(): SearchSpec
    {
        return SearchSpec::make([
            'name'         => 'c.name',
            'date_created' => ['column' => 'c.created_at', 'isDate' => true],
        ]);
    }

    /**
     * Create a category.
     *
     * Returns null when the name is already taken, which the page turns into a
     * readable message rather than a fatal duplicate-key error.
     *
     * @throws ValidationException when the name is empty
     */
    public static function createAs(string $name, bool $isActive, ?int $actorUserId = null): ?array
    {
        $name = trim($name);

        if ($name === '') {
            throw new \KantEase\ValidationException(
                ['name' => 'Enter a category name.'],
                'Enter a category name.'
            );
        }

        if (self::findByName($name) !== null) {
            return null;
        }

        // New categories go to the end of the list rather than the top, so
        // adding one never silently reorders the categories the canteen already
        // relies on. A staff member can move it up afterwards.
        $sortOrder = (int) Database::fetchValue(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM food_categories'
        );

        try {
            $created = self::create($name, $sortOrder, $isActive);
        } catch (\KantEase\DatabaseException $error) {
            if (($error->context()['driver_code'] ?? null) === 1062) {
                return null;
            }

            throw $error;
        }

        if ($created !== null) {
            AuditRepository::log(
                $actorUserId,
                'category.create',
                'food_category',
                (int) $created['id'],
                ['name' => $name]
            );
        }

        return $created;
    }

    /**
     * Rename a category and set whether it is active.
     *
     * @return array<string, mixed>|null null when the name is taken by another
     *                                    category
     * @throws NotFoundException when the category has gone
     */
    public static function updateAs(
        int $id,
        string $name,
        bool $isActive,
        int $sortOrder,
        ?int $actorUserId = null,
    ): ?array {
        $existing = self::findById($id);

        if ($existing === null) {
            throw new NotFoundException('That category no longer exists.');
        }

        $name = trim($name);

        if ($name === '') {
            throw new \KantEase\ValidationException(
                ['name' => 'Enter a category name.'],
                'Enter a category name.'
            );
        }

        // Catch the collision before the write, so the page can report it as a
        // field error instead of relying on the exception path. The UNIQUE key
        // is still the real guarantee; this is the friendly version of it.
        $clash = self::findByName($name);

        if ($clash !== null && (int) $clash['id'] !== $id) {
            return null;
        }

        try {
            Database::execute(
                'UPDATE food_categories SET name = ?, is_active = ?, sort_order = ? WHERE id = ?',
                [$name, $isActive ? 1 : 0, $sortOrder, $id]
            );
        } catch (\KantEase\DatabaseException $error) {
            if (($error->context()['driver_code'] ?? null) === 1062) {
                return null;
            }

            throw $error;
        }

        $wasActive = (int) ($existing['is_active'] ?? 0) === 1;

        if ($wasActive && ! $isActive && self::hasItems($id)) {
            // Not an error, but the reason matters for the audit trail: the
            // products in this category stayed in the database and simply left
            // the student menu.
            AuditRepository::log($actorUserId, 'category.hide_products', 'food_category', $id, [
                'name'   => $name,
                'hidden' => (int) Database::fetchValue(
                    'SELECT COUNT(*) FROM food_items WHERE category_id = ? AND is_archived = 0 AND is_available = 1',
                    [$id]
                ),
            ]);
        }

        AuditRepository::log($actorUserId, 'category.update', 'food_category', $id, [
            'name'       => $name,
            'active'     => $isActive,
            'was_active' => $wasActive,
            'sort_order' => $sortOrder,
        ]);

        return self::findById($id);
    }

    /**
     * Switch a category on or off without touching anything else.
     *
     * The one-row form on the list screen posts here, so turning a category
     * off never has to round-trip through the full edit form.
     *
     * @throws NotFoundException
     */
    public static function setActive(int $id, bool $isActive, ?int $actorUserId = null): array
    {
        $existing = self::findById($id);

        if ($existing === null) {
            throw new NotFoundException('That category no longer exists.');
        }

        Database::execute(
            'UPDATE food_categories SET is_active = ? WHERE id = ?',
            [$isActive ? 1 : 0, $id]
        );

        AuditRepository::log(
            $actorUserId,
            $isActive ? 'category.activate' : 'category.deactivate',
            'food_category',
            $id,
            ['name' => (string) $existing['name']]
        );

        $updated = self::findById($id);

        if ($updated === null) {
            throw new NotFoundException('That category no longer exists.');
        }

        return $updated;
    }

    /**
     * Delete a category that holds no products.
     *
     * @return bool False when products still reference it
     * @throws NotFoundException when the category has gone
     */
    public static function deleteAs(int $id, ?int $actorUserId = null): bool
    {
        $existing = self::findById($id);

        if ($existing === null) {
            throw new NotFoundException('That category no longer exists.');
        }

        if (self::hasItems($id)) {
            return false;
        }

        Database::execute('DELETE FROM food_categories WHERE id = ?', [$id]);

        AuditRepository::log($actorUserId, 'category.delete', 'food_category', $id, [
            'name' => (string) $existing['name'],
        ]);

        return true;
    }

    /**
     * How many categories exist, and how many are switched on.
     *
     * @return array{total: int, active: int}
     */
    public static function counts(): array
    {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(is_active = 1), 0) AS active
               FROM food_categories'
        ) ?? [];

        return [
            'total'  => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
        ];
    }

    /**
     * The page a Categories request is on, honouring ?page=.
     */
    public static function requestedPage(): int
    {
        return max(1, query_int('page', 1));
    }

    /**
     * Refuse a rename onto a name another category already holds.
     *
     * @throws BusinessRuleException
     */
    public static function assertNameFree(string $name, int $exceptId): void
    {
        $clash = self::findByName($name);

        if ($clash !== null && (int) $clash['id'] !== $exceptId) {
            throw new BusinessRuleException(sprintf(
                'There is already a category called "%s". Choose a different name.',
                trim($name)
            ));
        }
    }

    /**
     * Delete a category that holds no products.
     *
     * Kept for the pre-Phase-4B signature; the management screen calls
     * deleteAs(), which also writes the audit row.
     *
     * @return bool False when products still reference it.
     */
    public static function delete(int $id): bool
    {
        if (! self::exists($id)) {
            return false;
        }

        $inUse = (int) Database::fetchValue(
            'SELECT COUNT(*) FROM food_items WHERE category_id = ?',
            [$id]
        );

        if ($inUse > 0) {
            return false;
        }

        Database::execute('DELETE FROM food_categories WHERE id = ?', [$id]);

        return true;
    }

    /**
     * Whether turning this category off would hide products.
     */
    public static function hasItems(int $id): bool
    {
        return (int) Database::fetchValue(
            'SELECT COUNT(*) FROM food_items WHERE category_id = ?',
            [$id]
        ) > 0;
    }
}