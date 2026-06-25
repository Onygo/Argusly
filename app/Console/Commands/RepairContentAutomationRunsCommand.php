<?php

namespace App\Console\Commands;

use App\Enums\ContentAutomationRunStatus;
use App\Models\Content;
use App\Models\ContentAutomation;
use App\Models\ContentAutomationRun;
use App\Models\ContentAutomationRunItem;
use App\Services\ContentAutomation\AutomationRunItemStateService;
use Illuminate\Console\Command;

class RepairContentAutomationRunsCommand extends Command
{
    protected $signature = 'content-automation:repair-runs
        {automationId? : Optional automation id}
        {--dry-run : Preview changes without writing}
        {--fix : Apply the proposed changes}';

    protected $description = 'Repair stale or inconsistent content automation runs using persisted run items and content records.';

    public function handle(AutomationRunItemStateService $itemStateService): int
    {
        $automationId = trim((string) $this->argument('automationId'));
        $apply = (bool) $this->option('fix');
        $dryRun = ! $apply || (bool) $this->option('dry-run');

        $runs = ContentAutomationRun::query()
            ->with('items')
            ->when($automationId !== '', fn ($query) => $query->where('automation_id', $automationId))
            ->latest('created_at')
            ->limit($automationId !== '' ? 100 : 250)
            ->get();

        $report = [
            'scanned' => $runs->count(),
            'repaired' => 0,
            'stale_runs_marked' => 0,
            'duplicate_items_flagged' => 0,
            'content_links_attached' => 0,
        ];

        foreach ($runs as $run) {
            $automation = ContentAutomation::query()->find($run->automation_id);
            if (! $automation) {
                continue;
            }

            $changed = false;

            foreach (Content::query()->where('automation_run_id', (string) $run->id)->get() as $content) {
                $before = ContentAutomationRunItem::query()
                    ->where('automation_run_id', (string) $run->id)
                    ->where('content_id', (string) $content->id)
                    ->count();

                if (! $dryRun) {
                    $itemStateService->syncFromContent($content);
                }

                $after = ContentAutomationRunItem::query()
                    ->where('automation_run_id', (string) $run->id)
                    ->where('content_id', (string) $content->id)
                    ->count();

                if ($after > $before) {
                    $report['content_links_attached']++;
                    $changed = true;
                }
            }

            $duplicateGroups = ContentAutomationRunItem::query()
                ->select('automation_run_id', 'item_type', 'chain_index', 'locale')
                ->where('automation_run_id', (string) $run->id)
                ->groupBy('automation_run_id', 'item_type', 'chain_index', 'locale')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($duplicateGroups as $group) {
                $duplicates = ContentAutomationRunItem::query()
                    ->where('automation_run_id', (string) $group->automation_run_id)
                    ->where('item_type', (string) $group->item_type)
                    ->where('chain_index', (int) $group->chain_index)
                    ->where('locale', (string) $group->locale)
                    ->orderByRaw('CASE WHEN content_id IS NULL THEN 1 ELSE 0 END')
                    ->orderByRaw('CASE WHEN draft_id IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('created_at')
                    ->get();

                $keeper = $duplicates->shift();
                if (! $keeper) {
                    continue;
                }

                foreach ($duplicates as $duplicate) {
                    $report['duplicate_items_flagged']++;
                    $changed = true;

                    if (! $dryRun) {
                        $duplicate->forceFill([
                            'status' => ContentAutomationRunItem::STATUS_FAILED,
                            'failure_stage' => 'repair',
                            'last_error_code' => 'duplicate_run_item',
                            'last_error_message' => 'Duplicate automation run item flagged during repair.',
                            'metadata' => array_merge(is_array($duplicate->metadata) ? $duplicate->metadata : [], [
                                'duplicate_of_run_item_id' => (string) $keeper->id,
                                'flagged_by' => 'content-automation:repair-runs',
                            ]),
                            'finished_at' => now(),
                        ])->save();
                    }
                }
            }

            $staleRunning = (string) ($run->status?->value ?? $run->status) === ContentAutomationRunStatus::RUNNING->value
                && $run->started_at !== null
                && $run->started_at->lt(now()->subMinutes(30));

            if ($staleRunning) {
                $report['stale_runs_marked']++;
                $changed = true;

                if (! $dryRun) {
                    ContentAutomationRunItem::query()
                        ->where('automation_run_id', (string) $run->id)
                        ->whereIn('status', [
                            ContentAutomationRunItem::STATUS_PLANNED,
                            ContentAutomationRunItem::STATUS_RUNNING,
                        ])
                        ->get()
                        ->each(function (ContentAutomationRunItem $item): void {
                            $item->forceFill([
                                'status' => ContentAutomationRunItem::STATUS_FAILED,
                                'failure_stage' => 'repair',
                                'last_error_code' => 'stale_running_run',
                                'last_error_message' => 'stale running run was still marked active after the repair threshold and was marked failed.',
                                'finished_at' => now(),
                            ])->save();
                        });
                }
            }

            $run->refresh()->load('items');
            $truth = $this->truth($run);
            $newStatus = $this->statusFromTruth($truth, $run);
            $summary = sprintf(
                '%d generated, %d failed, %d partial.',
                (int) $truth['generated_count'],
                (int) $truth['failed_count'],
                (int) $truth['partial_count'],
            );

            if ($changed || (string) ($run->status?->value ?? $run->status) !== $newStatus->value) {
                if (! $dryRun) {
                    $run->forceFill([
                        'status' => $newStatus->value,
                        'generated_content_ids' => $truth['generated_content_ids'],
                        'generated_draft_ids' => $truth['generated_draft_ids'],
                        'result_summary' => $summary,
                        'error_message' => $this->lastError($run) ?: $run->error_message,
                        'finished_at' => $newStatus === ContentAutomationRunStatus::RUNNING ? $run->finished_at : ($run->finished_at ?: now()),
                        'metadata' => array_merge(is_array($run->metadata) ? $run->metadata : [], [
                            'truth' => $truth,
                            'repaired_at' => now()->toIso8601String(),
                        ]),
                    ])->save();

                    if (in_array($newStatus, [ContentAutomationRunStatus::FAILED, ContentAutomationRunStatus::PARTIAL], true)) {
                        $automation->forceFill([
                            'last_failure_message' => $run->fresh()->error_message,
                            'last_failure_code' => (string) data_get($run->fresh()->metadata, 'last_error_code', ''),
                            'last_failure_run_id' => (string) $run->id,
                            'last_failure_at' => now(),
                        ])->save();
                    }
                }

                $report['repaired']++;
            }
        }

        $this->table(['metric', 'count'], collect($report)->map(fn ($value, string $key): array => [$key, (string) $value])->values()->all());

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function truth(ContentAutomationRun $run): array
    {
        $contentIds = Content::query()
            ->where('automation_run_id', (string) $run->id)
            ->pluck('id')
            ->merge(
                $run->items
                    ->pluck('content_id')
                    ->filter()
                    ->map(fn ($id): string => (string) $id)
            )
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        $draftIds = ContentAutomationRunItem::query()
            ->where('automation_run_id', (string) $run->id)
            ->whereNotNull('draft_id')
            ->pluck('draft_id')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        return [
            'generated_count' => count($contentIds),
            'failed_count' => $run->items->where('status', ContentAutomationRunItem::STATUS_FAILED)->count(),
            'partial_count' => $run->items->where('status', ContentAutomationRunItem::STATUS_PARTIAL)->count(),
            'completed_count' => $run->items->where('status', ContentAutomationRunItem::STATUS_COMPLETED)->count(),
            'skipped_count' => $run->items->where('status', ContentAutomationRunItem::STATUS_SKIPPED)->count(),
            'running_count' => $run->items->where('status', ContentAutomationRunItem::STATUS_RUNNING)->count(),
            'planned_count' => $run->items->where('status', ContentAutomationRunItem::STATUS_PLANNED)->count(),
            'generated_content_ids' => $contentIds,
            'generated_draft_ids' => $draftIds,
        ];
    }

    private function statusFromTruth(array $truth, ContentAutomationRun $run): ContentAutomationRunStatus
    {
        $generated = (int) ($truth['generated_count'] ?? 0);
        $failed = (int) ($truth['failed_count'] ?? 0);
        $partial = (int) ($truth['partial_count'] ?? 0);
        $completed = (int) ($truth['completed_count'] ?? 0);
        $pending = (int) (($truth['running_count'] ?? 0) + ($truth['planned_count'] ?? 0));

        return match (true) {
            $generated === 0 && $failed > 0 => ContentAutomationRunStatus::FAILED,
            $generated > 0 && ($failed > 0 || $partial > 0 || $pending > 0) => ContentAutomationRunStatus::PARTIAL,
            $completed > 0 && $pending === 0 && $failed === 0 && $partial === 0 => ContentAutomationRunStatus::COMPLETED,
            $pending > 0 => ContentAutomationRunStatus::RUNNING,
            default => $run->status instanceof ContentAutomationRunStatus ? $run->status : ContentAutomationRunStatus::FAILED,
        };
    }

    private function lastError(ContentAutomationRun $run): string
    {
        $item = $run->items
            ->filter(fn (ContentAutomationRunItem $item): bool => trim((string) $item->last_error_message) !== '')
            ->sortByDesc('updated_at')
            ->first();

        return trim((string) ($item?->last_error_message ?? data_get($run->metadata, 'real_error.message', '')));
    }
}
