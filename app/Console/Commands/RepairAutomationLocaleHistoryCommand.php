<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Models\ContentAutomationRun;
use App\Services\ContentAutomation\AutomationRunItemStateService;
use Illuminate\Console\Command;

class RepairAutomationLocaleHistoryCommand extends Command
{
    protected $signature = 'automations:repair-locale-history
        {--run-id= : Repair a specific run}
        {--automation-id= : Repair runs for a specific automation}
        {--dry-run : Preview changes only}';

    protected $description = 'Repair automation locale history from real content, translation, and publication records.';

    public function handle(AutomationRunItemStateService $stateService): int
    {
        $runId = trim((string) $this->option('run-id'));
        $automationId = trim((string) $this->option('automation-id'));
        $dryRun = (bool) $this->option('dry-run');

        $runs = ContentAutomationRun::query()
            ->with('items')
            ->when($runId !== '', fn ($query) => $query->whereKey($runId))
            ->when($automationId !== '', fn ($query) => $query->where('automation_id', $automationId))
            ->latest('created_at')
            ->limit($runId !== '' ? 1 : 250)
            ->get();

        $report = [
            'scanned_runs' => $runs->count(),
            'repaired_runs' => 0,
            'synced_contents' => 0,
        ];

        foreach ($runs as $run) {
            $contents = Content::query()
                ->with(['drafts' => fn ($query) => $query->latest('created_at'), 'publications'])
                ->where('automation_run_id', (string) $run->id)
                ->get();

            $before = [
                'status' => (string) ($run->status?->value ?? $run->status),
                'generated_content_ids' => (array) ($run->generated_content_ids ?? []),
                'published_content_ids' => (array) ($run->published_content_ids ?? []),
            ];

            if (! $dryRun) {
                foreach ($contents as $content) {
                    $stateService->syncFromContent($content);
                    $report['synced_contents']++;
                }

                $stateService->syncRun($run->fresh(['items']) ?? $run);
            }

            $run->refresh();
            $after = [
                'status' => (string) ($run->status?->value ?? $run->status),
                'generated_content_ids' => (array) ($run->generated_content_ids ?? []),
                'published_content_ids' => (array) ($run->published_content_ids ?? []),
            ];

            $changed = $before !== $after;

            if ($dryRun && $contents->isNotEmpty()) {
                foreach ($contents as $content) {
                    $report['synced_contents']++;
                }
            }

            if ($changed || ($dryRun && $contents->isNotEmpty())) {
                $report['repaired_runs']++;
                $this->line(sprintf(
                    '- %s %s -> %s (%d content records)',
                    (string) $run->id,
                    $before['status'],
                    $after['status'],
                    $contents->count(),
                ));
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            collect($report)->map(fn ($value, string $key): array => [$key, (string) $value])->values()->all()
        );

        return self::SUCCESS;
    }
}
