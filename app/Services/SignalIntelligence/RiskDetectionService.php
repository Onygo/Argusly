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

class RiskDetectionService
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

        $negative = $events->filter(fn (SignalEvent $event): bool => $event->type?->value === SignalType::NEGATIVE_SENTIMENT->value
            || strtolower((string) data_get($event->metrics, 'sentiment_label', '')) === 'negative'
            || (is_numeric(data_get($event->metrics, 'sentiment_score')) && (float) data_get($event->metrics, 'sentiment_score') < 0));

        if ($negative->isNotEmpty()) {
            $detections->push($this->upserter->upsert(
                workspace: $workspace,
                category: SignalDetection::CATEGORY_RISK_DETECTION,
                type: 'negative_sentiment_cluster',
                events: $negative->values(),
                from: $from,
                to: $to,
                clientSite: $clientSite,
                primaryEntity: (string) ($negative->first()->entity_name ?: $negative->first()->entity_key),
                primaryTopic: $negative->first()->topic,
                attributes: ['recommended_actions' => ['Review negative sentiment context and escalation need.'], 'metadata' => ['rule' => 'negative_sentiment_cluster']],
            ));
        }

        $pressure = $events->filter(fn (SignalEvent $event): bool => $event->type?->value === SignalType::RISK_COMPETITOR_PRESSURE->value
            || (float) ($event->risk_score ?? 0) >= 70);
        if ($pressure->isNotEmpty()) {
            $detections->push($this->upserter->upsert(
                workspace: $workspace,
                category: SignalDetection::CATEGORY_RISK_DETECTION,
                type: 'competitor_pressure_rising',
                events: $pressure->values(),
                from: $from,
                to: $to,
                clientSite: $clientSite,
                primaryEntity: (string) ($pressure->first()->entity_name ?: $pressure->first()->entity_key),
                primaryTopic: $pressure->first()->topic,
                attributes: ['recommended_actions' => ['Compare competitor visibility and update counter-positioning.'], 'metadata' => ['rule' => 'competitor_pressure_rising']],
            ));
        }

        $brandAbsence = $events->filter(fn (SignalEvent $event): bool => $event->type?->value === SignalType::BRAND_MISSING->value);
        if ($brandAbsence->isNotEmpty()) {
            $detections->push($this->upserter->upsert(
                workspace: $workspace,
                category: SignalDetection::CATEGORY_RISK_DETECTION,
                type: 'brand_absence_on_key_topic',
                events: $brandAbsence->values(),
                from: $from,
                to: $to,
                clientSite: $clientSite,
                primaryEntity: (string) ($brandAbsence->first()->entity_name ?: $brandAbsence->first()->entity_key),
                primaryTopic: $brandAbsence->first()->topic,
                attributes: ['recommended_actions' => ['Improve owned coverage or citations for this absent brand topic.'], 'metadata' => ['rule' => 'brand_absence_on_key_topic']],
            ));
        }

        $decliningVisibility = $events->filter(fn (SignalEvent $event): bool => $event->category?->value === SignalCategory::AI_VISIBILITY->value
            && ($event->type?->value === SignalType::BRAND_MISSING->value || $event->type?->value === SignalType::RISK_DECLINING_VISIBILITY->value));
        if ($decliningVisibility->isNotEmpty()) {
            $detections->push($this->upserter->upsert(
                workspace: $workspace,
                category: SignalDetection::CATEGORY_RISK_DETECTION,
                type: 'declining_ai_visibility',
                events: $decliningVisibility->values(),
                from: $from,
                to: $to,
                clientSite: $clientSite,
                primaryEntity: (string) ($decliningVisibility->first()->entity_name ?: $decliningVisibility->first()->entity_key),
                primaryTopic: $decliningVisibility->first()->topic,
                attributes: ['recommended_actions' => ['Review AI visibility sources and missing citation causes.'], 'metadata' => ['rule' => 'declining_ai_visibility']],
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
                $query->whereIn('category', [
                    SignalCategory::RISK->value,
                    SignalCategory::AI_VISIBILITY->value,
                    SignalCategory::COMPETITOR_VISIBILITY->value,
                    SignalCategory::BRAND_VISIBILITY->value,
                ])->orWhereIn('type', [
                    SignalType::NEGATIVE_SENTIMENT->value,
                    SignalType::RISK_COMPETITOR_PRESSURE->value,
                    SignalType::RISK_DECLINING_VISIBILITY->value,
                    SignalType::BRAND_MISSING->value,
                ]);
            })
            ->orderByDesc('observed_at')
            ->limit($limit)
            ->get();
    }
}
