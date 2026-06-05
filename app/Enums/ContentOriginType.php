<?php

namespace App\Enums;

enum ContentOriginType: string
{
    case MANUAL = 'manual';
    case CHAINED = 'chained';
    case AUTOMATION = 'automation';
    case CHAINED_VIA_AUTOMATION = 'chained_via_automation';
    case SERIES_GENERATED = 'series_generated';
    case UNKNOWN = 'unknown';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }

    public function label(): string
    {
        return match ($this) {
            self::MANUAL => 'Manual',
            self::CHAINED => 'Chained',
            self::AUTOMATION => 'Automation',
            self::CHAINED_VIA_AUTOMATION => 'Chain (auto)',
            self::SERIES_GENERATED => 'Series',
            self::UNKNOWN => 'Unknown',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::MANUAL => 'border-slate-200 bg-slate-50 text-slate-700',
            self::CHAINED => 'border-purple-200 bg-purple-50 text-purple-700',
            self::AUTOMATION => 'border-blue-200 bg-blue-50 text-blue-700',
            self::CHAINED_VIA_AUTOMATION => 'border-indigo-200 bg-indigo-50 text-indigo-700',
            self::SERIES_GENERATED => 'border-emerald-200 bg-emerald-50 text-emerald-700',
            self::UNKNOWN => 'border-gray-200 bg-gray-50 text-gray-500',
        };
    }

    public function isFromAutomation(): bool
    {
        return in_array($this, [self::AUTOMATION, self::CHAINED_VIA_AUTOMATION], true);
    }

    public function isChained(): bool
    {
        return in_array($this, [self::CHAINED, self::CHAINED_VIA_AUTOMATION, self::SERIES_GENERATED], true);
    }
}
