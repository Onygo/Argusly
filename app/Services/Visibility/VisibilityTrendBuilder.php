<?php

namespace App\Services\Visibility;

use App\Models\Brand;
use App\Models\VisibilityScore;
use App\Models\VisibilityTrend;
use Illuminate\Database\Eloquent\Builder;

class VisibilityTrendBuilder
{
    public function buildForBrand(Brand $brand, ?string $provider = null): VisibilityTrend
    {
        $date = now()->toDateString();
        $scores = VisibilityScore::query()
            ->where('account_id', $brand->account_id)
            ->where('brand_id', $brand->id)
            ->when($provider !== null, fn (Builder $query) => $query->where('provider', $provider))
            ->whereDate('created_at', $date)
            ->get();

        return VisibilityTrend::query()->updateOrCreate(
            [
                'brand_id' => $brand->id,
                'period' => 'day',
                'period_date' => $date,
                'provider' => $provider,
            ],
            [
                'account_id' => $brand->account_id,
                'answer_presence_score' => $this->avg($scores, 'answer_presence_score'),
                'citation_score' => $this->avg($scores, 'citation_score'),
                'source_presence_score' => $this->avg($scores, 'source_presence_score'),
                'authority_score' => $this->avg($scores, 'authority_score'),
                'competitor_presence_score' => $this->avg($scores, 'competitor_presence_score'),
                'ai_attention_score' => $this->avg($scores, 'ai_attention_score'),
                'scores_count' => $scores->count(),
                'metadata_json' => [
                    'provider' => $provider,
                    'built_at' => now()->toIso8601String(),
                ],
            ],
        );
    }

    private function avg($scores, string $column): ?int
    {
        return $scores->isEmpty() ? null : (int) round($scores->avg($column));
    }
}
