<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\InvoiceCorrectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RecalculateVatInclusiveInvoicesCommand extends Command
{
    protected $signature = 'invoices:recalculate-vat-inclusive
        {--dry-run}
        {--invoice_id=}
        {--org_id=}
        {--limit=500}
        {--batch-id=}';

    protected $description = 'Recalculate VAT-inclusive historical invoices and regenerate versioned PDFs safely.';

    public function handle(InvoiceCorrectionService $corrections): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $batchId = (string) ($this->option('batch-id') ?: (string) Str::uuid());

        if (! Str::isUuid($batchId)) {
            $this->error('Invalid --batch-id, expected UUID.');

            return self::INVALID;
        }

        $orgIdOption = trim((string) $this->option('org_id'));
        if ($orgIdOption !== '' && ! ctype_digit($orgIdOption)) {
            $this->error('Invalid --org_id value.');

            return self::INVALID;
        }

        $query = Invoice::query()
            ->with(['paymentIntent', 'creditPackPurchase', 'items'])
            ->where('document_type', 'invoice')
            ->whereIn('status', ['issued', 'paid'])
            ->orderBy('issued_at')
            ->orderBy('id');

        if ($orgIdOption !== '') {
            $query->where('organization_id', (int) $orgIdOption);
        }

        $invoiceId = trim((string) $this->option('invoice_id'));
        if ($invoiceId !== '') {
            $query->where('id', $invoiceId);
        }

        $summary = [
            'scanned' => 0,
            'detected_wrong' => 0,
            'corrected' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($query->limit($limit)->get() as $invoice) {
            $summary['scanned']++;
            $detected = $corrections->detectIncorrect($invoice);

            if (! $detected['is_incorrect']) {
                $summary['skipped']++;
                continue;
            }

            $summary['detected_wrong']++;

            if ($dryRun) {
                continue;
            }

            try {
                $corrections->repairInPlace(
                    invoice: $invoice,
                    detected: $detected,
                    batchId: $batchId,
                    reason: 'VAT-inclusive recalculation command'
                );
                $summary['corrected']++;
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $this->warn(sprintf('Failed invoice %s: %s', $invoice->id, Str::limit($exception->getMessage(), 140, '')));
            }
        }

        $this->table(['metric', 'count'], [
            ['scanned', $summary['scanned']],
            ['detected_wrong', $summary['detected_wrong']],
            ['corrected', $summary['corrected']],
            ['skipped', $summary['skipped']],
            ['failed', $summary['failed']],
        ]);

        $this->info('batch_id=' . $batchId);

        return self::SUCCESS;
    }
}
