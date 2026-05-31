<?php

namespace App\Services;

use App\Jobs\CalculateContentLifecycleScoreJob;
use App\Models\Ga4MetricSnapshot;
use App\Models\SearchConsoleQuerySnapshot;
use App\Models\ContentAsset;
use App\Models\ContentLifecycleScore;
use App\Models\User;
use App\Services\Signals\SignalManager;

class ContentLifecycleService
{
    public function __construct(
        private readonly SignalManager $signals,
        private readonly CreditService $credits,
    ) {}

    public function requestForContentAsset(ContentAsset $contentAsset, User $user): void
    {
        $this->credits->consume(
            $contentAsset->account,
            $user,
            'content_lifecycle',
            'Content lifecycle check requested.',
            $contentAsset,
            ['content_asset_id' => $contentAsset->id],
        );

        CalculateContentLifecycleScoreJob::dispatch($contentAsset->id);
    }

    public function calculateForContentAsset(ContentAsset $contentAsset): ContentLifecycleScore
    {
        $contentAsset->loadMissing([
            'audits' => fn ($query) => $query->where('status', 'completed')->latest('audited_at')->latest(),
            'socialPosts',
            'sourceTranslations',
            'answerBlocks',
            'brand',
            'account',
        ]);

        $scores = $this->scores($contentAsset);
        $status = $this->statusFor($scores['health_score']);
        $refreshPriority = $this->refreshPriority($status, $scores);
        $reason = $this->reason($contentAsset, $status, $scores);

        $score = ContentLifecycleScore::query()->create([
            'account_id' => $contentAsset->account_id,
            'brand_id' => $contentAsset->brand_id,
            'content_asset_id' => $contentAsset->id,
            'language' => $contentAsset->language,
            'locale' => $contentAsset->locale,
            'status' => $status,
            'health_score' => $scores['health_score'],
            'freshness_score' => $scores['freshness_score'],
            'performance_score' => $scores['performance_score'],
            'visibility_score' => $scores['visibility_score'],
            'refresh_priority' => $refreshPriority,
            'reason' => $reason,
            'signals' => $scores['signals'],
            'scored_at' => now(),
        ]);

        $this->signals->produce($score);
        app(DomainEventService::class)->recordForSubject('LifecycleScoreCalculated', $score, null, [
            'content_asset_id' => $score->content_asset_id,
            'language' => $score->language,
            'locale' => $score->locale,
            'status' => $score->status,
            'health_score' => $score->health_score,
            'refresh_priority' => $score->refresh_priority,
            'signals' => $score->signals,
        ], $score->scored_at);

        return $score;
    }

