<?php

declare(strict_types=1);

namespace KantEase;

use InvalidArgumentException;

/**
 * Whitelisted search and date filtering.
 *
 * The original Node.js build had one genuinely excellent idea that is carried
 * over verbatim. It collected search parameters from the URL but never let a
 * request value reach SQL as an identifier. Instead each searchable field was
 * declared in server code as a fixed column expression:
 *
 *     studentOrders: {
 *       order_number: "o.id",
 *       date:        { column: "o.order_date", isDate: true },
 *       all:         ["order_number", "date", "item_name", "total_amount"]
 *     }
 *
 * The browser could choose WHICH of those to search; it could never invent a
 * new one. This class is that mechanism, with the date validation and the
 * `From`/`To` ordering check preserved.
 *
 * The alternative — interpolating a column name from $_GET — is the single
 * most common way a PHP application is SQL-injected, so the guard here is
 * deliberate: to search a new field you must edit a PHP array in this
 * codebase, which shows up in review.
 */
final class SearchSpec
{
    private const MAX_QUERY_LENGTH = 100;

    /**
     * @param array<string, string|array{column: string, isDate?: bool}> $fields
     *        Field name => fixed SQL expression, or a column marked as a date.
     * @param list<string>                                                     $allFields
     *        Field names searched when the request asks for "all".
     * @param string|null                                                      $rangeColumn
     *        Column the From/To filter applies to, or null when the section
     *        has no date range.
     */
    private function __construct(
        private readonly array $fields,
        private readonly array $allFields,
        private readonly ?string $rangeColumn,
    ) {
    }

    /**
     * @param array<string, string|array{column: string, isDate?: bool}> $fields
     */
    public static function make(
        array $fields,
        ?string $rangeColumn = null,
        string $defaultField = 'all',
    ): self {
        foreach (array_keys($fields) as $name) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
                throw new InvalidArgumentException(
                    sprintf('Unsafe search field name "%s".', $name)
                );
            }
        }

        if ($defaultField === 'all') {
            $allFields = array_keys($fields);
        } else {
            if (! array_key_exists($defaultField, $fields)) {
                throw new InvalidArgumentException(
                    sprintf('Default search field "%s" is not declared.', $defaultField)
                );
            }
            $allFields = [$defaultField];
        }

        return new self($fields, $allFields, $rangeColumn);
    }

    /**
     * Apply the current request's filters to a base query.
     *
     * @param string               $baseWhere  e.g. "o.user_id = ?" or
     *                                            "WHERE o.user_id = ?" — both work
     * @param array<int, mixed>     $baseParams values for $baseWhere
     * @return array{0: string, 1: array<int, mixed>} extra SQL and its params
     * @throws ValidationException
     */
    public function build(string $baseWhere = '', array $baseParams = []): array
    {
        $clauses = [];
        $params  = $baseParams;

        if ($baseWhere !== '') {
            // Normalise, do not trust. Callers pass either a bare condition
            // ("o.user_id = ?") or a complete clause ("WHERE o.user_id = ?").
            // Emitting the caller's string unchanged produced
            // "WHERE WHERE o.user_id = ?" and a syntax error, so the keyword
            // is stripped here instead of being a caller obligation.
            $clauses[] = preg_replace('/^\s*WHERE\s+/i', '', $baseWhere) ?? $baseWhere;
        }

        $text = $this->readQueryText();

        if ($text !== '') {
            $clauses[] = '(' . implode(' OR ', $this->textConditions($text)) . ')';
            foreach ($this->selectedFields() as $ignored) {
                $params[] = '%' . $this->escapeLike($text) . '%';
            }
        }

        [$rangeClause, $rangeParams] = $this->buildRange();

        if ($rangeClause !== '') {
            $clauses[] = $rangeClause;
            $params    = [...$params, ...$rangeParams];
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * The field the request asked for, validated against the whitelist.
     */
    private function requestedField(): string
    {
        $field = query_string('field', 'all');

        if (! array_key_exists($field, $this->fields) || $field === 'range') {
            return 'all';
        }

        return $field;
    }

    /** @return list<string> */
    private function selectedFields(): array
    {
        return $this->requestedField() === 'all' ? $this->allFields : [$this->requestedField()];
    }

    private function readQueryText(): string
    {
        $text = query_string('q');

        if (mb_strlen($text) > self::MAX_QUERY_LENGTH) {
            throw new ValidationException(
                ['q' => 'Your search is too long.'],
                'Your search is too long.'
            );
        }

        // Reject control characters so a crafted query cannot smuggle a
        // newline into a log line or a debug message.
        if (preg_match('/[\x00-\x1F\x7F]/', $text) === 1) {
            throw new ValidationException(['q' => 'That search is not valid.'], 'That search is not valid.');
        }

        return $text;
    }

    /**
     * Neutralise the LIKE wildcards in user input.
     *
     * Without this, searching for "%" matches every row and "a_b" matches
     * "axb", which turns the search box into a full table dump and lets a
     * student enumerate other students' names one prefix at a time.
     */
    private function escapeLike(string $text): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text);
    }

    /**
     * @return list<string>
     */
    private function textConditions(string $text): array
    {
        $conditions = [];

        foreach ($this->selectedFields() as $name) {
            $definition = $this->fields[$name] ?? null;
            if ($definition === null) {
                continue;
            }

            $column     = is_array($definition) ? $definition['column'] : $definition;
            $isDate     = is_array($definition) && ($definition['isDate'] ?? false);

            // Every fragment below comes from the server-side map above.
            // $text only ever reaches the database as a bound parameter.
            $conditions[] = $isDate
                ? sprintf("DATE_FORMAT(%s, '%%Y-%%m-%%d %%H:%%i:%%s') LIKE ?", $column)
                : sprintf('CAST((%s) AS CHAR) LIKE ?', $column);
        }

        if ($conditions === []) {
            // No searchable fields at all: match nothing rather than
            // everything. An unfiltered list leaking through a filter bug is
            // worse than an empty one.
            return ['1 = 0'];
        }

        return $conditions;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function buildRange(): array
    {
        if ($this->rangeColumn === null) {
            return ['', []];
        }

        $from = query_string('from');
        $to   = query_string('to');

        if ($from === '' && $to === '') {
            return ['', []];
        }

        $this->assertValidDate($from, 'From');
        $this->assertValidDate($to, 'To');

        if ($from !== '' && $to !== '' && $from > $to) {
            throw new ValidationException(
                ['from' => 'The From date must not be after the To date.'],
                'The From date must not be after the To date.'
            );
        }

        return [
            sprintf('%s BETWEEN ? AND ?', $this->rangeColumn),
            [
                $from === '' ? '1000-01-01 00:00:00' : $from . ' 00:00:00',
                $to   === '' ? '9999-12-31 23:59:59' : $to . ' 23:59:59',
            ],
        ];
    }

    private function assertValidDate(string $value, string $label): void
    {
        if ($value === '') {
            return;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new ValidationException(
                ['from' => sprintf('Enter a valid %s date.', $label)],
                sprintf('Enter a valid %s date.', $label)
            );
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        if (! checkdate($month, $day, $year)) {
            throw new ValidationException(
                ['from' => sprintf('Enter a valid %s date.', $label)],
                sprintf('Enter a valid %s date.', $label)
            );
        }
    }
}