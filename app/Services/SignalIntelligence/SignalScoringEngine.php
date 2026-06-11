<?php

namespace App\Services\SignalIntelligence;

use App\Enums\SignalSeverity;
use App\Enums\SignalType;
use App\Models\SignalEvent;
use Illuminate\Support\Collection;

class SignalScoringEngine
{
    public function calculateSignalStrength(SignalEvent $event): float
    {
        $weights = $this->weights('signal_strength_weights');
        $riskOrOpportunity = max((float) ($event->risk_score ?? 0), (float) ($event->opportunity_score ?? 0));

        return $this->clamp(
            ((float) ($event->signal_strength ?? 0) * $weights['signal_strength'])
            + ((float) ($event->confidence_score ?? config('signal_intelligence.score_defaults.confidence', 50)) * $weights['confidence'])
            + ((float) ($event->impact_score ?? config('signal_intelligence.score_defaults.impact', 50)) * $weights['impact'])
            + ((float) ($event->urgency_score ?? 0) * $weights['urgency'])
            + ($riskOrOpportunity * $weights['risk_or_opportunity'])
        );
    }

    /**
     * @param Collection<int,SignalEvent> $events
     */
    public function calculateConfidenceScore(Collection $events): float
    {
        return $this->average($events->map(fn (SignalEvent $event): float => (float) ($event->confidence_score ?? 50)));
    }

    /**
     * @param Collection<int,SignalEvent> $events
     */
    public function calculateBrandVisibilityScore(Collection $events): float
    {
        $weights = $this->weights('brand_visibility_weights');
        $mentionedRatio = $events->count() > 0
            ? $events->filter(fn (SignalEvent $event): bool => $event->type?->value === SignalType::BRAND_MENTIONED->value)->count() / $events->count()
            : 0;
        $missingRatio = $events->count() > 0
            ? $events->filter(fn (SignalEvent $event): bool => $event->type?->value === SignalType::BRAND_MISSING->value)->count() / $events->count()
            : 0;

        return $this->clamp(
            ($this->averageStrength($events) * $weights['strength'])
            + ($this->calculateConfidenceScore($events) * $weights['confidence'])
            + ($this->averageField($events, 'impact_score', 50) * $weights['impact'])
            + (($mentionedRatio * 100 - $missingRatio * 50) * $weights['presence_bonus'])
        );
    }

    /**
     * @param Collection<int,SignalEvent> $events
     */
    public function calculateCompetitorPressureScore(Collection $events): float
    {
        $weights = $this->weights('competitor_pressure_weights');

        return $this->clamp(
            ($this->averageStrength($events) * $weights['strength'])
            + ($this->averageField($events, 'risk_score', 50) * $weights['risk'])
            + ($this->averageField($events, 'impact_score', 50) * $weights['impact'])
            + ($this->frequencyScore($events) * $weights['frequency'])
        );
    }

    /**
     * @param Collection<int,SignalEvent> $currentEvents
     * @param Collection<int,SignalEvent> $previousEvents
     */
    public function calculateTrendVelocityScore(Collection $currentEvents, Collection $previousEvents): float
    {
        $weights = $this->weights('trend_velocity_weights');
        $previousCount = max(1, $previousEvents->count());
        $growth = max(0, (($currentEvents->count() - $previousEvents->count()) / $previousCount) * 50);
        $sourceDiversity = min(100, $currentEvents->pluck('signal_source_id')->filter()->unique()->count() * 25);
        $recency = $currentEvents->max('observed_at')
            ? max(0, 100 - min(100, now()->diffInHours($currentEvents->max('observed_at')) * 4))
            : 50;

        return $this->clamp(
            ($growth * $weights['growth'])
            + ($this->frequencyScore($currentEvents) * $weights['frequency'])
            + ($sourceDiversity * $weights['source_diversity'])
            + ($recency * $weights['recency'])
        );
    }

