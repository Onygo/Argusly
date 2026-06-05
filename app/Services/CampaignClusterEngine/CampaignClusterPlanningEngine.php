<?php

namespace App\Services\CampaignClusterEngine;

use App\Models\CampaignCluster;
use App\Models\CampaignClusterDependency;
use App\Models\CampaignClusterItem;
use App\Models\CampaignClusterRun;
use App\Models\CompanyIntelligenceProfile;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CampaignClusterPlanningEngine
{
    public function __construct(
        private readonly CampaignClusterInputBuilder $inputBuilder,
        private readonly CampaignClusterCandidateGenerator $candidateGenerator,
        private readonly CampaignClusterScoringService $scoringService,
        private readonly CampaignClusterMapBuilder $mapBuilder,
    ) {}

    public function run(Workspace $workspace, ?string $clientSiteId = null, array $options = []): CampaignClusterRun
    {
        $run = CampaignClusterRun::query()->create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => (string) $workspace->id,
            'client_site_id' => $clientSiteId,
            'status' => 'running',
            'source_type' => (string) ($options['source_type'] ?? 'agentic_marketing'),
            'input' => $options,
            'started_at' => now(),
        ]);

        try {
            DB::transaction(function () use ($workspace, $clientSiteId, $run): void {
                $input = $this->inputBuilder->build($workspace, $clientSiteId);
                $plans = $this->candidateGenerator->generate($input);
                $company = $input['company'] ?? null;
                $created = 0;
                $refreshed = 0;
                $clusterIds = [];

                foreach ($plans as $plan) {
                    $cluster = $this->persistPlan($workspace, $clientSiteId, $run, $plan, $company);
                    $cluster->wasRecentlyCreated ? $created++ : $refreshed++;
                    $clusterIds[] = (string) $cluster->id;
                }

                $run->forceFill([
                    'status' => 'completed',
                    'clusters_count' => count($plans),
                    'created_count' => $created,
                    'refreshed_count' => $refreshed,
                    'result' => [
                        'cluster_ids' => $clusterIds,
                        'primary_topics' => collect($plans)->pluck('primaryTopic')->all(),
                    ],
                    'finished_at' => now(),
                ])->save();
            });
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'failure_reason' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }

        return $run->refresh();
    }

    private function persistPlan(
        Workspace $workspace,
        ?string $clientSiteId,
        CampaignClusterRun $run,
        CampaignClusterPlan $plan,
        ?CompanyIntelligenceProfile $company
    ): CampaignCluster {
        $scores = $this->scoringService->score($plan);
        $sequence = $this->mapBuilder->publishingSequence($plan);
        $hash = hash('sha256', implode('|', [(string) $workspace->id, (string) $clientSiteId, Str::lower($plan->primaryTopic)]));

        $cluster = CampaignCluster::query()->updateOrCreate(
            ['workspace_id' => (string) $workspace->id, 'dedupe_hash' => $hash],
            [
                'organization_id' => $workspace->organization_id,
                'client_site_id' => $clientSiteId,
                'campaign_cluster_run_id' => (string) $run->id,
                'status' => CampaignCluster::STATUS_PLANNED,
                'name' => $plan->name,
                'primary_entity' => $plan->primaryEntity,
                'primary_topic' => $plan->primaryTopic,
                'authority_strategy' => $plan->authorityStrategy,
                'cta_strategy' => $plan->ctaStrategy,
                'refresh_cadence' => $plan->refreshCadence,
                'planned_start_date' => data_get($sequence, '0.planned_publish_date'),
                'planned_end_date' => data_get($sequence, (count($sequence) - 1).'.planned_publish_date'),
                'authority_score' => $scores['authority_score'],
                'topical_coverage_score' => $scores['topical_coverage_score'],
                'funnel_coverage_score' => $scores['funnel_coverage_score'],
                'ai_visibility_score' => $scores['ai_visibility_score'],
                'completeness_score' => $scores['completeness_score'],
                'funnel_coverage' => $scores['funnel_coverage'],
                'internal_link_architecture' => $this->mapBuilder->internalLinkArchitecture($plan),
                'localization_strategy' => $this->mapBuilder->localizationStrategy($plan, (array) ($company?->locales ?: ($workspace->enabled_content_languages ?? []))),
                'publishing_sequence' => $sequence,
                'timeline' => $this->mapBuilder->timeline($sequence),
                'visual_map' => $this->mapBuilder->visualMap($plan, $scores),
                'missing_coverage' => $scores['missing_coverage'],
                'authority_gaps' => $scores['authority_gaps'],
                'source_signals' => $plan->sourceSignals,
            ]
        );

        $cluster->items()->delete();
        foreach ($plan->items as $item) {
            CampaignClusterItem::query()->create(array_merge($item, [
                'campaign_cluster_id' => (string) $cluster->id,
                'planned_publish_date' => data_get($sequence, ((int) $item['sequence_order'] - 1).'.planned_publish_date'),
            ]));
        }

        $itemsByOrder = $cluster->items()->get()->keyBy('sequence_order');
        foreach ($plan->dependencies as $dependency) {
            $source = $itemsByOrder->get($dependency['source_order']);
            $target = $itemsByOrder->get($dependency['target_order']);
            if (! $source || ! $target) {
                continue;
            }

            CampaignClusterDependency::query()->create([
                'campaign_cluster_id' => (string) $cluster->id,
                'source_item_id' => (string) $source->id,
                'target_item_id' => (string) $target->id,
                'type' => $dependency['type'],
                'anchor_text' => $dependency['anchor_text'],
                'reason' => $dependency['reason'],
                'payload' => ['source_order' => $dependency['source_order'], 'target_order' => $dependency['target_order']],
            ]);
        }

        return $cluster->load(['items', 'dependencies']);
    }
}