    /**
     * @return array{freshness_score: int, performance_score: int, visibility_score: int, health_score: int, signals: array<string, mixed>}
     */
    private function scores(ContentAsset $contentAsset): array
    {
        $referenceDate = $contentAsset->last_refreshed_at ?? $contentAsset->published_at ?? $contentAsset->created_at;
        $daysSinceRefresh = $referenceDate ? (int) $referenceDate->diffInDays(now()) : 999;
        $wordCount = str_word_count(strip_tags((string) $contentAsset->body));
        $latestAudit = $contentAsset->audits->first();
        $auditScore = $latestAudit?->score;
        $ga4 = $this->ga4Trend($contentAsset);
        $search = $this->searchTrend($contentAsset);
        $distribution = $this->distributionStatus($contentAsset);
        $translations = $this->translationCoverage($contentAsset);
        $answerBlocks = $contentAsset->answerBlocks->whereIn('status', ['approved', 'published'])->count();

        $freshnessScore = match (true) {
            $daysSinceRefresh <= 30 => 95,
            $daysSinceRefresh <= 90 => 80,
            $daysSinceRefresh <= 180 => 60,
            $daysSinceRefresh <= 365 => 40,
            default => 20,
        };

        $performanceScore = match (true) {
            $wordCount >= 300 && $wordCount <= 1800 => 85,
            $wordCount >= 120 => 70,
            $wordCount >= 60 => 55,
            default => 35,
        };

        if ($ga4['has_data']) {
            $performanceScore = (int) round(($performanceScore * 0.55) + ($ga4['score'] * 0.25) + ($search['has_data'] ? $search['score'] * 0.20 : $performanceScore * 0.20));
        } elseif ($search['has_data']) {
            $performanceScore = (int) round(($performanceScore * 0.65) + ($search['score'] * 0.35));
        }

        if ($distribution['published_or_scheduled']) {
            $performanceScore += 5;
        }

        $visibilityScore = $auditScore ?? match (true) {
            filled($contentAsset->seo_metadata) && filled($contentAsset->metadata) => 75,
            filled($contentAsset->seo_metadata) || filled($contentAsset->metadata) => 60,
            default => 45,
        };

        if ($search['has_data']) {
            $visibilityScore = (int) round(($visibilityScore * 0.65) + ($search['visibility_score'] * 0.35));
        }

        if ($answerBlocks > 0) {
            $visibilityScore += 5;
        }

        $freshnessScore = $this->bounded($freshnessScore);
        $performanceScore = $this->bounded($performanceScore);
        $visibilityScore = $this->bounded($visibilityScore);
        $healthScore = (int) round(($freshnessScore * 0.4) + ($performanceScore * 0.25) + ($visibilityScore * 0.35));
        $recommendations = $this->recommendations($contentAsset, [
            'days_since_refresh' => $daysSinceRefresh,
            'audit_score' => $auditScore,
            'ga4' => $ga4,
            'search' => $search,
            'distribution' => $distribution,
            'translations' => $translations,
            'answer_blocks' => $answerBlocks,
        ]);

        return [
            'freshness_score' => $freshnessScore,
            'performance_score' => $performanceScore,
            'visibility_score' => $this->bounded($visibilityScore),
            'health_score' => $this->bounded($healthScore),
            'signals' => [
                'days_since_refresh' => $daysSinceRefresh,
                'word_count' => $wordCount,
                'latest_audit_score' => $auditScore,
                'language' => $contentAsset->language,
                'locale' => $contentAsset->locale,
                'reference_date' => $referenceDate?->toDateTimeString(),
                'ga4' => $ga4,
                'search_console' => $search,
                'social_distribution' => $distribution,
                'translation_coverage' => $translations,
                'answer_blocks_count' => $answerBlocks,
                'recommendations' => $recommendations,
            ],
        ];
    }

    private function ga4Trend(ContentAsset $contentAsset): array
    {
        $currentStart = now()->subDays(29)->toDateString();
        $previousStart = now()->subDays(59)->toDateString();
        $previousEnd = now()->subDays(30)->toDateString();

        $current = (int) Ga4MetricSnapshot::query()
            ->where('account_id', $contentAsset->account_id)
            ->where('brand_id', $contentAsset->brand_id)
            ->where('content_asset_id', $contentAsset->id)
            ->whereDate('date', '>=', $currentStart)
            ->sum('sessions');

        $previous = (int) Ga4MetricSnapshot::query()
            ->where('account_id', $contentAsset->account_id)
            ->where('brand_id', $contentAsset->brand_id)
            ->where('content_asset_id', $contentAsset->id)
            ->whereDate('date', '>=', $previousStart)
            ->whereDate('date', '<=', $previousEnd)
            ->sum('sessions');

        $change = $previous > 0 ? ($current - $previous) / $previous : null;
        $score = match (true) {
            $previous === 0 && $current > 0 => 80,
            $current === 0 && $previous > 0 => 25,
            $change === null => 60,
            $change >= 0.1 => 85,
            $change >= -0.1 => 72,
            $change >= -0.35 => 55,
            default => 30,
        };

        return [
            'has_data' => $current > 0 || $previous > 0,
            'current_sessions' => $current,
            'previous_sessions' => $previous,
            'change_ratio' => $change,
            'trend' => $this->trendLabel($change, $current, $previous),
            'score' => $score,
        ];
    }

