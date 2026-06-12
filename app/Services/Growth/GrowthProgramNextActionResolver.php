<?php

namespace App\Services\Growth;

use App\Models\GrowthAsset;
use App\Models\GrowthProgram;
use App\Models\ProgrammaticBriefBlueprint;
use App\Models\ProgrammaticDraftRequest;
use App\Models\ProgrammaticDraftReview;
use App\Models\ProgrammaticPublicationPlan;
use App\Models\ProgrammaticPublicationPlanItem;
use App\Models\ProgrammaticPublicationReadiness;
use Illuminate\Support\Collection;

class GrowthProgramNextActionResolver
{
    /**
     * @return array<string,mixed>
     */
    public function resolve(GrowthProgram $program): array
    {
        $assets = $program->relationLoaded('assets')
            ? $program->assets
            : $program->assets()->with('assetable')->get();
        $assetsByRole = $assets->groupBy('role');
        $metrics = (array) ($program->metrics ?? []);
        $planItems = $this->planItems($assetsByRole);

        $steps = $this->steps($program, $assetsByRole, $metrics, $planItems);
        $primary = $this->primaryAction($program, $metrics, $steps);
        $health = $this->health($metrics, $planItems, $assetsByRole);

        return [
            'stage' => $primary['stage'],
            'primary_action' => $primary,
            'secondary_actions' => $this->secondaryActions($program),
            'steps' => $steps,
            'health' => $health,
        ];
    }

