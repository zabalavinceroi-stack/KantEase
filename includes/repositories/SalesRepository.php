<?php

declare(strict_types=1);

namespace KantEase\Repositories;

use KantEase\Database;
use KantEase\OrderStatus;
use KantEase\PaymentStatus;

use function KantEase\cents_to_amount;
use function KantEase\format_datetime;
use function KantEase\to_cents;
use function KantEase\today_utc;

/**
 * Sales reporting.
 *
 * THE RULE THIS REPOSITORY EXISTS TO ENFORCE
 *
 * The original build computed revenue as:
 *
 *     SUM(total_amount) WHERE status <> 'Cancelled'
 *
 * which counted every unpaid order as money already collected. A canteen
 * dashboard that shows ₱4,500 of "today's sales" when ₱1,200 is still
 * outstanding on the counter is simply wrong, and the same figure was written
 * into the CSV the canteen accountant reads.
 *
 * Here the two ideas are kept strictly apart:
 *
 *     REVENUE  money actually received
 *              = status <> 'Cancelled' AND payment_status = 'Paid'
 *
 *     ORDER VALUE  what students have committed to
 *              = status <> 'Cancelled'
 *
 * Every figure and every export below reports both, so the difference between
 * what was promised and what was collected is always visible rather than
 * hidden inside one ambiguous number.
 */
final class SalesRepository
{
    /**
     * Everything the Admin dashboard needs, in one place.
     *
     * @return array<string, mixed>
     */
    public static function dashboardSummary(): array
    {
        return [
            'orders'        => self::orderTotals(null, null),
            'today'         => self::orderTotals(today_utc(), today_utc()),
            'status_counts' => OrderRepository::statusCounts(),
            'low_stock'     => FoodRepository::lowStock(10),
            'low_stock_count' => FoodRepository::countLowStock(),
        ];
    }

    /**
     * Order counts and money for a date range.
     *
     * @return array<string, mixed>
     */
    public static function orderTotals(?string $from, ?string $to): array
    {
        [$where, $params] = self::dateRange($from, $to, 'o.order_date');

        $row = Database::fetchOne(
            sprintf(
                "SELECT COUNT(*) AS orders,
                        SUM(status = 'Pending')   AS pending,
                        SUM(status = 'Preparing') AS preparing,
                        SUM(status = 'Ready')     AS ready,
                        SUM(status = 'Completed') AS completed,
                        SUM(status = 'Cancelled') AS cancelled,
                        SUM(payment_status = 'Paid')   AS paid_orders,
                        SUM(payment_status = 'Unpaid') AS unpaid_orders,
                        COALESCE(SUM(CASE WHEN status <> 'Cancelled' AND payment_status = 'Paid'   THEN total_amount ELSE 0 END), 0) AS revenue,
                        COALESCE(SUM(CASE WHEN status <> 'Cancelled'                             THEN total_amount ELSE 0 END), 0) AS order_value,
                        COALESCE(SUM(CASE WHEN status <> 'Cancelled' AND payment_status = 'Unpaid' THEN total_amount ELSE 0 END), 0) AS outstanding
                   FROM orders o
                   %s",
                $where
            ),
            $params
        ) ?? [];

        return [
            'orders'        => (int) ($row['orders'] ?? 0),
            'pending'       => (int) ($row['pending'] ?? 0),
            'preparing'     => (int) ($row['preparing'] ?? 0),
            'ready'         => (int) ($row['ready'] ?? 0),
            'completed'     => (int) ($row['completed'] ?? 0),
            'cancelled'     => (int) ($row['cancelled'] ?? 0),
            'paid_orders'   => (int) ($row['paid_orders'] ?? 0),
            'unpaid_orders' => (int) ($row['unpaid_orders'] ?? 0),
            'revenue'       => to_cents((string) ($row['revenue'] ?? '0')),
            'order_value'   => to_cents((string) ($row['order_value'] ?? '0')),
            'outstanding'   => to_cents((string) ($row['outstanding'] ?? '0')),
        ];
    }