    private function searchTrend(ContentAsset $contentAsset): array
    {
        $currentStart = now()->subDays(29)->toDateString();
        $previousStart = now()->subDays(59)->toDateString();
        $previousEnd = now()->subDays(30)->toDateString();

        $current = $this->searchTotals($contentAsset, $currentStart, now()->toDateString());
        $previous = $this->searchTotals($contentAsset, $previousStart, $previousEnd);
        $clickChange = $previous['clicks'] > 0 ? ($current['clicks'] - $previous['clicks']) / $previous['clicks'] : null;
        $impressionChange = $previous['impressions'] > 0 ? ($current['impressions'] - $previous['impressions']) / $previous['impressions'] : null;
        $positionDelta = $current['position'] !== null && $previous['position'] !== null ? $current['position'] - $previous['position'] : null;
        $hasData = $current['clicks'] > 0 || $current['impressions'] > 0 || $previous['clicks'] > 0 || $previous['impressions'] > 0;

        $score = 60;
        if ($hasData) {
            $score = 70;
            $score += $clickChange !== null ? (int) round($clickChange * 35) : 0;
            $score += $impressionChange !== null ? (int) round($impressionChange * 20) : 0;
            $score -= $positionDelta !== null ? (int) round($positionDelta * 3) : 0;
        }

        $visibilityScore = $score;
        if ($current['ctr'] !== null && $current['impressions'] >= 100) {
            $visibilityScore += $current['ctr'] >= 0.03 ? 8 : -12;
        }

        return [
            'has_data' => $hasData,
            'current_clicks' => $current['clicks'],
            'previous_clicks' => $previous['clicks'],
            'current_impressions' => $current['impressions'],
            'previous_impressions' => $previous['impressions'],
            'current_ctr' => $current['ctr'],
            'current_position' => $current['position'],
            'previous_position' => $previous['position'],
            'click_change_ratio' => $clickChange,
            'impression_change_ratio' => $impressionChange,
            'position_delta' => $positionDelta,
            'score' => $this->bounded($score),
            'visibility_score' => $this->bounded($visibilityScore),
        ];
    }

    private function searchTotals(ContentAsset $contentAsset, string $startDate, string $endDate): array
    {
        $row = SearchConsoleQuerySnapshot::query()
            ->where('account_id', $contentAsset->account_id)
            ->where('brand_id', $contentAsset->brand_id)
            ->where('content_asset_id', $contentAsset->id)
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->selectRaw('COALESCE(SUM(clicks), 0) as clicks_total')
            ->selectRaw('COALESCE(SUM(impressions), 0) as impressions_total')
            ->selectRaw('AVG(ctr) as ctr_average')
            ->selectRaw('AVG(position) as position_average')
            ->first();

        return [
            'clicks' => (int) ($row?->clicks_total ?? 0),
            'impressions' => (int) ($row?->impressions_total ?? 0),
            'ctr' => $row?->ctr_average !== null ? (float) $row->ctr_average : null,
            'position' => $row?->position_average !== null ? (float) $row->position_average : null,
        ];
    }

    private function distributionStatus(ContentAsset $contentAsset): array
    {
        $statuses = $contentAsset->socialPosts->pluck('status')->all();
        $publishedOrScheduled = $contentAsset->socialPosts->whereIn('status', ['published', 'scheduled', 'queued', 'publishing'])->isNotEmpty();

        return [
            'has_distribution' => $contentAsset->socialPosts->isNotEmpty(),
            'published_or_scheduled' => $publishedOrScheduled,
            'statuses' => array_values(array_unique($statuses)),
        ];
    }

