<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /**
     * Valid forward lifecycle transitions (no backward jumps, terminal delivered/cancelled):
     * pending    -> paid | processing | shipped | delivered | cancelled (paid+ requires payment_status = succeeded)
     * paid       -> processing | shipped | delivered | cancelled
     * processing -> shipped | delivered | cancelled
     * shipped    -> delivered | cancelled
     * delivered  -> (terminal)
     * cancelled  -> (terminal)
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Paid, self::Processing, self::Shipped, self::Delivered, self::Cancelled],
            self::Paid => [self::Processing, self::Shipped, self::Delivered, self::Cancelled],
            self::Processing => [self::Shipped, self::Delivered, self::Cancelled],
            self::Shipped => [self::Delivered, self::Cancelled],
            self::Delivered => [],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isCancellable(): bool
    {
        return in_array($this, [self::Pending, self::Paid, self::Processing, self::Shipped], true);
    }

    public function requiresPaidPayment(): bool
    {
        return in_array($this, [self::Paid, self::Processing, self::Shipped, self::Delivered], true);
    }
}
