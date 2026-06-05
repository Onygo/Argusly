<?php

namespace App\Services\Entitlements;

use App\Models\Organization;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Services\OrganizationAccessService;
use App\Services\SubscriptionService;
use Illuminate\Auth\Access\AuthorizationException;

class FeatureGate
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly OrganizationAccessService $access,
    ) {
    }

    public function can(mixed $workspace, string $featureKey): bool
    {
        $value = $this->value($workspace, $featureKey, null);

        // Compatibility mode: until plan features/entitlements are seeded,
        // do not block existing flows.
        if ($value === null) {
            return true;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        if (is_string($value)) {
            return trim($value) !== '' && ! in_array(strtolower(trim($value)), ['0', 'false', 'off', 'no'], true);
        }

        return (bool) $value;
    }

    public function value(mixed $workspace, string $featureKey, mixed $default = null): mixed
    {
        $resolved = $this->resolveWorkspace($workspace);
        if (! $resolved) {
            return $default;
        }

        $subscription = $this->resolveWorkspaceSubscription($resolved);

        $entitlement = WorkspaceEntitlement::query()
            ->where('workspace_id', $resolved->id)
            ->where('feature_key', $featureKey)
            ->where(function ($query) {
                $query->whereNull('effective_at')->orWhere('effective_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByRaw("CASE WHEN source = 'manual' THEN 0 WHEN source = 'plan' THEN 1 ELSE 2 END")
            ->orderByDesc('refreshed_at')
            ->orderByDesc('updated_at')
            ->first();

        if ($entitlement) {
            if ((string) $entitlement->source === 'plan'
                && $subscription
                && $entitlement->plan_id
                && (string) $entitlement->plan_id !== (string) $subscription->plan_id) {
                $entitlement = null;
            }
        }

        if ($entitlement) {
            return $entitlement->typedValue();
        }

        $earlyBirdValue = $this->access->earlyBirdValue($resolved, $featureKey);
        if ($earlyBirdValue !== null) {
            return $earlyBirdValue;
        }

        if (! $subscription) {
            return $default;
        }

        $planFeature = PlanFeature::query()
            ->where('plan_id', $subscription->plan_id)
            ->where('feature_key', $featureKey)
            ->first();

        if (! $planFeature) {
            return $default;
        }

        return $planFeature->typedValue();
    }

    /**
     * @throws AuthorizationException
     */
    public function assert(mixed $workspace, string $featureKey): void
    {
        if (! $this->can($workspace, $featureKey)) {
            throw new AuthorizationException(sprintf('Feature "%s" is not available for this workspace.', $featureKey));
        }
    }

    private function resolveWorkspace(mixed $workspace): ?Workspace
    {
        if ($workspace instanceof Workspace) {
            return $workspace;
        }

        if ($workspace instanceof Organization) {
            return Workspace::query()
                ->where('organization_id', $workspace->id)
                ->orderBy('created_at')
                ->first();
        }

        if (is_string($workspace) && $workspace !== '') {
            return Workspace::query()->find($workspace);
        }

        return null;
    }

    private function resolveWorkspaceSubscription(Workspace $workspace): ?Subscription
    {
        $byWorkspace = Subscription::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', ['active', 'trialing'])
            ->orderByDesc('updated_at')
            ->first();

        if ($byWorkspace) {
            return $byWorkspace;
        }

        if (! $workspace->organization_id) {
            return null;
        }

        $organization = $workspace->organization;
        if (! $organization) {
            return null;
        }

        return $this->subscriptions->getActiveForOrganization($organization);
    }
}
