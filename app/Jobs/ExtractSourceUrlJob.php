<?php

namespace App\Jobs;

use App\Models\ContentSource;
use App\Services\SourceExtraction\SourceExtractionResult;
use App\Services\SourceExtraction\SourceUrlExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExtractSourceUrlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public string $sourceId) {}

    public function handle(SourceUrlExtractor $extractor): void
    {
        $source = ContentSource::query()->with('workspace')->find($this->sourceId);
        if (! $source instanceof ContentSource) {
            return;
        }

        $source->update(['extraction_status' => 'extracting']);

        $result = $extractor->extract((string) $source->source_url, $source->workspace, [
            'use_cache' => true,
            'direct_timeout_seconds' => (int) config('source_extraction.relaxed_timeout_seconds', 60),
            'relaxed_timeout_seconds' => (int) config('source_extraction.relaxed_timeout_seconds', 60),
        ]);

        $this->applyResult($source, $result);
    }

    private function applyResult(ContentSource $source, SourceExtractionResult $result): void
    {
        if (! $result->success) {
            $source->update([
                'extraction_status' => 'failed',
                'metadata_json' => array_merge((array) $source->metadata_json, [
                    'error' => $result->errorMessage,
                    'failure_code' => $result->errorCode,
                    'diagnostics' => $result->metadata,
                ]),
            ]);

            return;
        }

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
                    'requested_mode' => data_get($source->metadata_json, 'fetch.requested_mode', 'default'),
                    'final_url' => $result->finalUrl,
                    'duration_ms' => $result->durationMs,
                ],
                'extraction' => [
                    'summary' => $result->summary,
                    'word_count' => $result->wordCount,
                    'author' => $result->author,
                    'publish_date' => $result->publishedAt,
                    'method' => $result->method,
                    'extracted_characters' => $result->chars,
                    'estimated_tokens' => $result->estimatedTokens,
                    'metadata' => $result->metadata,
                ],
            ]),
            'extracted_text' => $result->extractedText,
            'extracted_outline_json' => [
                'h1' => data_get($result->metadata, 'h1'),
                'h2' => data_get($result->metadata, 'outline.h2', []),
                'h3' => data_get($result->metadata, 'outline.h3', []),
            ],
        ]);
    }
}
