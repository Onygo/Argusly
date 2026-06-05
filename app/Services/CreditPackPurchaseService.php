<?php

namespace App\Services;

use App\Models\ClientSite;
use App\Models\CreditPack;
use App\Models\CreditPackPurchase;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\WorkspaceCreditTransaction;
use App\Services\Billing\CreditPolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CreditPackPurchaseService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly CreditPolicyService $creditPolicy
    )
    {
    }

    public function createPending(
        string $clientSiteId,
        string $packKey,
        ?Organization $organization = null,
        User|int|string|null $actor = null,
    ): CreditPackPurchase
    {
        $site = ClientSite::query()->with('workspace.organization')->find($clientSiteId);
        if (! $site || ! $site->workspace || ! $site->workspace->organization) {
            throw new RuntimeException('Client site not found for purchase.');
        }

        $siteOrganization = $site->workspace->organization;
        if ($organization && (int) $organization->id !== (int) $siteOrganization->id) {
            throw new RuntimeException('Client site does not belong to your organization.');
        }

        $this->subscriptions->assertOrganizationCanBuyCredits($siteOrganization, $actor);

        $pack = CreditPack::query()
            ->where('key', $packKey)
            ->where('is_active', true)
            ->first();

        if (! $pack) {
            throw new RuntimeException('Credit pack not found or inactive.');
        }

        return CreditPackPurchase::query()->create([
            'id' => (string) Str::uuid(),
            'client_site_id' => $clientSiteId,
            'credit_pack_id' => $pack->id,
            'status' => 'pending',
            'credits_amount' => (int) $pack->credits_amount,
            'price_cents' => (int) $pack->price_cents,
            'currency' => (string) $pack->currency,
            'provider' => null,
            'meta' => [
                'pack_key' => (string) $pack->key,
            ],
        ]);
    }

    public function markPaid(
        CreditPackPurchase $purchase,
        CreditWalletService $wallets,
        ?string $providerPaymentId = null
    ): CreditPackPurchase {
        if ($purchase->status === 'paid') {
            return $purchase;
        }

        if ($purchase->status !== 'pending') {
            throw new RuntimeException('Purchase is not pending.');
        }

        $idempotencyKey = 'pack_purchase:' . $purchase->id;

        return DB::transaction(function () use ($purchase, $wallets, $providerPaymentId, $idempotencyKey) {
            $purchase->refresh();

            if ($purchase->status === 'paid') {
                return $purchase;
            }

            $pack = $purchase->relationLoaded('creditPack')
                ? $purchase->creditPack
                : $purchase->creditPack()->first();

            $purchase->status = 'paid';
            $purchase->paid_at = now();
            $purchase->provider = $purchase->provider ?: 'mollie';
            $purchase->provider_payment_id = $providerPaymentId ?: $purchase->provider_payment_id;
            $expiresAt = $pack
                ? $this->creditPolicy->resolvePackExpiryAt($pack, $purchase->paid_at?->copy())
                : null;
            $purchase->purchased_credit_expires_at = $expiresAt;
            $purchase->save();

            $site = ClientSite::query()->with('workspace')->findOrFail((string) $purchase->client_site_id);

            $entry = $wallets->addWorkspaceCredits(
                workspaceId: (string) $site->workspace_id,
                amount: (int) $purchase->credits_amount,
                type: CreditWalletService::TYPE_PACK_PURCHASE,
                meta: [
                    'purchase_id' => (string) $purchase->id,
                    'credit_pack_id' => (string) $purchase->credit_pack_id,
                    'payment_intent_id' => (string) PaymentIntent::query()
                        ->where('billable_type', CreditPackPurchase::class)
                        ->where('billable_id', $purchase->id)
                        ->latest('created_at')
                        ->value('id'),
                ],
                sourceType: CreditPackPurchase::class,
                sourceId: (string) $purchase->id,
                expiresAt: $expiresAt,
                idempotencyKey: $idempotencyKey,
                preferredClientSiteId: (string) $purchase->client_site_id
            );

            $purchase->credit_ledger_entry_id = $entry?->id;
            $purchase->workspace_credit_transaction_id = WorkspaceCreditTransaction::query()
                ->where('idempotency_key', 'workspace:' . $idempotencyKey)
                ->value('id');
            $purchase->save();

            return $purchase;
        });
    }

    public function markFailed(CreditPackPurchase $purchase, string $reason): CreditPackPurchase
    {
        if (in_array($purchase->status, ['paid', 'refunded'], true)) {
            throw new RuntimeException('Cannot fail a paid/refunded purchase.');
        }

        $purchase->status = 'failed';
        $purchase->failed_at = now();
        $meta = is_array($purchase->meta) ? $purchase->meta : [];
        $meta['failed_reason'] = $reason;
        $purchase->meta = $meta;
        $purchase->save();

        return $purchase;
    }
}
