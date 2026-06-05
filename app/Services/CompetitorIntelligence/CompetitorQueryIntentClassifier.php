<?php

namespace App\Services\CompetitorIntelligence;

class CompetitorQueryIntentClassifier
{
    /**
     * @return array{query_intent:string,funnel_stage:string}
     */
    public function classify(string $text): array
    {
        $text = strtolower($text);

        if ($this->hasAny($text, [' vs ', 'versus', 'alternative', 'alternatives', 'compare', 'comparison'])) {
            return ['query_intent' => 'comparison', 'funnel_stage' => 'bofu'];
        }

        if ($this->hasAny($text, ['pricing', 'price', 'demo', 'book a demo', 'buy', 'trial', 'quote'])) {
            return ['query_intent' => 'transactional', 'funnel_stage' => 'bofu'];
        }

        if ($this->hasAny($text, ['use case', 'case study', 'for teams', 'for agencies', 'solution for', 'industry'])) {
            return ['query_intent' => 'commercial_investigation', 'funnel_stage' => 'mofu'];
        }

        if ($this->hasAny($text, ['implementation', 'integrate', 'setup', 'configure', 'workflow', 'playbook', 'template'])) {
            return ['query_intent' => 'implementation', 'funnel_stage' => 'mofu'];
        }

        return ['query_intent' => 'informational', 'funnel_stage' => 'tofu'];
    }

    private function hasAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
