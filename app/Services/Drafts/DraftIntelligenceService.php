<?php

namespace App\Services\Drafts;

use App\DTO\Drafts\DraftAnalysisDTO;
use App\Enums\DraftImprovementAction;
use App\Models\Draft;
use App\Models\DraftAnalysis;
use App\Services\DraftComparison\DraftComparisonScoringService;
use App\Services\Drafts\Exceptions\DraftImprovementException;
use App\Services\Drafts\Intelligence\DraftContentSnapshotBuilder;
use App\Services\Drafts\Intelligence\DraftImprovementPromptBuilder;
use App\Services\Drafts\Intelligence\DraftIntelligenceRubricRegistry;
use App\Services\Drafts\Intelligence\DraftIntelligenceScanService;
use App\Services\LinkIntelligence\DefaultLinkSuggestionService;
use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\Data\LlmResponse;
use App\Services\Llm\LlmJsonNormalizer;
use App\Services\Llm\LlmManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DraftIntelligenceService
{
    public const PROMPT_VERSION = 'draft-intelligence.v6';

    public const IMPROVEMENT_PROMPT_VERSION = 'draft-improvement.v2';

    public const FULL_IMPROVEMENT_PROMPT_VERSION = 'draft-improvement.full.v3';

    public function __construct(
        private readonly LlmManager $llmManager,
        private readonly DefaultLinkSuggestionService $linkSuggestionService,
        private readonly DraftComparisonScoringService $heuristics,
        private readonly DraftCtaScoringService $ctaScoring,
        private readonly DraftAnalysisResponseNormalizer $normalizer,
        private readonly DraftAnalysisCompletenessValidator $completenessValidator,
        private readonly DraftContentSnapshotBuilder $snapshotBuilder,
        private readonly DraftIntelligenceScanService $scanService,
        private readonly DraftImprovementPromptBuilder $improvementPromptBuilder,
        private readonly DraftIntelligenceRubricRegistry $rubricRegistry,
    ) {}

    public function analyze(Draft $draft, bool $force = false): DraftAnalysisDTO
    {
        $draft = $this->scanService->freshDraft($draft);

        $reusable = $force ? null : $this->findReusableAnalysis($draft);
        if ($reusable) {
            return DraftAnalysisDTO::fromModel($reusable);
        }

        try {
            return $this->analyzeWithLlm($draft);
        } catch (Throwable $exception) {
            report($exception);

            return $this->fallbackAnalysis($draft, $exception->getMessage());
        }
    }

    public function analyzeAndStore(Draft $draft, bool $force = false): DraftAnalysis
    {
        $draft = $this->scanService->freshDraft($draft);

        if (! $force) {
            $reusable = $this->findReusableAnalysis($draft);
            if ($reusable) {
                return $reusable;
            }
        }

        $analysis = $this->analyze($draft, $force);

        return DB::transaction(function () use ($draft, $analysis): DraftAnalysis {
            return DraftAnalysis::query()->create(array_merge(
                ['draft_id' => (string) $draft->id],
                $analysis->toModelAttributes(),
            ));
        });
    }

    public function hasFreshAnalysis(Draft $draft): bool
    {
        $draft = $this->scanService->freshDraft($draft);

        return $this->findReusableAnalysis($draft) !== null;
    }

    /**
     * @return array<string,mixed>
     */
    public function emptyNormalizedPayload(?Draft $draft = null): array
    {
        $payload = $this->normalizer->emptyCanonicalStructure();
        $payload['context'] = $draft
            ? array_merge($this->analysisContext($draft), ['rubric_version' => DraftIntelligenceRubricRegistry::VERSION])
            : ['prompt_version' => self::PROMPT_VERSION, 'rubric_version' => DraftIntelligenceRubricRegistry::VERSION];

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function improveSection(Draft $draft, string $section): array
    {
        $draft = $this->scanService->freshDraft($draft);

        $action = DraftImprovementAction::fromInput($section);
        if (! $action) {
            throw new RuntimeException('Unsupported draft improvement action: ' . $section);
        }

        $promptVersion = self::improvementPromptVersionForAction($action);
        $schema = $this->improvementSchema($action);
        $maxTokens = $this->initialImprovementMaxTokens($draft);
        $response = $this->dispatchImprovementRequest($draft, $action, $schema, $maxTokens);

        if ($this->responseWasCutOff($response)) {
            $retryMaxTokens = $this->retryImprovementMaxTokens($draft, $maxTokens);

            Log::warning('Draft improvement response was cut off before JSON parsing completed', [
                'draft_id' => (string) $draft->id,
                'action' => $action->value,
                'prompt_version' => $promptVersion,
                'provider' => $response->providerName,
                'model' => $response->modelUsed,
                'request_id' => $response->requestId,
                'max_tokens' => $maxTokens,
                'retry_max_tokens' => $retryMaxTokens,
                'incomplete_reason' => data_get($response->raw, 'incomplete_details.reason'),
                'response_preview' => Str::limit($response->text, 300),
            ]);

            if ($retryMaxTokens > $maxTokens) {
                $response = $this->dispatchImprovementRequest($draft, $action, $schema, $retryMaxTokens, [
                    'token_retry_reason' => 'max_output_tokens',
                    'previous_request_id' => $response->requestId,
                ]);
            }
        }

        $payload = $this->resolveImprovementPayload($draft, $action, $response);

        if ($payload === []) {
            $failureDiagnosis = $this->diagnosePayloadFailure($response);

            Log::warning('Draft improvement returned empty JSON payload', [
                'draft_id' => (string) $draft->id,
                'action' => $action->value,
                'prompt_version' => $promptVersion,
                'provider' => $response->providerName,
                'model' => $response->modelUsed,
                'request_id' => $response->requestId,
                'failure_stage' => $failureDiagnosis['stage'],
                'internal_reason' => $failureDiagnosis['reason'],
                'diagnosis_details' => $failureDiagnosis['details'],
                'response_preview' => Str::limit($response->text, 300),
            ]);

            throw $this->improvementFailure(
                action: $action,
                response: $response,
                message: 'Draft improvement returned no structured payload.',
                userMessage: $failureDiagnosis['user_message'],
                failureStage: $failureDiagnosis['stage'],
                internalReason: $failureDiagnosis['reason'],
            );
        }

        $validationErrors = $this->validateImprovementPayload($payload, $action);
        if ($validationErrors !== []) {
            Log::warning('Draft improvement payload failed schema validation', [
                'draft_id' => (string) $draft->id,
                'action' => $action->value,
                'prompt_version' => $promptVersion,
                'provider' => $response->providerName,
                'model' => $response->modelUsed,
                'request_id' => $response->requestId,
                'validation_errors' => $validationErrors,
                'payload_preview' => Str::limit(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', 500),
            ]);

            throw $this->improvementFailure(
                action: $action,
                response: $response,
                message: 'Draft improvement payload failed validation: ' . implode('; ', $validationErrors),
                userMessage: 'The AI returned an incomplete draft improvement. Please try again.',
                failureStage: 'schema_validation',
                internalReason: implode('; ', $validationErrors),
            );
        }

        $contentHtml = $this->normalizeContentHtml($payload['content_html'] ?? '');
        if ($contentHtml === '' || trim(strip_tags($contentHtml)) === '') {
            Log::warning('Draft improvement returned empty content_html', [
                'draft_id' => (string) $draft->id,
                'action' => $action->value,
                'prompt_version' => $promptVersion,
                'provider' => $response->providerName,
                'model' => $response->modelUsed,
                'request_id' => $response->requestId,
                'payload_preview' => Str::limit(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', 500),
            ]);

            throw $this->improvementFailure(
                action: $action,
                response: $response,
                message: 'Draft improvement returned empty content.',
                userMessage: 'The AI returned an empty draft improvement. Please try again.',
                failureStage: 'content_validation',
                internalReason: 'content_html_empty_after_normalization',
            );
        }

        $changeSummary = $this->nullableString($payload['change_summary'] ?? null);
        $changeNotes = $this->normalizeImprovementChangeNotes(
            $payload['change_notes'] ?? null,
            $action,
            $changeSummary,
        );

        if ($changeSummary === null && $changeNotes !== []) {
            $changeSummary = $this->summarizeChangeNotes($changeNotes);
        }

        if ($changeSummary === null) {
            Log::warning('Draft improvement returned no change_summary', [
                'draft_id' => (string) $draft->id,
                'action' => $action->value,
                'prompt_version' => $promptVersion,
                'provider' => $response->providerName,
                'model' => $response->modelUsed,
                'request_id' => $response->requestId,
                'payload_preview' => Str::limit(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', 500),
            ]);

            throw $this->improvementFailure(
                action: $action,
                response: $response,
                message: 'Draft improvement returned no change summary.',
                userMessage: 'The AI returned an incomplete draft improvement. Please try again.',
                failureStage: 'schema_validation',
                internalReason: 'change_summary_missing',
            );
        }

        return [
            'action' => $action->value,
            'section' => $action->value,
            'content_html' => $contentHtml,
            'title' => $this->nullableString($payload['title'] ?? null),
            'seo_title' => $this->nullableString(data_get($payload, 'seo.seo_title')),
            'seo_meta_description' => $this->nullableString(data_get($payload, 'seo.seo_meta_description')),
            'seo_h1' => $this->nullableString(data_get($payload, 'seo.seo_h1')),
            'change_summary' => $changeSummary,
            'change_notes' => $changeNotes,
            'model_used' => $response->modelUsed,
            'provider' => $response->providerName,
            'tokens_used' => (int) (($response->usage?->inputTokens ?? 0) + ($response->usage?->outputTokens ?? 0)),
            'request_id' => $response->requestId,
            'prompt_version' => $promptVersion,
        ];
    }

    public static function improvementPromptVersionForAction(DraftImprovementAction $action): string
    {
        return $action === DraftImprovementAction::FULL_DRAFT
            ? self::FULL_IMPROVEMENT_PROMPT_VERSION
            : self::IMPROVEMENT_PROMPT_VERSION;
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<string,mixed> $extraMetadata
     */
    private function dispatchImprovementRequest(
        Draft $draft,
        DraftImprovementAction $action,
        array $schema,
        int $maxTokens,
        array $extraMetadata = [],
    ): LlmResponse {
        $promptVersion = self::improvementPromptVersionForAction($action);

        $request = new LlmRequest(
            messages: [
                new LlmMessage('system', $this->improvementSystemPrompt($action)),
                new LlmMessage('user', $this->improvementUserPrompt($draft, $action)),
            ],
            model: $this->configuredImprovementModel(),
            temperature: $this->configuredImprovementTemperature(),
            maxTokens: $maxTokens,
            responseFormat: 'json',
            metadata: array_merge([
                'feature' => 'draft_intelligence_improvement',
                'modality' => 'text',
                'siteId' => (string) ($draft->client_site_id ?? ''),
                'workspaceId' => (string) ($draft->clientSite?->workspace_id ?? ''),
                'draftId' => (string) $draft->id,
                'action' => $action->value,
                'promptVersion' => $promptVersion,
                'llm_json_fix_retry_enabled' => true,
            ], $extraMetadata),
        );

        return $this->llmManager->generateJson($request, $schema);
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveImprovementPayload(
        Draft $draft,
        DraftImprovementAction $action,
        LlmResponse $response,
    ): array {
        $payload = is_array($response->json) ? $response->json : [];

        if ($payload !== []) {
            // Normalize to fill in missing optional fields that might be truncated
            return $this->normalizeImprovementPayloadDefaults($payload, $action);
        }

        // Try HTML-only fallback for responses that don't look like JSON
        $htmlFallback = $this->extractHtmlOnlyImprovementPayload($response->text, $action);
        if ($htmlFallback !== null) {
            Log::warning('Draft improvement used HTML-only fallback payload', [
                'draft_id' => (string) $draft->id,
                'action' => $action->value,
                'prompt_version' => self::improvementPromptVersionForAction($action),
                'provider' => $response->providerName,
                'model' => $response->modelUsed,
                'request_id' => $response->requestId,
                'response_preview' => Str::limit($response->text, 300),
            ]);

            return $htmlFallback;
        }

        // Try partial JSON extraction for truncated/malformed JSON responses
        $partialFallback = $this->extractPartialJsonImprovementPayload($response->text, $action);
        if ($partialFallback !== null) {
            Log::warning('Draft improvement used partial JSON extraction fallback', [
                'draft_id' => (string) $draft->id,
                'action' => $action->value,
                'prompt_version' => self::improvementPromptVersionForAction($action),
                'provider' => $response->providerName,
                'model' => $response->modelUsed,
                'request_id' => $response->requestId,
                'extraction_type' => $partialFallback['_extraction_type'] ?? 'unknown',
                'response_preview' => Str::limit($response->text, 300),
            ]);

            // Remove internal tracking key before returning
            unset($partialFallback['_extraction_type']);

            return $partialFallback;
        }

        return [];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function extractHtmlOnlyImprovementPayload(string $text, DraftImprovementAction $action): ?array
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            return null;
        }

        if (! str_starts_with($trimmed, '<')) {
            return null;
        }

        if (! preg_match('/<(p|h[1-6]|ul|ol|div|section|article)\b/i', $trimmed)) {
            return null;
        }

        return [
            'title' => null,
            'content_html' => $trimmed,
            'change_summary' => sprintf('%s update applied from HTML fallback.', $action->label()),
            'change_notes' => $this->defaultImprovementChangeNotes($action),
            'seo' => [
                'seo_title' => null,
                'seo_meta_description' => null,
                'seo_h1' => null,
            ],
        ];
    }

    /**
     * Extract improvement payload from partial/truncated JSON.
     * Used when the response looks like JSON but failed to parse completely.
     *
     * @return array<string,mixed>|null
     */
    private function extractPartialJsonImprovementPayload(string $text, DraftImprovementAction $action): ?array
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }

        // Only handle responses that look like JSON (start with { or [)
        if (! str_starts_with($trimmed, '{') && ! str_starts_with($trimmed, '[')) {
            return null;
        }

        // Use the normalizer's field extraction capability
        $normalizer = app(LlmJsonNormalizer::class);

        // Try to extract content_html - this is the critical field
        $contentHtml = $normalizer->extractFieldValue($trimmed, 'content_html');
        if ($contentHtml === null || trim($contentHtml) === '') {
            return null;
        }

        // Validate that the extracted content is substantial
        // Threshold of 30 chars balances rejecting tiny truncated fragments while
        // accepting reasonably complete short content
        $textLength = mb_strlen(strip_tags($contentHtml));
        if ($textLength < 30) {
            // Content too short - likely truncated mid-content
            return null;
        }

        // Validate that the extracted content looks like HTML (if it's long enough)
        if (! preg_match('/<(p|h[1-6]|ul|ol|div|section|article|a|span|strong|em)\b/i', $contentHtml)) {
            // Doesn't look like HTML at all - reject
            return null;
        }

        // Try to extract change_summary as well
        $changeSummary = $normalizer->extractFieldValue($trimmed, 'change_summary');

        // Try to extract optional fields
        $title = $normalizer->extractFieldValue($trimmed, 'title');
        $seoTitle = $normalizer->extractFieldValue($trimmed, 'seo_title');
        $seoMetaDescription = $normalizer->extractFieldValue($trimmed, 'seo_meta_description');
        $seoH1 = $normalizer->extractFieldValue($trimmed, 'seo_h1');

        $extractionType = $normalizer->isTruncatedJson($trimmed) ? 'truncated_json' : 'malformed_json';

        return [
            'title' => $title,
            'content_html' => $contentHtml,
            'change_summary' => $changeSummary ?? sprintf('%s update applied from partial JSON extraction.', $action->label()),
            'change_notes' => $this->defaultImprovementChangeNotes($action, $changeSummary),
            'seo' => [
                'seo_title' => $seoTitle,
                'seo_meta_description' => $seoMetaDescription,
                'seo_h1' => $seoH1,
            ],
            '_extraction_type' => $extractionType,
        ];
    }

    /**
     * Normalize the improvement payload by filling in missing optional fields.
     * This handles cases where truncated JSON recovery produced an incomplete payload.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalizeImprovementPayloadDefaults(array $payload, DraftImprovementAction $action): array
    {
        // Ensure title key exists
        if (! array_key_exists('title', $payload)) {
            $payload['title'] = null;
        }

        // Ensure change_summary key exists
        if (! array_key_exists('change_summary', $payload)) {
            $payload['change_summary'] = null;
        }

        if (! array_key_exists('change_notes', $payload)) {
            $payload['change_notes'] = $this->defaultImprovementChangeNotes(
                $action,
                is_string($payload['change_summary']) ? $payload['change_summary'] : null,
            );
        }

        // Normalize seo object with all required fields
        $seo = $payload['seo'] ?? [];
        if (! is_array($seo)) {
            $seo = [];
        }

        foreach (['seo_title', 'seo_meta_description', 'seo_h1'] as $key) {
            if (! array_key_exists($key, $seo)) {
                $seo[$key] = null;
            }
        }

        $payload['seo'] = $seo;

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private function validateImprovementPayload(array $payload, DraftImprovementAction $action): array
    {
        $errors = [];

        if (! array_key_exists('title', $payload)) {
            $errors[] = 'missing title key';
        }

        if (! array_key_exists('content_html', $payload)) {
            $errors[] = 'missing content_html key';
        } elseif (! is_string($payload['content_html'])) {
            $errors[] = 'content_html must be a string';
        }

        if (! array_key_exists('change_summary', $payload)) {
            $errors[] = 'missing change_summary key';
        } elseif (! is_string($payload['change_summary']) && ! is_null($payload['change_summary'])) {
            $errors[] = 'change_summary must be a string or null';
        }

        if (array_key_exists('change_notes', $payload) && ! is_array($payload['change_notes']) && ! is_null($payload['change_notes'])) {
            $errors[] = 'change_notes must be an array or null';
        }

        if ($action === DraftImprovementAction::FULL_DRAFT) {
            if (! array_key_exists('change_notes', $payload)) {
                $errors[] = 'missing change_notes key';
            } elseif (! is_array($payload['change_notes'])) {
                $errors[] = 'change_notes must be an array';
            } else {
                $validNotes = collect($payload['change_notes'])
                    ->filter(fn (mixed $note): bool => is_string($note) && trim($note) !== '')
                    ->values();

                if ($validNotes->isEmpty()) {
                    $errors[] = 'change_notes must contain at least one item';
                }
            }
        }

        $seo = $payload['seo'] ?? null;
        if (! is_array($seo)) {
            $errors[] = 'seo must be an object';

            return $errors;
        }

        foreach (['seo_title', 'seo_meta_description', 'seo_h1'] as $key) {
            if (! array_key_exists($key, $seo)) {
                $errors[] = sprintf('seo.%s is required', $key);
                continue;
            }

            if (! is_string($seo[$key]) && ! is_null($seo[$key])) {
                $errors[] = sprintf('seo.%s must be a string or null', $key);
            }
        }

        return $errors;
    }

    /**
     * @return array<int,string>
     */
    private function normalizeImprovementChangeNotes(
        mixed $value,
        DraftImprovementAction $action,
        ?string $changeSummary = null,
    ): array {
        $notes = collect(is_array($value) ? $value : [])
            ->map(static fn (mixed $note): string => trim((string) $note))
            ->filter(static fn (string $note): bool => $note !== '')
            ->unique()
            ->values()
            ->all();

        if ($notes !== []) {
            return $notes;
        }

        return $this->defaultImprovementChangeNotes($action, $changeSummary);
    }

    /**
     * @return array<int,string>
     */
    private function defaultImprovementChangeNotes(
        DraftImprovementAction $action,
        ?string $changeSummary = null,
    ): array {
        if ($changeSummary !== null && trim($changeSummary) !== '') {
            return [trim($changeSummary)];
        }

        return match ($action) {
            DraftImprovementAction::FULL_DRAFT => [
                'Improved the draft holistically across SEO, readability, headings, and CTA.',
            ],
            DraftImprovementAction::SEO => ['Improved SEO metadata and supporting copy.'],
            DraftImprovementAction::READABILITY => ['Improved readability and sentence flow.'],
            DraftImprovementAction::CTA => ['Added or strengthened the article CTA.'],
            DraftImprovementAction::HEADINGS => ['Refined heading clarity and hierarchy.'],
        };
    }

    /**
     * @param array<int,string> $changeNotes
     */
    private function summarizeChangeNotes(array $changeNotes): ?string
    {
        $summary = implode(' ', array_slice($changeNotes, 0, 3));

        return $this->nullableString(Str::limit($summary, 240, ''));
    }

    private function responseWasCutOff(LlmResponse $response): bool
    {
        return (string) data_get($response->raw, 'status') === 'incomplete'
            && (string) data_get($response->raw, 'incomplete_details.reason') === 'max_output_tokens';
    }

    /**
     * Diagnose why the payload extraction failed for better error messages.
     *
     * @return array{stage: string, reason: string, user_message: string, details: array<string,mixed>}
     */
    private function diagnosePayloadFailure(LlmResponse $response): array
    {
        $text = trim($response->text ?? '');

        // Case 1: Response was explicitly cut off by token limit
        if ($this->responseWasCutOff($response)) {
            return [
                'stage' => 'incomplete_response',
                'reason' => 'provider_response_incomplete:max_output_tokens',
                'user_message' => 'The AI response was cut off before the draft improvement completed. Please try again.',
                'details' => [
                    'incomplete_reason' => data_get($response->raw, 'incomplete_details.reason'),
                ],
            ];
        }

        // Case 2: Response is empty
        if ($text === '') {
            return [
                'stage' => 'parse',
                'reason' => 'empty_response',
                'user_message' => 'The AI returned an empty response. Please try again.',
                'details' => ['response_length' => 0],
            ];
        }

        $normalizer = app(LlmJsonNormalizer::class);

        // Case 3: Response looks like JSON but failed to parse
        if (str_starts_with($text, '{') || str_starts_with($text, '[')) {
            $isTruncated = $normalizer->isTruncatedJson($text);

            // Try to get more details about why parsing failed
            $diagnostics = $normalizer->decodeWithDiagnostics($text, $response->providerName);
            $decodeError = $diagnostics['error'] ?? 'unknown';

            if ($isTruncated) {
                // Truncated JSON without explicit max_output_tokens flag
                return [
                    'stage' => 'parse',
                    'reason' => 'json_truncated_without_explicit_flag',
                    'user_message' => 'The AI response appears to have been cut off mid-generation. Please try again.',
                    'details' => [
                        'is_truncated' => true,
                        'decode_error' => $decodeError,
                        'last_chars' => Str::limit(substr($text, -50), 50),
                    ],
                ];
            }

            // JSON parsing failed for other reasons
            return [
                'stage' => 'parse',
                'reason' => 'json_decode_failed:' . $decodeError,
                'user_message' => 'We could not process the AI response format. Please try again.',
                'details' => [
                    'decode_error' => $decodeError,
                    'starts_with_json' => true,
                    'response_length' => strlen($text),
                ],
            ];
        }

        // Case 4: Response doesn't look like JSON and isn't pure HTML
        if (! str_starts_with($text, '<')) {
            return [
                'stage' => 'parse',
                'reason' => 'non_json_non_html_response',
                'user_message' => 'The AI returned a response in an unexpected format. Please try again.',
                'details' => [
                    'starts_with' => Str::limit($text, 20),
                    'response_length' => strlen($text),
                ],
            ];
        }

        // Case 5: Response starts with < but didn't pass HTML validation
        return [
            'stage' => 'parse',
            'reason' => 'html_validation_failed',
            'user_message' => 'The AI returned HTML that could not be validated. Please try again.',
            'details' => [
                'starts_with' => Str::limit($text, 50),
                'response_length' => strlen($text),
            ],
        ];
    }

    private function initialImprovementMaxTokens(Draft $draft): int
    {
        $configured = (int) config('draft_intelligence.improvement_max_tokens', 0);
        if ($configured > 0) {
            return max(2200, $configured);
        }

        return max(2200, min(4800, (int) ceil(strlen((string) ($draft->content_html ?? '')) / 2)));
    }

    private function retryImprovementMaxTokens(Draft $draft, int $currentMaxTokens): int
    {
        $configured = (int) config('draft_intelligence.improvement_retry_max_tokens', 0);
        if ($configured > 0) {
            return max($currentMaxTokens, $configured);
        }

        return max($currentMaxTokens, min(6800, max($currentMaxTokens + 1400, (int) ceil(strlen((string) ($draft->content_html ?? '')) / 1.5))));
    }

    private function analyzeWithLlm(Draft $draft): DraftAnalysisDTO
    {
        $baseline = $this->scanService->buildDeterministicBaseline($draft);
        $draft = $baseline['draft'];
        $snapshot = $baseline['snapshot'];
        $signals = $baseline['signals'];
        $normalized = $baseline['payload'];

        $schema = $this->analysisSchema();
        $request = new LlmRequest(
            messages: [
                new LlmMessage('system', $this->analysisSystemPrompt()),
                new LlmMessage('user', json_encode(
                    $this->scanService->analysisPromptPayload($draft, $snapshot, $signals),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                ) ?: ''),
            ],
            model: $this->configuredAnalysisModel(),
            temperature: $this->configuredAnalysisTemperature(),
            maxTokens: $this->analysisMaxTokens($draft),
            responseFormat: 'json',
            metadata: [
                'feature' => 'draft_intelligence_analysis',
                'modality' => 'text',
                'siteId' => (string) ($draft->client_site_id ?? ''),
                'workspaceId' => (string) ($draft->clientSite?->workspace_id ?? ''),
                'draftId' => (string) $draft->id,
                'promptVersion' => self::PROMPT_VERSION,
                'random_seed' => $this->analysisSeed($snapshot),
            ],
        );

        $response = $this->llmManager->generateJson($request, [
            'name' => 'draft_intelligence_analysis',
            'description' => 'Structured analysis for one PublishLayer draft.',
            'schema' => $schema,
            'strict' => true,
        ]);

        // Capture raw text BEFORE any processing for debugging
        $rawResponseText = $response->text ?? '';

        // Log raw response for debugging when JSON is null or empty
        $rawPayload = is_array($response->json) ? $response->json : [];
        if (empty($rawPayload)) {
            Log::warning('Draft intelligence received empty/null JSON from LLM', [
                'draft_id' => (string) $draft->id,
                'raw_text_preview' => Str::limit($rawResponseText, 500),
                'model' => $response->modelUsed,
                'provider' => $response->providerName,
            ]);
        }

        $normalizationResult = $this->normalizer->normalize($rawPayload);
        $normalizedLlm = $normalizationResult['normalized'];
        $parserErrors = $normalizationResult['errors'];
        $normalized = $this->scanService->mergeWithLlm($normalized, $normalizedLlm, $snapshot, $signals);
        $normalized['context'] = array_merge((array) ($normalized['context'] ?? []), $this->analysisContext($draft, $snapshot), [
            'provider' => $response->providerName,
            'model' => $response->modelUsed,
            'request_id' => $response->requestId,
        ]);

        $seoScore = $this->normalizeScore(data_get($normalized, 'sections.seo.score'));
        $readabilityScore = $this->normalizeScore(data_get($normalized, 'sections.readability.score'));
        $ctaScore = $this->normalizeScore(data_get($normalized, 'sections.cta.score'));
        $headingsScore = $this->normalizeScore(data_get($normalized, 'sections.structure.score'));
        $llmVisibilityScore = $this->normalizeScore(data_get($normalized, 'sections.llm_visibility.score'));
        $brandVoiceFitScore = $this->normalizeScore(data_get($normalized, 'sections.brand_voice_fit.score'));
        $conversionFitScore = $this->normalizeScore(data_get($normalized, 'sections.conversion_fit.score'));
        $trustEvidenceScore = $this->normalizeScore(data_get($normalized, 'sections.trust_evidence.score'));
        $publishReadinessScore = $this->normalizeScore(data_get($normalized, 'sections.publish_readiness.score'));
        $keywordCoverage = $this->normalizeScore(data_get($normalized, 'keyword_coverage.score'));
        $entityCoverage = $this->normalizeScore(data_get($normalized, 'entity_coverage.score'));

        $validationResult = $this->completenessValidator->validate(
            $normalized,
            $seoScore,
            $readabilityScore,
            $ctaScore,
            $headingsScore,
            $llmVisibilityScore,
            $brandVoiceFitScore,
            $conversionFitScore,
            $trustEvidenceScore,
            $publishReadinessScore,
            $entityCoverage
        );

        $status = $validationResult['status'];
        $validationErrors = $validationResult['errors'];

        if ($status !== DraftAnalysis::STATUS_COMPLETED) {
            Log::warning('Draft intelligence analysis incomplete', [
                'draft_id' => (string) $draft->id,
                'status' => $status,
                'parser_errors' => $parserErrors,
                'validation_errors' => $validationErrors,
                'metrics' => $validationResult['metrics'],
                'raw_response_preview' => Str::limit($rawResponseText, 300),
            ]);
        }

        $metrics = $validationResult['metrics'];
        if ($status === DraftAnalysis::STATUS_FAILED && $metrics['sections_present'] === 0 && $metrics['sections_scored'] === 0) {
            throw new \RuntimeException(
                'Draft intelligence analysis produced no usable data. Raw response: ' . Str::limit($rawResponseText, 200)
            );
        }

        return new DraftAnalysisDTO(
            seoScore: $seoScore,
            readabilityScore: $readabilityScore,
            ctaScore: $ctaScore,
            headingsScore: $headingsScore,
            llmVisibilityScore: $llmVisibilityScore,
            brandVoiceFitScore: $brandVoiceFitScore,
            conversionFitScore: $conversionFitScore,
            trustEvidenceScore: $trustEvidenceScore,
            publishReadinessScore: $publishReadinessScore,
            publishReadinessStatus: (string) data_get($normalized, 'sections.publish_readiness.status_label', ''),
            publishReadinessBlockingIssues: (array) data_get($normalized, 'sections.publish_readiness.blocking_issues', []),
            publishReadinessNextActions: (array) data_get($normalized, 'sections.publish_readiness.recommended_next_actions', []),
            keywordCoverage: $keywordCoverage,
            entityCoverage: $entityCoverage,
            internalLinkOpportunities: (array) data_get($normalized, 'internal_link_opportunities', []),
            normalizedPayload: $normalized,
            signalsPayload: $signals,
            analysisModel: $response->modelUsed ?: $response->providerName,
            analysisProvider: $response->providerName,
            promptVersion: self::PROMPT_VERSION,
            snapshotSignature: (string) ($snapshot['snapshot_signature'] ?? ''),
            tokensUsed: (int) (($response->usage?->inputTokens ?? 0) + ($response->usage?->outputTokens ?? 0)),
            status: $status,
            rawResponse: $rawResponseText,
            parserErrors: $parserErrors,
            validationErrors: $validationErrors,
        );
    }

    private function fallbackAnalysis(Draft $draft, string $errorMessage): DraftAnalysisDTO
    {
        $baseline = $this->scanService->buildDeterministicBaseline($draft);
        $draft = $baseline['draft'];
        $snapshot = $baseline['snapshot'];
        $signals = $baseline['signals'];
        $normalized = $baseline['payload'];

        $normalized['summary'] = [
            'headline' => 'Deterministic draft intelligence baseline',
            'overall_explanation' => Str::limit($errorMessage, 240),
        ];
        $normalized['context'] = array_merge((array) ($normalized['context'] ?? []), $this->analysisContext($draft, $snapshot), [
            'provider' => 'deterministic',
            'model' => 'deterministic:phase4',
            'fallback_reason' => Str::limit($errorMessage, 240),
        ]);

        return new DraftAnalysisDTO(
            seoScore: $this->normalizeScore(data_get($normalized, 'sections.seo.score')),
            readabilityScore: $this->normalizeScore(data_get($normalized, 'sections.readability.score')),
            ctaScore: $this->normalizeScore(data_get($normalized, 'sections.cta.score')),
            headingsScore: $this->normalizeScore(data_get($normalized, 'sections.structure.score')),
            llmVisibilityScore: $this->normalizeScore(data_get($normalized, 'sections.llm_visibility.score')),
            brandVoiceFitScore: $this->normalizeScore(data_get($normalized, 'sections.brand_voice_fit.score')),
            conversionFitScore: $this->normalizeScore(data_get($normalized, 'sections.conversion_fit.score')),
            trustEvidenceScore: $this->normalizeScore(data_get($normalized, 'sections.trust_evidence.score')),
            publishReadinessScore: $this->normalizeScore(data_get($normalized, 'sections.publish_readiness.score')),
            publishReadinessStatus: (string) data_get($normalized, 'sections.publish_readiness.status_label', ''),
            publishReadinessBlockingIssues: (array) data_get($normalized, 'sections.publish_readiness.blocking_issues', []),
            publishReadinessNextActions: (array) data_get($normalized, 'sections.publish_readiness.recommended_next_actions', []),
            keywordCoverage: $this->normalizeScore(data_get($normalized, 'keyword_coverage.score')),
            entityCoverage: $this->normalizeScore(data_get($normalized, 'entity_coverage.score')),
            internalLinkOpportunities: (array) data_get($normalized, 'internal_link_opportunities', []),
            normalizedPayload: $normalized,
            signalsPayload: $signals,
            analysisModel: 'deterministic:phase4',
            analysisProvider: 'deterministic',
            promptVersion: self::PROMPT_VERSION,
            tokensUsed: 0,
            snapshotSignature: (string) ($snapshot['snapshot_signature'] ?? ''),
            status: DraftAnalysis::STATUS_COMPLETED,
            rawResponse: null,
            parserErrors: [],
            validationErrors: ['LLM analysis failed, using deterministic baseline: ' . Str::limit($errorMessage, 100)],
        );
    }

    private function findReusableAnalysis(Draft $draft): ?DraftAnalysis
    {
        $snapshot = $this->snapshotBuilder->build($draft);
        $snapshotSignature = (string) ($snapshot['snapshot_signature'] ?? '');

        return DraftAnalysis::query()
            ->where('draft_id', $draft->id)
            ->latest('created_at')
            ->get()
            ->first(function (DraftAnalysis $analysis) use ($snapshotSignature): bool {
                $storedSignature = trim((string) ($analysis->snapshot_signature ?: data_get($analysis->canonicalPayload(), 'context.snapshot_signature', '')));
                if ($storedSignature !== '') {
                    return hash_equals($storedSignature, $snapshotSignature);
                }

                $storedPromptVersion = (string) (data_get($analysis->canonicalPayload(), 'context.prompt_version') ?: $analysis->prompt_version ?: '');
                if ($storedPromptVersion !== self::PROMPT_VERSION) {
                    return false;
                }

                return (string) data_get($analysis->canonicalPayload(), 'context.snapshot_signature', '') === $snapshotSignature;
            });
    }

    /**
     * @return array<string,mixed>
     */
    private function analysisContext(Draft $draft, ?array $snapshot = null): array
    {
        $snapshot ??= $this->snapshotBuilder->build($draft);

        return [
            'content_hash' => $this->contentHash($draft),
            'analysis_signature' => $this->analysisSignature($draft, $snapshot),
            'snapshot_signature' => (string) ($snapshot['snapshot_signature'] ?? ''),
            'draft_updated_at' => $draft->updated_at?->toIso8601String(),
            'prompt_version' => self::PROMPT_VERSION,
            'rubric_version' => DraftIntelligenceRubricRegistry::VERSION,
        ];
    }

    private function analysisSignature(Draft $draft, ?array $snapshot = null): string
    {
        $snapshot ??= $this->snapshotBuilder->build($draft);

        return sha1(implode('|', [
            self::PROMPT_VERSION,
            (string) ($snapshot['snapshot_signature'] ?? ''),
            (string) ($draft->brief?->primary_keyword ?? ''),
            json_encode((array) ($draft->brief?->secondary_keywords ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
            (string) ($draft->brief?->call_to_action ?? ''),
            (string) ($draft->brief?->target_audience ?: $draft->brief?->audience ?: ''),
            (string) ($draft->brief?->funnel_stage ?? ''),
            (string) ($draft->brief?->search_intent ?? ''),
            (string) ($draft->brief?->content_type ?? ''),
            DraftIntelligenceRubricRegistry::VERSION,
        ]));
    }

    private function contentHash(Draft $draft): string
    {
        return sha1(implode('|', [
            (string) $draft->title,
            (string) $draft->seo_title,
            (string) $draft->seo_meta_description,
            (string) $draft->content_html,
        ]));
    }

    private function configuredAnalysisModel(): ?string
    {
        $model = trim((string) config('draft_intelligence.analysis_model', ''));

        return $model !== '' ? $model : null;
    }

    private function configuredAnalysisTemperature(): float
    {
        return (float) config('draft_intelligence.analysis_temperature', 0.0);
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function analysisSeed(array $snapshot): int
    {
        return hexdec(substr((string) ($snapshot['snapshot_signature'] ?? sha1('draft-intelligence')), 0, 7));
    }

    private function configuredImprovementModel(): ?string
    {
        $model = trim((string) config('draft_intelligence.improvement_model', ''));

        return $model !== '' ? $model : null;
    }

    private function configuredImprovementTemperature(): float
    {
        return (float) config('draft_intelligence.improvement_temperature', 0.1);
    }

    private function analysisSystemPrompt(): string
    {
        return <<<'PROMPT'
You are PublishLayer Draft Intelligence. Analyze one draft and return ONLY valid JSON.

CRITICAL RULES:
1. Return ONLY a JSON object - no markdown, no prose, no code fences
2. Include ALL required sections even if content is minimal
3. Every section MUST have: score (0-100 integer or null), explanation (1-2 concise sentences), improvements (1-3 actionable items)
4. Use the provided deterministic signals and shared rubrics as the primary scoring baseline
5. Keep any score calibration bounded and conservative; do not swing scores sharply without clear evidence
6. Never return empty explanations - always provide at least one sentence of context
7. internal_link_opportunities must be an array of objects, each with: target_title, reason, anchor_text, placement
8. If no internal links are appropriate, return an empty array and explain why in internal_link_summary
9. top_improvements must contain exactly 3 ranked, actionable recommendations
10. Score the CTA against the funnel stage and audience. Awareness and consideration content should not be penalized for using a softer CTA when the next step is clear and relevant.
11. Keep score explanations aligned with the numeric band and the deterministic evidence.
12. Score LLM Visibility based on how easy the draft is for AI systems to extract, summarize, and cite accurately. Use plain language such as "clear answer structure" and "summary-ready passages".
13. Score Brand Voice Fit based on the available brand guidance. If brand guidance is limited, degrade gracefully and explain that the score uses the available audience and tone signals.
14. Score Conversion Fit as broader than the CTA alone. Consider whether the article supports the intended next step and funnel stage, not just whether a CTA exists.
15. Score Trust and Evidence based on concrete framing, measured claims, examples, and unsupported hype avoidance.
16. For Publish Readiness, identify blocking issues explicitly and return a status label that matches the score.

REQUIRED STRUCTURE:
{
  "summary": { "headline": "string", "overall_explanation": "string" },
  "sections": {
    "seo": { "score": 0-100, "explanation": "string", "improvements": ["string"] },
    "readability": { "score": 0-100, "explanation": "string", "improvements": ["string"] },
    "cta": { "score": 0-100, "explanation": "string", "improvements": ["string"] },
    "structure": { "score": 0-100, "explanation": "string", "improvements": ["string"] },
    "llm_visibility": { "score": 0-100, "explanation": "string", "improvements": ["string"] },
    "brand_voice_fit": { "score": 0-100, "explanation": "string", "improvements": ["string"] },
    "conversion_fit": { "score": 0-100, "explanation": "string", "improvements": ["string"] },
    "trust_evidence": { "score": 0-100, "explanation": "string", "improvements": ["string"] },
    "publish_readiness": { "score": 0-100, "explanation": "string", "improvements": ["string"], "status_label": "string", "blocking_issues": ["string"], "recommended_next_actions": ["string"] },
    "entities": { "score": 0-100, "explanation": "string", "improvements": ["string"] }
  },
  "keyword_coverage": { "score": 0-100, "covered_terms": [], "missing_terms": [], "explanation": "string" },
  "entity_coverage": { "score": 0-100, "detected_entities": [], "missing_entities": [], "explanation": "string" },
  "internal_link_summary": "string",
  "internal_link_opportunities": [{ "target_title": "", "reason": "", "anchor_text": "", "placement": "" }],
  "top_improvements": ["string"]
}

CTA SCORE BANDS:
- 0-20: no real CTA
- 21-40: vague or weak CTA
- 41-60: present but generic CTA
- 61-80: clear, relevant, actionable CTA
- 81-100: highly compelling, specific, and well matched CTA

Keep output compact. Use the rubrics consistently across scan and improvement recommendations. Do not add extra nested analysis beyond the required structure.
PROMPT;
    }

    private function analysisUserPrompt(Draft $draft): string
    {
        $baseline = $this->scanService->buildDeterministicBaseline($draft);

        return json_encode(
            $this->scanService->analysisPromptPayload($baseline['draft'], $baseline['snapshot'], $baseline['signals']),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ) ?: '';
    }

    private function improvementSystemPrompt(DraftImprovementAction $action): string
    {
        return $this->improvementPromptBuilder->systemPrompt($action);
    }

    private function improvementUserPrompt(Draft $draft, DraftImprovementAction $action): string
    {
        return $this->improvementPromptBuilder->userPrompt($draft, $action);
    }

    /**
     * @return array<string,mixed>
     */
    private function analysisSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'sections', 'keyword_coverage', 'entity_coverage', 'internal_link_summary', 'internal_link_opportunities', 'top_improvements'],
            'properties' => [
                'summary' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['headline', 'overall_explanation'],
                    'properties' => [
                        'headline' => ['type' => 'string'],
                        'overall_explanation' => ['type' => 'string'],
                    ],
                ],
                'sections' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['seo', 'readability', 'cta', 'structure', 'llm_visibility', 'brand_voice_fit', 'conversion_fit', 'trust_evidence', 'publish_readiness', 'entities'],
                    'properties' => array_merge(
                        collect(['seo', 'readability', 'cta', 'structure', 'llm_visibility', 'brand_voice_fit', 'conversion_fit', 'trust_evidence', 'entities'])
                            ->mapWithKeys(fn (string $key): array => [$key => $this->sectionSchema()])
                            ->all(),
                        ['publish_readiness' => $this->publishReadinessSectionSchema()]
                    ),
                ],
                'keyword_coverage' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['score', 'covered_terms', 'missing_terms', 'explanation'],
                    'properties' => [
                        'score' => ['type' => ['integer', 'null']],
                        'covered_terms' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'missing_terms' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'explanation' => ['type' => 'string'],
                    ],
                ],
                'entity_coverage' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['score', 'detected_entities', 'missing_entities', 'explanation'],
                    'properties' => [
                        'score' => ['type' => ['integer', 'null']],
                        'detected_entities' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'missing_entities' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'explanation' => ['type' => 'string'],
                    ],
                ],
                'internal_link_summary' => [
                    'type' => 'string',
                ],
                'internal_link_opportunities' => [
                    'type' => 'array',
                    'maxItems' => 3,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['target_title', 'reason', 'anchor_text', 'placement'],
                        'properties' => [
                            'target_title' => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                            'anchor_text' => ['type' => 'string'],
                            'placement' => ['type' => 'string'],
                        ],
                    ],
                ],
                'top_improvements' => [
                    'type' => 'array',
                    'minItems' => 3,
                    'maxItems' => 3,
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function improvementSchema(DraftImprovementAction $action): array
    {
        $required = ['title', 'content_html', 'change_summary', 'change_notes', 'seo'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $required,
            'properties' => [
                'title' => ['type' => ['string', 'null']],
                'content_html' => ['type' => 'string'],
                'change_summary' => ['type' => ['string', 'null']],
                'change_notes' => [
                    'type' => ['array', 'null'],
                    'items' => ['type' => 'string'],
                    'minItems' => 1,
                    'maxItems' => 6,
                ],
                'seo' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['seo_title', 'seo_meta_description', 'seo_h1'],
                    'properties' => [
                        'seo_title' => ['type' => ['string', 'null']],
                        'seo_meta_description' => ['type' => ['string', 'null']],
                        'seo_h1' => ['type' => ['string', 'null']],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sectionSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['score', 'explanation', 'improvements'],
            'properties' => [
                'score' => ['type' => ['integer', 'null']],
                'explanation' => ['type' => 'string'],
                'improvements' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 3, 'items' => ['type' => 'string']],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function publishReadinessSectionSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['score', 'explanation', 'improvements', 'status_label', 'blocking_issues', 'recommended_next_actions'],
            'properties' => [
                'score' => ['type' => ['integer', 'null']],
                'explanation' => ['type' => 'string'],
                'improvements' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 4, 'items' => ['type' => 'string']],
                'status_label' => ['type' => 'string'],
                'blocking_issues' => ['type' => 'array', 'items' => ['type' => 'string']],
                'recommended_next_actions' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    private function analysisMaxTokens(Draft $draft): int
    {
        $plainTextLength = mb_strlen($this->plainText($draft));

        return $plainTextLength > 7000 ? 3200 : 2600;
    }

    /**
     * @param array<string,mixed> $normalized
     * @return array<string,mixed>
     */
    private function applyCtaCalibration(Draft $draft, array $normalized): array
    {
        $ctaAssessment = $this->ctaScoring->evaluateDraft($draft);

        data_set($normalized, 'sections.cta.score', (int) ($ctaAssessment['score'] ?? 0));
        data_set($normalized, 'sections.cta.explanation', (string) ($ctaAssessment['explanation'] ?? ''));
        data_set($normalized, 'sections.cta.improvements', array_values(array_unique(array_filter(
            (array) ($ctaAssessment['improvements'] ?? []),
            static fn (mixed $item): bool => trim((string) $item) !== '',
        ))));
        data_set($normalized, 'context.cta_score_band', (string) ($ctaAssessment['band_label'] ?? ''));
        data_set($normalized, 'context.cta_excerpt', $ctaAssessment['cta_excerpt'] ?? null);
        data_set($normalized, 'context.cta_funnel_stage', (string) ($ctaAssessment['funnel_stage'] ?? 'consideration'));
        data_set($normalized, 'context.cta_signals', $ctaAssessment['signals'] ?? []);

        return $normalized;
    }

    private function analysisPlainTextExcerpt(Draft $draft, int $maxCharacters = 9000): string
    {
        $plainText = $this->plainText($draft);
        if (mb_strlen($plainText) <= $maxCharacters) {
            return $plainText;
        }

        $headCharacters = (int) floor($maxCharacters * 0.62);
        $tailCharacters = max(1200, $maxCharacters - $headCharacters - 48);
        $head = trim(mb_substr($plainText, 0, $headCharacters));
        $tail = trim(mb_substr($plainText, -1 * $tailCharacters));

        return trim($head . "\n\n[...middle omitted for brevity...]\n\n" . $tail);
    }

    private function analysisClosingExcerpt(Draft $draft, int $maxCharacters = 2200): string
    {
        $plainText = $this->plainText($draft);
        if (mb_strlen($plainText) <= $maxCharacters) {
            return $plainText;
        }

        return trim(mb_substr($plainText, -1 * $maxCharacters));
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>
     */
    private function normalizeSectionPayload(mixed $value): array
    {
        return [
            'score' => $this->normalizeScore(data_get($value, 'score')),
            'explanation' => $this->nullableString(data_get($value, 'explanation')),
            'improvements' => $this->normalizeStringList(data_get($value, 'improvements', [])),
        ];
    }

    /**
     * @param mixed $value
     * @return array<int,array<string,mixed>>
     */
    private function normalizeLinkOpportunities(mixed $value): array
    {
        return collect((array) $value)
            ->map(function (mixed $item): array {
                return [
                    'target_title' => $this->nullableString(data_get($item, 'target_title')),
                    'reason' => $this->nullableString(data_get($item, 'reason')),
                    'anchor_text' => $this->nullableString(data_get($item, 'anchor_text')),
                    'placement' => $this->nullableString(data_get($item, 'placement')),
                ];
            })
            ->filter(fn (array $item): bool => trim((string) ($item['target_title'] ?? '')) !== '')
            ->values()
            ->all();
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function normalizeStringList(mixed $value): array
    {
        return collect((array) $value)
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->values()
            ->all();
    }

    private function normalizeScore(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) round((float) $value)));
    }

    private function nullableString(mixed $value): ?string
    {
        $string = (string) $value;
        $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $string) ?? $string;
        $string = trim(preg_replace('/\s+/u', ' ', $string) ?? $string);

        return $string !== '' ? $string : null;
    }

    private function normalizeContentHtml(mixed $value): string
    {
        $html = (string) $value;
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        $html = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $html) ?? $html;

        return trim($html);
    }

    private function improvementFailure(
        DraftImprovementAction $action,
        LlmResponse $response,
        string $message,
        string $userMessage,
        ?string $failureStage = null,
        ?string $internalReason = null,
    ): DraftImprovementException {
        return new DraftImprovementException(
            message: $message,
            action: $action->value,
            provider: $response->providerName,
            model: $response->modelUsed,
            requestId: $response->requestId,
            userMessage: $userMessage,
            responsePreview: Str::limit($response->text, 300),
            failureStage: $failureStage,
            internalReason: $internalReason,
        );
    }

    private function plainText(Draft $draft): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) ($draft->content_html ?? ''))));
    }

    /**
     * @return array<int,string>
     */
    private function headings(Draft $draft): array
    {
        preg_match_all('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', (string) ($draft->content_html ?? ''), $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $heading): string => trim(strip_tags($heading)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function expectedEntities(Draft $draft): array
    {
        $brief = $draft->brief;

        return collect([
            $brief?->primary_keyword,
            ...((array) ($brief?->secondary_keywords ?? [])),
            ...((array) ($brief?->key_points ?? [])),
            $brief?->unique_angle,
        ])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->take(20)
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function internalLinkCandidates(Draft $draft): array
    {
        try {
            return $this->linkSuggestionService
                ->debugCandidates($draft)
                ->take(3)
                ->map(fn (array $item): array => Arr::only($item, [
                    'target_title',
                    'target_site_url',
                    'accepted',
                    'reasons',
                    'shared_entities',
                    'similarity_score',
                ]))
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
