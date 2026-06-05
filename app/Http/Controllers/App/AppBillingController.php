<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Enums\Billing\SubscriptionPlanChangeStatus;
use App\Http\Requests\App\ChangeSubscriptionPlanRequest;
use App\Models\ClientSite;
use App\Models\CreditPack;
use App\Models\CreditPackPurchase;
use App\Models\PaymentIntent;
use App\Models\SiteCreditAllocation;
use App\Models\Subscription;
use App\Models\SubscriptionPlanChange;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\WorkspaceCreditTransaction;
use App\Services\Billing\SubscriptionCheckoutPricing;
use App\Services\CreditPackPurchaseService;
use App\Services\Credits\SiteCreditAllocationService;
use App\Services\Credits\WorkspaceCreditLedgerService;
use App\Services\OrganizationAccessService;
use App\Services\PackCheckoutService;
use App\Services\PlanChangeService;
use App\Services\SubscriptionService;
use App\Services\SubscriptionLifecycleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;

class AppBillingController extends Controller
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

    public function index(Request $request): View
    {
        $organization = $request->user()->organization;
        $subscriptionService = app(SubscriptionService::class);
        $access = app(OrganizationAccessService::class);
        $activeSubscription = $organization ? $subscriptionService->getActiveForOrganization($organization) : null;
        $currentSubscription = $organization ? $subscriptionService->getCurrentForOrganization($organization) : null;
        $earlyBirdActive = $organization ? $access->isEarlyBirdActive($organization) : false;
        $earlyBirdExpired = $organization ? $access->isEarlyBirdExpired($organization) : false;
        $scheduledPlanChange = $activeSubscription
            ? $activeSubscription->planChanges()
                ->with('toPlan')
                ->where('strategy', 'next_period')
                ->where('status', SubscriptionPlanChangeStatus::PENDING->value)
                ->latest('created_at')
                ->first()
            : null;
        $pendingImmediatePlanChange = $activeSubscription
            ? $activeSubscription->planChanges()
                ->with(['toPlan', 'paymentIntent'])
                ->where('strategy', 'immediate_proration')
                ->where('status', SubscriptionPlanChangeStatus::PENDING_PAYMENT->value)
                ->latest('created_at')
                ->first()
            : null;
        $siteIds = $organization?->clientSites()->pluck('client_sites.id')->all() ?? [];

        $sites = ClientSite::query()
            ->whereIn('id', $siteIds)
            ->orderBy('name')
            ->get(['id', 'name', 'site_url', 'workspace_id']);

        $siteMap = $sites->keyBy('id');

        $activeTab = in_array((string) $request->query('tab', 'ledger'), ['ledger', 'payments', 'subscriptions'], true)
            ? (string) $request->query('tab', 'ledger')
            : 'ledger';

        $siteAllocations = app(SiteCreditAllocationService::class);
        $workspaceCredits = app(WorkspaceCreditLedgerService::class);

        $wallets = $sites->map(function (ClientSite $site) use ($siteAllocations, $workspaceCredits): array {
            $allocation = $siteAllocations->getOrCreateAllocation((string) $site->id);
            $workspaceSummary = $workspaceCredits->summary((string) $site->workspace_id);

            return [
                'id' => (string) $allocation->id,
                'client_site_id' => (string) $site->id,
                'site_name' => (string) $site->name,
                'site_url' => (string) ($site->site_url ?? ''),
                'workspace_id' => (string) $site->workspace_id,
                'balance_cached' => (int) $allocation->allocated_credits,
                'reserved_cached' => (int) $allocation->reserved_cached,
                'available' => (int) $allocation->remaining,
                'used_cached' => (int) $allocation->used_cached,
                'workspace_unallocated_credits' => (int) ($workspaceSummary['unallocated_credits'] ?? 0),
                'updated_at' => $allocation->updated_at,
            ];
        });

        $workspaceSummaries = $sites->pluck('workspace_id')->unique()->map(function (string $workspaceId) use ($workspaceCredits): array {
            return $workspaceCredits->summary($workspaceId);
        });

        $totals = [
            'balance_cached' => (int) $workspaceSummaries->sum('balance_cached'),
            'reserved_cached' => (int) $workspaceSummaries->sum('reserved_cached'),
            'available' => (int) $workspaceSummaries->sum('available'),
            'allocated_credits' => (int) $wallets->sum('balance_cached'),
            'used_credits' => (int) $wallets->sum('used_cached'),
            'unallocated_credits' => (int) $workspaceSummaries->sum('unallocated_credits'),
            'open_payments_amount_cents' => (int) PaymentIntent::query()
                ->where('billable_type', CreditPackPurchase::class)
                ->whereIn('billable_id', function ($query) use ($siteIds) {
                    $query->select('id')
                        ->from('credit_pack_purchases')
                        ->whereIn('client_site_id', $siteIds);
                })
                ->whereIn('status', ['open', 'pending'])
                ->sum('amount_cents'),
            'invoices_count' => (int) Invoice::query()
                ->where('organization_id', $organization?->id)
                ->where('document_type', 'invoice')
                ->whereIn('status', ['issued', 'refunded', 'paid'])
                ->whereNotNull('payment_intent_id')
                ->count(),
        ];

        $packs = CreditPack::query()
            ->where('is_active', true)
            ->orderBy('credits_amount')
            ->get();

        $plans = Plan::query()
            ->publiclyVisible()
            ->fixedBilling()
            ->where('interval', 'month')
            ->orderBy('sort_order')
            ->orderBy('price_cents')
            ->get();
        $checkoutPricing = app(SubscriptionCheckoutPricing::class);
        $planCheckoutSummaries = $plans->mapWithKeys(function (Plan $plan) use ($checkoutPricing): array {
            return [(string) $plan->id => $checkoutPricing->forInitialSubscription($plan)];
        });
        $preselectedPlanSlug = trim((string) $request->session()->get('billing.selected_plan_slug', ''));
        $preselectedPlanId = null;
        if ($preselectedPlanSlug !== '') {
            $preselectedPlan = $plans->first(function (Plan $plan) use ($preselectedPlanSlug): bool {
                return (string) ($plan->slug ?? '') === $preselectedPlanSlug;
            });
            $preselectedPlanId = $preselectedPlan?->id;
        }

        // Filter + pagination query state is kept in the URL for shareable views.
        $workspaceIds = $sites->pluck('workspace_id')->filter()->unique()->values()->all();

        $ledgerData = $this->buildLedgerData($request, $siteIds, $workspaceIds, $siteMap);
        $paymentData = $this->buildPaymentData($request, $siteIds, $siteMap);
        $subscriptionData = $this->buildSubscriptionData($request, $siteIds, $siteMap);

        return view('app.billing.index', [
            'organization' => $organization,
            'activeSubscription' => $activeSubscription,
            'currentSubscription' => $currentSubscription,
            'canBuyPacks' => $activeSubscription !== null || $earlyBirdActive,
            'organizationAccess' => [
                'label' => $organization ? $access->label($organization) : 'Free',
                'badge_classes' => $organization ? $access->badgeClasses($organization) : 'border-border bg-background text-textSecondary',
                'is_early_bird_active' => $earlyBirdActive,
                'is_early_bird_expired' => $earlyBirdExpired,
                'early_bird_ends_at' => $organization?->early_bird_ends_at,
            ],
            'sites' => $sites,
            'wallets' => $wallets,
            'totals' => $totals,
            'packs' => $packs,
            'plans' => $plans,
            'workspaceSummaries' => $workspaceSummaries,
            'planCheckoutSummaries' => $planCheckoutSummaries,
            'preselectedPlanId' => $preselectedPlanId,
            'scheduledPlanChange' => $scheduledPlanChange,
            'pendingImmediatePlanChange' => $pendingImmediatePlanChange,
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
        ]);
    }

    public function allocateSiteCredits(Request $request, SiteCreditAllocationService $allocations): RedirectResponse
    {
        Gate::authorize('manage-organization');

        $organization = $request->user()->organization;
        $siteIds = $organization?->clientSites()->pluck('client_sites.id')->all() ?? [];

        $data = $request->validate([
            'client_site_id' => ['required', 'string', 'in:' . implode(',', $siteIds ?: ['__none__'])],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        $allocations->allocateToSite((string) $data['client_site_id'], (int) $data['amount'], $request->user()->id, [
            'trigger' => 'app_billing_ui',
        ]);

        return back()->with('status', 'Site allocation updated.');
    }

    public function reclaimSiteCredits(Request $request, SiteCreditAllocationService $allocations): RedirectResponse
    {
        Gate::authorize('manage-organization');

        $organization = $request->user()->organization;
        $siteIds = $organization?->clientSites()->pluck('client_sites.id')->all() ?? [];

        $data = $request->validate([
            'client_site_id' => ['required', 'string', 'in:' . implode(',', $siteIds ?: ['__none__'])],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        $allocations->reclaimFromSite((string) $data['client_site_id'], (int) $data['amount'], $request->user()->id, [
            'trigger' => 'app_billing_ui',
        ]);

        return back()->with('status', 'Credits reclaimed back to the workspace pool.');
    }

    public function transferSiteCredits(Request $request, SiteCreditAllocationService $allocations): RedirectResponse
    {
        Gate::authorize('manage-organization');

        $organization = $request->user()->organization;
        $siteIds = $organization?->clientSites()->pluck('client_sites.id')->all() ?? [];

        $data = $request->validate([
            'from_client_site_id' => ['required', 'string', 'in:' . implode(',', $siteIds ?: ['__none__'])],
            'to_client_site_id' => ['required', 'string', 'different:from_client_site_id', 'in:' . implode(',', $siteIds ?: ['__none__'])],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        $allocations->transfer(
            (string) $data['from_client_site_id'],
            (string) $data['to_client_site_id'],
            (int) $data['amount'],
            $request->user()->id,
            ['trigger' => 'app_billing_ui']
        );

        return back()->with('status', 'Credits transferred between sites.');
    }

    public function purchasePack(
        Request $request,
        CreditPackPurchaseService $purchaseService,
        PackCheckoutService $checkoutService
    ): RedirectResponse {
        Gate::authorize('manage-organization');

        if ($redirect = $this->ensureBillingOnboardingCompleted($request)) {
            return $redirect;
        }

        $organization = $request->user()->organization;
        $siteIds = $organization?->clientSites()->pluck('client_sites.id')->all() ?? [];

        $data = $request->validate([
            'client_site_id' => ['required', 'string', 'in:' . implode(',', $siteIds ?: ['__none__'])],
            'pack_key' => ['required', 'string', 'max:100'],
        ]);

        try {
            $purchase = $purchaseService->createPending(
                clientSiteId: (string) $data['client_site_id'],
                packKey: (string) $data['pack_key'],
                organization: $organization,
                actor: $request->user(),
            );
            $intent = $checkoutService->createCheckout($purchase);

            return redirect()->away((string) $intent->checkout_url);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['billing' => $exception->getMessage()]);
        }
    }

    public function startSubscription(
        Request $request,
        SubscriptionLifecycleService $lifecycle
    ): RedirectResponse {
        Gate::authorize('manage-organization');

        if ($redirect = $this->ensureBillingOnboardingCompleted($request)) {
            return $redirect;
        }

        $organization = $request->user()->organization;
        if (! $organization) {
            return back()->withErrors(['billing' => 'No organization found.']);
        }

        $data = $request->validate([
            'plan_id' => ['required', 'string'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:64'],
            'city' => ['nullable', 'string', 'max:128'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'vat_number' => ['nullable', 'string', 'max:64'],
            'kvk_number' => ['nullable', 'string', 'max:64'],
        ]);

        $plan = Plan::query()
            ->where('id', $data['plan_id'])
            ->publiclyVisible()
            ->fixedBilling()
            ->where('interval', 'month')
            ->first();
        if (! $plan) {
            return back()->withErrors(['billing' => 'Selected plan is invalid.']);
        }

        try {
            Log::info('billing.subscription.start.requested', [
                'organization_id' => $organization->id,
                'user_id' => $request->user()?->id,
                'plan_id' => (string) $plan->id,
                'plan_slug' => (string) ($plan->slug ?? ''),
                'plan_key' => (string) ($plan->key ?? ''),
            ]);

            $result = $lifecycle->startSignup($organization, $plan, $data);
            $intent = $result['payment_intent'];

            Log::info('billing.subscription.start.checkout_created', [
                'organization_id' => $organization->id,
                'user_id' => $request->user()?->id,
                'plan_id' => (string) $plan->id,
                'payment_intent_id' => (string) $intent->id,
                'provider' => (string) $intent->provider,
                'provider_payment_id' => (string) ($intent->provider_payment_id ?? ''),
                'subscription_id' => (string) ($result['subscription']->id ?? ''),
            ]);

            if (! $intent->checkout_url) {
                return back()->withErrors(['billing' => 'No checkout URL returned by payment provider.']);
            }

            return redirect()->away((string) $intent->checkout_url);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['billing' => $exception->getMessage()]);
        }
    }

    public function changePlan(
        ChangeSubscriptionPlanRequest $request,
        PlanChangeService $planChanges,
        SubscriptionService $subscriptions
    ): RedirectResponse {
        Gate::authorize('manage-organization');

        if ($redirect = $this->ensureBillingOnboardingCompleted($request)) {
            return $redirect;
        }

        $organization = $request->user()->organization;
        if (! $organization) {
            return back()->withErrors(['billing' => 'No organization found.']);
        }

        $subscription = $subscriptions->getActiveForOrganization($organization);
        if (! $subscription) {
            return back()->withErrors(['billing' => 'Active subscription required to change plan.']);
        }

        $data = $request->validated();
        $timing = $request->timing();

        $toPlan = Plan::query()
            ->where('id', $data['to_plan_id'])
            ->publiclyVisible()
            ->fixedBilling()
            ->where('interval', 'month')
            ->first();
        if (! $toPlan) {
            return back()->withErrors(['billing' => 'Target plan not found.']);
        }

        try {
            $result = $planChanges->requestChange($subscription, $toPlan, $timing);
            $intent = $result['payment_intent'];

            if ($intent && $intent->checkout_url) {
                return redirect()->away((string) $intent->checkout_url);
            }

            return back()->with('status', $timing->isImmediateProrated()
                ? 'Plan changed successfully.'
                : 'Plan change scheduled for next billing period.');
        } catch (RuntimeException $exception) {
            return back()->withErrors(['billing' => $exception->getMessage()]);
        }
    }

    public function updateBillingProfile(Request $request): RedirectResponse
    {
        Gate::authorize('manage-organization');

        $organization = $request->user()->organization;
        if (! $organization) {
            return back()->withErrors(['billing' => 'No organization found.']);
        }

        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:64'],
            'city' => ['nullable', 'string', 'max:128'],
            'country_code' => ['required', 'string', 'size:2'],
            'vat_number' => ['nullable', 'string', 'max:64'],
            'kvk_number' => ['nullable', 'string', 'max:64'],
        ]);

        $organization->billing_company_name = (string) $data['company_name'];
        $organization->billing_address_line1 = (string) $data['address_line1'];
        $organization->billing_address_line2 = $data['address_line2'] ?: null;
        $organization->billing_postal_code = $data['postal_code'] ?: null;
        $organization->billing_city = $data['city'] ?: null;
        $organization->billing_country_code = strtoupper((string) $data['country_code']);
        $organization->billing_vat_number = $data['vat_number'] ?: null;
        $organization->billing_kvk_number = $data['kvk_number'] ?: null;
        $organization->billing_address = [
            'line1' => $data['address_line1'],
            'line2' => $data['address_line2'] ?: null,
            'postal_code' => $data['postal_code'] ?: null,
            'city' => $data['city'] ?: null,
            'country_code' => strtoupper((string) $data['country_code']),
        ];
        $organization->save();

        return back()->with('status', 'Billing details updated.');
    }

    private function buildLedgerData(Request $request, array $siteIds, array $workspaceIds, Collection $siteMap): array
    {
        $filters = [
            'site' => (string) $request->query('ledger_site', ''),
            'type' => (string) $request->query('ledger_type', ''),
            'from' => (string) $request->query('ledger_from', ''),
            'to' => (string) $request->query('ledger_to', ''),
            'q' => trim((string) $request->query('ledger_q', '')),
        ];

        $query = WorkspaceCreditTransaction::query()
            ->where(function (Builder $builder) use ($siteIds, $workspaceIds): void {
                $builder->whereIn('client_site_id', $siteIds);

                if ($workspaceIds !== []) {
                    $builder->orWhere(function (Builder $workspaceQuery) use ($workspaceIds): void {
                        $workspaceQuery
                            ->whereNull('client_site_id')
                            ->whereIn('workspace_id', $workspaceIds);
                    });
                }
            });

        if ($filters['site'] !== '' && in_array($filters['site'], $siteIds, true)) {
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
                    'note' => (string) ($meta['note'] ?? ''),
                    'source_type' => (string) ($entry->reference_type ?? ''),
                    'source_id' => (string) ($entry->reference_id ?? ''),
                    'brief_id' => '',
                    'user_id' => '',
                    'meta' => $meta,
                ];
            })
            ->withQueryString();

        return [
            'filters' => $filters,
            'rows' => $rows,
        ];
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

    private function buildPaymentData(Request $request, array $siteIds, Collection $siteMap): array
    {
        $organizationId = (int) ($request->user()?->organization_id ?? 0);

        $providers = PaymentIntent::query()
            ->where(function (Builder $builder) use ($siteIds, $organizationId): void {
                $builder->where(function (Builder $pack) use ($siteIds): void {
                    $pack->where('billable_type', CreditPackPurchase::class)
                        ->whereIn('billable_id', function ($query) use ($siteIds) {
                            $query->select('id')
                                ->from('credit_pack_purchases')
                                ->whereIn('client_site_id', $siteIds);
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
            ->where(function (Builder $builder) use ($siteIds, $organizationId): void {
                $builder->where(function (Builder $pack) use ($siteIds): void {
                    $pack->where('billable_type', CreditPackPurchase::class)
                        ->whereIn('billable_id', function ($subQuery) use ($siteIds) {
                            $subQuery->select('id')
                                ->from('credit_pack_purchases')
                                ->whereIn('client_site_id', $siteIds);
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

        if ($filters['provider'] !== '' && $providers->contains($filters['provider'])) {
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
                    'meta' => is_array($payment->meta) ? $payment->meta : [],
                    'invoice_id' => (string) ($payment->invoice?->id ?? ''),
                    'invoice_number' => (string) ($payment->invoice?->number ?? ''),
                    'invoice_status' => (string) ($payment->invoice?->status ?? ''),
                ];
            })
            ->withQueryString();

        return [
            'filters' => $filters,
            'providers' => $providers,
            'rows' => $rows,
        ];
    }

    private function buildSubscriptionData(Request $request, array $siteIds, Collection $siteMap): array
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
            ->whereIn('client_site_id', $siteIds);

        if ($filters['site'] !== '' && in_array($filters['site'], $siteIds, true)) {
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

    private function isValidDate(string $value): bool
    {
        try {
            Carbon::parse($value);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function ensureBillingOnboardingCompleted(Request $request): ?RedirectResponse
    {
        if ($request->routeIs('app.billing.subscription.start')) {
            return null;
        }

        if (app(SubscriptionService::class)->allowsBillingBypassForUser($request->user())) {
            return null;
        }

        $organization = $request->user()?->organization;

        if ($organization && ! $organization->hasCompleteBillingDetails()) {
            return redirect()
                ->route('app.onboarding.company.show')
                ->withErrors([
                    'billing' => 'Vul eerst je bedrijfsgegevens in om door te gaan.',
                ]);
        }

        return null;
    }
}
