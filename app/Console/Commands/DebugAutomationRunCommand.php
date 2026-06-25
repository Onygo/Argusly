<?php

namespace App\Console\Commands;

use App\Enums\ContentAutomationTriggerType;
use App\Jobs\ContentAutomation\RunContentAutomationJob;
use App\Models\Content;
use App\Models\ContentAutomationRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DebugAutomationRunCommand extends Command
{
    protected $signature = 'automations:debug-run
        {--recent : Show recent runs}
        {--limit=20 : Number of recent runs to show}
        {--automation-id= : Filter by automation id}
        {--run-id= : Inspect a specific run id}
        {--show-items : Show run items}
        {--show-errors : Show item and run errors}
        {--retry-failed : Retry failed automation runs}
        {--dry-run : Preview retry without dispatching}';

    protected $description = 'Inspect content automation run state, item failures, created content, and linked failed jobs.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $automationId = trim((string) ($this->option('automation-id') ?? ''));
        $runId = trim((string) ($this->option('run-id') ?? ''));

        $runs = ContentAutomationRun::query()
            ->with(['automation', 'items'])
            ->when($runId !== '', fn ($query) => $query->where('id', $runId))
            ->when($automationId !== '', fn ($query) => $query->where('automation_id', $automationId))
            ->latest('created_at')
            ->limit($runId !== '' ? 1 : $limit)
            ->get();

        if ($runs->isEmpty()) {
            $this->warn('No automation runs found.');

            return self::SUCCESS;
        }

        foreach ($runs as $run) {
            $truth = $this->truth($run);
            $failedJobIds = $this->failedJobIds($run);
            $lastError = $this->lastError($run);

            $this->info('Automation run');
            $this->table(['Field', 'Value'], [
                ['automation id', (string) $run->automation_id],
                ['automation name', (string) ($run->automation?->name ?? '')],
                ['run id', (string) $run->id],
                ['started at', (string) ($run->started_at?->toDateTimeString() ?? '')],
                ['finished at', (string) ($run->finished_at?->toDateTimeString() ?? '')],
                ['status', (string) ($run->status?->value ?? $run->status)],
                ['intended item count', (string) $truth['intended_count']],
                ['generated item count', (string) $truth['generated_count']],
                ['failed item count', (string) $truth['failed_count']],
                ['partial item count', (string) $truth['partial_count']],
                ['last error summary', $lastError],
                ['linked failed job ids', implode(', ', $failedJobIds)],
                ['content ids created', implode(', ', $truth['generated_content_ids'])],
            ]);

            if ((bool) $this->option('show-items')) {
                $this->info('Items');
                $this->table(
                    ['ID', 'Index', 'Status', 'Stage', 'Locale', 'Content', 'Error'],
                    $run->items->map(fn ($item): array => [
                        (string) $item->id,
                        (string) $item->chain_index,
                        (string) $item->status,
                        (string) ($item->failure_stage ?? ''),
                        (string) ($item->locale ?? ''),
                        (string) ($item->content_id ?? ''),
                        (string) ($item->last_error_message ?? ''),
                    ])->all()
                );
            }

            if ((bool) $this->option('show-errors')) {
                $errors = $run->items
                    ->filter(fn ($item): bool => trim((string) $item->last_error_message) !== '')
                    ->values();

                if ($errors->isEmpty() && trim((string) $run->error_message) === '') {
                    $this->line('Errors: none recorded');
                } else {
                    if (trim((string) $run->error_message) !== '') {
                        $this->line('Run error: '.$run->error_message);
                    }

                    foreach ($errors as $item) {
                        $this->line(sprintf(
                            '- item %s [%s] %s: %s',
                            (string) $item->id,
                            (string) ($item->failure_stage ?? 'unknown'),
                            (string) ($item->last_error_code ?? 'error'),
                            (string) $item->last_error_message
                        ));
                    }
                }
            }

            if ((bool) $this->option('retry-failed') && in_array((string) ($run->status?->value ?? $run->status), ['failed', 'partial'], true)) {
                if ((bool) $this->option('dry-run')) {
                    $this->line('Dry run: would dispatch a new manual automation run.');
                } else {
                    RunContentAutomationJob::dispatch(
                        automationId: (string) $run->automation_id,
                        triggerType: ContentAutomationTriggerType::MANUAL->value,
                        requestedByUserId: null,
                    );
                    $this->line('Dispatched retry automation job.');
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
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

        return [
            'intended_count' => $run->items->count(),
            'generated_count' => count($contentIds),
            'failed_count' => $run->items->where('status', 'failed')->count(),
            'partial_count' => $run->items->where('status', 'partial')->count(),
            'generated_content_ids' => $contentIds,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function failedJobIds(ContentAutomationRun $run): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        return DB::table('failed_jobs')
            ->where('payload', 'like', '%'.$run->automation_id.'%')
            ->orWhere('exception', 'like', '%'.$run->id.'%')
            ->latest('failed_at')
            ->limit(10)
            ->pluck('uuid')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    private function lastError(ContentAutomationRun $run): string
    {
        $item = $run->items
            ->filter(fn ($item): bool => trim((string) $item->last_error_message) !== '')
            ->sortByDesc('updated_at')
            ->first();

        if ($item) {
            return trim(sprintf(
                '%s/%s: %s',
                (string) ($item->failure_stage ?? 'unknown'),
                (string) ($item->last_error_code ?? 'error'),
                (string) $item->last_error_message
            ));
        }

        return trim((string) ($run->error_message ?? ''));
    }
}
