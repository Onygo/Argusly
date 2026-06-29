<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

enum AgenticDetectorClassification: string
{
    case SIGNAL_ONLY = 'signal_only';
    case OPPORTUNITY_ONLY = 'opportunity_only';
    case SIGNAL_AND_OPPORTUNITY = 'signal_and_opportunity';
    case EXECUTION_ONLY = 'execution_only';
    case BLOCKED = 'blocked';

    public function canEmitSignal(): bool
    {
        return in_array($this, [self::SIGNAL_ONLY, self::SIGNAL_AND_OPPORTUNITY], true);
    }

    public function canEmitOpportunity(): bool
    {
        return in_array($this, [self::OPPORTUNITY_ONLY, self::SIGNAL_AND_OPPORTUNITY], true);
    }

    public function isExecutionOnly(): bool
    {
        return $this === self::EXECUTION_ONLY;
    }

    /**
     * @return array<int,string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $classification): string => $classification->value, self::cases());
    }
}