    /**
     * Revenue grouped into daily, weekly or monthly buckets.
     *
     * Weekly buckets are ISO weeks so they line up with a school term, and
     * the bucket label is the Monday date so the column header is unambiguous.
     *
     * @return list<array{period: string, revenue: int, order_value: int, orders: int}>
     */
    public static function trend(string $granularity, ?string $from = null, ?string $to = null): array
    {
        [$expression, $labelFormat] = match ($granularity) {
            'monthly' => ["DATE_FORMAT(o.order_date, '%Y-%m')", 'Y-m'],
            'weekly'  => ["DATE_FORMAT(o.order_date - INTERVAL WEEKDAY(o.order_date) DAY, '%Y-%m-%d')", 'Y-m-d'],
            default   => ['DATE(o.order_date)', 'Y-m-d'],
        };

        [$where, $params] = self::dateRange($from, $to, 'o.order_date');

        $rows = Database::fetchAll(
            sprintf(
                "SELECT %s AS period,
                        COALESCE(SUM(CASE WHEN status <> 'Cancelled' AND payment_status = 'Paid' THEN total_amount ELSE 0 END), 0) AS revenue,
                        COALESCE(SUM(CASE WHEN status <> 'Cancelled' THEN total_amount ELSE 0 END), 0) AS order_value,
                        COUNT(*) AS orders
                   FROM orders o
                   %s
                  GROUP BY period
                  ORDER BY period ASC",
                $expression,
                $where
            ),
            $params
        );

        return array_map(
            static fn (array $row): array => [
                'period'      => (string) $row['period'],
                'label'       => self::periodLabel((string) $row['period'], $labelFormat, $granularity),
                'revenue'     => to_cents((string) $row['revenue']),
                'order_value' => to_cents((string) $row['order_value']),
                'orders'      => (int) $row['orders'],
            ],
            $rows
        );
    }

    /**
     * Every day's revenue, including days with no sales.
     *
     * A chart with gaps is misleading: a canteen that sold nothing on Sunday
     * should show zero, not no bar at all.
     *
     * @return list<array{period: string, label: string, revenue: int, order_value: int, orders: int}>
     */
    public static function dailyTrend(int $days = 7, ?string $to = null): array
    {
        $end   = $to ?? today_utc();
        $start = gmdate('Y-m-d', strtotime($end . ' -' . max(0, $days - 1) . ' days'));

        $byPeriod = [];

        foreach (self::trend('daily', $start, $end) as $row) {
            $byPeriod[$row['period']] = $row;
        }

        $series = [];

        for ($cursor = strtotime($start); $cursor <= strtotime($end); $cursor = strtotime('+1 day', $cursor)) {
            $period = gmdate('Y-m-d', $cursor);

            $series[] = $byPeriod[$period] ?? [
                'period'      => $period,
                'label'       => gmdate('M j', $cursor),
                'revenue'     => 0,
                'order_value' => 0,
                'orders'      => 0,
            ];
        }

        return $series;
    }

    /**
     * Products ranked by units sold.
     *
     * Counts demand across every order that was not cancelled, regardless of
     * payment — an unpaid order still consumed stock, so it still belongs in a
     * best-seller list. The revenue column is calculated on the paid rule so
     * the two are never confused.
     *
     * @return list<array<string, mixed>>
     */
    public static function bestSellers(?string $from = null, ?string $to = null, int $limit = 10): array
    {
        [$where, $params] = self::dateRange($from, $to, 'o.order_date');

        return Database::fetchAll(
            sprintf(
                "SELECT oi.item_name,
                        COALESCE(f.category_id, 0) AS category_id,
                        c.name AS category,
                        SUM(oi.quantity) AS units,
                        COUNT(DISTINCT oi.order_id) AS order_count,
                        COALESCE(SUM(oi.subtotal), 0) AS gross_value,
                        COALESCE(SUM(CASE WHEN o.payment_status = 'Paid' THEN oi.subtotal ELSE 0 END), 0) AS paid_value
                   FROM order_items oi
                   JOIN orders o ON o.id = oi.order_id
                   LEFT JOIN food_items f ON f.id = oi.food_id
                   LEFT JOIN food_categories c ON c.id = f.category_id
                   %s
                  GROUP BY oi.item_name, c.name
                  ORDER BY units DESC, gross_value DESC, oi.item_name ASC
                  LIMIT %s",
                $where,
                Database::limitClause($limit)
            ),
            $params
        );
    }

