<?php

namespace App\Services;

use App\Models\Account;
use App\Models\BillingInvoice;
use App\Models\CreditBalance;
use App\Models\CreditTransaction;
use App\Models\CreditUsageStat;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscriptions\ModuleAccessService;
use Illuminate\Support\Collection;

class CommercialOperationsService
{
    public function __construct(
        private readonly CreditService $credits,
        private readonly EntitlementService $entitlements,
        private readonly ModuleAccessService $moduleAccess,
        private readonly DomainEventService $events,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(?Account $account = null): array
    {
        return [
            'stats' => [
                'plans' => Plan::query()->count(),
                'modules' => Module::query()->count(),
                'active_subscriptions' => Subscription::query()->active()->count(),
                'open_invoices' => BillingInvoice::query()->whereIn('status', ['draft', 'open', 'failed'])->count(),
                'credits_used' => (int) CreditUsageStat::query()->sum('credits_used'),
                'low_credit_accounts' => CreditBalance::query()->where('balance', '<=', 100)->count(),
            ],
            'plans' => Plan::query()->withCount('subscriptions')->with('modules')->orderBy('amount')->get(),
            'modules' => Module::query()->withCount('subscriptionModules')->orderBy('name')->get(),
            'accounts' => Account::query()->with(['activeSubscription.plan', 'creditBalance'])->orderBy('name')->limit(50)->get(),
            'usage' => $this->usageRows($account),
            'invoices' => BillingInvoice::query()->with(['account', 'subscription.plan'])->latest('issued_at')->latest()->limit(20)->get(),
            'entitlements' => $account ? $this->entitlementRows($account) : collect(),
            'billingReport' => $this->billingReport($account),
        ];
    }

    public function createInvoice(Account $account, ?Subscription $subscription = null, array $lineItems = [], string $status = 'open'): BillingInvoice
    {
        $subscription ??= $account->activeSubscription()->with('plan')->first();
        $lineItems = $lineItems ?: $this->defaultLineItems($account, $subscription);
        $subtotal = collect($lineItems)->sum(fn (array $item) => (int) ($item['amount'] ?? 0));
        $tax = (int) round($subtotal * 0.21);

        $invoice = BillingInvoice::query()->create([
            'account_id' => $account->id,
            'subscription_id' => $subscription?->id,
            'provider' => 'mollie',
            'status' => $status,
            'currency' => $subscription?->currency ?? 'EUR',
            'subtotal_amount' => $subtotal,
            'tax_amount' => $tax,
            'total_amount' => $subtotal + $tax,
            'period_start' => $subscription?->current_period_starts_at?->toDateString() ?? now()->startOfMonth()->toDateString(),
            'period_end' => $subscription?->current_period_ends_at?->toDateString() ?? now()->endOfMonth()->toDateString(),
            'line_items' => $lineItems,
            'issued_at' => now(),
            'due_at' => now()->addDays(14),
            'metadata' => ['source' => 'commercial_operations'],
        ]);

        $this->events->record('BillingInvoiceCreated', $account, null, $invoice, null, [
            'invoice_id' => $invoice->id,
            'total_amount' => $invoice->total_amount,
            'provider' => 'mollie',
        ], dispatch: false);

        return $invoice;
    }

    public function recordOverage(Account $account, string $limitKey, int $usage, int $limit, ?User $user = null): ?CreditTransaction
    {
        if ($usage <= $limit) {
            return null;
        }

        $overage = $usage - $limit;
        $credits = max(1, $overage * (int) config('billing.overage.credit_cost_per_unit', 10));
        $transaction = $this->credits->grant($account, -$credits, $user, "Overage charge for {$limitKey}", [
            'overage' => true,
            'limit_key' => $limitKey,
            'usage' => $usage,
            'limit' => $limit,
            'units' => $overage,
        ]);

        $this->events->recordForSubject('CommercialOverageRecorded', $transaction, $user, [
            'limit_key' => $limitKey,
            'units' => $overage,
            'credits' => $credits,
        ], dispatch: false);

        return $transaction;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function entitlementRows(Account $account): Collection
    {
        $limits = ['brands', 'competitors', 'credits'];

        return collect($limits)->map(fn (string $limit) => [
            'limit' => $limit,
            'value' => $this->entitlements->getLimit($account, $limit),
            'remaining' => $this->entitlements->getRemaining($account, $limit),
        ])->merge(
            Module::query()->where('is_active', true)->orderBy('key')->get()->map(fn (Module $module) => [
                'feature' => $module->key,
                'access' => $this->entitlements->hasAccess($account, $module->key),
                'module_active' => $this->moduleAccess->accountHasModule($account, $module->key),
            ]),
        )->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function usageRows(?Account $account = null): Collection
    {
        return CreditUsageStat::query()
            ->with(['account', 'brand'])
            ->when($account, fn ($query) => $query->where('account_id', $account->id))
            ->latest('period_start')
            ->limit(50)
            ->get()
            ->map(fn (CreditUsageStat $usage) => [
                'account' => $usage->account?->name,
                'brand' => $usage->brand?->name,
                'catalog_code' => $usage->catalog_code,
                'credits_used' => $usage->credits_used,
                'executions' => $usage->executions,
                'period' => $usage->period_start?->format('Y-m'),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function billingReport(?Account $account = null): array
    {
        $invoices = BillingInvoice::query()
            ->when($account, fn ($query) => $query->where('account_id', $account->id));

        return [
            'invoice_count' => (clone $invoices)->count(),
            'open_amount' => (int) (clone $invoices)->whereIn('status', ['draft', 'open', 'failed'])->sum('total_amount'),
            'paid_amount' => (int) (clone $invoices)->where('status', 'paid')->sum('total_amount'),
            'mollie_invoices' => (clone $invoices)->where('provider', 'mollie')->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function defaultLineItems(Account $account, ?Subscription $subscription): array
    {
        $plan = $subscription?->plan;
        $usage = (int) CreditUsageStat::query()->where('account_id', $account->id)->whereBetween('period_start', [now()->startOfMonth(), now()->endOfMonth()])->sum('credits_used');

        return array_values(array_filter([
            $plan ? [
                'description' => $plan->name.' subscription',
                'quantity' => 1,
                'amount' => $subscription->amount,
            ] : null,
            [
                'description' => 'Credit usage this period',
                'quantity' => $usage,
                'amount' => 0,
            ],
        ]));
    }
}
