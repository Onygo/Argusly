<?php

namespace App\Services\Translation;

use App\Enums\ContentSource;
use App\Enums\DraftType;
use App\Enums\SupportedLanguage;
use App\Models\Brief;
use App\Models\Content;
use App\Models\ContentSeo;
use App\Models\ContentTranslation;
use App\Models\CreditAction;
use App\Models\Draft;
use App\Models\Workspace;
use App\Services\Content\TranslationDebugService;
use App\Services\Content\TranslationLockService;
use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\LlmManager;
use App\Support\ContentPersistencePayloadNormalizer;
use App\Support\DutchTextCasingNormalizer;
use App\Support\TitleSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class TranslationService
{
    public function __construct(
        protected LlmManager $llmManager,
        protected TranslationPromptBuilder $promptBuilder,
        protected SeoLocalizationService $seoLocalizationService,
        protected TranslationLockService $translationLocks,
        protected TranslationDebugService $translationDebug,
    ) {}

    public function translate(
        Draft $sourceDraft,
        SupportedLanguage $targetLanguage,
        ?string $modelOverride = null,
        bool $allowExisting = false,
        array $debugContext = []
    ): array {
        $this->validateSourceDraft($sourceDraft);

        $currentJobUuid = $this->normalizeOptionalContextValue($debugContext['job_uuid'] ?? null);
        $currentTranslationRequestId = $this->normalizeOptionalContextValue($debugContext['translation_request_id'] ?? null);
        $currentQueueJobId = $this->normalizeOptionalContextValue($debugContext['queue_job_id'] ?? null);
        $currentTargetContentId = $this->normalizeOptionalContextValue($debugContext['target_content_id'] ?? null);

        if ($currentJobUuid !== null || $currentTranslationRequestId !== null || $currentQueueJobId !== null) {
            $this->validateTargetLanguageAvailabilityForJob(
                $sourceDraft,
                $targetLanguage,
                $allowExisting,
                $currentJobUuid,
                $currentTranslationRequestId,
                $currentQueueJobId,
                $currentTargetContentId,
                true,
            );
        } else {
            $this->validateTargetLanguageAvailability($sourceDraft, $targetLanguage, $allowExisting);
        }

        $sourceLanguage = $this->resolveSourceLanguage($sourceDraft);
        $model = $modelOverride ?? $this->resolveTranslationModel();

        Log::info('Starting draft translation', [
            'source_draft_id' => $sourceDraft->id,
            'source_language' => trim((string) $sourceDraft->getRawOriginal('language')),
            'normalized_source_language' => $sourceLanguage->value,
            'target_language' => $targetLanguage->value,
            'model' => $model,
        ]);

        $systemPrompt = $this->promptBuilder->buildSystemPrompt($targetLanguage);
        $userPrompt = $this->promptBuilder->buildUserPrompt(
            $sourceDraft,
            $sourceLanguage,
            $targetLanguage
        );

        $maxTokens = $this->promptBuilder->getMaxOutputTokens($sourceDraft);

        $request = new LlmRequest(
            messages: [
                new LlmMessage('system', $systemPrompt),
                new LlmMessage('user', $userPrompt),
            ],
            model: $model,
            temperature: 0.3,
            maxTokens: $maxTokens,
            responseFormat: 'json',
            metadata: [
                'feature' => 'draft_translation',
                'source_draft_id' => $sourceDraft->id,
                'source_language' => $sourceLanguage->value,
                'target_language' => $targetLanguage->value,
            ],
        );

        $this->translationDebug->logProviderRequest($debugContext + [
            'provider' => 'llm',
            'model' => $model,
            'source_content_length' => mb_strlen((string) $sourceDraft->content_html),
            'source_content_sha1' => sha1((string) $sourceDraft->content_html),
            'system_prompt_length' => mb_strlen($systemPrompt),
            'system_prompt_sha1' => sha1($systemPrompt),
            'user_prompt_length' => mb_strlen($userPrompt),
            'user_prompt_sha1' => sha1($userPrompt),
            'locale' => $targetLanguage->value,
            'max_output_tokens' => $maxTokens,
        ]);

        $response = $this->llmManager->generateJson($request);

        $contentHtml = (string) data_get($response->json, 'content_html', '');
        $this->translationDebug->logProviderResponse($debugContext + [
            'provider' => 'llm',
            'model' => $model,
            'response_length' => mb_strlen($contentHtml),
            'response_sha1' => sha1($contentHtml),
            'finish_reason' => method_exists($response, 'finishReason') ? $response->finishReason() : null,
            'input_tokens' => $response->usage?->inputTokens ?? 0,
            'output_tokens' => $response->usage?->outputTokens ?? 0,
            'request_id' => $response->requestId ?? null,
            'empty_response_detected' => trim($contentHtml) === '',
            'locale' => $targetLanguage->value,
        ]);

        if (! $response->json || ! is_array($response->json)) {
            throw new RuntimeException('Translation LLM response was not valid JSON');
        }

        $result = $this->normalizeTranslationResultForLanguage($response->json, $targetLanguage);

        return [
            'title' => $result['title'] ?? $sourceDraft->title,
            'content_html' => $result['content_html'] ?? '',
            'seo' => is_array($result['seo'] ?? null) ? $result['seo'] : [],
            'translation_notes' => $result['translation_notes'] ?? null,
            'model_used' => $model,
            'input_tokens' => $response->usage?->inputTokens ?? 0,
            'output_tokens' => $response->usage?->outputTokens ?? 0,
            'total_tokens' => ($response->usage?->inputTokens ?? 0) + ($response->usage?->outputTokens ?? 0),
            'request_id' => $response->requestId ?? null,
        ];
    }

    private function normalizeOptionalContextValue(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function normalizeTranslationResultForLanguage(array $result, SupportedLanguage $targetLanguage): array
    {
        if ($targetLanguage !== SupportedLanguage::NL) {
            return $result;
        }

        foreach (['title', 'translation_notes'] as $key) {
            if (array_key_exists($key, $result) && is_string($result[$key])) {
                $result[$key] = DutchTextCasingNormalizer::normalizeText($result[$key]);
            }
        }

        if (array_key_exists('content_html', $result) && is_string($result['content_html'])) {
            $result['content_html'] = $this->normalizeDutchHtmlHeadingCasing($result['content_html']);
        }

        if (isset($result['seo']) && is_array($result['seo'])) {
            foreach (['seo_title', 'seo_meta_description', 'seo_h1', 'seo_og_title', 'seo_og_description', 'seo_twitter_title', 'seo_twitter_description'] as $key) {
                if (array_key_exists($key, $result['seo']) && is_string($result['seo'][$key])) {
                    $result['seo'][$key] = DutchTextCasingNormalizer::normalizeText($result['seo'][$key]);
                }
            }
        }

        return $result;
    }

    private function normalizeDutchHtmlHeadingCasing(string $html): string
    {
        return preg_replace_callback('/(<h[1-6]\b[^>]*>)(.*?)(<\/h[1-6]>)/is', function (array $matches): string {
            $inner = (string) $matches[2];
            if ($inner !== strip_tags($inner)) {
                return (string) $matches[0];
            }

            return $matches[1].DutchTextCasingNormalizer::normalizeText($inner).$matches[3];
        }, $html) ?? $html;
    }

    public function createTranslatedDraft(
        Draft $sourceDraft,
        SupportedLanguage $targetLanguage,
        array $translationResult,
        ?string $userId = null
    ): Draft {
        $translationResult = $this->normalizeTranslationResultForLanguage($translationResult, $targetLanguage);
        $originalSource = $sourceDraft->getOriginalSourceDraft() ?? $sourceDraft;
        $originalSourceLanguage = $this->resolveSourceLanguage($originalSource);
        $titleResult = TitleSanitizer::normalizeWithMetadata(
            $translationResult['title'] ?? $sourceDraft->title,
            fallback: 'Untitled translation'
        );
        $translatedTitle = $titleResult['title'];
        $translationResult = $this->withSanitizedTitleMeta($translationResult, $titleResult);
        $this->logTitleShortening('translation.title_shortened', $titleResult, [
            'source_draft_id' => (string) $sourceDraft->id,
            'target_language' => $targetLanguage->value,
        ]);
        $translatedContentHtml = (string) ($translationResult['content_html'] ?? '');
        $localizedSeo = $this->seoLocalizationService->buildLocalizedSeoMetadata(
            $sourceDraft,
            $translatedTitle,
            $targetLanguage,
            (array) ($translationResult['seo'] ?? [])
        );
        $targetSelection = $this->resolveTranslationTargetSelection(
            $sourceDraft,
            $targetLanguage,
            $localizedSeo['slug'] ?? null
        );

        if ($targetSelection['content'] instanceof Content) {
            return $this->refreshTranslatedDraft(
                $sourceDraft,
                $targetSelection['content'],
                $targetLanguage,
                $translationResult,
                $userId
            );
        }

        $translatedDraft = DB::transaction(function () use (
            $sourceDraft,
            $originalSource,
            $originalSourceLanguage,
            $targetLanguage,
            $translationResult,
            $userId,
            $translatedTitle,
            $translatedContentHtml,
            $localizedSeo
        ): Draft {
            $translatedContent = $this->createTranslatedContent(
                $sourceDraft,
                $targetLanguage,
                $translatedTitle,
                $localizedSeo
            );

            $translatedBrief = $this->createTranslatedBrief(
                $sourceDraft,
                $translatedContent,
                $targetLanguage,
                $translatedTitle,
                $translationResult,
                $localizedSeo,
                $userId
            );

            $draft = Draft::create([
                'brief_id' => $translatedBrief->id,
                'content_id' => $translatedContent->id,
                'client_site_id' => $sourceDraft->client_site_id,
                'content_destination_id' => $sourceDraft->content_destination_id,
                'status' => 'ready',
                'title' => $translatedTitle,
                'output_type' => $sourceDraft->output_type,
                'language' => $targetLanguage->value,
                'draft_type' => DraftType::TRANSLATION->value,
                'source_draft_id' => $originalSource->id,
                'translation_source_language' => $originalSourceLanguage->value,
                'model_used' => $translationResult['model_used'] ?? null,
                'content_html' => $translatedContentHtml,
                'meta' => $this->buildTranslationMeta(
                    $sourceDraft,
                    $translationResult,
                    $userId,
                    $translatedContent,
                    $translatedBrief,
                    $localizedSeo
                ),
                'links' => $sourceDraft->links,
                'delivery_status' => 'pending',
                'seo_title' => $localizedSeo['seo_title'],
                'seo_meta_description' => $localizedSeo['seo_meta_description'],
                'seo_h1' => $localizedSeo['seo_h1'],
                'seo_og_title' => $localizedSeo['seo_og_title'],
                'seo_og_description' => $localizedSeo['seo_og_description'],
                'seo_canonical' => $localizedSeo['seo_canonical'],
                'seo_og_image' => $localizedSeo['seo_og_image'],
                'seo_twitter_title' => $localizedSeo['seo_twitter_title'],
                'seo_twitter_description' => $localizedSeo['seo_twitter_description'],
                'robots_index' => $sourceDraft->robots_index,
                'robots_follow' => $sourceDraft->robots_follow,
                'schema_type' => $sourceDraft->schema_type,
            ]);

            $translatedContent->forceFill([
                'title' => $translatedTitle,
                'seo_title' => $localizedSeo['seo_title'],
                'seo_meta_description' => $localizedSeo['seo_meta_description'],
                'seo_h1' => $localizedSeo['seo_h1'],
                'seo_canonical' => $localizedSeo['seo_canonical'],
                'seo_og_title' => $localizedSeo['seo_og_title'],
                'seo_og_description' => $localizedSeo['seo_og_description'],
                'seo_og_image' => $localizedSeo['seo_og_image'],
                'seo_twitter_title' => $localizedSeo['seo_twitter_title'],
                'seo_twitter_description' => $localizedSeo['seo_twitter_description'],
                'primary_keyword' => $localizedSeo['primary_keyword'],
                'publish_url_key' => $localizedSeo['slug'],
                'external_key' => $this->resolveExternalKeyForTranslation(
                    $sourceDraft,
                    $localizedSeo['slug'],
                    $targetLanguage,
                    $translatedContent
                ),
            ])->save();

            return $draft;
        });

        Log::info('Created translated draft', [
            'translated_draft_id' => $translatedDraft->id,
            'source_draft_id' => $sourceDraft->id,
            'translated_content_id' => $translatedDraft->content_id,
            'translated_brief_id' => $translatedDraft->brief_id,
            'target_language' => $targetLanguage->value,
        ]);

        return $translatedDraft->fresh(['content.seo', 'brief']) ?? $translatedDraft;
    }

    public function validateSourceDraft(Draft $sourceDraft): void
    {
        if (! $sourceDraft->canBeTranslated()) {
            throw new RuntimeException(
                'Translations must be created from original or hybrid drafts, not from other translations.'
            );
        }

        if (empty($sourceDraft->content_html)) {
            throw new RuntimeException('Source draft has no content to translate.');
        }

        if (! in_array($sourceDraft->status, ['ready', 'delivered', 'published'], true)) {
            throw new RuntimeException(
                'Source content must be available as a ready draft or a delivered/published version before it can be translated.'
            );
        }
    }

    public function resolveSourceLanguage(Draft $sourceDraft): SupportedLanguage
    {
        $sourceDraft->loadMissing('content.translationSourceContent', 'sourceDraft.content.translationSourceContent');

        $draftLanguage = SupportedLanguage::fromStringOrDefault((string) $sourceDraft->getRawOriginal('language'));
        $content = $this->resolveSourceContentRoot($sourceDraft) ?? $sourceDraft->content;

        if ($content instanceof Content && (! $content->isTranslationVariant() || (bool) $content->is_source_locale)) {
            $contentLanguage = SupportedLanguage::fromStringOrDefault((string) $content->getRawOriginal('language'));

            if ($contentLanguage !== $draftLanguage) {
                Log::info('translation.source_language_normalized_from_content', [
                    'source_draft_id' => (string) $sourceDraft->id,
                    'content_id' => (string) $content->id,
                    'source_locale' => trim((string) $sourceDraft->getRawOriginal('language')),
                    'normalized_source_locale' => $contentLanguage->value,
                    'reason' => 'content_source_locale_preferred',
                ]);
            }

            return $contentLanguage;
        }

        return $draftLanguage;
    }

    public function validateTargetLanguage(Draft $sourceDraft, SupportedLanguage $targetLanguage): void
    {
        $this->validateTargetLanguageAvailabilityForDispatch($sourceDraft, $targetLanguage, false);
    }

    public function validateTargetLanguageAvailability(
        Draft $sourceDraft,
        SupportedLanguage $targetLanguage,
        bool $allowExisting = false,
        ?string $currentJobUuid = null,
        ?string $currentTranslationRequestId = null,
        ?string $currentTargetContentId = null,
        bool $bypassDispatchOnlyProcessingCheck = false,
    ): void
    {
        $this->validateTargetLanguageAvailabilityForDispatch(
            $sourceDraft,
            $targetLanguage,
            $allowExisting,
            $currentJobUuid,
            $currentTranslationRequestId,
            $currentTargetContentId,
            $bypassDispatchOnlyProcessingCheck,
        );
    }

    public function validateTargetLanguageAvailabilityForDispatch(
        Draft $sourceDraft,
        SupportedLanguage $targetLanguage,
        bool $allowExisting = false,
        ?string $currentJobUuid = null,
        ?string $currentTranslationRequestId = null,
        ?string $currentTargetContentId = null,
        bool $bypassDispatchOnlyProcessingCheck = false,
    ): void
    {
        $this->validateTargetLanguageAvailabilityInternal(
            $sourceDraft,
            $targetLanguage,
            $allowExisting,
            $currentJobUuid,
            $currentTranslationRequestId,
            false,
            null,
            $currentTargetContentId,
            $bypassDispatchOnlyProcessingCheck,
        );
    }

    public function validateTargetLanguageAvailabilityForJob(
        Draft $draft,
        SupportedLanguage $targetLanguage,
        bool $allowExisting = false,
        ?string $currentJobUuid = null,
        ?string $currentTranslationRequestId = null,
        ?string $currentQueueJobId = null,
        ?string $currentTargetContentId = null,
        bool $bypassDispatchOnlyProcessingCheck = false,
    ): void
    {
        $this->validateTargetLanguageAvailabilityInternal(
            $draft,
            $targetLanguage,
            $allowExisting,
            $currentJobUuid,
            $currentTranslationRequestId,
            true,
            $currentQueueJobId,
            $currentTargetContentId,
            $bypassDispatchOnlyProcessingCheck,
        );
    }

    private function validateTargetLanguageAvailabilityInternal(
        Draft $sourceDraft,
        SupportedLanguage $targetLanguage,
        bool $allowExisting,
        ?string $currentJobUuid,
        ?string $currentTranslationRequestId,
        bool $insideActiveJob,
        ?string $currentQueueJobId = null,
        ?string $currentTargetContentId = null,
        bool $bypassDispatchOnlyProcessingCheck = false,
    ): void
    {
        $sourceLanguage = $this->resolveSourceLanguage($sourceDraft);
        $sourceContent = $this->resolveSourceContentRoot($sourceDraft);

        if ($sourceLanguage === $targetLanguage) {
            Log::warning('translation.target_language_rejected', [
                'source_draft_id' => (string) $sourceDraft->id,
                'source_locale' => trim((string) $sourceDraft->getRawOriginal('language')),
                'target_locale' => $targetLanguage->value,
                'normalized_source_locale' => $sourceLanguage->value,
                'normalized_target_locale' => $targetLanguage->value,
                'reason' => 'same_language',
            ]);

            throw new RuntimeException('Cannot translate draft to the same language.');
        }

        $workspace = $this->resolveWorkspace($sourceDraft);
        if ($workspace && ! $workspace->isLanguageEnabled($targetLanguage)) {
            Log::warning('translation.target_language_rejected', [
                'source_draft_id' => (string) $sourceDraft->id,
                'source_locale' => trim((string) $sourceDraft->getRawOriginal('language')),
                'target_locale' => $targetLanguage->value,
                'normalized_source_locale' => $sourceLanguage->value,
                'normalized_target_locale' => $targetLanguage->value,
                'reason' => 'workspace_language_disabled',
            ]);

            throw new RuntimeException(
                "Target language '{$targetLanguage->englishLabel()}' is not enabled for this workspace."
            );
        }

        $inspection = $this->inspectTargetLanguageAvailability($sourceDraft, $targetLanguage);
        $activeTranslationRequest = $sourceContent instanceof Content
            ? $sourceContent->translationRequestForLocale($targetLanguage->value)
            : null;
        $selfOwnedTranslationRequest = $activeTranslationRequest instanceof ContentTranslation
            && $this->translationRequestBelongsToCurrentJob($activeTranslationRequest, $currentJobUuid, $currentTranslationRequestId, $currentQueueJobId)
                ? $activeTranslationRequest
                : null;

        $this->logTargetLanguageAvailabilityInspection($inspection, $allowExisting);

        if (
            $activeTranslationRequest instanceof ContentTranslation
            && ! ($selfOwnedTranslationRequest instanceof ContentTranslation)
            && $this->translationLocks->translationIsActuallyRunning($activeTranslationRequest)
        ) {
            Log::warning('TRANSLATION_VALIDATION_CONFLICTING_REQUEST_BLOCKED', [
                'source_draft_id' => (string) $sourceDraft->id,
                'source_content_id' => $inspection['source_content_id'],
                'target_locale' => $targetLanguage->value,
                'translation_request_id' => (string) $activeTranslationRequest->id,
                'translation_request_status' => $activeTranslationRequest->status,
                'translation_request_job_uuid' => $activeTranslationRequest->processing_job_uuid,
                'current_job_uuid' => $currentJobUuid,
                'current_translation_request_id' => $currentTranslationRequestId,
                'inside_active_job' => $insideActiveJob,
                'bypass_dispatch_only_processing_check' => $bypassDispatchOnlyProcessingCheck,
            ]);

            if (! $insideActiveJob || ! $bypassDispatchOnlyProcessingCheck) {
                Log::warning('translation.target_language_rejected', [
                    'source_draft_id' => (string) $sourceDraft->id,
                    'source_content_id' => $inspection['source_content_id'],
                    'source_locale' => trim((string) $sourceDraft->getRawOriginal('language')),
                    'target_locale' => $targetLanguage->value,
                    'normalized_source_locale' => $sourceLanguage->value,
                    'normalized_target_locale' => $targetLanguage->value,
                    'reason' => 'translation_request_active',
                    'translation_request_id' => (string) $activeTranslationRequest->id,
                    'translation_request_status' => $activeTranslationRequest->status,
                    'current_job_uuid' => $currentJobUuid,
                    'current_translation_request_id' => $currentTranslationRequestId,
                    'inside_active_job' => $insideActiveJob,
                ]);

                throw new RuntimeException(
                    "A translation to '{$targetLanguage->englishLabel()}' is already {$activeTranslationRequest->status}."
                );
            }
        }

        if ($selfOwnedTranslationRequest instanceof ContentTranslation) {
            Log::info('TRANSLATION_VALIDATION_SELF_REQUEST_ALLOWED', [
                'source_draft_id' => (string) $sourceDraft->id,
                'source_content_id' => $inspection['source_content_id'],
                'target_locale' => $targetLanguage->value,
                'translation_request_id' => (string) $selfOwnedTranslationRequest->id,
                'translation_request_status' => $selfOwnedTranslationRequest->status,
                'translation_request_job_uuid' => $selfOwnedTranslationRequest->processing_job_uuid,
                'current_job_uuid' => $currentJobUuid,
                'current_translation_request_id' => $currentTranslationRequestId,
                'inside_active_job' => $insideActiveJob,
            ]);
        }

        $blockingRecords = $inspection['blocking_records'];

        if ($selfOwnedTranslationRequest instanceof ContentTranslation) {
            $allowedTargetContentId = $currentTargetContentId ?: ($selfOwnedTranslationRequest->target_content_id
                ? (string) $selfOwnedTranslationRequest->target_content_id
                : null);

            $blockingRecords = array_values(array_filter(
                $blockingRecords,
                function (array $record) use ($selfOwnedTranslationRequest, $allowedTargetContentId): bool {
                    if (
                        (string) ($record['model_type'] ?? '') === 'translation_request'
                        && (string) ($record['id'] ?? '') === (string) $selfOwnedTranslationRequest->id
                    ) {
                        return false;
                    }

                    if (
                        $allowedTargetContentId !== null
                        && (string) ($record['model_type'] ?? '') === 'content'
                        && (string) ($record['id'] ?? '') === $allowedTargetContentId
                    ) {
                        return false;
                    }

                    if (
                        $allowedTargetContentId !== null
                        && (string) ($record['model_type'] ?? '') === 'draft'
                        && (string) ($record['content_id'] ?? '') === $allowedTargetContentId
                    ) {
                        return false;
                    }

                    return true;
                }
            ));
        } elseif ($allowExisting && trim((string) $currentTargetContentId) !== '') {
            $blockingRecords = array_values(array_filter(
                $blockingRecords,
                fn (array $record): bool => ! (
                    (
                        (string) ($record['model_type'] ?? '') === 'content'
                        && (string) ($record['id'] ?? '') === trim((string) $currentTargetContentId)
                    ) || (
                        (string) ($record['model_type'] ?? '') === 'draft'
                        && (string) ($record['content_id'] ?? '') === trim((string) $currentTargetContentId)
                    )
                )
            ));
        }

        $blockingTargetContentRecords = array_values(array_filter(
            $blockingRecords,
            fn (array $record): bool => (string) ($record['model_type'] ?? '') === 'content'
        ));

        if ($blockingTargetContentRecords !== []) {
            Log::warning('TRANSLATION_VALIDATION_TARGET_EXISTS_BLOCKED', [
                'source_draft_id' => (string) $sourceDraft->id,
                'source_content_id' => $inspection['source_content_id'],
                'target_locale' => $targetLanguage->value,
                'blocking_records' => $blockingTargetContentRecords,
                'current_job_uuid' => $currentJobUuid,
                'current_translation_request_id' => $currentTranslationRequestId,
            ]);
        }

        if ($blockingRecords !== []) {
            $hasConflictingActiveRequest = collect($blockingRecords)->contains(
                fn (array $record): bool => (string) ($record['model_type'] ?? '') === 'translation_request'
            );

            if ($hasConflictingActiveRequest) {
                Log::warning('TRANSLATION_VALIDATION_CONFLICTING_REQUEST_BLOCKED', [
                    'source_draft_id' => (string) $sourceDraft->id,
                    'source_content_id' => $inspection['source_content_id'],
                    'target_locale' => $targetLanguage->value,
                    'blocking_records' => $blockingRecords,
                    'current_job_uuid' => $currentJobUuid,
                    'current_translation_request_id' => $currentTranslationRequestId,
                ]);
            }

            Log::warning('translation.target_language_rejected', [
                'source_draft_id' => (string) $sourceDraft->id,
                'source_content_id' => $inspection['source_content_id'],
                'source_locale' => trim((string) $sourceDraft->getRawOriginal('language')),
                'target_locale' => $targetLanguage->value,
                'normalized_source_locale' => $sourceLanguage->value,
                'normalized_target_locale' => $targetLanguage->value,
                'reason' => $hasConflictingActiveRequest ? 'translation_request_active' : 'translation_already_exists',
                'translation_request_id' => $activeTranslationRequest?->id ? (string) $activeTranslationRequest->id : null,
                'translation_request_status' => $activeTranslationRequest?->status,
                'current_job_uuid' => $currentJobUuid,
                'current_translation_request_id' => $currentTranslationRequestId,
                'inside_active_job' => $insideActiveJob,
                'bypass_dispatch_only_processing_check' => $bypassDispatchOnlyProcessingCheck,
                'blocking_records' => $blockingRecords,
                'ignored_records' => $inspection['ignored_records'],
            ]);

            if ($hasConflictingActiveRequest) {
                throw new RuntimeException(
                    "A translation to '{$targetLanguage->englishLabel()}' is already processing."
                );
            }

            throw new RuntimeException(
                "A translation to '{$targetLanguage->englishLabel()}' already exists for this draft."
            );
        }
    }

    private function translationRequestBelongsToCurrentJob(
        ContentTranslation $translation,
        ?string $currentJobUuid,
        ?string $currentTranslationRequestId,
        ?string $currentQueueJobId = null,
    ): bool {
        $currentJobUuid = trim((string) $currentJobUuid);
        $currentTranslationRequestId = trim((string) $currentTranslationRequestId);
        $currentQueueJobId = trim((string) $currentQueueJobId);

        return ($currentJobUuid !== '' && trim((string) $translation->processing_job_uuid) === $currentJobUuid)
            || ($currentTranslationRequestId !== '' && (string) $translation->id === $currentTranslationRequestId)
            || ($currentQueueJobId !== '' && trim((string) $translation->job_id) === $currentQueueJobId)
            || $this->translationLocks->lockBelongsToJob($translation, $currentJobUuid);
    }

    /**
     * @return array{
     *     source_draft_id:string,
     *     source_content_id:?string,
     *     source_locale:string,
     *     target_locale:string,
     *     draft_records:array<int,array<string,mixed>>,
     *     content_records:array<int,array<string,mixed>>,
     *     blocking_records:array<int,array<string,mixed>>,
     *     ignored_records:array<int,array<string,mixed>>,
     *     has_blocking_variant:bool
     * }
     */
    public function inspectTargetLanguageAvailability(
        Draft $sourceDraft,
        SupportedLanguage $targetLanguage
    ): array {
        $sourceLanguage = $this->resolveSourceLanguage($sourceDraft);
        $originalSource = $sourceDraft->getOriginalSourceDraft() ?? $sourceDraft;

        $originalSource->loadMissing([
            'content.translationSourceContent',
            'content.familyRoot',
            'content.currentVersion',
            'content.translationRequests',
            'content.localizedVariants.translationSourceContent',
            'content.localizedVariants.currentVersion',
            'content.localizedVariants.drafts',
        ]);

        $sourceLocalizationRoot = $this->resolveSourceContentRoot($originalSource);

        $draftCandidates = Draft::query()
            ->with([
                'content.translationSourceContent',
                'content.currentVersion',
                'content.drafts',
                'brief',
            ])
            ->where('source_draft_id', $originalSource->id)
            ->where('language', $targetLanguage->value)
            ->where('draft_type', DraftType::TRANSLATION->value)
            ->whereNotIn('status', ['failed', 'cancelled'])
            ->latest('created_at')
            ->get();

        $contentCandidates = $sourceLocalizationRoot instanceof Content
            ? $sourceLocalizationRoot
                ->localizationFamily()
                ->filter(fn (Content $candidate): bool => (string) $candidate->id !== (string) $sourceLocalizationRoot->id)
                ->filter(fn (Content $candidate): bool => $candidate->localeCode() === $targetLanguage->value)
                ->values()
            : collect();

        $translationRequestCandidate = $sourceLocalizationRoot instanceof Content
            ? $sourceLocalizationRoot->translationRequestForLocale($targetLanguage->value)
            : null;
        $translationRequestRecords = $translationRequestCandidate instanceof ContentTranslation
            && $translationRequestCandidate->isActiveLock()
            ? collect([$this->describeContentTranslationRequestCandidate($translationRequestCandidate)])
            : collect();

        $inspectedContentCandidates = $contentCandidates
            ->map(fn (Content $candidate): array => $this->describeContentTranslationCandidate($candidate, $sourceLocalizationRoot))
            ->values();

        $inspectedContentById = $inspectedContentCandidates
            ->keyBy(fn (array $candidate): string => (string) ($candidate['id'] ?? ''));

        $inspectedDraftCandidates = $draftCandidates
            ->map(fn (Draft $candidate): array => $this->describeDraftTranslationCandidate(
                $candidate,
                $targetLanguage,
                $sourceLocalizationRoot,
                $inspectedContentById->get((string) ($candidate->content_id ?? ''))
            ))
            ->values();

        $blockingDraftRecords = $inspectedDraftCandidates
            ->filter(fn (array $candidate): bool => (bool) ($candidate['blocking'] ?? false))
            ->values();

        $blockingContentRecords = $inspectedContentCandidates
            ->filter(function (array $candidate) use ($blockingDraftRecords): bool {
                if (! ($candidate['blocking'] ?? false)) {
                    return false;
                }

                return ! $blockingDraftRecords->contains(
                    fn (array $draftCandidate): bool => (string) ($draftCandidate['content_id'] ?? '') === (string) ($candidate['id'] ?? '')
                );
            })
            ->values();

        $blockingRecords = $blockingDraftRecords
            ->concat($blockingContentRecords)
            ->concat($translationRequestRecords)
            ->values()
            ->all();

        $ignoredRecords = $inspectedDraftCandidates
            ->concat($inspectedContentCandidates)
            ->filter(fn (array $candidate): bool => ! ($candidate['blocking'] ?? false))
            ->values()
            ->all();

        return [
            'source_draft_id' => (string) $originalSource->id,
            'source_content_id' => $sourceLocalizationRoot?->id ? (string) $sourceLocalizationRoot->id : null,
            'source_locale' => $sourceLanguage->value,
            'target_locale' => $targetLanguage->value,
            'draft_records' => $inspectedDraftCandidates->all(),
            'content_records' => $inspectedContentCandidates->all(),
            'blocking_records' => $blockingRecords,
            'ignored_records' => $ignoredRecords,
            'has_blocking_variant' => $blockingRecords !== [],
        ];
    }

    public function refreshTranslatedDraft(
        Draft $sourceDraft,
        Content $existingContent,
        SupportedLanguage $targetLanguage,
        array $translationResult,
        ?string $userId = null
    ): Draft {
        $originalSource = $sourceDraft->getOriginalSourceDraft() ?? $sourceDraft;
        $originalSourceLanguage = $this->resolveSourceLanguage($originalSource);
        $titleResult = TitleSanitizer::normalizeWithMetadata(
            $translationResult['title'] ?? $sourceDraft->title,
            fallback: 'Untitled translation'
        );
        $translatedTitle = $titleResult['title'];
        $translationResult = $this->withSanitizedTitleMeta($translationResult, $titleResult);
        $this->logTitleShortening('translation.title_shortened', $titleResult, [
            'source_draft_id' => (string) $sourceDraft->id,
            'existing_content_id' => (string) $existingContent->id,
            'target_language' => $targetLanguage->value,
        ]);
        $translatedContentHtml = (string) ($translationResult['content_html'] ?? '');
        $localizedSeo = $this->seoLocalizationService->buildLocalizedSeoMetadata(
            $sourceDraft,
            $translatedTitle,
            $targetLanguage,
            (array) ($translationResult['seo'] ?? [])
        );
        $targetSelection = $this->resolveTranslationTargetSelection(
            $sourceDraft,
            $targetLanguage,
            $localizedSeo['slug'] ?? null,
            $existingContent
        );

        if ($targetSelection['content'] instanceof Content) {
            $existingContent = $targetSelection['content'];
        }

        $translatedDraft = DB::transaction(function () use (
            $sourceDraft,
            $originalSource,
            $originalSourceLanguage,
            $existingContent,
            $targetLanguage,
            $translationResult,
            $userId,
            $translatedTitle,
            $translatedContentHtml,
            $localizedSeo
        ): Draft {
            $translatedContent = $this->refreshTranslatedContent(
                $sourceDraft,
                $existingContent,
                $targetLanguage,
                $translatedTitle,
                $localizedSeo
            );

            $translatedBrief = $this->updateOrCreateTranslatedBrief(
                $sourceDraft,
                $translatedContent,
                $targetLanguage,
                $translatedTitle,
                $translationResult,
                $localizedSeo,
                $userId
            );

            return Draft::create([
                'brief_id' => $translatedBrief->id,
                'content_id' => $translatedContent->id,
                'client_site_id' => $sourceDraft->client_site_id,
                'content_destination_id' => $sourceDraft->content_destination_id,
                'status' => 'ready',
                'title' => $translatedTitle,
                'output_type' => $sourceDraft->output_type,
                'language' => $targetLanguage->value,
                'draft_type' => DraftType::TRANSLATION->value,
                'source_draft_id' => $originalSource->id,
                'translation_source_language' => $originalSourceLanguage->value,
                'model_used' => $translationResult['model_used'] ?? null,
                'content_html' => $translatedContentHtml,
                'meta' => $this->buildTranslationMeta(
                    $sourceDraft,
                    $translationResult,
                    $userId,
                    $translatedContent,
                    $translatedBrief,
                    $localizedSeo
                ),
                'links' => $sourceDraft->links,
                'delivery_status' => 'pending',
                'seo_title' => $localizedSeo['seo_title'],
                'seo_meta_description' => $localizedSeo['seo_meta_description'],
                'seo_h1' => $localizedSeo['seo_h1'],
                'seo_og_title' => $localizedSeo['seo_og_title'],
                'seo_og_description' => $localizedSeo['seo_og_description'],
                'seo_canonical' => $localizedSeo['seo_canonical'],
                'seo_og_image' => $localizedSeo['seo_og_image'],
                'seo_twitter_title' => $localizedSeo['seo_twitter_title'],
                'seo_twitter_description' => $localizedSeo['seo_twitter_description'],
                'robots_index' => $sourceDraft->robots_index,
                'robots_follow' => $sourceDraft->robots_follow,
                'schema_type' => $sourceDraft->schema_type,
            ]);
        });

        return $translatedDraft->fresh(['content.seo', 'brief']) ?? $translatedDraft;
    }

    public function resolveTranslationModel(): string
    {
        return (string) config('translation.default_model', 'gpt-4.1-mini');
    }

    public function getTranslationCreditAction(): ?CreditAction
    {
        return CreditAction::query()
            ->where('key', 'translate.locale_version')
            ->where('is_active', true)
            ->first();
    }

    public function estimateTranslationCredits(Draft $sourceDraft): int
    {
        $action = $this->getTranslationCreditAction();
        if ($action) {
            return (int) $action->credits_cost;
        }

        return (int) config('translation.default_credit_cost', 6);
    }

    public function canTranslateToLanguages(Draft $sourceDraft): array
    {
        if (! $sourceDraft->canBeTranslated()) {
            return [];
        }

        $workspace = $this->resolveWorkspace($sourceDraft);
        if (! $workspace) {
            return [];
        }

        $currentLanguage = $this->resolveSourceLanguage($sourceDraft);

        return array_filter(
            $workspace->getEnabledLanguagesAsEnums(),
            fn (SupportedLanguage $lang): bool =>
                $lang !== $currentLanguage
                && ! $this->inspectTargetLanguageAvailability($sourceDraft, $lang)['has_blocking_variant']
        );
    }

    public function resolveTargetVariantContent(
        Draft $sourceDraft,
        SupportedLanguage $targetLanguage,
        ?Content $preferredContent = null
    ): ?Content {
        return $this->resolveTranslationTargetSelection(
            $sourceDraft,
            $targetLanguage,
            null,
            $preferredContent
        )['content'];
    }

    /**
     * @param  array<string,mixed>|null  $contentInspection
     * @return array<string,mixed>
     */
    private function describeDraftTranslationCandidate(
        Draft $candidate,
        SupportedLanguage $targetLanguage,
        ?Content $sourceLocalizationRoot,
        ?array $contentInspection = null
    ): array {
        $draftLocale = SupportedLanguage::fromStringOrDefault((string) $candidate->getRawOriginal('language'))->value;
        $isUsableDraft = $this->isUsableTranslatedDraft($candidate);
        $content = $candidate->content;
        $contentLocale = $content instanceof Content ? $content->localeCode() : null;
        $contentStatus = $content instanceof Content ? (string) $content->status : null;
        $publishStatus = $content instanceof Content ? (string) ($content->publish_status ?? '') : null;
        $legacyMigration = (bool) ($contentInspection['legacy_migration'] ?? false);
        $routeOrSlug = $this->resolveRouteOrSlugForDraft($candidate, $content);
        $reason = 'draft_only_translation_candidate';
        $blocking = false;

        if ($draftLocale !== $targetLanguage->value) {
            $reason = 'draft_locale_mismatch';
        } elseif (! $isUsableDraft) {
            $reason = 'incomplete_translation_attempt';
        } elseif (! $content instanceof Content) {
            $reason = 'orphaned_translation_draft';
        } elseif ($contentInspection !== null) {
            $blocking = (bool) ($contentInspection['blocking'] ?? false);
            $reason = $blocking
                ? 'valid_draft_locale_variant'
                : (string) ($contentInspection['block_reason'] ?? 'no_renderable_variant_state');
        } elseif ($content instanceof Content && $content->localeCode() !== $targetLanguage->value) {
            $reason = 'content_locale_mismatch';
        } elseif ($sourceLocalizationRoot instanceof Content) {
            $reason = 'content_family_mismatch';
        } else {
            $blocking = true;
        }

        return [
            'id' => (string) $candidate->id,
            'model_type' => 'draft',
            'locale' => $draftLocale,
            'status' => (string) $candidate->status,
            'publish_status' => null,
            'is_source' => false,
            'soft_deleted' => false,
            'legacy_migration' => $legacyMigration,
            'route_or_slug' => $routeOrSlug,
            'content_id' => $content?->id ? (string) $content->id : null,
            'content_locale' => $contentLocale,
            'content_status' => $contentStatus,
            'content_publish_status' => $publishStatus,
            'source_content_id' => $sourceLocalizationRoot?->id ? (string) $sourceLocalizationRoot->id : null,
            'linked_source_content_id' => $content instanceof Content && $content->translation_source_content_id
                ? (string) $content->translation_source_content_id
                : null,
            'blocking' => $blocking,
            'block_reason' => $reason,
            'repairable' => ! $blocking && in_array($reason, [
                'orphaned_translation_draft',
                'content_family_mismatch',
                'content_locale_mismatch',
                'legacy_locale_migration_record',
                'no_renderable_variant_state',
                'source_locale_record',
            ], true),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function describeContentTranslationCandidate(
        Content $candidate,
        Content $sourceLocalizationRoot
    ): array {
        $candidate->loadMissing('currentVersion', 'drafts');

        $legacyMigration = $this->isLegacyLocaleMigrationRecord($candidate);
        $hasRenderableVersion = trim((string) ($candidate->currentVersion?->body ?? '')) !== '';
        $hasUsableDraft = $candidate->drafts->contains(
            fn (Draft $draft): bool => $this->isUsableTranslatedDraft($draft)
                && SupportedLanguage::fromStringOrDefault((string) $draft->getRawOriginal('language'))->value === $candidate->localeCode()
        );
        $isLinkedVariant = $candidate->isTranslationVariant()
            && ! (bool) $candidate->is_source_locale
            && (string) $candidate->translation_source_content_id === (string) $sourceLocalizationRoot->id;
        $isUsableVariant = $isLinkedVariant
            && ! $legacyMigration
            && (
                $hasRenderableVersion
                || $candidate->isPublishedForTranslation()
                || $candidate->isDeliveredForTranslation()
                || $hasUsableDraft
            );

        $reason = match (true) {
            $legacyMigration => 'legacy_locale_migration_record',
            ! $candidate->isTranslationVariant() || (bool) $candidate->is_source_locale => 'source_locale_record',
            (string) $candidate->translation_source_content_id !== (string) $sourceLocalizationRoot->id => 'content_family_mismatch',
            ! $isUsableVariant => 'no_renderable_variant_state',
            default => 'valid_content_locale_variant',
        };

        return [
            'id' => (string) $candidate->id,
            'model_type' => 'content',
            'locale' => $candidate->localeCode(),
            'status' => (string) $candidate->status,
            'publish_status' => (string) ($candidate->publish_status ?? ''),
            'is_source' => (bool) $candidate->is_source_locale,
            'soft_deleted' => false,
            'legacy_migration' => $legacyMigration,
            'route_or_slug' => $this->resolveRouteOrSlugForContent($candidate),
            'source_content_id' => (string) $sourceLocalizationRoot->id,
            'linked_source_content_id' => $candidate->translation_source_content_id ? (string) $candidate->translation_source_content_id : null,
            'blocking' => $isUsableVariant,
            'block_reason' => $reason,
            'has_renderable_version' => $hasRenderableVersion,
            'has_usable_draft' => $hasUsableDraft,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function describeContentTranslationRequestCandidate(ContentTranslation $translationRequest): array
    {
        return [
            'id' => (string) $translationRequest->id,
            'model_type' => 'translation_request',
            'locale' => $translationRequest->target_locale,
            'status' => $translationRequest->status,
            'publish_status' => null,
            'is_source' => false,
            'soft_deleted' => false,
            'legacy_migration' => false,
            'route_or_slug' => null,
            'source_content_id' => (string) $translationRequest->content_id,
            'linked_source_content_id' => null,
            'blocking' => true,
            'block_reason' => 'active_translation_request',
            'target_content_id' => $translationRequest->target_content_id ? (string) $translationRequest->target_content_id : null,
            'error_message' => $translationRequest->error_message,
        ];
    }

    private function isUsableTranslatedDraft(Draft $draft): bool
    {
        return in_array((string) $draft->status, ['ready', 'delivered', 'published'], true)
            && trim((string) $draft->content_html) !== '';
    }

    private function isLegacyLocaleMigrationRecord(Content $content): bool
    {
        $meta = is_array($content->locale_repair_meta) ? $content->locale_repair_meta : [];

        return (string) data_get($meta, 'repair_type') === 'legacy_locale_repair'
            || trim((string) data_get($meta, 'legacy_route_locale')) !== '';
    }

    private function resolveRouteOrSlugForDraft(Draft $draft, ?Content $content = null): ?string
    {
        $draftSlug = trim((string) data_get($draft->brief?->client_refs, 'slug'));

        return $this->resolveRouteOrSlugForContent($content)
            ?? ($draftSlug !== '' ? $draftSlug : null);
    }

    private function resolveRouteOrSlugForContent(?Content $content): ?string
    {
        if (! $content instanceof Content) {
            return null;
        }

        $slug = trim((string) ($content->publish_url_key
            ?: data_get($content->currentVersion?->meta, 'slug')
            ?: $content->canonical_url_key));

        return $slug !== '' ? $slug : null;
    }

    /**
     * @param  array{
     *     source_draft_id:string,
     *     source_content_id:?string,
     *     source_locale:string,
     *     target_locale:string,
     *     draft_records:array<int,array<string,mixed>>,
     *     content_records:array<int,array<string,mixed>>,
     *     blocking_records:array<int,array<string,mixed>>,
     *     ignored_records:array<int,array<string,mixed>>,
     *     has_blocking_variant:bool
     * }  $inspection
     */
    private function logTargetLanguageAvailabilityInspection(array $inspection, bool $allowExisting): void
    {
        if ($inspection['blocking_records'] === [] && $inspection['ignored_records'] === []) {
            return;
        }

        Log::info('translation.target_language_availability_inspected', [
            'source_draft_id' => $inspection['source_draft_id'],
            'source_content_id' => $inspection['source_content_id'],
            'source_locale' => $inspection['source_locale'],
            'target_locale' => $inspection['target_locale'],
            'allow_existing' => $allowExisting,
            'blocking_records' => $inspection['blocking_records'],
            'ignored_records' => $inspection['ignored_records'],
            'draft_records' => $inspection['draft_records'],
            'content_records' => $inspection['content_records'],
        ]);
    }

    private function resolveWorkspace(Draft $sourceDraft): ?Workspace
    {
        $sourceDraft->loadMissing('clientSite.workspace');

        return $sourceDraft->clientSite?->workspace;
    }

    /**
     * @param  array<string, mixed>  $localizedSeo
     */
    private function createTranslatedContent(
        Draft $sourceDraft,
        SupportedLanguage $targetLanguage,
        string $translatedTitle,
        array $localizedSeo
    ): Content {
        $sourceContent = $this->resolveSourceContentRoot($sourceDraft);
        $sourceLanguage = $sourceContent instanceof Content
            ? SupportedLanguage::fromStringOrDefault($sourceContent->localeCode())
            : $this->resolveSourceLanguage($sourceDraft);
        $workspaceId = $sourceContent?->workspace_id ?? $sourceDraft->clientSite?->workspace_id;

        if (! $workspaceId) {
            throw new RuntimeException('Workspace context is missing for translated content.');
        }

        $sourceContent?->loadMissing('currentVersion');
        $translationSourceUpdatedAt = $sourceContent?->currentVersion?->updated_at
            ?: $sourceContent?->updated_at;

        if ($sourceContent instanceof Content) {
            $sourceContent->forceFill([
                'family_id' => $sourceContent->family_id ?: (string) $sourceContent->id,
                'translation_source_locale' => null,
                'is_source_locale' => true,
            ])->save();
        }

        $content = Content::create(ContentPersistencePayloadNormalizer::normalize([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'client_site_id' => $sourceDraft->client_site_id,
            'content_destination_id' => $sourceDraft->content_destination_id,
            'title' => $translatedTitle,
            'language' => $targetLanguage->value,
            'family_id' => $sourceContent?->localizationRootId(),
            'translation_source_content_id' => $sourceContent?->id,
            'translation_source_version_id' => $sourceContent?->current_version_id,
            'translation_source_locale' => $sourceLanguage->value,
            'is_source_locale' => false,
            'sync_with_source' => true,
            'auto_publish' => (bool) ($sourceContent?->auto_publish ?? true),
            'translation_generated_at' => now(),
            'translation_source_updated_at' => $translationSourceUpdatedAt,
            'seo_title' => $localizedSeo['seo_title'],
            'seo_meta_description' => $localizedSeo['seo_meta_description'],
            'seo_h1' => $localizedSeo['seo_h1'],
            'seo_canonical' => $localizedSeo['seo_canonical'],
            'seo_og_title' => $localizedSeo['seo_og_title'],
            'seo_og_description' => $localizedSeo['seo_og_description'],
            'seo_og_image' => $localizedSeo['seo_og_image'],
            'seo_twitter_title' => $localizedSeo['seo_twitter_title'],
            'seo_twitter_description' => $localizedSeo['seo_twitter_description'],
            'robots_index' => $sourceDraft->robots_index,
            'robots_follow' => $sourceDraft->robots_follow,
            'schema_type' => $sourceDraft->schema_type,
            'primary_keyword' => $localizedSeo['primary_keyword'],
            'type' => (string) ($sourceContent?->type ?? 'article'),
            'status' => 'draft',
            'source' => $sourceContent?->source instanceof ContentSource
                ? $sourceContent->source->value
                : (string) ($sourceContent?->source ?? 'manual'),
            'automation_id' => $sourceContent?->automation_id,
            'automation_run_id' => $sourceContent?->automation_run_id,
            'delivery_status' => 'pending',
            'publish_status' => 'draft',
            'publish_error' => null,
            'generation_mode' => (string) ($sourceContent?->generation_mode ?? 'balanced'),
            'brand_voice_id' => $sourceContent?->brand_voice_id,
            'buyer_persona_id' => $sourceContent?->buyer_persona_id,
            'team_member_id' => $sourceContent?->team_member_id,
            'preferred_length' => $sourceContent?->preferred_length,
        ]));

        ContentSeo::query()->updateOrCreate(
            ['content_id' => $content->id],
            [
                'meta_title' => $localizedSeo['seo_title'],
                'meta_description' => $localizedSeo['seo_meta_description'],
                'primary_keyword' => $localizedSeo['primary_keyword'],
                'secondary_keywords' => $localizedSeo['secondary_keywords'],
                'robots_index' => $sourceDraft->robots_index,
                'robots_follow' => $sourceDraft->robots_follow,
                'schema_type' => $sourceDraft->schema_type,
            ]
        );

        return $content;
    }

    /**
     * @param  array<string, mixed>  $localizedSeo
     */
    private function refreshTranslatedContent(
        Draft $sourceDraft,
        Content $existingContent,
        SupportedLanguage $targetLanguage,
        string $translatedTitle,
        array $localizedSeo
    ): Content {
        $sourceContent = $this->resolveSourceContentRoot($sourceDraft);
        $sourceLanguage = $sourceContent instanceof Content
            ? SupportedLanguage::fromStringOrDefault($sourceContent->localeCode())
            : $this->resolveSourceLanguage($sourceDraft);
        $sourceContent?->loadMissing('currentVersion');

        $translationSourceUpdatedAt = $sourceContent?->currentVersion?->updated_at
            ?: $sourceContent?->updated_at;

        if ($sourceContent instanceof Content) {
            $sourceContent->forceFill([
                'family_id' => $sourceContent->family_id ?: (string) $sourceContent->id,
                'translation_source_locale' => null,
                'is_source_locale' => true,
            ])->save();
        }

        $currentSlug = trim((string) ($existingContent->publish_url_key ?? ''));
        $resolvedSlug = $currentSlug !== '' ? $currentSlug : $localizedSeo['slug'];
        $resolvedExternalKey = $this->resolveExternalKeyForTranslation(
            $sourceDraft,
            $resolvedSlug,
            $targetLanguage,
            $existingContent
        );

        $existingContent->forceFill(ContentPersistencePayloadNormalizer::normalize([
            'client_site_id' => $sourceDraft->client_site_id,
            'content_destination_id' => $sourceDraft->content_destination_id,
            'title' => $translatedTitle,
            'language' => $targetLanguage->value,
            'family_id' => $sourceContent?->localizationRootId(),
            'translation_source_content_id' => $sourceContent?->id,
            'translation_source_version_id' => $sourceContent?->current_version_id,
            'translation_source_locale' => $sourceLanguage->value,
            'is_source_locale' => false,
            'translation_generated_at' => now(),
            'translation_source_updated_at' => $translationSourceUpdatedAt,
            'seo_title' => $localizedSeo['seo_title'],
            'seo_meta_description' => $localizedSeo['seo_meta_description'],
            'seo_h1' => $localizedSeo['seo_h1'],
            'seo_canonical' => $localizedSeo['seo_canonical'],
            'seo_og_title' => $localizedSeo['seo_og_title'],
            'seo_og_description' => $localizedSeo['seo_og_description'],
            'seo_og_image' => $localizedSeo['seo_og_image'],
            'seo_twitter_title' => $localizedSeo['seo_twitter_title'],
            'seo_twitter_description' => $localizedSeo['seo_twitter_description'],
            'robots_index' => $sourceDraft->robots_index,
            'robots_follow' => $sourceDraft->robots_follow,
            'schema_type' => $sourceDraft->schema_type,
            'primary_keyword' => $localizedSeo['primary_keyword'],
            'automation_id' => $sourceContent?->automation_id,
            'automation_run_id' => $sourceContent?->automation_run_id,
            'publish_error' => null,
            'delivery_status' => 'pending',
            'publish_url_key' => $resolvedSlug,
            'external_key' => $resolvedExternalKey,
        ]))->save();

        ContentSeo::query()->updateOrCreate(
            ['content_id' => $existingContent->id],
            [
                'meta_title' => $localizedSeo['seo_title'],
                'meta_description' => $localizedSeo['seo_meta_description'],
                'primary_keyword' => $localizedSeo['primary_keyword'],
                'secondary_keywords' => $localizedSeo['secondary_keywords'],
                'robots_index' => $sourceDraft->robots_index,
                'robots_follow' => $sourceDraft->robots_follow,
                'schema_type' => $sourceDraft->schema_type,
            ]
        );

        return $existingContent->fresh(['seo']) ?? $existingContent;
    }

    /**
     * @param  array<string, mixed>  $translationResult
     * @param  array<string, mixed>  $localizedSeo
     */
    private function createTranslatedBrief(
        Draft $sourceDraft,
        Content $translatedContent,
        SupportedLanguage $targetLanguage,
        string $translatedTitle,
        array $translationResult,
        array $localizedSeo,
        ?string $userId
    ): Brief {
        $sourceLanguage = $this->resolveSourceLanguage($sourceDraft);
        $sourceContent = $this->resolveSourceContentRoot($sourceDraft);
        $sourceBrief = $sourceDraft->brief;
        $sourceBriefRefs = is_array($sourceBrief?->client_refs) ? $sourceBrief->client_refs : [];

        return Brief::create(ContentPersistencePayloadNormalizer::normalizeBrief([
            'id' => (string) Str::uuid(),
            'client_site_id' => $sourceDraft->client_site_id,
            'content_destination_id' => $sourceDraft->content_destination_id,
            'created_by_user_id' => $userId ? (int) $userId : null,
            'content_id' => $translatedContent->id,
            'status' => 'done',
            'source' => (string) ($sourceBrief?->source ?? 'client_ui'),
            'progress' => 1.0,
            'title' => $translatedTitle,
            'language' => $targetLanguage->value,
            'content_type' => (string) ($sourceBrief?->content_type ?? 'blog'),
            'intent' => $sourceBrief?->intent,
            'primary_keyword' => $localizedSeo['primary_keyword'],
            'secondary_keywords' => $localizedSeo['secondary_keywords'],
            'audience' => $sourceBrief?->audience,
            'target_audience' => $sourceBrief?->target_audience,
            'funnel_stage' => $sourceBrief?->funnel_stage,
            'search_intent' => $sourceBrief?->search_intent,
            'output_type' => (string) ($sourceBrief?->output_type ?? $sourceDraft->output_type),
            'notes' => $sourceBrief?->notes,
            'tone_of_voice' => $sourceBrief?->tone_of_voice,
            'unique_angle' => $sourceBrief?->unique_angle,
            'key_points' => $sourceBrief?->key_points,
            'call_to_action' => $sourceBrief?->call_to_action,
            'desired_length_min' => $sourceBrief?->desired_length_min,
            'desired_length_max' => $sourceBrief?->desired_length_max,
            'client_refs' => array_merge($sourceBriefRefs, [
                'slug' => $localizedSeo['slug'],
                'translation' => [
                    'is_translation' => true,
                    'source_draft_id' => (string) $sourceDraft->id,
                    'source_content_id' => (string) ($sourceContent?->id ?? $sourceDraft->content_id ?? ''),
                    'source_brief_id' => (string) ($sourceBrief?->id ?? ''),
                    'source_language' => $sourceLanguage->value,
                    'target_language' => $targetLanguage->value,
                    'model_used' => $translationResult['model_used'] ?? null,
                ],
            ]),
        ]));
    }

    /**
     * @param  array<string, mixed>  $translationResult
     * @param  array<string, mixed>  $localizedSeo
     */
    private function updateOrCreateTranslatedBrief(
        Draft $sourceDraft,
        Content $translatedContent,
        SupportedLanguage $targetLanguage,
        string $translatedTitle,
        array $translationResult,
        array $localizedSeo,
        ?string $userId
    ): Brief {
        $sourceLanguage = $this->resolveSourceLanguage($sourceDraft);
        $sourceContent = $this->resolveSourceContentRoot($sourceDraft);
        $existingBrief = $translatedContent->brief;

        if (! $existingBrief) {
            return $this->createTranslatedBrief(
                $sourceDraft,
                $translatedContent,
                $targetLanguage,
                $translatedTitle,
                $translationResult,
                $localizedSeo,
                $userId
            );
        }

        $sourceBrief = $sourceDraft->brief;
        $sourceBriefRefs = is_array($sourceBrief?->client_refs) ? $sourceBrief->client_refs : [];

        $existingBrief->forceFill(ContentPersistencePayloadNormalizer::normalizeBrief([
            'client_site_id' => $sourceDraft->client_site_id,
            'content_destination_id' => $sourceDraft->content_destination_id,
            'created_by_user_id' => $userId ? (int) $userId : $existingBrief->created_by_user_id,
            'content_id' => $translatedContent->id,
            'status' => 'done',
            'source' => (string) ($sourceBrief?->source ?? $existingBrief->source ?? 'client_ui'),
            'progress' => 1.0,
            'title' => $translatedTitle,
            'language' => $targetLanguage->value,
            'content_type' => (string) ($sourceBrief?->content_type ?? $existingBrief->content_type ?? 'blog'),
            'intent' => $sourceBrief?->intent,
            'primary_keyword' => $localizedSeo['primary_keyword'],
            'secondary_keywords' => $localizedSeo['secondary_keywords'],
            'audience' => $sourceBrief?->audience,
            'target_audience' => $sourceBrief?->target_audience,
            'funnel_stage' => $sourceBrief?->funnel_stage,
            'search_intent' => $sourceBrief?->search_intent,
            'output_type' => (string) ($sourceBrief?->output_type ?? $sourceDraft->output_type),
            'notes' => $sourceBrief?->notes,
            'tone_of_voice' => $sourceBrief?->tone_of_voice,
            'unique_angle' => $sourceBrief?->unique_angle,
            'key_points' => $sourceBrief?->key_points,
            'call_to_action' => $sourceBrief?->call_to_action,
            'desired_length_min' => $sourceBrief?->desired_length_min,
            'desired_length_max' => $sourceBrief?->desired_length_max,
            'client_refs' => array_merge($sourceBriefRefs, [
                'slug' => $localizedSeo['slug'],
                'translation' => [
                    'is_translation' => true,
                    'source_draft_id' => (string) $sourceDraft->id,
                    'source_content_id' => (string) ($sourceContent?->id ?? $sourceDraft->content_id ?? ''),
                    'source_brief_id' => (string) ($sourceBrief?->id ?? ''),
                    'source_language' => $sourceLanguage->value,
                    'target_language' => $targetLanguage->value,
                    'model_used' => $translationResult['model_used'] ?? null,
                    'refreshed_at' => now()->toIso8601String(),
                ],
            ]),
        ]))->save();

        return $existingBrief;
    }

    /**
     * @param  array<string, mixed>  $translationResult
     * @param  array<string, mixed>  $localizedSeo
     * @return array<string, mixed>
     */
    private function buildTranslationMeta(
        Draft $sourceDraft,
        array $translationResult,
        ?string $userId,
        Content $translatedContent,
        Brief $translatedBrief,
        array $localizedSeo
    ): array {
        $sourceLanguage = $this->resolveSourceLanguage($sourceDraft);
        $sourceContent = $this->resolveSourceContentRoot($sourceDraft);
        $existingMeta = is_array($sourceDraft->meta) ? $sourceDraft->meta : [];

        return array_merge($existingMeta, [
            'slug' => $localizedSeo['slug'],
            'translation' => [
                'source_draft_id' => $sourceDraft->id,
                'source_content_id' => $sourceContent?->id ?? $sourceDraft->content_id,
                'source_brief_id' => $sourceDraft->brief_id,
                'source_language' => $sourceLanguage->value,
                'target_language' => $translatedContent->language->value,
                'translated_content_id' => $translatedContent->id,
                'translated_brief_id' => $translatedBrief->id,
                'translated_at' => now()->toIso8601String(),
                'translated_by_user_id' => $userId,
                'model_used' => $translationResult['model_used'] ?? null,
                'input_tokens' => $translationResult['input_tokens'] ?? 0,
                'output_tokens' => $translationResult['output_tokens'] ?? 0,
                'total_tokens' => $translationResult['total_tokens'] ?? 0,
                'request_id' => $translationResult['request_id'] ?? null,
                'translation_notes' => $translationResult['translation_notes'] ?? null,
                'original_title' => data_get($translationResult, 'meta.original_title'),
                'title_shortened' => (bool) data_get($translationResult, 'meta.title_shortened', false),
                'seo' => [
                    'slug' => $localizedSeo['slug'],
                    'primary_keyword' => $localizedSeo['primary_keyword'],
                    'secondary_keywords' => $localizedSeo['secondary_keywords'],
                    'needs_review' => (bool) ($localizedSeo['needs_review'] ?? false),
                ],
            ],
            'generation' => [
                'provider' => $this->resolveProviderFromModel($translationResult['model_used'] ?? ''),
                'model' => $translationResult['model_used'] ?? null,
                'input_tokens' => $translationResult['input_tokens'] ?? 0,
                'output_tokens' => $translationResult['output_tokens'] ?? 0,
                'tokens' => $translationResult['total_tokens'] ?? 0,
                'request_id' => $translationResult['request_id'] ?? null,
            ],
        ]);
    }

    private function resolveExternalKeyForTranslation(
        Draft $sourceDraft,
        string $slug,
        SupportedLanguage $targetLanguage,
        ?Content $targetContent = null
    ): string {
        $base = trim((string) ($sourceDraft->content?->external_key ?? ''));
        $base = $base !== '' ? $base : $slug;
        $proposedKey = Str::slug($base . '-' . $targetLanguage->value);
        $targetExternalKey = $targetContent instanceof Content
            ? trim((string) $targetContent->external_key)
            : '';
        $conflictQuery = Content::query()
            ->where('client_site_id', $sourceDraft->client_site_id)
            ->where('external_key', $proposedKey);

        if ($targetContent instanceof Content) {
            $conflictQuery->whereKeyNot($targetContent->id);
        }

        $conflictingContent = $conflictQuery->first(['id', 'external_key']);
        $fallbackGenerated = false;

        if ($targetExternalKey !== '') {
            $targetKeyConflict = Content::query()
                ->where('client_site_id', $sourceDraft->client_site_id)
                ->where('external_key', $targetExternalKey)
                ->whereKeyNot($targetContent->id)
                ->exists();

            if (! $targetKeyConflict) {
                $resolvedKey = $targetExternalKey;
            } else {
                $fallbackGenerated = true;
                $resolvedKey = $this->generateUniqueTranslatedExternalKey($sourceDraft, $proposedKey, $targetContent);
            }
        } elseif (! $conflictingContent instanceof Content) {
            $resolvedKey = $proposedKey;
        } else {
            $fallbackGenerated = true;
            $resolvedKey = $this->generateUniqueTranslatedExternalKey($sourceDraft, $proposedKey, $targetContent);
        }

        Log::info('translation.external_key_resolved', [
            'source_draft_id' => (string) $sourceDraft->id,
            'target_locale' => $targetLanguage->value,
            'target_content_id' => $targetContent?->id ? (string) $targetContent->id : null,
            'proposed_external_key' => $proposedKey,
            'resolved_external_key' => $resolvedKey,
            'conflict_detected' => $conflictingContent instanceof Content,
            'conflicting_content_id' => $conflictingContent?->id ? (string) $conflictingContent->id : null,
            'fallback_generated' => $fallbackGenerated,
        ]);

        return $resolvedKey;
    }

    private function resolveProviderFromModel(string $model): string
    {
        $model = strtolower($model);

        if (str_starts_with($model, 'gpt') || str_starts_with($model, 'o1') || str_starts_with($model, 'o3')) {
            return 'openai';
        }

        if (str_starts_with($model, 'claude')) {
            return 'anthropic';
        }

        if (str_starts_with($model, 'gemini')) {
            return 'gemini';
        }

        if (str_starts_with($model, 'mistral')) {
            return 'mistral';
        }

        return 'openai';
    }

    private function resolveSourceContentRoot(Draft $sourceDraft): ?Content
    {
        $originalSource = $sourceDraft->getOriginalSourceDraft() ?? $sourceDraft;
        $originalSource->loadMissing('content.familyRoot', 'content.translationSourceContent');

        return $originalSource->content?->localizationSource();
    }

    /**
     * @return array{content:?Content,strategy:string,adopted_repairable:bool}
     */
    private function resolveTranslationTargetSelection(
        Draft $sourceDraft,
        SupportedLanguage $targetLanguage,
        ?string $preferredRouteOrSlug = null,
        ?Content $preferredContent = null
    ): array {
        $sourceLocalizationRoot = $this->resolveSourceContentRoot($sourceDraft);
        $existingVariant = $this->resolveExistingVariantContent($sourceDraft, $targetLanguage);

        if ($existingVariant instanceof Content) {
            return $this->logResolvedTranslationTarget(
                $sourceDraft,
                $targetLanguage,
                $existingVariant,
                'exact_target_locale_variant',
                false
            );
        }

        if (! $sourceLocalizationRoot instanceof Content) {
            return $this->logResolvedTranslationTarget(
                $sourceDraft,
                $targetLanguage,
                null,
                'create_new_variant',
                false
            );
        }

        if (
            $preferredContent instanceof Content
            && $this->isResolvableTranslationTarget($preferredContent, $targetLanguage)
        ) {
            return $this->logResolvedTranslationTarget(
                $sourceDraft,
                $targetLanguage,
                $preferredContent->fresh(['currentVersion', 'drafts', 'brief']) ?? $preferredContent,
                'provided_target_variant',
                true
            );
        }

        $linkedSourceCandidate = $this->findRepairableTargetByLinkedSource(
            $sourceDraft,
            $targetLanguage,
            $sourceLocalizationRoot,
            $preferredRouteOrSlug
        );

        if ($linkedSourceCandidate instanceof Content) {
            return $this->logResolvedTranslationTarget(
                $sourceDraft,
                $targetLanguage,
                $linkedSourceCandidate,
                'repairable_linked_source_variant',
                true
            );
        }

        $routeCandidate = $this->findRepairableTargetByRouteOrSlug(
            $sourceDraft,
            $targetLanguage,
            $sourceLocalizationRoot,
            $preferredRouteOrSlug
        );

        if ($routeCandidate instanceof Content) {
            return $this->logResolvedTranslationTarget(
                $sourceDraft,
                $targetLanguage,
                $routeCandidate,
                'repairable_route_variant',
                true
            );
        }

        return $this->logResolvedTranslationTarget(
            $sourceDraft,
            $targetLanguage,
            null,
            'create_new_variant',
            false
        );
    }

    private function resolveExistingVariantContent(Draft $sourceDraft, SupportedLanguage $targetLanguage): ?Content
    {
        $sourceLocalizationRoot = $this->resolveSourceContentRoot($sourceDraft);
        if (! $sourceLocalizationRoot instanceof Content) {
            return null;
        }

        $sourceLocalizationRoot = $sourceLocalizationRoot->fresh([
            'translationSourceContent',
            'familyRoot',
            'localizedVariants.translationSourceContent',
            'localizedVariants.currentVersion',
            'localizedVariants.drafts',
        ]) ?? $sourceLocalizationRoot;

        return $sourceLocalizationRoot
            ->normalizedLocalizationFamily()
            ->first(function (Content $candidate) use ($sourceLocalizationRoot, $targetLanguage): bool {
                return (string) $candidate->id !== (string) $sourceLocalizationRoot->id
                    && $candidate->localeCode() === $targetLanguage->value
                    && (string) $candidate->status !== 'archived';
            });
    }

    private function findRepairableTargetByLinkedSource(
        Draft $sourceDraft,
        SupportedLanguage $targetLanguage,
        Content $sourceLocalizationRoot,
        ?string $preferredRouteOrSlug = null
    ): ?Content {
        $linkedSourceIds = collect([
            (string) $sourceLocalizationRoot->id,
            (string) ($sourceDraft->content_id ?? ''),
            (string) (($sourceDraft->getOriginalSourceDraft()?->content_id) ?? ''),
        ])->filter()->unique()->values()->all();

        if ($linkedSourceIds === []) {
            return null;
        }

        $candidates = Content::query()
            ->with(['currentVersion', 'drafts', 'brief'])
            ->where('client_site_id', $sourceDraft->client_site_id)
            ->where('language', $targetLanguage->value)
            ->where('status', '!=', 'archived')
            ->whereIn('translation_source_content_id', $linkedSourceIds)
            ->get();

        return $this->selectRepairableTargetCandidate(
            $candidates,
            $sourceLocalizationRoot,
            $preferredRouteOrSlug
        );
    }

    private function findRepairableTargetByRouteOrSlug(
        Draft $sourceDraft,
        SupportedLanguage $targetLanguage,
        Content $sourceLocalizationRoot,
        ?string $preferredRouteOrSlug = null
    ): ?Content {
        $routeOrSlugCandidates = collect([
            $preferredRouteOrSlug,
            $this->resolveRouteOrSlugForContent($sourceLocalizationRoot),
            $this->resolveRouteOrSlugForDraft($sourceDraft, $sourceDraft->content),
        ])->filter(fn ($value): bool => trim((string) $value) !== '')
            ->map(fn ($value): string => trim((string) $value))
            ->unique()
            ->values();

        if ($routeOrSlugCandidates->isEmpty()) {
            return null;
        }

        $familyIds = $sourceLocalizationRoot->localizationFamily()
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $candidates = Content::query()
            ->with(['currentVersion', 'drafts', 'brief'])
            ->where('client_site_id', $sourceDraft->client_site_id)
            ->where('language', $targetLanguage->value)
            ->where('status', '!=', 'archived')
            ->when($familyIds !== [], fn ($query) => $query->whereNotIn('id', $familyIds))
            ->get()
            ->filter(function (Content $candidate) use ($routeOrSlugCandidates): bool {
                $routeOrSlug = $this->resolveRouteOrSlugForContent($candidate);

                return $routeOrSlug !== null
                    && $routeOrSlugCandidates->contains($routeOrSlug);
            })
            ->values();

        return $this->selectRepairableTargetCandidate(
            $candidates,
            $sourceLocalizationRoot,
            $preferredRouteOrSlug
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int,Content>  $candidates
     */
    private function selectRepairableTargetCandidate(
        \Illuminate\Support\Collection $candidates,
        Content $sourceLocalizationRoot,
        ?string $preferredRouteOrSlug = null
    ): ?Content {
        return $candidates
            ->map(fn (Content $candidate): array => [
                'content' => $candidate,
                'inspection' => $this->describeContentTranslationCandidate($candidate, $sourceLocalizationRoot),
            ])
            ->filter(fn (array $candidate): bool => $this->isRepairableTranslationCandidate($candidate['inspection']))
            ->sortBy(function (array $candidate) use ($sourceLocalizationRoot, $preferredRouteOrSlug): array {
                /** @var Content $content */
                $content = $candidate['content'];
                $inspection = $candidate['inspection'];

                return [
                    (string) ($inspection['linked_source_content_id'] ?? '') === (string) $sourceLocalizationRoot->id ? 0 : 1,
                    $preferredRouteOrSlug !== null && (string) ($inspection['route_or_slug'] ?? '') === $preferredRouteOrSlug ? 0 : 1,
                    (bool) ($inspection['legacy_migration'] ?? false) ? 1 : 0,
                    $content->isPublishedForTranslation() ? 0 : 1,
                    $content->isDeliveredForTranslation() ? 0 : 1,
                    -1 * (int) ($content->updated_at?->timestamp ?? 0),
                    (string) $content->id,
                ];
            })
            ->map(fn (array $candidate): Content => $candidate['content'])
            ->first();
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function isRepairableTranslationCandidate(array $candidate): bool
    {
        return ! ((bool) ($candidate['blocking'] ?? false))
            && in_array((string) ($candidate['block_reason'] ?? ''), [
                'content_family_mismatch',
                'content_locale_mismatch',
                'legacy_locale_migration_record',
                'no_renderable_variant_state',
                'source_locale_record',
            ], true);
    }

    private function isResolvableTranslationTarget(Content $content, SupportedLanguage $targetLanguage): bool
    {
        return $content->localeCode() === $targetLanguage->value
            && (string) $content->status !== 'archived';
    }

    /**
     * @return array{content:?Content,strategy:string,adopted_repairable:bool}
     */
    private function logResolvedTranslationTarget(
        Draft $sourceDraft,
        SupportedLanguage $targetLanguage,
        ?Content $content,
        string $strategy,
        bool $adoptedRepairable
    ): array {
        Log::info('translation.target_resolution_selected', [
            'source_draft_id' => (string) $sourceDraft->id,
            'source_content_id' => $sourceDraft->content_id ? (string) $sourceDraft->content_id : null,
            'target_locale' => $targetLanguage->value,
            'strategy' => $strategy,
            'adopted_repairable_target' => $adoptedRepairable,
            'target_content_id' => $content?->id ? (string) $content->id : null,
            'target_linked_source_content_id' => $content?->translation_source_content_id ? (string) $content->translation_source_content_id : null,
            'target_route_or_slug' => $content ? $this->resolveRouteOrSlugForContent($content) : null,
        ]);

        return [
            'content' => $content,
            'strategy' => $strategy,
            'adopted_repairable' => $adoptedRepairable,
        ];
    }

    private function generateUniqueTranslatedExternalKey(
        Draft $sourceDraft,
        string $proposedKey,
        ?Content $targetContent = null
    ): string {
        for ($suffix = 2; $suffix <= 50; $suffix++) {
            $candidateKey = Str::slug($proposedKey . '-' . $suffix);

            $exists = Content::query()
                ->where('client_site_id', $sourceDraft->client_site_id)
                ->where('external_key', $candidateKey)
                ->when(
                    $targetContent instanceof Content,
                    fn ($query) => $query->whereKeyNot($targetContent->id)
                )
                ->exists();

            if (! $exists) {
                return $candidateKey;
            }
        }

        throw new RuntimeException('Unable to allocate a unique external key for the translated locale variant.');
    }

    /**
     * @param  array<string,mixed>  $translationResult
     * @param  array{title:string,original_title:string,was_shortened:bool,original_length:int,persisted_length:int,max_length:int}  $titleResult
     * @return array<string,mixed>
     */
    private function withSanitizedTitleMeta(array $translationResult, array $titleResult): array
    {
        $translationResult['title'] = $titleResult['title'];

        if ($titleResult['was_shortened']) {
            $meta = is_array($translationResult['meta'] ?? null) ? $translationResult['meta'] : [];
            $meta['original_title'] = $titleResult['original_title'];
            $meta['title_shortened'] = true;
            $translationResult['meta'] = $meta;
        }

        return $translationResult;
    }

    /**
     * @param  array{title:string,original_title:string,was_shortened:bool,original_length:int,persisted_length:int,max_length:int}  $titleResult
     * @param  array<string,mixed>  $context
     */
    private function logTitleShortening(string $event, array $titleResult, array $context): void
    {
        if (! $titleResult['was_shortened']) {
            return;
        }

        Log::notice($event, array_merge($context, [
            'original_length' => $titleResult['original_length'],
            'persisted_length' => $titleResult['persisted_length'],
            'max_length' => $titleResult['max_length'],
        ]));
    }
}
