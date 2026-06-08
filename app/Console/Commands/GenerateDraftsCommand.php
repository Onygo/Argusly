<?php

namespace App\Console\Commands;

use App\Jobs\GenerateDraftJob;
use App\Models\Draft;
use Illuminate\Console\Command;

class GenerateDraftsCommand extends Command
{
    protected $signature = 'argusly:generateDrafts {--limit=10} {--include-failed=0}';
    protected $description = 'Generate draft content_html using the unified LLM pipeline';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $includeFailed = ((int) $this->option('include-failed')) === 1;

        $query = Draft::query()
            ->whereNull('content_html')
            ->where(function ($q) use ($includeFailed) {
                $q->whereIn('status', ['ready', 'queued']);

                if ($includeFailed) {
                    $q->orWhere('status', 'failed');
                }
            })
            ->orderBy('created_at')
            ->limit($limit);

        $draftIds = $query->pluck('id')->all();

        if (count($draftIds) === 0) {
            $this->info('No drafts found to generate (status ready or queued' . ($includeFailed ? ' or failed' : '') . ', content_html is null).');
            return self::SUCCESS;
        }

        $this->info('Found ' . count($draftIds) . ' draft(s) to generate.');

        $processed = 0;
        $succeeded = 0;
        $failed = 0;

        foreach ($draftIds as $draftId) {
            $processed++;

            try {
                GenerateDraftJob::dispatchSync((string) $draftId);
                $draft = Draft::query()->find($draftId);
                if ($draft && in_array((string) $draft->status, ['generated', 'ready_to_deliver', 'delivered', 'published'], true)) {
                    $succeeded++;
                    $this->line('OK ' . (string) $draftId . ' status ' . (string) $draft->status);
                    continue;
                }

                $err = (string) ($draft?->last_error ?? 'AI generation failed');
                $failed++;
                $this->line('FAIL ' . (string) $draftId . ' reason: ' . $err);
            } catch (\Throwable $e) {
                $failed++;
                $this->line('EXCEPTION ' . (string) $draftId . ' reason: ' . $e->getMessage());
            }
        }

        $this->info('Done. Processed: ' . $processed . ', OK: ' . $succeeded . ', Failed: ' . $failed);

        return self::SUCCESS;
    }
}
