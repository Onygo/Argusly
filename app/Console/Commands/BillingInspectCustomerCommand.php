<?php

namespace App\Console\Commands;

use App\Models\CreditLedgerEntry;
use App\Models\CreditPackPurchase;
use App\Models\Invoice;
use App\Models\PaymentIntent;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Command;

class BillingInspectCustomerCommand extends Command
{
    protected $signature = 'billing:inspect-customer
        {--user= : User id or email}
        {--workspace= : Workspace id}
        {--limit=25 : Max records per section}';

    protected $description = 'Inspect billing links for a specific customer workspace/organization.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $userScope = trim((string) $this->option('user'));
        $workspaceScope = trim((string) $this->option('workspace'));

        if ($userScope === '' && $workspaceScope === '') {
            $this->error('Provide --user or --workspace.');

            return self::INVALID;
        }

        $user = null;
        $workspace = null;
        $organizationId = null;

        if ($userScope !== '') {
            $user = ctype_digit($userScope)
                ? User::query()->find((int) $userScope)
                : User::query()->where('email', $userScope)->first();

            if (! $user) {
                $this->error('No user found for --user scope.');

                return self::FAILURE;
            }

            $organizationId = (int) ($user->organization_id ?? 0);
        }

        if ($workspaceScope !== '') {
            $workspace = Workspace::query()->find($workspaceScope);

            if (! $workspace) {
                $this->error('No workspace found for --workspace scope.');

                return self::FAILURE;
            }

            $workspaceOrgId = (int) ($workspace->organization_id ?? 0);
            if ($organizationId !== null && $organizationId !== $workspaceOrgId) {
                $this->error('--user and --workspace resolve to different organizations.');

                return self::INVALID;
            }

            $organizationId = $workspaceOrgId;
        }

        if (! $organizationId) {
            $this->error('Unable to resolve organization from scope.');

            return self::FAILURE;
        }

        $clientSiteIds = Workspace::query()
            ->where('organization_id', $organizationId)
            ->with('clientSites:id,workspace_id')
            ->get()
            ->flatMap(fn (Workspace $ws) => $ws->clientSites->pluck('id'))
            ->values()
            ->all();

        $this->line('Organization ID: ' . $organizationId);
        if ($user) {
            $this->line('User: ' . (string) $user->id . ' <' . (string) $user->email . '>');
        }
        if ($workspace) {
            $this->line('Workspace: ' . (string) $workspace->id . ' (' . (string) $workspace->name . ')');
        }

        $subscriptions = Subscription::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get([
                'id',
                'workspace_id',
                'client_site_id',
                'status',
                'provider',
                'provider_customer_id',
                'provider_subscription_id',
                'provider_payment_id',
                'price_cents',
                'currency',
                'updated_at',
            ]);

        $this->newLine();
        $this->info('Subscriptions');
        $this->table(
            ['id', 'workspace', 'site', 'status', 'provider', 'cust', 'sub', 'last_pay', 'amount', 'updated_at'],
            $subscriptions->map(fn (Subscription $sub) => [
                (string) $sub->id,
                (string) ($sub->workspace_id ?? ''),
                (string) ($sub->client_site_id ?? ''),
                (string) $sub->status,
                (string) ($sub->provider ?? ''),
                (string) ($sub->provider_customer_id ?? ''),
                (string) ($sub->provider_subscription_id ?? ''),
                (string) ($sub->provider_payment_id ?? ''),
                number_format(((int) $sub->price_cents) / 100, 2) . ' ' . (string) $sub->currency,
                optional($sub->updated_at)->toDateTimeString(),
            ])->all()
        );

        $paymentIntents = PaymentIntent::query()
            ->where(function ($query) use ($organizationId, $clientSiteIds): void {
                $query->where(function ($subscription) use ($organizationId): void {
                    $subscription->where('billable_type', Subscription::class)
                        ->whereIn('billable_id', Subscription::query()
                            ->select('id')
                            ->where('organization_id', $organizationId));
                })->orWhere(function ($packs) use ($clientSiteIds): void {
                    $packs->where('billable_type', CreditPackPurchase::class)
                        ->whereIn('billable_id', CreditPackPurchase::query()
                            ->select('id')
                            ->whereIn('client_site_id', $clientSiteIds));
                });
            })
            ->with('invoice:id,payment_intent_id,organization_id,number,status')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get([
                'id',
                'billable_type',
                'billable_id',
                'status',
                'provider',
                'provider_payment_id',
                'amount_cents',
                'currency',
                'paid_at',
                'created_at',
            ]);

        $this->newLine();
        $this->info('Payment intents');
        $this->table(
            ['id', 'type', 'billable', 'status', 'provider', 'provider_payment_id', 'amount', 'paid_at', 'invoice'],
            $paymentIntents->map(fn (PaymentIntent $intent) => [
                (string) $intent->id,
                class_basename((string) $intent->billable_type),
                (string) $intent->billable_id,
                (string) $intent->status,
                (string) $intent->provider,
                (string) ($intent->provider_payment_id ?? ''),
                number_format(((int) $intent->amount_cents) / 100, 2) . ' ' . (string) $intent->currency,
                optional($intent->paid_at)->toDateTimeString(),
                (string) ($intent->invoice?->number ?? '-'),
            ])->all()
        );

        $invoices = Invoice::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('issued_at')
            ->limit($limit)
            ->get([
                'id',
                'number',
                'status',
                'payment_intent_id',
                'subscription_id',
                'credit_pack_purchase_id',
                'total_cents',
                'currency',
                'issued_at',
            ]);

        $this->newLine();
        $this->info('Invoices');
        $this->table(
            ['id', 'number', 'status', 'intent', 'subscription', 'pack', 'total', 'issued_at'],
            $invoices->map(fn (Invoice $invoice) => [
                (string) $invoice->id,
                (string) $invoice->number,
                (string) $invoice->status,
                (string) ($invoice->payment_intent_id ?? ''),
                (string) ($invoice->subscription_id ?? ''),
                (string) ($invoice->credit_pack_purchase_id ?? ''),
                number_format(((int) $invoice->total_cents) / 100, 2) . ' ' . (string) $invoice->currency,
                optional($invoice->issued_at)->toDateTimeString(),
            ])->all()
        );

        $walletEntries = CreditLedgerEntry::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get([
                'id',
                'client_site_id',
                'type',
                'amount',
                'source_type',
                'source_id',
                'purchase_payment_id',
                'created_at',
            ]);

        $this->newLine();
        $this->info('Ledger entries');
        $this->table(
            ['id', 'site', 'type', 'amount', 'source_type', 'source_id', 'purchase_payment_id', 'created_at'],
            $walletEntries->map(fn (CreditLedgerEntry $entry) => [
                (string) $entry->id,
                (string) ($entry->client_site_id ?? ''),
                (string) $entry->type,
                (string) $entry->amount,
                class_basename((string) ($entry->source_type ?? '')),
                (string) ($entry->source_id ?? ''),
                (string) ($entry->purchase_payment_id ?? ''),
                optional($entry->created_at)->toDateTimeString(),
            ])->all()
        );

        return self::SUCCESS;
    }
}
