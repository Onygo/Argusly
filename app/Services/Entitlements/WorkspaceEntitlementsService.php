<?php

namespace App\Services\Entitlements;

use App\Models\ClientSite;
use App\Models\Workspace;
use App\Models\WorkspaceUsage;
use App\Services\OrganizationAccessService;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WorkspaceEntitlementsService
{
    public function __construct(
        private readonly FeatureGate $gate,
        private readonly SubscriptionService $subscriptions,
        private readonly OrganizationAccessService $access,
    ) {
    }

    public function limits(Workspace $workspace): array
    {
        $workspace->loadMissing('organization');

        $subscription = $workspace->organization
            ? $this->subscriptions->getActiveForOrganization($workspace->organization)
            : null;

        $hasSubscription = $subscription !== null;
        $commercialAccess = $workspace->organization
            ? $this->access->hasPlatformAccess($workspace->organization)
            : $hasSubscription;

        return [
            'has_subscription' => $hasSubscription,
            'has_access' => $commercialAccess,
            'access_tier' => $workspace->organization ? $this->access->effectiveState($workspace->organization) : null,
            'max_sites' => $this->intValue($workspace, 'wp_sites_limit', 1),
            'briefs_per_month' => $this->intValue($workspace, 'briefs_per_month', -1),
            'drafts_per_month' => $this->intValue($workspace, 'drafts_per_month', -1),
            'can_generate_briefs' => $this->boolValue($workspace, 'can_generate_briefs', true),
            'can_generate_drafts' => $this->boolValue($workspace, 'can_generate_drafts', true),
            'can_push_to_wp' => $this->boolValue($workspace, 'can_push_to_wp', true),
        ];
    }

    public function usage(Workspace $workspace, ?string $yearMonth = null): array
    {
        $yearMonth = $yearMonth ?: now()->format('Y-m');

        $row = WorkspaceUsage::query()
            ->where('workspace_id', $workspace->id)
            ->where('year_month', $yearMonth)
            ->whereNull('site_id')
            ->first();

        return [
            'year_month' => $yearMonth,
            'briefs_count' => (int) ($row?->briefs_count ?? 0),
            'drafts_count' => (int) ($row?->drafts_count ?? 0),
        ];
    }

    public function assertCanAddSite(Workspace $workspace): void
    {
        $sites = $this->siteUsage($workspace);
        $max = (int) $sites['max_sites'];

        if ($max < 0) {
            return;
        }

        if ((int) $sites['sites_used'] >= $max) {
            throw new RuntimeException(sprintf('Site limit reached (%d). Upgrade your package to add more sites.', $max));
        }
    }

    public function siteUsage(Workspace $workspace): array
    {
        $limits = $this->limits($workspace);
        $max = (int) ($limits['max_sites'] ?? 1);

        $sitesQuery = ClientSite::query();
        if ($workspace->organization_id) {
            $sitesQuery->whereHas('workspace', function ($query) use ($workspace): void {
                $query->where('organization_id', $workspace->organization_id);
            });
        } else {
            $sitesQuery->where('workspace_id', $workspace->id);
        }

        $used = (int) $sitesQuery->count();

        return [
            'max_sites' => $max,
            'sites_used' => $used,
            'sites_remaining' => $max < 0 ? -1 : max(0, $max - $used),
            'site_limit_reached' => $max >= 0 && $used >= $max,
        ];
    }

    public function consumeBriefQuota(Workspace $workspace): void
    {
        $limits = $this->limits($workspace);

        if (! $limits['can_generate_briefs']) {
            throw new RuntimeException('Your package does not allow brief generation.');
        }

        $this->incrementAndAssert($workspace, 'briefs_count', (int) $limits['briefs_per_month']);
    }

    public function consumeDraftQuota(Workspace $workspace): void
    {
        $limits = $this->limits($workspace);

        if (! $limits['can_generate_drafts']) {
            throw new RuntimeException('Your package does not allow draft generation.');
        }

        $this->incrementAndAssert($workspace, 'drafts_count', (int) $limits['drafts_per_month']);
    }

    public function assertCanPushToWp(Workspace $workspace): void
    {
        $limits = $this->limits($workspace);

        if (! $limits['can_push_to_wp']) {
            throw new RuntimeException('Your package does not allow pushing content to WordPress.');
        }
    }

    private function incrementAndAssert(Workspace $workspace, string $column, int $monthlyLimit): void
    {
        if ($monthlyLimit < 0) {
            return;
        }

        $yearMonth = now()->format('Y-m');

        DB::transaction(function () use ($workspace, $column, $monthlyLimit, $yearMonth): void {
            $row = WorkspaceUsage::query()
                ->where('workspace_id', $workspace->id)
                ->where('year_month', $yearMonth)
                ->whereNull('site_id')
                ->lockForUpdate()
                ->first();

            if (! $row) {
                $row = WorkspaceUsage::query()->create([
                    'workspace_id' => $workspace->id,
                    'site_id' => null,
                    'year_month' => $yearMonth,
                    'period_ym' => str_replace('-', '', $yearMonth),
                    'briefs_count' => 0,
                    'drafts_count' => 0,
                    'articles_generated' => 0,
                    'llm_queries_run' => 0,
                    'audit_pages_crawled' => 0,
                ]);
            }

            $current = (int) $row->{$column};
            if ($current >= $monthlyLimit) {
                throw new RuntimeException(sprintf('Monthly quota exceeded for %s (%d).', $column, $monthlyLimit));
            }

            $row->{$column} = $current + 1;
            $row->save();
        });
    }

    private function boolValue(Workspace $workspace, string $key, bool $default): bool
    {
        $value = $this->gate->value($workspace, $key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return (bool) $value;
    }

    private function intValue(Workspace $workspace, string $key, int $default): int
    {
        $value = $this->gate->value($workspace, $key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