    private function translationCoverage(ContentAsset $contentAsset): array
    {
        $configured = $contentAsset->brand?->enabled_content_languages;
        $enabled = is_array($configured) && $configured !== []
            ? app(ContentLanguageService::class)->enabledCodesForBrand($contentAsset->brand)
            : [$contentAsset->language ?? app(ContentLanguageService::class)->defaultFor($contentAsset->brand, $contentAsset->account)];
        $targets = collect($enabled)->reject(fn (string $language) => $language === $contentAsset->language)->values();
        $completed = $contentAsset->sourceTranslations
            ->where('status', 'completed')
            ->pluck('target_language')
            ->unique()
            ->values();
        $missing = $targets->diff($completed)->values();
        $ratio = $targets->isEmpty() ? 1.0 : round($completed->intersect($targets)->count() / $targets->count(), 2);

        return [
            'enabled_count' => count($enabled),
            'completed_languages' => $completed->all(),
            'missing_languages' => $missing->all(),
            'coverage_ratio' => $ratio,
        ];
    }

    private function recommendations(ContentAsset $contentAsset, array $signals): array
    {
        $recommendations = [];

        if (($signals['days_since_refresh'] ?? 0) > 180 || ($signals['ga4']['change_ratio'] ?? 0) <= -0.35 || ($signals['search']['click_change_ratio'] ?? 0) <= -0.35) {
            $recommendations[] = 'refresh content';
        }

        if (($signals['search']['current_impressions'] ?? 0) >= 100 && ($signals['search']['current_ctr'] ?? 1) < 0.02) {
            $recommendations[] = 'improve title/meta for CTR';
        }

        if (! ($signals['distribution']['published_or_scheduled'] ?? false)) {
            $recommendations[] = 'create social distribution';
        }

        if (($signals['translations']['enabled_count'] ?? 0) > 1 && ! empty($signals['translations']['missing_languages'] ?? [])) {
            $recommendations[] = 'translate to missing languages';
        }

        if (($signals['answer_blocks'] ?? 0) === 0) {
            $recommendations[] = 'add answer blocks';
        }

        if (($signals['audit_score'] ?? null) === null || ($signals['audit_score'] ?? 100) < 70) {
            $recommendations[] = 'run audit';
        }

        return array_values(array_unique($recommendations));
    }

    private function trendLabel(?float $change, int $current, int $previous): string
    {
        if ($current === 0 && $previous === 0) {
            return 'no_data';
        }

        return match (true) {
            $change === null => 'new',
            $change >= 0.1 => 'growing',
            $change <= -0.35 => 'declining',
            $change < -0.1 => 'softening',
            default => 'stable',
        };
    }

    private function statusFor(int $healthScore): string
    {
        return match (true) {
            $healthScore >= 80 => 'healthy',
            $healthScore >= 65 => 'watch',
            $healthScore >= 50 => 'decaying',
            $healthScore >= 35 => 'needs_refresh',
            default => 'critical',
        };
    }

    /**
     * @param  array{freshness_score: int, performance_score: int, visibility_score: int, health_score: int, signals: array<string, mixed>}  $scores
     */
    private function refreshPriority(string $status, array $scores): int
    {
        $base = 100 - $scores['health_score'];
        $statusBoost = match ($status) {
            'critical' => 25,
            'needs_refresh' => 18,
            'decaying' => 10,
            'watch' => 5,
            default => 0,
        };

        return $this->bounded($base + $statusBoost);
    }

    /**
     * @param  array{freshness_score: int, performance_score: int, visibility_score: int, health_score: int, signals: array<string, mixed>}  $scores
     */
    private function reason(ContentAsset $contentAsset, string $status, array $scores): string
    {
        return match ($status) {
            'healthy' => 'Content lifecycle looks healthy based on freshness, length and latest audit visibility.',
            'watch' => 'Content is acceptable but should be watched for freshness or visibility drift.',
            'decaying' => 'Content is starting to decay and may benefit from a planned refresh.',
            'needs_refresh' => 'Content needs a refresh based on age, content length or audit visibility signals.',
            'critical' => 'Content is critically stale or underperforming and should be refreshed soon.',
            default => "Lifecycle score calculated for {$contentAsset->title}.",
        };
    }

    private function bounded(int $score): int
    {
        return max(0, min(100, $score));
    }
}
