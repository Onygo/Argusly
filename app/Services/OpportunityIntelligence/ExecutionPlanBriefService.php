<?php

namespace App\Services\OpportunityIntelligence;

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\OpportunityExecutionPlan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExecutionPlanBriefService
{
    public function __construct(private readonly ExecutionPlanToBriefMapper $mapper)
    {
    }

    public function createBrief(OpportunityExecutionPlan $plan, User $user): Brief
    {
        $plan->loadMissing(['workspace', 'clientSite', 'opportunity']);

        $this->authorize($plan, $user);

        return DB::transaction(function () use ($plan, $user): Brief {
            $plan = OpportunityExecutionPlan::query()
                ->whereKey($plan->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existingBrief = $this->existingBrief($plan);
            if ($existingBrief) {
                return $existingBrief;
            }

            if (! in_array((string) $plan->status, [OpportunityExecutionPlan::STATUS_APPROVED, OpportunityExecutionPlan::STATUS_PLANNED], true)) {
                throw new RuntimeException('Only approved or planned execution plans can create a content brief.');
            }

            $site = $this->resolveClientSite($plan);
            $brief = Brief::query()->create($this->mapper->map($plan, $user, (string) $site->id));

            $metadata = $plan->metadata ?? [];
            $metadata['brief_id'] = (string) $brief->id;
            $metadata['brief_created_by'] = (string) $user->id;
            $metadata['brief_created_at'] = now()->toIso8601String();

            $plan->forceFill(['metadata' => $metadata])->save();

            return $brief;
        });
    }

    private function authorize(OpportunityExecutionPlan $plan, User $user): void
    {
        if ((int) ($plan->workspace?->organization_id ?? 0) !== (int) $user->organization_id) {
            throw new AuthorizationException('Execution plan is not available for this workspace.');
        }

        if (! $user->is_admin && ! in_array((string) $user->role, ['owner', 'admin', 'editor'], true)) {
            throw new AuthorizationException('You are not allowed to create a brief from this execution plan.');
        }
    }

    private function existingBrief(OpportunityExecutionPlan $plan): ?Brief
    {
        $briefId = (string) data_get($plan->metadata, 'brief_id', '');
        if ($briefId === '') {
            return null;
        }

        return Brief::query()
            ->whereKey($briefId)
            ->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $plan->workspace_id))
            ->first();
    }

    private function resolveClientSite(OpportunityExecutionPlan $plan): ClientSite
    {
        $site = ClientSite::query()
            ->where('workspace_id', $plan->workspace_id)
            ->when($plan->client_site_id ?: $plan->opportunity?->client_site_id, fn ($query, $id) => $query->where('id', $id))
            ->orderByDesc('is_active')
            ->orderBy('created_at')
            ->first();

        if (! $site) {
            throw new RuntimeException('A connected client site is required before a content brief can be created.');
        }

        return $site;
    }
}
