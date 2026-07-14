<?php

namespace App\Services\BrandGrowthPlanning;

use App\Models\BrandGrowthPlan;
use App\Models\Brief;
use App\Models\Draft;
use App\Models\User;
use App\Services\OpportunityIntelligence\BriefDraftService;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

class BrandGrowthDraftCreationService
{
    public function __construct(
        private readonly BrandGrowthBriefCreationService $brandGrowthBriefs,
        private readonly BriefDraftService $drafts,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function createForBrandGrowthBriefs(BrandGrowthPlan $plan, User $user): array
    {
        $briefs = $this->briefsForPlan($plan);

        $created = 0;
        $existing = 0;
        $ineligible = 0;
        $skipped = 0;
        $draftIds = [];

        foreach ($briefs as $brief) {
            if ((string) $brief->source !== 'opportunity_execution_plan' || ! in_array((string) $brief->status, ['draft', 'approved'], true)) {
                $ineligible++;

                continue;
            }

            $draftIdBefore = (string) data_get($brief->client_refs, 'draft_id', '');

            try {
                $draft = $this->drafts->createDraft($brief, $user);
            } catch (AuthorizationException | RuntimeException) {
                $skipped++;

                continue;
            }

            if ($draft instanceof Draft) {
                $draftIds[] = (string) $draft->id;
            }

            $draftIdBefore !== '' && $draftIdBefore === (string) $draft->id ? $existing++ : $created++;
        }

        return [
            'briefs' => $briefs->count(),
            'drafts_created' => $created,
            'drafts_existing' => $existing,
            'briefs_needing_approval' => $ineligible,
            'skipped_briefs' => $skipped,
            'draft_ids' => array_values(array_unique($draftIds)),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Brief>
     */
    public function briefsForPlan(BrandGrowthPlan $plan)
    {
        $briefIds = $this->brandGrowthBriefs
            ->executionPlansForPlan($plan)
            ->map(fn ($executionPlan): string => (string) data_get($executionPlan->metadata, 'brief_id', ''))
            ->filter()
            ->unique()
            ->values();

        if ($briefIds->isEmpty()) {
            return collect();
        }

        return Brief::query()
            ->whereIn('id', $briefIds)
            ->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $plan->workspace_id))
            ->get()
            ->values();
    }
}
