<?php

declare(strict_types=1);

namespace KantEase\Repositories;

use KantEase\BusinessRuleException;
use KantEase\Database;
use KantEase\MovementType;
use KantEase\NotFoundException;
use KantEase\Pagination;
use KantEase\SearchSpec;

use function KantEase\cents_to_amount;

/**
 * Products and stock.
 *
 * The stock methods here carry the most important correctness guarantee in
 * KantEase. The original Node.js build got this right and the approach is
 * preserved exactly:
 *
 *   1. Take a row lock:  SELECT ... FROM food_items WHERE id IN (...) ORDER BY id FOR UPDATE
 *   2. Check the count while the lock is held.
 *   3. Take the stock:  UPDATE food_items SET stock = stock - ? WHERE id = ? AND stock >= ?
 *   4. Treat "0 rows updated" as a failure and roll the whole transaction back.
 *
 * Step 1 means two students racing for the last portion of the same item
 * queue up rather than both reading the same count. Step 3 is a second,
 * independent guard. Because both live in the database rather than in
 * application memory, they hold no matter how many Apache worker processes are
 * serving requests.
 *
 * Sorting the ids before locking is not cosmetic: locking in a consistent
 * order is what stops two orders containing the same two products from
 * deadlocking against each other.
 */
final class FoodRepository
{
    // -----------------------------------------------------------------------
    // Locking
    // -----------------------------------------------------------------------

    /**
     * Lock a set of products for update and return them keyed by id.
     *
     * MUST be called inside a transaction; the lock is released at COMMIT or
     * ROLLBACK.
     *
     * @param  list<int> $foodIds
     * @return array<int, array<string, mixed>> keyed by product id
     * @throws NotFoundException when any id does not exist
     */
    public static function lockForUpdate(array $foodIds): array
    {
        $foodIds = array_values(array_unique(array_map('intval', $foodIds)));
        sort($foodIds);

        if ($foodIds === []) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $locked */
        $locked = [];

        // Lock in small batches so a large order cannot build a huge lock set.
        foreach (array_chunk($foodIds, 50) as $chunk) {
            $sql = sprintf(
                'SELECT id, name, price, stock, low_stock_level, is_available, is_archived, category_id
                   FROM food_items
                  WHERE id IN (%s)
                  ORDER BY id
                  FOR UPDATE',
                Database::placeholders($chunk)
            );

            foreach (Database::fetchAll($sql, $chunk) as $row) {
                $locked[(int) $row['id']] = $row;
            }
        }

        foreach ($foodIds as $foodId) {
            if (! isset($locked[$foodId])) {
                throw new NotFoundException('A product in this order no longer exists. Refresh the page and try again.');
            }
        }

        return $locked;
    }

    // -----------------------------------------------------------------------
    // Stock mutation
    // -----------------------------------------------------------------------

    /**
     * Move a product's stock by $delta and write the ledger row.
     *
     * $delta is negative to take stock (a sale) and positive to return it (a
     * cancellation) or add it (a restock). The product row must already be
     * locked by lockForUpdate() so stock_before is read under the lock and the
     * recorded values cannot go stale.
     *
     * @param  array<string, mixed> $locked    the row from lockForUpdate()
     * @param  int                   $delta     signed change
     * @throws BusinessRuleException when there is not enough stock
     */
    public static function moveStock(
        array $locked,
        int $delta,
        MovementType $type,
        ?int $actorUserId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $note = null,
    ): int {
        $foodId = (int) $locked['id'];
        $before = (int) $locked['stock'];
        $after  = $before + $delta;

        if ($after < 0) {
            throw new BusinessRuleException(sprintf(
                'Not enough stock for %s. There %s %d left.',
                (string) $locked['name'],
                $before === 1 ? 'is' : 'are',
                $before
            ));
        }

        // A zero delta changes nothing, and MySQL reports 0 changed rows for a
        // no-op UPDATE, which the guard below would misread as a conflict.
        if ($delta === 0) {
            return $before;
        }

        // The guarded UPDATE is what makes overselling impossible even if a
        // caller skipped lockForUpdate(). `stock + delta >= 0` is equivalent
        // to the original's `AND stock >= quantity`, while also making a
        // negative count unreachable at the column level.
        $updated = Database::execute(
            'UPDATE food_items SET stock = stock + ? WHERE id = ? AND stock + ? >= 0',
            [$delta, $foodId, $delta]
        );

        if ($updated !== 1) {
            throw new BusinessRuleException(
                'The stock of that product changed while this order was being processed. Please try again.'
            );
        }

        self::recordMovement($foodId, $type, $delta, $before, $after, $actorUserId, $referenceType, $referenceId, $note);

        return $after;
    }

