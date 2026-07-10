<?php

namespace App\Services\Attribution\Models\Concerns;

use App\Models\AttributionTouchpoint;
use Illuminate\Support\Collection;

trait AllocatesAttributionCredit
{
    /**
     * @param  Collection<int, array{touchpoint: AttributionTouchpoint, match_confidence: string, score: int}>  $matches
     * @return Collection<int, array{touchpoint: AttributionTouchpoint, credit: float, metadata: array<string, mixed>}>
     */
    protected function allocateWeights(Collection $matches, array $weights, string $strategy): Collection
    {
        $matches = $matches->values();
        $sum = array_sum($weights);

        if ($matches->isEmpty() || $sum <= 0) {
            return collect();
        }

        return $matches->map(function (array $match, int $index) use ($weights, $sum, $strategy): array {
            return [
                'touchpoint' => $match['touchpoint'],
                'credit' => round((float) ($weights[$index] ?? 0) / $sum, 8),
                'metadata' => [
                    'strategy' => $strategy,
                    'match_confidence' => $match['match_confidence'],
                    'match_score' => $match['score'],
                ],
            ];
        })->filter(fn (array $allocation): bool => $allocation['credit'] > 0)->values();
    }
}
