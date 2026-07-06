<?php

namespace App\Services\PageIntelligence\PrValue\Concerns;

use App\Models\CompanyIntelligenceProfile;
use App\Models\PageEntity;
use App\Models\PageSentiment;
use App\Models\PageSnapshot;
use App\Models\PageTopic;
use Illuminate\Support\Carbon;

trait CalculatesPrValueFactors
{
    protected function prepared(PageSnapshot $snapshot): PageSnapshot
    {
        return $snapshot->loadMissing(['page.source', 'contentExtraction']);
    }

    protected function sourceAuthority(PageSnapshot $snapshot): float
    {
        $source = $snapshot->page?->source;

        if (! $source) {
            return 25;
        }

        $authority = (float) $source->authority_score;
        $trust = (int) $source->trust_level * 10;

        return $this->clamp(max($authority, $trust, 25));
    }

    protected function estimatedReach(PageSnapshot $snapshot): ?float
    {
        $source = $snapshot->page?->source;
        $metadata = (array) ($source?->metadata_json ?? []);
        $reach = data_get($metadata, 'estimated_reach')
            ?? data_get($metadata, 'monthly_visitors')
            ?? data_get($snapshot->page?->metadata_json, 'estimated_reach')
            ?? data_get($snapshot->metadata_json, 'estimated_reach');

        return is_numeric($reach) ? max(0, (float) $reach) : null;
    }

    protected function reachScore(?float $reach): float
    {
        if ($reach === null) {
            return 35;
        }

        return $this->clamp(log10(max(1, $reach)) * 16);
    }

    protected function pageVisibility(PageSnapshot $snapshot): float
    {
        $statusScore = match (true) {
            $snapshot->http_status >= 200 && $snapshot->http_status < 300 => 70,
            $snapshot->http_status >= 300 && $snapshot->http_status < 400 => 45,
            $snapshot->http_status >= 400 => 10,
            default => 35,
        };
        $indexability = $snapshot->page?->indexability_status === 'indexable' ? 20 : 0;
        $changed = $snapshot->content_changed ? 10 : 0;

        return $this->clamp($statusScore + $indexability + $changed);
    }

    protected function sentimentFactor(PageSnapshot $snapshot): array
    {
        $sentiment = PageSentiment::query()
            ->where('page_snapshot_id', $snapshot->id)
            ->whereIn('target_type', [PageSentiment::TARGET_BRAND, PageSentiment::TARGET_ENTITY, PageSentiment::TARGET_PAGE])
            ->orderByRaw("case when target_type = 'brand' then 0 when target_type = 'entity' then 1 else 2 end")
            ->first();

        $compound = $sentiment ? (float) $sentiment->compound_score : 0.0;
        $score = $this->clamp(($compound + 1) * 50);

        return [
            'score' => $score,
            'compound_score' => $compound,
            'label' => $sentiment?->label ?? 'unknown',
            'source_id' => $sentiment?->id,
        ];
    }

    protected function brandProminence(PageSnapshot $snapshot): float
    {
        $prominence = PageEntity::query()
            ->where('page_snapshot_id', $snapshot->id)
            ->where('entity_type', PageEntity::TYPE_BRAND)
            ->max('prominence_score');

        return $prominence !== null ? $this->clamp((float) $prominence) : 0;
    }

    protected function topicRelevance(PageSnapshot $snapshot): float
    {
        $avg = PageTopic::query()
            ->where('page_snapshot_id', $snapshot->id)
            ->avg('confidence_score');

        return $avg !== null ? $this->clamp((float) $avg) : 25;
    }

    protected function contentDepth(PageSnapshot $snapshot): float
    {
        $extraction = $snapshot->contentExtraction;
        $depth = $extraction?->content_depth_score;

        if ($depth !== null) {
            return $this->clamp((float) $depth);
        }

        $words = (int) ($extraction?->word_count ?? 0);

        return $this->clamp(min(100, $words / 12));
    }

    protected function industryRelevance(PageSnapshot $snapshot): float
    {
        $profiles = CompanyIntelligenceProfile::query()
            ->where('workspace_id', $snapshot->workspace_id)
            ->where('status', CompanyIntelligenceProfile::STATUS_ACTIVE)
            ->get();

        if ($profiles->isEmpty()) {
            return 30;
        }

        $topics = PageTopic::query()
            ->where('page_snapshot_id', $snapshot->id)
            ->pluck('topic_key')
            ->all();

        $terms = $profiles
            ->flatMap(fn (CompanyIntelligenceProfile $profile): array => array_filter(array_merge(
                [(string) $profile->market_category],
                (array) $profile->primary_topics,
                (array) $profile->authority_areas,
                (array) $profile->strategic_keywords,
            )))
            ->map(fn (string $term): string => $this->key($term))
            ->unique();

        $matches = $terms->intersect($topics)->count();

        return $this->clamp(30 + ($matches * 20));
    }

    protected function competitorContext(PageSnapshot $snapshot): float
    {
        $competitorCount = PageEntity::query()
            ->where('page_snapshot_id', $snapshot->id)
            ->where('entity_type', PageEntity::TYPE_COMPETITOR)
            ->sum('mention_count');

        return $this->clamp(50 + min(30, (int) $competitorCount * 10));
    }

    protected function recency(PageSnapshot $snapshot): float
    {
        $date = $snapshot->page?->published_at_current ?: $snapshot->fetched_at;

        if (! $date) {
            return 45;
        }

        $days = Carbon::parse($date)->diffInDays(now());

        return $this->clamp(match (true) {
            $days <= 7 => 100,
            $days <= 30 => 85,
            $days <= 90 => 65,
            $days <= 365 => 45,
            default => 25,
        });
    }

    /**
     * @param array<string,array{score:float,weight:float}> $factors
     */
    protected function weightedScore(array $factors): float
    {
        $totalWeight = array_sum(array_column($factors, 'weight'));

        if ($totalWeight <= 0) {
            return 0;
        }

        $weighted = 0;
        foreach ($factors as $factor) {
            $weighted += $this->clamp((float) $factor['score']) * (float) $factor['weight'];
        }

        return round($weighted / $totalWeight, 2);
    }

    protected function confidence(PageSnapshot $snapshot, ?float $reach, int $requiredSignalCount = 4): float
    {
        $available = 0;
        $available += $snapshot->page?->source ? 1 : 0;
        $available += $reach !== null ? 1 : 0;
        $available += PageEntity::query()->where('page_snapshot_id', $snapshot->id)->exists() ? 1 : 0;
        $available += PageTopic::query()->where('page_snapshot_id', $snapshot->id)->exists() ? 1 : 0;
        $available += PageSentiment::query()->where('page_snapshot_id', $snapshot->id)->exists() ? 1 : 0;
        $available += $snapshot->contentExtraction ? 1 : 0;

        return round($this->clamp(($available / max(1, $requiredSignalCount)) * 100), 2);
    }

    protected function estimatedValue(float $score, ?float $reach, float $baseRate = 0.08): float
    {
        $effectiveReach = $reach ?? 1000;

        return round(max(0, $effectiveReach * $baseRate * ($score / 100)), 2);
    }

    protected function clamp(float $value, float $min = 0, float $max = 100): float
    {
        return round(min($max, max($min, $value)), 2);
    }

    protected function key(string $value): string
    {
        $key = strtolower(trim($value));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key;

        return trim($key, '_');
    }
}
