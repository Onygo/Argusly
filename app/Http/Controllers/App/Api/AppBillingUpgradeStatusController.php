<?php

namespace App\Http\Controllers\App\Api;

use App\Enums\Billing\SubscriptionPlanChangeStatus;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\Billing\PlanEntitlementService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class AppBillingUpgradeStatusController extends Controller
{
    public function __invoke(
        Request $request,
        Workspace $workspace,
        SubscriptionService $subscriptions,
        PlanEntitlementService $planEntitlements,
    ): JsonResponse {
        Gate::authorize('manage-organization');

        if ((int) $workspace->organization_id !== (int) $request->user()->organization_id) {
            abort(404);
        }

        $organization = $request->user()->organization;
        $subscription = $organization ? $subscriptions->getCurrentForOrganization($organization) : null;

        $latestChange = $subscription
            ? $subscription->planChanges()
                ->with(['fromPlan:id,key,slug,name', 'toPlan:id,key,slug,name', 'paymentIntent:id,billable_type,billable_id,status,updated_at'])
                ->latest('updated_at')
                ->latest('created_at')
                ->first()
            : null;

        $status = $latestChange?->status;
        if ($status !== null && ! $status instanceof SubscriptionPlanChangeStatus) {
            Log::warning('billing.upgrade_status.invalid_status_value', [
                'workspace_id' => (string) $workspace->id,
                'subscription_plan_change_id' => (string) ($latestChange?->id ?? ''),
                'status' => (string) $latestChange?->getRawOriginal('status'),
            ]);

            $status = SubscriptionPlanChangeStatus::tryFrom((string) $latestChange?->getRawOriginal('status'));
        }

        $isPending = $status?->isPending() ?? false;
        $isFinal = $status ? $status->isFinal() : true;
        $shouldPoll = $isPending;

        $entitlements = $planEntitlements->getWorkspaceEntitlements($workspace);

        $currentPlan = $this->planKey($subscription?->plan?->key, $subscription?->plan?->slug);
        $targetPlan = $this->planKey($latestChange?->toPlan?->key, $latestChange?->toPlan?->slug) ?? $currentPlan;
        $effectivePlan = (string) data_get($entitlements, 'plan_key', $currentPlan ?? '');
        $paymentStatus = $latestChange?->paymentIntent?->status;

        if ($status === SubscriptionPlanChangeStatus::APPLIED
            && $targetPlan
            && $effectivePlan !== ''
            && $effectivePlan !== $targetPlan) {
            Log::warning('billing.upgrade_status.entitlement_mismatch', [
                'workspace_id' => (string) $workspace->id,
                'subscription_id' => (string) ($subscription?->id ?? ''),
                'subscription_plan_change_id' => (string) ($latestChange?->id ?? ''),
                'target_plan' => $targetPlan,
                'effective_plan' => $effectivePlan,
            ]);
        }

        return response()->json([
            'workspace_id' => (string) $workspace->id,
            'subscription_id' => $subscription ? (string) $subscription->id : null,
            'change_id' => $latestChange ? (string) $latestChange->id : null,
            'state' => $status?->value ?? 'no_change',
            'current_plan' => $currentPlan,
            'target_plan' => $targetPlan,
            'status' => $status?->value,
            'payment_status' => $paymentStatus,
            'is_pending' => $isPending,
            'is_final' => $isFinal,
            'should_poll' => $shouldPoll,
            'effective_plan' => $effectivePlan !== '' ? $effectivePlan : null,
            'entitlements' => [
                'compare_max_models' => (int) data_get($entitlements, 'compare_max_models', 1),
                'hybrid_drafts_enabled' => (bool) data_get($entitlements, 'hybrid_drafts_enabled', false),
                'monthly_credits' => (int) data_get($entitlements, 'monthly_credits', 0),
            ],
            'message' => $this->messageForStatus($status, $latestChange?->toPlan?->name),
            'changed_at' => $latestChange?->updated_at?->toIso8601String(),
            'updated_at' => ($latestChange?->updated_at ?? $subscription?->updated_at ?? now())?->toIso8601String(),
        ]);
    }

    private function messageForStatus(
        ?SubscriptionPlanChangeStatus $status,
        ?string $targetPlanName,
    ): string {
        $planLabel = trim((string) $targetPlanName);
        $planLabel = $planLabel !== '' ? $planLabel : 'The selected plan';

        return match ($status) {
            null => 'No upgrade is pending. Current plan entitlements are active.',
            SubscriptionPlanChangeStatus::PENDING => 'Upgrade requested. Preparing checkout confirmation.',
            SubscriptionPlanChangeStatus::PENDING_PAYMENT => 'Payment pending. Your new plan will unlock after confirmation.',
            SubscriptionPlanChangeStatus::APPLIED => sprintf('%s is active.', $planLabel),
            SubscriptionPlanChangeStatus::FAILED => 'Upgrade failed. Your current plan remains active.',
            SubscriptionPlanChangeStatus::BLOCKED => 'Upgrade is blocked. Please contact support.',
        };
    }

    private function planKey(?string $key, ?string $slug): ?string
    {
        $resolved = trim((string) ($key ?: $slug ?: ''));

        return $resolved !== '' ? $resolved : null;
    }
}
