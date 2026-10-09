<?php

declare(strict_types=1);

namespace KantEase\Repositories;

use KantEase\Database;

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
    /**
     * Every category, active or not, for the admin inventory screen.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return Database::fetchAll(
            'SELECT c.id, c.name, c.sort_order, c.is_active, c.created_at,
                    (SELECT COUNT(*) FROM food_items f WHERE f.category_id = c.id) AS item_count
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

    /**
     * Delete a category that holds no products.
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