<?php

namespace App\Services;

use App\Models\CreditPackPurchase;
use App\Models\Invoice;
use App\Models\PaymentIntent;
use App\Models\Subscription;
use App\Models\SubscriptionPlanChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceCreatorService
{
    public function __construct(private readonly InvoiceService $invoices)
    {
    }

    /**
     * @param array<string,mixed> $providerStatus
     */
    public function resolveOrCreateIntentFromMolliePayment(
        string $providerPaymentId,
        array $providerStatus,
        ?int $organizationId = null
    ): ?PaymentIntent {
        $providerPaymentId = trim($providerPaymentId);
        if ($providerPaymentId === '') {
            return null;
        }

        $existing = PaymentIntent::query()
            ->where('provider', 'mollie')
            ->where('provider_payment_id', $providerPaymentId)
            ->orderByDesc('created_at')
            ->first();
        if ($existing) {
            return $this->syncIntentStatus($existing, $providerStatus);
        }

        $resolution = $this->resolveBillableFromProviderStatus($providerStatus, $providerPaymentId, $organizationId);
        if (! $resolution) {
            return null;
        }

        /** @var Model $billable */
        $billable = $resolution['billable'];
        $purpose = $resolution['purpose'];

        return DB::transaction(function () use ($providerPaymentId, $providerStatus, $billable, $purpose): PaymentIntent {
            $intent = PaymentIntent::query()
                ->where('provider', 'mollie')
                ->where('provider_payment_id', $providerPaymentId)
                ->where('billable_type', $billable::class)
                ->where('billable_id', (string) $billable->getKey())
                ->lockForUpdate()
                ->first();

            if (! $intent) {
                [$amountCents, $currency] = $this->resolveAmountAndCurrency($providerStatus, $billable);

                $meta = is_array($providerStatus['metadata'] ?? null)
                    ? $providerStatus['metadata']
                    : [];
                $meta['purpose'] = $meta['purpose'] ?? $purpose;
                $meta['recovered_from_webhook'] = true;

                $intent = PaymentIntent::query()->create([
                    'id' => (string) Str::uuid(),
                    'billable_type' => $billable::class,
                    'billable_id' => (string) $billable->getKey(),
                    'provider' => 'mollie',
                    'status' => (string) ($providerStatus['status'] ?? 'paid'),
                    'amount_cents' => $amountCents,
                    'currency' => $currency,
                    'provider_payment_id' => $providerPaymentId,
                    'idempotency_key' => 'recovered:mollie:' . $providerPaymentId,
                    'last_provider_status' => (string) ($providerStatus['status'] ?? ''),
                    'paid_at' => $this->isPaid($providerStatus) ? now() : null,
                    'meta' => $meta,
                ]);
            }

            return $this->syncIntentStatus($intent, $providerStatus);
        });
    }

    /**
     * @param array<string,mixed> $providerStatus
     */
    public function ensureInvoiceForPaidIntent(PaymentIntent $intent, array $providerStatus): ?Invoice
    {
        $intent = $this->syncIntentStatus($intent, $providerStatus);

        if (! $this->isPaid($providerStatus) && ! in_array((string) $intent->status, ['paid', 'settled'], true)) {
            return null;
        }

        if ($intent->invoice) {
            return $intent->invoice;
        }

        return $this->invoices->createForPaymentIntent($intent->fresh('billable') ?? $intent);
    }

    /**
     * @param array<string,mixed> $providerStatus
     */
    private function syncIntentStatus(PaymentIntent $intent, array $providerStatus): PaymentIntent
    {
        $intent->status = (string) ($providerStatus['status'] ?? $intent->status);
        $intent->last_provider_status = (string) ($providerStatus['status'] ?? $intent->last_provider_status);
        if ($this->isPaid($providerStatus)) {
            $intent->paid_at = $intent->paid_at ?: now();
        }
        if (! empty($providerStatus['is_failed'])) {
            $intent->failed_at = $intent->failed_at ?: now();
        }
        if (! empty($providerStatus['is_canceled']) || ! empty($providerStatus['is_expired'])) {
            $intent->canceled_at = $intent->canceled_at ?: now();
        }
        $intent->save();

        return $intent;
    }

    /**
     * @param array<string,mixed> $providerStatus
     * @return array{billable:Model,purpose:string}|null
     */
    private function resolveBillableFromProviderStatus(array $providerStatus, string $providerPaymentId, ?int $organizationId): ?array
    {
        $meta = is_array($providerStatus['metadata'] ?? null)
            ? $providerStatus['metadata']
            : [];
        $purpose = (string) ($meta['purpose'] ?? '');

        if ($purpose === 'credit_pack' && ! empty($meta['purchase_id'])) {
            $purchase = CreditPackPurchase::query()
                ->whereKey((string) $meta['purchase_id'])
                ->first();
            if ($purchase && $this->matchesOrganization($purchase, $organizationId)) {
                return ['billable' => $purchase, 'purpose' => 'credit_pack'];
            }
        }

        if ($purpose === 'plan_change_adjustment' && ! empty($meta['plan_change_id'])) {
            $change = SubscriptionPlanChange::query()
                ->whereKey((string) $meta['plan_change_id'])
                ->first();
            if ($change && ($organizationId === null || (int) $change->organization_id === $organizationId)) {
                return ['billable' => $change, 'purpose' => 'plan_change_adjustment'];
            }
        }

        if (str_starts_with($purpose, 'subscription') && ! empty($meta['subscription_id'])) {
            $subscription = Subscription::query()
                ->whereKey((string) $meta['subscription_id'])
                ->first();
            if ($subscription && ($organizationId === null || (int) $subscription->organization_id === $organizationId)) {
                return ['billable' => $subscription, 'purpose' => $purpose !== '' ? $purpose : 'subscription_renewal'];
            }
        }

        $providerSubscriptionId = trim((string) ($providerStatus['provider_subscription_id'] ?? ''));
        if ($providerSubscriptionId !== '') {
            $subscription = Subscription::query()
                ->where('provider_subscription_id', $providerSubscriptionId)
                ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
                ->orderByDesc('updated_at')
                ->first();
            if ($subscription) {
                return ['billable' => $subscription, 'purpose' => $purpose !== '' ? $purpose : 'subscription_renewal'];
            }
        }

        $purchaseByProviderPayment = CreditPackPurchase::query()
            ->where('provider_payment_id', $providerPaymentId)
            ->orderByDesc('updated_at')
            ->first();
        if ($purchaseByProviderPayment && $this->matchesOrganization($purchaseByProviderPayment, $organizationId)) {
            return ['billable' => $purchaseByProviderPayment, 'purpose' => 'credit_pack'];
        }

        return null;
    }

    private function matchesOrganization(CreditPackPurchase $purchase, ?int $organizationId): bool
    {
        if ($organizationId === null) {
            return true;
        }

        $purchase->loadMissing('clientSite.workspace');

        return (int) ($purchase->clientSite?->workspace?->organization_id ?? 0) === $organizationId;
    }

    /**
     * @param array<string,mixed> $providerStatus
     * @return array{0:int,1:string}
     */
    private function resolveAmountAndCurrency(array $providerStatus, Model $billable): array
    {
        $currency = strtoupper(trim((string) data_get($providerStatus, 'amount.currency', '')));
        $value = trim((string) data_get($providerStatus, 'amount.value', ''));

        if ($currency !== '' && $value !== '' && is_numeric($value)) {
            return [(int) round(((float) $value) * 100), $currency];
        }

        if ($billable instanceof CreditPackPurchase) {
            return [(int) $billable->price_cents, (string) $billable->currency];
        }

        if ($billable instanceof Subscription) {
            $metadataTotal = (int) data_get($providerStatus, 'metadata.total_due_today_cents', 0);

            return [
                $metadataTotal > 0 ? $metadataTotal : (int) $billable->price_cents,
                (string) $billable->currency,
            ];
        }

        if ($billable instanceof SubscriptionPlanChange) {
            return [(int) ($billable->proration_amount_cents ?? 0), (string) ($billable->currency ?: 'EUR')];
        }

        return [0, 'EUR'];
    }

    /**
     * @param array<string,mixed> $providerStatus
     */
    private function isPaid(array $providerStatus): bool
    {
        if (! empty($providerStatus['is_paid'])) {
            return true;
        }

        return in_array((string) ($providerStatus['status'] ?? ''), ['paid', 'settled'], true);
    }
}
