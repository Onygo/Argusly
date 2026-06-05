<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\BillingSettingsService;
use App\ViewModels\InvoicePdfData;
use Illuminate\Console\Command;

class InvoiceHtmlPreviewCommand extends Command
{
    protected $signature = 'billing:invoice-html-preview
        {invoice_id : Invoice UUID/id}
        {--output= : Output absolute path or storage-relative filename}
        {--stdout : Print HTML to stdout}
        {--force : Allow running outside local environment}';

    protected $description = 'Render invoice HTML preview (no PDF) for template inspection';

    public function handle(BillingSettingsService $settings): int
    {
        if (! app()->environment('local') && ! (bool) $this->option('force')) {
            $this->error('This command is local-only by default. Use --force to run in other environments.');

            return self::FAILURE;
        }

        $invoiceId = (string) $this->argument('invoice_id');
        $invoice = Invoice::query()
            ->with(['organization', 'items', 'paymentIntent', 'creditPackPurchase'])
            ->find($invoiceId);

        if (! $invoice) {
            $this->error('Invoice not found: ' . $invoiceId);

            return self::FAILURE;
        }

        $issuer = $settings->getInvoiceIssuerProfile();
        $pdfData = InvoicePdfData::fromInvoice($invoice, $issuer, null);

        $html = view('pdf.invoice', [
            'invoice' => $invoice,
            'pdf' => $pdfData,
        ])->render();

        if ((bool) $this->option('stdout')) {
            $this->line($html);
        }

        $output = trim((string) $this->option('output'));
        if ($output === '') {
            $output = storage_path('app/reports/invoice-html-preview-' . $invoice->number . '.html');
        } elseif (! str_starts_with($output, '/')) {
            $output = storage_path('app/' . ltrim($output, '/'));
        }

        $dir = dirname($output);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($output, $html);

        $this->info('HTML preview written: ' . $output);

        return self::SUCCESS;
    }
}
