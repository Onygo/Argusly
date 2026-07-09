<?php

namespace App\Support\Intelligence;

enum IntelligenceStage: string
{
    case RAW_OBSERVATION = 'raw_observation';
    case SIGNAL = 'signal';
    case INSIGHT = 'insight';
    case RECOMMENDATION = 'recommendation';
    case ACTION = 'action';
    case OUTCOME = 'outcome';

    /**
     * @return array<int, self>
     */
    public static function progression(): array
    {
        return [
            self::RAW_OBSERVATION,
            self::SIGNAL,
            self::INSIGHT,
            self::RECOMMENDATION,
            self::ACTION,
            self::OUTCOME,
        ];
    }

    public function precedes(self $stage): bool
    {
        return array_search($this, self::progression(), true) < array_search($stage, self::progression(), true);
    }
}
