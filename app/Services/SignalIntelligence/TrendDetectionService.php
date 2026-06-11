<?php

namespace App\Services\SignalIntelligence;

use App\Enums\SignalCategory;
use App\Enums\SignalType;
use App\Models\ClientSite;
use App\Models\SignalDetection;
use App\Models\SignalEvent;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class TrendDetectionService
{
    public function __construct(private readonly SignalDetectionUpserter $upserter)
    {
    }

    /**
     * @return Collection<int,SignalDetection>
     */
    public function detect(Workspace $workspace, ?ClientSite $clientSite, CarbonInterface $from, CarbonInterface $to, int $limit = 100): Collection
    {
        $duration = $from->diffInSeconds($to);
        $previousFrom = $from->copy()->subSeconds($duration);
        $previousTo = $from->copy();
        $current = $this->events($workspace, $clientSite, $from, $to, $limit);
        $previous = $this->events($workspace, $clientSite, $previousFrom, $previousTo, $limit);
        $detections = collect();

        $current->where('type.value', SignalType::TOPIC_TRENDING->value)
            ->groupBy(fn (SignalEvent $event): string => (string) ($event->topic ?: $event->entity_key ?: 'unknown'))
            ->each(function (Collection $group, string $topic) use ($workspace, $clientSite, $from, $to, $previous, $detections): void {
                $previousGroup = $previous->filter(fn (SignalEvent $event): bool => (string) ($event->topic ?: $event->entity_key ?: 'unknown') === $topic);

                if ($group->count() >= 1 && $group->count() >= $previousGroup->count()) {
                    $detections->push($this->upserter->upsert(
                        workspace: $workspace,
                        category: SignalDetection::CATEGORY_TREND_DETECTION,
                        type: 'topic_trending',
                        events: $group->values(),
                        from: $from,
                        to: $to,
                        clientSite: $clientSite,
                        primaryEntity: null,
                        primaryTopic: $topic,
                        attributes: ['recommended_actions' => ['Review topic demand and related content coverage.'], 'metadata' => ['rule' => 'topic_trending']],
                        previousEvents: $previousGroup->values(),
                    ));
                }

                if ($group->count() > $previousGroup->count()) {
                    $detections->push($this->upserter->upsert(
                        workspace: $workspace,
                        category: SignalDetection::CATEGORY_TREND_DETECTION,
                        type: 'topic_velocity',
                        events: $group->values(),
                        from: $from,
                        to: $to,
                        clientSite: $clientSite,
                        primaryEntity: null,
                        primaryTopic: $topic,
                        attributes: ['recommended_actions' => ['Prioritize fresh coverage while velocity is rising.'], 'metadata' => ['rule' => 'topic_velocity']],
                        previousEvents: $previousGroup->values(),
                    ));
                }

                $sourceDiversity = $group->pluck('signal_source_id')->filter()->unique()->count();
                if ($sourceDiversity >= 2) {
                    $detections->push($this->upserter->upsert(
                        workspace: $workspace,
                        category: SignalDetection::CATEGORY_TREND_DETECTION,
                        type: 'cross_source_repetition',
                        events: $group->values(),
                        from: $from,
                        to: $to,
                        clientSite: $clientSite,
                        primaryEntity: null,
                        primaryTopic: $topic,
                        attributes: ['recommended_actions' => ['Validate the topic across source types before acting.'], 'metadata' => ['rule' => 'cross_source_repetition']],
                        previousEvents: $previousGroup->values(),
                    ));
                }

                if ($previousGroup->isEmpty() && $group->count() >= 2) {
                    $detections->push($this->upserter->upsert(
                        workspace: $workspace,
                        category: SignalDetection::CATEGORY_TREND_DETECTION,
                        type: 'emerging_topic',
                        events: $group->values(),
                        from: $from,
                        to: $to,
                        clientSite: $clientSite,
                        primaryEntity: null,
                        primaryTopic: $topic,
                        attributes: ['recommended_actions' => ['Assess whether this emerging topic deserves monitoring.'], 'metadata' => ['rule' => 'emerging_topic']],
                        previousEvents: $previousGroup->values(),
                    ));
                }
            });

        return $detections->values();
    }

    /**
     * @return Collection<int,SignalEvent>
     */
    private function events(Workspace $workspace, ?ClientSite $clientSite, CarbonInterface $from, CarbonInterface $to, int $limit): Collection
    {
        return SignalEvent::query()
            ->where('workspace_id', $workspace->id)
            ->when($clientSite, fn ($query) => $query->where('client_site_id', $clientSite->id))
            ->whereBetween('observed_at', [$from, $to])
            ->where(function ($query): void {
                $query->where('category', SignalCategory::TREND->value)
                    ->orWhere('type', SignalType::TOPIC_TRENDING->value);
            })
            ->orderByDesc('observed_at')
            ->limit($limit)
            ->get();
    }
}
