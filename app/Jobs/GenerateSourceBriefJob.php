<?php

namespace App\Jobs;

use App\Models\ContentSource;
use App\Services\SourceBriefing\ArticleContentExtractor;
use App\Services\SourceBriefing\ChainProposalGenerator;
use App\Services\SourceBriefing\SourceBasedBriefGenerator;
use App\Services\SourceBriefing\SourceContentAnalyzer;
use App\Services\SourceBriefing\Exceptions\SourceBriefingException;
use App\Services\SourceExtraction\SourceExtractionResult;
use App\Services\SourceExtraction\SourceUrlExtractor;
use App\Services\SourceBriefing\UrlSourceFetcher;
use App\Services\SourceBriefing\WorkspaceSourceContextBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GenerateSourceBriefJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    private const STAGE_STARTED = 'source_brief_job_started';

    private const STAGE_SOURCE_LOADED = 'source_loaded';

    private const STAGE_SOURCE_FETCHING = 'source_fetching';

    private const STAGE_SOURCE_EXTRACTING = 'source_extracting';

    private const STAGE_CONTEXT_BUILT = 'workspace_context_built';

    private const STAGE_ANALYSIS_STARTED = 'analysis_started';

    private const STAGE_ANALYSIS_COMPLETED = 'analysis_completed';

    private const STAGE_BRIEF_GENERATED = 'brief_generated';

    private const STAGE_JOB_COMPLETED = 'source_brief_job_completed';

    private const STAGE_JOB_FAILED = 'source_brief_job_failed';

    private const MIN_EXTRACTED_CHARS = 200;

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function __construct(
        public string $sourceId,
        public string $outputMode = 'brief_only'
    ) {}

    public function handle(
        UrlSourceFetcher $fetcher,
        ArticleContentExtractor $extractor,
        WorkspaceSourceContextBuilder $contextBuilder,
        SourceContentAnalyzer $analyzer,
        SourceBasedBriefGenerator $briefGenerator,
        ChainProposalGenerator $chainGenerator
    ): void {
        $currentStage = self::STAGE_STARTED;
        $lockKey = "source_brief_generation:{$this->sourceId}";

        $lock = Cache::lock($lockKey, 300);

        if (! $lock->get()) {
            Log::info('Source brief generation already running', [
                'source_id' => $this->sourceId,
            ]);

            return;
        }

        try {
            $this->logStage(self::STAGE_STARTED, ['source_id' => $this->sourceId]);

            $source = ContentSource::query()->find($this->sourceId);
            if (! $source) {
                throw new RuntimeException("ContentSource not found: {$this->sourceId}");
            }

            // Skip if already completed
            if ($source->isGenerationCompleted()) {
                Log::info('Source brief generation already completed, skipping', [
                    'source_id' => $this->sourceId,
                ]);

                return;
            }

            // Skip if not in a runnable state (e.g., running means another job picked it up)
            if ((string) $source->generation_status === ContentSource::GENERATION_STATUS_RUNNING) {
                Log::info('Source brief generation already running, skipping duplicate', [
                    'source_id' => $this->sourceId,
                ]);

                return;
            }

            $source->markGenerationRunning('initializing');
            $currentStage = self::STAGE_SOURCE_LOADED;

            $this->logStage(self::STAGE_SOURCE_LOADED, [
                'source_id' => $this->sourceId,
                'source_url' => $source->source_url,
                'extraction_status' => $source->extraction_status,
                'output_mode' => $this->outputMode,
            ]);

            if (trim((string) $source->extracted_text) === '' || (string) $source->extraction_status !== 'extracted') {
                $currentStage = self::STAGE_SOURCE_FETCHING;
                $source->markGenerationProgress('fetching_source');
                $mode = (string) data_get($source->metadata_json, 'fetch.requested_mode', 'default');
                $logContext = $this->sourceLogContext($source, $mode);
                $this->logStage(self::STAGE_SOURCE_FETCHING, $logContext);

                $manualNotes = trim((string) data_get($source->metadata_json, 'manual_source_notes', ''));
                if ($manualNotes !== '') {
                    $this->applyManualNotes($source, $manualNotes, $mode);
                } else {
                    $currentStage = self::STAGE_SOURCE_EXTRACTING;
                    $source->markGenerationProgress('extracting_source');
                    $this->logStage(self::STAGE_SOURCE_EXTRACTING, $logContext);

                    $result = app(SourceUrlExtractor::class)->extract((string) $source->source_url, $source->workspace()->first(), [
                        'mode' => $mode,
                        'use_cache' => true,
                        'min_text_chars' => self::MIN_EXTRACTED_CHARS,
                    ]);

                    if (! $result->success) {
                        throw new SourceBriefingException(
                            (string) $result->errorCode,
                            (string) $result->errorMessage,
                            (string) $result->errorMessage,
                        );
                    }

                    $this->applyExtractionResult($source, $result, $mode);
                }

                $source->refresh();
            }

            if ((string) $source->extraction_status === 'failed') {
                throw new SourceBriefingException(
                    'SOURCE_EXTRACTION_FAILED',
                    'Content extraction failed for this page. Try another public article URL.',
                );
            }

            $this->assertExtractedContentIsUsable(trim((string) $source->extracted_text));

            $workspace = $source->workspace()->firstOrFail();

            // Build workspace context
            $source->markGenerationProgress('building_workspace_context');
            $workspaceContext = $contextBuilder->build($workspace, null);
            $currentStage = self::STAGE_CONTEXT_BUILT;
            $this->logStage(self::STAGE_CONTEXT_BUILT, ['source_id' => $this->sourceId]);

            // Run analysis (includes LLM call)
            $currentStage = self::STAGE_ANALYSIS_STARTED;
            $source->markGenerationProgress('analyzing_source');
            $this->logStage(self::STAGE_ANALYSIS_STARTED, array_merge($this->sourceLogContext($source, (string) $source->generation_output_mode), [
                'extracted_length' => mb_strlen((string) $source->extracted_text),
                'estimated_tokens' => $this->estimateTokens((string) $source->extracted_text),
            ]));

            $analysis = $analyzer->analyze($source, $workspace, null);
            $currentStage = self::STAGE_ANALYSIS_COMPLETED;
            $this->logStage(self::STAGE_ANALYSIS_COMPLETED, array_merge($this->sourceLogContext($source, (string) $source->generation_output_mode), [
                'ai_provider' => data_get($analysis, '_debug.ai_provider'),
                'ai_model' => data_get($analysis, '_debug.ai_model'),
                'generation_duration_ms' => data_get($analysis, '_debug.generation_duration_ms'),
            ]));

            // Generate chain proposal if requested
            $chainProposal = null;
            if ($this->outputMode === 'brief_chain') {
                $source->markGenerationProgress('generating_chain');
                $chainProposal = $chainGenerator->generate($source, $analysis, $workspaceContext);
            }

            // Generate brief
            $source->markGenerationProgress('generating_brief');
            $generated = $briefGenerator->generate(
                $source,
                $analysis,
                $workspaceContext,
                $this->outputMode,
                $chainProposal
            );
            $currentStage = self::STAGE_BRIEF_GENERATED;
            $this->logStage(self::STAGE_BRIEF_GENERATED, ['source_id' => $this->sourceId]);

            // Mark completed
            $source->markGenerationCompleted($analysis, $generated);
            $currentStage = self::STAGE_JOB_COMPLETED;

            $this->logStage(self::STAGE_JOB_COMPLETED, [
                'source_id' => $this->sourceId,
                'working_title' => data_get($generated, 'brief.working_title'),
            ]);
        } catch (Throwable $exception) {
            if ($this->handleFailure($exception, $currentStage)) {
                return;
            }

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function handleFailure(Throwable $exception, string $stage): bool
    {
        $this->logStage(self::STAGE_JOB_FAILED, [
            'source_id' => $this->sourceId,
            'stage' => $stage,
            'error' => $exception->getMessage(),
            'exception_class' => $exception::class,
        ]);

        $source = ContentSource::query()->find($this->sourceId);
        if (! $source) {
            return false;
        }

        $code = $this->determineFailureCode($exception, $stage);
        $isSourceFetchFailure = $this->isSourceFetchFailure($exception, $stage)
            || in_array($code, ['SOURCE_FETCH_TIMEOUT', 'SOURCE_FETCH_BLOCKED', 'SOURCE_FETCH_UNAVAILABLE', 'SOURCE_FETCH_FAILED'], true);
        $isRetryable = $this->isRetryable($exception);
        $attemptsRemaining = $this->tries - $this->attempts();

        // URL fetch failures are customer-actionable. The controller may still present
        // timeout/blocking failures as a fallback extraction state for the create flow.
        $shouldMarkFailed = $isSourceFetchFailure || ! $isRetryable || $attemptsRemaining <= 0;

        if ($shouldMarkFailed) {
            $userMessage = $this->getUserFriendlyMessage($exception, $stage);

            $source->markGenerationFailed($code, $userMessage, [
                'stage' => $stage,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'exception_trace' => array_slice($exception->getTrace(), 0, 10),
                'attempts' => $this->attempts(),
                'attempts_remaining' => max(0, $attemptsRemaining),
                'retryable' => $isRetryable,
                'terminal' => $isSourceFetchFailure,
            ]);
        }

        return $isSourceFetchFailure || ($exception instanceof SourceBriefingException && ! $exception->retryable);
    }

    private function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof SourceBriefingException) {
            return $exception->retryable;
        }

        $message = strtolower($exception->getMessage());

        $retryablePatterns = [
            'timeout',
            'timed out',
            'connection reset',
            'connection refused',
            'temporarily unavailable',
            'rate limit',
            'too many requests',
            '502',
            '503',
            '504',
            'bad gateway',
            'service unavailable',
        ];

        foreach ($retryablePatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isSourceFetchFailure(Throwable $exception, string $stage): bool
    {
        if ($stage === self::STAGE_SOURCE_FETCHING) {
            return true;
        }

        return str_contains(strtolower($exception->getMessage()), 'failed to fetch url');
    }

    private function determineFailureCode(Throwable $exception, ?string $stage = null): string
    {
        if ($exception instanceof SourceBriefingException) {
            return $exception->failureCode;
        }

        $message = strtolower($exception->getMessage());

        if ($stage !== null && $this->isSourceFetchFailure($exception, $stage)) {
            if (str_contains($message, 'timeout') || str_contains($message, 'timed out') || str_contains($message, 'curl error 28')) {
                return 'SOURCE_FETCH_TIMEOUT';
            }

            if (str_contains($message, 'http 403') || str_contains($message, 'http 401')) {
                return 'SOURCE_FETCH_BLOCKED';
            }

            if (preg_match('/http\s+4\d\d/i', $exception->getMessage()) === 1) {
                return 'SOURCE_FETCH_UNAVAILABLE';
            }

            return 'SOURCE_FETCH_FAILED';
        }

        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return 'GENERATION_TIMEOUT';
        }

        if (str_contains($message, 'rate limit') || str_contains($message, 'too many requests')) {
            return 'RATE_LIMIT_EXCEEDED';
        }

        if (str_contains($message, 'extraction')) {
            return 'SOURCE_EXTRACTION_FAILED';
        }

        if (str_contains($message, 'not ready')) {
            return 'SOURCE_NOT_READY';
        }

        if (str_contains($message, 'not found')) {
            return 'SOURCE_NOT_FOUND';
        }

        return 'GENERATION_FAILED';
    }

    private function getUserFriendlyMessage(Throwable $exception, ?string $stage = null): string
    {
        if ($exception instanceof SourceBriefingException) {
            return $exception->userMessage;
        }

        $code = $this->determineFailureCode($exception, $stage);

        return match ($code) {
            'SOURCE_EXTRACTION_EMPTY' => 'We could not extract readable article content from this page. Try another source or retry extraction.',
            'SOURCE_EXTRACTION_TOO_SHORT' => 'The extracted page content is too short to generate a reliable brief. Try a fuller article URL.',
            'SOURCE_EXTRACTION_UNSUPPORTED_STRUCTURE' => 'The page uses a structure we could not extract reliably. Try another public article URL.',
            'SOURCE_PAGE_TOO_LARGE' => 'This page is too large to analyze safely. Try a cleaner article URL or paste the key source details manually.',
            'SOURCE_FETCH_TIMEOUT' => 'We could not fetch this URL within the request window. We are trying fallback extraction methods. You can leave this page and come back.',
            'SOURCE_FETCH_BLOCKED' => 'This source site blocked the fetch request. Use another public URL or create the brief manually.',
            'SOURCE_FETCH_UNAVAILABLE' => 'This source URL could not be reached. Check that the URL is public and try again.',
            'SOURCE_FETCH_FAILED' => 'We could not fetch this URL. Try again, use another public URL, or create the brief manually.',
            'GENERATION_TIMEOUT' => 'The generation process timed out. Please try again.',
            'RATE_LIMIT_EXCEEDED' => 'We are experiencing high demand. Please wait a moment and try again.',
            'SOURCE_EXTRACTION_FAILED' => 'Content extraction failed for this page. Try another public article URL.',
            'SOURCE_NOT_READY' => 'The source content is not ready for generation. Please analyze the URL again.',
            'SOURCE_NOT_FOUND' => 'The source could not be found. It may have been deleted.',
            default => 'An error occurred during brief generation. Please try again.',
        };
    }

    public function failed(Throwable $exception): void
    {
        Log::error('GenerateSourceBriefJob permanently failed', [
            'source_id' => $this->sourceId,
            'output_mode' => $this->outputMode,
            'error' => $exception->getMessage(),
            'exception_class' => $exception::class,
        ]);

        $source = ContentSource::query()->find($this->sourceId);
        if ($source && ! $source->isGenerationFailed()) {
            $source->markGenerationFailed(
                'GENERATION_FAILED',
                'An unexpected error occurred during brief generation. Please try again.',
                [
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'attempts' => $this->attempts(),
                ]
            );
        }
    }

    private function logStage(string $stage, array $context = []): void
    {
        Log::info("GenerateSourceBriefJob: {$stage}", array_merge([
            'job_stage' => $stage,
            'source_id' => $this->sourceId,
        ], $context));
    }

    private function assertExtractedContentIsUsable(string $plainText): void
    {
        if ($plainText === '') {
            throw new SourceBriefingException(
                'SOURCE_EXTRACTION_EMPTY',
                'We could not extract readable article content from this page. Try another source or retry extraction.',
            );
        }

        if (mb_strlen($plainText) < self::MIN_EXTRACTED_CHARS) {
            throw new SourceBriefingException(
                'SOURCE_EXTRACTION_TOO_SHORT',
                'The extracted page content is too short to generate a reliable brief. Try a fuller article URL.',
            );
        }
    }

    private function estimateTokens(string $value): int
    {
        return (int) max(1, ceil(mb_strlen($value) / 4));
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceLogContext(ContentSource $source, string $mode): array
    {
        return [
            'url' => (string) ($source->final_url ?: $source->source_url),
            'user_id' => $source->created_by_user_id,
            'workspace_id' => (string) $source->workspace_id,
            'mode' => $mode,
            'output_mode' => $this->outputMode,
        ];
    }

    private function applyManualNotes(ContentSource $source, string $manualNotes, string $mode): void
    {
        $plainText = trim($manualNotes);
        $this->assertExtractedContentIsUsable($plainText);

        $source->update([
            'source_title' => $source->source_title ?: 'Manual source notes',
            'source_language' => $source->source_language ?: 'en',
            'extraction_status' => 'extracted',
            'fetched_at' => now(),
            'metadata_json' => array_merge((array) $source->metadata_json, [
                'fetch' => [
                    'requested_mode' => $mode,
                    'manual_source_notes' => true,
                ],
                'extraction' => [
                    'summary' => mb_substr($plainText, 0, 280),
                    'word_count' => str_word_count($plainText),
                    'method' => 'manual_source_notes',
                    'extracted_characters' => mb_strlen($plainText),
                    'estimated_tokens' => $this->estimateTokens($plainText),
                ],
            ]),
            'extracted_text' => $plainText,
            'extracted_outline_json' => [
                'h1' => 'Manual source notes',
                'h2' => [],
                'h3' => [],
            ],
        ]);
    }

    private function applyExtractionResult(ContentSource $source, SourceExtractionResult $result, string $mode): void
    {
        $plainText = trim((string) $result->extractedText);
        $this->assertExtractedContentIsUsable($plainText);

        $source->update([
            'source_url' => $result->url,
            'final_url' => $result->finalUrl,
            'source_domain' => (string) parse_url((string) ($result->finalUrl ?: $result->url), PHP_URL_HOST),
            'source_title' => $result->title ?: $source->source_title,
            'source_language' => $result->language ?: $source->source_language,
            'extraction_status' => 'extracted',
            'fetched_at' => now(),
            'metadata_json' => array_merge((array) $source->metadata_json, [
                'fetch' => [
                    'final_url' => $result->finalUrl,
                    'requested_mode' => $mode,
                    'duration_ms' => $result->durationMs,
                ],
                'extraction' => [
                    'summary' => $result->summary,
                    'word_count' => $result->wordCount,
                    'publish_date' => $result->publishedAt,
                    'author' => $result->author,
                    'method' => $result->method,
                    'quality' => data_get($result->metadata, 'quality', []),
                    'extracted_characters' => $result->chars,
                    'estimated_tokens' => $result->estimatedTokens,
                    'metadata' => $result->metadata,
                ],
            ]),
            'extracted_text' => $plainText,
            'extracted_outline_json' => [
                'h1' => data_get($result->metadata, 'h1'),
                'h2' => data_get($result->metadata, 'outline.h2', []),
                'h3' => data_get($result->metadata, 'outline.h3', []),
            ],
        ]);
    }
}
