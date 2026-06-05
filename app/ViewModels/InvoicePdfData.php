<?php

namespace App\ViewModels;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Carbon\CarbonInterface;

class InvoicePdfData
{
    /**
     * @param array<int,string> $sellerLines
     * @param array<int,string> $billedToLines
     * @param array<int,array{label:string,value:string}> $metaFields
     * @param array<int,array{description:string,qty:string,unit_net:string,line_net:string,vat_label:string,line_gross:string}> $items
     */
    public function __construct(
        public readonly array $sellerLines,
        public readonly array $billedToLines,
        public readonly array $metaFields,
        public readonly array $items,
        public readonly string $subtotalNet,
        public readonly string $vatLabel,
        public readonly string $vatAmount,
        public readonly string $totalGross,
        public readonly string $currencyLabel,
        public readonly string $currencySymbol,
        public readonly ?string $notes,
        public readonly ?string $terms,
        public readonly ?string $footerLine,
        public readonly ?string $issuerLogoDataUri
    ) {
    }

    /**
     * @param array<string,mixed> $issuer
     */
    public static function fromInvoice(Invoice $invoice, array $issuer, ?string $issuerLogoDataUri = null): self
    {
        $currency = strtoupper((string) $invoice->currency ?: 'EUR');

        $sellerLines = self::nonEmpty([
            (string) ($issuer['company_name'] ?? 'PublishLayer'),
            (string) ($issuer['address_line1'] ?? ''),
            (string) ($issuer['address_line2'] ?? ''),
            trim((string) (($issuer['postal_code'] ?? '') . ' ' . ($issuer['city'] ?? ''))),
            strtoupper((string) ($issuer['country_code'] ?? 'NL')),
            self::prefixed('VAT', $issuer['vat_number'] ?? null),
            self::prefixed('KvK', $issuer['kvk_number'] ?? null),
            self::prefixed('Email', $issuer['email'] ?? null),
            self::prefixed('Web', $issuer['website'] ?? null),
        ]);

        $billedToLines = self::nonEmpty([
            (string) ($invoice->billing_company_name ?? ''),
            (string) ($invoice->billing_address_line1 ?? ''),
            (string) ($invoice->billing_address_line2 ?? ''),
            trim((string) (($invoice->billing_postal_code ?? '') . ' ' . ($invoice->billing_city ?? ''))),
            strtoupper((string) ($invoice->billing_country_code ?? '')),
            self::prefixed('VAT', $invoice->billing_vat_number),
            self::prefixed('KvK', $invoice->billing_kvk_number),
        ]);

        $metaFields = self::nonEmptyMeta([
            ['label' => 'Invoice number', 'value' => (string) $invoice->number],
            ['label' => 'Issue date', 'value' => self::formatDate($invoice->issued_at)],
            ['label' => 'Due date', 'value' => self::formatDate($invoice->issued_at)],
            ['label' => 'Status', 'value' => ucfirst((string) $invoice->status)],
            ['label' => 'Payment reference', 'value' => (string) ($invoice->paymentIntent?->provider_payment_id ?: $invoice->paymentIntent?->id ?: $invoice->creditPackPurchase?->provider_payment_id ?: '-')],
        ]);

        $vatRate = (float) ($invoice->vat_rate ?? 0);
        $vatLabel = $vatRate > 0 ? rtrim(rtrim(number_format($vatRate, 2, '.', ''), '0'), '.') . '%' : '0%';

        return new self(
            sellerLines: $sellerLines,
            billedToLines: $billedToLines,
            metaFields: $metaFields,
            items: self::mapItems($invoice, $currency),
            subtotalNet: self::formatMoney(self::valueFromInvoice($invoice->subtotal_net, $invoice->subtotal_cents), $currency),
            vatLabel: $vatLabel,
            vatAmount: self::formatMoney(self::valueFromInvoice($invoice->vat_amount, $invoice->tax_cents), $currency),
            totalGross: self::formatMoney(self::valueFromInvoice($invoice->total_gross, $invoice->total_cents), $currency),
            currencyLabel: $currency,
            currencySymbol: self::currencySymbol($currency),
            notes: self::normalizeText(data_get($invoice->meta, 'notes')),
            terms: self::normalizeText(data_get($invoice->meta, 'terms')),
            footerLine: self::buildFooterLine($issuer),
            issuerLogoDataUri: $issuerLogoDataUri,
        );
    }

    /**
     * @param array<string,mixed> $issuer
     */
    private static function buildFooterLine(array $issuer): ?string
    {
        $parts = self::nonEmpty([
            (string) ($issuer['company_name'] ?? ''),
            trim((string) ($issuer['email'] ?? '')),
            trim((string) ($issuer['website'] ?? '')),
        ]);

        if ($parts === []) {
            return null;
        }

        return implode(' · ', $parts);
    }

    private static function normalizeText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    /**
     * @return array<int,array{description:string,qty:string,unit_net:string,line_net:string,vat_label:string,line_gross:string}>
     */
    private static function mapItems(Invoice $invoice, string $currency): array
    {
        return $invoice->items->map(function (InvoiceItem $item) use ($currency): array {
            $taxRate = (float) ($item->tax_rate ?? 0);
            $itemVatLabel = $taxRate > 0 ? rtrim(rtrim(number_format($taxRate, 2, '.', ''), '0'), '.') . '%' : '0%';

            return [
                'description' => (string) $item->description,
                'qty' => number_format((float) $item->quantity, 2, '.', ''),
                'unit_net' => self::formatMoney(self::valueFromInvoice($item->unit_price_net, $item->unit_price_cents), $currency),
                'line_net' => self::formatMoney(self::valueFromInvoice($item->line_total_net, $item->subtotal_cents), $currency),
                'vat_label' => $itemVatLabel,
                'line_gross' => self::formatMoney(self::valueFromInvoice($item->line_total_gross, $item->total_cents), $currency),
            ];
        })->values()->all();
    }

    private static function valueFromInvoice(mixed $decimalValue, mixed $centsValue): float
    {
        if ($decimalValue !== null && $decimalValue !== '') {
            return (float) $decimalValue;
        }

        return ((int) ($centsValue ?? 0)) / 100;
    }

    private static function prefixed(string $label, mixed $value): string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? '' : sprintf('%s %s', $label, $text);
    }

    /**
     * @param array<int,string> $lines
     * @return array<int,string>
     */
    private static function nonEmpty(array $lines): array
    {
        return array_values(array_filter(array_map(static fn (string $line): string => trim($line), $lines), static fn (string $line): bool => $line !== ''));
    }

    /**
     * @param array<int,array{label:string,value:string}> $fields
     * @return array<int,array{label:string,value:string}>
     */
    private static function nonEmptyMeta(array $fields): array
    {
        return array_values(array_filter($fields, static fn (array $field): bool => trim((string) ($field['value'] ?? '')) !== ''));
    }

    private static function formatDate(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d');
        }

        if ($value === null || $value === '') {
            return '';
        }

        return (string) $value;
    }

    public static function formatMoney(float $amount, string $currency): string
    {
        $symbol = self::currencySymbol($currency);

        return sprintf('%s %s', $symbol, number_format($amount, 2, ',', '.'));
    }

    private static function currencySymbol(string $currency): string
    {
        return match (strtoupper($currency)) {
            'EUR' => '€',
            'USD' => '$',
            'GBP' => 'GBP',
            default => strtoupper($currency),
        };
    }
}
