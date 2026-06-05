<?php

namespace App\Enums;

enum ContentAutomationRunStatus: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case PARTIAL = 'partial';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';

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
            self::QUEUED => 'Queued',
            self::RUNNING => 'Running',
            self::COMPLETED => 'Completed',
            self::PARTIAL => 'Partial',
            self::FAILED => 'Failed',
            self::SKIPPED => 'Skipped',
        };
    }
}
