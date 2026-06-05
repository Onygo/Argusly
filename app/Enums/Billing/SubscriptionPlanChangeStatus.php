<?php

namespace App\Enums\Billing;

enum SubscriptionPlanChangeStatus: string
{
    case PENDING = 'pending';
    case PENDING_PAYMENT = 'pending_payment';
    case APPLIED = 'applied';
    case FAILED = 'failed';
    case BLOCKED = 'blocked';

    public function isPending(): bool
    {
        return in_array($this, [self::PENDING, self::PENDING_PAYMENT], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::APPLIED, self::FAILED, self::BLOCKED], true);
    }

    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        return match ($this) {
            // Business rule exceptions:
            // - pending -> applied for immediate upgrades with zero amount due.
            // - pending -> failed for early validation failures.
            self::PENDING => in_array($target, [self::PENDING_PAYMENT, self::APPLIED, self::FAILED, self::BLOCKED], true),
            self::PENDING_PAYMENT => in_array($target, [self::APPLIED, self::FAILED, self::BLOCKED], true),
            self::APPLIED, self::FAILED, self::BLOCKED => false,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
