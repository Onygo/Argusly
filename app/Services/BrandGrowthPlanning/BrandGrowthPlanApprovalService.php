<?php

namespace App\Services\BrandGrowthPlanning;

use App\Enums\BrandGrowthPlanStatus;
use App\Models\BrandGrowthPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BrandGrowthPlanApprovalService
{
    /**
     * @return array<string, int|null>
     */
    public function approve(BrandGrowthPlan $plan, User $user): array
    {
        return DB::transaction(function () use ($plan, $user): array {
            $plan = BrandGrowthPlan::query()
                ->whereKey($plan->id)
                ->lockForUpdate()
                ->firstOrFail();

            $supersededApprovedPlans = BrandGrowthPlan::query()
                ->where('workspace_id', $plan->workspace_id)
                ->whereKeyNot($plan->id)
                ->where('status', BrandGrowthPlanStatus::APPROVED->value)
                ->lockForUpdate()
                ->get();

            BrandGrowthPlan::query()
                ->whereKey($supersededApprovedPlans->pluck('id')->all())
                ->update([
                    'status' => BrandGrowthPlanStatus::SUPERSEDED->value,
                    'reviewed_by' => $user->id,
                    'reviewed_at' => now(),
                ]);

            $plan->forceFill([
                'status' => BrandGrowthPlanStatus::APPROVED->value,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'approved_by' => $user->id,
                'approved_at' => now(),
            ])->save();

            return [
                'superseded_approved_plans' => $supersededApprovedPlans->count(),
                'previous_baseline_version' => $supersededApprovedPlans->sortByDesc('version')->first()?->version,
            ];
        });
    }
}
