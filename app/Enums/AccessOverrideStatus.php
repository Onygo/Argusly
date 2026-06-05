<?php

namespace App\Enums;

enum AccessOverrideStatus: string
{
    case ACTIVE = 'active';
    case SCHEDULED = 'scheduled';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::SCHEDULED => 'Scheduled',
            self::EXPIRED => 'Expired',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::ACTIVE => 'border-emerald-300/80 bg-emerald-500/10 text-emerald-800',
            self::SCHEDULED => 'border-sky-300/80 bg-sky-500/10 text-sky-800',
            self::EXPIRED => 'border-amber-300/80 bg-amber-500/10 text-amber-800',
            self::CANCELLED => 'border-rose-300/80 bg-rose-500/10 text-rose-800',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::ACTIVE, self::SCHEDULED], true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
