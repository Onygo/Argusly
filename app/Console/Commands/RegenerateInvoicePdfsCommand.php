<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class RegenerateInvoicePdfsCommand extends Command
{
    protected $signature = 'invoices:regenerate-pdfs
        {--from= : Regenerate invoices issued at or after this date (Y-m-d)}
        {--to= : Regenerate invoices issued at or before this date (Y-m-d)}
        {--organization= : Restrict to this organization id}
        {--ids= : Comma-separated invoice ids}
        {--dry-run : Only show how many invoices match}';

    protected $description = 'Regenerate stored invoice PDFs using current template and existing invoice data.';

    public function handle(InvoiceService $invoices): int
    {
        $from = $this->parseDate((string) $this->option('from'), true);
        $to = $this->parseDate((string) $this->option('to'), false);
        $organizationOption = trim((string) $this->option('organization'));
        $ids = collect(explode(',', (string) $this->option('ids')))
            ->map(fn (string $id): string => trim($id))
            ->filter()
            ->values();
        $dryRun = (bool) $this->option('dry-run');

        if ((string) $this->option('from') !== '' && ! $from) {
            $this->error('Invalid --from date, expected Y-m-d.');

            return self::INVALID;
        }
        if ((string) $this->option('to') !== '' && ! $to) {
            $this->error('Invalid --to date, expected Y-m-d.');

            return self::INVALID;
        }
        if ($organizationOption !== '' && ! ctype_digit($organizationOption)) {
            $this->error('Invalid --organization value, expected numeric organization id.');

            return self::INVALID;
        }

        $query = Invoice::query()
            ->with(['organization', 'items', 'paymentIntent', 'creditPackPurchase'])
            ->orderBy('issued_at')
            ->orderBy('id');

        if ($from) {
            $query->where('issued_at', '>=', $from);
        }
        if ($to) {
            $query->where('issued_at', '<=', $to);
        }
        if ($organizationOption !== '') {
            $query->where('organization_id', (int) $organizationOption);
        }
        if ($ids->isNotEmpty()) {
            $this->applyIdFilter($query, $ids->all());
        }

        $total = (clone $query)->count();
        $this->info('Invoices matched: ' . $total);

        if ($dryRun) {
            return self::SUCCESS;
        }

        $regenerated = 0;
        $failed = 0;

        $query->chunkById(100, function ($batch) use ($invoices, &$regenerated, &$failed): void {
            foreach ($batch as $invoice) {
                try {
                    $invoices->generatePdf($invoice, true, true);
                    $regenerated++;
                } catch (\Throwable $exception) {
                    $failed++;
                    $this->warn(sprintf(
                        'Failed invoice %s (%s): %s',
                        (string) $invoice->id,
                        (string) $invoice->number,
                        $exception->getMessage()
                    ));
                }
            }
        });

        $this->table(['metric', 'count'], [
            ['matched', $total],
            ['regenerated', $regenerated],
            ['failed', $failed],
        ]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function parseDate(string $value, bool $startOfDay): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value);

            return $startOfDay ? $date->startOfDay() : $date->endOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int,string> $ids
     */
    private function applyIdFilter(Builder $query, array $ids): void
    {
        $query->whereIn('id', $ids);
    }
}
