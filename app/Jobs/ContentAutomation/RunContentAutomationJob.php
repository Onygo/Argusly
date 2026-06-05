<?php

namespace App\Jobs\ContentAutomation;

use App\Enums\ContentAutomationTriggerType;
use App\Models\ContentAutomation;
use App\Models\ContentAutomationRun;
use App\Services\ContentAutomation\AutomationFailureService;
use App\Services\ContentAutomation\ContentAutomationOrchestrator;
use App\Support\QueueNames;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunContentAutomationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600;

    public int $uniqueFor = 3600;

    public function __construct(
        public string $automationId,
        public string $triggerType = 'scheduled',
        public ?int $requestedByUserId = null,
    ) {
        $this->onQueue(QueueNames::DEFAULT);
    }

    public function uniqueId(): string
    {
        return 'content_automation:' . $this->automationId;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->expireAfter($this->timeout)
                ->releaseAfter(120),
        ];
    }

    public function handle(ContentAutomationOrchestrator $orchestrator): void
    {
        $automation = ContentAutomation::query()->find($this->automationId);
        if (! $automation) {
            return;
        }

        try {
            $orchestrator->run(
                $automation,
                ContentAutomationTriggerType::tryFrom($this->triggerType) ?? ContentAutomationTriggerType::SCHEDULED,
                $this->requestedByUserId,
            );
        } catch (Throwable $exception) {
            $run = ContentAutomationRun::query()
                ->where('automation_id', $this->automationId)
                ->latest('created_at')
                ->first();

            $context = $this->failureContext($automation, $run);
            app(AutomationFailureService::class)->persistFailure(
                $automation,
                $run,
                $exception,
                $context
            );

            Log::error('content_automation.queue_job_exception', array_merge($context, [
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
                'trace' => collect($exception->getTrace())->take(8)->all(),
            ]));

            if (! app(AutomationFailureService::class)->isRetryable($exception)) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $automation = ContentAutomation::query()->find($this->automationId);
        $run = ContentAutomationRun::query()
            ->where('automation_id', $this->automationId)
            ->latest('created_at')
            ->first();

        if (! $automation) {
            return;
        }

        $context = array_merge($this->failureContext($automation, $run), [
            'preserve_real_error' => true,
        ]);

        app(AutomationFailureService::class)->persistFailure(
            $automation,
            $run,
            $exception,
            $context
        );

        Log::error('content_automation.queue_job_failed', array_merge($context, [
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
            'exception_file' => $exception->getFile(),
            'exception_line' => $exception->getLine(),
        ]));
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * @return array<string, mixed>
     */
    private function failureContext(ContentAutomation $automation, ?ContentAutomationRun $run): array
    {
        return [
            'automation_id' => (string) $automation->id,
            'automation_run_id' => $run?->id ? (string) $run->id : null,
            'workspace_id' => (string) $automation->workspace_id,
            'client_site_id' => $automation->client_site_id ? (string) $automation->client_site_id : null,
            'locale' => $automation->sourceLocale(),
            'chain_size' => (int) $automation->chain_size,
            'attempt' => $this->attempts(),
            'max_tries' => $this->tries,
            'job_id' => $this->job && method_exists($this->job, 'getJobId') ? $this->job->getJobId() : null,
            'queue' => $this->job && method_exists($this->job, 'getQueue') ? $this->job->getQueue() : QueueNames::DEFAULT,
            'trigger_type' => $this->triggerType,
            'requested_by_user_id' => $this->requestedByUserId,
            'failure_stage' => $run ? 'execution' : 'start',
            'error_code' => 'job_execution_failed',
        ];
    }
}
