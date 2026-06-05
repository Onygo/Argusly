<?php

namespace App\Console\Commands;

use App\Models\ContentImage;
use App\Models\CreditLedgerEntry;
use App\Services\CreditWalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RefundFailedGenerationsCommand extends Command
{
    protected $signature = 'credits:refund-failed-generations
        {--workspace= : Restrict to one workspace id}
        {--company= : Restrict to one organization/company id}
        {--since= : Only include generations updated at/after this datetime}
        {--dry-run : Show candidates without applying refunds}';

    protected $description = 'Refund credits for failed/canceled/expired generations without output when credits were charged.';

    public function handle(CreditWalletService $wallets): int
    {
        $workspaceId = trim((string) $this->option('workspace'));
        $companyId = trim((string) $this->option('company'));
        $dryRun = (bool) $this->option('dry-run');

        $sinceRaw = trim((string) $this->option('since'));
        $since = null;
        if ($sinceRaw !== '') {
            try {
                $since = Carbon::parse($sinceRaw);
            } catch (\Throwable) {
                $this->error('Invalid --since value. Use a valid datetime string.');

                return self::INVALID;
            }
        }

        $query = ContentImage::query()
            ->with('content.clientSite.workspace')
            ->whereIn('status', ['failed', 'canceled', 'expired'])
            ->orderBy('updated_at');

        if ($workspaceId !== '') {
            $query->whereHas('content', function ($content) use ($workspaceId): void {
                $content->where('workspace_id', $workspaceId);
            });
        }

        if ($companyId !== '') {
            $query->whereHas('content.clientSite.workspace', function ($workspace) use ($companyId): void {
                $workspace->where('organization_id', $companyId);
            });
        }

        if ($since) {
            $query->where('updated_at', '>=', $since);
        }

        $images = $query->get();
        $candidateSummaries = [];
        $candidates = $images->filter(function (ContentImage $image) use (&$candidateSummaries): bool {
            if ($image->hasOutput()) {
                return false;
            }

            $usage = $this->resolveUsageEntry($image);
            if (! $usage) {
                return false;
            }

            $existingRefund = CreditLedgerEntry::query()
                ->where('source_type', ContentImage::class)
                ->where('source_id', $image->id)
                ->where('type', CreditWalletService::TYPE_REFUND)
                ->exists();

            if ($existingRefund) {
                return false;
            }

            $candidateSummaries[(string) $image->id] = [
                'usage_entry_id' => (string) $usage->id,
                'credits' => abs((int) $usage->amount),
            ];

            return true;
        })->values();

        $candidatesCreditsTotal = collect($candidateSummaries)->sum(fn (array $row): int => (int) ($row['credits'] ?? 0));

        $refundedCount = 0;
        $refundedCredits = 0;
        $rows = [];

        foreach ($candidates as $index => $image) {
            $summary = $candidateSummaries[(string) $image->id] ?? ['usage_entry_id' => '', 'credits' => (int) $image->credit_cost];

            $rows[] = [
                'content_image_id' => (string) $image->id,
                'status' => (string) $image->status,
                'credit_status' => (string) $image->credit_status,
                'credit_cost' => (string) (int) ($summary['credits'] ?? 0),
                'updated_at' => optional($image->updated_at)->toDateTimeString() ?: '',
                'reason' => (string) ($image->error_message ?? ''),
            ];

            if ($dryRun) {
                continue;
            }

            $beforeLedgerId = (string) ($image->credit_ledger_entry_id ?? '');
            $entry = $wallets->ensureReleasedForContentImage($image, 'backfill_failed_generation');
            $image->refresh();
            $afterLedgerId = (string) ($image->credit_ledger_entry_id ?? '');

            if ($entry && $afterLedgerId !== '' && $afterLedgerId !== $beforeLedgerId) {
                $refundedCount++;
                $refundedCredits += (int) ($summary['credits'] ?? max(0, (int) $image->credit_cost));
            }
        }

        $this->line('Candidates: ' . $candidates->count());
        $this->line('Credits to refund: ' . ($dryRun ? $candidatesCreditsTotal : $refundedCredits));
        $this->line('Refunded generations: ' . ($dryRun ? 0 : $refundedCount));

        $preview = collect($rows)->take(20)->values()->all();
        if ($preview !== []) {
            $this->table(
                ['content_image_id', 'status', 'credit_status', 'credit_cost', 'updated_at', 'reason'],
                $preview
            );
        }

        return self::SUCCESS;
    }

    private function resolveUsageEntry(ContentImage $image): ?CreditLedgerEntry
    {
        $direct = CreditLedgerEntry::query()
                ->where('source_type', ContentImage::class)
                ->where('source_id', $image->id)
                ->where('type', CreditWalletService::TYPE_USAGE)
                ->latest('created_at')
                ->first();
        if ($direct) {
            return $direct;
        }

        $legacyCandidates = CreditLedgerEntry::query()
            ->where('source_type', \App\Models\Content::class)
            ->where('source_id', (string) $image->content_id)
            ->where('type', CreditWalletService::TYPE_USAGE)
            ->where(function ($query): void {
                $query->whereJsonContains('meta->event', 'content.featured_image.generate')
                    ->orWhereJsonContains('meta->event_type', 'image_generation');
            })
            ->latest('created_at')
            ->limit(10)
            ->get();

        if ($legacyCandidates->isEmpty()) {
            return null;
        }

        $targetTs = optional($image->created_at)?->getTimestamp() ?? now()->getTimestamp();

        return $legacyCandidates->sortBy(function (CreditLedgerEntry $entry) use ($targetTs): int {
            return abs((optional($entry->created_at)?->getTimestamp() ?? $targetTs) - $targetTs);
        })->first();
    }
}
