<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentIntent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class InvoiceCorrectionService
{
    public function __construct(private readonly InvoiceService $invoices)
    {
    }

    /**
     * @return array{payment_gross_cents:int,current_gross_cents:int,current_vat_cents:int,expected_net_cents:int,expected_vat_cents:int,expected_gross_cents:int,is_incorrect:bool}
     */
    public function detectIncorrect(Invoice $invoice): array
    {
        $invoice->loadMissing('paymentIntent', 'creditPackPurchase');

        $paymentGrossCents = $this->resolvePaymentGrossCents($invoice);
        $currentGrossCents = $this->resolveInvoiceGrossCents($invoice);
        $currentVatCents = $this->resolveInvoiceVatCents($invoice);

        $vat = [
            'rate' => (float) ($invoice->vat_rate ?? 0),
            'reverse_charge' => (bool) ($invoice->reverse_charge ?? false),
            'type' => (string) ($invoice->vat_type ?? ''),
        ];

        $pricingMode = (string) ($invoice->pricing_mode ?: config('billing.pricing_mode', 'vat_inclusive'));
        $expected = $this->invoices->calculateAmounts($paymentGrossCents, $vat, $pricingMode);

        $isIncorrect = $paymentGrossCents > 0 && (
            $currentGrossCents !== $paymentGrossCents
            || ($currentGrossCents - $paymentGrossCents) === $currentVatCents
        );

        return [
            'payment_gross_cents' => $paymentGrossCents,
            'current_gross_cents' => $currentGrossCents,
            'current_vat_cents' => $currentVatCents,
            'expected_net_cents' => $expected['subtotal_net_cents'],
            'expected_vat_cents' => $expected['vat_amount_cents'],
            'expected_gross_cents' => $expected['total_gross_cents'],
            'is_incorrect' => $isIncorrect,
        ];
    }

    public function repairWithCreditNote(Invoice $original, array $detected, string $batchId, string $reason): array
    {
        if ($original->corrected_at || $original->replacementInvoices()->exists()) {
            return [
                'credit_note' => $original->creditNotes()->first(),
                'replacement' => $original->replacementInvoices()->first(),
                'skipped' => true,
            ];
        }

        return DB::transaction(function () use ($original, $detected, $batchId, $reason): array {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($original->id);
            if ($locked->corrected_at || $locked->replacementInvoices()->exists()) {
                return [
                    'credit_note' => $locked->creditNotes()->first(),
                    'replacement' => $locked->replacementInvoices()->first(),
                    'skipped' => true,
                ];
            }

            $creditNote = $this->createCreditNote($locked, $batchId, $reason);
            $replacement = $this->createReplacementInvoice($locked, $detected, $batchId, $reason);

            $locked->status = 'voided_for_correction';
            $locked->corrected_at = now();
            $locked->correction_reason = $reason;
            $locked->corrected_by_batch_id = $batchId;
            $meta = is_array($locked->meta) ? $locked->meta : [];
            $meta['correction'] = [
                'strategy' => 'credit_note',
                'credit_note_invoice_id' => (string) $creditNote->id,
                'replacement_invoice_id' => (string) $replacement->id,
                'batch_id' => $batchId,
            ];
            $locked->meta = $meta;
            $locked->save();

            return [
                'credit_note' => $creditNote,
                'replacement' => $replacement,
                'skipped' => false,
            ];
        });
    }

    public function repairInPlace(Invoice $invoice, array $detected, string $batchId, string $reason): Invoice
    {
        if (! (bool) config('billing.allow_in_place_invoice_correction', false)) {
            throw new RuntimeException('In-place invoice correction is disabled by configuration.');
        }

        return DB::transaction(function () use ($invoice, $detected, $batchId, $reason): Invoice {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->corrected_at && (string) $locked->corrected_by_batch_id === $batchId) {
                return $locked;
            }

            if ($locked->pdf_path && Storage::disk('local')->exists((string) $locked->pdf_path)) {
                $archivePath = sprintf(
                    'invoices/archive/%s/%s-%s.pdf',
                    now()->format('Y'),
                    $locked->number,
                    now()->format('YmdHis')
                );
                Storage::disk('local')->copy((string) $locked->pdf_path, $archivePath);
                $locked->pdf_path_previous = $archivePath;
            }

            Invoice::$allowInPlaceMutation = true;
            try {
                $locked->pricing_mode = 'vat_inclusive';
                $locked->subtotal_net = $this->centsToDecimal($detected['expected_net_cents']);
                $locked->vat_amount = $this->centsToDecimal($detected['expected_vat_cents']);
                $locked->total_gross = $this->centsToDecimal($detected['expected_gross_cents']);
                $locked->subtotal_cents = $detected['expected_net_cents'];
                $locked->tax_cents = $detected['expected_vat_cents'];
                $locked->total_cents = $detected['expected_gross_cents'];
                $locked->corrected_at = now();
                $locked->correction_reason = $reason;
                $locked->corrected_by_batch_id = $batchId;
                $locked->save();
            } finally {
                Invoice::$allowInPlaceMutation = false;
            }

            $this->updateInvoiceItemsForInPlace($locked, $detected);
            $this->invoices->generatePdf($locked->fresh(['organization', 'items']), true, true);

            return $locked->fresh(['items']);
        });
    }

    private function createCreditNote(Invoice $original, string $batchId, string $reason): Invoice
    {
        $number = $this->nextInvoiceNumber((int) now()->format('Y'));
        $net = $this->resolveInvoiceNetCents($original);
        $vat = $this->resolveInvoiceVatCents($original);
        $gross = $this->resolveInvoiceGrossCents($original);

        $credit = Invoice::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => $original->organization_id,
            'subscription_id' => $original->subscription_id,
            'payment_intent_id' => null,
            'credit_pack_purchase_id' => $original->credit_pack_purchase_id,
            'type' => $original->type,
            'document_type' => 'credit_note',
            'number' => $number,
            'status' => 'issued',
            'currency' => $original->currency,
            'pricing_mode' => 'vat_inclusive',
            'subtotal_net' => $this->centsToDecimal(-1 * $net),
            'vat_amount' => $this->centsToDecimal(-1 * $vat),
            'total_gross' => $this->centsToDecimal(-1 * $gross),
            'subtotal_cents' => $net,
            'tax_cents' => $vat,
            'total_cents' => $gross,
            'vat_rate' => $original->vat_rate,
            'vat_type' => $original->vat_type,
            'reverse_charge' => $original->reverse_charge,
            'issued_at' => now(),
            'paid_at' => $original->paid_at,
            'billing_company_name' => $original->billing_company_name,
            'billing_address_line1' => $original->billing_address_line1,
            'billing_address_line2' => $original->billing_address_line2,
            'billing_postal_code' => $original->billing_postal_code,
            'billing_city' => $original->billing_city,
            'billing_country_code' => $original->billing_country_code,
            'billing_vat_number' => $original->billing_vat_number,
            'billing_kvk_number' => $original->billing_kvk_number,
            'credit_note_for_invoice_id' => $original->id,
            'corrected_by_batch_id' => $batchId,
            'correction_reason' => $reason,
            'meta' => array_merge(is_array($original->meta) ? $original->meta : [], [
                'correction' => [
                    'origin_invoice_id' => (string) $original->id,
                    'strategy' => 'credit_note',
                ],
            ]),
        ]);

        $credit->items()->create([
            'description' => 'Credit note for invoice ' . $original->number,
            'quantity' => -1,
            'unit_price_cents' => $net,
            'unit_price_net' => $this->centsToDecimal($net),
            'subtotal_cents' => $net,
            'line_total_net' => $this->centsToDecimal(-1 * $net),
            'tax_rate' => $original->vat_rate,
            'tax_cents' => $vat,
            'vat_amount' => $this->centsToDecimal(-1 * $vat),
            'total_cents' => $gross,
            'line_total_gross' => $this->centsToDecimal(-1 * $gross),
            'meta' => ['credit_note_for_invoice_id' => (string) $original->id],
        ]);

        $credit->immutable_hash = $this->buildImmutableHash($credit);
        $credit->save();

        return $this->invoices->generatePdf($credit->fresh(['organization', 'items']), true, true);
    }

    private function createReplacementInvoice(Invoice $original, array $detected, string $batchId, string $reason): Invoice
    {
        $number = $this->nextInvoiceNumber((int) now()->format('Y'));

        $replacement = Invoice::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => $original->organization_id,
            'subscription_id' => $original->subscription_id,
            'payment_intent_id' => null,
            'credit_pack_purchase_id' => $original->credit_pack_purchase_id,
            'type' => $original->type,
            'document_type' => 'invoice',
            'number' => $number,
            'status' => 'issued',
            'currency' => $original->currency,
            'pricing_mode' => 'vat_inclusive',
            'subtotal_net' => $this->centsToDecimal($detected['expected_net_cents']),
            'vat_amount' => $this->centsToDecimal($detected['expected_vat_cents']),
            'total_gross' => $this->centsToDecimal($detected['expected_gross_cents']),
            'subtotal_cents' => $detected['expected_net_cents'],
            'tax_cents' => $detected['expected_vat_cents'],
            'total_cents' => $detected['expected_gross_cents'],
            'vat_rate' => $original->vat_rate,
            'vat_type' => $original->vat_type,
            'reverse_charge' => $original->reverse_charge,
            'issued_at' => now(),
            'paid_at' => $original->paid_at,
            'billing_company_name' => $original->billing_company_name,
            'billing_address_line1' => $original->billing_address_line1,
            'billing_address_line2' => $original->billing_address_line2,
            'billing_postal_code' => $original->billing_postal_code,
            'billing_city' => $original->billing_city,
            'billing_country_code' => $original->billing_country_code,
            'billing_vat_number' => $original->billing_vat_number,
            'billing_kvk_number' => $original->billing_kvk_number,
            'replaces_invoice_id' => $original->id,
            'corrected_by_batch_id' => $batchId,
            'correction_reason' => $reason,
            'meta' => array_merge(is_array($original->meta) ? $original->meta : [], [
                'correction' => [
                    'origin_invoice_id' => (string) $original->id,
                    'strategy' => 'replacement',
                ],
                'original_payment_intent_id' => (string) ($original->payment_intent_id ?? ''),
            ]),
        ]);

        $replacement->items()->create([
            'description' => $this->resolveReplacementLineDescription($original),
            'quantity' => 1,
            'unit_price_cents' => $detected['expected_net_cents'],
            'unit_price_net' => $this->centsToDecimal($detected['expected_net_cents']),
            'subtotal_cents' => $detected['expected_net_cents'],
            'line_total_net' => $this->centsToDecimal($detected['expected_net_cents']),
            'tax_rate' => $original->vat_rate,
            'tax_cents' => $detected['expected_vat_cents'],
            'vat_amount' => $this->centsToDecimal($detected['expected_vat_cents']),
            'total_cents' => $detected['expected_gross_cents'],
            'line_total_gross' => $this->centsToDecimal($detected['expected_gross_cents']),
            'meta' => ['replaces_invoice_id' => (string) $original->id],
        ]);

        $replacement->immutable_hash = $this->buildImmutableHash($replacement);
        $replacement->save();

        return $this->invoices->generatePdf($replacement->fresh(['organization', 'items']), true, true);
    }

    private function updateInvoiceItemsForInPlace(Invoice $invoice, array $detected): void
    {
        $item = InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('id')->first();
        if (! $item) {
            $invoice->items()->create([
                'description' => $this->resolveReplacementLineDescription($invoice),
                'quantity' => 1,
                'unit_price_cents' => $detected['expected_net_cents'],
                'unit_price_net' => $this->centsToDecimal($detected['expected_net_cents']),
                'subtotal_cents' => $detected['expected_net_cents'],
                'line_total_net' => $this->centsToDecimal($detected['expected_net_cents']),
                'tax_rate' => $invoice->vat_rate,
                'tax_cents' => $detected['expected_vat_cents'],
                'vat_amount' => $this->centsToDecimal($detected['expected_vat_cents']),
                'total_cents' => $detected['expected_gross_cents'],
                'line_total_gross' => $this->centsToDecimal($detected['expected_gross_cents']),
                'meta' => ['corrected_in_place' => true],
            ]);

            return;
        }

        $item->quantity = 1;
        $item->unit_price_cents = $detected['expected_net_cents'];
        $item->unit_price_net = $this->centsToDecimal($detected['expected_net_cents']);
        $item->subtotal_cents = $detected['expected_net_cents'];
        $item->line_total_net = $this->centsToDecimal($detected['expected_net_cents']);
        $item->tax_cents = $detected['expected_vat_cents'];
        $item->vat_amount = $this->centsToDecimal($detected['expected_vat_cents']);
        $item->total_cents = $detected['expected_gross_cents'];
        $item->line_total_gross = $this->centsToDecimal($detected['expected_gross_cents']);
        $item->tax_rate = $invoice->vat_rate;
        $item->save();
    }

    private function resolvePaymentGrossCents(Invoice $invoice): int
    {
        if ($invoice->paymentIntent) {
            return (int) $invoice->paymentIntent->amount_cents;
        }

        if ($invoice->creditPackPurchase) {
            return (int) $invoice->creditPackPurchase->price_cents;
        }

        return 0;
    }

    private function resolveInvoiceGrossCents(Invoice $invoice): int
    {
        if ($invoice->total_gross !== null) {
            return (int) round(((float) $invoice->total_gross) * 100);
        }

        return (int) $invoice->total_cents;
    }

    private function resolveInvoiceNetCents(Invoice $invoice): int
    {
        if ($invoice->subtotal_net !== null) {
            return (int) round(((float) $invoice->subtotal_net) * 100);
        }

        return (int) $invoice->subtotal_cents;
    }

    private function resolveInvoiceVatCents(Invoice $invoice): int
    {
        if ($invoice->vat_amount !== null) {
            return (int) round(((float) $invoice->vat_amount) * 100);
        }

        return (int) $invoice->tax_cents;
    }

    private function resolveReplacementLineDescription(Invoice $invoice): string
    {
        return match ((string) $invoice->type) {
            'credit_pack' => 'Credit pack purchase (corrected VAT inclusive)',
            'plan_change_adjustment' => 'Plan change adjustment (corrected VAT inclusive)',
            default => 'Subscription payment (corrected VAT inclusive)',
        };
    }

    private function centsToDecimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
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

    private function buildImmutableHash(Invoice $invoice): string
    {
        return hash('sha256', json_encode([
            'invoice_id' => $invoice->id,
            'number' => $invoice->number,
            'document_type' => $invoice->document_type,
            'type' => $invoice->type,
            'subtotal_net' => $invoice->subtotal_net,
            'vat_amount' => $invoice->vat_amount,
            'total_gross' => $invoice->total_gross,
            'issued_at' => optional($invoice->issued_at)?->toIso8601String(),
        ]));
    }
}
