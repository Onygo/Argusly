<?php

namespace App\Services\AgenticMarketing\Intelligence;

use App\Enums\AgenticMarketingOpportunityStatus;
use App\Enums\AgenticMarketingOpportunityType;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\Notification;
use App\Models\Workspace;
use App\Services\Notifications\NotificationService;

class RecommendationLifecycle
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /**
     * Stores recommendations as open Agentic Marketing opportunities without
     * creating executable actions.
     *
     * @return array{created:int,reused:int,opportunity_ids:array<int, string>}
     */
    public function persistRecommendations(AgenticMarketingObjective $objective, ReasoningSnapshot $snapshot): array
    {
        $summary = [
            'created' => 0,
            'reused' => 0,
            'opportunity_ids' => [],
        ];

        foreach ($snapshot->recommendations as $recommendation) {
            $opportunity = AgenticMarketingOpportunity::createOrReuseOpen([
                'objective_id' => (string) $objective->id,
                'title' => $recommendation->title,
                'type' => $this->opportunityType($recommendation),
                'priority_score' => $recommendation->priority,
                'status' => AgenticMarketingOpportunityStatus::Open->value,
                'payload' => [
                    'source' => 'agentic_marketing_intelligence',
                    'automatic_execution' => false,
                    'reasoning_snapshot_fingerprint' => $snapshot->fingerprint(),
                    'model_key' => $snapshot->modelKey,
                    'model_version' => $snapshot->modelVersion,
                    'period' => [
                        'start' => $snapshot->periodStart->toDateTimeString(),
                        'end' => $snapshot->periodEnd->toDateTimeString(),
                        'granularity' => $snapshot->granularity,
                    ],
                    'recommendation' => $recommendation->toArray(),
                    'evidence' => $recommendation->evidence->toArray(),
                    'market_pack_context' => $recommendation->marketPackContext,
                ],
            ]);

            if ($opportunity->wasRecentlyCreated) {
                $summary['created']++;
            } else {
                $summary['reused']++;
                $this->refreshOpportunity($opportunity, $recommendation, $snapshot);
            }

            $summary['opportunity_ids'][] = (string) $opportunity->id;
        }

        $summary['opportunity_ids'] = array_values(array_unique($summary['opportunity_ids']));

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function notifyWorkspace(Workspace $workspace, ReasoningSnapshot $snapshot, array $options = []): ?Notification
    {
        $top = collect($snapshot->recommendations)->sortByDesc(fn (MarketingRecommendation $recommendation): int => $recommendation->priority)->first();

        if (! $top instanceof MarketingRecommendation) {
            return null;
        }

        $threshold = (int) config('argusly.agentic_marketing_intelligence.thresholds.notification_priority_min', 65);
        $force = (bool) ($options['force'] ?? false);

        if (! $force && $top->priority < $threshold) {
            return null;
        }

        $count = count($snapshot->recommendations);
        $title = $count === 1
            ? 'Marketing intelligence recommendation ready'
            : $count.' marketing intelligence recommendations ready';
        $body = $top->title.' Priority '.$top->priority.', confidence '.number_format($top->confidence * 100, 0).'%.';

        return $this->notifications->notifyWorkspace(
            (string) $workspace->id,
            $top->priority >= $threshold ? Notification::TYPE_ACTION_REQUIRED : Notification::TYPE_SYSTEM,
            $title,
            $body,
            [
                'priority' => $top->priority,
                'dedupe_key' => 'agentic-marketing-intelligence:'.$snapshot->fingerprint(),
                'meta' => [
                    'source' => 'agentic_marketing_intelligence',
                    'automatic_execution' => false,
                    'reasoning_snapshot_fingerprint' => $snapshot->fingerprint(),
                    'model_key' => $snapshot->modelKey,
                    'model_version' => $snapshot->modelVersion,
                    'period_start' => $snapshot->periodStart->toDateTimeString(),
                    'period_end' => $snapshot->periodEnd->toDateTimeString(),
                    'recommendation_keys' => collect($snapshot->recommendations)->pluck('key')->values()->all(),
                    'top_recommendation' => $top->toArray(),
                    'evidence' => [
                        'marketing_observation_ids' => $snapshot->evidence->marketingObservationIds,
                        'page_snapshot_ids' => $snapshot->evidence->pageSnapshotIds,
                        'page_score_ids' => $snapshot->evidence->pageScoreIds,
                        'trend_ids' => $snapshot->evidence->trendIds,
                        'performance_signal_keys' => $snapshot->evidence->performanceSignalKeys,
                        'report_ids' => $snapshot->evidence->reportIds,
                        'scheduled_briefing_ids' => $snapshot->evidence->scheduledBriefingIds,
                    ],
                    'market_pack_context' => $snapshot->marketPackContext,
                ],
            ]
        );
    }

    public function dismiss(AgenticMarketingOpportunity $opportunity, ?string $reason = null): AgenticMarketingOpportunity
    {
        $payload = (array) ($opportunity->payload ?? []);
        $payload['lifecycle'] = array_replace((array) ($payload['lifecycle'] ?? []), [
            'status' => AgenticMarketingOpportunityStatus::Dismissed->value,
            'reason' => $reason,
            'changed_at' => now()->toISOString(),
        ]);

        $opportunity->forceFill([
            'status' => AgenticMarketingOpportunityStatus::Dismissed->value,
            'payload' => $payload,
        ])->save();

        return $opportunity;
    }

    private function opportunityType(MarketingRecommendation $recommendation): string
    {
        return match ($recommendation->type) {
            'ai_visibility_opportunity' => AgenticMarketingOpportunityType::AiVisibility->value,
            'search_visibility_opportunity', 'content_momentum_opportunity', 'performance_risk', 'score_risk' => AgenticMarketingOpportunityType::Refresh->value,
            'competitive_risk', 'earned_media_opportunity', 'momentum_opportunity' => AgenticMarketingOpportunityType::ContentNetwork->value,
            'measurement_risk' => 'measurement_coverage',
            default => $recommendation->type,
        };
    }

    private function refreshOpportunity(AgenticMarketingOpportunity $opportunity, MarketingRecommendation $recommendation, ReasoningSnapshot $snapshot): void
    {
        $payload = (array) ($opportunity->payload ?? []);
        $payload['recommendation'] = $recommendation->toArray();
        $payload['evidence'] = $recommendation->evidence->toArray();
        $payload['reasoning_snapshot_fingerprint'] = $snapshot->fingerprint();

        $opportunity->forceFill([
            'title' => $recommendation->title,
            'priority_score' => $recommendation->priority,
            'payload' => $payload,
        ])->save();
    }
}
