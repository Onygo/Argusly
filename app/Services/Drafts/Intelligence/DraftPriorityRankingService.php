<?php

namespace App\Services\Drafts\Intelligence;

class DraftPriorityRankingService
{
    public function score(string $impactLevel, string $effortLevel, string $confidenceLevel): int
    {
        $impact = $this->weight($impactLevel, ['low' => 1, 'medium' => 2, 'high' => 3]);
        $effort = $this->weight($effortLevel, ['low' => 1, 'medium' => 2, 'high' => 3]);
        $confidence = $this->weight($confidenceLevel, ['low' => 1, 'medium' => 2, 'high' => 3]);

        return ($impact * 100) + ($confidence * 20) - ($effort * 15);
    }

    /**
     * @param array<int,array<string,mixed>> $recommendations
     * @return array<int,array<string,mixed>>
     */
    public function order(array $recommendations): array
    {
        return collect($recommendations)
            ->map(function (array $recommendation): array {
                $recommendation['priority_score'] = $this->score(
                    (string) ($recommendation['impact_level'] ?? 'medium'),
                    (string) ($recommendation['effort_level'] ?? 'medium'),
                    (string) ($recommendation['confidence_level'] ?? 'medium'),
                );

                return $recommendation;
            })
            ->sort(function (array $left, array $right): int {
                $scoreCompare = ($right['priority_score'] ?? 0) <=> ($left['priority_score'] ?? 0);
                if ($scoreCompare !== 0) {
                    return $scoreCompare;
                }

                return strcmp(
                    (string) ($left['title'] ?? ''),
                    (string) ($right['title'] ?? '')
                );
            })
            ->values()
            ->map(function (array $recommendation, int $index): array {
                $recommendation['sort_order'] = $index + 1;

                return $recommendation;
            })
            ->all();
    }

    /**
     * @param array<string,int> $map
     */
    private function weight(string $value, array $map): int
    {
        return $map[strtolower(trim($value))] ?? 2;
    }
}
