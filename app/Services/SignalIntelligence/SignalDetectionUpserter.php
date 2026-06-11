<?php

namespace App\Services\SignalIntelligence;

use App\Enums\SignalSeverity;
use App\Enums\SignalStatus;
use App\Models\ClientSite;
use App\Models\SignalDetection;
use App\Models\SignalEvent;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class SignalDetectionUpserter
{
    public function __construct(
        private readonly SignalScoringEngine $scoring,
        private readonly SignalDetectionLinker $linker,
    ) {}

    /**
     * @param Collection<int,SignalEvent> $events
     * @param Collection<int,SignalEvent> $previousEvents
     * @param array<string,mixed> $attributes
     */
    public function upsert(
        Workspace $workspace,
        string $category,
        string $type,
        Collection $events,
        CarbonInterface $from,
        CarbonInterface $to,
        ?ClientSite $clientSite = null,
        ?string $primaryEntity = null,
        ?string $primaryTopic = null,
        array $attributes = [],
        Collection $previousEvents = new Collection(),
    ): SignalDetection {
        $breakdown = $this->scoring->breakdown($events, $previousEvents);
        $priority = max(
            (float) $breakdown['brand_visibility'],
            (float) $breakdown['competitor_pressure'],
            (float) $breakdown['trend_velocity'],
            (float) $breakdown['risk'],
            (float) $breakdown['opportunity_readiness']
        );
        $hash = $this->dedupeHash($workspace, $clientSite, $category, $type, $primaryEntity, $primaryTopic, $from, $to);

        $detection = SignalDetection::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'dedupe_hash' => $hash,
            ],
            [
                'organization_id' => $workspace->organization_id,
                'client_site_id' => $clientSite?->id,
                'category' => $category,
                'type' => $type,
                'status' => SignalStatus::DETECTED->value,
                'title' => (string) ($attributes['title'] ?? $this->title($type, $primaryEntity, $primaryTopic)),
                'summary' => (string) ($attributes['summary'] ?? $this->summary($type, $events->count())),
                'primary_topic' => $primaryTopic,
                'primary_entity' => $primaryEntity,
                'severity' => $attributes['severity'] ?? $this->severity($priority)->value,
                'priority_score' => $priority,
                'confidence_score' => $this->scoring->calculateConfidenceScore($events),
                'impact_score' => (float) ($breakdown['avg_signal_strength'] ?? 50),
                'urgency_score' => (float) ($breakdown['trend_velocity'] ?? 0),
                'risk_score' => (float) ($breakdown['risk'] ?? 0),
                'opportunity_score' => (float) ($breakdown['opportunity_readiness'] ?? 0),
                'score_breakdown' => $breakdown,
                'evidence_summary' => [],
                'recommended_actions' => $attributes['recommended_actions'] ?? [],
                'first_seen_at' => $events->min('observed_at') ?? $from,
                'last_seen_at' => $events->max('observed_at') ?? $to,
                'metadata' => array_merge([
                    'period_from' => $from->toDateTimeString(),
                    'period_to' => $to->toDateTimeString(),
                ], (array) ($attributes['metadata'] ?? [])),
            ]
        );

        $detection->forceFill([
            'priority_score' => $priority,
            'confidence_score' => $this->scoring->calculateConfidenceScore($events),
            'impact_score' => (float) ($breakdown['avg_signal_strength'] ?? 50),
            'urgency_score' => (float) ($breakdown['trend_velocity'] ?? 0),
            'risk_score' => (float) ($breakdown['risk'] ?? 0),
            'opportunity_score' => (float) ($breakdown['opportunity_readiness'] ?? 0),
            'score_breakdown' => $breakdown,
            'last_seen_at' => $events->max('observed_at') ?? $to,
        ])->save();

        return $this->linker->linkMany($detection, $events);
    }

    private function dedupeHash(
        Workspace $workspace,
        ?ClientSite $clientSite,
        string $category,
        string $type,
        ?string $primaryEntity,
        ?string $primaryTopic,
        CarbonInterface $from,
        CarbonInterface $to,
    ): string {
        return hash('sha256', implode('|', [
            $workspace->id,
            $clientSite?->id ?? '',
            $category,
            $type,
            strtolower((string) $primaryEntity),
            strtolower((string) $primaryTopic),
            $from->toDateString(),
            $to->toDateString(),
        ]));
    }

    private function severity(float $priority): SignalSeverity
    {
        return match (true) {
            $priority >= 85 => SignalSeverity::CRITICAL,
            $priority >= 70 => SignalSeverity::HIGH,
            $priority >= 50 => SignalSeverity::MEDIUM,
            $priority >= 30 => SignalSeverity::LOW,
            default => SignalSeverity::INFO,
        };
    }

    private function title(string $type, ?string $entity, ?string $topic): string
    {
        return trim(str_replace('_', ' ', $type).' '.($entity ?: $topic ?: 'signal'));
    }

    private function summary(string $type, int $count): string
    {
        return sprintf('%d signal event(s) matched detection rule %s.', $count, $type);
    }
}
