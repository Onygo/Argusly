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

class BrandMonitoringDetectionService
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

        foreach ([SignalType::BRAND_MENTIONED->value, SignalType::BRAND_MISSING->value, SignalType::OWNED_CITATION_MISSING->value] as $type) {
            $events->where('type.value', $type)
                ->groupBy(fn (SignalEvent $event): string => ($event->entity_key ?: $event->entity_name ?: 'brand').'|'.($event->topic ?: 'general'))
                ->each(function (Collection $group) use ($workspace, $clientSite, $from, $to, $type, $detections): void {
                    if ($group->isEmpty()) {
                        return;
                    }

                    $detections->push($this->upserter->upsert(
                        workspace: $workspace,
                        category: SignalDetection::CATEGORY_BRAND_MONITORING,
                        type: $type,
                        events: $group->values(),
                        from: $from,
                        to: $to,
                        clientSite: $clientSite,
                        primaryEntity: (string) ($group->first()->entity_name ?: $group->first()->entity_key),
                        primaryTopic: $group->first()->topic,
                        attributes: [
                            'recommended_actions' => ['Review brand visibility evidence and citation coverage.'],
                            'metadata' => ['rule' => $type],
                        ],
                    ));
                });
        }

        $negativeSentiment = $events->filter(fn (SignalEvent $event): bool => strtolower((string) data_get($event->metrics, 'sentiment_label', '')) === 'negative'
            || (is_numeric(data_get($event->metrics, 'sentiment_score')) && (float) data_get($event->metrics, 'sentiment_score') < 0));

        if ($negativeSentiment->isNotEmpty()) {
            $detections->push($this->upserter->upsert(
                workspace: $workspace,
                category: SignalDetection::CATEGORY_BRAND_MONITORING,
                type: 'sentiment_shift',
                events: $negativeSentiment->values(),
                from: $from,
                to: $to,
                clientSite: $clientSite,
                primaryEntity: (string) ($negativeSentiment->first()->entity_name ?: $negativeSentiment->first()->entity_key),
                primaryTopic: $negativeSentiment->first()->topic,
                attributes: [
                    'recommended_actions' => ['Review recent negative contexts and update monitoring notes.'],
                    'metadata' => ['rule' => 'sentiment_shift'],
                ],
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
                $query->where('category', SignalCategory::BRAND_VISIBILITY->value)
                    ->orWhereIn('type', [
                        SignalType::BRAND_MENTIONED->value,
                        SignalType::BRAND_MISSING->value,
                        SignalType::OWNED_CITATION_MISSING->value,
                    ]);
            })
            ->orderByDesc('observed_at')
            ->limit($limit)
            ->get();
    }
}
