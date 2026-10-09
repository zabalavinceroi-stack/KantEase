<?php

declare(strict_types=1);

namespace KantEase\Repositories;

use KantEase\Database;

use function KantEase\client_ip;

/**
 * Writes the `activity_logs` audit trail.
 *
 * Every privileged action lands here: sign-ins, profile edits, account
 * changes, inventory edits, order processing and cancellations. This is the
 * evidence trail required by spec 9 for "restricted administrator endpoints".
 *
 * Logging never interrupts the action it describes. If the insert fails the
 * real error propagates, but an audit write that fails on its own cannot roll
 * back a completed stock deduction.
 */
final class AuditRepository
{
    /**
     * Record an action.
     *
     * @param array<string, mixed> $details Extra context, stored as JSON.
     */
    public static function log(
        ?int $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $details = [],
    ): void {
        try {
            Database::execute(
                'INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details, ip_address)
                      VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $userId,
                    $action,
                    $entityType,
                    $entityId,
                    $details === [] ? null : self::encode($details),
                    client_ip(),
                ]
            );
        } catch (\Throwable $error) {
            // Never let the audit trail take down a page, but make the failure
            // visible to whoever reads the PHP error log.
            error_log(sprintf(
                'KantEase: activity log write failed for "%s": %s',
                $action,
                $error->getMessage()
            ));
        }
    }

    /**
     * Recent entries, newest first, for the Admin dashboard.
     *
     * @return list<array<string, mixed>>
     */
    public static function recent(int $limit = 10): array
    {
        return Database::fetchAll(
            'SELECT a.id, a.action, a.entity_type, a.entity_id, a.details, a.ip_address, a.created_at,
                    u.user_code, u.full_name
               FROM activity_logs a
               LEFT JOIN users u ON u.id = a.user_id
              ORDER BY a.created_at DESC, a.id DESC
              LIMIT ' . Database::limitClause($limit)
        );
    }

    /**
     * Everything recorded about one entity, for an order or account detail.
     *
     * @return list<array<string, mixed>>
     */
    public static function forEntity(string $entityType, int $entityId, int $limit = 50): array
    {
        return Database::fetchAll(
            'SELECT a.id, a.action, a.details, a.ip_address, a.created_at,
                    u.user_code, u.full_name
               FROM activity_logs a
               LEFT JOIN users u ON u.id = a.user_id
              WHERE a.entity_type = ? AND a.entity_id = ?
              ORDER BY a.created_at DESC, a.id DESC
              LIMIT ' . Database::limitClause($limit),
            [$entityType, $entityId]
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    private static function encode(array $details): string
    {
        return json_encode(
            $details,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        ) ?: '{}';
    }
}