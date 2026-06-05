<?php

namespace App\Enums;

enum ContentAutomationPublicationMode: string
{
    case DRAFT_ONLY = 'draft_only';
    case AUTO_PUBLISH = 'auto_publish';

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
            self::DRAFT_ONLY => 'Draft only',
            self::AUTO_PUBLISH => 'Auto publish',
        };
    }
}
