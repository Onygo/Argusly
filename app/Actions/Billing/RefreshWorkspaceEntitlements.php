<?php

namespace App\Actions\Billing;

use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Entitlements\EntitlementRefreshService;

class RefreshWorkspaceEntitlements
{
    public function __construct(
        private readonly EntitlementRefreshService $entitlementRefresh,
        private readonly PlanEntitlementService $planEntitlements,
    ) {
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    public function forSubscription(Subscription $subscription): array
    {
        $subscription->loadMissing('plan', 'workspace', 'organization.workspaces');

        $workspaces = collect();

        if ($subscription->workspace) {
            $workspaces->push($subscription->workspace);
        }

        if ($subscription->organization) {
            $workspaces = $workspaces
                ->merge($subscription->organization->workspaces)
                ->filter(fn (mixed $workspace): bool => $workspace instanceof Workspace)
                ->unique(fn (Workspace $workspace): string => (string) $workspace->id)
                ->values();
        }

        $snapshots = [];

        foreach ($workspaces as $workspace) {
            /** @var Workspace $workspace */
            $snapshots[(string) $workspace->id] = $this->forWorkspace($workspace, $subscription);
        }

        return $snapshots;
    }

    /**
     * @return array<string,mixed>
     */
    public function forWorkspace(Workspace $workspace, ?Subscription $subscription = null): array
    {
        $workspace = $workspace->fresh(['organization']) ?? $workspace;

        $this->entitlementRefresh->refreshForWorkspace($workspace, $subscription);
        $this->planEntitlements->forgetWorkspace($workspace);

        return $this->planEntitlements->getWorkspaceEntitlements($workspace->fresh(['organization']) ?? $workspace);
    }
}
