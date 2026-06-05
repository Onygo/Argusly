<?php

namespace App\Services\QueryIntent;

class FunnelStageMapper
{
    public function map(string $intent, string $text): string
    {
        $text = strtolower($text);

        if (str_contains($text, 'renewal') || str_contains($text, 'expansion') || str_contains($text, 'optimize existing')) {
            return 'retention';
        }

        return match ($intent) {
            'informational' => 'awareness',
            'commercial', 'implementation', 'risk_evaluation' => 'consideration',
            'transactional', 'comparison', 'migration', 'navigational' => 'decision',
            default => 'awareness',
        };
    }
}
