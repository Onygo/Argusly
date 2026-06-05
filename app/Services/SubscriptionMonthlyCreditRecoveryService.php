<?php

namespace App\Services;

use App\Models\ClientSite;
use App\Models\CreditLedgerEntry;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\CreditPolicyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SubscriptionMonthlyCreditRecoveryService
{
    public function __construct(
        private readonly CreditWalletService $wallets,
        private readonly SubscriptionService $subscriptions,
        private readonly CreditPolicyService $creditPolicy
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function recoverForOrganization(
        Organization $organization,
        ?Carbon $period = null,
        bool $dryRun = false,
        ?User $adminUser = null,
        string $trigger = 'artisan',
        bool $requirePaidSignal = false
    ): array {
        $subscription = $this->subscriptions->getActiveForOrganization($organization);

        if (! $subscription) {
            return [
                'ok' => false,
                'action' => 'error',
                'reason' => 'No active subscription found for this organization.',
                'organization_id' => (string) $organization->id,
            ];
        }

        return $this->recoverForSubscription(
            subscription: $subscription,
            period: $period,
            dryRun: $dryRun,
            adminUser: $adminUser,
            trigger: $trigger,
            requirePaidSignal: $requirePaidSignal
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function recoverForSubscription(
        Subscription $subscription,
        ?Carbon $period = null,
        bool $dryRun = false,
        ?User $adminUser = null,
        string $trigger = 'artisan',
        bool $requirePaidSignal = false
    ): array {
        $subscription->loadMissing(['plan', 'organization']);

        $clientSiteId = $this->resolveClientSiteId($subscription);
        if ($clientSiteId === '') {
            return [
                'ok' => false,
                'action' => 'error',
                'reason' => 'No client site available for subscription wallet crediting.',
                'subscription_id' => (string) $subscription->id,
                'organization_id' => (string) ($subscription->organization_id ?? ''),
            ];
        }

        $amount = $subscription->monthlyCredits();
        if ($amount <= 0) {
            return [
                'ok' => true,
                'action' => 'skipped',
                'reason' => 'Active plan has no monthly credits configured.',
                'subscription_id' => (string) $subscription->id,
                'organization_id' => (string) ($subscription->organization_id ?? ''),
                'client_site_id' => $clientSiteId,
                'amount' => 0,
            ];
        }

        [$periodStart, $periodEnd, $inferredPeriod] = $this->resolvePeriod($subscription, $period);
        $providerPaymentId = $this->resolveProviderPaymentId($subscription, $periodStart, $periodEnd);

        if ($requirePaidSignal && ! $this->hasPaidSignal($subscription, $periodStart, $periodEnd, $providerPaymentId)) {
            return [
                'ok' => true,
                'action' => 'skipped',
                'reason' => 'No paid subscription payment evidence for this billing period.',
                'subscription_id' => (string) $subscription->id,
                'organization_id' => (string) ($subscription->organization_id ?? ''),
                'client_site_id' => $clientSiteId,
                'amount' => $amount,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
            ];
        }

        $existingEntry = $this->findExistingGrantEntry(
            subscription: $subscription,
            periodStart: $periodStart,
            providerPaymentId: $providerPaymentId
        );

        $walletBefore = $this->wallets->getSummary($clientSiteId);

        if ($existingEntry) {
            return [
                'ok' => true,
                'action' => 'already_granted',
                'reason' => 'Credits already granted for this billing period.',
                'subscription_id' => (string) $subscription->id,
                'organization_id' => (string) ($subscription->organization_id ?? ''),
                'client_site_id' => $clientSiteId,
                'plan_id' => (string) ($subscription->plan_id ?? ''),
                'amount' => $amount,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'ledger_id' => (string) $existingEntry->id,
                'wallet_before_available' => (int) ($walletBefore['available'] ?? 0),
                'wallet_after_available' => (int) ($walletBefore['available'] ?? 0),
            ];
        }

        if ($dryRun) {
            return [
                'ok' => true,
                'action' => 'dry_run',
                'reason' => 'Dry run mode. No ledger entry written.',
                'subscription_id' => (string) $subscription->id,
                'organization_id' => (string) ($subscription->organization_id ?? ''),
                'client_site_id' => $clientSiteId,
                'plan_id' => (string) ($subscription->plan_id ?? ''),
                'amount' => $amount,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'inferred_period' => $inferredPeriod,
                'provider_payment_id' => $providerPaymentId,
                'wallet_before_available' => (int) ($walletBefore['available'] ?? 0),
                'wallet_after_available' => (int) ($walletBefore['available'] ?? 0),
            ];
        }

        $idempotencyKey = sprintf(
            'allowance:sub:%s:period:%s:recovery',
            (string) $subscription->id,
            $periodStart->format('Ymd')
        );

        $expiresAt = $this->creditPolicy->resolveSubscriptionGrantExpiryAt($subscription, $periodStart, $periodEnd);

        $workspaceId = (string) ($subscription->workspace_id ?: ClientSite::query()->whereKey($clientSiteId)->value('workspace_id'));

        $entry = $this->wallets->addWorkspaceCredits(
            workspaceId: $workspaceId,
            amount: $amount,
            type: CreditWalletService::TYPE_ALLOWANCE,
            meta: array_filter([
                'ledger_type' => 'subscription_recovery_grant',
                'reason' => 'Manual recovery after payment fix',
                'admin_user_id' => $adminUser?->id,
                'plan_id' => (string) ($subscription->plan_id ?? ''),
                'subscription_id' => (string) $subscription->id,
                'provider' => (string) ($subscription->provider ?: 'mollie'),
                'provider_payment_id' => $providerPaymentId,
                'mollie_payment_id' => (string) ($subscription->provider === 'mollie' ? $providerPaymentId : ''),
                'period_start' => $periodStart->toIso8601String(),
                'period_end' => $periodEnd->toIso8601String(),
                'inferred_period' => $inferredPeriod,
                'trigger' => $trigger,
            ], static fn ($value): bool => $value !== null && $value !== ''),
            sourceType: Subscription::class,
            sourceId: (string) $subscription->id,
            expiresAt: $expiresAt,
            idempotencyKey: $idempotencyKey,
            preferredClientSiteId: $clientSiteId
        );

        $walletAfter = $this->wallets->getSummary($clientSiteId);

        Log::info('subscription_credit_recovery_grant', [
            'organization_id' => (string) ($subscription->organization_id ?? ''),
            'subscription_id' => (string) $subscription->id,
            'client_site_id' => $clientSiteId,
            'amount' => $amount,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'ledger_id' => (string) ($entry?->id ?? $idempotencyKey),
            'admin_user_id' => $adminUser?->id,
            'trigger' => $trigger,
            'provider_payment_id' => $providerPaymentId,
        ]);

        return [
            'ok' => true,
            'action' => 'granted',
            'reason' => 'Monthly credits granted successfully.',
            'subscription_id' => (string) $subscription->id,
            'organization_id' => (string) ($subscription->organization_id ?? ''),
            'client_site_id' => $clientSiteId,
            'plan_id' => (string) ($subscription->plan_id ?? ''),
            'amount' => $amount,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'inferred_period' => $inferredPeriod,
            'provider_payment_id' => $providerPaymentId,
                'ledger_id' => (string) ($entry?->id ?? $idempotencyKey),
            'wallet_before_available' => (int) ($walletBefore['available'] ?? 0),
            'wallet_after_available' => (int) ($walletAfter['available'] ?? 0),
        ];
    }

    /**
     * @return array<string,int>
     */
    public function backfillMissingForActiveSubscriptions(int $limit = 500): array
    {
        $subscriptions = Subscription::query()
            ->with(['plan', 'organization'])
            ->whereIn('status', ['active', 'trialing'])
            ->orderBy('updated_at')
            ->limit(max(1, $limit))
            ->get();

        $summary = [
            'scanned' => 0,
            'granted' => 0,
            'already_granted' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        foreach ($subscriptions as $subscription) {
            $summary['scanned']++;

            $result = $this->recoverForSubscription(
                subscription: $subscription,
                period: null,
                dryRun: false,
                adminUser: null,
                trigger: 'backfill_job',
                requirePaidSignal: true
            );

            $action = (string) ($result['action'] ?? 'error');
            if (! isset($summary[$action])) {
                $action = 'errors';
            }

            $summary[$action]++;
        }

        return $summary;
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: bool}
     */
    private function resolvePeriod(Subscription $subscription, ?Carbon $period): array
    {
        $interval = $this->normalizeInterval((string) ($subscription->interval ?: 'month'));

        if ($period !== null) {
            $start = $period->copy()->startOfMonth();
            $end = $start->copy()->addMonth()->startOfDay();

            return [$start, $end, false];
        }

        if ($subscription->current_period_start) {
            $start = $subscription->current_period_start->copy()->startOfDay();
            $end = $subscription->current_period_end
                ? $subscription->current_period_end->copy()->startOfDay()
                : $this->nextPeriodEnd($start, $interval);

            return [$start, $end, false];
        }

        if ($subscription->next_payment_at) {
            $end = $subscription->next_payment_at->copy()->startOfDay();
            $start = $interval === 'year'
                ? $end->copy()->subYear()->startOfDay()
                : $end->copy()->subMonth()->startOfDay();

            return [$start, $end, true];
        }

        $start = now()->startOfMonth();
        $end = $this->nextPeriodEnd($start, $interval);

        return [$start, $end, true];
    }

    private function normalizeInterval(string $interval): string
    {
        return strtolower(trim($interval)) === 'year' ? 'year' : 'month';
    }

    private function nextPeriodEnd(Carbon $start, string $interval): Carbon
    {
        return $interval === 'year'
            ? $start->copy()->addYear()->startOfDay()
            : $start->copy()->addMonth()->startOfDay();
    }

    private function resolveClientSiteId(Subscription $subscription): string
    {
        $clientSiteId = trim((string) ($subscription->client_site_id ?? ''));
        if ($clientSiteId !== '') {
            return $clientSiteId;
        }

        if ($subscription->workspace_id) {
            $workspaceSiteId = (string) ClientSite::query()
                ->where('workspace_id', $subscription->workspace_id)
                ->orderBy('created_at')
                ->value('id');

            if ($workspaceSiteId !== '') {
                return $workspaceSiteId;
            }
        }

        return (string) ClientSite::query()
            ->whereIn('workspace_id', function ($query) use ($subscription) {
                $query->select('id')
                    ->from('workspaces')
                    ->where('organization_id', $subscription->organization_id);
            })
            ->orderBy('created_at')
            ->value('id');
    }

    private function resolveProviderPaymentId(
        Subscription $subscription,
        Carbon $periodStart,
        Carbon $periodEnd
    ): ?string {
        $providerPaymentId = trim((string) ($subscription->provider_payment_id ?? ''));

        $paidIntent = PaymentIntent::query()
            ->where('billable_type', Subscription::class)
            ->where('billable_id', $subscription->id)
            ->where('status', 'paid')
            ->where(function ($query) use ($periodStart, $periodEnd, $providerPaymentId): void {
                $query->whereBetween('paid_at', [
                    $periodStart->copy()->subDays(2),
                    $periodEnd->copy()->addDays(2),
                ]);

                if ($providerPaymentId !== '') {
                    $query->orWhere('provider_payment_id', $providerPaymentId);
                }
            })
            ->orderByDesc('paid_at')
            ->orderByDesc('created_at')
            ->first(['provider_payment_id']);

        $resolved = trim((string) ($paidIntent?->provider_payment_id ?? ''));
        if ($resolved !== '') {
            return $resolved;
        }

        return $providerPaymentId !== '' ? $providerPaymentId : null;
    }

    private function hasPaidSignal(
        Subscription $subscription,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?string $providerPaymentId
    ): bool {
        return PaymentIntent::query()
            ->where('billable_type', Subscription::class)
            ->where('billable_id', $subscription->id)
            ->where('status', 'paid')
            ->where(function ($query) use ($periodStart, $periodEnd, $providerPaymentId): void {
                $query->whereBetween('paid_at', [
                    $periodStart->copy()->subDays(2),
                    $periodEnd->copy()->addDays(2),
                ]);

                if ($providerPaymentId) {
                    $query->orWhere('provider_payment_id', $providerPaymentId);
                }
            })
            ->exists();
    }

    private function findExistingGrantEntry(
        Subscription $subscription,
        Carbon $periodStart,
        ?string $providerPaymentId
    ): ?CreditLedgerEntry {
        $periodStartDate = $periodStart->copy()->startOfDay();

        $entries = CreditLedgerEntry::query()
            ->where('source_type', Subscription::class)
            ->where('source_id', (string) $subscription->id)
            ->where('type', CreditWalletService::TYPE_ALLOWANCE)
            ->where('amount', '>', 0)
            ->orderByDesc('created_at')
            ->get();

        foreach ($entries as $entry) {
            $samePeriod = false;

            if ($entry->period_start && $entry->period_start->copy()->startOfDay()->eq($periodStartDate)) {
                $samePeriod = true;
            } elseif (is_array($entry->meta)) {
                $metaStart = data_get($entry->meta, 'period_start');
                if (is_string($metaStart) && trim($metaStart) !== '') {
                    try {
                        $samePeriod = Carbon::parse($metaStart)->startOfDay()->eq($periodStartDate);
                    } catch (\Throwable) {
                        $samePeriod = false;
                    }
                }
            }

            if ($samePeriod) {
                return $entry;
            }

            if ($providerPaymentId !== null && $providerPaymentId !== '') {
                $references = array_filter([
                    (string) data_get($entry->meta, 'provider_payment_id', ''),
                    (string) data_get($entry->meta, 'mollie_payment_id', ''),
                    (string) data_get($entry->meta, 'reference', ''),
                    (string) ($entry->purchase_payment_id ?? ''),
                ], static fn (string $value): bool => trim($value) !== '');

                foreach ($references as $reference) {
                    if (hash_equals($reference, $providerPaymentId)) {
                        return $entry;
                    }
                }
            }
        }

        return null;
    }

}
