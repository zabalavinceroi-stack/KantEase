<?php

declare(strict_types=1);

namespace KantEase\Repositories;

use KantEase\AuthorizationException;
use KantEase\BusinessRuleException;
use KantEase\Config;
use KantEase\Database;
use KantEase\MovementType;
use KantEase\NotFoundException;
use KantEase\OrderStatus;
use KantEase\Pagination;
use KantEase\PaymentStatus;
use KantEase\SearchSpec;
use KantEase\ValidationException;
use Throwable;

use function KantEase\cents_to_amount;
use function KantEase\paginate;
use function KantEase\to_cents;
use function KantEase\utc_now;

/**
 * Orders: placing, listing, processing, cancelling and payment.
 *
 * INVENTORY POLICY — unchanged from the original build and now written down.
 * Stock is deducted at the moment an order is placed, inside the same
 * transaction that creates the order. If the order is cancelled, the same
 * quantities are returned exactly once. An order that is completed or merely
 * abandoned keeps its stock consumed, because the food was made.
 *
 * Every write path runs inside a transaction that begins by locking the order
 * row FOR UPDATE. That single lock is what makes the following guarantees
 * hold under concurrent requests from several Apache workers:
 *
 *   - A cancelled order cannot be cancelled twice (DEF: double restoration).
 *   - A completed order cannot be completed twice.
 *   - Two administrators cannot interleave edits of the same order.
 *   - A payment can be recorded exactly once (also enforced by a UNIQUE key
 *     on payments.reference).
 */
final class OrderRepository
{
    // =======================================================================
    // Placing an order
    // =======================================================================