    /**
     * Units sold per product per category.
     *
     * @return list<array<string, mixed>>
     */
    public static function productBreakdown(?string $from = null, ?string $to = null): array
    {
        [$where, $params] = self::dateRange($from, $to, 'o.order_date');

        return Database::fetchAll(
            sprintf(
                "SELECT COALESCE(c.name, 'Uncategorised') AS category,
                        oi.item_name,
                        SUM(oi.quantity) AS units,
                        COALESCE(SUM(oi.subtotal), 0) AS gross_value,
                        COALESCE(SUM(CASE WHEN o.payment_status = 'Paid' THEN oi.subtotal ELSE 0 END), 0) AS paid_value
                   FROM order_items oi
                   JOIN orders o ON o.id = oi.order_id
                   LEFT JOIN food_items f ON f.id = oi.food_id
                   LEFT JOIN food_categories c ON c.id = f.category_id
                   %s
                  GROUP BY category, oi.item_name
                  ORDER BY category ASC, units DESC, oi.item_name ASC",
                $where
            ),
            $params
        );
    }

    /**
     * How the canteen is doing compared with the previous period.
     *
     * @return array<string, int>
     */
    public static function comparison(string $granularity, ?string $from, ?string $to): array
    {
        [$start, $end] = self::resolveRange($granularity, $from, $to);

        $currentStart = strtotime($start);
        $currentEnd   = strtotime($end);

        if ($currentStart === false || $currentEnd === false || $currentEnd < $currentStart) {
            return ['days' => 0, 'previous_revenue' => 0, 'change_cents' => 0];
        }

        $days = (int) (($currentEnd - $currentStart) / 86400) + 1;

        $previousEnd   = gmdate('Y-m-d', $currentStart - 86400);
        $previousStart = gmdate('Y-m-d', $currentStart - $days * 86400);

        $current  = self::orderTotals($start, $end);
        $previous = self::orderTotals($previousStart, $previousEnd);

        return [
            'days'             => $days,
            'previous_revenue' => $previous['revenue'],
            'change_cents'     => $current['revenue'] - $previous['revenue'],
        ];
    }

    /**
     * Turn the report period controls into concrete dates.
     *
     * @return array{0: string, 1: string}
     */
    public static function resolveRange(string $preset, ?string $from, ?string $to): array
    {
        $today = today_utc();

        return match ($preset) {
            'today'    => [$today, $today],
            'week'     => [gmdate('Y-m-d', strtotime($today . ' -6 days')), $today],
            'month'    => [gmdate('Y-m-01', strtotime($today)), $today],
            'last_week'=> [
                gmdate('Y-m-d', strtotime($today . '-13 days')),
                gmdate('Y-m-d', strtotime($today . '-7 days')),
            ],
            'last_month'=> [
                gmdate('Y-m-01', strtotime($today . 'first day of last month')),
                gmdate('Y-m-t', strtotime($today . 'last day of last month')),
            ],
            default    => [
                $from !== null && $from !== '' ? $from : gmdate('Y-m-d', strtotime($today . '-29 days')),
                $to   !== null && $to   !== '' ? $to   : $today,
            ],
        };
    }

    /**
     * Build a WHERE fragment for an inclusive date range on a datetime column.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private static function dateRange(?string $from, ?string $to, string $column): array
    {
        $clauses = [];
        $params  = [];

        if ($from !== null && $from !== '') {
            $clauses[] = sprintf('%s >= ?', $column);
            $params[]  = $from . ' 00:00:00';
        }

        if ($to !== null && $to !== '') {
            $clauses[] = sprintf('%s <= ?', $column);
            $params[]  = $to . ' 23:59:59';
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * A readable column heading for a trend bucket.
     */
    private static function periodLabel(string $period, string $format, string $granularity): string
    {
        $timestamp = strtotime($period . ' 00:00:00 UTC');

        if ($timestamp === false) {
            return $period;
        }

        return match ($granularity) {
            'monthly' => gmdate('M Y', $timestamp),
            'weekly'  => 'Week of ' . gmdate('M j', $timestamp),
            default   => gmdate('D ' . $format, $timestamp),
        };
    }

