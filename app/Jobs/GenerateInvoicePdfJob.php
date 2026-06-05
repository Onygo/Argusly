<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateInvoicePdfJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public string $invoiceId)
    {
        $this->onQueue('billing');
    }

    public function handle(InvoiceService $invoices): void
    {
        $invoice = Invoice::query()->with(['organization', 'items'])->find($this->invoiceId);
        if (! $invoice) {
            return;
        }

        $invoices->generatePdf($invoice, true);
    }
}