    /**
     * @param Collection<int,SignalEvent> $events
     */
    public function calculateRiskScore(Collection $events): float
    {
        $weights = $this->weights('risk_weights');
        $negativeSentiment = $events->filter(function (SignalEvent $event): bool {
            $label = strtolower((string) data_get($event->metrics, 'sentiment_label', data_get($event->metadata, 'sentiment_label', '')));
            $score = data_get($event->metrics, 'sentiment_score', data_get($event->metadata, 'sentiment_score'));

            return $label === 'negative' || (is_numeric($score) && (float) $score < 0);
        })->count();
        $negativeScore = $events->count() > 0 ? ($negativeSentiment / $events->count()) * 100 : 0;

        return $this->clamp(
            ($this->averageField($events, 'risk_score', 50) * $weights['risk'])
            + ($this->severityScore($events) * $weights['severity'])
            + ($negativeScore * $weights['negative_sentiment'])
            + ($this->frequencyScore($events) * $weights['frequency'])
        );
    }

    /**
     * @param Collection<int,SignalEvent> $events
     */
    public function calculateOpportunityReadinessScore(Collection $events): float
    {
        $weights = $this->weights('opportunity_readiness_weights');

        return $this->clamp(
            ($this->averageField($events, 'opportunity_score', 50) * $weights['opportunity'])
            + ($this->averageField($events, 'impact_score', 50) * $weights['impact'])
            + ($this->calculateConfidenceScore($events) * $weights['confidence'])
            + ($this->frequencyScore($events) * $weights['frequency'])
        );
    }

    /**
     * @param Collection<int,SignalEvent> $events
     * @param Collection<int,SignalEvent> $previousEvents
     * @return array<string,mixed>
     */
    public function breakdown(Collection $events, Collection $previousEvents = new Collection()): array
    {
        return [
            'event_count' => $events->count(),
            'previous_event_count' => $previousEvents->count(),
            'avg_signal_strength' => $this->averageStrength($events),
            'confidence' => $this->calculateConfidenceScore($events),
            'brand_visibility' => $this->calculateBrandVisibilityScore($events),
            'competitor_pressure' => $this->calculateCompetitorPressureScore($events),
            'trend_velocity' => $this->calculateTrendVelocityScore($events, $previousEvents),
            'risk' => $this->calculateRiskScore($events),
            'opportunity_readiness' => $this->calculateOpportunityReadinessScore($events),
            'source_diversity' => $events->pluck('signal_source_id')->filter()->unique()->count(),
        ];
    }

    /**
     * @return array<string,float>
     */
    private function weights(string $key): array
    {
        return array_map('floatval', (array) config("signal_intelligence.scoring.{$key}", []));
    }

    /**
     * @param Collection<int,float|int> $values
     */
    private function average(Collection $values): float
    {
        $values = $values->filter(fn (mixed $value): bool => is_numeric($value));

        return $this->clamp($values->count() > 0 ? (float) $values->avg() : 0);
    }

    /**
     * @param Collection<int,SignalEvent> $events
     */
    private function averageStrength(Collection $events): float
    {
        return $this->average($events->map(fn (SignalEvent $event): float => $this->calculateSignalStrength($event)));
    }

    /**
     * @param Collection<int,SignalEvent> $events
     */
    private function averageField(Collection $events, string $field, float $default): float
    {
        return $this->average($events->map(fn (SignalEvent $event): float => (float) ($event->{$field} ?? $default)));
    }

    /**
     * @param Collection<int,SignalEvent> $events
     */
    private function frequencyScore(Collection $events): float
    {
        return $this->clamp(min(100, $events->count() * 20));
    }

    /**
     * @param Collection<int,SignalEvent> $events
     */
    private function severityScore(Collection $events): float
    {
        $map = [
            SignalSeverity::INFO->value => 20,
            SignalSeverity::LOW->value => 35,
            SignalSeverity::MEDIUM->value => 60,
            SignalSeverity::HIGH->value => 80,
            SignalSeverity::CRITICAL->value => 100,
        ];

        return $this->average($events->map(fn (SignalEvent $event): int => $map[$event->severity?->value ?? SignalSeverity::INFO->value] ?? 20));
    }

    private function clamp(float $score): float
    {
        return round(max(0, min(100, $score)), 2);
    }
}