    /**
     * @param Collection<string,Collection<int,GrowthAsset>> $assetsByRole
     * @param array<string,mixed> $metrics
     * @param Collection<int,ProgrammaticPublicationPlanItem> $planItems
     * @return array<int,array<string,mixed>>
     */
    private function steps(GrowthProgram $program, Collection $assetsByRole, array $metrics, Collection $planItems): array
    {
        $blockedReviews = $assetsByRole->get(GrowthAsset::ROLE_DRAFT_REVIEW, collect())
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof ProgrammaticDraftReview
                && $asset->assetable->status === ProgrammaticDraftReview::STATUS_BLOCKED);
        $blockedReadiness = $assetsByRole->get(GrowthAsset::ROLE_PUBLICATION_READINESS, collect())
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof ProgrammaticPublicationReadiness
                && $asset->assetable->status === ProgrammaticPublicationReadiness::STATUS_BLOCKED);
        $conflicts = $planItems->filter(fn (ProgrammaticPublicationPlanItem $item): bool => data_get($item->metadata, 'conflict.reason') !== null);

        return [
            $this->step('Opportunity', (int) ($metrics['opportunities_count'] ?? 0), 'Detect programmatic opportunities', 'Ready when an opportunity or signal is attached.'),
            $this->step('Programmatic Opportunity', (int) ($metrics['programmatic_opportunities_count'] ?? 0), 'Build cluster preview', 'Validate the opportunity before expanding it.'),
            $this->step('Cluster', (int) ($metrics['programmatic_clusters_count'] ?? 0), 'Build brief blueprints', 'Cluster items define the programmatic asset map.'),
            $this->step('Blueprint', (int) ($metrics['brief_blueprints_count'] ?? 0), 'Convert approved blueprints to briefs', 'Approve blueprints before creating briefs.'),
            $this->step('Brief', (int) ($metrics['briefs_count'] ?? 0), 'Prepare draft requests', 'Converted briefs become draft request inputs.'),
            $this->step('Draft Request', (int) ($metrics['draft_requests_count'] ?? 0), 'Create approved drafts', 'Approve draft requests before creating drafts.'),
            $this->step('Draft', (int) ($metrics['drafts_count'] ?? 0), 'Run quality checks', 'Generated drafts need quality checks.', null),
            $this->step('Review', (int) ($metrics['draft_reviews_count'] ?? 0), 'Convert approved reviews to content', 'Resolve blocked quality checks before conversion.', $this->blockedReviewReason($blockedReviews)),
            $this->step('Content', (int) ($metrics['converted_content_count'] ?? 0), 'Run publication readiness', 'Converted content needs publication readiness checks.'),
            $this->step('Readiness', (int) ($metrics['publication_readiness_count'] ?? 0), 'Create publication plan', 'Approve publication readiness before planning.', $this->blockedReadinessReason($blockedReadiness)),
            $this->step('Plan', (int) ($metrics['publication_plans_count'] ?? 0), 'Prepare scheduled publications', 'Approved plan items become scheduled publication assets.', $this->conflictReason($conflicts)),
            $this->step('Scheduled', (int) ($metrics['scheduled_programmatic_publications_count'] ?? 0), 'Monitor scheduled assets', 'No live publishing is triggered from this command center.'),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $steps
     * @return array<string,mixed>
     */
    private function primaryAction(GrowthProgram $program, array $metrics, array $steps): array
    {
        $actions = [
            [
                'stage' => 'Opportunity',
                'label' => 'Detect Programmatic Opportunities',
                'ability' => 'prepare',
                'route' => route('app.growth-programs.detect-programmatic-opportunities', $program),
                'helper' => 'Find programmatic expansion opportunities from attached opportunities and signals.',
                'ready' => ((int) ($metrics['programmatic_opportunities_count'] ?? 0)) < 1
                    && ((int) ($metrics['opportunities_count'] ?? 0) + (int) ($metrics['signals_count'] ?? 0)) > 0,
                'missing' => ((int) ($metrics['opportunities_count'] ?? 0) + (int) ($metrics['signals_count'] ?? 0)) < 1 ? ['Attach an opportunity or signal first.'] : [],
            ],
            [
                'stage' => 'Programmatic Opportunity',
                'label' => 'Build Cluster Preview',
                'ability' => 'prepare',
                'route' => route('app.growth-programs.build-cluster-previews', $program),
                'helper' => 'Expand validated programmatic opportunities into a controlled cluster preview.',
                'ready' => ((int) ($metrics['programmatic_opportunities_count'] ?? 0)) > 0 && ((int) ($metrics['programmatic_clusters_count'] ?? 0)) < 1,
                'missing' => ['Detect or attach a programmatic opportunity first.'],
            ],
            [
                'stage' => 'Cluster',
                'label' => 'Build Brief Blueprints',
                'ability' => 'prepare',
                'route' => route('app.growth-programs.build-brief-blueprints', $program),
                'helper' => 'Turn cluster items into editorial brief blueprints.',
                'ready' => ((int) ($metrics['programmatic_clusters_count'] ?? 0)) > 0 && ((int) ($metrics['brief_blueprints_count'] ?? 0)) < 1,
                'missing' => ['Build a cluster preview first.'],
            ],
            [
                'stage' => 'Blueprint',
                'label' => 'Convert Approved Blueprints to Briefs',
                'ability' => 'approve',
                'route' => route('app.growth-programs.convert-approved-blueprints', $program),
                'helper' => 'Create briefs only from approved blueprints.',
                'ready' => ((int) ($metrics['approved_brief_blueprints_count'] ?? 0)) > 0 && ((int) ($metrics['converted_blueprints_count'] ?? 0)) < (int) ($metrics['approved_brief_blueprints_count'] ?? 0),
                'missing' => ['Review and approve blueprints first.'],
            ],
            [
                'stage' => 'Brief',
                'label' => 'Prepare Draft Requests',
                'ability' => 'prepare',
                'route' => route('app.growth-programs.prepare-draft-requests', $program),
                'helper' => 'Prepare draft request assets from converted briefs.',
                'ready' => ((int) ($metrics['converted_blueprints_count'] ?? 0)) > 0 && ((int) ($metrics['draft_requests_count'] ?? 0)) < 1,
                'missing' => ['Convert approved blueprints to briefs first.'],
            ],
            [
                'stage' => 'Draft Request',
                'label' => 'Create Approved Drafts',
                'ability' => 'prepare',
                'route' => route('app.growth-programs.generate-approved-draft-requests', $program),
                'helper' => 'Create drafts for approved draft requests.',
                'ready' => ((int) ($metrics['approved_draft_requests_count'] ?? 0)) > 0 && ((int) ($metrics['generated_draft_requests_count'] ?? 0)) < (int) ($metrics['approved_draft_requests_count'] ?? 0),
                'missing' => ['Approve draft requests first.'],
            ],
            [
                'stage' => 'Draft',
                'label' => 'Run Draft Quality Checks',
                'ability' => 'prepare',
                'route' => route('app.growth-programs.review-generated-drafts', $program),
                'helper' => 'Run quality checks for generated drafts before conversion.',
                'ready' => ((int) ($metrics['generated_draft_requests_count'] ?? 0)) > 0 && ((int) ($metrics['draft_reviews_count'] ?? 0)) < (int) ($metrics['generated_draft_requests_count'] ?? 0),
                'missing' => ['Create approved drafts first.'],
            ],
            [
                'stage' => 'Review',
                'label' => 'Convert Approved Reviews to Content',
                'ability' => 'approve',
                'route' => route('app.growth-programs.convert-approved-reviews-to-content', $program),
                'helper' => 'Convert approved quality checks into content assets.',
                'ready' => ((int) ($metrics['approved_draft_reviews_count'] ?? 0)) > 0 && ((int) ($metrics['converted_content_count'] ?? 0)) < (int) ($metrics['approved_draft_reviews_count'] ?? 0),
                'missing' => ['Approve draft quality checks first.'],
            ],
            [
                'stage' => 'Content',
                'label' => 'Run Publication Readiness',
                'ability' => 'prepare',
                'route' => route('app.growth-programs.publication-readiness', $program),
                'helper' => 'Check destination, SEO, schema and publication risk before planning.',
                'ready' => ((int) ($metrics['converted_content_count'] ?? 0)) > 0 && ((int) ($metrics['publication_readiness_count'] ?? 0)) < (int) ($metrics['converted_content_count'] ?? 0),
                'missing' => ['Convert approved reviews to content first.'],
            ],
            [
                'stage' => 'Readiness',
                'label' => 'Create Publication Plan',
                'ability' => 'approve',
                'route' => route('app.growth-programs.publication-plans.create', $program),
                'helper' => 'Create a plan from approved publication readiness assets.',
                'ready' => ((int) ($metrics['approved_publication_readiness_count'] ?? 0)) > 0 && ((int) ($metrics['publication_plans_count'] ?? 0)) < 1,
                'missing' => ['Approve publication readiness first.'],
            ],
            [
                'stage' => 'Plan',
                'label' => 'Prepare Scheduled Publications',
                'ability' => 'approve',
                'route' => route('app.growth-programs.publication-plans.schedule', $program),
                'helper' => 'Prepare scheduled publication assets. This does not publish content live.',
                'ready' => ((int) ($metrics['approved_publication_plan_items_count'] ?? 0)) > 0 && ((int) ($metrics['scheduled_programmatic_publications_count'] ?? 0)) < (int) ($metrics['approved_publication_plan_items_count'] ?? 0),
                'missing' => ['Approve a publication plan first.'],
            ],
        ];

        $selected = collect($actions)->filter(fn (array $action): bool => (bool) $action['ready'])->last()
            ?? collect($actions)->first();

        $selected['method'] = 'POST';
        $selected['blocked'] = $selected['missing'] !== [] && ! $selected['ready'];
        $selected['current_step'] = collect($steps)->firstWhere('label', $selected['stage']);

        return $selected;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function secondaryActions(GrowthProgram $program): array
    {
        return [
            ['label' => 'Build Brief Blueprints', 'route' => route('app.growth-programs.build-brief-blueprints', $program), 'ability' => 'prepare'],
            ['label' => 'Convert Approved Blueprints to Briefs', 'route' => route('app.growth-programs.convert-approved-blueprints', $program), 'ability' => 'approve'],
            ['label' => 'Prepare Draft Requests', 'route' => route('app.growth-programs.prepare-draft-requests', $program), 'ability' => 'prepare'],
            ['label' => 'Create Approved Drafts', 'route' => route('app.growth-programs.generate-approved-draft-requests', $program), 'ability' => 'prepare'],
            ['label' => 'Run Draft Quality Checks', 'route' => route('app.growth-programs.review-generated-drafts', $program), 'ability' => 'prepare'],
            ['label' => 'Convert Approved Reviews to Content', 'route' => route('app.growth-programs.convert-approved-reviews-to-content', $program), 'ability' => 'approve'],
            ['label' => 'Run Publication Readiness', 'route' => route('app.growth-programs.publication-readiness', $program), 'ability' => 'prepare'],
            ['label' => 'Prepare Scheduled Publications', 'route' => route('app.growth-programs.publication-plans.schedule', $program), 'ability' => 'approve'],
        ];
    }

    /**
     * @param Collection<int,ProgrammaticPublicationPlanItem> $planItems
     * @param Collection<string,Collection<int,GrowthAsset>> $assetsByRole
     * @return array<int,array<string,mixed>>
     */
    private function health(array $metrics, Collection $planItems, Collection $assetsByRole): array
    {
        $conflicts = $planItems->filter(fn (ProgrammaticPublicationPlanItem $item): bool => data_get($item->metadata, 'conflict.reason') !== null);
        $missingDestination = $conflicts->contains(fn (ProgrammaticPublicationPlanItem $item): bool => data_get($item->metadata, 'conflict.reason') === 'missing_destination');
        $terminalConflict = $conflicts->contains(fn (ProgrammaticPublicationPlanItem $item): bool => data_get($item->metadata, 'conflict.reason') === 'existing_publication_terminal');
        $activePlanConflict = $conflicts->contains(fn (ProgrammaticPublicationPlanItem $item): bool => data_get($item->metadata, 'conflict.reason') === 'content_already_scheduled_in_active_plan');
        $blockedReviews = (int) ($metrics['blocked_draft_reviews_count'] ?? 0);
        $blockedReadiness = (int) ($metrics['blocked_publication_readiness_count'] ?? 0);

        $traceabilityComplete = $assetsByRole->flatten(1)->every(fn (GrowthAsset $asset): bool => $asset->assetable !== null)
            && $planItems->every(fn (ProgrammaticPublicationPlanItem $item): bool => $item->status !== ProgrammaticPublicationPlanItem::STATUS_SCHEDULED || $item->content_publication_id !== null);

        return [
            ['label' => 'Traceability', 'status' => $traceabilityComplete ? 'complete' : 'incomplete', 'detail' => $traceabilityComplete ? 'Every mapped asset has a source link.' : 'Some mapped assets need link repair.'],
            ['label' => 'Safety gates', 'status' => ($blockedReviews + $blockedReadiness + $conflicts->count()) > 0 ? 'blocked' : 'passed', 'detail' => ($blockedReviews + $blockedReadiness + $conflicts->count()) > 0 ? 'Resolve blocked checks or plan conflicts.' : 'No blocked checks or plan conflicts detected.'],
            ['label' => 'Destination status', 'status' => $missingDestination ? 'blocked' : 'ready', 'detail' => $missingDestination ? 'Choose a destination before preparing scheduled publications.' : 'No missing destination conflict detected.'],
            ['label' => 'Publication risk', 'status' => ((float) ($metrics['average_publication_readiness_score'] ?? 0)) > 0 ? 'tracked' : 'pending', 'detail' => 'Avg readiness '.number_format((float) ($metrics['average_publication_readiness_score'] ?? 0), 1).', avg risk '.number_format((float) ($metrics['average_risk_score'] ?? 0), 1).'.'],
            ['label' => 'Duplicate/conflict status', 'status' => ($terminalConflict || $activePlanConflict) ? 'blocked' : 'clear', 'detail' => $conflicts->count() > 0 ? $conflicts->count().' conflict(s) need attention.' : 'No active plan conflicts detected.'],
            ['label' => 'Cost and tokens', 'status' => 'estimated', 'detail' => number_format((int) ($metrics['estimated_generation_tokens'] ?? 0)).' tokens, €'.number_format((float) ($metrics['estimated_generation_cost'] ?? 0), 4).' estimated.'],
            ['label' => 'Reach and AI visibility', 'status' => 'estimated', 'detail' => number_format((float) ($metrics['estimated_reach'] ?? 0), 0).' reach, '.number_format((float) ($metrics['estimated_ai_visibility'] ?? 0), 1).' AI visibility.'],
        ];
    }

    /**
     * @param Collection<string,Collection<int,GrowthAsset>> $assetsByRole
     * @return Collection<int,ProgrammaticPublicationPlanItem>
     */
    private function planItems(Collection $assetsByRole): Collection
    {
        return $assetsByRole->get(GrowthAsset::ROLE_PUBLICATION_PLAN, collect())
            ->flatMap(function (GrowthAsset $asset): Collection {
                if (! $asset->assetable instanceof ProgrammaticPublicationPlan) {
                    return collect();
                }

                $asset->assetable->loadMissing('items.contentPublication');

                return $asset->assetable->items;
            })
            ->values();
    }

    /**
     * @return array<string,mixed>
     */
    private function step(string $label, int $count, string $nextAction, string $helper, ?string $blockedReason = null): array
    {
        return [
            'label' => $label,
            'count' => $count,
            'status' => $blockedReason ? 'blocked' : ($count > 0 ? 'complete' : 'pending'),
            'next_action' => $nextAction,
            'helper' => $helper,
            'blocked_reason' => $blockedReason,
        ];
    }

    private function blockedReviewReason(Collection $blockedReviews): ?string
    {
        $review = $blockedReviews->first()?->assetable;

        return $review instanceof ProgrammaticDraftReview
            ? (string) (data_get($review->blocking_issues, '0.message') ?: data_get($review->blocking_issues, '0') ?: 'Blocked quality checks need attention.')
            : null;
    }

    private function blockedReadinessReason(Collection $blockedReadiness): ?string
    {
        $readiness = $blockedReadiness->first()?->assetable;

        return $readiness instanceof ProgrammaticPublicationReadiness
            ? (string) (data_get($readiness->missing_requirements, '0.message') ?: data_get($readiness->missing_requirements, '0') ?: 'Blocked publication readiness needs attention.')
            : null;
    }

    private function conflictReason(Collection $conflicts): ?string
    {
        $reason = (string) data_get($conflicts->first()?->metadata, 'conflict.reason', '');

        return match ($reason) {
            'missing_destination' => 'Choose a destination before preparing scheduled publications.',
            'existing_publication_terminal' => 'A terminal publication already exists and will not be changed.',
            'content_already_scheduled_in_active_plan' => 'This content is already scheduled in another active plan.',
            default => $reason !== '' ? str($reason)->replace('_', ' ')->headline()->toString() : null,
        };
    }
}
