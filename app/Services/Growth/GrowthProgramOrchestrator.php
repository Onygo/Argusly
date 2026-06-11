<?php

namespace App\Services\Growth;

use App\Enums\GrowthProgramStatus;
use App\Models\Brief;
use App\Models\CampaignCluster;
use App\Models\Content;
use App\Models\ContentOpportunity;
use App\Models\ContentPublication;
use App\Models\CompetitorContentOpportunity;
use App\Models\Draft;
use App\Models\AgenticMarketingOpportunity;
use App\Models\GrowthAsset;
use App\Models\GrowthProgram;
use App\Models\GrowthRun;
use App\Models\Opportunity;
use App\Models\OpportunityExecutionPlan;
use App\Models\OpportunitySignal;
use App\Models\ProgrammaticBriefBlueprint;
use App\Models\ProgrammaticCluster;
use App\Models\ProgrammaticClusterItem;
use App\Models\ProgrammaticDraftRequest;
use App\Models\ProgrammaticDraftReview;
use App\Models\ProgrammaticOpportunity;
use App\Models\ProgrammaticPublicationPlan;
use App\Models\ProgrammaticPublicationPlanItem;
use App\Models\ProgrammaticPublicationReadiness;
use App\Models\User;
use App\Models\Workspace;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class GrowthProgramOrchestrator
{
    /**
     * @param array<string,mixed> $attributes
     */
    public function create(Workspace $workspace, array $attributes = [], ?User $owner = null): GrowthProgram
    {
        return DB::transaction(function () use ($workspace, $attributes, $owner): GrowthProgram {
            $status = GrowthProgramStatus::tryFrom((string) ($attributes['status'] ?? GrowthProgramStatus::DETECTED->value))
                ?? GrowthProgramStatus::DETECTED;

            $program = GrowthProgram::query()->create([
                'organization_id' => $workspace->organization_id,
                'workspace_id' => (string) $workspace->id,
                'name' => trim((string) ($attributes['name'] ?? 'Untitled growth program')),
                'description' => trim((string) ($attributes['description'] ?? '')) ?: null,
                'status' => $status->value,
                'owner_user_id' => $owner?->id ?? $attributes['owner_user_id'] ?? null,
                'score' => (float) ($attributes['score'] ?? 0),
                'estimated_impact' => (float) ($attributes['estimated_impact'] ?? 0),
                'estimated_reach' => (float) ($attributes['estimated_reach'] ?? 0),
                'estimated_ai_visibility_impact' => (float) ($attributes['estimated_ai_visibility_impact'] ?? 0),
                'metadata' => (array) ($attributes['metadata'] ?? []),
                $status->timestampColumn() => now(),
            ]);

            $this->startRun($program, $status, 'program_created', [
                'source' => (string) ($attributes['source'] ?? 'manual'),
            ], $owner);

            return $this->refreshMetrics($program);
        });
    }

    public function createFromOpportunity(Opportunity $opportunity, ?User $owner = null): GrowthProgram
    {
        $opportunity->loadMissing(['workspace', 'content', 'contentCluster', 'campaign', 'signals', 'activeExecutionPlans']);
        $workspace = $opportunity->workspace;

        if (! $workspace) {
            throw new RuntimeException('Opportunity workspace is missing.');
        }

        return DB::transaction(function () use ($opportunity, $workspace, $owner): GrowthProgram {
            $program = $this->create($workspace, [
                'name' => $this->programNameForOpportunity($opportunity),
                'description' => $opportunity->summary,
                'status' => GrowthProgramStatus::QUALIFIED->value,
                'score' => (float) ($opportunity->priority_score ?? 0),
                'estimated_impact' => (float) ($opportunity->impact_score ?? $opportunity->priority_score ?? 0),
                'estimated_reach' => $this->estimatedReachFromScore((float) ($opportunity->priority_score ?? 0)),
                'estimated_ai_visibility_impact' => $this->estimatedAiVisibilityImpact($opportunity),
                'source' => 'opportunity_intelligence',
                'metadata' => [
                    'source' => 'opportunity_intelligence',
                    'source_opportunity_id' => (string) $opportunity->id,
                    'opportunity_category' => $opportunity->category?->value ?? $opportunity->category,
                ],
            ], $owner);

            $run = $this->startRun($program, GrowthProgramStatus::QUALIFIED, 'opportunity_linked', [
                'opportunity_id' => (string) $opportunity->id,
            ], $owner);

            $this->attachOpportunity($program, $opportunity, $run);

            foreach ($opportunity->signals as $signal) {
                $this->attachSignal($program, $signal, $run);
            }

            foreach ($opportunity->activeExecutionPlans as $plan) {
                $this->attachExecutionPlan($program, $plan, $run);
            }

            if ($opportunity->content) {
                $this->linkAsset($program, $opportunity->content, GrowthAsset::ROLE_CONTENT, $run);
            }

            return $this->refreshMetrics($program);
        });
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function linkAsset(
        GrowthProgram $program,
        Model $assetable,
        ?string $role = null,
        ?GrowthRun $run = null,
        array $metadata = [],
    ): GrowthAsset {
        $role ??= $this->roleFor($assetable);

        return DB::transaction(function () use ($program, $assetable, $role, $run, $metadata): GrowthAsset {
            $workspaceId = $this->workspaceIdFor($assetable) ?: (string) $program->workspace_id;
            if ((string) $workspaceId !== (string) $program->workspace_id) {
                throw new InvalidArgumentException('Growth asset belongs to another workspace.');
            }

            $asset = GrowthAsset::query()->updateOrCreate(
                [
                    'growth_program_id' => (string) $program->id,
                    'assetable_type' => $assetable->getMorphClass(),
                    'assetable_id' => (string) $assetable->getKey(),
                    'role' => $role,
                ],
                [
                    'organization_id' => $program->organization_id,
                    'workspace_id' => (string) $program->workspace_id,
                    'growth_run_id' => $run?->id,
                    'status_at_link' => $this->statusFor($assetable),
                    'source_type' => (string) ($metadata['source'] ?? 'orchestrator'),
                    'weight' => (float) ($metadata['weight'] ?? 1),
                    'metadata' => array_replace_recursive($this->snapshotFor($assetable), $metadata),
                ]
            );

            $this->refreshMetrics($program);

            return $asset->refresh();
        });
    }

    public function attachOpportunity(GrowthProgram $program, Opportunity $opportunity, ?GrowthRun $run = null): GrowthAsset
    {
        $asset = $this->linkAsset($program, $opportunity, GrowthAsset::ROLE_OPPORTUNITY, $run, ['source' => 'opportunity_mapping']);
        $this->advanceAtLeast($program, GrowthProgramStatus::QUALIFIED);

        return $asset;
    }

    public function attachContentOpportunity(GrowthProgram $program, ContentOpportunity $opportunity, ?GrowthRun $run = null): GrowthAsset
    {
        $asset = $this->linkAsset($program, $opportunity, GrowthAsset::ROLE_CONTENT_OPPORTUNITY, $run, ['source' => 'content_opportunity_mapping']);
        $this->advanceAtLeast($program, GrowthProgramStatus::QUALIFIED);

        return $asset;
    }

    public function attachCompetitorGap(GrowthProgram $program, CompetitorContentOpportunity $opportunity, ?GrowthRun $run = null): GrowthAsset
    {
        $asset = $this->linkAsset($program, $opportunity, GrowthAsset::ROLE_COMPETITOR_GAP, $run, ['source' => 'competitor_gap_mapping']);
        $this->advanceAtLeast($program, GrowthProgramStatus::QUALIFIED);

        return $asset;
    }

    public function attachAgenticOpportunity(GrowthProgram $program, AgenticMarketingOpportunity $opportunity, ?GrowthRun $run = null): GrowthAsset
    {
        $asset = $this->linkAsset($program, $opportunity, GrowthAsset::ROLE_AGENTIC_OPPORTUNITY, $run, ['source' => 'agentic_opportunity_mapping']);
        if ($opportunity->content) {
            $this->linkAsset($program, $opportunity->content, GrowthAsset::ROLE_CONTENT, $run, ['source' => 'agentic_opportunity_content']);
        }
        $this->advanceAtLeast($program, GrowthProgramStatus::QUALIFIED);

        return $asset;
    }

    public function attachSignal(GrowthProgram $program, OpportunitySignal $signal, ?GrowthRun $run = null): GrowthAsset
    {
        $asset = $this->linkAsset($program, $signal, GrowthAsset::ROLE_SIGNAL, $run, ['source' => 'signal_mapping']);
        $this->advanceAtLeast($program, GrowthProgramStatus::DETECTED);

        return $asset;
    }

    public function attachProgrammaticOpportunity(GrowthProgram $program, ProgrammaticOpportunity $opportunity, ?GrowthRun $run = null): GrowthAsset
    {
        $asset = $this->linkAsset($program, $opportunity, GrowthAsset::ROLE_PROGRAMMATIC_OPPORTUNITY, $run, ['source' => 'programmatic_opportunity_mapping']);
        $opportunity->forceFill([
            'growth_program_id' => (string) $program->id,
            'status' => $opportunity->status === ProgrammaticOpportunity::STATUS_DETECTED ? ProgrammaticOpportunity::STATUS_VALIDATED : $opportunity->status,
            'validated_at' => $opportunity->validated_at ?: now(),
        ])->save();
        $this->advanceAtLeast($program, GrowthProgramStatus::QUALIFIED);

        return $asset;
    }

    public function attachProgrammaticCluster(GrowthProgram $program, ProgrammaticCluster $cluster, ?GrowthRun $run = null): GrowthAsset
    {
        $asset = $this->linkAsset($program, $cluster, GrowthAsset::ROLE_PROGRAMMATIC_CLUSTER, $run, ['source' => 'programmatic_cluster_mapping']);
        $cluster->forceFill(['growth_program_id' => (string) $program->id])->save();
        $this->advanceAtLeast($program, GrowthProgramStatus::PLANNED);

        return $asset;
    }

    public function attachBriefBlueprint(GrowthProgram $program, ProgrammaticBriefBlueprint $blueprint, ?GrowthRun $run = null): GrowthAsset
    {
        $asset = $this->linkAsset($program, $blueprint, GrowthAsset::ROLE_BRIEF_BLUEPRINT, $run, ['source' => 'brief_blueprint_mapping']);
        $blueprint->forceFill(['growth_program_id' => (string) $program->id])->save();
        $this->advanceAtLeast($program, GrowthProgramStatus::BRIEFED);

        return $asset;
    }

    public function buildBriefBlueprintForClusterItem(GrowthProgram $program, ProgrammaticClusterItem $item, ?GrowthRun $run = null): ProgrammaticBriefBlueprint
    {
        $item->loadMissing('cluster');
        if ((string) $item->workspace_id !== (string) $program->workspace_id) {
            throw new InvalidArgumentException('Programmatic cluster item belongs to another workspace.');
        }

        $blueprint = app(ProgrammaticBriefBlueprintBuilder::class)->build($item);
        $blueprint->forceFill(['growth_program_id' => (string) $program->id])->save();
        $this->attachBriefBlueprint($program, $blueprint, $run);

        return $blueprint->refresh();
    }

    public function buildBriefBlueprintsForCluster(GrowthProgram $program, ProgrammaticCluster $cluster, ?GrowthRun $run = null): int
    {
        if ((string) $cluster->workspace_id !== (string) $program->workspace_id) {
            throw new InvalidArgumentException('Programmatic cluster belongs to another workspace.');
        }

        $count = 0;
        $cluster->items()
            ->whereIn('status', [ProgrammaticClusterItem::STATUS_PREVIEW, ProgrammaticClusterItem::STATUS_ACCEPTED])
            ->orderByDesc('priority_score')
            ->get()
            ->each(function (ProgrammaticClusterItem $item) use ($program, $run, &$count): void {
                $this->buildBriefBlueprintForClusterItem($program, $item, $run);
                $count++;
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function syncBriefBlueprintsForProgram(GrowthProgram $program): int
    {
        $count = 0;
        $program->assets()
            ->where('role', GrowthAsset::ROLE_PROGRAMMATIC_CLUSTER)
            ->with('assetable')
            ->get()
            ->each(function (GrowthAsset $asset) use ($program, &$count): void {
                if (! $asset->assetable instanceof ProgrammaticCluster) {
                    return;
                }

                $count += $this->buildBriefBlueprintsForCluster($program, $asset->assetable);
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function buildClusterPreviewForProgrammaticOpportunity(ProgrammaticOpportunity $opportunity): ProgrammaticCluster
    {
        $cluster = app(ProgrammaticClusterBuilder::class)->build($opportunity);
        if ($opportunity->growthProgram) {
            $this->attachProgrammaticCluster($opportunity->growthProgram, $cluster);
        }

        return $cluster;
    }

    public function syncProgrammaticClustersForProgram(GrowthProgram $program): int
    {
        $count = 0;
        $program->assets()
            ->where('role', GrowthAsset::ROLE_PROGRAMMATIC_OPPORTUNITY)
            ->with('assetable')
            ->get()
            ->each(function (GrowthAsset $asset) use ($program, &$count): void {
                if (! $asset->assetable instanceof ProgrammaticOpportunity) {
                    return;
                }

                $cluster = $this->buildClusterPreviewForProgrammaticOpportunity($asset->assetable);
                $this->attachProgrammaticCluster($program, $cluster);
                $count++;
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function detectProgrammaticOpportunitiesForProgram(GrowthProgram $program): int
    {
        return $this->syncProgrammaticOpportunitiesFromAssets($program);
    }

    public function syncProgrammaticOpportunitiesFromAssets(GrowthProgram $program): int
    {
        $detector = app(ProgrammaticOpportunityDetector::class);
        $count = 0;

        $program->assets()->with('assetable')->get()
            ->filter(fn (GrowthAsset $asset): bool => in_array($asset->role, [
                GrowthAsset::ROLE_OPPORTUNITY,
                GrowthAsset::ROLE_CONTENT_OPPORTUNITY,
                GrowthAsset::ROLE_COMPETITOR_GAP,
                GrowthAsset::ROLE_AGENTIC_OPPORTUNITY,
                GrowthAsset::ROLE_SIGNAL,
            ], true))
            ->each(function (GrowthAsset $asset) use ($program, $detector, &$count): void {
                if (! $asset->assetable) {
                    return;
                }

                $programmatic = $detector->detect($asset->assetable);
                if ($programmatic) {
                    $this->attachProgrammaticOpportunity($program, $programmatic);
                    $count++;
                }
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function attachExecutionPlan(GrowthProgram $program, OpportunityExecutionPlan $plan, ?GrowthRun $run = null): GrowthAsset
    {
        $asset = $this->linkAsset($program, $plan, GrowthAsset::ROLE_EXECUTION_PLAN, $run, ['source' => 'execution_plan_mapping']);
        $this->syncExecutionPlanAssets($program, $plan, $run);
        $this->advanceAtLeast($program, GrowthProgramStatus::PLANNED);

        return $asset;
    }

    public function syncExecutionPlanAssets(GrowthProgram $program, OpportunityExecutionPlan $plan, ?GrowthRun $run = null): GrowthProgram
    {
        $plan->loadMissing(['opportunity.content', 'opportunity.signals']);

        if ($plan->opportunity) {
            $this->attachOpportunity($program, $plan->opportunity, $run);
            foreach ($plan->opportunity->signals as $signal) {
                $this->attachSignal($program, $signal, $run);
            }
            if ($plan->opportunity->content) {
                $this->linkAsset($program, $plan->opportunity->content, GrowthAsset::ROLE_CONTENT, $run, ['source' => 'execution_plan_opportunity_content']);
            }
        }

        $this->syncBriefsFromExecutionPlan($program, $plan, $run);

        return $this->refreshMetrics($program);
    }

    public function attachBrief(GrowthProgram $program, Brief $brief, ?GrowthRun $run = null): GrowthAsset
    {
        $asset = $this->linkAsset($program, $brief, GrowthAsset::ROLE_BRIEF, $run, ['source' => 'brief_mapping']);
        if ($brief->content) {
            $this->linkAsset($program, $brief->content, GrowthAsset::ROLE_CONTENT, $run, ['source' => 'brief_content']);
        }
        $this->advanceAtLeast($program, GrowthProgramStatus::BRIEFED);

        return $asset;
    }

    public function attachConvertedBrief(GrowthProgram $program, ProgrammaticBriefBlueprint $blueprint, Brief $brief, ?GrowthRun $run = null): GrowthAsset
    {
        $this->attachBriefBlueprint($program, $blueprint, $run);
        $asset = $this->attachBrief($program, $brief, $run);
        $this->advanceAtLeast($program, GrowthProgramStatus::BRIEFED);

        return $asset;
    }

    public function convertApprovedBlueprintsForCluster(GrowthProgram $program, ProgrammaticCluster $cluster, ?GrowthRun $run = null): int
    {
        if ((string) $cluster->workspace_id !== (string) $program->workspace_id) {
            throw new InvalidArgumentException('Programmatic cluster belongs to another workspace.');
        }

        $converter = app(ProgrammaticBriefConverter::class);
        $count = 0;
        ProgrammaticBriefBlueprint::query()
            ->where('programmatic_cluster_id', $cluster->id)
            ->whereIn('status', [ProgrammaticBriefBlueprint::STATUS_APPROVED, ProgrammaticBriefBlueprint::STATUS_CONVERTED])
            ->get()
            ->each(function (ProgrammaticBriefBlueprint $blueprint) use ($program, $converter, $run, &$count): void {
                $brief = $converter->convertBlueprint($blueprint);
                $this->attachConvertedBrief($program, $blueprint->refresh(), $brief, $run);
                $count++;
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function convertApprovedBlueprintsForProgram(GrowthProgram $program, ?GrowthRun $run = null): int
    {
        $converter = app(ProgrammaticBriefConverter::class);
        $count = 0;
        ProgrammaticBriefBlueprint::query()
            ->where('growth_program_id', $program->id)
            ->whereIn('status', [ProgrammaticBriefBlueprint::STATUS_APPROVED, ProgrammaticBriefBlueprint::STATUS_CONVERTED])
            ->get()
            ->each(function (ProgrammaticBriefBlueprint $blueprint) use ($program, $converter, $run, &$count): void {
                $brief = $converter->convertBlueprint($blueprint);
                $this->attachConvertedBrief($program, $blueprint->refresh(), $brief, $run);
                $count++;
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function syncConvertedBriefsFromBlueprints(GrowthProgram $program, ?GrowthRun $run = null): int
    {
        $converter = app(ProgrammaticBriefConverter::class);
        $count = 0;
        ProgrammaticBriefBlueprint::query()
            ->where('growth_program_id', $program->id)
            ->where('status', ProgrammaticBriefBlueprint::STATUS_CONVERTED)
            ->get()
            ->each(function (ProgrammaticBriefBlueprint $blueprint) use ($program, $converter, $run, &$count): void {
                $brief = $converter->existingBriefFor($blueprint);
                if (! $brief) {
                    return;
                }

                $this->attachConvertedBrief($program, $blueprint, $brief, $run);
                $count++;
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function attachDraftRequest(GrowthProgram $program, ProgrammaticDraftRequest $request, ?GrowthRun $run = null): GrowthAsset
    {
        $asset = $this->linkAsset($program, $request, GrowthAsset::ROLE_DRAFT_REQUEST, $run, ['source' => 'programmatic_draft_request_mapping']);
        $request->forceFill(['growth_program_id' => (string) $program->id])->save();
        $this->advanceAtLeast($program, GrowthProgramStatus::BRIEFED);

        return $asset;
    }

    public function prepareDraftRequestForBlueprint(GrowthProgram $program, ProgrammaticBriefBlueprint $blueprint, ?GrowthRun $run = null): ProgrammaticDraftRequest
    {
        if ((string) $blueprint->workspace_id !== (string) $program->workspace_id) {
            throw new InvalidArgumentException('Programmatic brief blueprint belongs to another workspace.');
        }

        $request = app(ProgrammaticDraftRequestBuilder::class)->buildForBlueprint($blueprint);
        $request->forceFill(['growth_program_id' => (string) $program->id])->save();
        $this->attachDraftRequest($program, $request, $run);

        return $request->refresh();
    }

    public function prepareDraftRequestsForCluster(GrowthProgram $program, ProgrammaticCluster $cluster, ?GrowthRun $run = null): int
    {
        if ((string) $cluster->workspace_id !== (string) $program->workspace_id) {
            throw new InvalidArgumentException('Programmatic cluster belongs to another workspace.');
        }

        $count = 0;
        ProgrammaticBriefBlueprint::query()
            ->where('programmatic_cluster_id', $cluster->id)
            ->where('status', ProgrammaticBriefBlueprint::STATUS_CONVERTED)
            ->limit((int) config('argusly_programmatic.max_requests_per_cluster', 25))
            ->get()
            ->each(function (ProgrammaticBriefBlueprint $blueprint) use ($program, $run, &$count): void {
                $this->prepareDraftRequestForBlueprint($program, $blueprint, $run);
                $count++;
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function prepareDraftRequestsForProgram(GrowthProgram $program, ?GrowthRun $run = null): int
    {
        $count = 0;
        ProgrammaticBriefBlueprint::query()
            ->where('growth_program_id', $program->id)
            ->where('status', ProgrammaticBriefBlueprint::STATUS_CONVERTED)
            ->limit((int) config('argusly_programmatic.max_requests_per_growth_program', 100))
            ->get()
            ->each(function (ProgrammaticBriefBlueprint $blueprint) use ($program, $run, &$count): void {
                $this->prepareDraftRequestForBlueprint($program, $blueprint, $run);
                $count++;
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function syncDraftRequestsForProgram(GrowthProgram $program, ?GrowthRun $run = null): int
    {
        return $this->prepareDraftRequestsForProgram($program, $run);
    }

    public function attachGeneratedDraft(GrowthProgram $program, ProgrammaticDraftRequest $request, Draft $draft, ?GrowthRun $run = null): GrowthAsset
    {
        $this->attachDraftRequest($program, $request, $run);
        $asset = $this->attachDraft($program, $draft, $run);
        $this->advanceAtLeast($program, GrowthProgramStatus::DRAFTING);

        return $asset;
    }

    public function generateDraftForRequest(GrowthProgram $program, ProgrammaticDraftRequest $request, ?GrowthRun $run = null): Draft
    {
        if ((string) $request->workspace_id !== (string) $program->workspace_id) {
            throw new InvalidArgumentException('Programmatic draft request belongs to another workspace.');
        }

        $draft = app(ProgrammaticDraftGenerator::class)->generate($request);
        $this->attachGeneratedDraft($program, $request->refresh(), $draft, $run);

        return $draft;
    }

    public function generateApprovedDraftsForCluster(GrowthProgram $program, ProgrammaticCluster $cluster, ?GrowthRun $run = null): int
    {
        if (! (bool) config('argusly_programmatic.allow_batch_generation', false)) {
            throw new InvalidArgumentException('Batch programmatic draft generation is disabled.');
        }

        $count = 0;
        ProgrammaticDraftRequest::query()
            ->where('programmatic_cluster_id', $cluster->id)
            ->where('status', ProgrammaticDraftRequest::STATUS_APPROVED)
            ->limit((int) config('argusly_programmatic.max_requests_per_cluster', 25))
            ->get()
            ->each(function (ProgrammaticDraftRequest $request) use ($program, $run, &$count): void {
                $this->generateDraftForRequest($program, $request, $run);
                $count++;
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function generateApprovedDraftsForProgram(GrowthProgram $program, ?GrowthRun $run = null): int
    {
        if (! (bool) config('argusly_programmatic.allow_batch_generation', false)) {
            throw new InvalidArgumentException('Batch programmatic draft generation is disabled.');
        }

        $count = 0;
        ProgrammaticDraftRequest::query()
            ->where('growth_program_id', $program->id)
            ->where('status', ProgrammaticDraftRequest::STATUS_APPROVED)
            ->limit((int) config('argusly_programmatic.max_requests_per_growth_program', 100))
            ->get()
            ->each(function (ProgrammaticDraftRequest $request) use ($program, $run, &$count): void {
                $this->generateDraftForRequest($program, $request, $run);
                $count++;
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function syncGeneratedDraftsFromRequests(GrowthProgram $program, ?GrowthRun $run = null): int
    {
        $count = 0;
        ProgrammaticDraftRequest::query()
            ->where('growth_program_id', $program->id)
            ->whereIn('status', [ProgrammaticDraftRequest::STATUS_GENERATED, ProgrammaticDraftRequest::STATUS_QUEUED])
            ->get()
            ->each(function (ProgrammaticDraftRequest $request) use ($program, $run, &$count): void {
                $draft = $request->linkedDraft();
                if (! $draft) {
                    return;
                }

                $this->attachGeneratedDraft($program, $request, $draft, $run);
                $count++;
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function attachDraftReview(GrowthProgram $program, ProgrammaticDraftReview $review, ?GrowthRun $run = null): GrowthAsset
    {
        $asset = $this->linkAsset($program, $review, GrowthAsset::ROLE_DRAFT_REVIEW, $run, ['source' => 'programmatic_draft_review_mapping']);
        $review->forceFill(['growth_program_id' => (string) $program->id])->save();
        $this->advanceAtLeast($program, GrowthProgramStatus::REVIEW);

        return $asset;
    }

    public function reviewDraftRequest(GrowthProgram $program, ProgrammaticDraftRequest $request, ?GrowthRun $run = null): ProgrammaticDraftReview
    {
        if ((string) $request->workspace_id !== (string) $program->workspace_id) {
            throw new InvalidArgumentException('Programmatic draft request belongs to another workspace.');
        }

        $review = app(ProgrammaticDraftReviewService::class)->reviewRequest($request);
        $review->forceFill(['growth_program_id' => (string) $program->id])->save();
        $this->attachDraftReview($program, $review, $run);

        return $review->refresh();
    }

    public function reviewGeneratedDraftsForCluster(GrowthProgram $program, ProgrammaticCluster $cluster, ?GrowthRun $run = null): int
    {
        $count = 0;
        ProgrammaticDraftRequest::query()
            ->where('programmatic_cluster_id', $cluster->id)
            ->where('status', ProgrammaticDraftRequest::STATUS_GENERATED)
            ->get()
            ->each(function (ProgrammaticDraftRequest $request) use ($program, $run, &$count): void {
                $this->reviewDraftRequest($program, $request, $run);
                $count++;
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function reviewGeneratedDraftsForProgram(GrowthProgram $program, ?GrowthRun $run = null): int
    {
        $count = 0;
        ProgrammaticDraftRequest::query()
            ->where('growth_program_id', $program->id)
            ->where('status', ProgrammaticDraftRequest::STATUS_GENERATED)
            ->get()
            ->each(function (ProgrammaticDraftRequest $request) use ($program, $run, &$count): void {
                $this->reviewDraftRequest($program, $request, $run);
                $count++;
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function syncDraftReviewsForProgram(GrowthProgram $program, ?GrowthRun $run = null): int
    {
        $count = 0;
        ProgrammaticDraftReview::query()
            ->where('growth_program_id', $program->id)
            ->get()
            ->each(function (ProgrammaticDraftReview $review) use ($program, $run, &$count): void {
                $this->attachDraftReview($program, $review, $run);
                $count++;
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function attachConvertedContent(GrowthProgram $program, ProgrammaticDraftReview $review, Content $content, ?GrowthRun $run = null): GrowthAsset
    {
        $this->attachDraftReview($program, $review, $run);
        $asset = $this->linkAsset($program, $content, GrowthAsset::ROLE_CONTENT, $run, ['source' => 'programmatic_content_conversion']);
        $this->advanceAtLeast($program, GrowthProgramStatus::REVIEW);

        return $asset;
    }

    public function convertReviewToContent(GrowthProgram $program, ProgrammaticDraftReview $review, ?GrowthRun $run = null, ?int $createdByUserId = null): Content
    {
        if ((string) $review->workspace_id !== (string) $program->workspace_id) {
            throw new InvalidArgumentException('Programmatic draft review belongs to another workspace.');
        }

        $content = app(ProgrammaticContentConverter::class)->convertReview($review, $createdByUserId);
        $this->attachConvertedContent($program, $review->refresh(), $content, $run);

        return $content;
    }

    public function convertApprovedReviewsForCluster(GrowthProgram $program, ProgrammaticCluster $cluster, ?GrowthRun $run = null, ?int $createdByUserId = null): int
    {
        if ((string) $cluster->workspace_id !== (string) $program->workspace_id) {
            throw new InvalidArgumentException('Programmatic cluster belongs to another workspace.');
        }

        $count = 0;
        ProgrammaticDraftReview::query()
            ->where('programmatic_cluster_id', $cluster->id)
            ->where('status', ProgrammaticDraftReview::STATUS_APPROVED)
            ->get()
            ->each(function (ProgrammaticDraftReview $review) use ($program, $run, $createdByUserId, &$count): void {
                $this->convertReviewToContent($program, $review, $run, $createdByUserId);
                $count++;
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function convertApprovedReviewsForProgram(GrowthProgram $program, ?GrowthRun $run = null, ?int $createdByUserId = null): int
    {
        $count = 0;
        ProgrammaticDraftReview::query()
            ->where('growth_program_id', $program->id)
            ->where('status', ProgrammaticDraftReview::STATUS_APPROVED)
            ->get()
            ->each(function (ProgrammaticDraftReview $review) use ($program, $run, $createdByUserId, &$count): void {
                $this->convertReviewToContent($program, $review, $run, $createdByUserId);
                $count++;
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function syncConvertedContentFromReviews(GrowthProgram $program, ?GrowthRun $run = null): int
    {
        $converter = app(ProgrammaticContentConverter::class);
        $count = 0;
        ProgrammaticDraftReview::query()
            ->where('growth_program_id', $program->id)
            ->where('status', ProgrammaticDraftReview::STATUS_APPROVED)
            ->get()
            ->each(function (ProgrammaticDraftReview $review) use ($program, $converter, $run, &$count): void {
                $content = $converter->existingContentFor($review);
                if (! $content) {
                    return;
                }

                $this->attachConvertedContent($program, $review, $content, $run);
                $count++;
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function attachPublicationReadiness(GrowthProgram $program, ProgrammaticPublicationReadiness $readiness, ?GrowthRun $run = null): GrowthAsset
    {
        $asset = $this->linkAsset($program, $readiness, GrowthAsset::ROLE_PUBLICATION_READINESS, $run, ['source' => 'publication_readiness_mapping']);
        $readiness->forceFill(['growth_program_id' => (string) $program->id])->save();
        $this->advanceAtLeast($program, GrowthProgramStatus::REVIEW);

        return $asset;
    }

    public function runPublicationReadinessForContent(GrowthProgram $program, Content $content, ?GrowthRun $run = null): ProgrammaticPublicationReadiness
    {
        if ((string) $content->workspace_id !== (string) $program->workspace_id) {
            throw new InvalidArgumentException('Content belongs to another workspace.');
        }

        $readiness = app(ProgrammaticPublicationReadinessService::class)->checkContent($content);
        $this->attachPublicationReadiness($program, $readiness, $run);

        return $readiness->refresh();
    }

    public function runPublicationReadinessForCluster(GrowthProgram $program, ProgrammaticCluster $cluster, ?GrowthRun $run = null): int
    {
        if ((string) $cluster->workspace_id !== (string) $program->workspace_id) {
            throw new InvalidArgumentException('Programmatic cluster belongs to another workspace.');
        }

        $count = 0;
        ProgrammaticDraftReview::query()
            ->where('programmatic_cluster_id', $cluster->id)
            ->with(['draft'])
            ->get()
            ->each(function (ProgrammaticDraftReview $review) use ($program, $run, &$count): void {
                $content = $review->linkedContent();
                if (! $content) {
                    return;
                }

                $this->runPublicationReadinessForContent($program, $content, $run);
                $count++;
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function runPublicationReadinessForProgram(GrowthProgram $program, ?GrowthRun $run = null): int
    {
        $count = 0;
        ProgrammaticDraftReview::query()
            ->where('growth_program_id', $program->id)
            ->with(['draft'])
            ->get()
            ->each(function (ProgrammaticDraftReview $review) use ($program, $run, &$count): void {
                $content = $review->linkedContent();
                if (! $content) {
                    return;
                }

                $this->runPublicationReadinessForContent($program, $content, $run);
                $count++;
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function syncPublicationReadinessForProgram(GrowthProgram $program, ?GrowthRun $run = null): int
    {
        $count = 0;
        ProgrammaticPublicationReadiness::query()
            ->where('growth_program_id', $program->id)
            ->get()
            ->each(function (ProgrammaticPublicationReadiness $readiness) use ($program, $run, &$count): void {
                $this->attachPublicationReadiness($program, $readiness, $run);
                $count++;
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function attachPublicationPlan(GrowthProgram $program, ProgrammaticPublicationPlan $plan, ?GrowthRun $run = null): GrowthAsset
    {
        $asset = $this->linkAsset($program, $plan, GrowthAsset::ROLE_PUBLICATION_PLAN, $run, ['source' => 'publication_plan_mapping']);
        $plan->forceFill(['growth_program_id' => (string) $program->id])->save();
        $this->advanceAtLeast($program, GrowthProgramStatus::REVIEW);

        return $asset;
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function createPublicationPlanFromReadiness(GrowthProgram $program, ProgrammaticPublicationReadiness $readiness, array $attributes = [], ?GrowthRun $run = null): ProgrammaticPublicationPlan
    {
        if ((string) $readiness->workspace_id !== (string) $program->workspace_id) {
            throw new InvalidArgumentException('Publication readiness belongs to another workspace.');
        }

        $plan = app(ProgrammaticPublicationPlanBuilder::class)->createFromReadiness($readiness, array_replace([
            'growth_program_id' => (string) $program->id,
        ], $attributes));
        $this->attachPublicationPlan($program, $plan, $run);

        return $plan->refresh();
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function createPublicationPlanForCluster(GrowthProgram $program, ProgrammaticCluster $cluster, array $attributes = [], ?GrowthRun $run = null): ProgrammaticPublicationPlan
    {
        if ((string) $cluster->workspace_id !== (string) $program->workspace_id) {
            throw new InvalidArgumentException('Programmatic cluster belongs to another workspace.');
        }

        $plan = app(ProgrammaticPublicationPlanBuilder::class)->createForCluster($cluster, array_replace([
            'growth_program_id' => (string) $program->id,
        ], $attributes));
        $this->attachPublicationPlan($program, $plan, $run);

        return $plan->refresh();
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function createPublicationPlanForProgram(GrowthProgram $program, array $attributes = [], ?GrowthRun $run = null): ProgrammaticPublicationPlan
    {
        $plan = app(ProgrammaticPublicationPlanBuilder::class)->createForProgram($program, $attributes);
        $this->attachPublicationPlan($program, $plan, $run);

        return $plan->refresh();
    }

    public function syncPublicationPlansForProgram(GrowthProgram $program, ?GrowthRun $run = null): int
    {
        $count = 0;
        ProgrammaticPublicationPlan::query()
            ->where('growth_program_id', $program->id)
            ->get()
            ->each(function (ProgrammaticPublicationPlan $plan) use ($program, $run, &$count): void {
                $this->attachPublicationPlan($program, $plan, $run);
                $count++;
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function attachScheduledPublication(GrowthProgram $program, ContentPublication $publication, ?GrowthRun $run = null): GrowthAsset
    {
        $asset = $this->attachPublication($program, $publication, $run);
        $this->advanceAtLeast($program, GrowthProgramStatus::SCHEDULED);

        return $asset;
    }

    public function schedulePublicationPlan(GrowthProgram $program, ProgrammaticPublicationPlan $plan, ?GrowthRun $run = null): int
    {
        if ((string) $plan->workspace_id !== (string) $program->workspace_id) {
            throw new InvalidArgumentException('Publication plan belongs to another workspace.');
        }

        $count = app(ProgrammaticPublicationScheduler::class)->schedulePlan($plan);
        $plan->items()->get()->each(function (ProgrammaticPublicationPlanItem $item) use ($program, $run): void {
            $publication = $item->linkedPublication();
            if ($publication) {
                $this->attachScheduledPublication($program, $publication, $run);
            }
        });
        $this->refreshMetrics($program);

        return $count;
    }

    public function scheduleApprovedPlansForProgram(GrowthProgram $program, ?GrowthRun $run = null): int
    {
        $count = 0;
        ProgrammaticPublicationPlan::query()
            ->where('growth_program_id', $program->id)
            ->where('status', ProgrammaticPublicationPlan::STATUS_APPROVED)
            ->get()
            ->each(function (ProgrammaticPublicationPlan $plan) use ($program, $run, &$count): void {
                $count += $this->schedulePublicationPlan($program, $plan, $run);
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function syncScheduledPublicationsForProgram(GrowthProgram $program, ?GrowthRun $run = null): int
    {
        $count = 0;
        ProgrammaticPublicationPlan::query()
            ->where('growth_program_id', $program->id)
            ->with('items')
            ->get()
            ->each(function (ProgrammaticPublicationPlan $plan) use ($program, $run, &$count): void {
                $plan->items->each(function (ProgrammaticPublicationPlanItem $item) use ($program, $run, &$count): void {
                    $publication = $item->linkedPublication();
                    if (! $publication) {
                        return;
                    }

                    $this->attachScheduledPublication($program, $publication, $run);
                    $count++;
                });
            });

        $this->refreshMetrics($program);

        return $count;
    }

    public function syncBriefsFromExecutionPlan(GrowthProgram $program, OpportunityExecutionPlan $plan, ?GrowthRun $run = null): GrowthProgram
    {
        Brief::query()
            ->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $program->workspace_id))
            ->where(function ($query) use ($plan): void {
                $query->where('client_refs->execution_plan_id', (string) $plan->id)
                    ->orWhere('client_refs->opportunity_execution_plan_id', (string) $plan->id);
            })
            ->with(['content', 'drafts'])
            ->get()
            ->each(function (Brief $brief) use ($program, $run): void {
                $this->attachBrief($program, $brief, $run);
                foreach ($brief->drafts as $draft) {
                    $this->attachDraft($program, $draft, $run);
                }
            });

        return $this->refreshMetrics($program);
    }

    public function attachDraft(GrowthProgram $program, Draft $draft, ?GrowthRun $run = null): GrowthAsset
    {
        $asset = $this->linkAsset($program, $draft, GrowthAsset::ROLE_DRAFT, $run, ['source' => 'draft_mapping']);
        $draft->loadMissing(['content.publications', 'brief']);
        if ($draft->content) {
            $this->linkAsset($program, $draft->content, GrowthAsset::ROLE_CONTENT, $run, ['source' => 'draft_content']);
        }
        $this->advanceAtLeast($program, $this->draftStage($draft));

        return $asset;
    }

    public function syncDraftsFromBriefs(GrowthProgram $program, ?GrowthRun $run = null): GrowthProgram
    {
        $briefIds = $program->assets()
            ->where('role', GrowthAsset::ROLE_BRIEF)
            ->where('assetable_type', (new Brief())->getMorphClass())
            ->pluck('assetable_id');

        Draft::query()
            ->whereIn('brief_id', $briefIds)
            ->with(['content.publications'])
            ->get()
            ->each(fn (Draft $draft) => $this->attachDraft($program, $draft, $run));

        return $this->refreshMetrics($program);
    }

    public function attachPublication(GrowthProgram $program, ContentPublication $publication, ?GrowthRun $run = null): GrowthAsset
    {
        $asset = $this->linkAsset($program, $publication, GrowthAsset::ROLE_PUBLICATION, $run, ['source' => 'publication_mapping']);
        $this->advanceAtLeast($program, $this->publicationStage($publication));

        return $asset;
    }

    public function syncPublicationsFromDrafts(GrowthProgram $program, ?GrowthRun $run = null): GrowthProgram
    {
        $contentIds = $program->assets()
            ->whereIn('role', [GrowthAsset::ROLE_CONTENT, GrowthAsset::ROLE_DRAFT])
            ->get()
            ->flatMap(function (GrowthAsset $asset): array {
                if ($asset->role === GrowthAsset::ROLE_CONTENT) {
                    return [(string) $asset->assetable_id];
                }

                $draft = Draft::query()->find($asset->assetable_id);

                return $draft?->content_id ? [(string) $draft->content_id] : [];
            })
            ->filter()
            ->unique()
            ->values();

        ContentPublication::query()
            ->whereIn('content_id', $contentIds)
            ->get()
            ->each(fn (ContentPublication $publication) => $this->attachPublication($program, $publication, $run));

        return $this->refreshMetrics($program);
    }

    public function startRun(
        GrowthProgram $program,
        GrowthProgramStatus|string $stage,
        string $triggeredBy = 'manual',
        array $input = [],
        ?User $actor = null,
    ): GrowthRun {
        $stage = $stage instanceof GrowthProgramStatus
            ? $stage
            : GrowthProgramStatus::tryFrom($stage) ?? GrowthProgramStatus::DETECTED;

        return GrowthRun::query()->create([
            'organization_id' => $program->organization_id,
            'workspace_id' => (string) $program->workspace_id,
            'growth_program_id' => (string) $program->id,
            'status' => GrowthRun::STATUS_RUNNING,
            'stage' => $stage->value,
            'triggered_by' => $triggeredBy,
            'input' => $input,
            'started_by' => $actor?->id,
            'started_at' => now(),
        ]);
    }

    public function completeRun(GrowthRun $run, array $result = []): GrowthRun
    {
        $program = $run->program()->firstOrFail();
        $metrics = $this->calculateMetrics($program);

        $run->forceFill([
            'status' => GrowthRun::STATUS_COMPLETED,
            'result' => $result,
            'metrics_snapshot' => $metrics,
            'finished_at' => now(),
        ])->save();

        $this->refreshMetrics($program);

        return $run->refresh();
    }

    public function failRun(GrowthRun $run, string $reason): GrowthRun
    {
        $run->forceFill([
            'status' => GrowthRun::STATUS_FAILED,
            'failure_reason' => Str::limit($reason, 2000, ''),
            'finished_at' => now(),
        ])->save();

        return $run->refresh();
    }

    public function transition(GrowthProgram $program, GrowthProgramStatus|string $target): GrowthProgram
    {
        $target = $target instanceof GrowthProgramStatus
            ? $target
            : GrowthProgramStatus::tryFrom($target) ?? throw new InvalidArgumentException('Unsupported growth program status.');
        $current = $program->status instanceof GrowthProgramStatus
            ? $program->status
            : GrowthProgramStatus::tryFrom((string) $program->status) ?? GrowthProgramStatus::DETECTED;

        if (! $current->canTransitionTo($target)) {
            throw new InvalidArgumentException('Growth programs cannot transition backwards.');
        }

        $program->forceFill([
            'status' => $target->value,
            $target->timestampColumn() => $program->{$target->timestampColumn()} ?: now(),
        ])->save();

        return $this->refreshMetrics($program);
    }

    public function refreshMetrics(GrowthProgram $program): GrowthProgram
    {
        $metrics = $this->calculateMetrics($program);

        $program->forceFill([
            'metrics' => $metrics,
            'estimated_reach' => (float) $metrics['estimated_reach'],
            'estimated_ai_visibility_impact' => (float) $metrics['estimated_ai_visibility'],
        ])->save();

        return $program->refresh();
    }

    /**
     * @return array<string,mixed>
     */
    public function calculateMetrics(GrowthProgram $program): array
    {
        $assets = $program->assets()->with('assetable')->get();
        $opportunities = $assets->where('role', GrowthAsset::ROLE_OPPORTUNITY);
        $signals = $assets->where('role', GrowthAsset::ROLE_SIGNAL);
        $contentOpportunities = $assets->where('role', GrowthAsset::ROLE_CONTENT_OPPORTUNITY);
        $competitorGaps = $assets->where('role', GrowthAsset::ROLE_COMPETITOR_GAP);
        $agenticOpportunities = $assets->where('role', GrowthAsset::ROLE_AGENTIC_OPPORTUNITY);
        $executionPlans = $assets->where('role', GrowthAsset::ROLE_EXECUTION_PLAN);
        $programmaticOpportunities = $assets->where('role', GrowthAsset::ROLE_PROGRAMMATIC_OPPORTUNITY);
        $programmaticClusters = $assets->where('role', GrowthAsset::ROLE_PROGRAMMATIC_CLUSTER);
        $briefBlueprints = $assets->where('role', GrowthAsset::ROLE_BRIEF_BLUEPRINT);
        $draftRequests = $assets->where('role', GrowthAsset::ROLE_DRAFT_REQUEST);
        $draftReviews = $assets->where('role', GrowthAsset::ROLE_DRAFT_REVIEW);
        $publicationReadiness = $assets->where('role', GrowthAsset::ROLE_PUBLICATION_READINESS);
        $publicationPlans = $assets->where('role', GrowthAsset::ROLE_PUBLICATION_PLAN);
        $briefs = $assets->where('role', GrowthAsset::ROLE_BRIEF);
        $drafts = $assets->where('role', GrowthAsset::ROLE_DRAFT);
        $publications = $assets->where('role', GrowthAsset::ROLE_PUBLICATION);
        $contentAssets = $assets->where('role', GrowthAsset::ROLE_CONTENT);
        $clusters = $assets->where('role', GrowthAsset::ROLE_CAMPAIGN_CLUSTER);

        $publishedContentCount = $contentAssets
            ->filter(fn (GrowthAsset $asset): bool => $this->isPublishedContent($asset->assetable))
            ->count();
        $publishedPublicationCount = $publications
            ->filter(fn (GrowthAsset $asset): bool => $this->isPublishedPublication($asset->assetable))
            ->count();
        $scheduledCount = $publications
            ->filter(fn (GrowthAsset $asset): bool => $this->isScheduledPublication($asset->assetable))
            ->count();
        $scheduledProgrammaticPublications = $publications
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof ContentPublication
                && data_get($asset->assetable->meta, 'source') === 'programmatic_publication_scheduler'
                && (string) $asset->assetable->remote_status === ContentPublication::REMOTE_SCHEDULED);
        $pendingProgrammaticPublications = $publications
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof ContentPublication
                && data_get($asset->assetable->meta, 'source') === 'programmatic_publication_scheduler'
                && (string) $asset->assetable->delivery_status === ContentPublication::STATUS_PENDING);
        $scheduledPublicationWindowStart = $scheduledProgrammaticPublications
            ->map(fn (GrowthAsset $asset): ?string => data_get($asset->assetable?->meta, 'planned_publish_at'))
            ->filter()
            ->sort()
            ->first();
        $scheduledPublicationWindowEnd = $scheduledProgrammaticPublications
            ->map(fn (GrowthAsset $asset): ?string => data_get($asset->assetable?->meta, 'planned_publish_at'))
            ->filter()
            ->sortDesc()
            ->first();
        $publishedCount = max($publishedContentCount, $publishedPublicationCount);
        $measuredCount = $contentAssets
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof Content && $asset->assetable->first_published_at !== null)
            ->count();
        $priorityScore = max(
            (float) $program->score,
            (float) $opportunities->max(fn (GrowthAsset $asset): float => (float) ($asset->assetable?->priority_score ?? 0)),
            (float) $contentOpportunities->max(fn (GrowthAsset $asset): float => (float) ($asset->assetable?->priority_score ?? 0)),
            (float) $competitorGaps->max(fn (GrowthAsset $asset): float => (float) ($asset->assetable?->priority_score ?? 0)),
            (float) $agenticOpportunities->max(fn (GrowthAsset $asset): float => (float) ($asset->assetable?->priority_score ?? 0)),
        );
        $clusterReach = (float) $clusters->sum(fn (GrowthAsset $asset): float => (float) ($asset->assetable?->completeness_score ?? 0) * 25);
        $programmaticClusterItemsCount = (int) $programmaticClusters->sum(fn (GrowthAsset $asset): int => $asset->assetable instanceof ProgrammaticCluster ? (int) $asset->assetable->items()->count() : 0);
        $acceptedClusterItemsCount = (int) $programmaticClusters->sum(fn (GrowthAsset $asset): int => $asset->assetable instanceof ProgrammaticCluster ? (int) $asset->assetable->items()->where('status', 'accepted')->count() : 0);
        $rejectedClusterItemsCount = (int) $programmaticClusters->sum(fn (GrowthAsset $asset): int => $asset->assetable instanceof ProgrammaticCluster ? (int) $asset->assetable->items()->where('status', 'rejected')->count() : 0);
        $estimatedProgrammaticReach = (float) $programmaticClusters->sum(fn (GrowthAsset $asset): float => (float) ($asset->assetable?->estimated_reach ?? 0));
        $estimatedProgrammaticAiVisibility = (float) $programmaticClusters->max(fn (GrowthAsset $asset): float => (float) ($asset->assetable?->estimated_ai_visibility ?? 0));
        $estimatedProgrammaticBusinessImpact = (float) $programmaticClusters->max(fn (GrowthAsset $asset): float => (float) ($asset->assetable?->estimated_business_impact ?? 0));
        $approvedBriefBlueprintsCount = $briefBlueprints
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof ProgrammaticBriefBlueprint && $asset->assetable->status === ProgrammaticBriefBlueprint::STATUS_APPROVED)
            ->count();
        $rejectedBriefBlueprintsCount = $briefBlueprints
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof ProgrammaticBriefBlueprint && $asset->assetable->status === ProgrammaticBriefBlueprint::STATUS_REJECTED)
            ->count();
        $convertedBlueprintsCount = $briefBlueprints
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof ProgrammaticBriefBlueprint && $asset->assetable->status === ProgrammaticBriefBlueprint::STATUS_CONVERTED)
            ->count();
        $programmaticBriefsCount = $briefs
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof Brief && data_get($asset->assetable->client_refs, 'source_type') === 'programmatic_brief_blueprint')
            ->count();
        $approvedDraftRequestsCount = $draftRequests
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof ProgrammaticDraftRequest && $asset->assetable->status === ProgrammaticDraftRequest::STATUS_APPROVED)
            ->count();
        $queuedDraftRequestsCount = $draftRequests
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof ProgrammaticDraftRequest && $asset->assetable->status === ProgrammaticDraftRequest::STATUS_QUEUED)
            ->count();
        $generatedDraftRequestsCount = $draftRequests
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof ProgrammaticDraftRequest && $asset->assetable->status === ProgrammaticDraftRequest::STATUS_GENERATED)
            ->count();
        $failedProgrammaticDraftsCount = $draftRequests
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof ProgrammaticDraftRequest && $asset->assetable->status === ProgrammaticDraftRequest::STATUS_FAILED)
            ->count();
        $queuedProgrammaticDraftsCount = $draftRequests
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof ProgrammaticDraftRequest && $asset->assetable->status === ProgrammaticDraftRequest::STATUS_QUEUED)
            ->count();
        $generatedProgrammaticDraftsCount = $drafts
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof Draft && data_get($asset->assetable->meta, 'source') === 'programmatic_draft_request')
            ->count();
        $approvedDraftReviewsCount = $draftReviews
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof ProgrammaticDraftReview && $asset->assetable->status === ProgrammaticDraftReview::STATUS_APPROVED)
            ->count();
        $blockedDraftReviewsCount = $draftReviews
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof ProgrammaticDraftReview && $asset->assetable->status === ProgrammaticDraftReview::STATUS_BLOCKED)
            ->count();
        $programmaticContentAssets = $contentAssets
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof Content && data_get($asset->metadata, 'source') === 'programmatic_content_conversion');
        $programmaticContentCount = $programmaticContentAssets->count();
        $contentReadyForPublicationCount = $programmaticContentAssets
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof Content
                && $asset->assetable->current_revision_id !== null
                && ! in_array((string) $asset->assetable->publish_status, ['published', 'delivered'], true))
            ->count();
        $approvedPublicationReadinessCount = $publicationReadiness
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof ProgrammaticPublicationReadiness && $asset->assetable->status === ProgrammaticPublicationReadiness::STATUS_APPROVED)
            ->count();
        $blockedPublicationReadinessCount = $publicationReadiness
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof ProgrammaticPublicationReadiness && $asset->assetable->status === ProgrammaticPublicationReadiness::STATUS_BLOCKED)
            ->count();
        $averagePublicationReadinessScore = round((float) $publicationReadiness->avg(fn (GrowthAsset $asset): float => (float) ($asset->assetable?->readiness_score ?? 0)), 2);
        $publicationReadyContentCount = $publicationReadiness
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof ProgrammaticPublicationReadiness
                && in_array($asset->assetable->status, [ProgrammaticPublicationReadiness::STATUS_READY, ProgrammaticPublicationReadiness::STATUS_APPROVED], true))
            ->count();
        $publicationPlanItemsCount = (int) $publicationPlans->sum(fn (GrowthAsset $asset): int => $asset->assetable instanceof ProgrammaticPublicationPlan ? (int) $asset->assetable->items()->count() : 0);
        $approvedPublicationPlanItemsCount = (int) $publicationPlans->sum(fn (GrowthAsset $asset): int => $asset->assetable instanceof ProgrammaticPublicationPlan ? (int) $asset->assetable->items()->where('status', ProgrammaticPublicationPlanItem::STATUS_APPROVED)->count() : 0);
        $plannedWindowStart = $publicationPlans
            ->map(fn (GrowthAsset $asset): ?string => $asset->assetable instanceof ProgrammaticPublicationPlan ? $asset->assetable->planned_start_at?->toIso8601String() : null)
            ->filter()
            ->sort()
            ->first();
        $plannedWindowEnd = $publicationPlans
            ->map(fn (GrowthAsset $asset): ?string => $asset->assetable instanceof ProgrammaticPublicationPlan ? $asset->assetable->planned_end_at?->toIso8601String() : null)
            ->filter()
            ->sortDesc()
            ->first();
        $blueprintReadinessPercentage = $briefBlueprints->count() > 0
            ? (int) round($briefBlueprints->avg(fn (GrowthAsset $asset): int => $asset->assetable instanceof ProgrammaticBriefBlueprint ? $asset->assetable->readinessPercentage() : 0))
            : 0;
        $status = $program->status instanceof GrowthProgramStatus
            ? $program->status
            : GrowthProgramStatus::tryFrom((string) $program->status) ?? GrowthProgramStatus::DETECTED;

        return [
            'opportunities_count' => $opportunities->count(),
            'content_opportunities_count' => $contentOpportunities->count(),
            'competitor_gaps_count' => $competitorGaps->count(),
            'agentic_opportunities_count' => $agenticOpportunities->count(),
            'signals_count' => $signals->count(),
            'execution_plans_count' => $executionPlans->count(),
            'programmatic_opportunities_count' => $programmaticOpportunities->count(),
            'programmatic_clusters_count' => $programmaticClusters->count(),
            'programmatic_cluster_items_count' => $programmaticClusterItemsCount,
            'accepted_cluster_items_count' => $acceptedClusterItemsCount,
            'rejected_cluster_items_count' => $rejectedClusterItemsCount,
            'brief_blueprints_count' => $briefBlueprints->count(),
            'approved_brief_blueprints_count' => $approvedBriefBlueprintsCount,
            'rejected_brief_blueprints_count' => $rejectedBriefBlueprintsCount,
            'converted_blueprints_count' => $convertedBlueprintsCount,
            'programmatic_briefs_count' => $programmaticBriefsCount,
            'blueprint_readiness_percentage' => $blueprintReadinessPercentage,
            'draft_requests_count' => $draftRequests->count(),
            'approved_draft_requests_count' => $approvedDraftRequestsCount,
            'queued_draft_requests_count' => $queuedDraftRequestsCount,
            'generated_draft_requests_count' => $generatedDraftRequestsCount,
            'estimated_generation_cost' => round((float) $draftRequests->sum(fn (GrowthAsset $asset): float => (float) ($asset->assetable?->estimated_cost ?? 0)), 4),
            'estimated_generation_tokens' => (int) $draftRequests->sum(fn (GrowthAsset $asset): int => (int) ($asset->assetable?->estimated_tokens ?? 0)),
            'generated_programmatic_drafts_count' => $generatedProgrammaticDraftsCount,
            'failed_programmatic_drafts_count' => $failedProgrammaticDraftsCount,
            'queued_programmatic_drafts_count' => $queuedProgrammaticDraftsCount,
            'actual_generation_cost' => round((float) $draftRequests->sum(fn (GrowthAsset $asset): float => (float) data_get($asset->assetable?->metadata, 'actual_generation_cost', 0)), 4),
            'actual_generation_tokens' => (int) $draftRequests->sum(fn (GrowthAsset $asset): int => (int) data_get($asset->assetable?->metadata, 'actual_generation_tokens', 0)),
            'draft_reviews_count' => $draftReviews->count(),
            'approved_draft_reviews_count' => $approvedDraftReviewsCount,
            'blocked_draft_reviews_count' => $blockedDraftReviewsCount,
            'average_draft_quality_score' => round((float) $draftReviews->avg(fn (GrowthAsset $asset): float => (float) ($asset->assetable?->overall_score ?? 0)), 2),
            'average_seo_score' => round((float) $draftReviews->avg(fn (GrowthAsset $asset): float => (float) ($asset->assetable?->seo_score ?? 0)), 2),
            'average_ai_visibility_score' => round((float) $draftReviews->avg(fn (GrowthAsset $asset): float => (float) ($asset->assetable?->ai_visibility_score ?? 0)), 2),
            'average_risk_score' => round((float) $draftReviews->avg(fn (GrowthAsset $asset): float => (float) ($asset->assetable?->risk_score ?? 0)), 2),
            'programmatic_content_count' => $programmaticContentCount,
            'converted_content_count' => $programmaticContentCount,
            'content_ready_for_publication_count' => $contentReadyForPublicationCount,
            'publication_readiness_count' => $publicationReadiness->count(),
            'approved_publication_readiness_count' => $approvedPublicationReadinessCount,
            'blocked_publication_readiness_count' => $blockedPublicationReadinessCount,
            'average_publication_readiness_score' => $averagePublicationReadinessScore,
            'publication_ready_content_count' => $publicationReadyContentCount,
            'publication_plans_count' => $publicationPlans->count(),
            'publication_plan_items_count' => $publicationPlanItemsCount,
            'approved_publication_plan_items_count' => $approvedPublicationPlanItemsCount,
            'planned_publication_window_start' => $plannedWindowStart,
            'planned_publication_window_end' => $plannedWindowEnd,
            'scheduled_programmatic_publications_count' => $scheduledProgrammaticPublications->count(),
            'pending_programmatic_publications_count' => $pendingProgrammaticPublications->count(),
            'scheduled_publication_window_start' => $scheduledPublicationWindowStart,
            'scheduled_publication_window_end' => $scheduledPublicationWindowEnd,
            'briefs_count' => $briefs->count(),
            'drafts_count' => $drafts->count(),
            'publications_count' => $publications->count(),
            'scheduled_count' => $scheduledCount,
            'published_count' => $publishedCount,
            'measured_count' => $measuredCount,
            'assets_count' => $assets->count(),
            'clusters_count' => $clusters->count(),
            'progress_percentage' => $status->progress(),
            'current_stage_label' => $status->label(),
            'next_recommended_action' => $this->nextRecommendedAction($status, [
                'execution_plans_count' => $executionPlans->count(),
                'programmatic_clusters_count' => $programmaticClusters->count(),
                'brief_blueprints_count' => $briefBlueprints->count(),
                'briefs_count' => $briefs->count(),
                'drafts_count' => $drafts->count(),
                'publications_count' => $publications->count(),
                'published_count' => $publishedCount,
            ]),
            'estimated_reach' => round(max((float) $program->estimated_reach, $priorityScore * 100, $clusterReach), 2),
            'estimated_traffic' => round(max($priorityScore * 18, $clusterReach * 0.3), 2),
            'estimated_ai_visibility' => round(max((float) $program->estimated_ai_visibility_impact, $priorityScore, (float) $clusters->max(fn (GrowthAsset $asset): float => (float) ($asset->assetable?->ai_visibility_score ?? 0))), 2),
            'estimated_programmatic_reach' => round($estimatedProgrammaticReach, 2),
            'estimated_programmatic_ai_visibility' => round($estimatedProgrammaticAiVisibility, 2),
            'estimated_programmatic_business_impact' => round($estimatedProgrammaticBusinessImpact, 2),
        ];
    }

    private function roleFor(Model $assetable): string
    {
        return match (true) {
            $assetable instanceof Opportunity => GrowthAsset::ROLE_OPPORTUNITY,
            $assetable instanceof ContentOpportunity => GrowthAsset::ROLE_CONTENT_OPPORTUNITY,
            $assetable instanceof CompetitorContentOpportunity => GrowthAsset::ROLE_COMPETITOR_GAP,
            $assetable instanceof AgenticMarketingOpportunity => GrowthAsset::ROLE_AGENTIC_OPPORTUNITY,
            $assetable instanceof OpportunitySignal => GrowthAsset::ROLE_SIGNAL,
            $assetable instanceof ProgrammaticOpportunity => GrowthAsset::ROLE_PROGRAMMATIC_OPPORTUNITY,
            $assetable instanceof ProgrammaticCluster => GrowthAsset::ROLE_PROGRAMMATIC_CLUSTER,
            $assetable instanceof ProgrammaticBriefBlueprint => GrowthAsset::ROLE_BRIEF_BLUEPRINT,
            $assetable instanceof ProgrammaticDraftRequest => GrowthAsset::ROLE_DRAFT_REQUEST,
            $assetable instanceof ProgrammaticDraftReview => GrowthAsset::ROLE_DRAFT_REVIEW,
            $assetable instanceof ProgrammaticPublicationReadiness => GrowthAsset::ROLE_PUBLICATION_READINESS,
            $assetable instanceof ProgrammaticPublicationPlan => GrowthAsset::ROLE_PUBLICATION_PLAN,
            $assetable instanceof OpportunityExecutionPlan => GrowthAsset::ROLE_EXECUTION_PLAN,
            $assetable instanceof Brief => GrowthAsset::ROLE_BRIEF,
            $assetable instanceof Draft => GrowthAsset::ROLE_DRAFT,
            $assetable instanceof Content => GrowthAsset::ROLE_CONTENT,
            $assetable instanceof ContentPublication => GrowthAsset::ROLE_PUBLICATION,
            $assetable instanceof CampaignCluster => GrowthAsset::ROLE_CAMPAIGN_CLUSTER,
            default => throw new InvalidArgumentException('Unsupported growth asset type: '.$assetable::class),
        };
    }

    private function workspaceIdFor(Model $assetable): ?string
    {
        return match (true) {
            $assetable->getAttribute('workspace_id') !== null => (string) $assetable->getAttribute('workspace_id'),
            $assetable instanceof Brief => (string) ($assetable->loadMissing('clientSite')->clientSite?->workspace_id ?? ''),
            $assetable instanceof Draft => (string) ($assetable->loadMissing('clientSite')->clientSite?->workspace_id ?? ''),
            $assetable instanceof ContentPublication => (string) ($assetable->loadMissing('content')->content?->workspace_id ?? ''),
            $assetable instanceof AgenticMarketingOpportunity => (string) ($assetable->loadMissing(['content', 'objective'])->content?->workspace_id ?? $assetable->objective?->workspace_id ?? ''),
            default => null,
        };
    }

    private function statusFor(Model $assetable): ?string
    {
        foreach (['status', 'publish_status', 'delivery_status'] as $field) {
            if (isset($assetable->{$field})) {
                $value = $assetable->{$field};

                return $value instanceof BackedEnum
                    ? (string) $value->value
                    : (string) $value;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshotFor(Model $assetable): array
    {
        return [
            'asset_type' => $assetable::class,
            'asset_id' => (string) $assetable->getKey(),
            'title' => (string) ($assetable->title ?? $assetable->name ?? ''),
            'linked_at' => now()->toIso8601String(),
        ];
    }

    private function programNameForOpportunity(Opportunity $opportunity): string
    {
        $category = str_replace('_', ' ', (string) ($opportunity->category?->value ?? $opportunity->category ?? 'Growth'));
        $topic = trim((string) ($opportunity->topic ?: $opportunity->title ?: 'Growth program'));

        return Str::headline($category).': '.Str::limit($topic, 120, '');
    }

    private function estimatedReachFromScore(float $score): float
    {
        return round(max(1000.0, $score * 100.0), 2);
    }

    private function estimatedAiVisibilityImpact(Opportunity $opportunity): float
    {
        $category = (string) ($opportunity->category?->value ?? $opportunity->category ?? '');
        $score = (float) ($opportunity->impact_score ?: $opportunity->priority_score ?: 0);

        return str_contains($category, 'ai_visibility') ? max(60.0, $score) : $score;
    }

    private function isPublishedContent(mixed $assetable): bool
    {
        return $assetable instanceof Content
            && in_array((string) $assetable->publish_status, ['published', 'delivered'], true);
    }

    private function isPublishedPublication(mixed $assetable): bool
    {
        return $assetable instanceof ContentPublication
            && in_array((string) $assetable->delivery_status, ['delivered', 'published', 'success'], true);
    }

    private function isScheduledPublication(mixed $assetable): bool
    {
        return $assetable instanceof ContentPublication
            && in_array((string) $assetable->remote_status, ['scheduled'], true);
    }

    private function advanceAtLeast(GrowthProgram $program, GrowthProgramStatus $target): GrowthProgram
    {
        $current = $program->status instanceof GrowthProgramStatus
            ? $program->status
            : GrowthProgramStatus::tryFrom((string) $program->status) ?? GrowthProgramStatus::DETECTED;

        if ($current->progress() >= $target->progress()) {
            return $this->refreshMetrics($program);
        }

        return $this->transition($program, $target);
    }

    private function draftStage(Draft $draft): GrowthProgramStatus
    {
        return in_array((string) $draft->status, [Draft::STATUS_READY_FOR_REVIEW, Draft::STATUS_CHANGES_REQUESTED, Draft::STATUS_APPROVED_FOR_PUBLISHING], true)
            ? GrowthProgramStatus::REVIEW
            : GrowthProgramStatus::DRAFTING;
    }

    private function publicationStage(ContentPublication $publication): GrowthProgramStatus
    {
        if ($this->isPublishedPublication($publication)) {
            return GrowthProgramStatus::PUBLISHED;
        }

        if ($this->isScheduledPublication($publication)) {
            return GrowthProgramStatus::SCHEDULED;
        }

        return GrowthProgramStatus::REVIEW;
    }

    /**
     * @param array<string,int> $counts
     */
    private function nextRecommendedAction(GrowthProgramStatus $status, array $counts): ?string
    {
        if (($counts['execution_plans_count'] ?? 0) < 1 && ($counts['programmatic_clusters_count'] ?? 0) < 1) {
            return 'Create or attach an execution plan.';
        }

        if (($counts['brief_blueprints_count'] ?? 0) < 1 && (($counts['briefs_count'] ?? 0) < 1)) {
            return 'Prepare programmatic brief blueprints.';
        }

        if (($counts['briefs_count'] ?? 0) < 1) {
            return 'Create or attach a content brief.';
        }

        if (($counts['drafts_count'] ?? 0) < 1) {
            return 'Generate or attach a draft.';
        }

        if (($counts['publications_count'] ?? 0) < 1) {
            return 'Schedule or attach a publication record.';
        }

        if (($counts['published_count'] ?? 0) < 1 && $status->progress() < GrowthProgramStatus::PUBLISHED->progress()) {
            return 'Publish when review and scheduling are complete.';
        }

        return $status === GrowthProgramStatus::MEASURED ? null : 'Measure performance and update outcomes.';
    }
}
