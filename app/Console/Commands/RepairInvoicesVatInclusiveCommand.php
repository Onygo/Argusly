<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\InvoiceCorrectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RepairInvoicesVatInclusiveCommand extends Command
{
    protected $signature = 'billing:repair-invoices-vat-inclusive
        {--dry-run}
        {--from=}
        {--to=}
        {--org_id=}
        {--invoice_id=}
        {--limit=500}
        {--strategy=credit-note}
        {--batch-id=}';

    protected $description = 'Repair incorrectly issued VAT invoices by applying credit-note/replacement or in-place correction.';

    public function handle(InvoiceCorrectionService $repair): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $strategy = (string) $this->option('strategy');
        if (! in_array($strategy, ['credit-note', 'in-place'], true)) {
            $this->error('Invalid --strategy. Allowed: credit-note | in-place');

            return self::INVALID;
        }

        if ($strategy === 'in-place' && ! (bool) config('billing.allow_in_place_invoice_correction', false)) {
            $this->error('In-place strategy is disabled by config billing.allow_in_place_invoice_correction=false');

            return self::INVALID;
        }

        $batchId = (string) ($this->option('batch-id') ?: (string) Str::uuid());
        if (! Str::isUuid($batchId)) {
            $this->error('Invalid --batch-id, expected UUID.');

            return self::INVALID;
        }

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

        $orgIdRaw = (string) $this->option('org_id');
        $orgId = $orgIdRaw !== '' && ctype_digit($orgIdRaw) ? (int) $orgIdRaw : null;
        if ($orgIdRaw !== '' && $orgId === null) {
            $this->error('Invalid --org_id.');

            return self::INVALID;
        }

        $invoiceId = trim((string) $this->option('invoice_id'));

        $query = Invoice::query()
            ->with(['paymentIntent', 'creditPackPurchase', 'items'])
            ->whereIn('status', ['issued', 'paid'])
            ->where('document_type', 'invoice')
            ->orderBy('issued_at')
            ->orderBy('id');

        if ($from) {
            $query->whereDate('issued_at', '>=', $from->toDateString());
        }
        if ($to) {
            $query->whereDate('issued_at', '<=', $to->toDateString());
        }
        if ($orgId !== null) {
            $query->where('organization_id', $orgId);
        }
        if ($invoiceId !== '') {
            $query->where('id', $invoiceId);
        }

        $summary = [
            'scanned' => 0,
            'incorrect_found' => 0,
            'corrected' => 0,
            'skipped_already_corrected' => 0,
            'skipped_no_issue' => 0,
            'failed' => 0,
        ];

        $rows = [];
        foreach ($query->limit($limit)->get() as $invoice) {
            $summary['scanned']++;

            $detected = $repair->detectIncorrect($invoice);
            if (! $detected['is_incorrect']) {
                $summary['skipped_no_issue']++;
                $rows[] = $this->csvRow($invoice, $detected, $strategy, 'skipped_no_issue');
                continue;
            }

            $summary['incorrect_found']++;

            if ($invoice->corrected_at || $invoice->replacementInvoices()->exists()) {
                $summary['skipped_already_corrected']++;
                $rows[] = $this->csvRow($invoice, $detected, $strategy, 'skipped_already_corrected');
                continue;
            }

            if ($dryRun) {
                $rows[] = $this->csvRow($invoice, $detected, $strategy, 'dry_run_detected');
                continue;
            }

            try {
                $reason = 'VAT-inclusive correction: original invoice treated gross payment as net.';
                if ($strategy === 'credit-note') {
                    $repair->repairWithCreditNote($invoice, $detected, $batchId, $reason);
                } else {
                    $repair->repairInPlace($invoice, $detected, $batchId, $reason);
                }

                $summary['corrected']++;
                $rows[] = $this->csvRow($invoice, $detected, $strategy, 'corrected');
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $rows[] = $this->csvRow(
                    $invoice,
                    $detected,
                    $strategy,
                    'failed',
                    Str::limit($exception->getMessage(), 160, '')
                );
            }
        }

        $reportPath = $this->writeReport($batchId, $rows);

        $this->table(['metric', 'count'], [
            ['scanned', $summary['scanned']],
            ['incorrect_found', $summary['incorrect_found']],
            ['corrected', $summary['corrected']],
            ['skipped_already_corrected', $summary['skipped_already_corrected']],
            ['skipped_no_issue', $summary['skipped_no_issue']],
            ['failed', $summary['failed']],
        ]);

        $this->info('Batch: ' . $batchId);
        $this->info('Report: storage/app/' . $reportPath);

        return self::SUCCESS;
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

    private function writeReport(string $batchId, array $rows): string
    {
        $path = sprintf('reports/invoice-vat-repair-%s.csv', $batchId);
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'invoice_id', 'org_id', 'payment_id', 'old_total', 'new_total', 'old_vat', 'new_vat', 'strategy', 'result', 'error',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['invoice_id'],
                $row['org_id'],
                $row['payment_id'],
                $row['old_total'],
                $row['new_total'],
                $row['old_vat'],
                $row['new_vat'],
                $row['strategy'],
                $row['result'],
                $row['error'],
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        Storage::disk('local')->put($path, $csv ?: '');

        return $path;
    }

    private function csvRow(Invoice $invoice, array $detected, string $strategy, string $result, string $error = ''): array
    {
        return [
            'invoice_id' => (string) $invoice->id,
            'org_id' => (string) $invoice->organization_id,
            'payment_id' => (string) ($invoice->payment_intent_id ?: ''),
            'old_total' => number_format($detected['current_gross_cents'] / 100, 2, '.', ''),
            'new_total' => number_format($detected['expected_gross_cents'] / 100, 2, '.', ''),
            'old_vat' => number_format($detected['current_vat_cents'] / 100, 2, '.', ''),
            'new_vat' => number_format($detected['expected_vat_cents'] / 100, 2, '.', ''),
            'strategy' => $strategy,
            'result' => $result,
            'error' => $error,
        ];
    }
}
