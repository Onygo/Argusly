<?php

namespace App\Services;

use App\Jobs\CalculateContentLifecycleScoreJob;
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
        $contentAsset->loadMissing(['audits' => fn ($query) => $query->where('status', 'completed')->latest('audited_at')->latest()]);

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

        $visibilityScore = $auditScore ?? match (true) {
            filled($contentAsset->seo_metadata) && filled($contentAsset->metadata) => 75,
            filled($contentAsset->seo_metadata) || filled($contentAsset->metadata) => 60,
            default => 45,
        };

        $healthScore = (int) round(($freshnessScore * 0.4) + ($performanceScore * 0.25) + ($visibilityScore * 0.35));

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
            ],
        ];
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
