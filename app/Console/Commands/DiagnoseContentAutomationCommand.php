<?php

namespace App\Console\Commands;

use App\Models\ContentAutomation;
use App\Models\ContentAutomationRun;
use App\Models\ContentAutomationRunItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DiagnoseContentAutomationCommand extends Command
{
    protected $signature = 'content-automation:diagnose
        {automationId? : Optional automation id}
        {--run= : Inspect a specific run id}';

    protected $description = 'Show diagnostic details for a content automation and its latest failing run.';

    public function handle(): int
    {
        $automationId = trim((string) $this->argument('automationId'));
        $runId = trim((string) ($this->option('run') ?? ''));

        $run = ContentAutomationRun::query()
            ->when($runId !== '', fn ($query) => $query->where('id', $runId))
            ->when($runId === '' && $automationId !== '', fn ($query) => $query->where('automation_id', $automationId))
            ->latest('created_at')
            ->first();

        if (! $run && $automationId !== '') {
            $automation = ContentAutomation::query()->find($automationId);
            if (! $automation) {
                $this->error('Automation not found.');

                return self::FAILURE;
            }

            $run = $automation->runs()->latest('created_at')->first();
        }

        if (! $run) {
            $this->error('No automation run found for the requested scope.');

            return self::FAILURE;
        }

        $automation = ContentAutomation::query()->findOrFail($run->automation_id);
        $items = ContentAutomationRunItem::query()
            ->where('automation_run_id', (string) $run->id)
            ->get();

        $latestFailedJob = $this->latestFailedJob((string) $automation->id, $run);

        $this->table(['Field', 'Value'], [
            ['automation id', (string) $automation->id],
            ['last run id', (string) $run->id],
            ['status', (string) ($run->status?->value ?? $run->status)],
            ['attempts', (string) ((int) $run->attempt_count ?: 0)],
            ['generated item count', (string) count((array) $run->generated_content_ids)],
            ['failed item count', (string) $items->where('status', ContentAutomationRunItem::STATUS_FAILED)->count()],
            ['last real exception', (string) data_get($run->metadata, 'real_error.message', $run->error_message ?: 'n/a')],
            ['latest failed_jobs entry', $latestFailedJob['summary']],
        ]);

        if ($latestFailedJob['details'] !== null) {
            $this->newLine();
            $this->line('failed_jobs details:');
            $this->line($latestFailedJob['details']);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{summary:string,details:?string}
     */
    private function latestFailedJob(string $automationId, ContentAutomationRun $run): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [
                'summary' => 'failed_jobs table not available',
                'details' => null,
            ];
        }

        $row = DB::table('failed_jobs')
            ->where('payload', 'like', '%RunContentAutomationJob%')
            ->where('payload', 'like', '%' . $automationId . '%')
            ->orderByDesc('failed_at')
            ->first();

        if (! $row) {
            return [
                'summary' => 'no failed_jobs row found',
                'details' => null,
            ];
        }

        $exception = trim((string) ($row->exception ?? ''));
        $firstLine = strtok($exception, "\n") ?: 'failed job found';

        return [
            'summary' => sprintf(
                '#%s at %s: %s',
                (string) ($row->id ?? 'n/a'),
                (string) ($row->failed_at ?? 'n/a'),
                $firstLine
            ),
            'details' => $exception,
        ];
    }
}
