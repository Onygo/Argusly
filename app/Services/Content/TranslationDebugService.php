<?php

namespace App\Services\Content;

use App\Models\Content;
use App\Models\ContentTranslation;
use App\Models\TranslationDebugEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TranslationDebugService
{
    public function logDispatch(array $context): void
    {
        $this->record('DISPATCHED', 'Translation job dispatched.', $context);
    }

    public function logJobStart(array $context): void
    {
        $this->record('JOB_STARTED', 'TranslateDraftJob started.', $context);
    }

    public function logStateSnapshot(string $message, array $context): void
    {
        $this->record('STATE_SNAPSHOT', $message, $context);
    }

    public function logProviderRequest(array $context): void
    {
        $this->record('PROVIDER_REQUEST', 'Translation provider request started.', $context);
    }

    public function logProviderResponse(array $context): void
    {
        $this->record('PROVIDER_RESPONSE', 'Translation provider response received.', $context);
    }

    public function logFailure(string $message, array $context): void
    {
        $this->record('FAILED', $message, $context, 'error');
    }

    public function logRecovery(string $message, array $context): void
    {
        $this->record('LOCK_RECOVERED', $message, $context, 'warning');
    }

    public function logLockState(string $message, array $context): void
    {
        $this->record('LOCK_STATE', $message, $context);
    }

    public function logQueueState(string $message, array $context): void
    {
        $this->record('QUEUE_STATE', $message, $context);
    }

    public function logCompletion(array $context): void
    {
        $this->record('COMPLETED', 'Translation completed.', $context);
    }

    /**
     * @return array<string,mixed>
     */
    public function buildContext(
        ?ContentTranslation $translation = null,
        array $extra = [],
        ?Content $content = null,
    ): array {
        $translationContent = $translation?->content;
        $sourceContent = $content ?? $translationContent;

        return array_filter([
            'trace_id' => $extra['trace_id'] ?? $translation?->translation_trace_id,
            'content_id' => (string) ($translation?->target_content_id ?: $sourceContent?->id ?: ''),
            'source_content_id' => (string) ($translation?->content_id ?: $sourceContent?->id ?: ''),
            'translation_row_id' => $translation?->id ? (string) $translation->id : null,
            'family_id' => $sourceContent?->family_id ? (string) $sourceContent->family_id : null,
            'locale' => $extra['locale'] ?? $translation?->target_locale ?? $sourceContent?->localeCode(),
            'processing' => $translation?->isQueuedOrProcessing(),
            'processing_started_at' => $translation?->processing_started_at?->toIso8601String(),
            'processing_job_uuid' => $translation?->processing_job_uuid,
            'processing_locked_at' => $translation?->processing_locked_at?->toIso8601String(),
            'processing_last_heartbeat_at' => $translation?->processing_last_heartbeat_at?->toIso8601String(),
            'failed_at' => $translation?->processing_failed_at?->toIso8601String(),
            'error_message' => $translation?->displayErrorMessage(),
            'target_translation_exists' => $translation?->target_content_id !== null,
            'source_content_exists' => $sourceContent !== null,
            'queue_name' => $extra['queue_name'] ?? null,
            'attempt_count' => $extra['attempt_count'] ?? null,
            'queue_state' => $extra['queue_state'] ?? null,
            'stale_reason' => $extra['stale_reason'] ?? null,
            'heartbeat_age_seconds' => $extra['heartbeat_age_seconds'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string,mixed>
     */
    public function contentDebugger(Content $content): array
    {
        $source = $content->localizationSource();
        $source->loadMissing('translationRequests', 'localizedVariants', 'workspace.organization');
        $translations = $source->translationRequests;
        $rows = app(TranslationLockService::class)->detectStaleLocks($translations)->keyBy(fn (array $row): string => (string) $row['translation']->id);
        $events = $this->eventsForContent((string) $source->id, 20);

        return [
            'source_content_id' => (string) $source->id,
            'events' => $events,
            'translations' => $translations->map(function (ContentTranslation $translation) use ($rows): array {
                $row = $rows->get((string) $translation->id, []);

                return [
                    'id' => (string) $translation->id,
                    'locale' => (string) $translation->target_locale,
                    'status' => (string) $translation->status,
                    'job_uuid' => $translation->processing_job_uuid,
                    'job_id' => $translation->job_id,
                    'started_at' => $translation->processing_started_at,
                    'heartbeat_at' => $translation->processing_last_heartbeat_at,
                    'failed_at' => $translation->processing_failed_at,
                    'error_message' => $translation->displayErrorMessage(),
                    'queue_state' => $row['queue_state'] ?? null,
                    'stale_reason' => $row['reason'] ?? null,
                    'heartbeat_age_seconds' => $row['heartbeat_age_seconds'] ?? null,
                    'pending_jobs' => collect($row['pending_jobs'] ?? [])->count(),
                    'failed_jobs' => collect($row['failed_jobs'] ?? [])->count(),
                    'retry_count' => max(
                        (int) (collect($row['pending_jobs'] ?? [])->first()['attempts'] ?? 0),
                        (int) (collect($row['failed_jobs'] ?? [])->first()['attempts'] ?? 0),
                    ),
                ];
            })->values(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function diagnosticsForContent(Content $content): array
    {
        $debugger = $this->contentDebugger($content);
        $translationIds = collect($debugger['translations'])->pluck('id')->filter()->all();

        return $debugger + [
            'pending_jobs' => app(TranslationLockService::class)->pendingJobsByTranslationRequestId($translationIds),
            'failed_jobs' => app(TranslationLockService::class)->failedJobsByTranslationRequestId($translationIds),
            'log_tail' => $this->tailTranslationLog(20),
        ];
    }

    /**
     * @return Collection<int,TranslationDebugEvent>
     */
    public function eventsForContent(string $contentId, int $limit = 20): Collection
    {
        if (! Schema::hasTable('translation_debug_events')) {
            return collect();
        }

        return TranslationDebugEvent::query()
            ->where('content_id', $contentId)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * @return array<int,string>
     */
    public function tailTranslationLog(int $lines = 20): array
    {
        $path = storage_path('logs/translation.log');

        if (! File::exists($path)) {
            return [];
        }

        $content = File::lines($path)->collect()->take(-max(1, $lines))->values();

        return $content->all();
    }

    /**
     * @param array<string,mixed> $context
     */
    private function record(string $eventType, string $message, array $context, string $level = 'debug'): void
    {
        $payload = $this->sanitizePayload($context);

        Log::channel('translation')->{$level}($message, $payload + ['event_type' => $eventType]);

        if (Schema::hasTable('translation_debug_events')) {
            TranslationDebugEvent::query()->create([
                'trace_id' => (string) ($payload['trace_id'] ?? $payload['translation_trace_id'] ?? '00000000-0000-0000-0000-000000000000'),
                'content_id' => $payload['source_content_id'] ?? $payload['content_id'] ?? null,
                'locale' => $payload['locale'] ?? $payload['target_locale'] ?? null,
                'event_type' => $eventType,
                'message' => $message,
                'payload' => $payload,
            ]);
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        foreach (['prompt', 'system_prompt', 'user_prompt', 'content_html', 'body', 'response_body'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                $payload[$key . '_length'] = mb_strlen($payload[$key]);
                $payload[$key . '_sha1'] = sha1($payload[$key]);
                unset($payload[$key]);
            }
        }

        return $payload;
    }
}
