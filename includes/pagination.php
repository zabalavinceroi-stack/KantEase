<?php

declare(strict_types=1);

namespace KantEase;

/**
 * Immutable description of one page of a list.
 *
 * The original admin order list returned every order in the system with a
 * correlated GROUP_CONCAT subquery and no limit. That does not scale past a
 * few hundred orders, so pagination is now a first-class part of the data
 * layer rather than something a page file improvises.
 */
final class Pagination
{
    private function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
        public readonly int $totalPages,
        public readonly int $offset,
        public readonly int $from,
        public readonly int $to,
    ) {
    }

    /**
     * @param array{page?: int, per_page?: int, total?: int, total_pages?: int, offset?: int, from?: int, to?: int} $pager
     */
    public static function fromArray(array $pager): self
    {
        return new self(
            (int) ($pager['page'] ?? 1),
            (int) ($pager['per_page'] ?? 25),
            (int) ($pager['total'] ?? 0),
            (int) ($pager['total_pages'] ?? 1),
            (int) ($pager['offset'] ?? 0),
            (int) ($pager['from'] ?? 0),
            (int) ($pager['to'] ?? 0),
        );
    }

    public static function for(int $total, int $page = 1, int $perPage = 25): self
    {
        return self::fromArray(paginate($total, $page, $perPage));
    }

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->totalPages;
    }

    public function previousPage(): int
    {
        return max(1, $this->page - 1);
    }

    public function nextPage(): int
    {
        return min($this->totalPages, $this->page + 1);
    }

    /**
     * Page numbers to render in the pager, with null marking an ellipsis.
     *
     * Always shows the first and last page plus a window around the current
     * one, so the control stays a fixed width no matter how long the list is.
     *
     * @return list<int|null>
     */
    public function window(int $radius = 2): array
    {
        if ($this->totalPages <= 7) {
            return range(1, $this->totalPages);
        }

        $pages = [1];
        $start = max(2, $this->page - $radius);
        $end   = min($this->totalPages - 1, $this->page + $radius);

        if ($start > 2) {
            $pages[] = null;
        }

        for ($i = $start; $i <= $end; $i++) {
            $pages[] = $i;
        }

        if ($end < $this->totalPages - 1) {
            $pages[] = null;
        }

        $pages[] = $this->totalPages;

        return $pages;
    }

    /**
     * A one-line summary such as "Showing 21-45 of 132 orders".
     */
    public function summary(string $noun = 'result'): string
    {
        if ($this->isEmpty()) {
            return sprintf('No %ss found', $noun);
        }

        $plural = $this->total === 1 ? $noun : $noun . 's';

        return sprintf('Showing %d-%d of %d %s', $this->from, $this->to, $this->total, $plural);
    }
}