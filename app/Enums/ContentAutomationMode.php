<?php

namespace App\Enums;

enum ContentAutomationMode: string
{
    case SINGLE_POST = 'single_post';
    case CHAIN = 'chain';
    case PILLAR_PLUS_CLUSTER = 'pillar_plus_cluster';

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
            self::SINGLE_POST => 'Single post',
            self::CHAIN => 'Content chain',
            self::PILLAR_PLUS_CLUSTER => 'Pillar + cluster',
        };
    }
}
