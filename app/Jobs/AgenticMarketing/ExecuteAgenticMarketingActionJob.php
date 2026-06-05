<?php

namespace App\Jobs\AgenticMarketing;

use App\Models\AgenticMarketingAction;
use App\Models\User;
use App\Services\AgenticMarketing\AgenticActionRunLogger;
use App\Services\AgenticMarketing\AgenticMarketingActionExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ExecuteAgenticMarketingActionJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function __construct(
        public string $actionId,
        public ?int $actorId = null,
        public ?string $claimId = null,
    ) {
        $this->onQueue('agentic-marketing');
    }

    public function uniqueId(): string
    {
        return 'agentic-marketing-action:'.$this->actionId;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->expireAfter($this->timeout + 60)
                ->dontRelease(),
        ];
    }

    public function handle(AgenticMarketingActionExecutor $executor): void
    {
        $action = AgenticMarketingAction::query()->find($this->actionId);
        if (! $action) {
            Log::warning('agentic_marketing.action_job.missing_action', [
                'action_id' => $this->actionId,
            ]);

            return;
        }

        app(AgenticActionRunLogger::class)->markRunning($action->loadMissing(['objective', 'opportunity']), $this->claimId ?: $this->job?->getJobId());
        $executor->execute($action, $this->actorId ? User::query()->find($this->actorId) : null, $this->claimId);
    }

    public function failed(Throwable $exception): void
    {
        $message = trim($exception->getMessage()) !== ''
            ? Str::limit($exception->getMessage(), 240)
            : 'The action could not be executed safely. Please review and retry.';

        $action = AgenticMarketingAction::query()->find($this->actionId);
        if (! $action) {
            return;
        }

        if ($action->status !== AgenticMarketingAction::STATUS_RUNNING) {
            return;
        }

        if ($this->claimId && $action->execution_claim_id && $action->execution_claim_id !== $this->claimId) {
            return;
        }

        $action->forceFill([
            'status' => AgenticMarketingAction::STATUS_FAILED,
            'error_message' => $message,
            'failed_at' => now(),
            'completed_at' => null,
            'result' => array_merge([
                'summary' => 'Action failed before making changes.',
                'created_content_id' => null,
                'created_draft_id' => null,
                'suggestions' => [],
                'warnings' => [$message],
                'service_used' => 'queued_executor',
                'executed_at' => now()->toIso8601String(),
            ], (array) ($action->result ?? [])),
        ])->save();
        app(AgenticActionRunLogger::class)->markFailed($action->loadMissing(['objective', 'opportunity']), $message);

        Log::warning('agentic_marketing.action_job.failed', [
            'action_id' => $this->actionId,
            'action_type' => $action->action_type,
            'error' => $message,
        ]);
    }
}