    // -----------------------------------------------------------------------
    // Exports
    // -----------------------------------------------------------------------

    /**
     * Sales CSV rows.
     *
     * Revenue and order value are separate columns, with the payment rule
     * named in the header so nobody can misread the file later.
     *
     * @param  array<string, mixed> $totals
     * @return list<list<string|int>>
     */
    public static function salesCsv(array $totals, array $trend, string $granularity): array
    {
        $rows = [[
            'Period',
            'Transactions',
            'Revenue (Paid, excl. Cancelled)',
            'Order Value (excl. Cancelled)',
            'Unpaid Outstanding',
        ]];

        foreach ($trend as $bucket) {
            $rows[] = [
                $bucket['label'],
                $bucket['orders'],
                cents_to_amount($bucket['revenue']),
                cents_to_amount($bucket['order_value']),
                cents_to_amount($bucket['order_value'] - $bucket['revenue']),
            ];
        }

        $rows[] = [];
        $rows[] = ['SUMMARY', '', '', '', ''];
        $rows[] = ['Range', 'Transactions', 'Revenue (Paid)', 'Order Value', 'Outstanding'];
        $rows[] = [
            ($granularity !== 'custom' ? ucfirst($granularity) : 'Custom range'),
            $totals['orders'],
            cents_to_amount($totals['revenue']),
            cents_to_amount($totals['order_value']),
            cents_to_amount($totals['outstanding']),
        ];
        $rows[] = ['Paid orders', $totals['paid_orders'], '', '', ''];
        $rows[] = ['Unpaid orders', $totals['unpaid_orders'], '', '', ''];
        $rows[] = ['Cancelled orders', $totals['cancelled'], '', '', ''];

        return $rows;
    }

    /**
     * Best-seller CSV rows.
     *
     * @param  list<array<string, mixed>> $rows
     * @return list<list<string|int>>
     */
    public static function bestSellersCsv(array $rows): array
    {
        $csv = [['Rank', 'Product', 'Category', 'Units Sold', 'Orders', 'Gross Value', 'Paid Revenue']];

        foreach (array_values($rows) as $index => $row) {
            $csv[] = [
                $index + 1,
                (string) $row['item_name'],
                (string) ($row['category'] ?? ''),
                (int) $row['units'],
                (int) $row['order_count'],
                cents_to_amount(to_cents((string) $row['gross_value'])),
                cents_to_amount(to_cents((string) $row['paid_value'])),
            ];
        }

        return $csv;
    }

    /**
     * Order list CSV rows.
     *
     * @param  list<array<string, mixed>> $orders
     * @return list<list<string|int>>
     */
    public static function ordersCsv(array $orders): array
    {
        $csv = [[
            'Order No.',
            'Date',
            'Student ID',
            'Student Name',
            'Total',
            'Payment',
            'Status',
            'Cancel Reason',
        ]];

        foreach ($orders as $order) {
            $csv[] = [
                (string) $order['order_number'],
                format_datetime((string) $order['order_date'], 'Y-m-d H:i:s'),
                (string) ($order['student_user_code'] ?? $order['user_code'] ?? ''),
                (string) ($order['student_name'] ?? $order['full_name'] ?? ''),
                cents_to_amount(to_cents((string) $order['total_amount'])),
                (string) $order['payment_status'],
                (string) $order['status'],
                (string) ($order['cancel_reason'] ?? ''),
            ];
        }

        return $csv;
    }

    /**
     * Payments CSV rows.
     *
     * @param  list<array<string, mixed>> $payments
     * @return list<list<string|int>>
     */
    public static function paymentsCsv(array $payments): array
    {
        $csv = [['Order No.', 'Paid At', 'Amount', 'Method', 'Confirmed By', 'Note']];

        foreach ($payments as $payment) {
            $csv[] = [
                (string) $payment['order_number'],
                format_datetime((string) $payment['received_at'], 'Y-m-d H:i:s'),
                cents_to_amount(to_cents((string) $payment['amount'])),
                (string) $payment['method'],
                trim(sprintf('%s (%s)', (string) $payment['full_name'], (string) $payment['user_code'])),
                (string) ($payment['note'] ?? ''),
            ];
        }

        return $csv;
    }
}