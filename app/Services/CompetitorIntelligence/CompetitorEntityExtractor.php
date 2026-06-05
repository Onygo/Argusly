<?php

namespace App\Services\CompetitorIntelligence;

class CompetitorEntityExtractor
{
    /**
     * @return array<int, string>
     */
    public function extract(string $text, int $limit = 12): array
    {
        preg_match_all('/\b(?:[A-Z][A-Za-z0-9]+(?:\s+[A-Z][A-Za-z0-9]+){0,3}|[A-Z]{2,})\b/', $text, $matches);

        return collect($matches[0] ?? [])
            ->map(fn (string $entity): string => trim($entity))
            ->filter(fn (string $entity): bool => strlen($entity) >= 3 && ! in_array($entity, ['The', 'And', 'For'], true))
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take($limit)
            ->values()
            ->all();
    }
}
