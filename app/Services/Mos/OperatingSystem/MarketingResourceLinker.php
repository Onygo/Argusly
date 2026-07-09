<?php

namespace App\Services\Mos\OperatingSystem;

use App\Models\AgenticMarketingOpportunity;
use App\Models\Campaign;
use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Content;
use App\Models\MarketingInitiative;
use App\Models\MarketingObjective;
use App\Models\MarketingObservation;
use App\Models\MarketingOperatingLink;
use App\Models\MonitoredPage;
use App\Models\PageIntelligenceReport;
use App\Models\RecommendedAction;
use App\Models\ScheduledPageIntelligenceBriefing;
use App\Services\AgenticMarketing\Intelligence\MarketingRecommendation;
use App\Services\PerformanceIntelligence\PerformanceSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class MarketingResourceLinker
{
    public const RESOURCE_MARKETING_OBJECTIVE = 'marketing_objective';
    public const RESOURCE_MARKETING_INITIATIVE = 'marketing_initiative';
    public const RESOURCE_CAMPAIGN = 'campaign';
    public const RESOURCE_CONTENT = 'content';
    public const RESOURCE_MONITORED_PAGE = 'monitored_page';
    public const RESOURCE_PERFORMANCE_SNAPSHOT = 'performance_snapshot';
    public const RESOURCE_AGENTIC_RECOMMENDATION = 'agentic_marketing_recommendation';
    public const RESOURCE_RECOMMENDED_ACTION = 'recommended_action';
    public const RESOURCE_PAGE_INTELLIGENCE_REPORT = 'page_intelligence_report';
    public const RESOURCE_SCHEDULED_BRIEFING = 'scheduled_briefing';
    public const RESOURCE_MARKETING_OBSERVATION = 'marketing_observation';
    public const RESOURCE_CONNECTOR_ACCOUNT = 'connector_account';
    public const RESOURCE_CONNECTOR_DATASET = 'connector_dataset';

    public function __construct(
        private readonly MarketingTimeline $timeline,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function link(
        MarketingObjective|MarketingInitiative $subject,
        object|array $resource,
        string $relationshipType,
        array $metadata = [],
        ?float $confidence = null,
    ): MarketingOperatingLink {
        $normalized = $this->normalizeResource($resource);
        $objective = $subject instanceof MarketingObjective ? $subject : $subject->objective;
        $initiative = $subject instanceof MarketingInitiative ? $subject : null;

        $link = MarketingOperatingLink::query()->updateOrCreate([
            'marketing_objective_id' => $objective?->id,
            'marketing_initiative_id' => $initiative?->id,
            'relationship_type' => $relationshipType,
            'resource_key' => $normalized['resource_key'],
        ], [
            'organization_id' => $subject->organization_id,
            'workspace_id' => $subject->workspace_id,
            'resource_type' => $normalized['resource_type'],
            'resource_id' => $normalized['resource_id'],
            'resource_title' => $normalized['resource_title'],
            'resource_model' => $normalized['resource_model'],
            'confidence_score' => $confidence,
            'metadata_json' => $this->sanitizeMetadata($normalized['metadata'] + $metadata),
        ]);

        $this->timeline->record(
            $subject,
            'resource.linked',
            'Marketing operating resource linked',
            $link->resource_title ?: $link->resource_key,
            metadata: [
                'relationship_type' => $relationshipType,
                'resource_type' => $link->resource_type,
                'resource_key' => $link->resource_key,
            ],
            resource: [
                'type' => $link->resource_type,
                'id' => $link->resource_id,
                'key' => $link->resource_key,
            ],
        );

        return $link;
    }

    /**
     * @param  iterable<int, MarketingObservation>  $observations
     * @return array<int, MarketingOperatingLink>
     */
    public function linkObservations(MarketingObjective|MarketingInitiative $subject, iterable $observations): array
    {
        $links = [];

        foreach ($observations as $observation) {
            $links[] = $this->link(
                $subject,
                $observation,
                MarketingOperatingLink::RELATION_EVIDENCES,
                [
                    'metric_key' => $observation->metric_key,
                    'period_start' => $observation->period_start?->toDateString(),
                    'period_end' => $observation->period_end?->toDateString(),
                    'granularity' => $observation->granularity,
                ],
                $observation->confidence_score !== null ? (float) $observation->confidence_score : null,
            );
        }

        return $links;
    }

    /**
     * @return array{resource_type:string,resource_id:?string,resource_key:string,resource_title:?string,resource_model:?string,metadata:array<string,mixed>}
     */
    public function normalizeResource(object|array $resource): array
    {
        if (is_array($resource)) {
            $type = (string) Arr::get($resource, 'resource_type', Arr::get($resource, 'type'));
            $id = Arr::get($resource, 'resource_id', Arr::get($resource, 'id'));
            $key = (string) Arr::get($resource, 'resource_key', $type.':'.$id);

            return [
                'resource_type' => $type,
                'resource_id' => $id !== null ? (string) $id : null,
                'resource_key' => $key,
                'resource_title' => Arr::get($resource, 'resource_title', Arr::get($resource, 'title')),
                'resource_model' => Arr::get($resource, 'resource_model'),
                'metadata' => (array) Arr::get($resource, 'metadata', []),
            ];
        }

        if ($resource instanceof MarketingRecommendation) {
            return [
                'resource_type' => self::RESOURCE_AGENTIC_RECOMMENDATION,
                'resource_id' => $resource->key,
                'resource_key' => self::RESOURCE_AGENTIC_RECOMMENDATION.':'.$resource->key,
                'resource_title' => $resource->title,
                'resource_model' => $resource::class,
                'metadata' => [
                    'type' => $resource->type,
                    'priority' => $resource->priority,
                    'confidence' => $resource->confidence,
                    'affected_pages' => $resource->affectedPages,
                    'affected_topics' => $resource->affectedTopics,
                    'affected_channels' => $resource->affectedChannels,
                    'evidence' => $resource->evidence->toArray(),
                ],
            ];
        }

        if ($resource instanceof PerformanceSnapshot) {
            $fingerprint = hash('sha256', json_encode($resource->toArray(), JSON_THROW_ON_ERROR));

            return [
                'resource_type' => self::RESOURCE_PERFORMANCE_SNAPSHOT,
                'resource_id' => $fingerprint,
                'resource_key' => self::RESOURCE_PERFORMANCE_SNAPSHOT.':'.$fingerprint,
                'resource_title' => 'Performance snapshot',
                'resource_model' => $resource::class,
                'metadata' => [
                    'period_start' => $resource->periodStart->toDateTimeString(),
                    'period_end' => $resource->periodEnd->toDateTimeString(),
                    'granularity' => $resource->granularity,
                    'signal_count' => count($resource->signals),
                    'observation_ids' => $resource->observationIds,
                ],
            ];
        }

        if ($resource instanceof Model) {
            $type = $this->modelResourceType($resource);
            $id = (string) $resource->getKey();

            return [
                'resource_type' => $type,
                'resource_id' => $id,
                'resource_key' => $type.':'.$id,
                'resource_title' => $this->modelTitle($resource),
                'resource_model' => $resource::class,
                'metadata' => $this->modelMetadata($resource),
            ];
        }

        $type = Str::of($resource::class)->classBasename()->snake()->toString();
        $key = $type.':'.sha1(json_encode(get_object_vars($resource), JSON_THROW_ON_ERROR));

        return [
            'resource_type' => $type,
            'resource_id' => null,
            'resource_key' => $key,
            'resource_title' => null,
            'resource_model' => $resource::class,
            'metadata' => [],
        ];
    }

    private function modelResourceType(Model $model): string
    {
        return match (true) {
            $model instanceof MarketingObjective => self::RESOURCE_MARKETING_OBJECTIVE,
            $model instanceof MarketingInitiative => self::RESOURCE_MARKETING_INITIATIVE,
            $model instanceof Campaign => self::RESOURCE_CAMPAIGN,
            $model instanceof Content => self::RESOURCE_CONTENT,
            $model instanceof MonitoredPage => self::RESOURCE_MONITORED_PAGE,
            $model instanceof AgenticMarketingOpportunity => self::RESOURCE_AGENTIC_RECOMMENDATION,
            $model instanceof RecommendedAction => self::RESOURCE_RECOMMENDED_ACTION,
            $model instanceof PageIntelligenceReport => self::RESOURCE_PAGE_INTELLIGENCE_REPORT,
            $model instanceof ScheduledPageIntelligenceBriefing => self::RESOURCE_SCHEDULED_BRIEFING,
            $model instanceof MarketingObservation => self::RESOURCE_MARKETING_OBSERVATION,
            $model instanceof ConnectorAccount => self::RESOURCE_CONNECTOR_ACCOUNT,
            $model instanceof ConnectorDataset => self::RESOURCE_CONNECTOR_DATASET,
            default => Str::of($model::class)->classBasename()->snake()->toString(),
        };
    }

    private function modelTitle(Model $model): ?string
    {
        foreach (['name', 'title', 'summary', 'metric_key', 'account_name', 'display_name'] as $attribute) {
            $value = $model->getAttribute($attribute);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        if ($model instanceof MonitoredPage) {
            return (string) ($model->title_current ?: $model->canonical_url ?: $model->first_seen_url ?: 'Monitored page');
        }

        if ($model instanceof ScheduledPageIntelligenceBriefing) {
            return trim($model->report_type.' '.$model->frequency) ?: 'Scheduled briefing';
        }

        if ($model instanceof MarketingObservation) {
            return $model->metric_key.' observation';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function modelMetadata(Model $model): array
    {
        if ($model instanceof MarketingObservation) {
            return [
                'metric_key' => $model->metric_key,
                'period_start' => $model->period_start?->toDateString(),
                'period_end' => $model->period_end?->toDateString(),
                'granularity' => $model->granularity,
                'unit' => $model->unit,
            ];
        }

        if ($model instanceof PageIntelligenceReport) {
            return [
                'report_type' => $model->report_type,
                'status' => $model->status,
                'period_start' => $model->period_start?->toDateString(),
                'period_end' => $model->period_end?->toDateString(),
                'snapshot_version' => $model->snapshot_version,
                'market_pack_key' => $model->market_pack_key,
            ];
        }

        if ($model instanceof ScheduledPageIntelligenceBriefing) {
            return [
                'report_type' => $model->report_type,
                'frequency' => $model->frequency,
                'market_pack_key' => $model->market_pack_key,
                'next_run_at' => $model->next_run_at?->toDateTimeString(),
            ];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function sanitizeMetadata(array $metadata): array
    {
        unset(
            $metadata['provider_key'],
            $metadata['connector_provider_id'],
            $metadata['connector_account_id'],
            $metadata['connector_dataset_id'],
        );

        return $metadata;
    }
}
