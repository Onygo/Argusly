<?php

namespace App\Services\Content;

use App\Enums\SupportedLanguage;
use App\Jobs\TranslateDraftJob;
use App\Models\Content;
use App\Models\ContentTranslation;
use App\Services\Translation\TranslationService;
use App\Services\CreditWalletService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class ContentTranslationCoordinator
{
    public function __construct(
        private readonly ContentLocalizationService $localizations,
        private readonly TranslationService $translations,
        private readonly LocaleMismatchService $mismatchService,
        private readonly TranslationLockService $translationLocks,
        private readonly TranslationDebugService $translationDebug,
        private readonly CreditWalletService $creditWallets,
    ) {}

    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function targetLocales(Content $content): Collection
    {
        $source = $this->localizations->source($content);
        $existing = $this->localizations->family($source)->keyBy(fn (Content $variant): string => $variant->localeCode());
        $enabled = $source->workspace?->getEnabledLanguagesAsEnums() ?? SupportedLanguage::cases();
        $source->load('translationRequests');
        $cleanupRows = $this->translationLocks->cleanupStaleLocks($source->translationRequests, force: true)
            ->keyBy(fn (array $row): string => (string) $row['translation']->target_locale);

        return collect($enabled)
            ->reject(fn (SupportedLanguage $language): bool => $language->value === $source->localeCode())
            ->map(function (SupportedLanguage $language) use ($existing, $source, $cleanupRows): array {
                $variant = $existing->get($language->value);
                $request = $source->translationRequestForLocale($language->value);
                $cleanup = $cleanupRows->get($language->value);
                $queueState = (string) ($cleanup['queue_state'] ?? 'ready');
                $recovered = (bool) ($cleanup['recovered'] ?? false);
                $request = $request?->fresh();
                $state = match (true) {
                    ! $request instanceof ContentTranslation => 'ready',
                    $request->isInsufficientCreditsFailure() => ContentTranslation::STATUS_INSUFFICIENT_CREDITS,
                    $recovered || $request->isStaleFailure() => 'stale_recovered',
                    $queueState !== '' && $queueState !== 'ready' => $queueState,
                    default => $request->displayStatus(),
                };
                $hasExistingVariant = $variant instanceof Content || filled($request?->target_content_id);

                return [
                    'value' => $language->value,
                    'label' => $language->englishLabel(),
                    'native_label' => $language->label(),
                    'existing_variant' => $variant,
                    'translation_request' => $request,
                    'translation_request_id' => $request?->id ? (string) $request->id : null,
                    'target_content_id' => $request?->target_content_id ? (string) $request->target_content_id : null,
                    'state' => $state,
                    'state_label' => match ($state) {
                        'ready' => 'Ready for translation',
                        ContentTranslation::STATUS_QUEUED => 'Queued',
                        ContentTranslation::STATUS_PROCESSING => 'Translating',
                        ContentTranslation::STATUS_COMPLETED => 'Translated',
                        ContentTranslation::STATUS_FAILED => 'Failed',
                        ContentTranslation::STATUS_INSUFFICIENT_CREDITS => 'Not enough credits',
                        'stale_recovered' => 'Stale recovered',
                        default => 'Ready for translation',
                    },
                    'lock_state' => $queueState,
                    'error_message' => $request?->displayErrorMessage(),
                    'action' => $state === ContentTranslation::STATUS_INSUFFICIENT_CREDITS
                        ? 'billing'
                        : (in_array($state, [ContentTranslation::STATUS_FAILED, 'stale', 'stale_recovered'], true)
                        ? 'retry'
                        : ($hasExistingVariant ? 'refresh' : 'translate')),
                    'verb' => match (true) {
                        $state === ContentTranslation::STATUS_INSUFFICIENT_CREDITS => 'Retry after adding credits',
                        in_array($state, [ContentTranslation::STATUS_FAILED, 'stale', 'stale_recovered'], true) => 'Retry translation',
                        $hasExistingVariant => 'Refresh translation',
                        default => 'Translate',
                    },
                    'last_failed_at' => $request?->processing_failed_at,
                    'heartbeat_at' => $request?->processing_last_heartbeat_at,
                    'job_uuid' => $request?->processing_job_uuid,
                    'recovered_stale_lock' => $recovered,
                    'required_credits' => $request?->required_credits,
                    'available_credits' => $request?->available_credits,
                    'buy_credits_url' => route('app.billing.index'),
                    'upgrade_plan_url' => route('app.billing.index', ['tab' => 'subscriptions']),
                    'is_disabled' => in_array($state, [
                        ContentTranslation::STATUS_QUEUED,
                        ContentTranslation::STATUS_PROCESSING,
                    ], true),
                ];
            })
            ->values();
    }

    /**
     * Validate source locale before allowing translation.
     *
     * @return array{valid: bool, issues: array<int, string>, source_locale: string, detected_locale: string|null}
     */
    public function validateSourceLocale(Content $content): array
    {
        $source = $this->localizations->source($content);

        $validation = $this->mismatchService->validateSourceForTranslation($source);

        return [
            'valid' => $validation['valid'],
            'issues' => $validation['issues'],
            'source_locale' => $source->localeCode(),
            'detected_locale' => $validation['suggested_fix'] !== null
                ? str_replace('fix_locale_to_', '', $validation['suggested_fix'])
                : null,
        ];
    }

    /**
     * @return array{mode:string,source_content:Content,source_draft:\App\Models\Draft,existing_variant:?Content,target_language:SupportedLanguage}
     */
    public function queue(Content $content, string $targetLocale, ?string $userId = null, bool $skipLocaleValidation = false): array
    {
        $source = $this->localizations->source($content);
        $requestedTargetLocale = trim($targetLocale);
        $targetLanguage = SupportedLanguage::fromStringOrDefault($requestedTargetLocale);
        $source->load('translationRequests');

        if (! $skipLocaleValidation) {
            $localeValidation = $this->mismatchService->validateSourceForTranslation($source);

            if (! $localeValidation['valid']) {
                Log::warning('content.translation.locale_mismatch_detected', [
                    'content_id' => (string) $content->id,
                    'source_content_id' => (string) $source->id,
                    'source_locale' => $source->localeCode(),
                    'target_locale' => $targetLanguage->value,
                    'issues' => $localeValidation['issues'],
                    'suggested_fix' => $localeValidation['suggested_fix'],
                ]);
            }
        }

        $source = $this->mismatchService->autoCorrectSourceLocale($source)['content'];

        $existingVariant = $this->localizations->variantForLocale($source, $targetLanguage->value);

        try {
            $sourceSelection = $this->localizations->resolveTranslationSource($source, $userId ? (int) $userId : null);
            $sourceDraft = $sourceSelection['draft'];
            $sourceType = $sourceSelection['source_type'];
            $normalizedSourceLocale = $this->translations->resolveSourceLanguage($sourceDraft)->value;
        } catch (RuntimeException $exception) {
            Log::warning('content.translation.queue_rejected', [
                'content_id' => (string) $content->id,
                'source_content_id' => (string) $source->id,
                'source_locale' => $source->localeCode(),
                'target_locale' => $targetLanguage->value,
                'content_status' => (string) $source->status,
                'content_publish_status' => (string) ($source->publish_status ?? ''),
                'content_delivery_status' => (string) $source->resolveDeliveryStatus(),
                'linked_draft_id' => null,
                'draft_status' => null,
                'translation_source_type' => null,
                'block_reason' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        Log::info('content.translation.queue_requested', [
            'content_id' => (string) $content->id,
            'source_content_id' => (string) $source->id,
            'source_locale' => $source->localeCode(),
            'target_locale' => $targetLanguage->value,
            'content_status' => (string) $source->status,
            'content_publish_status' => (string) ($source->publish_status ?? ''),
            'content_delivery_status' => (string) $source->resolveDeliveryStatus(),
            'linked_draft_id' => (string) $sourceDraft->id,
            'draft_status' => (string) $sourceDraft->status,
            'translation_source_type' => $sourceType,
            'source_draft_id' => (string) $sourceDraft->id,
            'source_draft_locale' => trim((string) $sourceDraft->getRawOriginal('language')),
            'normalized_source_locale' => $normalizedSourceLocale,
            'requested_target_locale' => $requestedTargetLocale,
            'normalized_target_locale' => $targetLanguage->value,
            'existing_variant_id' => $existingVariant?->id,
        ]);

        try {
            $this->translations->validateSourceDraft($sourceDraft);
            $this->translations->validateTargetLanguageAvailabilityForDispatch(
                $sourceDraft,
                $targetLanguage,
                $existingVariant instanceof Content
            );
        } catch (RuntimeException $exception) {
            Log::warning('content.translation.queue_rejected', [
                'content_id' => (string) $content->id,
                'source_content_id' => (string) $source->id,
                'source_draft_id' => (string) $sourceDraft->id,
                'source_locale' => $source->localeCode(),
                'target_locale' => $targetLanguage->value,
                'content_status' => (string) $source->status,
                'content_publish_status' => (string) ($source->publish_status ?? ''),
                'content_delivery_status' => (string) $source->resolveDeliveryStatus(),
                'linked_draft_id' => (string) $sourceDraft->id,
                'draft_status' => (string) $sourceDraft->status,
                'translation_source_type' => $sourceType,
                'source_draft_locale' => trim((string) $sourceDraft->getRawOriginal('language')),
                'normalized_source_locale' => $normalizedSourceLocale,
                'requested_target_locale' => $requestedTargetLocale,
                'normalized_target_locale' => $targetLanguage->value,
                'block_reason' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $requiredCredits = $this->translations->estimateTranslationCredits($sourceDraft);
        $creditSummary = $this->creditWallets->getSummary((string) $source->client_site_id);
        $availableCredits = (int) ($creditSummary['available'] ?? 0);

        if ($availableCredits < $requiredCredits) {
            $userSafeMessage = sprintf(
                'Not enough credits to translate this article. Required: %d, available: %d.',
                $requiredCredits,
                $availableCredits
            );

            $translationRequest = DB::transaction(function () use (
                $source,
                $targetLanguage,
                $userId,
                $existingVariant,
                $requiredCredits,
                $availableCredits,
                $userSafeMessage
            ): ContentTranslation {
                $translationRequest = ContentTranslation::query()
                    ->where('content_id', (string) $source->id)
                    ->where('target_locale', $targetLanguage->value)
                    ->lockForUpdate()
                    ->first();

                if (! $translationRequest instanceof ContentTranslation) {
                    $translationRequest = new ContentTranslation([
                        'content_id' => (string) $source->id,
                        'target_locale' => $targetLanguage->value,
                    ]);
                }

                $translationRequest->forceFill([
                    'content_id' => (string) $source->id,
                    'target_locale' => $targetLanguage->value,
                    'target_content_id' => $existingVariant?->id,
                    'requested_by_user_id' => $userId,
                    'translation_trace_id' => $translationRequest->translation_trace_id ?: (string) Str::uuid(),
                    'status' => ContentTranslation::STATUS_FAILED,
                ])->save();

                return $this->translationLocks->markTranslationInsufficientCredits(
                    $translationRequest,
                    $requiredCredits,
                    $availableCredits,
                    $userSafeMessage,
                    'client_site_allocation',
                );
            });

            $this->translationDebug->logFailure(
                'Translation dispatch blocked because credits are insufficient.',
                $this->translationDebug->buildContext($translationRequest, [
                    'trace_id' => $translationRequest->translation_trace_id,
                    'required_credits' => $requiredCredits,
                    'available_credits' => $availableCredits,
                    'entitlement_source' => 'client_site_allocation',
                    'queue_name' => config('translation.queue.name', 'default'),
                ], $source)
            );

            throw new RuntimeException($userSafeMessage);
        }

        $dispatchJobUuid = (string) Str::uuid();
        $translationTraceId = (string) Str::uuid();

        $translationRequest = DB::transaction(function () use (
            $source,
            $targetLanguage,
            $userId,
            $existingVariant,
            $dispatchJobUuid,
            $translationTraceId
        ): ContentTranslation {
            $translationRequest = ContentTranslation::query()
                ->where('content_id', (string) $source->id)
                ->where('target_locale', $targetLanguage->value)
                ->lockForUpdate()
                ->first();

            if ($translationRequest instanceof ContentTranslation) {
                $cleanup = $this->translationLocks->cleanupStaleLocks(collect([$translationRequest]), force: true)->first();
                $translationRequest = $translationRequest->fresh() ?? $translationRequest;

                if ($translationRequest->status !== ContentTranslation::STATUS_FAILED
                    && (bool) ($cleanup['running'] ?? $this->translationLocks->translationIsActuallyRunning($translationRequest))) {
                    throw new RuntimeException(
                        "A translation to '{$targetLanguage->englishLabel()}' is already {$translationRequest->status}."
                    );
                }
            } else {
                $translationRequest = new ContentTranslation([
                    'content_id' => (string) $source->id,
                    'target_locale' => $targetLanguage->value,
                ]);
            }

            $translationRequest->forceFill([
                'content_id' => (string) $source->id,
                'target_locale' => $targetLanguage->value,
                'target_content_id' => $existingVariant?->id,
                'requested_by_user_id' => $userId,
                'translation_trace_id' => $translationRequest->translation_trace_id ?: $translationTraceId,
                'status' => ContentTranslation::STATUS_QUEUED,
            ])->save();

            $translationRequest = $this->translationLocks->acquireTranslationLock(
                $translationRequest,
                ContentTranslation::STATUS_QUEUED,
                $dispatchJobUuid,
            );

            $this->translationLocks->clearQueuedJobsForFingerprint(
                (string) $source->id,
                $targetLanguage->value,
                $dispatchJobUuid,
            );

            return $translationRequest;
        });

        $this->translationDebug->logStateSnapshot(
            'Translation state queued before dispatch.',
            $this->translationDebug->buildContext($translationRequest, [
                'trace_id' => $translationRequest->translation_trace_id,
                'queue_name' => config('translation.queue.name', 'default'),
                'attempt_count' => 0,
                'dispatch_job_uuid' => $dispatchJobUuid,
            ], $source)
        );

        $job = new TranslateDraftJob(
            sourceDraftId: (string) $sourceDraft->id,
            targetLanguage: $targetLanguage->value,
            userId: $userId,
            targetContentId: $existingVariant?->id,
            translationRequestId: (string) $translationRequest->id,
            dispatchJobUuid: $dispatchJobUuid,
            sourceContentId: (string) $source->id,
            traceId: (string) $translationRequest->translation_trace_id,
        );

        $queuePayload = [
            'job_class' => TranslateDraftJob::class,
            'queue_name' => config('translation.queue.name', 'default'),
            'queue_connection' => config('translation.queue.connection'),
            'payload' => [
                'source_draft_id' => (string) $sourceDraft->id,
                'source_content_id' => (string) $source->id,
                'target_locale' => $targetLanguage->value,
                'target_content_id' => $existingVariant?->id ? (string) $existingVariant->id : null,
                'user_id' => $userId,
                'organization_id' => $source->workspace?->organization_id,
                'translation_request_id' => (string) $translationRequest->id,
                'dispatch_job_uuid' => $dispatchJobUuid,
                'trace_id' => (string) $translationRequest->translation_trace_id,
                'tries' => $job->tries,
                'timeout' => $job->timeout,
            ],
        ];

        $this->translationDebug->logDispatch(
            $this->translationDebug->buildContext($translationRequest, $queuePayload, $source)
        );

        try {
            $dispatch = dispatch($job)->afterCommit();
        } catch (\Throwable $exception) {
            Log::error('content.translation.dispatch_failed', [
                'content_id' => (string) $content->id,
                'source_content_id' => (string) $source->id,
                'translation_request_id' => (string) $translationRequest->id,
                'target_locale' => $targetLanguage->value,
                'error' => $exception->getMessage(),
            ]);

            $this->translationDebug->logFailure(
                'Translation dispatch failed.',
                $this->translationDebug->buildContext($translationRequest, [
                    'trace_id' => $translationRequest->translation_trace_id,
                    'queue_name' => config('translation.queue.name', 'default'),
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'stack_trace' => $exception->getTraceAsString(),
                ], $source)
            );

            $this->translationLocks->markTranslationFailed($translationRequest, $exception->getMessage());

            throw $exception;
        }

        Log::info('content.translation.job_dispatched', [
            'content_id' => (string) $content->id,
            'source_content_id' => (string) $source->id,
            'source_draft_id' => (string) $sourceDraft->id,
            'translation_request_id' => (string) $translationRequest->id,
            'target_locale' => $targetLanguage->value,
            'content_status' => (string) $source->status,
            'content_publish_status' => (string) ($source->publish_status ?? ''),
            'content_delivery_status' => (string) $source->resolveDeliveryStatus(),
            'linked_draft_id' => (string) $sourceDraft->id,
            'draft_status' => (string) $sourceDraft->status,
            'translation_source_type' => $sourceType,
            'requested_target_locale' => $requestedTargetLocale,
            'normalized_target_locale' => $targetLanguage->value,
            'translation_request_id' => (string) $translationRequest->id,
            'queue' => ($dispatch->job ?? null)?->queue ?? config('translation.queue.name', 'default'),
            'connection' => ($dispatch->job ?? null)?->connection ?? config('translation.queue.connection'),
        ]);

        return [
            'mode' => $existingVariant instanceof Content ? 'refresh' : 'translate',
            'source_content' => $source,
            'source_draft' => $sourceDraft,
            'existing_variant' => $existingVariant,
            'target_language' => $targetLanguage,
            'translation_request' => $translationRequest,
        ];
    }
}
