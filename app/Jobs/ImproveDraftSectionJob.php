<?php

namespace App\Jobs;

use App\Enums\DraftImprovementAction;
use App\Events\LinkIntelligence\ArticleSignalsRequested;
use App\Exceptions\InsufficientCreditsException;
use App\Models\CreditReservation;
use App\Models\Draft;
use App\Services\Drafts\DraftIntelligenceBillingService;
use App\Services\Drafts\Exceptions\DraftImprovementException;
use App\Services\Drafts\DraftIntelligenceService;
use App\Services\Drafts\Intelligence\DraftImprovementHistoryBuilder;
use App\Services\Llm\Exceptions\LlmException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ImproveDraftSectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public string $draftId,
        public string $section,
        public ?string $userId = null,
        public ?string $operationKey = null,
    ) {
        $this->operationKey ??= (string) Str::uuid();
        $this->onQueue((string) config('draft_intelligence.queue', 'ai-low'));
    }

    public function handle(
        DraftIntelligenceService $intelligence,
        DraftIntelligenceBillingService $billing,
        ?DraftImprovementHistoryBuilder $historyBuilder = null,
    ): void {
        $historyBuilder ??= app(DraftImprovementHistoryBuilder::class);
        $reservation = null;
        $draft = Draft::query()->with('analysis')->find($this->draftId);
        $action = DraftImprovementAction::fromInput($this->section);

        if (! $draft) {
            Log::warning('Draft improvement job skipped because draft was not found', [
                'draft_id' => $this->draftId,
                'action' => $this->section,
                'operation_key' => $this->operationKey,
            ]);

            return;
        }

        try {
            if (! $action) {
                throw new RuntimeException('Unsupported draft improvement action: ' . $this->section);
            }

            $promptVersion = DraftIntelligenceService::improvementPromptVersionForAction($action);

            if (trim((string) $draft->content_html) === '') {
                $message = 'Draft improvement requires existing draft content.';
                $this->recordFailureState($draft, $action, $message, false, null);

                Log::warning('Draft improvement job skipped because content_html is empty', $this->baseLogContext($draft, $action) + [
                    'failure_reason' => $message,
                ]);

                return;
            }

            $this->recordQueuedOrProcessingState($draft, $action, 'processing');
            $historyBuilder->markProcessing($draft, $action, $this->operationKey, $this->userId);

            Log::info('Draft improvement job started', $this->baseLogContext($draft, $action) + [
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
                'prompt_version' => $promptVersion,
            ]);

            $reservation = $billing->reserveForImprovement(
                draft: $draft,
                userId: $this->userId,
                suffix: $action->value . ':' . $this->operationKey,
            );

            $result = $intelligence->improveSection($draft, $this->section);
            $meta = is_array($draft->meta) ? $draft->meta : [];
            $history = collect((array) data_get($meta, 'draft_intelligence.improvements', []))
                ->push([
                    'action' => (string) ($result['action'] ?? $action->value),
                    'section' => (string) ($result['section'] ?? $action->value),
                    'label' => $action->label(),
                    'status' => 'completed',
                    'changed_at' => now()->toIso8601String(),
                    'model_used' => $result['model_used'] ?? null,
                    'provider' => $result['provider'] ?? null,
                    'tokens_used' => (int) ($result['tokens_used'] ?? 0),
                    'change_summary' => $result['change_summary'] ?? null,
                    'change_notes' => $result['change_notes'] ?? [],
                    'prompt_version' => $result['prompt_version'] ?? $promptVersion,
                    'request_id' => $result['request_id'] ?? null,
                ])
                ->take(-15)
                ->values()
                ->all();

            data_set($meta, 'draft_intelligence.improvements', $history);
            data_set($meta, 'draft_intelligence.latest_improvement', [
                'action' => $action->value,
                'label' => $action->label(),
                'status' => 'completed',
                'queued_at' => data_get($meta, 'draft_intelligence.latest_improvement.queued_at'),
                'started_at' => data_get($meta, 'draft_intelligence.latest_improvement.started_at') ?: now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
                'operation_key' => $this->operationKey,
                'requested_by_user_id' => $this->userId,
                'provider' => $result['provider'] ?? null,
                'model_used' => $result['model_used'] ?? null,
                'prompt_version' => $result['prompt_version'] ?? $promptVersion,
                'request_id' => $result['request_id'] ?? null,
                'tokens_used' => (int) ($result['tokens_used'] ?? 0),
                'change_summary' => $result['change_summary'] ?? null,
                'change_notes' => $result['change_notes'] ?? [],
                'error' => null,
            ]);

            $updates = [
                'content_html' => $result['content_html'] ?? $draft->content_html,
                'meta' => $meta,
                'last_error' => null,
            ];

            if ($action->allowsSeoFieldUpdates()) {
                $updates['title'] = $result['title'] ?? $draft->title;
                $updates['seo_title'] = $result['seo_title'] ?? $draft->seo_title;
                $updates['seo_meta_description'] = $result['seo_meta_description'] ?? $draft->seo_meta_description;
                $updates['seo_h1'] = $result['seo_h1'] ?? $draft->seo_h1;
            }

            $draft->forceFill($updates)->saveQuietly();
            ArticleSignalsRequested::dispatch((string) $draft->id);

            $updatedDraft = $draft->fresh(['analysis']);
            $historyBuilder->markCompleted($updatedDraft, $action, $this->operationKey, $this->userId, $result);

            AnalyzeDraftJob::dispatch(
                (string) $draft->id,
                true,
                $this->userId,
                $this->operationKey,
            )->onQueue((string) config('draft_intelligence.queue', 'ai-low'))
                ->afterCommit();

            $billing->capture($reservation, $draft, [
                'action' => $action->value,
                'section' => (string) ($result['section'] ?? $action->value),
                'tokens_used' => (int) ($result['tokens_used'] ?? 0),
                'model_used' => $result['model_used'] ?? null,
            ], $this->userId);

            Log::info('Draft improvement job completed', $this->baseLogContext($draft, $action) + [
                'provider' => $result['provider'] ?? null,
                'model_used' => $result['model_used'] ?? null,
                'prompt_version' => $result['prompt_version'] ?? $promptVersion,
                'request_id' => $result['request_id'] ?? null,
                'tokens_used' => (int) ($result['tokens_used'] ?? 0),
                'change_summary' => Str::limit((string) ($result['change_summary'] ?? ''), 180),
            ]);
        } catch (Throwable $exception) {
            if ($reservation instanceof CreditReservation) {
                $billing->release($reservation, $draft, 'draft_improvement_failed', [
                    'action' => $action?->value ?? $this->section,
                    'section' => $action?->value ?? $this->section,
                    'error' => $exception->getMessage(),
                    'exception_class' => $exception::class,
                ], $this->userId);
            }

            $shouldRetry = ! $this->shouldFailWithoutRetry($exception)
                && $this->attempts() < $this->tries;

            $this->recordFailureState(
                $draft,
                $action,
                $this->userFacingFailureReason($exception),
                $shouldRetry,
                $exception,
            );
            if ($draft && $action) {
                $historyBuilder->markFailed(
                    $draft,
                    $action,
                    $this->operationKey,
                    $this->userId,
                    $this->userFacingFailureReason($exception),
                );
            }

            Log::error('Draft improvement job failed', $this->baseLogContext($draft, $action) + [
                'provider' => $exception instanceof LlmException
                    ? $exception->provider
                    : ($exception instanceof DraftImprovementException ? $exception->provider : null),
                'model_used' => $exception instanceof DraftImprovementException ? $exception->model : null,
                'request_id' => $exception instanceof LlmException
                    ? $exception->requestId
                    : ($exception instanceof DraftImprovementException ? $exception->requestId : null),
                'prompt_version' => $action
                    ? DraftIntelligenceService::improvementPromptVersionForAction($action)
                    : DraftIntelligenceService::IMPROVEMENT_PROMPT_VERSION,
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
                'will_retry' => $shouldRetry,
                'exception_class' => $exception::class,
                'failure_stage' => $exception instanceof DraftImprovementException ? $exception->failureStage : null,
                'internal_reason' => $exception instanceof DraftImprovementException ? $exception->internalReason : null,
                'failure_reason' => $exception->getMessage(),
                'response_preview' => $exception instanceof DraftImprovementException ? $exception->responsePreview : null,
            ]);

            if (! $shouldRetry) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }

    private function recordQueuedOrProcessingState(Draft $draft, DraftImprovementAction $action, string $status): void
    {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $existing = (array) data_get($meta, 'draft_intelligence.latest_improvement', []);

        data_set($meta, 'draft_intelligence.latest_improvement', array_merge($existing, [
            'action' => $action->value,
            'label' => $action->label(),
            'status' => $status,
            'queued_at' => $existing['queued_at'] ?? now()->toIso8601String(),
            'started_at' => $status === 'processing'
                ? now()->toIso8601String()
                : ($existing['started_at'] ?? null),
            'operation_key' => $this->operationKey,
            'requested_by_user_id' => $this->userId,
            'error' => null,
            'failed_at' => null,
            'will_retry' => false,
        ]));

        $draft->forceFill([
            'meta' => $meta,
            'last_error' => null,
        ])->save();
    }

    private function recordFailureState(
        Draft $draft,
        ?DraftImprovementAction $action,
        string $message,
        bool $willRetry,
        ?Throwable $exception,
    ): void {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $existing = (array) data_get($meta, 'draft_intelligence.latest_improvement', []);
        $resolvedAction = $action?->value ?? (string) ($existing['action'] ?? $this->section);
        $label = $action?->label() ?? (string) ($existing['label'] ?? Str::headline($resolvedAction));

        data_set($meta, 'draft_intelligence.latest_improvement', array_merge($existing, [
            'action' => $resolvedAction,
            'label' => $label,
            'status' => $willRetry ? 'queued' : 'failed',
            'queued_at' => $existing['queued_at'] ?? now()->toIso8601String(),
            'started_at' => $existing['started_at'] ?? null,
            'failed_at' => now()->toIso8601String(),
            'operation_key' => $this->operationKey,
            'requested_by_user_id' => $this->userId,
            'error' => $message,
            'exception_class' => $exception ? $exception::class : null,
            'failure_stage' => $exception instanceof DraftImprovementException ? $exception->failureStage : null,
            'internal_reason' => $exception instanceof DraftImprovementException ? $exception->internalReason : null,
            'will_retry' => $willRetry,
        ]));

        $draft->forceFill([
            'meta' => $meta,
            'last_error' => $message,
        ])->save();
    }

    /**
     * @return array<string,mixed>
     */
    private function baseLogContext(?Draft $draft, ?DraftImprovementAction $action): array
    {
        return [
            'draft_id' => $this->draftId,
            'brief_id' => (string) ($draft?->brief_id ?? ''),
            'client_site_id' => (string) ($draft?->client_site_id ?? ''),
            'action' => $action?->value ?? $this->section,
            'operation_key' => $this->operationKey,
            'user_id' => $this->userId,
        ];
    }

    private function shouldFailWithoutRetry(Throwable $exception): bool
    {
        if ($exception instanceof InsufficientCreditsException) {
            return true;
        }

        if ($exception instanceof DraftImprovementException) {
            return ! $exception->retryable;
        }

        if ($exception instanceof LlmException) {
            return in_array((int) ($exception->statusCode ?? 0), [400, 401, 403, 422], true);
        }

        return $exception instanceof RuntimeException;
    }

    private function userFacingFailureReason(Throwable $exception): string
    {
        if ($exception instanceof InsufficientCreditsException) {
            return $exception->getMessage();
        }

        if ($exception instanceof LlmException && $exception->userMessage) {
            return $exception->userMessage;
        }

        if ($exception instanceof DraftImprovementException && $exception->userMessage) {
            return $exception->userMessage;
        }

        return Str::limit($exception->getMessage(), 240);
    }
}
