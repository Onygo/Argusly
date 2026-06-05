<?php

namespace App\Services\SourceBriefing;

use App\Models\ClientSite;
use App\Models\ContentSource;
use App\Models\User;
use App\Models\Workspace;
use App\Jobs\ExtractSourceUrlJob;
use App\Services\SourceExtraction\SourceExtractionResult;
use App\Services\SourceExtraction\SourceUrlExtractor;
use App\Services\SourceBriefing\Exceptions\SourceBriefingException;
use App\Services\SourceBriefing\Exceptions\SourcePreviewException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SourceBriefingService
{
    public function __construct(
        private readonly UrlSourceFetcher $fetcher,
        private readonly ArticleContentExtractor $extractor,
        private readonly SourceContentAnalyzer $analyzer,
        private readonly SourceBasedBriefGenerator $briefGenerator,
        private readonly ChainProposalGenerator $chainGenerator,
        private readonly WorkspaceSourceContextBuilder $contextBuilder,
        private readonly SourceUrlExtractor $sourceUrlExtractor,
    ) {}

    public function preview(Workspace $workspace, string $url, User $user, string $mode = 'default'): ContentSource
    {
        $source = ContentSource::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'type' => 'url',
            'source_url' => trim($url),
            'extraction_status' => 'fetching',
            'created_by_user_id' => (int) $user->id,
        ]);

        try {
            $result = $this->sourceUrlExtractor->extract($url, $workspace, [
                'mode' => $mode,
                'use_cache' => true,
            ]);

            if (! $result->success && $this->shouldQueueFallback($result)) {
                $source->update([
                    'extraction_status' => 'pending',
                    'metadata_json' => [
                        'fetch' => ['requested_mode' => $mode],
                        'pending_message' => 'Extraction is still running. We are trying fallback methods.',
                        'diagnostics' => $result->metadata,
                    ],
                ]);

                ExtractSourceUrlJob::dispatch($source->id)->onQueue('generation');

                return $source->fresh();
            }

            if (! $result->success) {
                throw new SourceBriefingException(
                    (string) $result->errorCode,
                    (string) $result->errorMessage,
                    (string) $result->errorMessage,
                );
            }

            $this->applyExtractionResult($source, $result, $mode);
        } catch (\Throwable $exception) {
            $failureCode = $exception instanceof SourceBriefingException ? $exception->failureCode : 'SOURCE_PREVIEW_FAILED';
            $userMessage = $exception instanceof SourceBriefingException ? $exception->userMessage : $exception->getMessage();

            Log::warning('Source briefing preview failed', [
                'workspace_id' => (string) $workspace->id,
                'user_id' => (int) $user->id,
                'source_url' => $url,
                'failure_code' => $failureCode,
                'error' => $exception->getMessage(),
                'exception_class' => $exception::class,
                'mode' => $mode,
            ]);

            $source->update([
                'extraction_status' => 'failed',
                'metadata_json' => array_merge(is_array($source->metadata_json) ? $source->metadata_json : [], [
                    'error' => $userMessage,
                    'failure_code' => $failureCode,
                    'fetch' => [
                        'requested_mode' => $mode,
                    ],
                    'diagnostics' => [
                        'rejected_reason' => $exception->getMessage(),
                        'exception_class' => $exception::class,
                    ],
                ]),
            ]);

            throw new SourcePreviewException($userMessage, $source->fresh());
        }

        return $source->fresh();
    }

    public function applyExtractionResult(ContentSource $source, SourceExtractionResult $result, string $mode = 'default'): void
    {
        $source->update([
            'source_url' => $result->url,
            'final_url' => $result->finalUrl,
            'source_domain' => (string) parse_url((string) ($result->finalUrl ?: $result->url), PHP_URL_HOST),
            'source_title' => $result->title,
            'source_language' => $result->language,
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
            'extracted_text' => (string) $result->extractedText,
            'extracted_outline_json' => [
                'h1' => data_get($result->metadata, 'h1'),
                'h2' => data_get($result->metadata, 'outline.h2', []),
                'h3' => data_get($result->metadata, 'outline.h3', []),
            ],
        ]);
    }

    private function shouldQueueFallback(SourceExtractionResult $result): bool
    {
        if ((array) data_get($result->metadata, 'attempts', []) === []) {
            return false;
        }

        return in_array((string) $result->errorCode, [
            'SOURCE_FETCH_TIMEOUT',
            'SOURCE_FETCH_BLOCKED',
            'SOURCE_FETCH_UNAVAILABLE',
        ], true);
    }

    public function generate(ContentSource $source, Workspace $workspace, ?ClientSite $site, string $outputMode): ContentSource
    {
        $workspaceContext = $this->contextBuilder->build($workspace, $site);
        $analysis = $this->analyzer->analyze($source, $workspace, $site);
        $chainProposal = $outputMode === 'brief_chain'
            ? $this->chainGenerator->generate($source, $analysis, $workspaceContext)
            : null;
        $generated = $this->briefGenerator->generate($source, $analysis, $workspaceContext, $outputMode, $chainProposal);

        $source->update([
            'analysis_json' => $analysis,
            'generated_payload_json' => $generated,
            'extraction_status' => 'generated',
        ]);

        return $source->fresh();
    }
}
