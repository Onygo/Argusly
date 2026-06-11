<?php

namespace App\Services\OpportunityIntelligence;

use App\Models\Brief;
use App\Models\Draft;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BriefDraftService
{
    public function __construct(private readonly BriefToDraftMapper $mapper)
    {
    }

    public function createDraft(Brief $brief, User $user): Draft
    {
        $brief->loadMissing(['clientSite.workspace']);
        $this->authorize($brief, $user);

        return DB::transaction(function () use ($brief, $user): Draft {
            $brief = Brief::query()
                ->whereKey($brief->id)
                ->lockForUpdate()
                ->firstOrFail();

            $brief->loadMissing(['clientSite.workspace']);

            $existingDraft = $this->existingDraft($brief);
            if ($existingDraft) {
                return $existingDraft;
            }

            $this->assertEligible($brief);

            $draft = Draft::query()->create($this->mapper->map($brief));

            $refs = is_array($brief->client_refs) ? $brief->client_refs : [];
            $refs['draft_id'] = (string) $draft->id;
            $refs['draft_created_by'] = (string) $user->id;
            $refs['draft_created_at'] = now()->toIso8601String();

            $brief->forceFill(['client_refs' => $refs])->save();

            return $draft;
        });
    }

    private function authorize(Brief $brief, User $user): void
    {
        if ((int) ($brief->clientSite?->workspace?->organization_id ?? 0) !== (int) $user->organization_id) {
            throw new AuthorizationException('Brief is not available for this workspace.');
        }

        if (! $user->is_admin && ! in_array((string) $user->role, ['owner', 'admin', 'editor'], true)) {
            throw new AuthorizationException('You are not allowed to create a draft from this brief.');
        }
    }

    private function assertEligible(Brief $brief): void
    {
        if ((string) $brief->source !== 'opportunity_execution_plan') {
            throw new RuntimeException('Only briefs created from an execution plan can use this draft flow.');
        }

        if (! in_array((string) $brief->status, ['draft', 'approved'], true)) {
            throw new RuntimeException('Only draft or approved briefs can create a first draft.');
        }
    }

    private function existingDraft(Brief $brief): ?Draft
    {
        $draftId = (string) data_get($brief->client_refs, 'draft_id', '');
        if ($draftId !== '') {
            $draft = Draft::query()
                ->whereKey($draftId)
                ->where('brief_id', $brief->id)
                ->first();

            if ($draft) {
                return $draft;
            }
        }

        $executionPlanId = (string) (data_get($brief->client_refs, 'execution_plan_id') ?: data_get($brief->client_refs, 'opportunity_execution_plan_id'));
        if ($executionPlanId === '') {
            return null;
        }

        return Draft::query()
            ->where('brief_id', $brief->id)
            ->get()
            ->first(function (Draft $draft) use ($executionPlanId): bool {
                $source = (string) data_get($draft->meta, 'source_context.source');
                $draftExecutionPlanId = (string) (data_get($draft->meta, 'source_context.execution_plan_id') ?: data_get($draft->meta, 'source_context.opportunity_execution_plan_id'));

                return $source === 'opportunity_execution_plan'
                    && $draftExecutionPlanId === $executionPlanId;
            });
    }
}