    /**
     * Set a product's stock to an exact value, logging the difference.
     *
     * Used by Admin -> Inventory, where a staff member counts what is actually
     * on the shelf rather than adding to a number.
     *
     * @param  array<string, mixed> $locked
     * @throws BusinessRuleException
     */
    public static function setStock(
        array $locked,
        int $target,
        MovementType $type,
        ?int $actorUserId = null,
        ?string $note = null,
    ): int {
        $foodId = (int) $locked['id'];
        $before = (int) $locked['stock'];

        if ($target < 0) {
            throw new BusinessRuleException('Stock cannot be negative.');
        }

        $delta = $target - $before;

        if ($delta === 0) {
            return $before;
        }

        $updated = Database::execute(
            'UPDATE food_items SET stock = ? WHERE id = ?',
            [$target, $foodId]
        );

        if ($updated !== 1) {
            throw new BusinessRuleException('The stock of that product could not be updated. Please try again.');
        }

        self::recordMovement(
            $foodId,
            $type,
            $delta,
            $before,
            $target,
            $actorUserId,
            'inventory',
            $foodId,
            $note
        );

        return $target;
    }

    /**
     * Append one row to the stock ledger.
     */
    public static function recordMovement(
        int $foodId,
        MovementType $type,
        int $delta,
        int $before,
        int $after,
        ?int $actorUserId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $note = null,
    ): void {
        Database::execute(
            'INSERT INTO inventory_movements
                (food_id, movement_type, quantity_change, stock_before, stock_after,
                 reference_type, reference_id, user_id, note)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $foodId,
                $type->value,
                $delta,
                $before,
                $after,
                $referenceType,
                $referenceId,
                $actorUserId,
                $note !== null ? mb_substr(trim($note), 0, 200) : null,
            ]
        );
    }

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------

    /**
     * The menu a student sees.
     *
     * Sold-out products are included on purpose: a student should be able to
     * see that Siomai exists today and that it has run out, rather than
     * wondering where it went. The order controls are disabled for them.
     *
     * @return list<array<string, mixed>>
     */
    public static function studentMenu(SearchSpec $search): array
    {
        [$where, $params] = $search->build('WHERE f.is_archived = 0 AND f.is_available = 1');

        return Database::fetchAll(
            sprintf(
                'SELECT f.id, f.name, f.description, f.price, f.stock, f.low_stock_level,
                        f.image_path, c.name AS category, c.id AS category_id
                   FROM food_items f
                   JOIN food_categories c ON c.id = f.category_id
                   %s
                  ORDER BY c.sort_order ASC, f.name ASC',
                $where
            ),
            $params
        );
    }

    /**
     * The full inventory, including archived products, for Admin -> Inventory.
     *
     * @return array{rows: list<array<string, mixed>>, pagination: Pagination}
     */
    public static function adminList(SearchSpec $search, int $page = 1, int $perPage = 50, bool $includeArchived = false): array
    {
        $baseWhere = $includeArchived ? '' : 'WHERE f.is_archived = 0';

        [$where, $params] = $search->build($baseWhere);

        $total = (int) Database::fetchValue(
            sprintf('SELECT COUNT(*) FROM food_items f %s', $where),
            $params
        );

        $pager = Pagination::for($total, $page, $perPage);

        $rows = Database::fetchAll(
            sprintf(
                'SELECT f.id, f.name, f.description, f.price, f.stock, f.low_stock_level,
                        f.image_path, f.is_available, f.is_archived, f.created_at,
                        c.name AS category, c.id AS category_id,
                        CASE WHEN f.stock <= f.low_stock_level THEN \'Low\' ELSE \'OK\' END AS stock_status
                   FROM food_items f
                   JOIN food_categories c ON c.id = f.category_id
                   %s
                  ORDER BY c.sort_order ASC, f.name ASC
                  %s',
                $where,
                Database::limitClause($pager->perPage, $pager->offset)
            ),
            $params
        );

        return ['rows' => $rows, 'pagination' => $pager];
    }

    /** @return array<string, mixed>|null */
    public static function findById(int $foodId): ?array
    {
        return Database::fetchOne(
            'SELECT f.id, f.name, f.description, f.price, f.stock, f.low_stock_level,
                    f.image_path, f.is_available, f.is_archived, f.created_at, f.updated_at,
                    c.name AS category, c.id AS category_id
               FROM food_items f
               JOIN food_categories c ON c.id = f.category_id
              WHERE f.id = ?',
            [$foodId]
        );
    }

    /**
     * Products an administrator may add to an order.
     *
     * Archived products are excluded: an order can never reference a product
     * that has been removed from the menu.
     *
     * @return list<array<string, mixed>>
     */
    public static function orderable(): array
    {
        return Database::fetchAll(
            "SELECT id, name, price, stock, category_id
               FROM food_items
              WHERE is_archived = 0
              ORDER BY name ASC"
        );
    }

    /**
     * How many orders reference a product.
     *
     * A product used by any order cannot be deleted, only archived — that is
     * what keeps historical order records intact.
     */
    public static function orderReferenceCount(int $foodId): int
    {
        return (int) Database::fetchValue(
            'SELECT COUNT(*) FROM order_items WHERE food_id = ?',
            [$foodId]
        );
    }

    /**
     * Products at or below their low-stock threshold.
     *
     * @return list<array<string, mixed>>
     */
    public static function lowStock(int $limit = 25): array
    {
        return Database::fetchAll(
            'SELECT f.id, f.name, f.stock, f.low_stock_level, c.name AS category
               FROM food_items f
               JOIN food_categories c ON c.id = f.category_id
              WHERE f.is_archived = 0
                AND f.is_available = 1
                AND f.stock <= f.low_stock_level
              ORDER BY f.stock ASC, f.name ASC
              LIMIT ' . Database::limitClause($limit)
        );
    }

    public static function countLowStock(): int
    {
        return (int) Database::fetchValue(
            'SELECT COUNT(*)
               FROM food_items
              WHERE is_archived = 0 AND is_available = 1 AND stock <= low_stock_level'
        );
    }

    public static function countMenuItems(): int
    {
        return (int) Database::fetchValue(
            'SELECT COUNT(*) FROM food_items WHERE is_archived = 0 AND is_available = 1'
        );
    }

    /**
     * A product matching the low-stock rule, for a "notify me" style hint.
     */
    public static function isLowStock(int $foodId): bool
    {
        $row = Database::fetchOne(
            'SELECT stock, low_stock_level FROM food_items WHERE id = ?',
            [$foodId]
        );

        return $row !== null && (int) $row['stock'] <= (int) $row['low_stock_level'];
    }

    // -----------------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------------

    /**
     * Create a product and record its opening stock in the ledger.
     */
    public static function create(
        int $categoryId,
        string $name,
        string $description,
        int $priceCents,
        int $stock,
        int $lowStockLevel,
        bool $isAvailable = true,
        ?string $imagePath = null,
        ?int $actorUserId = null,
    ): int {
        return Database::transaction(static function () use (
            $categoryId, $name, $description, $priceCents, $stock, $lowStockLevel, $isAvailable, $imagePath, $actorUserId
        ): int {
            Database::execute(
                'INSERT INTO food_items
                    (category_id, name, description, price, stock, low_stock_level, is_available, image_path)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $categoryId,
                    $name,
                    $description !== '' ? $description : null,
                    cents_to_amount($priceCents),
                    $stock,
                    $lowStockLevel,
                    $isAvailable ? 1 : 0,
                    $imagePath,
                ]
            );

            $foodId = (int) Database::connection()->lastInsertId();

            if ($stock > 0) {
                self::recordMovement(
                    $foodId,
                    MovementType::Create,
                    $stock,
                    0,
                    $stock,
                    $actorUserId,
                    'inventory',
                    $foodId,
                    'Opening stock'
                );
            }

            AuditRepository::log($actorUserId, 'inventory.create', 'food_item', $foodId, [
                'name'  => $name,
                'stock' => $stock,
            ]);

            return $foodId;
        });
    }

    /**
     * Edit a product.
     *
     * Changing the stock here goes through setStock() so it is recorded in the
     * ledger, not applied as a silent overwrite.
     *
     * @param array<string, mixed> $changes
     */
    public static function update(int $foodId, array $changes, ?int $actorUserId = null, ?string $note = null): void
    {
        Database::transaction(static function () use ($foodId, $changes, $actorUserId, $note): void {
            $locked = self::lockForUpdate([$foodId]);

            $sets   = [];
            $params = [];

            if (array_key_exists('name', $changes)) {
                $sets[]   = 'name = ?';
                $params[] = $changes['name'];
            }

            if (array_key_exists('description', $changes)) {
                $sets[]   = 'description = ?';
                $params[] = $changes['description'] !== '' ? $changes['description'] : null;
            }

            if (array_key_exists('price_cents', $changes)) {
                $sets[]   = 'price = ?';
                $params[] = cents_to_amount((int) $changes['price_cents']);
            }

            if (array_key_exists('category_id', $changes)) {
                $sets[]   = 'category_id = ?';
                $params[] = (int) $changes['category_id'];
            }

            if (array_key_exists('low_stock_level', $changes)) {
                $sets[]   = 'low_stock_level = ?';
                $params[] = (int) $changes['low_stock_level'];
            }

            if (array_key_exists('is_available', $changes)) {
                $sets[]   = 'is_available = ?';
                $params[] = $changes['is_available'] ? 1 : 0;
            }

            if (array_key_exists('image_path', $changes)) {
                $sets[]   = 'image_path = ?';
                $params[] = $changes['image_path'];
            }

            if ($sets !== []) {
                $params[] = $foodId;
                Database::execute(
                    sprintf('UPDATE food_items SET %s WHERE id = ?', implode(', ', $sets)),
                    $params
                );
            }

            if (array_key_exists('stock', $changes)) {
                $type = MovementType::tryFrom((string) ($changes['movement_type'] ?? MovementType::Adjustment->value))
                    ?? MovementType::Adjustment;

                self::setStock($locked[$foodId], (int) $changes['stock'], $type, $actorUserId, $note);
            }

            AuditRepository::log($actorUserId, 'inventory.update', 'food_item', $foodId, [
                'name'    => $changes['name'] ?? $locked[$foodId]['name'],
                'changes' => array_keys($changes),
            ]);
        });
    }

    /**
     * Archive or restore a product.
     *
     * Archiving is preferred over deletion because order_items keeps a
     * foreign key to this row; deleting would set it to NULL. Archiving keeps
     * the menu tidy while leaving every historical order readable.
     */
    public static function setArchived(int $foodId, bool $archived, ?int $actorUserId = null): void
    {
        Database::transaction(static function () use ($foodId, $archived, $actorUserId): void {
            $locked = self::lockForUpdate([$foodId]);

            if ($archived) {
                $openOrders = (int) Database::fetchValue(
                    "SELECT COUNT(DISTINCT oi.order_id)
                       FROM order_items oi
                       JOIN orders o ON o.id = oi.order_id
                      WHERE oi.food_id = ?
                        AND o.status IN ('Pending','Preparing','Ready')",
                    [$foodId]
                );

                if ($openOrders > 0) {
                    throw new BusinessRuleException(sprintf(
                        'This product is still on %d order%s that %s not finished. Finish or cancel %s first.',
                        $openOrders,
                        $openOrders === 1 ? '' : 's',
                        $openOrders === 1 ? 'has' : 'have',
                        $openOrders === 1 ? 'it' : 'them'
                    ));
                }
            }

            Database::execute(
                'UPDATE food_items SET is_archived = ?, is_available = ? WHERE id = ?',
                [$archived ? 1 : 0, $archived ? 0 : 1, $foodId]
            );

            AuditRepository::log($actorUserId, $archived ? 'inventory.archive' : 'inventory.restore', 'food_item', $foodId, [
                'name' => $locked[$foodId]['name'],
            ]);
        });
    }

    /**
     * Permanently delete a product.
     *
     * Refused once any order references it, exactly as the original did.
     */
    public static function delete(int $foodId, ?int $actorUserId = null): void
    {
        Database::transaction(static function () use ($foodId, $actorUserId): void {
            $item = Database::fetchOne('SELECT id, name FROM food_items WHERE id = ? FOR UPDATE', [$foodId]);

            if ($item === null) {
                throw new NotFoundException('That product no longer exists.');
            }

            $references = self::orderReferenceCount($foodId);

            if ($references > 0) {
                throw new BusinessRuleException(sprintf(
                    'This product appears on %d order line%s and cannot be deleted. Archive it instead — that hides it from the menu while keeping the order history intact.',
                    $references,
                    $references === 1 ? '' : 's'
                ));
            }

            Database::execute('DELETE FROM food_items WHERE id = ?', [$foodId]);

            AuditRepository::log($actorUserId, 'inventory.delete', 'food_item', $foodId, [
                'name' => $item['name'],
            ]);
        });
    }

    /**
     * The movement history for one product.
     *
     * @return list<array<string, mixed>>
     */
    public static function movementHistory(int $foodId, int $limit = 30): array
    {
        return Database::fetchAll(
            'SELECT m.id, m.movement_type, m.quantity_change, m.stock_before, m.stock_after,
                    m.reference_type, m.note, m.created_at,
                    u.user_code, u.full_name
               FROM inventory_movements m
               LEFT JOIN users u ON u.id = m.user_id
              WHERE m.food_id = ?
              ORDER BY m.created_at DESC, m.id DESC
              LIMIT ' . Database::limitClause($limit),
            [$foodId]
        );
    }

    /**
     * Check whether a name is already used by another product.
     */
    public static function nameTaken(string $name, ?int $exceptId = null): bool
    {
        $sql    = 'SELECT 1 FROM food_items WHERE name = ?';
        $params = [trim($name)];

        if ($exceptId !== null) {
            $sql     .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        return Database::fetchValue($sql, $params) !== null;
    }
}