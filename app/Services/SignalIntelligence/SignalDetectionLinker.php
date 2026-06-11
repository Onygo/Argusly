<?php

namespace App\Services\SignalIntelligence;

use App\Models\SignalDetection;
use App\Models\SignalEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SignalDetectionLinker
{
    public function __construct(private readonly SignalScoringEngine $scoring)
    {
    }

    /**
     * @param array<string,mixed> $contribution
     */
    public function link(SignalDetection $detection, SignalEvent $event, float $weight = 1.0, array $contribution = []): SignalDetection
    {
        $exists = $detection->events()->whereKey($event->id)->exists();

        if (! $exists) {
            $detection->events()->attach($event->id, [
                'id' => (string) Str::uuid(),
                'weight' => max(0, min(1, $weight)),
                'contribution' => $contribution,
            ]);
        }

        return $this->refreshSummary($detection);
    }

    /**
     * @param Collection<int,SignalEvent> $events
     */
    public function linkMany(SignalDetection $detection, Collection $events, float $weight = 1.0): SignalDetection
    {
        $events->each(fn (SignalEvent $event): SignalDetection => $this->link($detection, $event, $weight, [
            'signal_strength' => $event->signal_strength,
            'confidence_score' => $event->confidence_score,
            'type' => $event->type?->value,
        ]));

        return $this->refreshSummary($detection);
    }

    public function refreshSummary(SignalDetection $detection): SignalDetection
    {
        $events = $detection->events()->get();
        $evidence = $events
            ->flatMap(fn (SignalEvent $event): array => (array) $event->evidence)
            ->take(10)
            ->values()
            ->all();

        $existingBreakdown = (array) $detection->score_breakdown;
        $breakdown = $this->scoring->breakdown($events);

        foreach (['previous_event_count', 'trend_velocity'] as $key) {
            if (array_key_exists($key, $existingBreakdown)) {
                $breakdown[$key] = $existingBreakdown[$key];
            }
        }

        $detection->forceFill([
            'score_breakdown' => $breakdown,
            'evidence_summary' => $evidence,
            'last_seen_at' => $events->max('observed_at') ?? $detection->last_seen_at,
        ])->save();

        return $detection->refresh();
    }
}
