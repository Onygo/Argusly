<?php

namespace App\Services\Mos\OperatingSystem;

use App\Models\AgenticMarketingOpportunity;
use App\Models\ClientSite;
use App\Models\MarketingInitiative;
use App\Models\MarketingObjective;
use App\Models\MarketingObservation;
use App\Models\MarketingOperatingLink;
use App\Models\PageIntelligenceReport;
use App\Models\RecommendedAction;
use App\Models\ScheduledPageIntelligenceBriefing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgenticMarketing\Intelligence\MarketingRecommendation;
use App\Services\PerformanceIntelligence\PerformanceIntelligenceEngine;
use App\Services\PerformanceIntelligence\PerformanceSnapshot;
use App\Support\Intelligence\IntelligenceGraphEdge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MarketingOperatingSystem
{
    public function __construct(
        public readonly MarketingObjectiveLifecycle $objectives,
        public readonly MarketingInitiativeLifecycle $initiatives,
        public readonly MarketingResourceLinker $links,
        public readonly MarketingWorkflowCoordinator $workflows,
        public readonly MarketingReviewService $reviews,
        public readonly MarketingPriorityService $priorities,
        private readonly MarketingTimeline $timeline,
        private readonly PerformanceIntelligenceEngine $performance,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createObjective(Workspace $workspace, array $attributes): MarketingObjective
    {
        return $this->objectives->create($workspace, $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createInitiative(MarketingObjective $objective, array $attributes): MarketingInitiative
    {
        return $this->initiatives->create($objective, $attributes);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function transitionObjective(MarketingObjective $objective, string $status, ?User $actor = null, array $metadata = []): MarketingObjective
    {
        return $this->objectives->transition($objective, $status, $actor, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function transitionInitiative(MarketingInitiative $initiative, string $status, ?User $actor = null, array $metadata = []): MarketingInitiative
    {
        return $this->initiatives->transition($initiative, $status, $actor, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function linkResource(
        MarketingObjective|MarketingInitiative $subject,
        object|array $resource,
        string $relationship = MarketingOperatingLink::RELATION_SUPPORTS,
        array $metadata = [],
        ?float $confidence = null,
    ): MarketingOperatingLink {
        return $this->links->link($subject, $resource, $relationship, $metadata, $confidence);
    }

    public function linkRecommendation(
        MarketingObjective|MarketingInitiative $subject,
        MarketingRecommendation|AgenticMarketingOpportunity|RecommendedAction $recommendation,
    ): MarketingOperatingLink {
        if ($recommendation instanceof MarketingRecommendation) {
            $this->priorities->fromRecommendation($subject, $recommendation);

            return $this->links->link(
                $subject,
                $recommendation,
                MarketingOperatingLink::RELATION_RECOMMENDS,
                ['source' => 'agentic_marketing_intelligence'],
                $recommendation->confidence,
            );
        }

        $metadata = [
            'source' => $recommendation instanceof AgenticMarketingOpportunity
                ? 'agentic_marketing_opportunity'
                : 'recommended_action',
            'priority_score' => $recommendation->priority_score,
            'status' => $recommendation->status,
        ];

        return $this->links->link(
            $subject,
            $recommendation,
            MarketingOperatingLink::RELATION_RECOMMENDS,
            $metadata,
            $recommendation instanceof RecommendedAction && $recommendation->confidence_score !== null
                ? ((float) $recommendation->confidence_score / 100)
                : null,
        );
    }

    public function integrateReport(MarketingObjective|MarketingInitiative $subject, PageIntelligenceReport $report): MarketingOperatingLink
    {
        return $this->links->link(
            $subject,
            $report,
            MarketingOperatingLink::RELATION_REPORTS,
            ['integration' => 'page_intelligence_report'],
        );
    }

    public function integrateBriefing(MarketingObjective|MarketingInitiative $subject, ScheduledPageIntelligenceBriefing $briefing): MarketingOperatingLink
    {
        return $this->links->link(
            $subject,
            $briefing,
            MarketingOperatingLink::RELATION_BRIEFS,
            ['integration' => 'scheduled_page_intelligence_briefing'],
        );
    }

    public function snapshotPerformance(
        MarketingObjective|MarketingInitiative $subject,
        ?ClientSite $site = null,
        Carbon|string|null $from = null,
        Carbon|string|null $to = null,
        string $granularity = MarketingObservation::GRANULARITY_DAILY,
    ): PerformanceSnapshot {
        $workspace = $subject->workspace instanceof Workspace
            ? $subject->workspace
            : Workspace::query()->findOrFail($subject->workspace_id);
        $site ??= $subject->client_site_id ? ClientSite::query()->find($subject->client_site_id) : null;
        $snapshot = $this->performance->snapshot($workspace, $site, $from, $to, $granularity);

        $this->links->link($subject, $snapshot, MarketingOperatingLink::RELATION_MEASURES, [
            'integration' => 'performance_intelligence',
        ]);

        return $snapshot;
    }

    /**
     * @param  iterable<int, MarketingObservation>  $observations
     * @return array<int, MarketingOperatingLink>
     */
    public function linkObservations(MarketingObjective|MarketingInitiative $subject, iterable $observations): array
    {
        return $this->links->linkObservations($subject, $observations);
    }

    /**
     * @return array<string, mixed>
     */
    public function graph(MarketingObjective $objective): array
    {
        $objective->loadMissing([
            'initiatives',
            'priorities',
            'workflows',
            'timelineEvents',
            'reviews',
            'operatingLinks',
        ]);

        $initiativeLinks = MarketingOperatingLink::query()
            ->where('marketing_objective_id', $objective->id)
            ->whereNotNull('marketing_initiative_id')
            ->get();
        $links = $objective->operatingLinks->merge($initiativeLinks)->unique('id')->values();

        return [
            'objective' => $this->objectivePayload($objective),
            'initiatives' => $objective->initiatives->map(fn (MarketingInitiative $initiative): array => $this->initiativePayload($initiative))->values()->all(),
            'priorities' => $objective->priorities->map(fn ($priority): array => $priority->toArray())->values()->all(),
            'workflows' => $objective->workflows->map(fn ($workflow): array => $workflow->toArray())->values()->all(),
            'timeline' => $objective->timelineEvents()->latestFirst()->get()->map(fn ($event): array => $event->toArray())->values()->all(),
            'reviews' => $objective->reviews->map(fn ($review): array => $review->toArray())->values()->all(),
            'links' => $links->map(fn (MarketingOperatingLink $link): array => $link->toArray())->values()->all(),
            'resources' => $this->resourceGroups($links),
            'chain' => [
                'objectives' => 1,
                'initiatives' => $objective->initiatives->count(),
                'campaigns' => $this->countType($links, MarketingResourceLinker::RESOURCE_CAMPAIGN),
                'content' => $this->countType($links, MarketingResourceLinker::RESOURCE_CONTENT),
                'pages' => $this->countType($links, MarketingResourceLinker::RESOURCE_MONITORED_PAGE),
                'performance' => $this->countType($links, MarketingResourceLinker::RESOURCE_PERFORMANCE_SNAPSHOT),
                'recommendations' => $this->countType($links, MarketingResourceLinker::RESOURCE_AGENTIC_RECOMMENDATION)
                    + $this->countType($links, MarketingResourceLinker::RESOURCE_RECOMMENDED_ACTION),
                'reports' => $this->countType($links, MarketingResourceLinker::RESOURCE_PAGE_INTELLIGENCE_REPORT),
                'briefings' => $this->countType($links, MarketingResourceLinker::RESOURCE_SCHEDULED_BRIEFING),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function graphForInitiative(MarketingInitiative $initiative): array
    {
        $initiative->loadMissing(['objective', 'priorities', 'workflows', 'timelineEvents', 'reviews', 'operatingLinks']);
        $links = $initiative->operatingLinks;

        return [
            'objective' => $initiative->objective ? $this->objectivePayload($initiative->objective) : null,
            'initiative' => $this->initiativePayload($initiative),
            'priorities' => $initiative->priorities->map(fn ($priority): array => $priority->toArray())->values()->all(),
            'workflows' => $initiative->workflows->map(fn ($workflow): array => $workflow->toArray())->values()->all(),
            'timeline' => $initiative->timelineEvents()->latestFirst()->get()->map(fn ($event): array => $event->toArray())->values()->all(),
            'reviews' => $initiative->reviews->map(fn ($review): array => $review->toArray())->values()->all(),
            'links' => $links->map(fn (MarketingOperatingLink $link): array => $link->toArray())->values()->all(),
            'resources' => $this->resourceGroups($links),
        ];
    }

    /**
     * @return array<int, IntelligenceGraphEdge>
     */
    public function intelligenceGraphEdges(MarketingObjective|MarketingInitiative $subject): array
    {
        if ($subject instanceof MarketingInitiative) {
            $subject->loadMissing('operatingLinks');

            return $subject->operatingLinks
                ->map(fn (MarketingOperatingLink $link): IntelligenceGraphEdge => $link->toIntelligenceGraphEdge())
                ->values()
                ->all();
        }

        $subject->loadMissing(['initiatives', 'operatingLinks']);
        $initiativeLinks = MarketingOperatingLink::query()
            ->where('marketing_objective_id', $subject->id)
            ->whereNotNull('marketing_initiative_id')
            ->get();

        return $subject->operatingLinks
            ->merge($initiativeLinks)
            ->unique('id')
            ->map(fn (MarketingOperatingLink $link): IntelligenceGraphEdge => $link->toIntelligenceGraphEdge())
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function objectivePayload(MarketingObjective $objective): array
    {
        return [
            'id' => $objective->id,
            'name' => $objective->name,
            'status' => $objective->status,
            'priority' => $objective->priority,
            'target_metric_key' => $objective->target_metric_key,
            'market_pack_key' => $objective->market_pack_key,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function initiativePayload(MarketingInitiative $initiative): array
    {
        return [
            'id' => $initiative->id,
            'marketing_objective_id' => $initiative->marketing_objective_id,
            'name' => $initiative->name,
            'status' => $initiative->status,
            'priority' => $initiative->priority,
            'market_pack_key' => $initiative->market_pack_key,
        ];
    }

    /**
     * @param  Collection<int, MarketingOperatingLink>  $links
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function resourceGroups(Collection $links): array
    {
        return $links
            ->groupBy('resource_type')
            ->map(fn (Collection $group): array => $group
                ->map(fn (MarketingOperatingLink $link): array => [
                    'relationship_type' => $link->relationship_type,
                    'resource_type' => $link->resource_type,
                    'resource_id' => $link->resource_id,
                    'resource_key' => $link->resource_key,
                    'resource_title' => $link->resource_title,
                    'confidence_score' => $link->confidence_score,
                    'metadata' => $link->metadata_json,
                ])
                ->values()
                ->all())
            ->all();
    }

    /**
     * @param  Collection<int, MarketingOperatingLink>  $links
     */
    private function countType(Collection $links, string $type): int
    {
        return $links->where('resource_type', $type)->count();
    }
}
