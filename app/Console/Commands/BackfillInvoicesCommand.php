<?php

namespace App\Console\Commands;

use App\Billing\Providers\PaymentProviderRegistry;
use App\Models\CreditPackPurchase;
use App\Models\PaymentIntent;
use App\Models\Subscription;
use App\Models\SubscriptionPlanChange;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Workspace;
use App\Services\InvoiceCreatorService;
use App\Services\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BackfillInvoicesCommand extends Command
{
    protected $signature = 'billing:backfill-invoices
        {--from= : Include payments with paid/created date on or after this date (YYYY-MM-DD)}
        {--to= : Include payments with paid/created date on or before this date (YYYY-MM-DD)}
        {--org_id= : Restrict to one organization ID}
        {--user= : Restrict by user id or email (uses user organization)}
        {--workspace= : Restrict by workspace id (uses workspace organization)}
        {--dry-run : Scan and report only, no invoices created}
        {--limit=500 : Max payment intents to process}
        {--batch-id= : Optional explicit batch UUID}
        {--queue-pdf : Queue PDF generation jobs instead of inline generation}';

    protected $description = 'Backfill missing invoices for historical paid payments safely and idempotently.';

    public function handle(
        InvoiceService $invoices,
        PaymentProviderRegistry $providers,
        InvoiceCreatorService $invoiceCreator
    ): int
    {
        $from = $this->parseDate((string) $this->option('from'));
        $to = $this->parseDate((string) $this->option('to'));

        if ((string) $this->option('from') !== '' && ! $from) {
            $this->error('Invalid --from date, expected YYYY-MM-DD.');

            return self::INVALID;
        }

        if ((string) $this->option('to') !== '' && ! $to) {
            $this->error('Invalid --to date, expected YYYY-MM-DD.');

            return self::INVALID;
        }

        $orgIdOption = trim((string) $this->option('org_id'));
        $orgId = ctype_digit($orgIdOption) ? (int) $orgIdOption : null;

        if ($orgIdOption !== '' && $orgId === null) {
            $this->error('Invalid --org_id value.');

            return self::INVALID;
        }

        $userScope = trim((string) $this->option('user'));
        if ($userScope !== '') {
            $user = ctype_digit($userScope)
                ? User::query()->find((int) $userScope)
                : User::query()->where('email', $userScope)->first();

            if (! $user) {
                $this->error('No user found for --user scope.');

                return self::INVALID;
            }
            if (! $user->organization_id) {
                $this->error('Scoped user has no organization.');

                return self::INVALID;
            }

            if ($orgId !== null && $orgId !== (int) $user->organization_id) {
                $this->error('--org_id and --user resolve to different organizations.');

                return self::INVALID;
            }

            $orgId = (int) $user->organization_id;
        }

        $workspaceScope = trim((string) $this->option('workspace'));
        if ($workspaceScope !== '') {
            $workspace = Workspace::query()->find($workspaceScope);
            if (! $workspace) {
                $this->error('No workspace found for --workspace scope.');

                return self::INVALID;
            }
            if (! $workspace->organization_id) {
                $this->error('Scoped workspace has no organization.');

                return self::INVALID;
            }

            if ($orgId !== null && $orgId !== (int) $workspace->organization_id) {
                $this->error('--org_id and --workspace resolve to different organizations.');

                return self::INVALID;
            }

            $orgId = (int) $workspace->organization_id;
        }

        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $queuePdf = (bool) $this->option('queue-pdf');
        $batchId = (string) ($this->option('batch-id') ?: (string) Str::uuid());

        if (! Str::isUuid($batchId)) {
            $this->error('Invalid --batch-id value, expected UUID.');

            return self::INVALID;
        }

        $this->info(sprintf(
            'Invoice backfill started | batch=%s | dry_run=%s | limit=%d',
            $batchId,
            $dryRun ? 'yes' : 'no',
            $limit
        ));

        $runId = (string) Str::uuid();

        DB::table('invoice_backfill_runs')->insert([
            'id' => $runId,
            'batch_id' => $batchId,
            'dry_run' => $dryRun,
            'from_date' => $from?->toDateString(),
            'to_date' => $to?->toDateString(),
            'organization_id' => $orgId,
            'limit_count' => $limit,
            'queue_pdf' => $queuePdf,
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $summary = [
            'scanned' => 0,
            'eligible' => 0,
            'created' => 0,
            'skipped_already_invoiced' => 0,
            'skipped_refunded_or_chargeback' => 0,
            'skipped_missing_billing' => 0,
            'skipped_org_filter' => 0,
            'skipped_unsupported_type' => 0,
            'failed' => 0,
            'skipped_not_paid' => 0,
            'pdf_generated_ok' => 0,
            'pdf_failed' => 0,
            'pdf_queued' => 0,
        ];

        $reportRows = [];

        $base = PaymentIntent::query()
            ->where(function ($query) {
                $query->whereIn('status', ['paid', 'settled'])
                    ->orWhereIn('last_provider_status', ['paid', 'settled'])
                    ->orWhere(function ($q) {
                        $q->where('billable_type', CreditPackPurchase::class)
                            ->whereExists(function ($exists) {
                                $exists->selectRaw('1')
                                    ->from('credit_pack_purchases as cpp')
                                    ->whereColumn('cpp.id', 'payment_intents.billable_id')
                                    ->where('cpp.status', 'paid');
                            });
                    });
            })
            ->orderBy('created_at')
            ->orderBy('id');

        if ($from) {
            $base->whereRaw('DATE(COALESCE(paid_at, created_at)) >= ?', [$from->toDateString()]);
        }

        if ($to) {
            $base->whereRaw('DATE(COALESCE(paid_at, created_at)) <= ?', [$to->toDateString()]);
        }

        $lastCreatedAt = null;
        $lastId = null;

        while ($summary['scanned'] < $limit) {
            $query = clone $base;

            if ($lastCreatedAt !== null && $lastId !== null) {
                $query->where(function ($cursor) use ($lastCreatedAt, $lastId) {
                    $cursor->where('created_at', '>', $lastCreatedAt)
                        ->orWhere(function ($sameTs) use ($lastCreatedAt, $lastId) {
                            $sameTs->where('created_at', '=', $lastCreatedAt)
                                ->where('id', '>', $lastId);
                        });
                });
            }

            $chunk = $query
                ->limit(min(100, $limit - $summary['scanned']))
                ->get();

            if ($chunk->isEmpty()) {
                break;
            }

            foreach ($chunk as $intent) {
                $summary['scanned']++;

                $intent->load('invoice', 'billable');
                $organization = $this->resolveOrganization($intent);
                $classification = $this->classifyPayment($intent);

                if ($orgId !== null && (int) ($organization?->id ?? 0) !== $orgId) {
                    $summary['skipped_org_filter']++;
                    $this->recordRunItem($runId, $intent, $organization?->id, $classification, 'skipped', 'org_mismatch');
                    $reportRows[] = $this->reportRow($intent, $organization?->id, $classification, 'skipped', null, 'skipped_org_filter', null);
                    continue;
                }

                if (! $organization || $classification === 'unsupported') {
                    $summary['skipped_unsupported_type']++;
                    $this->recordRunItem($runId, $intent, $organization?->id, $classification, 'skipped', 'unsupported_payment_type');
                    $reportRows[] = $this->reportRow($intent, $organization?->id, $classification, 'skipped', null, 'unsupported_payment_type', null);
                    continue;
                }

                if ($intent->invoice) {
                    $summary['skipped_already_invoiced']++;
                    $this->recordRunItem($runId, $intent, $organization->id, $classification, 'skipped', 'already_invoiced', $intent->invoice->id, (string) ($intent->invoice->pdf_status ?? ''));
                    $reportRows[] = $this->reportRow($intent, $organization->id, $classification, 'skipped', $intent->invoice->id, 'already_invoiced', (string) ($intent->invoice->pdf_status ?? ''));
                    continue;
                }

                if ($this->isRefundedOrChargeback($intent)) {
                    $summary['skipped_refunded_or_chargeback']++;
                    $this->recordRunItem($runId, $intent, $organization->id, $classification, 'skipped', 'refunded_or_chargeback');
                    $reportRows[] = $this->reportRow($intent, $organization->id, $classification, 'skipped', null, 'refunded_or_chargeback', null);
                    continue;
                }

                [$billingSnapshot, $billingSource] = $this->resolveBackfillBillingSnapshot($intent, $organization);
                if (! $this->hasRequiredBillingData($billingSnapshot)) {
                    $summary['skipped_missing_billing']++;
                    $this->recordRunItem($runId, $intent, $organization->id, $classification, 'skipped', 'missing_billing_data');
                    $reportRows[] = $this->reportRow($intent, $organization->id, $classification, 'skipped', null, 'missing_billing_data', null);
                    continue;
                }

                $summary['eligible']++;

                if ($dryRun) {
                    $this->recordRunItem($runId, $intent, $organization->id, $classification, 'skipped', 'dry_run_eligible');
                    $reportRows[] = $this->reportRow($intent, $organization->id, $classification, 'skipped', null, 'dry_run_eligible', null);
                    continue;
                }

                try {
                    $invoice = DB::transaction(function () use ($invoices, $intent, $batchId, $queuePdf, $billingSnapshot, $billingSource) {
                        $fresh = PaymentIntent::query()->with('invoice', 'billable')->findOrFail($intent->id);

                        if ($fresh->invoice) {
                            return $fresh->invoice;
                        }

                        return $invoices->createForPaymentIntent($fresh, [
                            'is_backfilled' => true,
                            'backfilled_at' => now(),
                            'backfill_source' => $billingSource,
                            'backfill_batch_id' => $batchId,
                            'strict_billing_required' => true,
                            'billing_snapshot' => $billingSnapshot,
                            'queue_pdf' => $queuePdf,
                            'soft_fail_pdf' => true,
                        ]);
                    });

                    $summary['created']++;

                    $pdfStatus = (string) ($invoice->pdf_status ?? '');
                    if ($pdfStatus === 'failed') {
                        $summary['pdf_failed']++;
                    } elseif ($pdfStatus === 'queued') {
                        $summary['pdf_queued']++;
                    } else {
                        $summary['pdf_generated_ok']++;
                    }

                    $this->recordRunItem(
                        $runId,
                        $intent,
                        $organization->id,
                        $classification,
                        'created',
                        'invoice_created',
                        $invoice->id,
                        $pdfStatus
                    );

                    $reportRows[] = $this->reportRow(
                        $intent,
                        $organization->id,
                        $classification,
                        'created',
                        $invoice->id,
                        'invoice_created',
                        $pdfStatus
                    );
                } catch (\Throwable $exception) {
                    $summary['failed']++;

                    $this->recordRunItem(
                        $runId,
                        $intent,
                        $organization->id,
                        $classification,
                        'failed',
                        'exception',
                        null,
                        null,
                        $exception->getMessage()
                    );

                    $reportRows[] = $this->reportRow(
                        $intent,
                        $organization->id,
                        $classification,
                        'failed',
                        null,
                        'exception:' . Str::limit($exception->getMessage(), 140, ''),
                        null
                    );
                }
            }

            $last = $chunk->last();
            $lastCreatedAt = $last?->created_at;
            $lastId = $last?->id;

            $this->line(sprintf('Processed %d/%d', $summary['scanned'], $limit));
        }

        // Legacy fallback: paid credit-pack purchases without payment_intent.
        if ($summary['scanned'] < $limit) {
            $remaining = $limit - $summary['scanned'];
            $this->processLegacyPaidPurchasesWithoutIntent(
                $invoices,
                $runId,
                $batchId,
                $dryRun,
                $queuePdf,
                $orgId,
                $from,
                $to,
                $remaining,
                $summary,
                $reportRows
            );
        }

        if ($summary['scanned'] < $limit) {
            $remaining = $limit - $summary['scanned'];
            $this->processWebhookEventsWithoutIntent(
                providers: $providers,
                invoiceCreator: $invoiceCreator,
                invoices: $invoices,
                runId: $runId,
                batchId: $batchId,
                dryRun: $dryRun,
                queuePdf: $queuePdf,
                orgId: $orgId,
                limit: $remaining,
                summary: $summary,
                reportRows: $reportRows
            );
        }

        $reportPath = $this->writeCsvReport($batchId, $reportRows);

        DB::table('invoice_backfill_runs')
            ->where('id', $runId)
            ->update([
                'summary' => $summary,
                'report_path' => $reportPath,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        $this->table(['metric', 'count'], [
            ['scanned payments', $summary['scanned']],
            ['eligible payments', $summary['eligible']],
            ['invoices created', $summary['created']],
            ['skipped already invoiced', $summary['skipped_already_invoiced']],
            ['skipped refunded/chargeback', $summary['skipped_refunded_or_chargeback']],
            ['skipped missing billing data', $summary['skipped_missing_billing']],
            ['skipped not paid', $summary['skipped_not_paid']],
            ['pdf generated ok', $summary['pdf_generated_ok']],
            ['pdf failed', $summary['pdf_failed']],
            ['pdf queued', $summary['pdf_queued']],
            ['failed', $summary['failed']],
        ]);

        $this->info('CSV report: storage/app/' . $reportPath);

        return self::SUCCESS;
    }

    /**
     * @param array<string,int> $summary
     * @param array<int,array<string,string>> $reportRows
     */
    private function processWebhookEventsWithoutIntent(
        PaymentProviderRegistry $providers,
        InvoiceCreatorService $invoiceCreator,
        InvoiceService $invoices,
        string $runId,
        string $batchId,
        bool $dryRun,
        bool $queuePdf,
        ?int $orgId,
        int $limit,
        array &$summary,
        array &$reportRows
    ): void {
        if ($limit <= 0) {
            return;
        }

        $events = WebhookEvent::query()
            ->where('provider', 'mollie')
            ->where('event_type', 'payment.updated')
            ->whereNotNull('provider_event_id')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('payment_intents')
                    ->where('provider', 'mollie')
                    ->whereColumn('provider_payment_id', 'webhook_events.provider_event_id');
            })
            ->orderBy('received_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($events->isEmpty()) {
            return;
        }

        $provider = $providers->get('mollie');

        foreach ($events as $event) {
            $providerPaymentId = (string) ($event->provider_event_id ?? '');
            if ($providerPaymentId === '') {
                continue;
            }

            try {
                $status = $provider->fetchPayment($providerPaymentId);
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $reportRows[] = [
                    'payment_id' => '',
                    'mollie_payment_id' => $providerPaymentId,
                    'org_id' => '',
                    'type' => 'unsupported',
                    'result' => 'failed',
                    'reason' => 'provider_fetch_failed:' . Str::limit($exception->getMessage(), 80, ''),
                    'invoice_id' => '',
                    'pdf_status' => '',
                ];
                continue;
            }

            if (! $this->isPaidProviderStatus($status)) {
                $summary['skipped_not_paid']++;
                $reportRows[] = [
                    'payment_id' => '',
                    'mollie_payment_id' => $providerPaymentId,
                    'org_id' => '',
                    'type' => 'unsupported',
                    'result' => 'skipped',
                    'reason' => 'not_paid',
                    'invoice_id' => '',
                    'pdf_status' => '',
                ];
                continue;
            }

            $intent = $invoiceCreator->resolveOrCreateIntentFromMolliePayment($providerPaymentId, $status, $orgId);
            if (! $intent) {
                $summary['skipped_unsupported_type']++;
                $reportRows[] = [
                    'payment_id' => '',
                    'mollie_payment_id' => $providerPaymentId,
                    'org_id' => '',
                    'type' => 'unsupported',
                    'result' => 'skipped',
                    'reason' => 'unsupported_payment_type',
                    'invoice_id' => '',
                    'pdf_status' => '',
                ];
                continue;
            }

            $summary['scanned']++;
            $intent->load('invoice', 'billable');
            $organization = $this->resolveOrganization($intent);
            $classification = $this->classifyPayment($intent);

            if (! $organization || $classification === 'unsupported') {
                $summary['skipped_unsupported_type']++;
                $this->recordRunItem($runId, $intent, $organization?->id, $classification, 'skipped', 'unsupported_payment_type');
                $reportRows[] = $this->reportRow($intent, $organization?->id, $classification, 'skipped', null, 'unsupported_payment_type', null);
                continue;
            }

            if ($orgId !== null && (int) $organization->id !== $orgId) {
                $summary['skipped_org_filter']++;
                $this->recordRunItem($runId, $intent, $organization->id, $classification, 'skipped', 'org_mismatch');
                $reportRows[] = $this->reportRow($intent, $organization->id, $classification, 'skipped', null, 'skipped_org_filter', null);
                continue;
            }

            if ($intent->invoice) {
                $summary['skipped_already_invoiced']++;
                $this->recordRunItem($runId, $intent, $organization->id, $classification, 'skipped', 'already_invoiced', $intent->invoice->id, (string) ($intent->invoice->pdf_status ?? ''));
                $reportRows[] = $this->reportRow($intent, $organization->id, $classification, 'skipped', $intent->invoice->id, 'already_invoiced', (string) ($intent->invoice->pdf_status ?? ''));
                continue;
            }

            if ($this->isRefundedOrChargeback($intent)) {
                $summary['skipped_refunded_or_chargeback']++;
                $this->recordRunItem($runId, $intent, $organization->id, $classification, 'skipped', 'refunded_or_chargeback');
                $reportRows[] = $this->reportRow($intent, $organization->id, $classification, 'skipped', null, 'refunded_or_chargeback', null);
                continue;
            }

            [$billingSnapshot, $billingSource] = $this->resolveBackfillBillingSnapshot($intent, $organization);
            if (! $this->hasRequiredBillingData($billingSnapshot)) {
                $summary['skipped_missing_billing']++;
                $this->recordRunItem($runId, $intent, $organization->id, $classification, 'skipped', 'missing_billing_data');
                $reportRows[] = $this->reportRow($intent, $organization->id, $classification, 'skipped', null, 'missing_billing_data', null);
                continue;
            }

            $summary['eligible']++;

            if ($dryRun) {
                $this->recordRunItem($runId, $intent, $organization->id, $classification, 'skipped', 'dry_run_eligible');
                $reportRows[] = $this->reportRow($intent, $organization->id, $classification, 'skipped', null, 'dry_run_eligible', null);
                continue;
            }

            try {
                $invoice = DB::transaction(function () use ($invoices, $intent, $batchId, $queuePdf, $billingSnapshot, $billingSource) {
                    $fresh = PaymentIntent::query()->with('invoice', 'billable')->findOrFail($intent->id);
                    if ($fresh->invoice) {
                        return $fresh->invoice;
                    }

                    return $invoices->createForPaymentIntent($fresh, [
                        'is_backfilled' => true,
                        'backfilled_at' => now(),
                        'backfill_source' => $billingSource,
                        'backfill_batch_id' => $batchId,
                        'strict_billing_required' => true,
                        'billing_snapshot' => $billingSnapshot,
                        'queue_pdf' => $queuePdf,
                        'soft_fail_pdf' => true,
                    ]);
                });

                $summary['created']++;
                $pdfStatus = (string) ($invoice->pdf_status ?? '');
                if ($pdfStatus === 'failed') {
                    $summary['pdf_failed']++;
                } elseif ($pdfStatus === 'queued') {
                    $summary['pdf_queued']++;
                } else {
                    $summary['pdf_generated_ok']++;
                }

                $this->recordRunItem($runId, $intent, $organization->id, $classification, 'created', 'invoice_created', $invoice->id, $pdfStatus);
                $reportRows[] = $this->reportRow($intent, $organization->id, $classification, 'created', $invoice->id, 'invoice_created', $pdfStatus);
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $this->recordRunItem($runId, $intent, $organization->id, $classification, 'failed', 'exception', null, null, $exception->getMessage());
                $reportRows[] = $this->reportRow($intent, $organization->id, $classification, 'failed', null, 'exception:' . Str::limit($exception->getMessage(), 140, ''), null);
            }
        }
    }

    private function parseDate(string $value): ?Carbon
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveOrganization(PaymentIntent $intent): ?object
    {
        $billable = $intent->billable;

        if ($billable instanceof CreditPackPurchase) {
            $billable->loadMissing('clientSite.workspace.organization');

            return $billable->clientSite?->workspace?->organization;
        }

        if ($billable instanceof Subscription) {
            $billable->loadMissing('organization');

            return $billable->organization;
        }

        if ($billable instanceof SubscriptionPlanChange) {
            $billable->loadMissing('organization');

            return $billable->organization;
        }

        return null;
    }

    private function classifyPayment(PaymentIntent $intent): string
    {
        $billable = $intent->billable;

        if ($billable instanceof CreditPackPurchase) {
            return 'credit_pack_purchase';
        }

        if ($billable instanceof SubscriptionPlanChange) {
            return 'proration_adjustment';
        }

        if ($billable instanceof Subscription) {
            $purpose = (string) data_get($intent->meta, 'purpose', 'subscription_renewal');

            return $purpose === 'subscription_initial'
                ? 'subscription_initial'
                : 'subscription_renewal';
        }

        return 'unsupported';
    }

    private function isRefundedOrChargeback(PaymentIntent $intent): bool
    {
        $statuses = array_filter([
            strtolower((string) $intent->status),
            strtolower((string) $intent->last_provider_status),
            strtolower((string) data_get($intent->meta, 'status', '')),
            strtolower((string) data_get($intent->meta, 'provider_status', '')),
            strtolower((string) data_get($intent->meta, 'mollie_status', '')),
        ]);

        foreach ($statuses as $status) {
            if (in_array($status, ['refunded', 'charged_back', 'chargeback'], true)) {
                return true;
            }
        }

        return (bool) data_get($intent->meta, 'is_refunded', false)
            || (bool) data_get($intent->meta, 'is_chargeback', false);
    }

    /**
     * @param array<string,mixed> $status
     */
    private function isPaidProviderStatus(array $status): bool
    {
        if (! empty($status['is_paid'])) {
            return true;
        }

        return in_array((string) ($status['status'] ?? ''), ['paid', 'settled'], true);
    }

    /**
     * @param array<string,int> $summary
     * @param array<int,array<string,string>> $reportRows
     */
    private function processLegacyPaidPurchasesWithoutIntent(
        InvoiceService $invoices,
        string $runId,
        string $batchId,
        bool $dryRun,
        bool $queuePdf,
        ?int $orgId,
        ?Carbon $from,
        ?Carbon $to,
        int $limit,
        array &$summary,
        array &$reportRows
    ): void {
        if ($limit <= 0) {
            return;
        }

        $query = CreditPackPurchase::query()
            ->where('status', 'paid')
            ->whereDoesntHave('paymentIntents')
            ->with(['clientSite.workspace.organization'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit);

        if ($from) {
            $query->whereRaw('DATE(COALESCE(paid_at, created_at)) >= ?', [$from->toDateString()]);
        }

        if ($to) {
            $query->whereRaw('DATE(COALESCE(paid_at, created_at)) <= ?', [$to->toDateString()]);
        }

        $rows = $query->get();

        foreach ($rows as $purchase) {
            $summary['scanned']++;
            $classification = 'credit_pack_purchase';

            $organization = $purchase->clientSite?->workspace?->organization;
            if (! $organization) {
                $summary['skipped_unsupported_type']++;
                $reportRows[] = [
                    'payment_id' => '',
                    'mollie_payment_id' => (string) ($purchase->provider_payment_id ?? ''),
                    'org_id' => '',
                    'type' => $classification,
                    'result' => 'skipped',
                    'reason' => 'unsupported_payment_type',
                    'invoice_id' => '',
                    'pdf_status' => '',
                ];
                continue;
            }

            if ($orgId !== null && (int) $organization->id !== $orgId) {
                $summary['skipped_org_filter']++;
                $reportRows[] = [
                    'payment_id' => '',
                    'mollie_payment_id' => (string) ($purchase->provider_payment_id ?? ''),
                    'org_id' => (string) $organization->id,
                    'type' => $classification,
                    'result' => 'skipped',
                    'reason' => 'skipped_org_filter',
                    'invoice_id' => '',
                    'pdf_status' => '',
                ];
                continue;
            }

            $existing = \App\Models\Invoice::query()->where('credit_pack_purchase_id', $purchase->id)->first();
            if ($existing) {
                $summary['skipped_already_invoiced']++;
                $this->recordLegacyRunItem($runId, $purchase, (int) $organization->id, $classification, 'skipped', 'already_invoiced', $existing->id, (string) ($existing->pdf_status ?? ''));
                $reportRows[] = [
                    'payment_id' => '',
                    'mollie_payment_id' => (string) ($purchase->provider_payment_id ?? ''),
                    'org_id' => (string) $organization->id,
                    'type' => $classification,
                    'result' => 'skipped',
                    'reason' => 'already_invoiced',
                    'invoice_id' => (string) $existing->id,
                    'pdf_status' => (string) ($existing->pdf_status ?? ''),
                ];
                continue;
            }

            [$billingSnapshot, $billingSource] = $this->resolveBackfillBillingSnapshotFromPurchase($purchase, $organization);
            if (! $this->hasRequiredBillingData($billingSnapshot)) {
                $summary['skipped_missing_billing']++;
                $this->recordLegacyRunItem($runId, $purchase, (int) $organization->id, $classification, 'skipped', 'missing_billing_data');
                $reportRows[] = [
                    'payment_id' => '',
                    'mollie_payment_id' => (string) ($purchase->provider_payment_id ?? ''),
                    'org_id' => (string) $organization->id,
                    'type' => $classification,
                    'result' => 'skipped',
                    'reason' => 'missing_billing_data',
                    'invoice_id' => '',
                    'pdf_status' => '',
                ];
                continue;
            }

            $summary['eligible']++;

            if ($dryRun) {
                $this->recordLegacyRunItem($runId, $purchase, (int) $organization->id, $classification, 'skipped', 'dry_run_eligible');
                $reportRows[] = [
                    'payment_id' => '',
                    'mollie_payment_id' => (string) ($purchase->provider_payment_id ?? ''),
                    'org_id' => (string) $organization->id,
                    'type' => $classification,
                    'result' => 'skipped',
                    'reason' => 'dry_run_eligible',
                    'invoice_id' => '',
                    'pdf_status' => '',
                ];
                continue;
            }

            try {
                $invoice = DB::transaction(function () use ($invoices, $purchase, $batchId, $queuePdf, $billingSnapshot, $billingSource) {
                    $fresh = CreditPackPurchase::query()
                        ->with(['clientSite.workspace.organization'])
                        ->findOrFail($purchase->id);

                    $existing = \App\Models\Invoice::query()->where('credit_pack_purchase_id', $fresh->id)->first();
                    if ($existing) {
                        return $existing;
                    }

                    return $invoices->createForLegacyCreditPackPurchase($fresh, [
                        'is_backfilled' => true,
                        'backfilled_at' => now(),
                        'backfill_source' => $billingSource,
                        'backfill_batch_id' => $batchId,
                        'strict_billing_required' => true,
                        'billing_snapshot' => $billingSnapshot,
                        'queue_pdf' => $queuePdf,
                        'soft_fail_pdf' => true,
                    ]);
                });

                $summary['created']++;

                $pdfStatus = (string) ($invoice->pdf_status ?? '');
                if ($pdfStatus === 'failed') {
                    $summary['pdf_failed']++;
                } elseif ($pdfStatus === 'queued') {
                    $summary['pdf_queued']++;
                } else {
                    $summary['pdf_generated_ok']++;
                }

                $this->recordLegacyRunItem($runId, $purchase, (int) $organization->id, $classification, 'created', 'invoice_created', $invoice->id, $pdfStatus);
                $reportRows[] = [
                    'payment_id' => '',
                    'mollie_payment_id' => (string) ($purchase->provider_payment_id ?? ''),
                    'org_id' => (string) $organization->id,
                    'type' => $classification,
                    'result' => 'created',
                    'reason' => 'invoice_created',
                    'invoice_id' => (string) $invoice->id,
                    'pdf_status' => $pdfStatus,
                ];
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $this->recordLegacyRunItem(
                    $runId,
                    $purchase,
                    (int) $organization->id,
                    $classification,
                    'failed',
                    'exception',
                    null,
                    null,
                    $exception->getMessage()
                );
                $reportRows[] = [
                    'payment_id' => '',
                    'mollie_payment_id' => (string) ($purchase->provider_payment_id ?? ''),
                    'org_id' => (string) $organization->id,
                    'type' => $classification,
                    'result' => 'failed',
                    'reason' => 'exception:' . Str::limit($exception->getMessage(), 140, ''),
                    'invoice_id' => '',
                    'pdf_status' => '',
                ];
            }
        }
    }

    /**
     * @return array{0:array<string,mixed>,1:string}
     */
    private function resolveBackfillBillingSnapshot(PaymentIntent $intent, object $organization): array
    {
        $snapshot = data_get($intent->meta, 'billing_snapshot');
        if (is_array($snapshot) && $snapshot !== []) {
            return [$this->normalizeBillingSnapshot($snapshot), 'payment'];
        }

        return [[
            'company_name' => (string) ($organization->billing_company_name ?: $organization->name),
            'address_line1' => $organization->billing_address_line1,
            'address_line2' => $organization->billing_address_line2,
            'postal_code' => $organization->billing_postal_code,
            'city' => $organization->billing_city,
            'country_code' => strtoupper((string) ($organization->billing_country_code ?: '')),
            'vat_number' => $organization->billing_vat_number,
            'kvk_number' => $organization->billing_kvk_number,
        ], 'org_current_profile'];
    }

    /**
     * @return array{0:array<string,mixed>,1:string}
     */
    private function resolveBackfillBillingSnapshotFromPurchase(CreditPackPurchase $purchase, object $organization): array
    {
        $snapshot = data_get($purchase->meta, 'billing_snapshot');
        if (is_array($snapshot) && $snapshot !== []) {
            return [$this->normalizeBillingSnapshot($snapshot), 'payment'];
        }

        return [[
            'company_name' => (string) ($organization->billing_company_name ?: $organization->name),
            'address_line1' => $organization->billing_address_line1,
            'address_line2' => $organization->billing_address_line2,
            'postal_code' => $organization->billing_postal_code,
            'city' => $organization->billing_city,
            'country_code' => strtoupper((string) ($organization->billing_country_code ?: '')),
            'vat_number' => $organization->billing_vat_number,
            'kvk_number' => $organization->billing_kvk_number,
        ], 'org_current_profile'];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function normalizeBillingSnapshot(array $snapshot): array
    {
        return [
            'company_name' => (string) ($snapshot['company_name'] ?? $snapshot['billing_company_name'] ?? ''),
            'address_line1' => $snapshot['address_line1'] ?? $snapshot['billing_address_line1'] ?? null,
            'address_line2' => $snapshot['address_line2'] ?? $snapshot['billing_address_line2'] ?? null,
            'postal_code' => $snapshot['postal_code'] ?? $snapshot['billing_postal_code'] ?? null,
            'city' => $snapshot['city'] ?? $snapshot['billing_city'] ?? null,
            'country_code' => strtoupper((string) ($snapshot['country_code'] ?? $snapshot['billing_country_code'] ?? '')),
            'vat_number' => $snapshot['vat_number'] ?? $snapshot['billing_vat_number'] ?? null,
            'kvk_number' => $snapshot['kvk_number'] ?? $snapshot['billing_kvk_number'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $billing
     */
    private function hasRequiredBillingData(array $billing): bool
    {
        return trim((string) ($billing['company_name'] ?? '')) !== ''
            && trim((string) ($billing['address_line1'] ?? '')) !== ''
            && trim((string) ($billing['country_code'] ?? '')) !== '';
    }

    private function writeCsvReport(string $batchId, array $rows): string
    {
        $path = sprintf('reports/invoice-backfill-%s.csv', $batchId);

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['payment_id', 'mollie_payment_id', 'org_id', 'type', 'result', 'reason', 'invoice_id', 'pdf_status']);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['payment_id'],
                $row['mollie_payment_id'],
                $row['org_id'],
                $row['type'],
                $row['result'],
                $row['reason'],
                $row['invoice_id'],
                $row['pdf_status'],
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        Storage::disk('local')->put($path, $csv ?: '');

        return $path;
    }

    private function reportRow(
        PaymentIntent $intent,
        ?int $organizationId,
        string $type,
        string $result,
        ?string $invoiceId,
        string $reason,
        ?string $pdfStatus
    ): array {
        return [
            'payment_id' => (string) $intent->id,
            'mollie_payment_id' => (string) ($intent->provider_payment_id ?? ''),
            'org_id' => $organizationId !== null ? (string) $organizationId : '',
            'type' => $type,
            'result' => $result,
            'reason' => $reason,
            'invoice_id' => $invoiceId ?: '',
            'pdf_status' => $pdfStatus ?: '',
        ];
    }

    private function recordRunItem(
        string $runId,
        PaymentIntent $intent,
        ?int $organizationId,
        string $type,
        string $result,
        string $reason,
        ?string $invoiceId = null,
        ?string $pdfStatus = null,
        ?string $error = null
    ): void {
        DB::table('invoice_backfill_run_items')->updateOrInsert(
            [
                'run_id' => $runId,
                'payment_intent_id' => $intent->id,
            ],
            [
                'provider_payment_id' => (string) ($intent->provider_payment_id ?? ''),
                'organization_id' => $organizationId,
                'type' => $type,
                'result' => $result,
                'reason' => $reason,
                'invoice_id' => $invoiceId,
                'pdf_status' => $pdfStatus,
                'error' => $error,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function recordLegacyRunItem(
        string $runId,
        CreditPackPurchase $purchase,
        int $organizationId,
        string $type,
        string $result,
        string $reason,
        ?string $invoiceId = null,
        ?string $pdfStatus = null,
        ?string $error = null
    ): void {
        DB::table('invoice_backfill_run_items')->insert([
            'run_id' => $runId,
            'payment_intent_id' => (string) $purchase->id,
            'provider_payment_id' => (string) ($purchase->provider_payment_id ?? ''),
            'organization_id' => $organizationId,
            'type' => $type,
            'result' => $result,
            'reason' => $reason,
            'invoice_id' => $invoiceId,
            'pdf_status' => $pdfStatus,
            'error' => $error,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
