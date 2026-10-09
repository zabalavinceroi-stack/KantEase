<?php

declare(strict_types=1);

namespace KantEase;

/**
 * The two kinds of account KantEase has.
 */
enum UserRole: string
{
    case Student = 'student';
    case Admin   = 'admin';

    /** Label shown on screen. */
    public function label(): string
    {
        return match ($this) {
            self::Student => 'Student',
            self::Admin   => 'Administrator',
        };
    }

    /** Badge modifier class in the local stylesheet. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Student => 'badge--student',
            self::Admin   => 'badge--admin',
        };
    }

    /** Landing page for this role after signing in. */
    public function homePath(): string
    {
        return match ($this) {
            self::Student => '/student/dashboard.php',
            self::Admin   => '/admin/dashboard.php',
        };
    }

    /** Prefix used when allocating a new user code. */
    public function codePrefix(): string
    {
        return match ($this) {
            self::Student => 'STU-',
            self::Admin   => 'ADM-',
        };
    }
}

/**
 * The order lifecycle.
 *
 * Pending -> Preparing -> Ready -> Completed, or Cancelled from any of the
 * first three. Completed and Cancelled are terminal.
 *
 * The original Node.js build only locked terminal states, which meant
 * Completed -> Pending and Pending -> Completed were both accepted. That was
 * audit finding DEF-4. canTransitionTo() is the single place the graph is
 * defined now.
 */
enum OrderStatus: string
{
    case Pending   = 'Pending';
    case Preparing = 'Preparing';
    case Ready     = 'Ready';
    case Completed = 'Completed';
    case Cancelled = 'Cancelled';

    /** Label shown to students and staff. */
    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Pending',
            self::Preparing => 'Preparing',
            self::Ready     => 'Ready for Pickup',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Short label for dense tables. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            default     => $this->value,
        };
    }

    /** Badge modifier class in the local stylesheet. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending   => 'badge--pending',
            self::Preparing => 'badge--preparing',
            self::Ready     => 'badge--ready',
            self::Completed => 'badge--completed',
            self::Cancelled => 'badge--cancelled',
        };
    }

    /** True when no further status change is permitted. */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    /** True while the order still holds stock that cancellation would return. */
    public function holdsStock(): bool
    {
        return $this !== self::Cancelled;
    }

    /**
     * The statuses reachable in one step from this one.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending   => [self::Preparing, self::Cancelled],
            self::Preparing => [self::Ready, self::Cancelled],
            self::Ready     => [self::Completed, self::Cancelled],
            self::Completed => [],
            self::Cancelled => [],
        };
    }

    /**
     * Whether moving directly to $target is legal.
     *
     * Staying in the same status is allowed so an administrator can change
     * only the payment status or add a note without advancing anything.
     */
    public function canTransitionTo(self $target): bool
    {
        if ($target === $this) {
            return true;
        }

        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Human-readable explanation used when a transition is rejected.
     */
    public function rejectionReason(self $target): string
    {
        if ($this === self::Cancelled) {
            return 'This order was cancelled and can no longer be changed.';
        }

        if ($this === self::Completed) {
            return 'This order is already completed. Only its payment status can still change.';
        }

        if (! $target->isTerminal() && $this->allowedTransitions() === []) {
            return 'This order cannot be changed any further.';
        }

        $next = array_map(
            static fn (self $status): string => $status->label(),
            $this->allowedTransitions()
        );

        return sprintf(
            'An order cannot go from "%s" to "%s". From "%s" it can only go to: %s.',
            $this->label(),
            $target->label(),
            $this->label(),
            $next === [] ? 'nothing' : implode(', ', $next)
        );
    }

    /** The status an order starts in. */
    public static function initial(): self
    {
        return self::Pending;
    }

    /**
     * Safe parse from untrusted input.
     *
     * Returns null rather than throwing so callers can produce their own
     * validation message.
     */
    public static function tryFromInput(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}

/**
 * Cash-at-the-canteen payment state.
 *
 * KantEase records payment; it never captures it. There is no gateway, no
 * card handling and no online payment of any kind.
 */
enum PaymentStatus: string
{
    case Unpaid = 'Unpaid';
    case Paid   = 'Paid';

    public function label(): string
    {
        return $this->value;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Unpaid => 'badge--unpaid',
            self::Paid   => 'badge--paid',
        };
    }

    public function isPaid(): bool
    {
        return $this === self::Paid;
    }

    public static function tryFromInput(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}

/**
 * Every reason a stock count can change.
 *
 * sale and sale_restore are the two halves of the order lifecycle and are the
 * pair that make cancellation auditable: one row out, one row back, with
 * stock_before and stock_after on both.
 */
enum MovementType: string
{
    case Create      = 'create';
    case Restock     = 'restock';
    case Waste       = 'waste';
    case Adjustment  = 'adjustment';
    case Sale        = 'sale';
    case SaleRestore = 'sale_restore';

    public function label(): string
    {
        return match ($this) {
            self::Create      => 'Opening stock',
            self::Restock     => 'Restocked',
            self::Waste       => 'Removed / wasted',
            self::Adjustment  => 'Stock corrected',
            self::Sale        => 'Sold (order placed)',
            self::SaleRestore => 'Returned (order cancelled)',
        };
    }

    /** True when this movement is part of an order's stock lifecycle. */
    public function isOrderMovement(): bool
    {
        return $this === self::Sale || $this === self::SaleRestore;
    }

    /** Movement types an administrator may choose in the Inventory page. */
    public static function administrative(): array
    {
        return [self::Restock, self::Waste, self::Adjustment];
    }

    public static function tryFromInput(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}