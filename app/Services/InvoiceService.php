<?php

namespace App\Services;

use App\Contracts\PdfRenderer;
use App\Jobs\GenerateInvoicePdfJob;
use App\Models\CreditPackPurchase;
use App\Models\Invoice;
use App\Models\PaymentIntent;
use App\Models\Subscription;
use App\Models\SubscriptionPlanChange;
use App\ViewModels\InvoicePdfData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class InvoiceService
{
    public function __construct(
        private readonly VatService $vatService,
        private readonly BillingSettingsService $settings,
        private readonly PdfRenderer $pdfRenderer
    )
    {
    }

    /**
     * @param array<string,mixed> $options
     */
    public function createForPaymentIntent(PaymentIntent $intent, array $options = []): Invoice
    {
        $existing = Invoice::query()->where('payment_intent_id', $intent->id)->first();
        if ($existing) {
            return $existing;
        }

        $context = $this->resolveContext($intent);
        $organization = $context['organization'];
        $type = $context['type'];
        $description = $context['description'];

        if (! $organization || ! $type || ! $description) {
            throw new RuntimeException('Unable to build invoice for payment intent billable type.');
        }

        $billing = $this->resolveBillingData($organization, $options);

        if (($options['strict_billing_required'] ?? false) && ! $this->hasRequiredBillingData($billing)) {
            throw new RuntimeException('Missing required billing snapshot fields for backfill.');
        }

        $vat = $this->resolveVatData($billing, $organization, $intent, $options);

        $pricingMode = (string) ($options['pricing_mode'] ?? config('billing.pricing_mode', 'vat_inclusive'));
        $invoiceLines = $this->resolveInvoiceLines($intent, $description);
        $amounts = $this->calculateInvoiceLineAmounts($invoiceLines, $vat, $pricingMode);
        $this->assertPaidInvoiceMatchesCapturedGross($intent, $amounts['total_gross_cents']);

        $invoice = DB::transaction(function () use ($context, $intent, $type, $description, $billing, $vat, $amounts, $options, $pricingMode) {
            $number = $this->nextInvoiceNumber((int) now()->format('Y'));

            $meta = [
                'assumptions' => [
                    'outside_eu_zero_vat' => true,
                    'eu_reverse_charge_requires_vat_number' => true,
                ],
            ];

            if (! empty($options['is_backfilled'])) {
                $meta['backfill'] = [
                    'source' => (string) ($options['backfill_source'] ?? ($billing['backfill_source'] ?? 'org_current_profile')),
                    'batch_id' => (string) ($options['backfill_batch_id'] ?? ''),
                ];
            }

            $invoice = Invoice::query()->create([
                'id' => (string) Str::uuid(),
                'organization_id' => $context['organization']->id,
                'subscription_id' => $context['subscription']?->id,
                'payment_intent_id' => $intent->id,
                'credit_pack_purchase_id' => $context['credit_pack_purchase']?->id,
                'type' => $type,
                'number' => $number,
                'status' => 'issued',
                'currency' => (string) $intent->currency,
                'pricing_mode' => $pricingMode,
                'subtotal_net' => $this->centsToDecimal($amounts['subtotal_net_cents']),
                'vat_amount' => $this->centsToDecimal($amounts['vat_amount_cents']),
                'total_gross' => $this->centsToDecimal($amounts['total_gross_cents']),
                'subtotal_cents' => $amounts['subtotal_net_cents'],
                'tax_cents' => $amounts['vat_amount_cents'],
                'total_cents' => $amounts['total_gross_cents'],
                'vat_rate' => $vat['rate'],
                'vat_type' => $vat['type'],
                'reverse_charge' => $vat['reverse_charge'],
                'document_type' => 'invoice',
                'issued_at' => now(),
                'paid_at' => $intent->paid_at,
                'billing_company_name' => (string) ($billing['company_name'] ?? ''),
                'billing_address_line1' => $billing['address_line1'] ?? null,
                'billing_address_line2' => $billing['address_line2'] ?? null,
                'billing_postal_code' => $billing['postal_code'] ?? null,
                'billing_city' => $billing['city'] ?? null,
                'billing_country_code' => strtoupper((string) ($billing['country_code'] ?? 'NL')),
                'billing_vat_number' => $billing['vat_number'] ?? null,
                'billing_kvk_number' => $billing['kvk_number'] ?? null,
                'meta' => $meta,
                'is_backfilled' => (bool) ($options['is_backfilled'] ?? false),
                'backfilled_at' => ! empty($options['is_backfilled']) ? ($options['backfilled_at'] ?? now()) : null,
                'backfill_source' => ! empty($options['is_backfilled'])
                    ? (string) ($options['backfill_source'] ?? ($billing['backfill_source'] ?? 'org_current_profile'))
                    : null,
                'backfill_batch_id' => ! empty($options['is_backfilled'])
                    ? (string) ($options['backfill_batch_id'] ?? '')
                    : null,
            ]);

            foreach ($amounts['lines'] as $line) {
                $invoice->items()->create([
                    'description' => $line['description'],
                    'quantity' => 1,
                    'unit_price_cents' => $line['subtotal_net_cents'],
                    'unit_price_net' => $this->centsToDecimal($line['subtotal_net_cents']),
                    'subtotal_cents' => $line['subtotal_net_cents'],
                    'line_total_net' => $this->centsToDecimal($line['subtotal_net_cents']),
                    'tax_rate' => $vat['rate'],
                    'tax_cents' => $line['vat_amount_cents'],
                    'vat_amount' => $this->centsToDecimal($line['vat_amount_cents']),
                    'total_cents' => $line['total_gross_cents'],
                    'line_total_gross' => $this->centsToDecimal($line['total_gross_cents']),
                    'meta' => [
                        'code' => $line['code'],
                        'type' => $line['type'],
                    ],
                ]);
            }

            $immutableHash = hash('sha256', json_encode([
                'invoice_id' => $invoice->id,
                'number' => $invoice->number,
                'type' => $invoice->type,
                'subtotal_cents' => $invoice->subtotal_cents,
                'tax_cents' => $invoice->tax_cents,
                'total_cents' => $invoice->total_cents,
                'issued_at' => optional($invoice->issued_at)?->toIso8601String(),
            ]));

            $invoice->immutable_hash = $immutableHash;
            $invoice->save();

            if ($context['plan_change']) {
                $context['plan_change']->invoice_id = $invoice->id;
                $context['plan_change']->save();
            }

            return $invoice;
        });

        if (($options['skip_pdf'] ?? false) === true) {
            return $invoice;
        }

        if (($options['queue_pdf'] ?? false) === true) {
            $invoice->pdf_status = 'queued';
            $invoice->pdf_error_message = null;
            $invoice->save();

            GenerateInvoicePdfJob::dispatch($invoice->id);

            return $invoice;
        }

        return $this->generatePdf($invoice->fresh(['organization', 'items']), (bool) ($options['soft_fail_pdf'] ?? false));
    }

    public function markRefunded(Invoice $invoice, string $refundReference): Invoice
    {
        if ($invoice->status === 'refunded') {
            return $invoice;
        }

        $invoice->status = 'refunded';
        $invoice->refund_reference = $refundReference;
        $invoice->refunded_at = now();
        $invoice->save();

        return $invoice;
    }

    /**
     * @param array<string,mixed> $options
     */
    public function createForLegacyCreditPackPurchase(CreditPackPurchase $purchase, array $options = []): Invoice
    {
        $existing = Invoice::query()
            ->where('credit_pack_purchase_id', $purchase->id)
            ->first();
        if ($existing) {
            return $existing;
        }

        $purchase->loadMissing('clientSite.workspace.organization');
        $organization = $purchase->clientSite?->workspace?->organization;

        if (! $organization) {
            throw new RuntimeException('Unable to resolve organization for legacy credit-pack purchase.');
        }

        $billing = $this->resolveBillingData($organization, $options);

        if (($options['strict_billing_required'] ?? false) && ! $this->hasRequiredBillingData($billing)) {
            throw new RuntimeException('Missing required billing snapshot fields for backfill.');
        }

        $vat = $this->resolveVatData($billing, $organization, new PaymentIntent(['meta' => []]), $options);
        $pricingMode = (string) ($options['pricing_mode'] ?? config('billing.pricing_mode', 'vat_inclusive'));
        $amounts = $this->calculateAmounts((int) $purchase->price_cents, $vat, $pricingMode);

        $invoice = DB::transaction(function () use ($purchase, $organization, $billing, $vat, $amounts, $options, $pricingMode) {
            $number = $this->nextInvoiceNumber((int) now()->format('Y'));

            $meta = [
                'assumptions' => [
                    'outside_eu_zero_vat' => true,
                    'eu_reverse_charge_requires_vat_number' => true,
                ],
                'legacy_source' => 'credit_pack_purchase',
                'legacy_provider_payment_id' => (string) ($purchase->provider_payment_id ?? ''),
            ];

            if (! empty($options['is_backfilled'])) {
                $meta['backfill'] = [
                    'source' => (string) ($options['backfill_source'] ?? ($billing['backfill_source'] ?? 'org_current_profile')),
                    'batch_id' => (string) ($options['backfill_batch_id'] ?? ''),
                ];
            }

            $invoice = Invoice::query()->create([
                'id' => (string) Str::uuid(),
                'organization_id' => $organization->id,
                'subscription_id' => null,
                'payment_intent_id' => null,
                'credit_pack_purchase_id' => $purchase->id,
                'type' => 'credit_pack',
                'number' => $number,
                'status' => 'issued',
                'currency' => (string) $purchase->currency,
                'pricing_mode' => $pricingMode,
                'subtotal_net' => $this->centsToDecimal($amounts['subtotal_net_cents']),
                'vat_amount' => $this->centsToDecimal($amounts['vat_amount_cents']),
                'total_gross' => $this->centsToDecimal($amounts['total_gross_cents']),
                'subtotal_cents' => $amounts['subtotal_net_cents'],
                'tax_cents' => $amounts['vat_amount_cents'],
                'total_cents' => $amounts['total_gross_cents'],
                'vat_rate' => $vat['rate'],
                'vat_type' => $vat['type'],
                'reverse_charge' => $vat['reverse_charge'],
                'document_type' => 'invoice',
                'issued_at' => now(),
                'paid_at' => $purchase->paid_at ?: now(),
                'billing_company_name' => (string) ($billing['company_name'] ?? ''),
                'billing_address_line1' => $billing['address_line1'] ?? null,
                'billing_address_line2' => $billing['address_line2'] ?? null,
                'billing_postal_code' => $billing['postal_code'] ?? null,
                'billing_city' => $billing['city'] ?? null,
                'billing_country_code' => strtoupper((string) ($billing['country_code'] ?? 'NL')),
                'billing_vat_number' => $billing['vat_number'] ?? null,
                'billing_kvk_number' => $billing['kvk_number'] ?? null,
                'meta' => $meta,
                'is_backfilled' => (bool) ($options['is_backfilled'] ?? false),
                'backfilled_at' => ! empty($options['is_backfilled']) ? ($options['backfilled_at'] ?? now()) : null,
                'backfill_source' => ! empty($options['is_backfilled'])
                    ? (string) ($options['backfill_source'] ?? ($billing['backfill_source'] ?? 'org_current_profile'))
                    : null,
                'backfill_batch_id' => ! empty($options['is_backfilled'])
                    ? (string) ($options['backfill_batch_id'] ?? '')
                    : null,
            ]);

            $invoice->items()->create([
                'description' => sprintf('Credit pack purchase (%d credits)', (int) $purchase->credits_amount),
                'quantity' => 1,
                'unit_price_cents' => $amounts['subtotal_net_cents'],
                'unit_price_net' => $this->centsToDecimal($amounts['subtotal_net_cents']),
                'subtotal_cents' => $amounts['subtotal_net_cents'],
                'line_total_net' => $this->centsToDecimal($amounts['subtotal_net_cents']),
                'tax_rate' => $vat['rate'],
                'tax_cents' => $amounts['vat_amount_cents'],
                'vat_amount' => $this->centsToDecimal($amounts['vat_amount_cents']),
                'total_cents' => $amounts['total_gross_cents'],
                'line_total_gross' => $this->centsToDecimal($amounts['total_gross_cents']),
                'meta' => [],
            ]);

            $immutableHash = hash('sha256', json_encode([
                'invoice_id' => $invoice->id,
                'number' => $invoice->number,
                'type' => $invoice->type,
                'subtotal_cents' => $invoice->subtotal_cents,
                'tax_cents' => $invoice->tax_cents,
                'total_cents' => $invoice->total_cents,
                'issued_at' => optional($invoice->issued_at)?->toIso8601String(),
            ]));

            $invoice->immutable_hash = $immutableHash;
            $invoice->save();

            return $invoice;
        });

        if (($options['skip_pdf'] ?? false) === true) {
            return $invoice;
        }

        if (($options['queue_pdf'] ?? false) === true) {
            $invoice->pdf_status = 'queued';
            $invoice->pdf_error_message = null;
            $invoice->save();

            GenerateInvoicePdfJob::dispatch($invoice->id);

            return $invoice;
        }

        return $this->generatePdf($invoice->fresh(['organization', 'items']), (bool) ($options['soft_fail_pdf'] ?? false));
    }

    public function generatePdf(Invoice $invoice, bool $softFail = false, bool $forceRegenerate = false): Invoice
    {
        try {
            if (! $forceRegenerate && $invoice->pdf_path && Storage::disk('local')->exists((string) $invoice->pdf_path)) {
                if ($invoice->pdf_status !== 'generated') {
                    $invoice->pdf_status = 'generated';
                    $invoice->pdf_error_message = null;
                    $invoice->save();
                }

                return $invoice;
            }

            $path = sprintf('invoices/%s/%s.pdf', now()->format('Y'), $invoice->number);
            $pdfBytes = $this->renderPdfBytes($invoice);

            Storage::disk('local')->put($path, $pdfBytes);

            $invoice->pdf_path = $path;
            $invoice->pdf_checksum = hash('sha256', $pdfBytes);
            $invoice->pdf_status = 'generated';
            $invoice->pdf_error_message = null;
            $invoice->save();

            return $invoice;
        } catch (\Throwable $exception) {
            if (! $softFail) {
                throw $exception;
            }

            $invoice->pdf_status = 'failed';
            $invoice->pdf_error_message = Str::limit($exception->getMessage(), 500, '');
            $invoice->save();

            return $invoice;
        }
    }

    public function renderPdfBytes(Invoice $invoice): string
    {
        $invoice = $invoice->fresh(['organization', 'items', 'paymentIntent', 'creditPackPurchase']) ?? $invoice;
        $pdfData = $this->buildPdfData($invoice);

        return $this->pdfRenderer->renderInvoice([
            'invoice' => $invoice,
            'pdf' => $pdfData,
        ]);
    }

    /**
     * @return array{organization:mixed,subscription:mixed,credit_pack_purchase:mixed,plan_change:mixed,type:string|null,description:string|null}
     */
    private function resolveContext(PaymentIntent $intent): array
    {
        $billable = $intent->billable;

        $organization = null;
        $subscription = null;
        $creditPackPurchase = null;
        $planChange = null;
        $type = null;
        $description = null;

        if ($billable instanceof CreditPackPurchase) {
            $creditPackPurchase = $billable;
            $organization = $billable->clientSite?->workspace?->organization;
            $type = 'credit_pack';
            $description = sprintf('Credit pack purchase (%d credits)', (int) $billable->credits_amount);
        }

        if ($billable instanceof Subscription) {
            $subscription = $billable;
            $organization = $billable->organization;
            $purpose = (string) data_get($intent->meta, 'purpose', 'subscription_renewal');

            $type = 'subscription';
            $description = $purpose === 'subscription_initial'
                ? sprintf('Plan subscription initial payment (%s)', (string) ($billable->plan?->name ?? 'Plan'))
                : sprintf('Plan subscription renewal (%s)', (string) ($billable->plan?->name ?? 'Plan'));
        }

        if ($billable instanceof SubscriptionPlanChange) {
            $planChange = $billable;
            $subscription = $billable->subscription;
            $organization = $billable->organization;
            $type = 'plan_change_adjustment';
            $description = sprintf(
                'Plan change adjustment (%s -> %s)',
                (string) ($billable->fromPlan?->name ?? 'old'),
                (string) ($billable->toPlan?->name ?? 'new')
            );
        }

        return [
            'organization' => $organization,
            'subscription' => $subscription,
            'credit_pack_purchase' => $creditPackPurchase,
            'plan_change' => $planChange,
            'type' => $type,
            'description' => $description,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function resolveBillingData(object $organization, array $options): array
    {
        $snapshot = is_array($options['billing_snapshot'] ?? null)
            ? $options['billing_snapshot']
            : [];

        if ($snapshot !== []) {
            $snapshot['backfill_source'] = $options['backfill_source'] ?? 'payment';

            return $snapshot;
        }

        $billingAddress = is_array($organization->billing_address ?? null)
            ? $organization->billing_address
            : [];

        $addressLine1 = $billingAddress['line1'] ?? $organization->billing_address_line1;
        $addressLine2 = $billingAddress['line2'] ?? $organization->billing_address_line2;
        $postalCode = $billingAddress['postal_code'] ?? $organization->billing_postal_code;
        $city = $billingAddress['city'] ?? $organization->billing_city;
        $countryCode = $billingAddress['country_code'] ?? $organization->billing_country_code;

        return [
            'company_name' => (string) ($organization->legal_name ?: $organization->billing_company_name ?: $organization->name),
            'address_line1' => $addressLine1,
            'address_line2' => $addressLine2,
            'postal_code' => $postalCode,
            'city' => $city,
            'country_code' => strtoupper((string) ($countryCode ?: 'NL')),
            'vat_number' => (string) ($organization->vat_id ?: $organization->billing_vat_number),
            'kvk_number' => $organization->billing_kvk_number,
            'backfill_source' => $options['backfill_source'] ?? 'org_current_profile',
        ];
    }

    /**
     * @param array<string,mixed> $billing
     * @param array<string,mixed> $options
     * @return array{rate:float,type:string,reverse_charge:bool}
     */
    private function resolveVatData(array $billing, object $organization, PaymentIntent $intent, array $options): array
    {
        $vatSnapshot = is_array($options['vat_snapshot'] ?? null)
            ? $options['vat_snapshot']
            : (is_array(data_get($intent->meta, 'vat_snapshot')) ? data_get($intent->meta, 'vat_snapshot') : []);

        if (
            $vatSnapshot !== []
            && array_key_exists('rate', $vatSnapshot)
            && array_key_exists('type', $vatSnapshot)
            && array_key_exists('reverse_charge', $vatSnapshot)
        ) {
            return [
                'rate' => (float) $vatSnapshot['rate'],
                'type' => (string) $vatSnapshot['type'],
                'reverse_charge' => (bool) $vatSnapshot['reverse_charge'],
            ];
        }

        return $this->vatService->resolve(
            (string) ($billing['country_code'] ?? $organization->billing_country_code ?? 'NL'),
            (string) ($billing['vat_number'] ?? $organization->billing_vat_number ?? '')
        );
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

    /**
     * @param array{rate:float,type:string,reverse_charge:bool} $vat
     * @return array{subtotal_net_cents:int,vat_amount_cents:int,total_gross_cents:int}
     */
    public function calculateAmounts(int $baseAmountCents, array $vat, string $pricingMode = 'vat_inclusive'): array
    {
        $grossCents = max(0, $baseAmountCents);
        $rate = (float) ($vat['rate'] ?? 0.0);
        $reverseCharge = (bool) ($vat['reverse_charge'] ?? false);

        if ($pricingMode === 'vat_exclusive') {
            $net = $grossCents;
            $vatAmount = ($reverseCharge || $rate <= 0.0)
                ? 0
                : (int) round($net * ($rate / 100));
            $gross = $net + $vatAmount;

            return [
                'subtotal_net_cents' => $net,
                'vat_amount_cents' => $vatAmount,
                'total_gross_cents' => $gross,
            ];
        }

        // Default VAT-inclusive mode: gross already includes VAT.
        if ($reverseCharge || $rate <= 0.0) {
            return [
                'subtotal_net_cents' => $grossCents,
                'vat_amount_cents' => 0,
                'total_gross_cents' => $grossCents,
            ];
        }

        $net = (int) round($grossCents / (1 + ($rate / 100)));
        $vatAmount = $grossCents - $net;

        return [
            'subtotal_net_cents' => $net,
            'vat_amount_cents' => $vatAmount,
            'total_gross_cents' => $grossCents,
        ];
    }

    /**
     * @param array<int,array{code:string,type:string,description:string,amount_cents:int}> $lines
     * @param array{rate:float,type:string,reverse_charge:bool} $vat
     * @return array{
     *   subtotal_net_cents:int,
     *   vat_amount_cents:int,
     *   total_gross_cents:int,
     *   lines:array<int,array{code:string,type:string,description:string,subtotal_net_cents:int,vat_amount_cents:int,total_gross_cents:int}>
     * }
     */
    private function calculateInvoiceLineAmounts(array $lines, array $vat, string $pricingMode): array
    {
        $subtotalNet = 0;
        $vatAmount = 0;
        $totalGross = 0;
        $resolved = [];

        foreach ($lines as $line) {
            $lineAmounts = $this->calculateAmounts((int) $line['amount_cents'], $vat, $pricingMode);

            $subtotalNet += $lineAmounts['subtotal_net_cents'];
            $vatAmount += $lineAmounts['vat_amount_cents'];
            $totalGross += $lineAmounts['total_gross_cents'];

            $resolved[] = [
                'code' => $line['code'],
                'type' => $line['type'],
                'description' => $line['description'],
                'subtotal_net_cents' => $lineAmounts['subtotal_net_cents'],
                'vat_amount_cents' => $lineAmounts['vat_amount_cents'],
                'total_gross_cents' => $lineAmounts['total_gross_cents'],
            ];
        }

        return [
            'subtotal_net_cents' => $subtotalNet,
            'vat_amount_cents' => $vatAmount,
            'total_gross_cents' => $totalGross,
            'lines' => $resolved,
        ];
    }

    /**
     * @return array<int,array{code:string,type:string,description:string,amount_cents:int}>
     */
    private function resolveInvoiceLines(PaymentIntent $intent, string $fallbackDescription): array
    {
        $metaLines = is_array(data_get($intent->meta, 'line_items')) ? data_get($intent->meta, 'line_items') : [];
        $lines = [];

        foreach ($metaLines as $line) {
            $amountCents = max(0, (int) data_get($line, 'amount_cents', 0));

            if ($amountCents <= 0) {
                continue;
            }

            $label = trim((string) data_get($line, 'label', ''));
            $description = trim((string) data_get($line, 'description', ''));

            $lines[] = [
                'code' => trim((string) data_get($line, 'code', 'line_item')) ?: 'line_item',
                'type' => trim((string) data_get($line, 'type', 'one_time')) ?: 'one_time',
                'description' => $label !== '' ? $label . ($description !== '' ? ' - ' . $description : '') : ($description !== '' ? $description : $fallbackDescription),
                'amount_cents' => $amountCents,
            ];
        }

        if ($lines !== []) {
            return $lines;
        }

        return [[
            'code' => 'default',
            'type' => 'one_time',
            'description' => $fallbackDescription,
            'amount_cents' => (int) $intent->amount_cents,
        ]];
    }

    private function centsToDecimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function assertPaidInvoiceMatchesCapturedGross(PaymentIntent $intent, int $computedGrossCents): void
    {
        $isPaid = $intent->paid_at !== null || in_array((string) $intent->status, ['paid', 'settled'], true);
        if (! $isPaid) {
            return;
        }

        $capturedGross = (int) $intent->amount_cents;
        if ($computedGrossCents !== $capturedGross) {
            throw new RuntimeException(sprintf(
                'Invoice gross mismatch for paid payment intent %s (captured=%d, computed=%d).',
                (string) $intent->id,
                $capturedGross,
                $computedGrossCents
            ));
        }
    }

    private function nextInvoiceNumber(int $year): string
    {
        return DB::transaction(function () use ($year): string {
            $row = DB::table('invoice_sequences')
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                DB::table('invoice_sequences')->insert([
                    'year' => $year,
                    'next_number' => 2,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $sequence = 1;
            } else {
                $sequence = (int) $row->next_number;

                DB::table('invoice_sequences')
                    ->where('year', $year)
                    ->update([
                        'next_number' => $sequence + 1,
                        'updated_at' => now(),
                    ]);
            }

            return sprintf('PL%d%05d', $year, $sequence);
        });
    }

    private function resolveIssuerLogoDataUri(string $logoPath): ?string
    {
        $logoPath = trim($logoPath);
        if ($logoPath === '') {
            return null;
        }

        $fullPath = public_path(ltrim($logoPath, '/'));
        if (! is_file($fullPath)) {
            return null;
        }

        $contents = @file_get_contents($fullPath);
        if ($contents === false) {
            return null;
        }

        $extension = strtolower((string) pathinfo($fullPath, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };

        return 'data:' . $mime . ';base64,' . base64_encode($contents);
    }

    private function buildPdfData(Invoice $invoice): InvoicePdfData
    {
        $issuer = $this->settings->getInvoiceIssuerProfile();

        return InvoicePdfData::fromInvoice(
            $invoice,
            $issuer,
            $this->resolveIssuerLogoDataUri((string) ($issuer['logo_path'] ?? ''))
        );
    }
}
