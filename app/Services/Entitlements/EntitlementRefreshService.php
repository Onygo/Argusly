<?php

namespace App\Services\Entitlements;

use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class EntitlementRefreshService
{
    public function refreshForSubscription(Subscription $subscription): void
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

        foreach ($workspaces as $workspace) {
            if ($workspace instanceof Workspace) {
                $this->refreshForWorkspace($workspace, $subscription);
            }
        }
    }

    public function refreshForWorkspace(Workspace $workspace, ?Subscription $subscription = null): void
    {
        $workspace->loadMissing('organization');

        if (! $subscription) {
            $subscription = Subscription::query()
                ->where(function ($query) use ($workspace) {
                    $query->where('workspace_id', $workspace->id)
                        ->orWhere('organization_id', $workspace->organization_id);
                })
                ->whereIn('status', ['active', 'trialing'])
                ->latest('updated_at')
                ->first();
        }

        if (! $subscription || ! $subscription->plan_id) {
            return;
        }

        $subscription->loadMissing('plan');
        $plan = $subscription->plan;

        if (! $plan) {
            return;
        }

        $limits = is_array($plan->limits) ? $plan->limits : [];

        $featureRows = PlanFeature::query()
            ->where('plan_id', $plan->id)
            ->get();

        $derived = [
            'included_credits' => ['type' => 'int', 'int' => (int) ($plan->included_credits_per_interval ?: $plan->included_credits)],
            'users_limit' => ['type' => 'int', 'int' => (int) ($plan->seat_limit ?: Arr::get($limits, 'users', 1))],
            'wp_sites_limit' => ['type' => 'int', 'int' => (int) Arr::get($limits, 'sites', 1)],
            'workspaces_limit' => ['type' => 'int', 'int' => (int) Arr::get($limits, 'workspaces', 1)],
            'topics_seed_keywords_limit' => ['type' => 'int', 'int' => (int) Arr::get($limits, 'topics_seed_keywords_limit', -1)],
            'articles_per_month_limit' => ['type' => 'int', 'int' => (int) Arr::get($limits, 'articles_per_month_limit', Arr::get($limits, 'included_drafts_per_month', -1))],
            'llm_tracking_queries_per_month_limit' => ['type' => 'int', 'int' => (int) Arr::get($limits, 'llm_tracking_queries_per_month_limit', -1)],
            'competitor_slots_limit' => ['type' => 'int', 'int' => (int) Arr::get($limits, 'competitor_slots_limit', -1)],
            'seo_audit_crawl_pages_per_month_limit' => ['type' => 'int', 'int' => (int) Arr::get($limits, 'seo_audit_crawl_pages_per_month_limit', -1)],
            'languages_limit' => ['type' => 'int', 'int' => (int) Arr::get($limits, 'languages_limit', -1)],
        ];

        foreach ($featureRows as $feature) {
            $derived[$feature->feature_key] = [
                'type' => (string) $feature->value_type,
                'bool' => $feature->value_bool,
                'int' => $feature->value_int,
                'string' => $feature->value_string,
                'json' => $feature->value_json,
            ];
        }

        WorkspaceEntitlement::query()
            ->where('workspace_id', $workspace->id)
            ->where('source', 'plan')
            ->whereNotIn('feature_key', array_keys($derived))
            ->delete();

        foreach ($derived as $key => $value) {
            $entitlement = WorkspaceEntitlement::query()->firstOrNew([
                'workspace_id' => $workspace->id,
                'feature_key' => $key,
            ]);

            if (! $entitlement->exists) {
                $entitlement->id = (string) Str::uuid();
            }

            if ((string) $entitlement->source === 'manual') {
                continue;
            }

            $entitlement->organization_id = $workspace->organization_id;
            $entitlement->subscription_id = $subscription->id;
            $entitlement->plan_id = $plan->id;
            $entitlement->value_type = (string) ($value['type'] ?? 'bool');
            $entitlement->value_bool = $value['bool'] ?? null;
            $entitlement->value_int = $value['int'] ?? null;
            $entitlement->value_string = $value['string'] ?? null;
            $entitlement->value_json = $value['json'] ?? null;
            $entitlement->source = 'plan';
            $entitlement->effective_at = $subscription->current_period_start;
            $entitlement->expires_at = $subscription->current_period_end;
            $entitlement->refreshed_at = now();
            $entitlement->meta = [
                'plan_key' => (string) $plan->key,
                'subscription_id' => (string) $subscription->id,
            ];
            $entitlement->save();
        }
    }
}
