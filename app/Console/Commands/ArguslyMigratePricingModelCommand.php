<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Services\Entitlements\EntitlementRefreshService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ArguslyMigratePricingModelCommand extends Command
{
    private const PLATFORM_KEY = 'argusly_platform';
    private const INCLUDED_CREDITS = 250;
    private const INCLUDED_SITES = 1;
    private const INCLUDED_USERS = 5;

    protected $signature = 'argusly:migrate-pricing-model {--org-id=}';

    protected $description = 'Migrate the existing pilot subscription to the simplified Argusly Platform pricing model.';

    public function handle(EntitlementRefreshService $entitlements): int
    {
        $organization = $this->resolveOrganization();
        if (! $organization) {
            $this->error('No pilot organization with an active subscription was found.');

            return self::FAILURE;
        }

        $result = DB::transaction(function () use ($organization, $entitlements): array {
            $platform = $this->upsertPlatformPlan();
            $this->markLegacyPlansInactive($platform);

            $subscription = $this->activeSubscription($organization);
            if (! $subscription) {
                throw new \RuntimeException('No active subscription found for organization.');
            }

            $subscription->loadMissing('plan');
            $previousPlan = $subscription->plan?->name ?: $subscription->plan?->key ?: 'Unknown';

            $existingSites = (int) $organization->clientSites()->count();
            $siteEntitlementLimit = max(self::INCLUDED_SITES, $existingSites);
            $extraSiteEntitlements = max(0, $existingSites - self::INCLUDED_SITES);
            $creditsPreserved = $this->availableCreditsForOrganization($organization);

            $subscription->forceFill([
                'plan_id' => $platform->id,
                'pending_plan_id' => null,
                'interval' => 'month',
                'price_cents' => 9900,
                'currency' => 'EUR',
                'included_credits_per_interval' => self::INCLUDED_CREDITS,
                'seat_limit' => self::INCLUDED_USERS,
                'meta' => array_merge((array) ($subscription->meta ?? []), [
                    'pricing_model_migration' => [
                        'previous_plan_id' => (string) ($subscription->getOriginal('plan_id') ?? ''),
                        'previous_plan' => $previousPlan,
                        'new_plan_key' => self::PLATFORM_KEY,
                        'included_sites' => self::INCLUDED_SITES,
                        'existing_sites' => $existingSites,
                        'grandfathered_extra_sites' => $extraSiteEntitlements,
                        'credits_preserved' => $creditsPreserved,
                        'migrated_at' => now()->toIso8601String(),
                    ],
                ]),
            ])->save();

            $organization->forceFill([
                'active_subscription_id' => $subscription->id,
                'access_tier' => Organization::ACCESS_TIER_PAID,
                'converted_to_paid_at' => $organization->converted_to_paid_at ?: now(),
            ])->save();

            $organization->loadMissing('workspaces');
            foreach ($organization->workspaces as $workspace) {
                if ($workspace instanceof Workspace) {
                    $entitlements->refreshForWorkspace($workspace, $subscription->fresh(['plan']));
                    $this->upsertGrandfatheredSiteEntitlement($workspace, $subscription, $platform, $siteEntitlementLimit, $existingSites);
                }
            }

            return [
                'organization' => $organization->name,
                'previous_plan' => $previousPlan,
                'new_plan' => $platform->name,
                'included_sites' => self::INCLUDED_SITES,
                'existing_sites' => $existingSites,
                'extra_site_entitlements_created' => $extraSiteEntitlements,
                'credits_preserved' => $creditsPreserved,
            ];
        });

        $this->line('Organization: ' . $result['organization']);
        $this->line('Previous Plan: ' . $result['previous_plan']);
        $this->line('New Plan: ' . $result['new_plan']);
        $this->line('Included Sites: ' . $result['included_sites']);
        $this->line('Existing Sites: ' . $result['existing_sites']);
        $this->line('Extra Site Entitlements Created: ' . $result['extra_site_entitlements_created']);
        $this->line('Credits Preserved: ' . $result['credits_preserved']);
        $this->info('Status: Success');

        return self::SUCCESS;
    }

    private function resolveOrganization(): ?Organization
    {
        $orgId = trim((string) $this->option('org-id'));
        if ($orgId !== '') {
            return Organization::query()->find($orgId);
        }

        return Organization::query()
            ->whereHas('subscriptions', fn ($query) => $query->whereIn('status', ['active', 'trialing']))
            ->withCount(['subscriptions', 'workspaces', 'clientSites', 'users', 'invoices'])
            ->orderByDesc('updated_at')
            ->first();
    }

    private function activeSubscription(Organization $organization): ?Subscription
    {
        if ($organization->active_subscription_id) {
            $subscription = Subscription::query()
                ->whereKey($organization->active_subscription_id)
                ->whereIn('status', ['active', 'trialing'])
                ->first();

            if ($subscription) {
                return $subscription;
            }
        }

        return Subscription::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['active', 'trialing'])
            ->orderByDesc('updated_at')
            ->first();
    }

    private function upsertPlatformPlan(): Plan
    {
        $plan = Plan::query()
            ->where(function ($query): void {
                $query->where('key', self::PLATFORM_KEY)
                    ->orWhere('slug', self::PLATFORM_KEY)
                    ->orWhere('internal_code', self::PLATFORM_KEY);
            })
            ->first() ?? new Plan(['id' => (string) Str::uuid()]);

        $plan->forceFill([
            'key' => self::PLATFORM_KEY,
            'slug' => self::PLATFORM_KEY,
            'internal_code' => self::PLATFORM_KEY,
            'name' => 'Argusly Platform',
            'description_short' => 'One platform subscription with 250 monthly credits, one included site, and five users.',
            'interval' => 'month',
            'price_monthly_cents' => 9900,
            'price_yearly_cents' => 99000,
            'monthly_price_cents' => 9900,
            'price_cents' => 9900,
            'currency' => 'EUR',
            'vat_included' => true,
            'included_credits' => self::INCLUDED_CREDITS,
            'included_credits_per_interval' => self::INCLUDED_CREDITS,
            'credit_rollover_policy' => 'limited',
            'credit_expiry_days' => 90,
            'credit_rollover_monthly_cycles' => 3,
            'workspace_limit' => 1,
            'user_limit' => self::INCLUDED_USERS,
            'seat_limit' => self::INCLUDED_USERS,
            'limits' => [
                'workspaces' => 1,
                'sites' => self::INCLUDED_SITES,
                'users' => self::INCLUDED_USERS,
                'extra_site_price_cents' => 2900,
                'languages_limit' => -1,
            ],
            'has_required_onboarding' => false,
            'onboarding_fee_cents' => 0,
            'onboarding_fee_currency' => 'EUR',
            'onboarding_is_visible_public' => false,
            'is_active' => true,
            'is_public' => true,
            'billing_type' => 'fixed',
            'billing_provider' => config('billing.default_provider', 'mollie'),
            'billing_provider_plan_key' => self::PLATFORM_KEY,
            'is_featured' => true,
            'is_popular' => true,
            'sort_order' => 1,
            'badge' => null,
            'cta_label' => 'Request a pilot',
            'cta_href' => null,
        ])->save();

        return $plan->fresh();
    }

    private function markLegacyPlansInactive(Plan $platform): void
    {
        Plan::query()
            ->whereKeyNot($platform->id)
            ->where(function ($query): void {
                $query->whereIn('key', ['starter', 'creator', 'growth', 'scale'])
                    ->orWhereIn('slug', ['starter', 'creator', 'growth', 'scale'])
                    ->orWhereIn('internal_code', ['starter', 'creator', 'growth', 'scale']);
            })
            ->update([
                'is_active' => false,
                'is_public' => false,
                'badge' => 'Legacy',
                'updated_at' => now(),
            ]);
    }

    private function upsertGrandfatheredSiteEntitlement(
        Workspace $workspace,
        Subscription $subscription,
        Plan $platform,
        int $siteEntitlementLimit,
        int $existingSites,
    ): void {
        $entitlement = WorkspaceEntitlement::query()->firstOrNew([
            'workspace_id' => $workspace->id,
            'feature_key' => 'wp_sites_limit',
        ]);

        if (! $entitlement->exists) {
            $entitlement->id = (string) Str::uuid();
        }

        $entitlement->forceFill([
            'organization_id' => $workspace->organization_id,
            'subscription_id' => $subscription->id,
            'plan_id' => $platform->id,
            'value_type' => 'int',
            'value_bool' => null,
            'value_int' => $siteEntitlementLimit,
            'value_string' => null,
            'value_json' => null,
            'source' => $existingSites > self::INCLUDED_SITES ? 'migration' : 'plan',
            'effective_at' => $subscription->current_period_start ?: now(),
            'expires_at' => null,
            'refreshed_at' => now(),
            'meta' => [
                'migration' => self::PLATFORM_KEY,
                'included_sites' => self::INCLUDED_SITES,
                'existing_sites' => $existingSites,
                'grandfathered_extra_sites' => max(0, $existingSites - self::INCLUDED_SITES),
            ],
        ])->save();
    }

    private function availableCreditsForOrganization(Organization $organization): int
    {
        $workspaceCredits = null;

        if (DB::getSchemaBuilder()->hasTable('workspace_credit_wallets')) {
            $workspaceCredits = (int) DB::table('workspace_credit_wallets')
                ->where('organization_id', $organization->id)
                ->selectRaw('COALESCE(SUM(balance_cached - reserved_cached), 0) as total')
                ->value('total');
        }

        if (DB::getSchemaBuilder()->hasTable('site_credit_allocations')) {
            $siteCredits = (int) DB::table('site_credit_allocations as sca')
                ->join('workspaces as w', 'w.id', '=', 'sca.workspace_id')
                ->where('w.organization_id', $organization->id)
                ->selectRaw('COALESCE(SUM(sca.allocated_credits - sca.reserved_cached), 0) as total')
                ->value('total');

            return max($workspaceCredits ?? 0, $siteCredits);
        }

        if (DB::getSchemaBuilder()->hasTable('credit_wallets')) {
            $legacyCredits = (int) DB::table('credit_wallets as cw')
                ->join('client_sites as cs', 'cs.id', '=', 'cw.client_site_id')
                ->join('workspaces as w', 'w.id', '=', 'cs.workspace_id')
                ->where('w.organization_id', $organization->id)
                ->selectRaw('COALESCE(SUM(cw.balance_cached - cw.reserved_cached), 0) as total')
                ->value('total');

            return max($workspaceCredits ?? 0, $legacyCredits);
        }

        return $workspaceCredits ?? 0;
    }
}
