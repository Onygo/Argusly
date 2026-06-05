<?php

namespace App\Services\CampaignClusterEngine;

use App\Enums\AgenticMarketingOpportunityStatus;
use App\Enums\AgenticMarketingOpportunityType;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\AgenticMarketingRun;
use App\Models\CampaignCluster;
use App\Models\CampaignClusterDependency;
use App\Models\CampaignClusterItem;
use App\Models\ClientSite;
use App\Services\AgenticMarketing\AgenticMarketingActionPlanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CampaignClusterActionMaterializer
{
    public function __construct(
        private readonly AgenticMarketingActionPlanner $planner,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function materialize(CampaignCluster $cluster): array
    {
        return DB::transaction(function () use ($cluster): array {
            $cluster->loadMissing(['workspace', 'site', 'items', 'dependencies.sourceItem', 'dependencies.targetItem']);
            $objective = $this->objectiveForCluster($cluster);
            $run = $this->startRun($objective, $cluster);

            $summary = [
                'objective_id' => (string) $objective->id,
                'run_id' => (string) $run->id,
                'cluster_id' => (string) $cluster->id,
                'items' => 0,
                'opportunities_created' => 0,
                'opportunities_reused' => 0,
                'actions_created' => 0,
                'actions_reused' => 0,
                'actions_skipped' => 0,
                'action_ids' => [],
            ];

            foreach ($cluster->items as $item) {
                $opportunity = $this->opportunityForItem($objective, $cluster, $item);
                $summary['items']++;
                $summary[$opportunity->wasRecentlyCreated ? 'opportunities_created' : 'opportunities_reused']++;

                $result = $this->planner->planForOpportunity($opportunity->fresh(['objective']), $run);
                $summary['actions_created'] += (int) $result['created'];
                $summary['actions_reused'] += (int) $result['reused'];
                $summary['actions_skipped'] += (int) $result['skipped'];
                $summary['action_ids'] = array_values(array_unique(array_merge($summary['action_ids'], $result['action_ids'])));

                $this->rememberMaterializedItem($item, $opportunity, $result['action_ids']);
            }

            $run->markCompleted($summary);

            return $summary;
        });
    }

    private function objectiveForCluster(CampaignCluster $cluster): AgenticMarketingObjective
    {
        $name = Str::limit('Campaign cluster: '.$cluster->name, 255, '');
        $clientSiteId = $this->resolveClientSiteId($cluster);

        $objective = AgenticMarketingObjective::query()
            ->where('organization_id', $cluster->organization_id)
            ->where('workspace_id', $cluster->workspace_id)
            ->where('name', $name)
            ->first();

        if (! $clientSiteId && $objective?->client_site_id) {
            $clientSiteId = (string) $objective->client_site_id;
        }

        $payload = [
            'source' => 'campaign_cluster',
            'campaign_cluster_id' => (string) $cluster->id,
            'client_site_id' => $clientSiteId,
            'primary_topic' => $cluster->primary_topic,
            'primary_entity' => $cluster->primary_entity,
        ];

        if ($objective) {
            $objective->forceFill([
                'goal' => $this->objectiveGoal($cluster),
                'client_site_id' => $clientSiteId,
                'languages' => $this->clusterLocales($cluster),
                'payload' => array_replace_recursive((array) $objective->payload, $payload),
            ])->save();

            return $objective;
        }

        return AgenticMarketingObjective::query()->create([
            'organization_id' => $cluster->organization_id,
            'workspace_id' => $cluster->workspace_id,
            'client_site_id' => $clientSiteId,
            'name' => $name,
            'goal' => $this->objectiveGoal($cluster),
            'locale' => $this->primaryLocale($cluster),
            'audience' => 'Marketing, content, and development teams responsible for AI visibility.',
            'languages' => $this->clusterLocales($cluster),
            'kpi_type' => 'ai_visibility',
            'approval_mode' => 'manual',
            'status' => 'active',
            'payload' => $payload,
        ]);
    }

    private function startRun(AgenticMarketingObjective $objective, CampaignCluster $cluster): AgenticMarketingRun
    {
        $run = AgenticMarketingRun::query()->create([
            'objective_id' => (string) $objective->id,
            'status' => AgenticMarketingRun::STATUS_QUEUED,
            'payload' => [
                'type' => 'campaign_cluster_action_materialization',
                'campaign_cluster_id' => (string) $cluster->id,
                'campaign_cluster_name' => $cluster->name,
            ],
        ]);

        return $run->markRunning();
    }

    private function opportunityForItem(AgenticMarketingObjective $objective, CampaignCluster $cluster, CampaignClusterItem $item): AgenticMarketingOpportunity
    {
        $clientSiteId = $objective->client_site_id ?: $this->resolveClientSiteId($cluster);
        $primaryKeyword = $item->target_entity ?: $cluster->primary_topic ?: $item->title;
        $isAnswerBlockEnhancement = $item->type === 'answer_blocks';
        $summary = $isAnswerBlockEnhancement
            ? sprintf(
                'Prepare structured answer blocks for the "%s" cluster pages and keep them attached to content assets for editorial review.',
                $cluster->name
            )
            : sprintf(
                'Create the "%s" asset from the %s publishing sequence and prepare it for editorial review.',
                $item->title,
                $cluster->name
            );

        $payload = [
            'detector' => 'campaign_cluster_action_materializer',
            'signal_type' => 'campaign_cluster_item',
            'dedupe_key' => 'campaign_cluster_item:'.$item->id,
            'content_id' => $item->content_id,
            'workspace_id' => (string) $cluster->workspace_id,
            'client_site_id' => $clientSiteId,
            'locale' => $objective->locale ?: $this->primaryLocale($cluster),
            'topic' => $item->title,
            'primary_keyword' => $primaryKeyword,
            'target_audience' => $objective->audience,
            'funnel_stage' => $item->funnel_stage,
            'primary_search_intent' => $item->search_intent,
            'search_intent' => $item->search_intent,
            'content_type' => $isAnswerBlockEnhancement ? 'content_enhancement' : 'blog',
            'angle' => (string) data_get($item->payload, 'angle', $cluster->authority_strategy),
            'suggested_cta' => $cluster->cta_strategy,
            'suggested_schema' => data_get($item->payload, 'suggested_schema', $isAnswerBlockEnhancement ? 'FAQPage' : 'Article'),
            'reasoning' => $summary,
            'signals' => [
                'campaign_cluster_id' => (string) $cluster->id,
                'campaign_cluster_item_id' => (string) $item->id,
                'topic_keyword' => $primaryKeyword,
                'suggested_title' => $item->title,
                'source_locale' => $objective->locale,
                'funnel_stage' => $item->funnel_stage,
                'search_intent' => $item->search_intent,
                'content_type' => $item->type,
                'planned_publish_date' => optional($item->planned_publish_date)->toDateString(),
                'suggested_schema' => data_get($item->payload, 'suggested_schema', $isAnswerBlockEnhancement ? 'FAQPage' : 'Article'),
                'asset_kind' => data_get($item->payload, 'asset_kind', $isAnswerBlockEnhancement ? 'content_enhancement' : 'content_asset'),
                'materialized_action_type' => data_get($item->payload, 'materialized_action_type', $isAnswerBlockEnhancement ? 'add_answer_block' : 'create_article'),
                'cta_strategy' => $cluster->cta_strategy,
                'link_opportunities' => $this->linkOpportunities($cluster, $item),
            ],
            'score_explanation' => [
                'summary' => $summary,
            ],
            'campaign_cluster' => [
                'id' => (string) $cluster->id,
                'name' => $cluster->name,
                'primary_topic' => $cluster->primary_topic,
                'authority_strategy' => $cluster->authority_strategy,
                'refresh_cadence' => $cluster->refresh_cadence,
            ],
        ];

        return AgenticMarketingOpportunity::createOrReuseOpen([
            'objective_id' => (string) $objective->id,
            'content_id' => $item->content_id,
            'title' => $item->title,
            'type' => $isAnswerBlockEnhancement
                ? AgenticMarketingOpportunityType::AnswerCoverage->value
                : AgenticMarketingOpportunityType::NewArticle->value,
            'priority_score' => $this->priorityScore($cluster, $item),
            'status' => AgenticMarketingOpportunityStatus::Open->value,
            'payload' => $payload,
        ]);
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function linkOpportunities(CampaignCluster $cluster, CampaignClusterItem $item): array
    {
        return $cluster->dependencies
            ->filter(fn (CampaignClusterDependency $dependency): bool => (string) $dependency->source_item_id === (string) $item->id)
            ->map(fn (CampaignClusterDependency $dependency): array => [
                'target_title' => (string) ($dependency->targetItem?->title ?: 'Related cluster asset'),
                'anchor_text_suggestion' => (string) ($dependency->anchor_text ?: $dependency->targetItem?->title ?: $cluster->primary_topic),
                'reason' => (string) ($dependency->reason ?: 'Support the planned internal link architecture.'),
            ])
            ->values()
            ->all();
    }

    /**
     * @param array<int,string> $actionIds
     */
    private function rememberMaterializedItem(CampaignClusterItem $item, AgenticMarketingOpportunity $opportunity, array $actionIds): void
    {
        $payload = (array) $item->payload;
        $payload['agentic_marketing'] = [
            'objective_id' => (string) $opportunity->objective_id,
            'opportunity_id' => (string) $opportunity->id,
            'action_ids' => array_values(array_unique($actionIds)),
            'materialized_at' => now()->toIso8601String(),
        ];

        $item->forceFill(['payload' => $payload])->save();
    }

    private function objectiveGoal(CampaignCluster $cluster): string
    {
        return sprintf(
            'Generate and review the campaign cluster "%s" for %s with the planned publishing sequence, CTAs, localization, and internal links.',
            $cluster->name,
            $cluster->primary_topic
        );
    }

    /**
     * @return array<int,string>
     */
    private function clusterLocales(CampaignCluster $cluster): array
    {
        $locales = collect((array) data_get($cluster->localization_strategy, 'priority_locales', []))
            ->merge((array) ($cluster->workspace?->enabled_content_languages ?? []))
            ->map(fn (mixed $locale): string => Str::lower(trim((string) $locale)))
            ->filter()
            ->unique()
            ->values();

        return $locales->isNotEmpty() ? $locales->all() : ['en'];
    }

    private function primaryLocale(CampaignCluster $cluster): string
    {
        return $this->clusterLocales($cluster)[0] ?? 'en';
    }

    private function resolveClientSiteId(CampaignCluster $cluster): ?string
    {
        if ($cluster->client_site_id) {
            return (string) $cluster->client_site_id;
        }

        $siteIds = ClientSite::query()
            ->where('workspace_id', $cluster->workspace_id)
            ->where(function ($query): void {
                $query->where('is_active', true)
                    ->orWhereIn('status', ['active', 'connected']);
            })
            ->orderByDesc('is_active')
            ->orderBy('created_at')
            ->limit(2)
            ->pluck('id');

        return $siteIds->count() === 1 ? (string) $siteIds->first() : null;
    }

    private function priorityScore(CampaignCluster $cluster, CampaignClusterItem $item): int
    {
        $base = (int) round(((float) $cluster->completeness_score + (float) $item->authority_contribution + (float) $item->coverage_contribution) / 3);

        return max(35, min(95, $base > 0 ? $base : 70));
    }
}