    /**
     * Create an order for a student.
     *
     * @param list<array{food_id: int, quantity: int}> $items
     * @return array{
     *     order: array<string, mixed>,
     *     lines: list<array<string, mixed>>,
     *     total_cents: int,
     *     replayed: bool
     * }
     * @throws ValidationException
     * @throws BusinessRuleException
     */
    public static function place(int $studentUserId, array $items, ?string $note, ?string $idempotencyKey): array
    {
        $quantities = self::normaliseRequestedItems($items);

        // Idempotency: if this exact checkout has already been processed, hand
        // back the existing order rather than creating a second one.
        if ($idempotencyKey !== null) {
            $existing = self::findByIdempotencyKey($idempotencyKey, $studentUserId);

            if ($existing !== null) {
                return [
                    'order'        => $existing,
                    'lines'        => self::items((int) $existing['id']),
                    'total_cents'  => to_cents((string) $existing['total_amount']),
                    'replayed'     => true,
                ];
            }
        }

        return Database::transaction(static function () use (
            $studentUserId, $quantities, $note, $idempotencyKey
        ): array {
            // Re-check inside the transaction: a concurrent replay of the same
            // request may have committed while this one was waiting.
            if ($idempotencyKey !== null) {
                $existing = self::findByIdempotencyKey($idempotencyKey, $studentUserId);

                if ($existing !== null) {
                    return [
                        'order'       => $existing,
                        'lines'       => self::items((int) $existing['id']),
                        'total_cents' => to_cents((string) $existing['total_amount']),
                        'replayed'    => true,
                    ];
                }
            }

            $student = Database::fetchOne('SELECT id, role, is_active FROM users WHERE id = ?', [$studentUserId]);

            if ($student === null || (string) $student['role'] !== 'student' || (int) $student['is_active'] !== 1) {
                throw new AuthorizationException('Only an active student account can place an order.');
            }

            // Lock every product in ascending id order. Consistent lock order
            // is what stops two orders sharing products from deadlocking.
            $foodIds = array_keys($quantities);
            sort($foodIds);
            $locked = FoodRepository::lockForUpdate($foodIds);

            // Price and stock are re-read here from the locked rows. Nothing
            // the browser sent about price or availability is trusted.
            $lines       = [];
            $totalCents  = 0;

            foreach ($foodIds as $foodId) {
                $food       = $locked[$foodId];
                $quantity   = $quantities[$foodId];
                $priceCents = to_cents((string) $food['price']);

                if ((int) $food['is_archived'] === 1 || (int) $food['is_available'] !== 1) {
                    throw new BusinessRuleException(sprintf(
                        '%s is not on the menu right now. Please remove it from your order.',
                        (string) $food['name']
                    ));
                }

                $available = (int) $food['stock'];

                if ($available < $quantity) {
                    throw new BusinessRuleException(sprintf(
                        $available === 0
                            ? '%s has just sold out. Please remove it from your order.'
                            : 'Only %d of %s left. Please reduce the quantity.',
                        (string) $food['name']
                    ));
                }

                $subtotalCents = $priceCents * $quantity;
                $totalCents   += $subtotalCents;

                $lines[] = [
                    'food_id'     => $foodId,
                    'name'        => (string) $food['name'],
                    'quantity'    => $quantity,
                    'price_cents' => $priceCents,
                    'subtotal'    => $subtotalCents,
                ];
            }

            // DECIMAL(10,2) tops out at 99,999,999.99.
            if ($totalCents > 9_999_999_999) {
                throw new ValidationException(
                    ['items' => 'That order is too large. Please contact the canteen.'],
                    'That order is too large. Please contact the canteen.'
                );
            }

            $orderNumber = self::nextOrderNumber();

            Database::execute(
                'INSERT INTO orders (order_number, idempotency_key, user_id, total_amount, status, payment_status, note)
                      VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $orderNumber,
                    $idempotencyKey,
                    $studentUserId,
                    cents_to_amount($totalCents),
                    OrderStatus::Pending->value,
                    PaymentStatus::Unpaid->value,
                    $note !== null && $note !== '' ? $note : null,
                ]
            );

            $orderId = (int) Database::connection()->lastInsertId();

            foreach ($lines as $line) {
                Database::execute(
                    'INSERT INTO order_items (order_id, food_id, item_name, quantity, unit_price, subtotal)
                          VALUES (?, ?, ?, ?, ?, ?)',
                    [
                        $orderId,
                        $line['food_id'],
                        $line['name'],
                        $line['quantity'],
                        cents_to_amount($line['price_cents']),
                        cents_to_amount($line['subtotal']),
                    ]
                );

                FoodRepository::moveStock(
                    $locked[$line['food_id']],
                    -$line['quantity'],
                    MovementType::Sale,
                    $studentUserId,
                    'order',
                    $orderId,
                    sprintf('%s placed order %s', $orderNumber, $orderNumber)
                );
            }

            self::recordStatusChange($orderId, null, OrderStatus::Pending, $studentUserId, 'Order placed.');

            AuditRepository::log($studentUserId, 'order.place', 'order', $orderId, [
                'order_number' => $orderNumber,
                'total'        => cents_to_amount($totalCents),
                'lines'        => count($lines),
            ]);

            $order = self::findById($orderId);

            if ($order === null) {
                throw new BusinessRuleException('The order was saved but could not be read back. Please contact the canteen.');
            }

            return [
                'order'       => $order,
                'lines'       => self::items($orderId),
                'total_cents' => $totalCents,
                'replayed'    => false,
            ];
        });
    }

    /**
     * Validate and collapse the requested lines.
     *
     * @param  list<array<string, mixed>> $items
     * @return array<int, int> food id => quantity
     * @throws ValidationException
     */
    private static function normaliseRequestedItems(array $items): array
    {
        $maxItems     = Config::int('orders.max_distinct_items', 20);
        $maxQuantity  = Config::int('orders.max_quantity_per_item', 100);

        if ($items === []) {
            throw new ValidationException(
                ['items' => 'Choose at least one item before placing your order.'],
                'Choose at least one item before placing your order.'
            );
        }

        if (count($items) > $maxItems) {
            throw new ValidationException(
                ['items' => sprintf('An order can contain at most %d different items.', $maxItems)],
                sprintf('An order can contain at most %d different items.', $maxItems)
            );
        }

        $quantities = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new ValidationException(
                    ['items' => 'Your order contains an item that could not be read.'],
                    'Your order contains an item that could not be read.'
                );
            }

            $foodId   = $item['food_id'] ?? null;
            $quantity = $item['quantity'] ?? null;

            $validFoodId = is_int($foodId) || (is_string($foodId) && preg_match('/^\d+$/', $foodId) === 1);
            $validQty    = is_int($quantity) || (is_string($quantity) && preg_match('/^\d+$/', $quantity) === 1);

            if (! $validFoodId || ! $validQty) {
                throw new ValidationException(
                    ['items' => 'Your order contains an item that could not be read. Please rebuild your cart.'],
                    'Your order contains an item that could not be read. Please rebuild your cart.'
                );
            }

            $foodId   = (int) $foodId;
            $quantity = (int) $quantity;

            if ($foodId < 1 || $quantity < 1 || $quantity > $maxQuantity) {
                throw new ValidationException(
                    ['items' => sprintf('Each item must have a quantity from 1 to %d.', $maxQuantity)],
                    sprintf('Each item must have a quantity from 1 to %d.', $maxQuantity)
                );
            }

            if (isset($quantities[$foodId])) {
                // Merge rather than reject: two rows of the same product are
                // the same intent, and the stock check will still catch a
                // request that is asking for more than exists.
                $quantities[$foodId] += $quantity;

                if ($quantities[$foodId] > $maxQuantity) {
                    throw new ValidationException(
                        ['items' => sprintf('The quantity for one item must be %d or fewer.', $maxQuantity)],
                        sprintf('The quantity for one item must be %d or fewer.', $maxQuantity)
                    );
                }

                continue;
            }

            $quantities[$foodId] = $quantity;
        }

        if (count($quantities) > $maxItems) {
            throw new ValidationException(
                ['items' => sprintf('An order can contain at most %d different items.', $maxItems)],
                sprintf('An order can contain at most %d different items.', $maxItems)
            );
        }

        return $quantities;
    }

    // =======================================================================
    // Reading
    // =======================================================================

    /** @return array<string, mixed>|null */
    public static function findById(int $orderId): ?array
    {
        return Database::fetchOne(
            'SELECT o.*, u.user_code, u.full_name, u.email
               FROM orders o
               JOIN users u ON u.id = o.user_id
              WHERE o.id = ?',
            [$orderId]
        );
    }

    /**
     * Find an order by its public reference.
     *
     * @return array<string, mixed>|null
     */
    public static function findByNumber(string $orderNumber): ?array
    {
        return Database::fetchOne(
            'SELECT o.*, u.user_code, u.full_name, u.email
               FROM orders o
               JOIN users u ON u.id = o.user_id
              WHERE o.order_number = ?',
            [trim($orderNumber)]
        );
    }

    /**
     * Find a student's own order.
     *
     * Scoped by user_id in the WHERE clause rather than checked afterwards, so
     * a student probing order numbers gets the same 404 as a real one.
     *
     * @return array<string, mixed>|null
     */
    public static function findForStudent(int $orderId, int $studentUserId): ?array
    {
        return Database::fetchOne(
            'SELECT o.*, u.user_code, u.full_name, u.email
               FROM orders o
               JOIN users u ON u.id = o.user_id
              WHERE o.id = ? AND o.user_id = ?',
            [$orderId, $studentUserId]
        );
    }

    /** @return list<array<string, mixed>> */
    public static function items(int $orderId): array
    {
        return Database::fetchAll(
            'SELECT id, food_id, item_name, quantity, unit_price, subtotal
               FROM order_items
              WHERE order_id = ?
              ORDER BY id ASC',
            [$orderId]
        );
    }

    /**
     * Fetch an order and its lines, or fail.
     *
     * @return array{order: array<string, mixed>, items: list<array<string, mixed>>}
     * @throws NotFoundException
     */
    public static function detail(int $orderId): array
    {
        $order = self::findById($orderId);

        if ($order === null) {
            throw new NotFoundException('That order could not be found.');
        }

        return ['order' => $order, 'items' => self::items($orderId)];
    }

    public static function countForUser(int $userId): int
    {
        return (int) Database::fetchValue('SELECT COUNT(*) FROM orders WHERE user_id = ?', [$userId]);
    }

    /**
     * Whether any line of an order no longer links to a product.
     *
     * The original refused to change stock for such an order, because there
     * was no longer a stock record to put the units back into. That
     * restriction is preserved.
     */
    public static function hasUnlinkedItems(int $orderId): bool
    {
        return Database::fetchValue(
            'SELECT 1 FROM order_items WHERE order_id = ? AND food_id IS NULL LIMIT 1',
            [$orderId]
        ) !== null;
    }

    /** @return array<string, mixed>|null */
    public static function findByIdempotencyKey(string $key, int $userId): ?array
    {
        return Database::fetchOne(
            'SELECT o.*, u.user_code, u.full_name, u.email
               FROM orders o
               JOIN users u ON u.id = o.user_id
              WHERE o.idempotency_key = ? AND o.user_id = ?',
            [$key, $userId]
        );
    }

    // =======================================================================
    // Listing
    // =======================================================================

    /**
     * A student's own orders, newest first.
     *
     * @return array{rows: list<array<string, mixed>>, pagination: Pagination}
     */
    public static function paginateForStudent(int $studentUserId, SearchSpec $search, int $page = 1, int $perPage = 15): array
    {
        [$where, $params] = $search->build('WHERE o.user_id = ?', [$studentUserId]);

        $total = (int) Database::fetchValue(sprintf('SELECT COUNT(*) FROM orders o %s', $where), $params);

        $pager = Pagination::for($total, $page, $perPage);

        $rows = Database::fetchAll(
            sprintf(
                'SELECT o.id, o.order_number, o.total_amount, o.status, o.payment_status,
                        o.order_date, o.updated_at, o.cancelled_at, o.cancel_reason,
                        (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS line_count
                   FROM orders o
                   %s
                  ORDER BY o.order_date DESC, o.id DESC
                  %s',
                $where,
                Database::limitClause($pager->perPage, $pager->offset)
            ),
            $params
        );

        return ['rows' => $rows, 'pagination' => $pager];
    }

    /**
     * All orders for the administrator's list.
     *
     * @param array{status?: string, payment?: string, search?: SearchSpec} $filters
     * @return array{rows: list<array<string, mixed>>, pagination: Pagination, totals: array<string, int>}
     */
    public static function paginateForAdmin(array $filters, int $page = 1, int $perPage = 25): array
    {
        $search  = $filters['search'] ?? self::adminSearch();
        $clauses = [];
        $params  = [];

        // Status and payment filters come from the query string, so they are
        // parsed through the enum rather than passed straight to SQL. An
        // unrecognised value means "no filter", never a raw fragment.
        $statusFilter = OrderStatus::tryFromInput($filters['status'] ?? null);
        $payFilter    = PaymentStatus::tryFromInput($filters['payment'] ?? null);

        if ($statusFilter !== null) {
            $clauses[] = 'o.status = ?';
            $params[]  = $statusFilter->value;
        }

        if ($payFilter !== null) {
            $clauses[] = 'o.payment_status = ?';
            $params[]  = $payFilter->value;
        }

        // SearchSpec::build() returns a complete "WHERE ..." clause or ''.
        // Take exactly the six characters off rather than ltrim(), whose
        // character set would happily eat into a following identifier.
        [$where, $searchParams] = $search->build();

        if ($where !== '') {
            $clauses[] = substr($where, 6);
        }

        $combinedWhere = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);
        $combinedParams = [...$searchParams, ...$params];

        $totals = self::adminListTotals($combinedWhere, $combinedParams);

        $pager = Pagination::for(
            $totals['orders'],
            $page,
            $perPage
        );

        $rows = Database::fetchAll(
            sprintf(
                'SELECT o.id, o.order_number, o.total_amount, o.status, o.payment_status,
                        o.order_date, o.cancelled_at, o.cancel_reason,
                        u.id AS student_id, u.user_code AS student_user_code, u.full_name AS student_name,
                        (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS line_count,
                        (SELECT COUNT(*) FROM order_items oi
                          WHERE oi.order_id = o.id AND oi.food_id IS NULL) AS unlinked_line_count,
                        (SELECT GROUP_CONCAT(CONCAT(oi.item_name, \' x\', oi.quantity)
                                             ORDER BY oi.id SEPARATOR \', \')
                           FROM order_items oi WHERE oi.order_id = o.id) AS item_summary
                   FROM orders o
                   JOIN users u ON u.id = o.user_id
                   %s
                  ORDER BY o.order_date DESC, o.id DESC
                  %s',
                $combinedWhere,
                Database::limitClause($pager->perPage, $pager->offset)
            ),
            $combinedParams
        );

        return ['rows' => $rows, 'pagination' => $pager, 'totals' => $totals];
    }

    /**
     * Counts and totals for the administrator's order list header.
     *
     * @param array<int, mixed> $params
     * @return array{orders: int, pending: int, preparing: int, ready: int, completed: int, cancelled: int, unpaid: int, paid: int, revenue_cents: int, value_cents: int}
     */
    private static function adminListTotals(string $where, array $params): array
    {
        $row = Database::fetchOne(
            sprintf(
                'SELECT COUNT(*) AS orders,
                        SUM(status = \'Pending\')   AS pending,
                        SUM(status = \'Preparing\') AS preparing,
                        SUM(status = \'Ready\')     AS ready,
                        SUM(status = \'Completed\') AS completed,
                        SUM(status = \'Cancelled\') AS cancelled,
                        SUM(payment_status = \'Unpaid\') AS unpaid,
                        SUM(payment_status = \'Paid\')   AS paid,
                        COALESCE(SUM(CASE WHEN status <> \'Cancelled\' AND payment_status = \'Paid\'   THEN total_amount ELSE 0 END), 0) AS revenue_cents,
                        COALESCE(SUM(CASE WHEN status <> \'Cancelled\'                             THEN total_amount ELSE 0 END), 0) AS value_cents
                   FROM orders o
                   %s',
                $where
            ),
            $params
        ) ?? [];

        return [
            'orders'       => (int) ($row['orders'] ?? 0),
            'pending'      => (int) ($row['pending'] ?? 0),
            'preparing'    => (int) ($row['preparing'] ?? 0),
            'ready'        => (int) ($row['ready'] ?? 0),
            'completed'    => (int) ($row['completed'] ?? 0),
            'cancelled'    => (int) ($row['cancelled'] ?? 0),
            'unpaid'       => (int) ($row['unpaid'] ?? 0),
            'paid'         => (int) ($row['paid'] ?? 0),
            'revenue_cents'=> to_cents((string) ($row['revenue_cents'] ?? '0')),
            'value_cents'  => to_cents((string) ($row['value_cents'] ?? '0')),
        ];
    }

    /**
     * The searchable fields allowed on the admin order list.
     *
     * Preserved from the original allow-list, with order_number now matching
     * the human reference instead of the raw auto-increment id.
     */
    public static function adminSearch(): SearchSpec
    {
        return SearchSpec::make([
            'order_number' => 'o.order_number',
            'student_id'   => 'u.user_code',
            'student_name' => 'u.full_name',
            'item_name'    => '(SELECT GROUP_CONCAT(oi.item_name ORDER BY oi.id SEPARATOR \', \') FROM order_items oi WHERE oi.order_id = o.id)',
            'total_amount' => 'o.total_amount',
            'status'       => 'o.status',
            'payment'      => 'o.payment_status',
            'date'         => ['column' => 'o.order_date', 'isDate' => true],
        ], 'o.order_date');
    }

    /**
     * The searchable fields allowed on the student's own order list.
     */
    public static function studentSearch(): SearchSpec
    {
        return SearchSpec::make([
            'order_number' => 'o.order_number',
            'status'       => 'o.status',
            'payment'      => 'o.payment_status',
            'date'         => ['column' => 'o.order_date', 'isDate' => true],
            'total_amount' => 'o.total_amount',
            'item_name'    => '(SELECT GROUP_CONCAT(oi.item_name ORDER BY oi.id SEPARATOR \', \') FROM order_items oi WHERE oi.order_id = o.id)',
        ], 'o.order_date');
    }

    /**
     * A student's dashboard figures.
     *
     * Computed in SQL rather than by summing rows in the browser, and — unlike
     * the original, which added up every order including cancelled and unpaid
     * ones — the money figures exclude cancelled orders.
     *
     * @return array<string, mixed>
     */
    public static function studentSummary(int $studentUserId): array
    {
        $row = Database::fetchOne(
            "SELECT COUNT(*) AS total_orders,
                    SUM(status = 'Pending')   AS pending,
                    SUM(status = 'Preparing') AS preparing,
                    SUM(status = 'Ready')     AS ready,
                    SUM(status = 'Completed') AS completed,
                    SUM(status = 'Cancelled') AS cancelled,
                    COALESCE(SUM(CASE WHEN status <> 'Cancelled' THEN total_amount ELSE 0 END), 0) AS order_value,
                    COALESCE(SUM(CASE WHEN status <> 'Cancelled' AND payment_status = 'Paid' THEN total_amount ELSE 0 END), 0) AS paid_total,
                    COALESCE(SUM(payment_status = 'Paid' AND status <> 'Cancelled'), 0) AS paid_orders,
                    MAX(order_date) AS last_order_at
               FROM orders
              WHERE user_id = ?",
            [$studentUserId]
        ) ?? [];

        return [
            'total_orders'  => (int) ($row['total_orders'] ?? 0),
            'pending'       => (int) ($row['pending'] ?? 0),
            'preparing'     => (int) ($row['preparing'] ?? 0),
            'ready'         => (int) ($row['ready'] ?? 0),
            'completed'     => (int) ($row['completed'] ?? 0),
            'cancelled'     => (int) ($row['cancelled'] ?? 0),
            'order_value'   => to_cents((string) ($row['order_value'] ?? '0')),
            'paid_total'    => to_cents((string) ($row['paid_total'] ?? '0')),
            'paid_orders'   => (int) ($row['paid_orders'] ?? 0),
            'last_order_at' => $row['last_order_at'] ?? null,
        ];
    }

    /**
     * Status counts across every order, for the admin dashboard.
     *
     * @return array<string, int>
     */
    public static function statusCounts(): array
    {
        $row = Database::fetchOne(
            "SELECT SUM(status = 'Pending')   AS pending,
                    SUM(status = 'Preparing') AS preparing,
                    SUM(status = 'Ready')     AS ready,
                    SUM(status = 'Completed') AS completed,
                    SUM(status = 'Cancelled') AS cancelled,
                    COUNT(*)                   AS total
               FROM orders"
        ) ?? [];

        return [
            'Pending'   => (int) ($row['pending'] ?? 0),
            'Preparing' => (int) ($row['preparing'] ?? 0),
            'Ready'     => (int) ($row['ready'] ?? 0),
            'Completed' => (int) ($row['completed'] ?? 0),
            'Cancelled' => (int) ($row['cancelled'] ?? 0),
            'Total'     => (int) ($row['total'] ?? 0),
        ];
    }

    /**
     * Orders the student should be able to act on, for the dashboard banner.
     *
     * @return list<array<string, mixed>>
     */
    public static function recentForStudent(int $studentUserId, int $limit = 5): array
    {
        return Database::fetchAll(
            sprintf(
                'SELECT id, order_number, total_amount, status, payment_status, order_date,
                        (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = orders.id) AS line_count
                   FROM orders
                  WHERE user_id = ?
                  ORDER BY order_date DESC, id DESC
                  LIMIT %s',
                Database::limitClause($limit)
            ),
            [$studentUserId]
        );
    }

    /**
     * The newest orders across all students, for the admin dashboard.
     *
     * @return list<array<string, mixed>>
     */
    public static function recentForAdmin(int $limit = 8): array
    {
        return Database::fetchAll(
            sprintf(
                'SELECT o.id, o.order_number, o.total_amount, o.status, o.payment_status, o.order_date,
                        u.user_code AS student_user_code, u.full_name AS student_name
                   FROM orders o
                   JOIN users u ON u.id = o.user_id
                  ORDER BY o.order_date DESC, o.id DESC
                  LIMIT %s',
                Database::limitClause($limit)
            )
        );
    }

    /**
     * Lightweight status polling for the student's My Orders page.
     *
     * Returns only what changed since the client last looked, so polling does
     * not re-download the whole list every few seconds.
     *
     * @param  list<int> $sinceOrderIds
     * @return list<array<string, mixed>>
     */
    public static function statusUpdatesForStudent(int $studentUserId, array $sinceOrderIds = []): array
    {
        $sinceOrderIds = array_values(array_filter(array_map('intval', $sinceOrderIds)));

        $sql = 'SELECT id, order_number, status, payment_status, updated_at
                  FROM orders
                 WHERE user_id = ?';

        $params = [$studentUserId];

        if ($sinceOrderIds !== []) {
            $sql     .= sprintf(' AND id NOT IN (%s)', Database::placeholders($sinceOrderIds));
            $params  = [...$params, ...$sinceOrderIds];
        }

        return Database::fetchAll($sql . ' ORDER BY updated_at DESC', $params);
    }

    /**
     * The full status timeline for one order.
     *
     * @return list<array<string, mixed>>
     */
    public static function history(int $orderId): array
    {
        return Database::fetchAll(
            'SELECT h.from_status, h.to_status, h.note, h.created_at,
                    u.user_code, u.full_name
               FROM order_status_history h
               LEFT JOIN users u ON u.id = h.changed_by
              WHERE h.order_id = ?
              ORDER BY h.created_at ASC, h.id ASC',
            [$orderId]
        );
    }

    // =======================================================================
    // Processing
    // =======================================================================

    /**
     * Move an order to a new status.
     *
     * Illegal transitions are rejected before anything is written, and the
     * whole operation runs inside a transaction that holds a FOR UPDATE lock
     * on the order row for its duration.
     *
     * @throws BusinessRuleException
     * @throws NotFoundException
     */
    public static function transition(int $orderId, OrderStatus $target, int $actingAdminId, ?string $note = null): void
    {
        Database::transaction(static function () use ($orderId, $target, $actingAdminId, $note): void {
            $order = Database::fetchOne(
                'SELECT id, order_number, status, payment_status, total_amount FROM orders WHERE id = ? FOR UPDATE',
                [$orderId]
            );

            if ($order === null) {
                throw new NotFoundException('That order no longer exists.');
            }

            $current = OrderStatus::from((string) $order['status']);

            if (! $current->canTransitionTo($target)) {
                throw new BusinessRuleException($current->rejectionReason($target));
            }

            if ($target === OrderStatus::Cancelled) {
                throw new BusinessRuleException('Use the cancel action so a reason is recorded.');
            }

            $stageColumn = match ($target) {
                OrderStatus::Preparing => 'prepared_at',
                OrderStatus::Ready     => 'ready_at',
                OrderStatus::Completed => 'completed_at',
                default                => null,
            };

            $sets   = ['status = ?'];
            $params = [$target->value];

            if ($stageColumn !== null) {
                $sets[]   = sprintf('%s = COALESCE(%s, ?)', $stageColumn, $stageColumn);
                $params[] = utc_now();
            }

            if ($note !== null && $note !== '') {
                $sets[]   = 'note = ?';
                $params[] = mb_substr($note, 0, 200);
            }

            $params[] = $orderId;

            Database::execute(sprintf('UPDATE orders SET %s WHERE id = ?', implode(', ', $sets)), $params);

            self::recordStatusChange($orderId, $current, $target, $actingAdminId, $note);

            AuditRepository::log($actingAdminId, 'order.status', 'order', $orderId, [
                'order_number' => $order['order_number'],
                'from'         => $current->value,
                'to'           => $target->value,
            ]);
        });
    }

    /**
     * Cancel an order and return its stock exactly once.
     *
     * The guarantee rests on three things together:
     *   1. The order row is locked FOR UPDATE before the status is read, so
     *      two simultaneous cancel requests serialise.
     *   2. The status is re-read inside the lock and a Cancelled order is
     *      rejected, so only the first request proceeds.
     *   3. The whole thing is one transaction, so the stock rows and the order
     *      row commit together or not at all.
     *
     * @throws BusinessRuleException
     * @throws NotFoundException
     */
    public static function cancel(int $orderId, string $reason, int $actingAdminId): void
    {
        Database::transaction(static function () use ($orderId, $reason, $actingAdminId): void {
            $order = Database::fetchOne(
                'SELECT id, order_number, status, payment_status FROM orders WHERE id = ? FOR UPDATE',
                [$orderId]
            );

            if ($order === null) {
                throw new NotFoundException('That order no longer exists.');
            }

            $current = OrderStatus::from((string) $order['status']);

            if ($current === OrderStatus::Cancelled) {
                throw new BusinessRuleException('This order was already cancelled, so its stock has already been returned.');
            }

            if ($current === OrderStatus::Completed) {
                throw new BusinessRuleException(
                    'This order is already completed, so it cannot be cancelled. Speak to the student instead.'
                );
            }

            $lines = self::items($orderId);

            if ($lines === []) {
                throw new BusinessRuleException('This order has no items, so there is nothing to cancel.');
            }

            // Aggregate by product before taking any lock: one product can
            // appear on several lines and must only be restored once.
            $quantities = [];

            foreach ($lines as $line) {
                if ($line['food_id'] === null) {
                    throw new BusinessRuleException(
                        'This order contains a product that is no longer in the inventory, so its stock cannot be returned safely.'
                    );
                }

                $foodId = (int) $line['food_id'];
                $quantities[$foodId] = ($quantities[$foodId] ?? 0) + (int) $line['quantity'];
            }

            $foodIds = array_keys($quantities);
            sort($foodIds);
            $locked = FoodRepository::lockForUpdate($foodIds);

            foreach ($foodIds as $foodId) {
                FoodRepository::moveStock(
                    $locked[$foodId],
                    $quantities[$foodId],
                    MovementType::SaleRestore,
                    $actingAdminId,
                    'order',
                    $orderId,
                    sprintf('Order %s cancelled', (string) $order['order_number'])
                );
            }

            Database::execute(
                "UPDATE orders
                    SET status = ?, payment_status = ?, cancel_reason = ?,
                        cancelled_at = ?, cancelled_by = ?
                  WHERE id = ?",
                [
                    OrderStatus::Cancelled->value,
                    PaymentStatus::Unpaid->value,
                    mb_substr($reason, 0, 200),
                    utc_now(),
                    $actingAdminId,
                    $orderId,
                ]
            );

            self::recordStatusChange($orderId, $current, OrderStatus::Cancelled, $actingAdminId, $reason);

            AuditRepository::log($actingAdminId, 'order.cancel', 'order', $orderId, [
                'order_number' => $order['order_number'],
                'from'         => $current->value,
                'reason'       => $reason,
                'units_returned' => array_sum($quantities),
            ]);
        });
    }

    /**
     * A student cancelling their own order.
     *
     * Only a Pending order may be cancelled this way. Once the canteen has
     * started preparing, the food is being cooked and the student should ask
     * the staff instead.
     *
     * @throws NotFoundException
     * @throws BusinessRuleException
     */
    public static function cancelByStudent(int $orderId, int $studentUserId, string $reason): void
    {
        $order = self::findForStudent($orderId, $studentUserId);

        if ($order === null) {
            // Same response as "no such order": a student must not be able to
            // discover that a given order number belongs to somebody else.
            throw new NotFoundException('That order could not be found.');
        }

        if (OrderStatus::from((string) $order['status']) !== OrderStatus::Pending) {
            throw new BusinessRuleException(
                'This order has already been started by the canteen, so it can no longer be cancelled online. Please speak to the canteen staff.'
            );
        }

        self::cancel($orderId, $reason !== '' ? $reason : 'Cancelled by the student', $studentUserId);

        AuditRepository::log($studentUserId, 'order.cancel_by_student', 'order', $orderId, [
            'order_number' => $order['order_number'],
        ]);
    }

    /**
     * Record a cash payment.
     *
     * `payments.reference` holds the order number and is UNIQUE, so the second
     * attempt to pay the same order fails at the storage layer even if two
     * administrators click at the same moment.
     *
     * @throws BusinessRuleException
     * @throws NotFoundException
     */
    public static function markPaid(int $orderId, int $actingAdminId, ?string $note = null): void
    {
        Database::transaction(static function () use ($orderId, $actingAdminId, $note): void {
            $order = Database::fetchOne(
                'SELECT id, order_number, status, payment_status, total_amount FROM orders WHERE id = ? FOR UPDATE',
                [$orderId]
            );

            if ($order === null) {
                throw new NotFoundException('That order no longer exists.');
            }

            $status = OrderStatus::from((string) $order['status']);

            if ($status === OrderStatus::Cancelled) {
                throw new BusinessRuleException('A cancelled order cannot be marked as paid.');
            }

            $payment = PaymentStatus::from((string) $order['payment_status']);

            if ($payment === PaymentStatus::Paid) {
                throw new BusinessRuleException('This order has already been marked as paid.');
            }

            try {
                Database::execute(
                    'INSERT INTO payments (order_id, reference, amount, method, received_by, note)
                          VALUES (?, ?, ?, \'Cash\', ?, ?)',
                    [
                        $orderId,
                        (string) $order['order_number'],
                        (string) $order['total_amount'],
                        $actingAdminId,
                        $note !== null && $note !== '' ? mb_substr($note, 0, 200) : null,
                    ]
                );
            } catch (BusinessRuleException $error) {
                throw $error;
            } catch (Throwable $error) {
                if (($error instanceof \KantEase\DatabaseException) && ($error->context()['driver_code'] ?? null) === 1062) {
                    // UNIQUE(payments.reference): another administrator
                    // recorded this payment a moment earlier.
                    throw new BusinessRuleException('This order has already been marked as paid.');
                }

                throw $error;
            }

            Database::execute(
                'UPDATE orders SET payment_status = ? WHERE id = ?',
                [PaymentStatus::Paid->value, $orderId]
            );

            AuditRepository::log($actingAdminId, 'order.mark_paid', 'order', $orderId, [
                'order_number' => $order['order_number'],
                'amount'       => (string) $order['total_amount'],
                'status'       => $status->value,
            ]);
        });
    }

    /**
     * Reverse a recorded payment.
     *
     * Deliberately not a plain toggle. Reversing a payment is a real event
     * with a real reason (wrong change, refunded meal) and is written to the
     * audit log rather than silently flipping a column.
     */
    public static function revokePayment(int $orderId, int $actingAdminId, string $reason): void
    {
        Database::transaction(static function () use ($orderId, $actingAdminId, $reason): void {
            $order = Database::fetchOne(
                'SELECT id, order_number, status, payment_status FROM orders WHERE id = ? FOR UPDATE',
                [$orderId]
            );

            if ($order === null) {
                throw new NotFoundException('That order no longer exists.');
            }

            if (PaymentStatus::from((string) $order['payment_status']) !== PaymentStatus::Paid) {
                throw new BusinessRuleException('This order is not marked as paid.');
            }

            Database::execute(
                'UPDATE orders SET payment_status = ? WHERE id = ?',
                [PaymentStatus::Unpaid->value, $orderId]
            );

            Database::execute(
                'DELETE FROM payments WHERE order_id = ?',
                [$orderId]
            );

            AuditRepository::log($actingAdminId, 'order.revoke_payment', 'order', $orderId, [
                'order_number' => $order['order_number'],
                'reason'       => mb_substr($reason, 0, 200),
            ]);
        });
    }

    /**
     * Replace the lines of an order that is still editable.
     *
     * Prices for lines that already existed are preserved from the stored
     * snapshot, exactly as the original did, so editing quantities never
     * reprice history. Newly added lines use the current price.
     *
     * @param  list<array{food_id: int, quantity: int}> $items
     * @throws BusinessRuleException
     * @throws NotFoundException
     */
    public static function replaceItems(int $orderId, array $items, int $actingAdminId): void
    {
        $quantities = self::normaliseRequestedItems($items);

        Database::transaction(static function () use ($orderId, $quantities, $actingAdminId): void {
            $order = Database::fetchOne(
                'SELECT id, order_number, status FROM orders WHERE id = ? FOR UPDATE',
                [$orderId]
            );

            if ($order === null) {
                throw new NotFoundException('That order no longer exists.');
            }

            $status = OrderStatus::from((string) $order['status']);

            if ($status->isTerminal()) {
                throw new BusinessRuleException(
                    sprintf('This order is %s, so its items can no longer be changed.', strtolower($status->label()))
                );
            }

            $existingLines = self::items($orderId);

            if ($existingLines === []) {
                throw new BusinessRuleException('This order has no items.');
            }

            // Preserve the historical snapshot for any product already on the
            // order, and refuse to touch stock for a line whose product has
            // since been deleted.
            $oldQuantities = [];
            $oldDetails    = [];

            foreach ($existingLines as $line) {
                if ($line['food_id'] === null) {
                    throw new BusinessRuleException(
                        'This order contains a product that is no longer in the inventory, so its stock cannot be changed safely.'
                    );
                }

                $foodId = (int) $line['food_id'];
                $oldQuantities[$foodId] = ($oldQuantities[$foodId] ?? 0) + (int) $line['quantity'];
                $oldDetails[$foodId] ??= $line;
            }

            $affected = array_values(array_unique([...array_keys($oldQuantities), ...array_keys($quantities)]));
            sort($affected);

            $locked = FoodRepository::lockForUpdate($affected);

            $totalCents = 0;
            $newLines   = [];

            foreach ($quantities as $foodId => $quantity) {
                $previous = $oldDetails[$foodId] ?? null;

                $name = $previous !== null
                    ? (string) $previous['item_name']
                    : (string) $locked[$foodId]['name'];

                $priceCents = $previous !== null
                    ? to_cents((string) $previous['unit_price'])
                    : to_cents((string) $locked[$foodId]['price']);

                $totalCents += $priceCents * $quantity;

                $newLines[] = [
                    'food_id'     => $foodId,
                    'name'        => $name,
                    'quantity'    => $quantity,
                    'price_cents' => $priceCents,
                    'subtotal'    => $priceCents * $quantity,
                ];
            }

            if ($totalCents > 9_999_999_999) {
                throw new BusinessRuleException('That order total is too large.');
            }

            // Apply the stock difference one product at a time.
            foreach ($affected as $foodId) {
                $difference = ($quantities[$foodId] ?? 0) - ($oldQuantities[$foodId] ?? 0);

                if ($difference === 0) {
                    continue;
                }

                FoodRepository::moveStock(
                    $locked[$foodId],
                    -$difference,
                    $difference > 0 ? MovementType::Sale : MovementType::SaleRestore,
                    $actingAdminId,
                    'order',
                    $orderId,
                    sprintf('Order %s edited by staff', (string) $order['order_number'])
                );
            }

            Database::execute('DELETE FROM order_items WHERE order_id = ?', [$orderId]);

            foreach ($newLines as $line) {
                Database::execute(
                    'INSERT INTO order_items (order_id, food_id, item_name, quantity, unit_price, subtotal)
                          VALUES (?, ?, ?, ?, ?, ?)',
                    [
                        $orderId,
                        $line['food_id'],
                        $line['name'],
                        $line['quantity'],
                        cents_to_amount($line['price_cents']),
                        cents_to_amount($line['subtotal']),
                    ]
                );
            }

            Database::execute(
                'UPDATE orders SET total_amount = ? WHERE id = ?',
                [cents_to_amount($totalCents), $orderId]
            );

            AuditRepository::log($actingAdminId, 'order.replace_items', 'order', $orderId, [
                'order_number' => $order['order_number'],
                'new_total'    => cents_to_amount($totalCents),
                'lines'        => count($newLines),
            ]);
        });
    }

    /**
     * Attach or change a staff note.
     */
    public static function setNote(int $orderId, ?string $note, int $actingAdminId): void
    {
        Database::transaction(static function () use ($orderId, $note, $actingAdminId): void {
            $order = Database::fetchOne('SELECT id, status FROM orders WHERE id = ? FOR UPDATE', [$orderId]);

            if ($order === null) {
                throw new NotFoundException('That order no longer exists.');
            }

            Database::execute(
                'UPDATE orders SET note = ? WHERE id = ?',
                [$note !== null && $note !== '' ? mb_substr($note, 0, 200) : null, $orderId]
            );

            AuditRepository::log($actingAdminId, 'order.note', 'order', $orderId);
        });
    }

    /**
     * Abandon Pending orders older than the configured window.
     *
     * Runs the same cancellation path as a manual cancel, so the stock is
     * returned exactly once and the reason is recorded.
     *
     * @return int Number of orders cancelled.
     */
    public static function cancelAbandoned(int $actingAdminId): int
    {
        $minutes = Config::int('orders.abandon_after_minutes', 0);

        if ($minutes < 1) {
            return 0;
        }

        $threshold = gmdate('Y-m-d H:i:s', time() - $minutes * 60);

        $candidates = Database::fetchColumnAll(
            'SELECT id FROM orders WHERE status = ? AND order_date < ?',
            [OrderStatus::Pending->value, $threshold]
        );

        $cancelled = 0;

        foreach ($candidates as $orderId) {
            try {
                self::cancel((int) $orderId, 'Automatically cancelled: not collected within the pick-up window.', $actingAdminId);
                $cancelled++;
            } catch (Throwable) {
                // Someone picked it up or cancelled it in the meantime. That
                // is the desired outcome, not an error.
            }
        }

        return $cancelled;
    }

    // =======================================================================
    // Internals
    // =======================================================================

    /**
     * Allocate the next KE-YYYYMMDD-#### reference.
     *
     * Must run inside a transaction. The counter row is locked, so two
     * simultaneous orders cannot receive the same number.
     *
     * @throws BusinessRuleException
     */
    public static function nextOrderNumber(): string
    {
        try {
            $next = Database::fetchValue(
                'SELECT next_number FROM id_sequences WHERE sequence_name = \'order\' FOR UPDATE',
                []
            );
        } catch (Throwable $error) {
            throw new BusinessRuleException(
                'KantEase could not generate an order number. Please contact the canteen.'
            );
        }

        if ($next === null) {
            throw new BusinessRuleException(
                'The order number counter is missing. Import database/database.sql again.'
            );
        }

        $nextNumber = max(1, (int) $next);

        Database::execute(
            'UPDATE id_sequences SET next_number = ? WHERE sequence_name = \'order\'',
            [$nextNumber + 1]
        );

        return sprintf('KE-%s-%04d', gmdate('Ymd'), $nextNumber);
    }

    /**
     * Append to the order timeline.
     */
    public static function recordStatusChange(
        int $orderId,
        ?OrderStatus $from,
        OrderStatus $to,
        ?int $changedBy,
        ?string $note = null,
    ): void {
        Database::execute(
            'INSERT INTO order_status_history (order_id, from_status, to_status, changed_by, note)
                  VALUES (?, ?, ?, ?, ?)',
            [
                $orderId,
                $from?->value,
                $to->value,
                $changedBy,
                $note !== null && $note !== '' ? mb_substr($note, 0, 200) : null,
            ]
        );
    }

    /**
     * The payment record for an order, if any.
     *
     * @return array<string, mixed>|null
     */
    public static function payment(int $orderId): ?array
    {
        return Database::fetchOne(
            'SELECT p.id, p.reference, p.amount, p.method, p.received_at, p.note,
                    p.created_at, u.user_code, u.full_name
               FROM payments p
               JOIN users u ON u.id = p.received_by
              WHERE p.order_id = ?',
            [$orderId]
        );
    }

    /**
     * Verify the invariant that an order's stored total equals the sum of its
     * lines. Used by the Phase 8 integrity checks and the installer.
     *
     * @return list<array<string, mixed>>
     */
    public static function findTotalMismatches(int $limit = 20): array
    {
        return Database::fetchAll(
            'SELECT o.id, o.order_number, o.total_amount,
                    COALESCE(SUM(oi.subtotal), 0) AS lines_total
               FROM orders o
               LEFT JOIN order_items oi ON oi.order_id = o.id
              GROUP BY o.id, o.order_number, o.total_amount
             HAVING ABS(o.total_amount - COALESCE(SUM(oi.subtotal), 0)) > 0.005
              ' . Database::limitClause($limit)
        );
    }
}