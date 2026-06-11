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

class CompetitorMonitoringDetectionService
{
    public function __construct(private readonly SignalDetectionUpserter $upserter)
    {
    }

    /**
     * @return Collection<int,SignalDetection>
     */
    public function detect(Workspace $workspace, ?ClientSite $clientSite, CarbonInterface $from, CarbonInterface $to, int $limit = 100): Collection
    {
        $events = $this->events($workspace, $clientSite, $from, $to, $limit);
        $detections = collect();

        foreach ([SignalType::COMPETITOR_MENTIONED->value, SignalType::COMPETITOR_DOMINANCE->value, SignalType::COMPETITOR_CONTENT_SPIKE->value] as $type) {
            $events->where('type.value', $type)
                ->groupBy(fn (SignalEvent $event): string => ($event->entity_key ?: $event->entity_name ?: 'competitor').'|'.($event->topic ?: 'general'))
                ->each(function (Collection $group) use ($workspace, $clientSite, $from, $to, $type, $detections): void {
                    if ($group->isEmpty()) {
                        return;
                    }

                    $detections->push($this->upserter->upsert(
                        workspace: $workspace,
                        category: SignalDetection::CATEGORY_COMPETITOR_MONITORING,
                        type: $type,
                        events: $group->values(),
                        from: $from,
                        to: $to,
                        clientSite: $clientSite,
                        primaryEntity: (string) ($group->first()->entity_name ?: $group->first()->entity_key),
                        primaryTopic: $group->first()->topic,
                        attributes: ['recommended_actions' => ['Review competitor pressure and affected topics.'], 'metadata' => ['rule' => $type]],
                    ));
                });
        }

        $topicExpansion = $events->whereNotNull('topic')->groupBy('topic')->filter(fn (Collection $group): bool => $group->count() >= 2);
        foreach ($topicExpansion as $topic => $group) {
            $detections->push($this->upserter->upsert(
                workspace: $workspace,
                category: SignalDetection::CATEGORY_COMPETITOR_MONITORING,
                type: 'competitor_topic_expansion',
                events: $group->values(),
                from: $from,
                to: $to,
                clientSite: $clientSite,
                primaryEntity: null,
                primaryTopic: (string) $topic,
                attributes: ['recommended_actions' => ['Compare owned coverage for this expanding competitor topic.'], 'metadata' => ['rule' => 'competitor_topic_expansion']],
            ));
        }

        $pressure = $events->filter(fn (SignalEvent $event): bool => (float) ($event->risk_score ?? 0) >= 70 || (float) data_get($event->metrics, 'competitor_share_score', 0) >= 70);
        if ($pressure->isNotEmpty()) {
            $detections->push($this->upserter->upsert(
                workspace: $workspace,
                category: SignalDetection::CATEGORY_COMPETITOR_MONITORING,
                type: 'share_of_voice_loss',
                events: $pressure->values(),
                from: $from,
                to: $to,
                clientSite: $clientSite,
                primaryEntity: (string) ($pressure->first()->entity_name ?: $pressure->first()->entity_key),
                primaryTopic: $pressure->first()->topic,
                attributes: ['recommended_actions' => ['Investigate competitor share-of-voice pressure.'], 'metadata' => ['rule' => 'share_of_voice_loss']],
            ));
        }

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
                $query->where('category', SignalCategory::COMPETITOR_VISIBILITY->value)
                    ->orWhereIn('type', [
                        SignalType::COMPETITOR_MENTIONED->value,
                        SignalType::COMPETITOR_DOMINANCE->value,
                        SignalType::COMPETITOR_CONTENT_SPIKE->value,
                    ]);
            })
            ->orderByDesc('observed_at')
            ->limit($limit)
            ->get();
    }
}
