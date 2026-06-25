<?php

namespace App\Console\Commands;

use App\Enums\ContentAutomationRunStatus;
use App\Models\Content;
use App\Models\ContentAutomation;
use App\Models\ContentAutomationRun;
use App\Models\ContentAutomationRunItem;
use Illuminate\Console\Command;

class RepairAutomationRunStateCommand extends Command
{
    protected $signature = 'automations:repair-run-state
        {--run-id= : Repair a specific run id}
        {--automation-id= : Repair runs for a specific automation}
        {--dry-run : Preview changes without writing}';

    protected $description = 'Recalculate automation run statuses and counters from persisted content and run items.';

    public function handle(): int
    {
        $runId = trim((string) ($this->option('run-id') ?? ''));
        $automationId = trim((string) ($this->option('automation-id') ?? ''));
        $dryRun = (bool) $this->option('dry-run');

        $runs = ContentAutomationRun::query()
            ->with('items')
            ->when($runId !== '', fn ($query) => $query->where('id', $runId))
            ->when($automationId !== '', fn ($query) => $query->where('automation_id', $automationId))
            ->latest('created_at')
            ->limit($runId !== '' ? 1 : 200)
            ->get();

        $report = [
            'scanned' => $runs->count(),
            'affected' => 0,
            'repaired' => 0,
            'diagnostic_placeholders_added' => 0,
        ];

        foreach ($runs as $run) {
            $truth = $this->truth($run);
            $newStatus = $this->statusFromTruth($truth, $run);
            $contentIds = $truth['generated_content_ids'];
            $draftIds = $run->items
                ->pluck('draft_id')
                ->filter()
                ->map(fn ($id): string => (string) $id)
                ->unique()
                ->values()
                ->all();
            $needsPlaceholder = $truth['intended_count'] > 0
                && $truth['generated_count'] === 0
                && $truth['failed_count'] === 0
                && trim((string) $run->error_message) === '';
            $lastError = $this->lastError($run);
            $hasStaleRunError = $lastError === '' && trim((string) $run->error_message) !== '';
            $hasStaleAutomationFailure = ContentAutomation::query()
                ->whereKey((string) $run->automation_id)
                ->where('last_failure_run_id', (string) $run->id)
                ->exists();

            $currentStatus = (string) ($run->status?->value ?? $run->status);
            $affected = $currentStatus !== $newStatus->value
                || $run->generated_content_ids !== $contentIds
                || $needsPlaceholder
                || $hasStaleRunError
                || (in_array($newStatus, [
                    ContentAutomationRunStatus::COMPLETED,
                    ContentAutomationRunStatus::SKIPPED,
                ], true) && $hasStaleAutomationFailure);

            if (! $affected) {
                continue;
            }

            $report['affected']++;
            $this->line(sprintf(
                '- %s status %s -> %s generated=%d failed=%d partial=%d',
                (string) $run->id,
                $currentStatus,
                $newStatus->value,
                (int) $truth['generated_count'],
                (int) $truth['failed_count'],
                (int) $truth['partial_count'],
            ));

            if ($dryRun) {
                continue;
            }

            if ($needsPlaceholder) {
                ContentAutomationRunItem::query()->create([
                    'automation_run_id' => (string) $run->id,
                    'automation_id' => (string) $run->automation_id,
                    'chain_index' => max(1, (int) ($truth['intended_count'] ?? 1)),
                    'status' => ContentAutomationRunItem::STATUS_FAILED,
                    'failure_stage' => 'unknown',
                    'last_error_code' => 'missing_failure_diagnostics',
                    'last_error_message' => 'Automation generated no content and no item-level failure was previously recorded.',
                    'client_site_id' => $run->client_site_id,
                    'metadata' => [
                        'created_by' => 'automations:repair-run-state',
                    ],
                    'started_at' => $run->started_at,
                    'finished_at' => $run->finished_at ?: now(),
                ]);
                $report['diagnostic_placeholders_added']++;
                $run->load('items');
                $truth = $this->truth($run);
                $newStatus = $this->statusFromTruth($truth, $run);
            }

            $metadata = is_array($run->metadata) ? $run->metadata : [];
            $metadata['truth'] = $truth;
            $metadata['repaired_at'] = now()->toIso8601String();

            $run->forceFill([
                'status' => $newStatus->value,
                'generated_content_ids' => $contentIds,
                'generated_draft_ids' => $draftIds,
                'error_message' => $lastError ?: null,
                'result_summary' => $this->summary($truth),
                'metadata' => $metadata,
            ])->save();

            if (in_array($newStatus, [
                ContentAutomationRunStatus::COMPLETED,
                ContentAutomationRunStatus::SKIPPED,
            ], true)) {
                $automation = ContentAutomation::query()->find((string) $run->automation_id);

                if ($automation instanceof ContentAutomation
                    && (string) ($automation->last_failure_run_id ?? '') === (string) $run->id) {
                    $automation->forceFill([
                        'last_failure_message' => null,
                        'last_failure_code' => null,
                        'last_failure_run_id' => null,
                        'last_failure_at' => null,
                    ])->save();
                }
            }

            $report['repaired']++;
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], collect($report)->map(fn ($value, string $key): array => [$key, (string) $value])->values()->all());

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function truth(ContentAutomationRun $run): array
    {
        $run->loadMissing('items');
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

        return [
            'intended_count' => $run->items->count(),
            'generated_count' => count($contentIds),
            'failed_count' => $run->items->where('status', ContentAutomationRunItem::STATUS_FAILED)->count(),
            'partial_count' => $run->items->where('status', ContentAutomationRunItem::STATUS_PARTIAL)->count(),
            'skipped_count' => $run->items->where('status', ContentAutomationRunItem::STATUS_SKIPPED)->count(),
            'generated_content_ids' => $contentIds,
        ];
    }

    private function statusFromTruth(array $truth, ContentAutomationRun $run): ContentAutomationRunStatus
    {
        $intended = (int) $truth['intended_count'];
        $generated = (int) $truth['generated_count'];
        $failed = (int) $truth['failed_count'];
        $partial = (int) $truth['partial_count'];
        $skipped = (int) $truth['skipped_count'];

        if ($intended === 0 && (string) ($run->status?->value ?? $run->status) === ContentAutomationRunStatus::SKIPPED->value) {
            return ContentAutomationRunStatus::SKIPPED;
        }

        return match (true) {
            $generated === 0 && $failed > 0 => ContentAutomationRunStatus::FAILED,
            $generated > 0 && ($failed > 0 || $partial > 0 || $skipped > 0) => ContentAutomationRunStatus::PARTIAL,
            $generated > 0 => ContentAutomationRunStatus::COMPLETED,
            $skipped > 0 && $failed === 0 => ContentAutomationRunStatus::SKIPPED,
            default => ContentAutomationRunStatus::FAILED,
        };
    }

    private function lastError(ContentAutomationRun $run): string
    {
        $item = $run->items
            ->filter(fn ($item): bool => trim((string) $item->last_error_message) !== '')
            ->sortByDesc('updated_at')
            ->first();

        return trim((string) ($item?->last_error_message ?? ''));
    }

    private function summary(array $truth): string
    {
        return sprintf(
            '%d article(s) generated, %d failed, %d partial.',
            (int) $truth['generated_count'],
            (int) $truth['failed_count'],
            (int) $truth['partial_count'],
        );
    }
}
