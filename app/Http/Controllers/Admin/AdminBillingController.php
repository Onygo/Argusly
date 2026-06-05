<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientSite;
use App\Models\CreditPackPurchase;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PaymentIntent;
use App\Models\SiteCreditAllocation;
use App\Models\Subscription;
use App\Models\SubscriptionPlanChange;
use App\Models\Invoice;
use App\Models\WorkspaceCreditTransaction;
use App\Services\Billing\BillingAuditService;
use App\Services\CreditWalletService;
use App\Services\MarketingPricingService;
use App\Services\AuditLogService;
use App\Services\BillingSettingsService;
use App\Services\Credits\WorkspaceCreditLedgerService;
use App\Services\Entitlements\EntitlementRefreshService;
use App\Services\OrganizationAccessService;
use App\Services\PlanQuotaService;
use App\Services\SubscriptionLifecycleService;
use App\Services\SiteSettingsService;
use App\Services\SubscriptionMonthlyCreditRecoveryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminBillingController extends Controller
{
    private const LEDGER_TYPES = [
        'reservation',
        'release',
        'usage',
        'allowance',
        'adjustment',
        'pack_purchase',
    ];

    private const PAYMENT_STATUSES = [
        'open',
        'paid',
        'failed',
        'canceled',
        'expired',
        'refunded',
        'pending',
    ];

    private const SUBSCRIPTION_STATUSES = [
        'all',
        'active',
        'pending_mandate',
        'trialing',
        'past_due',
        'suspended',
        'canceled',
    ];

    /** @var array<string, string> */
    private const QUOTA_FEATURES = [
        'topics_seed_keywords_limit' => 'Topics / seed keywords',
        'llm_tracking_queries_per_month_limit' => 'LLM tracking queries / month',
        'competitor_slots_limit' => 'Competitor slots',
        'seo_audit_crawl_pages_per_month_limit' => 'SEO audit crawl pages / month',
        'languages_limit' => 'Languages',
    ];

    public function index(BillingSettingsService $settings): View
    {
        $organizations = Organization::query()
            ->with('workspaces.clientSites')
            ->orderBy('name')
            ->get();
        $plans = Plan::query()
            ->with(['features' => fn ($query) => $query->orderBy('sort_order')->orderBy('label')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $rows = $organizations->map(function (Organization $organization): array {
            $siteIds = $organization->clientSites()->pluck('client_sites.id')->all();
            $workspaceIds = $organization->workspaces()->pluck('workspaces.id')->all();
            $activeSubscription = Subscription::query()
                ->with('plan')
                ->where('organization_id', $organization->id)
                ->whereIn('status', ['active', 'trialing', 'past_due', 'canceled'])
                ->orderByRaw("CASE WHEN status IN ('active','trialing') THEN 0 ELSE 1 END")
                ->orderByDesc('updated_at')
                ->first();

            $workspaceSummaries = collect($workspaceIds)
                ->map(fn (string $workspaceId): array => app(WorkspaceCreditLedgerService::class)->summary($workspaceId));

            $balance = (int) $workspaceSummaries->sum('balance_cached');
            $reserved = (int) $workspaceSummaries->sum('reserved_cached');
            $available = (int) $workspaceSummaries->sum('available');
            $invoicesCount = (int) Invoice::query()->where('organization_id', $organization->id)->count();
            $seatLimit = (int) ($activeSubscription?->seat_limit ?: 0);
            $seatUsage = (int) $organization->users()->where('active', true)->count();
            $paymentHealth = $activeSubscription?->status ?? 'inactive';

            return [
                'organization' => $organization,
                'sites_count' => count($siteIds),
                'plan_name' => (string) ($activeSubscription?->plan?->name ?? 'None'),
                'subscription_status' => (string) ($activeSubscription?->status ?? 'inactive'),
                'payment_health' => $paymentHealth,
                'next_payment_at' => $activeSubscription?->next_payment_at,
                'monthly_credits' => (int) ($activeSubscription?->included_credits_per_interval ?? 0),
                'mollie_subscription_id' => (string) ($activeSubscription?->provider_subscription_id ?? ''),
                'seat_usage' => $seatUsage,
                'seat_limit' => $seatLimit,
                'balance_cached' => $balance,
                'reserved_cached' => $reserved,
                'available' => $available,
                'remaining_credits' => $available,
                'invoices_count' => $invoicesCount,
            ];
        });

        return view('admin.billing.index', [
            'rows' => $rows,
            'issuer' => $settings->getInvoiceIssuerProfile(),
            'plans' => $plans,
        ]);
    }

    public function updateInvoiceIssuerProfile(Request $request, BillingSettingsService $settings): RedirectResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:64'],
            'city' => ['nullable', 'string', 'max:128'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'vat_number' => ['nullable', 'string', 'max:64'],
            'kvk_number' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'logo_path' => ['nullable', 'string', 'max:255'],
        ]);

        $settings->putInvoiceIssuerProfile([
            'company_name' => (string) $data['company_name'],
            'address_line1' => (string) ($data['address_line1'] ?? ''),
            'address_line2' => (string) ($data['address_line2'] ?? ''),
            'postal_code' => (string) ($data['postal_code'] ?? ''),
            'city' => (string) ($data['city'] ?? ''),
            'country_code' => strtoupper((string) ($data['country_code'] ?? 'NL')),
            'vat_number' => (string) ($data['vat_number'] ?? ''),
            'kvk_number' => (string) ($data['kvk_number'] ?? ''),
            'email' => (string) ($data['email'] ?? ''),
            'website' => (string) ($data['website'] ?? ''),
            'logo_path' => (string) ($data['logo_path'] ?? 'images/publishlayer-logo.jpg'),
        ]);

        return back()->with('status', 'Invoice issuer profile updated.');
    }

    public function show(Request $request, Organization $organization, PlanQuotaService $quotas, BillingAuditService $billingAudit): View
    {
        $access = app(OrganizationAccessService::class);
        $clientSiteIds = $organization->clientSites()->pluck('client_sites.id')->all();

        $sites = ClientSite::query()
            ->whereIn('id', $clientSiteIds)
            ->orderBy('name')
            ->get(['id', 'name', 'site_url', 'workspace_id']);

        $siteMap = $sites->keyBy('id');
        $workspaceIds = $sites->pluck('workspace_id')->filter()->unique()->values()->all();

        $activeTab = in_array((string) $request->query('tab', 'ledger'), ['ledger', 'payments', 'subscriptions'], true)
            ? (string) $request->query('tab', 'ledger')
            : 'ledger';

        $wallets = SiteCreditAllocation::query()
            ->whereIn('client_site_id', $clientSiteIds)
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (SiteCreditAllocation $allocation) use ($siteMap): array {
                $site = $siteMap->get($allocation->client_site_id);

                return [
                    'id' => (string) $allocation->id,
                    'client_site_id' => (string) $allocation->client_site_id,
                    'site_name' => (string) ($site?->name ?? $allocation->client_site_id),
                    'site_url' => (string) ($site?->site_url ?? ''),
                    'balance_cached' => (int) $allocation->allocated_credits,
                    'reserved_cached' => (int) $allocation->reserved_cached,
                    'available' => (int) $allocation->remaining,
                    'used_cached' => (int) $allocation->used_cached,
                    'updated_at' => $allocation->updated_at,
                ];
            });

        $workspaceSummaries = collect($workspaceIds)
            ->map(fn (string $workspaceId): array => app(WorkspaceCreditLedgerService::class)->summary($workspaceId));

        $openPaymentsAmountCents = PaymentIntent::query()
            ->where('billable_type', CreditPackPurchase::class)
            ->whereIn('billable_id', function ($query) use ($clientSiteIds) {
                $query->select('id')
                    ->from('credit_pack_purchases')
                    ->whereIn('client_site_id', $clientSiteIds);
            })
            ->whereIn('status', ['open', 'pending'])
            ->sum('amount_cents');

        // Keep tab datasets independent so each tab can paginate and filter via query string.
        $ledgerData = $this->buildLedgerData($request, $clientSiteIds, $workspaceIds, $siteMap);
        $paymentData = $this->buildPaymentData($request, $clientSiteIds, $siteMap);
        $subscriptionData = $this->buildSubscriptionData($request, $clientSiteIds, $siteMap);
        $usagePeriod = (string) $request->query('usage_period', now()->format('Ym'));
        if (preg_match('/^\d{6}$/', $usagePeriod) !== 1) {
            $usagePeriod = now()->format('Ym');
        }

        $activeSubscription = $organization->subscriptions()
            ->with('plan')
            ->whereIn('status', ['active', 'trialing', 'pending_mandate', 'past_due'])
            ->orderByRaw("CASE WHEN status IN ('active','trialing') THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->first();
        $activePlan = $activeSubscription?->plan;

        $quotaSettings = [];
        if ($activePlan) {
            foreach (self::QUOTA_FEATURES as $key => $label) {
                $feature = PlanFeature::query()
                    ->where('plan_id', $activePlan->id)
                    ->where('feature_key', $key)
                    ->first();

                $quotaSettings[$key] = [
                    'label' => $label,
                    'value' => (int) ($feature?->value_int ?? -1),
                ];
            }
        }

        $workspaceUsageRows = $organization->workspaces()
            ->with('clientSites')
            ->orderBy('name')
            ->get()
            ->map(function ($workspace) use ($usagePeriod, $quotas): array {
                $articlesUsed = $quotas->periodUsage($workspace, PlanQuotaService::METRIC_ARTICLES_GENERATED, $usagePeriod);
                $llmUsed = $quotas->periodUsage($workspace, PlanQuotaService::METRIC_LLM_QUERIES_RUN, $usagePeriod);
                $auditUsed = $quotas->periodUsage($workspace, PlanQuotaService::METRIC_AUDIT_PAGES_CRAWLED, $usagePeriod);
                $competitorUsed = (int) \App\Models\CrossLinkPermission::query()
                    ->where('from_workspace_id', $workspace->id)
                    ->whereIn('status', ['pending', 'approved'])
                    ->count();

                return [
                    'workspace_id' => (string) $workspace->id,
                    'workspace_name' => (string) $workspace->display_name,
                    'sites_count' => (int) $workspace->clientSites->count(),
                    'usage' => [
                        'articles_generated' => $articlesUsed,
                        'llm_queries_run' => $llmUsed,
                        'audit_pages_crawled' => $auditUsed,
                        'competitor_slots_used' => $competitorUsed,
                    ],
                    'limits' => [
                        'articles_generated' => $quotas->limitForMetric($workspace, PlanQuotaService::METRIC_ARTICLES_GENERATED, -1),
                        'llm_queries_run' => $quotas->limitForMetric($workspace, PlanQuotaService::METRIC_LLM_QUERIES_RUN, -1),
                        'audit_pages_crawled' => $quotas->limitForMetric($workspace, PlanQuotaService::METRIC_AUDIT_PAGES_CRAWLED, -1),
                        'competitor_slots_used' => $quotas->limitForFeature($workspace, 'competitor_slots_limit', -1),
                    ],
                ];
            });

        return view('admin.billing.show', [
            'organization' => $organization,
            'sites' => $sites,
            'wallets' => $wallets,
            'walletStats' => [
                'total_available' => (int) $workspaceSummaries->sum('available'),
                'total_reserved' => (int) $workspaceSummaries->sum('reserved_cached'),
                'total_balance' => (int) $workspaceSummaries->sum('balance_cached'),
                'open_payments_amount_cents' => (int) $openPaymentsAmountCents,
            ],
            'activeTab' => $activeTab,
            'ledgerRows' => $ledgerData['rows'],
            'ledgerFilters' => $ledgerData['filters'],
            'ledgerTypes' => self::LEDGER_TYPES,
            'paymentsRows' => $paymentData['rows'],
            'paymentFilters' => $paymentData['filters'],
            'paymentStatuses' => self::PAYMENT_STATUSES,
            'paymentProviders' => $paymentData['providers'],
            'subscriptionRows' => $subscriptionData['rows'],
            'subscriptionFilters' => $subscriptionData['filters'],
            'subscriptionStatuses' => self::SUBSCRIPTION_STATUSES,
            'activePlan' => $activePlan,
            'quotaSettings' => $quotaSettings,
            'workspaceUsageRows' => $workspaceUsageRows,
            'usagePeriod' => $usagePeriod,
            'organizationAccess' => [
                'label' => $access->label($organization),
                'badge_classes' => $access->badgeClasses($organization),
                'is_early_bird_active' => $access->isEarlyBirdActive($organization),
                'is_early_bird_expired' => $access->isEarlyBirdExpired($organization),
                'early_bird_ends_at' => $organization->early_bird_ends_at,
            ],
            'billingAudit' => $billingAudit->auditOrganization($organization),
        ]);
    }

    public function updatePlanQuotaLimits(
        Request $request,
        Plan $plan,
        EntitlementRefreshService $refreshService
    ): RedirectResponse {
        $data = $request->validate([
            'topics_seed_keywords_limit' => ['required', 'integer', 'min:-1', 'max:1000000'],
            'llm_tracking_queries_per_month_limit' => ['required', 'integer', 'min:-1', 'max:1000000'],
            'competitor_slots_limit' => ['required', 'integer', 'min:-1', 'max:1000000'],
            'seo_audit_crawl_pages_per_month_limit' => ['required', 'integer', 'min:-1', 'max:1000000'],
            'languages_limit' => ['required', 'integer', 'min:-1', 'max:1000000'],
        ]);

        foreach (self::QUOTA_FEATURES as $key => $label) {
            $feature = PlanFeature::query()->firstOrNew([
                'plan_id' => $plan->id,
                'feature_key' => $key,
            ]);

            if (! $feature->exists) {
                $feature->id = (string) Str::uuid();
            }

            $feature->label = $feature->label ?: $label;
            $feature->feature_group = $feature->feature_group ?: 'Quota';
            $feature->sort_order = $feature->sort_order ?: 900;
            $feature->is_highlight = false;
            $feature->value_type = 'int';
            $feature->value_bool = null;
            $feature->value_int = (int) $data[$key];
            $feature->value_string = null;
            $feature->value_json = null;
            $feature->save();
        }

        $subscriptions = Subscription::query()
            ->with('workspace', 'organization.workspaces', 'plan')
            ->where('plan_id', $plan->id)
            ->whereIn('status', ['active', 'trialing', 'pending_mandate', 'past_due'])
            ->get();

        foreach ($subscriptions as $subscription) {
            $refreshService->refreshForSubscription($subscription);
        }

        return back()->with('status', sprintf('Quota limits updated for plan "%s".', $plan->name));
    }

    public function storePlan(Request $request): RedirectResponse
    {
        $attributes = $this->validatePlanPayload($request);

        $plan = new Plan();
        $plan->id = (string) Str::uuid();
        $plan->fill($attributes);
        $plan->save();

        $this->syncFeaturedPlan($plan);
        $this->forgetPublicPlanCache();

        return back()->with('status', sprintf('Plan "%s" created.', $plan->name));
    }

    public function updatePlan(Request $request, Plan $plan): RedirectResponse
    {
        $attributes = $this->validatePlanPayload($request, $plan);

        $plan->fill($attributes);
        $plan->save();

        $this->syncFeaturedPlan($plan);
        $this->forgetPublicPlanCache();

        return back()->with('status', sprintf('Plan "%s" updated.', $plan->name));
    }

    public function storePlanFeature(
        Request $request,
        Plan $plan,
        EntitlementRefreshService $refreshService
    ): RedirectResponse {
        $attributes = $this->validatePlanFeaturePayload($request, $plan);

        $feature = new PlanFeature();
        $feature->id = (string) Str::uuid();
        $feature->plan_id = $plan->id;

        $this->fillPlanFeature($feature, $attributes);
        $feature->save();

        $this->refreshEntitlementsForPlan($plan, $refreshService);
        $this->forgetPublicPlanCache();

        return back()->with('status', sprintf('Feature added to "%s".', $plan->name));
    }

    public function updatePlanFeature(
        Request $request,
        Plan $plan,
        PlanFeature $feature,
        EntitlementRefreshService $refreshService
    ): RedirectResponse {
        $this->ensurePlanFeatureBelongsToPlan($plan, $feature);

        $attributes = $this->validatePlanFeaturePayload($request, $plan, $feature);

        $this->fillPlanFeature($feature, $attributes);
        $feature->save();

        $this->refreshEntitlementsForPlan($plan, $refreshService);
        $this->forgetPublicPlanCache();

        return back()->with('status', sprintf('Feature "%s" updated.', $feature->label ?: $feature->feature_key));
    }

    public function destroyPlanFeature(
        Plan $plan,
        PlanFeature $feature,
        EntitlementRefreshService $refreshService
    ): RedirectResponse {
        $this->ensurePlanFeatureBelongsToPlan($plan, $feature);

        $featureLabel = $feature->label ?: $feature->feature_key;
        $feature->delete();

        $this->refreshEntitlementsForPlan($plan, $refreshService);
        $this->forgetPublicPlanCache();

        return back()->with('status', sprintf('Feature "%s" removed.', $featureLabel));
    }

    public function grantCredits(Request $request, Organization $organization, CreditWalletService $walletService): RedirectResponse
    {
        $siteIds = $organization->clientSites()->pluck('client_sites.id')->all();

        $data = $request->validate([
            'client_site_id' => ['required', 'string', 'in:' . implode(',', $siteIds ?: ['__none__'])],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $site = ClientSite::query()->findOrFail((string) $data['client_site_id']);

        $walletService->addWorkspaceCredits(
            workspaceId: (string) $site->workspace_id,
            amount: (int) $data['amount'],
            type: CreditWalletService::TYPE_ADJUSTMENT,
            meta: array_filter([
                'gifted_by_admin_user_id' => $request->user()->id,
                'gifted_for_organization_id' => $organization->id,
                'note' => trim((string) ($data['note'] ?? '')),
                'gifted' => true,
            ]),
            sourceType: Organization::class,
            sourceId: (string) $organization->id,
            preferredClientSiteId: (string) $data['client_site_id']
        );

        return back()->with('status', 'Free credits granted successfully.');
    }

    public function grantMonthlySubscriptionCredits(
        Request $request,
        Organization $organization,
        SubscriptionMonthlyCreditRecoveryService $recovery
    ): RedirectResponse {
        $data = $request->validate([
            'period' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'dry_run' => ['nullable', 'boolean'],
            'confirm_recovery' => ['nullable', 'boolean'],
        ]);

        $dryRun = (bool) ($data['dry_run'] ?? false);
        if (! $dryRun && ! (bool) ($data['confirm_recovery'] ?? false)) {
            return back()->withErrors([
                'billing' => 'Please confirm the recovery action before granting monthly credits.',
            ]);
        }

        $period = null;
        if (! empty($data['period'])) {
            try {
                $period = Carbon::createFromFormat('Y-m', (string) $data['period'])->startOfMonth();
            } catch (\Throwable) {
                return back()->withErrors([
                    'billing' => 'Invalid period value. Use YYYY-MM format.',
                ]);
            }
        }

        $result = $recovery->recoverForOrganization(
            organization: $organization,
            period: $period,
            dryRun: $dryRun,
            adminUser: $request->user(),
            trigger: 'admin_ui',
            requirePaidSignal: false
        );

        if (! (bool) ($result['ok'] ?? false)) {
            return back()->withErrors(['billing' => (string) ($result['reason'] ?? 'Recovery failed.')]);
        }

        $action = (string) ($result['action'] ?? '');
        $amount = (int) ($result['amount'] ?? 0);
        $periodStart = (string) ($result['period_start'] ?? 'n/a');
        $periodEnd = (string) ($result['period_end'] ?? 'n/a');

        if ($action === 'dry_run') {
            return back()->with('status', sprintf(
                'Dry run: would grant %d monthly credits for %s to %s.',
                $amount,
                $periodStart . ' -> ' . $periodEnd,
                (string) ($result['client_site_id'] ?? 'n/a')
            ));
        }

        if ($action === 'already_granted') {
            return back()->with('status', sprintf(
                'Skipped: monthly credits already granted for %s (ledger %s).',
                $periodStart . ' -> ' . $periodEnd,
                (string) ($result['ledger_id'] ?? 'n/a')
            ));
        }

        if ($action === 'skipped') {
            return back()->with('status', (string) ($result['reason'] ?? 'No monthly credits granted.'));
        }

        return back()->with('status', sprintf(
            'Granted %d monthly credits for %s (ledger %s).',
            $amount,
            $periodStart . ' -> ' . $periodEnd,
            (string) ($result['ledger_id'] ?? 'n/a')
        ));
    }

    public function triggerMandateRecheck(Organization $organization, SubscriptionLifecycleService $lifecycle): RedirectResponse
    {
        $subscription = $organization->subscriptions()
            ->whereIn('status', ['pending_mandate', 'active', 'past_due'])
            ->latest('updated_at')
            ->first();

        if (! $subscription) {
            return back()->withErrors(['billing' => 'No subscription found for mandate recheck.']);
        }

        $lifecycle->activateRecurringIfMandateReady($subscription);

        return back()->with('status', 'Mandate recheck triggered.');
    }

    public function triggerRenewalRetry(Organization $organization, SubscriptionLifecycleService $lifecycle): RedirectResponse
    {
        $subscription = $organization->subscriptions()
            ->whereIn('status', ['past_due', 'pending_mandate', 'active'])
            ->latest('updated_at')
            ->first();

        if (! $subscription) {
            return back()->withErrors(['billing' => 'No subscription found for renewal retry.']);
        }

        // Retry refreshes provider state and never grants credits without a paid webhook event.
        $subscription = $lifecycle->refreshProviderState($subscription);
        if ($subscription->status === 'past_due') {
            $lifecycle->suspendIfGraceExpired($subscription->fresh() ?? $subscription);
        }

        return back()->with('status', 'Renewal retry action executed.');
    }

    public function forceCancelSubscription(
        Request $request,
        Organization $organization,
        AuditLogService $auditLogs
    ): RedirectResponse {
        $subscription = $organization->subscriptions()
            ->whereIn('status', ['active', 'trialing', 'pending_mandate', 'past_due', 'suspended'])
            ->latest('updated_at')
            ->first();

        if (! $subscription) {
            return back()->withErrors(['billing' => 'No cancelable subscription found for this organization.']);
        }

        $before = [
            'subscription_id' => (string) $subscription->id,
            'status' => (string) $subscription->status,
            'status_reason' => (string) ($subscription->status_reason ?? ''),
            'provider_payment_id' => (string) ($subscription->provider_payment_id ?? ''),
            'provider_subscription_id' => (string) ($subscription->provider_subscription_id ?? ''),
            'provider_mandate_id' => (string) ($subscription->provider_mandate_id ?? ''),
            'active_subscription_id' => (string) ($organization->active_subscription_id ?? ''),
        ];

        DB::transaction(function () use ($organization, $subscription): void {
            PaymentIntent::query()
                ->where('billable_type', Subscription::class)
                ->where('billable_id', $subscription->id)
                ->whereIn('status', ['pending', 'open'])
                ->update([
                    'status' => 'canceled',
                    'canceled_at' => now(),
                    'checkout_url' => null,
                ]);

            $subscription->status = 'canceled';
            $subscription->status_reason = 'admin_forced_cancel';
            $subscription->canceled_at = now();
            $subscription->provider_payment_id = null;
            $subscription->provider_subscription_id = null;
            $subscription->provider_mandate_id = null;
            $subscription->save();

            if ((string) ($organization->active_subscription_id ?? '') === (string) $subscription->id) {
                $organization->active_subscription_id = null;
                $organization->save();
            }
        });

        $after = [
            'subscription_id' => (string) $subscription->id,
            'status' => 'canceled',
            'status_reason' => 'admin_forced_cancel',
            'active_subscription_id' => (string) ($organization->fresh()->active_subscription_id ?? ''),
        ];

        $auditLogs->log(
            actor: $request->user(),
            subject: $organization,
            action: 'billing.subscription.force_canceled',
            before: $before,
            after: $after,
            request: $request
        );

        return back()->with('status', 'Subscription was force-canceled and pending checkout intents were canceled.');
    }

    private function buildLedgerData(Request $request, array $clientSiteIds, array $workspaceIds, Collection $siteMap): array
    {
        // Ledger filters map 1:1 to query-string keys so the UI state is shareable via URL.
        $filters = [
            'site' => (string) $request->query('ledger_site', ''),
            'type' => (string) $request->query('ledger_type', ''),
            'from' => (string) $request->query('ledger_from', ''),
            'to' => (string) $request->query('ledger_to', ''),
            'q' => trim((string) $request->query('ledger_q', '')),
        ];

        $query = WorkspaceCreditTransaction::query()
            ->where(function (Builder $builder) use ($clientSiteIds, $workspaceIds): void {
                $builder->whereIn('client_site_id', $clientSiteIds);

                if ($workspaceIds !== []) {
                    $builder->orWhere(function (Builder $workspaceQuery) use ($workspaceIds): void {
                        $workspaceQuery
                            ->whereNull('client_site_id')
                            ->whereIn('workspace_id', $workspaceIds);
                    });
                }
            });

        if ($filters['site'] !== '' && in_array($filters['site'], $clientSiteIds, true)) {
            $query->where('client_site_id', $filters['site']);
        }

        if ($filters['type'] !== '' && in_array($filters['type'], self::LEDGER_TYPES, true)) {
            $this->applyLedgerTypeFilter($query, $filters['type']);
        }

        if ($filters['from'] !== '' && $this->isValidDate($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if ($filters['to'] !== '' && $this->isValidDate($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        if ($filters['q'] !== '') {
            $query->where(function (Builder $builder) use ($filters) {
                $builder
                    ->where('reference_id', 'like', '%' . $filters['q'] . '%')
                    ->orWhere('metadata', 'like', '%' . $filters['q'] . '%');
            });
        }

        $rows = $query
            ->latest('created_at')
            ->paginate(10, ['*'], 'ledger_page')
            ->through(function (WorkspaceCreditTransaction $entry) use ($siteMap) {
                $meta = is_array($entry->metadata) ? $entry->metadata : [];
                $siteName = $entry->client_site_id
                    ? (string) ($siteMap->get($entry->client_site_id)->name ?? $entry->client_site_id)
                    : 'Workspace pool';

                return [
                    'id' => (string) $entry->id,
                    'type' => $this->presentLedgerType($entry),
                    'amount' => (int) $entry->amount,
                    'created_at' => $entry->created_at,
                    'site_name' => $siteName,
                    'site_id' => (string) ($entry->client_site_id ?? ''),
                    'reference' => $this->ledgerReference($entry),
                    'note' => (string) ($meta['note'] ?? ''),
                    'meta' => $meta,
                    'source_type' => (string) ($entry->reference_type ?? ''),
                    'source_id' => (string) ($entry->reference_id ?? ''),
                    'brief_id' => '',
                    'user_id' => '',
                ];
            })
            ->withQueryString();

        return [
            'filters' => $filters,
            'rows' => $rows,
        ];
    }

    private function buildPaymentData(Request $request, array $clientSiteIds, Collection $siteMap): array
    {
        $organizationId = (int) ($request->route('organization')?->id ?? 0);

        $providerOptions = PaymentIntent::query()
            ->where(function (Builder $builder) use ($clientSiteIds, $organizationId): void {
                $builder->where(function (Builder $pack) use ($clientSiteIds): void {
                    $pack->where('billable_type', CreditPackPurchase::class)
                        ->whereIn('billable_id', function ($query) use ($clientSiteIds) {
                            $query->select('id')
                                ->from('credit_pack_purchases')
                                ->whereIn('client_site_id', $clientSiteIds);
                        });
                })->orWhere(function (Builder $subscription) use ($organizationId): void {
                    $subscription->where('billable_type', Subscription::class)
                        ->whereIn('billable_id', function ($query) use ($organizationId) {
                            $query->select('id')
                                ->from('subscriptions')
                                ->where('organization_id', $organizationId);
                        });
                })->orWhere(function (Builder $planChange) use ($organizationId): void {
                    $planChange->where('billable_type', SubscriptionPlanChange::class)
                        ->whereIn('billable_id', function ($query) use ($organizationId) {
                            $query->select('id')
                                ->from('subscription_plan_changes')
                                ->where('organization_id', $organizationId);
                        });
                });
            })
            ->select('provider')
            ->distinct()
            ->orderBy('provider')
            ->pluck('provider')
            ->filter()
            ->values();

        $filters = [
            'status' => (string) $request->query('payment_status', ''),
            'provider' => (string) $request->query('payment_provider', ''),
            'from' => (string) $request->query('payment_from', ''),
            'to' => (string) $request->query('payment_to', ''),
            'q' => trim((string) $request->query('payment_q', '')),
        ];

        $query = PaymentIntent::query()
            ->where(function (Builder $builder) use ($clientSiteIds, $organizationId): void {
                $builder->where(function (Builder $pack) use ($clientSiteIds): void {
                    $pack->where('billable_type', CreditPackPurchase::class)
                        ->whereIn('billable_id', function ($subQuery) use ($clientSiteIds) {
                            $subQuery->select('id')
                                ->from('credit_pack_purchases')
                                ->whereIn('client_site_id', $clientSiteIds);
                        });
                })->orWhere(function (Builder $subscription) use ($organizationId): void {
                    $subscription->where('billable_type', Subscription::class)
                        ->whereIn('billable_id', function ($subQuery) use ($organizationId) {
                            $subQuery->select('id')
                                ->from('subscriptions')
                                ->where('organization_id', $organizationId);
                        });
                })->orWhere(function (Builder $planChange) use ($organizationId): void {
                    $planChange->where('billable_type', SubscriptionPlanChange::class)
                        ->whereIn('billable_id', function ($subQuery) use ($organizationId) {
                            $subQuery->select('id')
                                ->from('subscription_plan_changes')
                                ->where('organization_id', $organizationId);
                        });
                });
            })
            ->with(['billable', 'invoice']);

        if ($filters['status'] !== '' && in_array($filters['status'], self::PAYMENT_STATUSES, true)) {
            $query->where('status', $filters['status']);
        }

        if ($filters['provider'] !== '' && $providerOptions->contains($filters['provider'])) {
            $query->where('provider', $filters['provider']);
        }

        if ($filters['from'] !== '' && $this->isValidDate($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if ($filters['to'] !== '' && $this->isValidDate($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        if ($filters['q'] !== '') {
            $query->where(function (Builder $builder) use ($filters) {
                $builder
                    ->where('id', 'like', '%' . $filters['q'] . '%')
                    ->orWhere('provider_payment_id', 'like', '%' . $filters['q'] . '%')
                    ->orWhere('idempotency_key', 'like', '%' . $filters['q'] . '%')
                    ->orWhere('meta', 'like', '%' . $filters['q'] . '%');
            });
        }

        $rows = $query
            ->latest('created_at')
            ->paginate(10, ['*'], 'payments_page')
            ->through(function (PaymentIntent $payment) use ($siteMap) {
                $meta = is_array($payment->meta) ? $payment->meta : [];
                $purchase = $payment->billable instanceof CreditPackPurchase ? $payment->billable : null;
                $subscription = $payment->billable instanceof Subscription ? $payment->billable : null;
                $planChange = $payment->billable instanceof SubscriptionPlanChange ? $payment->billable : null;
                $planChange?->loadMissing('subscription');
                $siteId = (string) (
                    $purchase?->client_site_id
                    ?? $subscription?->client_site_id
                    ?? $planChange?->subscription?->client_site_id
                    ?? ''
                );

                return [
                    'id' => (string) $payment->id,
                    'status' => (string) $payment->status,
                    'provider' => (string) $payment->provider,
                    'amount_cents' => (int) $payment->amount_cents,
                    'currency' => (string) $payment->currency,
                    'created_at' => $payment->created_at,
                    'paid_at' => $payment->paid_at,
                    'failed_at' => $payment->failed_at,
                    'canceled_at' => $payment->canceled_at,
                    'provider_payment_id' => (string) ($payment->provider_payment_id ?? ''),
                    'checkout_url' => (string) ($payment->checkout_url ?? ''),
                    'site_name' => (string) ($siteMap->get($siteId)->name ?? ($siteId ?: '-')),
                    'site_id' => $siteId,
                    'billable_id' => (string) ($payment->billable_id ?? ''),
                    'billable_type' => (string) ($payment->billable_type ?? ''),
                    'meta' => $meta,
                    'invoice_id' => (string) ($payment->invoice?->id ?? ''),
                    'invoice_number' => (string) ($payment->invoice?->number ?? ''),
                    'invoice_status' => (string) ($payment->invoice?->status ?? ''),
                ];
            })
            ->withQueryString();

        return [
            'filters' => $filters,
            'providers' => $providerOptions,
            'rows' => $rows,
        ];
    }

    private function buildSubscriptionData(Request $request, array $clientSiteIds, Collection $siteMap): array
    {
        $filters = [
            'site' => (string) $request->query('subscription_site', ''),
            'status' => (string) $request->query('subscription_status', 'active'),
        ];

        if (!in_array($filters['status'], self::SUBSCRIPTION_STATUSES, true)) {
            $filters['status'] = 'active';
        }

        $query = Subscription::query()
            ->with('plan')
            ->whereIn('client_site_id', $clientSiteIds);

        if ($filters['site'] !== '' && in_array($filters['site'], $clientSiteIds, true)) {
            $query->where('client_site_id', $filters['site']);
        }

        if ($filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        $rows = $query
            ->latest('created_at')
            ->paginate(10, ['*'], 'subscriptions_page')
            ->through(function (Subscription $subscription) use ($siteMap) {
                return [
                    'id' => (string) $subscription->id,
                    'status' => (string) $subscription->status,
                    'status_reason' => (string) ($subscription->status_reason ?? ''),
                    'plan_name' => (string) ($subscription->plan?->name ?? 'Plan'),
                    'site_name' => (string) ($siteMap->get($subscription->client_site_id)->name ?? $subscription->client_site_id),
                    'site_id' => (string) ($subscription->client_site_id ?? ''),
                    'current_period_end' => $subscription->current_period_end,
                    'next_payment_at' => $subscription->next_payment_at,
                    'price_cents' => (int) ($subscription->price_cents ?? 0),
                    'currency' => (string) ($subscription->currency ?? 'EUR'),
                    'included_credits_per_interval' => (int) ($subscription->included_credits_per_interval ?? 0),
                    'provider' => (string) ($subscription->provider ?? ''),
                    'provider_customer_id' => (string) ($subscription->provider_customer_id ?? ''),
                    'provider_subscription_id' => (string) ($subscription->provider_subscription_id ?? ''),
                    'canceled_at' => $subscription->canceled_at,
                    'meta' => is_array($subscription->meta) ? $subscription->meta : [],
                ];
            })
            ->withQueryString();

        return [
            'filters' => $filters,
            'rows' => $rows,
        ];
    }

    private function validatePlanPayload(Request $request, ?Plan $plan = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', 'alpha_dash', Rule::unique('plans', 'slug')->ignore($plan?->id)],
            'internal_code' => ['nullable', 'string', 'max:64', 'alpha_dash', Rule::unique('plans', 'internal_code')->ignore($plan?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'price_monthly_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'price_yearly_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'currency' => ['nullable', 'string', 'max:8'],
            'badge' => ['nullable', 'string', 'max:120'],
            'cta_label' => ['nullable', 'string', 'max:120'],
            'cta_url' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
            'billing_type' => ['required', 'string', Rule::in(['fixed', 'custom'])],
            'billing_provider' => ['nullable', 'string', 'max:32'],
            'billing_provider_plan_key' => ['nullable', 'string', 'max:128'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:1000000'],
            'included_credits' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'seat_limit' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'has_required_onboarding' => ['nullable', 'boolean'],
            'onboarding_label' => ['nullable', 'string', 'max:120'],
            'onboarding_checkout_label' => ['nullable', 'string', 'max:120'],
            'onboarding_receipt_label' => ['nullable', 'string', 'max:120'],
            'onboarding_description' => ['nullable', 'string', 'max:2000'],
            'onboarding_fee_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'onboarding_fee_currency' => ['nullable', 'string', 'max:8'],
            'onboarding_display_mode' => ['nullable', 'string', Rule::in(['guided_onboarding', 'launch_setup', 'implementation_onboarding', 'custom'])],
            'onboarding_is_visible_public' => ['nullable', 'boolean'],
            'onboarding_sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'onboarding_internal_notes' => ['nullable', 'string', 'max:5000'],
            'onboarding_effective_from' => ['nullable', 'date'],
        ]);

        $billingType = (string) $data['billing_type'];
        $isPublic = (bool) ($data['is_public'] ?? false);
        $isActive = (bool) ($data['is_active'] ?? false);
        $isFeatured = (bool) ($data['is_featured'] ?? false);

        if ($billingType !== 'fixed') {
            $isFeatured = false;
        }

        if (! $isPublic || ! $isActive) {
            $isFeatured = false;
        }

        $monthly = $data['price_monthly_cents'] ?? null;
        $yearly = $data['price_yearly_cents'] ?? null;
        $legacyMonthly = $monthly;
        $legacyPrice = $monthly;
        $requiresOnboarding = (bool) ($data['has_required_onboarding'] ?? false);

        if ($billingType === 'custom') {
            $monthly = null;
            $yearly = null;
            $legacyMonthly = 0;
            $legacyPrice = 0;
        }

        if ($requiresOnboarding) {
            validator(
                $data,
                [
                    'onboarding_label' => ['required', 'string', 'max:120'],
                    'onboarding_fee_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
                ],
                [],
                [
                    'onboarding_label' => 'onboarding label',
                    'onboarding_fee_cents' => 'onboarding fee',
                ]
            )->validate();
        }

        $internalCode = trim((string) ($data['internal_code'] ?? ''));
        if ($internalCode === '') {
            $internalCode = (string) $data['slug'];
        }

        return [
            'key' => (string) $data['slug'],
            'slug' => (string) $data['slug'],
            'internal_code' => $internalCode,
            'name' => (string) $data['name'],
            'description_short' => trim((string) ($data['description'] ?? '')) ?: null,
            'interval' => 'month',
            'price_monthly_cents' => $monthly,
            'price_yearly_cents' => $yearly,
            'monthly_price_cents' => $legacyMonthly,
            'price_cents' => $legacyPrice,
            'currency' => strtoupper(trim((string) ($data['currency'] ?? 'EUR'))),
            'included_credits' => (int) ($data['included_credits'] ?? 0),
            'included_credits_per_interval' => (int) ($data['included_credits'] ?? 0),
            'seat_limit' => (int) ($data['seat_limit'] ?? 0),
            'has_required_onboarding' => $requiresOnboarding,
            'onboarding_label' => $requiresOnboarding ? trim((string) ($data['onboarding_label'] ?? '')) : null,
            'onboarding_checkout_label' => $requiresOnboarding ? (trim((string) ($data['onboarding_checkout_label'] ?? '')) ?: trim((string) ($data['onboarding_label'] ?? ''))) : null,
            'onboarding_receipt_label' => $requiresOnboarding ? (trim((string) ($data['onboarding_receipt_label'] ?? '')) ?: trim((string) ($data['onboarding_label'] ?? ''))) : null,
            'onboarding_description' => $requiresOnboarding ? (trim((string) ($data['onboarding_description'] ?? '')) ?: null) : null,
            'onboarding_fee_cents' => $requiresOnboarding ? (int) ($data['onboarding_fee_cents'] ?? 0) : null,
            'onboarding_fee_currency' => strtoupper(trim((string) ($data['onboarding_fee_currency'] ?? $data['currency'] ?? 'EUR'))),
            'onboarding_display_mode' => $requiresOnboarding ? (trim((string) ($data['onboarding_display_mode'] ?? '')) ?: 'guided_onboarding') : null,
            'onboarding_is_visible_public' => $requiresOnboarding ? (bool) ($data['onboarding_is_visible_public'] ?? false) : false,
            'onboarding_sort_order' => $requiresOnboarding ? (int) ($data['onboarding_sort_order'] ?? 0) : 0,
            'onboarding_internal_notes' => $requiresOnboarding ? (trim((string) ($data['onboarding_internal_notes'] ?? '')) ?: null) : null,
            'onboarding_effective_from' => $requiresOnboarding && ! empty($data['onboarding_effective_from']) ? Carbon::parse((string) $data['onboarding_effective_from']) : null,
            'is_active' => $isActive,
            'is_public' => $isPublic,
            'billing_type' => $billingType,
            'billing_provider' => trim((string) ($data['billing_provider'] ?? '')) ?: null,
            'billing_provider_plan_key' => trim((string) ($data['billing_provider_plan_key'] ?? '')) ?: null,
            'is_featured' => $isFeatured,
            'is_popular' => $isFeatured,
            'sort_order' => (int) $data['sort_order'],
            'badge' => trim((string) ($data['badge'] ?? '')) ?: null,
            'cta_label' => trim((string) ($data['cta_label'] ?? '')) ?: null,
            'cta_href' => trim((string) ($data['cta_url'] ?? '')) ?: null,
        ];
    }

    private function validatePlanFeaturePayload(Request $request, Plan $plan, ?PlanFeature $feature = null): array
    {
        $data = $request->validate([
            'feature_key' => [
                'required',
                'string',
                'max:120',
                Rule::unique('plan_features', 'feature_key')
                    ->where(fn ($query) => $query->where('plan_id', $plan->id))
                    ->ignore($feature?->id),
            ],
            'label' => ['required', 'string', 'max:255'],
            'feature_group' => ['nullable', 'string', 'max:120'],
            'is_highlight' => ['nullable', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:1000000'],
            'value_type' => ['required', 'string', Rule::in(['bool', 'int', 'string', 'json'])],
            'value_bool' => ['nullable', 'boolean'],
            'value_int' => ['nullable', 'integer', 'min:-1', 'max:100000000'],
            'value_string' => ['nullable', 'string', 'max:255'],
            'value_json' => ['nullable', 'json'],
        ]);

        return [
            'feature_key' => trim((string) $data['feature_key']),
            'label' => trim((string) $data['label']),
            'feature_group' => trim((string) ($data['feature_group'] ?? '')) ?: null,
            'is_highlight' => (bool) ($data['is_highlight'] ?? false),
            'sort_order' => (int) $data['sort_order'],
            'value_type' => (string) $data['value_type'],
            'value_bool' => $request->boolean('value_bool'),
            'value_int' => array_key_exists('value_int', $data) && $data['value_int'] !== null ? (int) $data['value_int'] : null,
            'value_string' => trim((string) ($data['value_string'] ?? '')) ?: null,
            'value_json' => isset($data['value_json']) && $data['value_json'] !== null
                ? json_decode((string) $data['value_json'], true)
                : null,
        ];
    }

    private function fillPlanFeature(PlanFeature $feature, array $attributes): void
    {
        $feature->feature_key = $attributes['feature_key'];
        $feature->label = $attributes['label'];
        $feature->feature_group = $attributes['feature_group'];
        $feature->is_highlight = (bool) $attributes['is_highlight'];
        $feature->sort_order = (int) $attributes['sort_order'];
        $feature->value_type = $attributes['value_type'];
        $feature->value_bool = $attributes['value_type'] === 'bool' ? (bool) $attributes['value_bool'] : null;
        $feature->value_int = $attributes['value_type'] === 'int' ? $attributes['value_int'] : null;
        $feature->value_string = $attributes['value_type'] === 'string' ? $attributes['value_string'] : null;
        $feature->value_json = $attributes['value_type'] === 'json' ? $attributes['value_json'] : null;
    }

    private function ensurePlanFeatureBelongsToPlan(Plan $plan, PlanFeature $feature): void
    {
        if ((string) $feature->plan_id !== (string) $plan->id) {
            abort(404);
        }
    }

    private function syncFeaturedPlan(Plan $plan): void
    {
        if (! $plan->is_featured || $plan->billing_type !== 'fixed' || ! $plan->is_public || ! $plan->is_active) {
            $plan->forceFill([
                'is_featured' => false,
                'is_popular' => false,
            ])->save();

            return;
        }

        Plan::query()
            ->where('id', '!=', $plan->id)
            ->where('billing_type', 'fixed')
            ->where('is_public', true)
            ->update([
                'is_featured' => false,
                'is_popular' => false,
            ]);

        $plan->forceFill([
            'is_featured' => true,
            'is_popular' => true,
        ])->save();
    }

    private function refreshEntitlementsForPlan(Plan $plan, EntitlementRefreshService $refreshService): void
    {
        $subscriptions = Subscription::query()
            ->with('workspace', 'organization.workspaces', 'plan')
            ->where('plan_id', $plan->id)
            ->whereIn('status', ['active', 'trialing', 'pending_mandate', 'past_due'])
            ->get();

        foreach ($subscriptions as $subscription) {
            $refreshService->refreshForSubscription($subscription);
        }
    }

    private function forgetPublicPlanCache(): void
    {
        Cache::forget('public.landing.active_plans');
        Cache::forget('public.landing.enterprise_plan');
    }

    private function isValidDate(string $value): bool
    {
        try {
            Carbon::parse($value);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function applyLedgerTypeFilter(Builder $query, string $type): void
    {
        match ($type) {
            'reservation' => $query->where('type', WorkspaceCreditLedgerService::TYPE_RESERVE),
            'release' => $query->where('type', WorkspaceCreditLedgerService::TYPE_RELEASE),
            'usage' => $query->where('type', WorkspaceCreditLedgerService::TYPE_COMMIT),
            'allowance' => $query->where('type', WorkspaceCreditLedgerService::TYPE_SUBSCRIPTION_GRANT),
            'pack_purchase' => $query->where('type', WorkspaceCreditLedgerService::TYPE_PURCHASE),
            'adjustment' => $query->whereIn('type', [
                WorkspaceCreditLedgerService::TYPE_ADJUSTMENT,
                WorkspaceCreditLedgerService::TYPE_REFUND,
                WorkspaceCreditLedgerService::TYPE_ALLOCATION_RETURN,
            ]),
            default => null,
        };
    }

    private function presentLedgerType(WorkspaceCreditTransaction $entry): string
    {
        return match ($entry->type) {
            WorkspaceCreditLedgerService::TYPE_RESERVE => 'reservation',
            WorkspaceCreditLedgerService::TYPE_RELEASE => 'release',
            WorkspaceCreditLedgerService::TYPE_COMMIT => 'usage',
            WorkspaceCreditLedgerService::TYPE_SUBSCRIPTION_GRANT => 'allowance',
            WorkspaceCreditLedgerService::TYPE_PURCHASE => 'pack_purchase',
            WorkspaceCreditLedgerService::TYPE_REFUND => 'refund',
            WorkspaceCreditLedgerService::TYPE_ALLOCATION_RETURN => 'allocation_return',
            default => (string) $entry->type,
        };
    }

    private function ledgerReference(WorkspaceCreditTransaction $entry): string
    {
        $meta = is_array($entry->metadata) ? $entry->metadata : [];

        foreach (['payment_intent_id', 'payment_id', 'pack_purchase_id', 'content_id', 'draft_id', 'reservation_entry_id'] as $key) {
            if (!empty($meta[$key])) {
                return $key . ': ' . (string) $meta[$key];
            }
        }

        if ($entry->reference_id) {
            return 'reference: ' . (string) $entry->reference_id;
        }

        return 'entry: ' . (string) $entry->id;
    }

    public function pricingPageContent(SiteSettingsService $settings): View
    {
        $content = $settings->get('pricing_page_content', []);

        return view('admin.billing.pricing-page-content', [
            'content' => $content,
        ]);
    }

    public function updatePricingPageContent(Request $request, SiteSettingsService $settings, MarketingPricingService $pricing): RedirectResponse
    {
        $data = $request->validate([
            'hero_badge' => ['nullable', 'string', 'max:100'],
            'hero_title' => ['nullable', 'string', 'max:255'],
            'hero_subline' => ['nullable', 'string', 'max:255'],
            'hero_text_1' => ['nullable', 'string', 'max:1000'],
            'hero_text_2' => ['nullable', 'string', 'max:1000'],
            'hero_note' => ['nullable', 'string', 'max:500'],
            'monthly_no_setup_text' => ['nullable', 'string', 'max:255'],
            'includes' => ['nullable', 'string', 'max:1000'],
            'why_title' => ['nullable', 'string', 'max:255'],
            'why_points' => ['nullable', 'string', 'max:2000'],
            'credit_faq_title' => ['nullable', 'string', 'max:255'],
            'credit_faq_text' => ['nullable', 'string', 'max:1000'],
            'credit_examples' => ['nullable', 'string', 'max:2000'],
            'credit_failure_note' => ['nullable', 'string', 'max:500'],
            'enterprise_title' => ['nullable', 'string', 'max:255'],
            'enterprise_text' => ['nullable', 'string', 'max:1000'],
            'enterprise_points' => ['nullable', 'string', 'max:2000'],
            'bottom_cta_title' => ['nullable', 'string', 'max:255'],
            'bottom_cta_text' => ['nullable', 'string', 'max:1000'],
            'bottom_cta_button_label' => ['nullable', 'string', 'max:100'],
            'bottom_cta_button_url' => ['nullable', 'string', 'max:255'],
        ]);

        $content = [
            'hero_badge' => trim((string) ($data['hero_badge'] ?? '')) ?: null,
            'hero_title' => trim((string) ($data['hero_title'] ?? '')) ?: null,
            'hero_subline' => trim((string) ($data['hero_subline'] ?? '')) ?: null,
            'hero_text_1' => trim((string) ($data['hero_text_1'] ?? '')) ?: null,
            'hero_text_2' => trim((string) ($data['hero_text_2'] ?? '')) ?: null,
            'hero_note' => trim((string) ($data['hero_note'] ?? '')) ?: null,
            'monthly_no_setup_text' => trim((string) ($data['monthly_no_setup_text'] ?? '')) ?: null,
            'includes' => $this->parseNewlineSeparatedList($data['includes'] ?? ''),
            'why_title' => trim((string) ($data['why_title'] ?? '')) ?: null,
            'why_points' => $this->parseNewlineSeparatedList($data['why_points'] ?? ''),
            'credit_faq_title' => trim((string) ($data['credit_faq_title'] ?? '')) ?: null,
            'credit_faq_text' => trim((string) ($data['credit_faq_text'] ?? '')) ?: null,
            'credit_examples' => $this->parseNewlineSeparatedList($data['credit_examples'] ?? ''),
            'credit_failure_note' => trim((string) ($data['credit_failure_note'] ?? '')) ?: null,
            'enterprise_title' => trim((string) ($data['enterprise_title'] ?? '')) ?: null,
            'enterprise_text' => trim((string) ($data['enterprise_text'] ?? '')) ?: null,
            'enterprise_points' => $this->parseNewlineSeparatedList($data['enterprise_points'] ?? ''),
            'bottom_cta_title' => trim((string) ($data['bottom_cta_title'] ?? '')) ?: null,
            'bottom_cta_text' => trim((string) ($data['bottom_cta_text'] ?? '')) ?: null,
            'bottom_cta_button_label' => trim((string) ($data['bottom_cta_button_label'] ?? '')) ?: null,
            'bottom_cta_button_url' => trim((string) ($data['bottom_cta_button_url'] ?? '')) ?: null,
        ];

        $settings->put('pricing_page_content', $content);

        Cache::forget('public.pricing.page_content');
        $pricing->clearCaches();

        return back()->with('status', 'Pricing page content updated.');
    }

    /**
     * Parse newline-separated text into an array of non-empty trimmed strings.
     *
     * @return array<int, string>
     */
    private function parseNewlineSeparatedList(string $text): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", $text)),
            fn (string $line): bool => $line !== ''
        ));
    }
}
