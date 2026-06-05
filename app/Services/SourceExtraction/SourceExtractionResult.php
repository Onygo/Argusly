<?php

namespace App\Services\SourceExtraction;

class SourceExtractionResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $url,
        public readonly ?string $finalUrl = null,
        public readonly ?string $title = null,
        public readonly ?string $author = null,
        public readonly mixed $publishedAt = null,
        public readonly ?string $language = null,
        public readonly ?string $summary = null,
        public readonly ?string $extractedText = null,
        public readonly ?string $html = null,
        public readonly int $wordCount = 0,
        public readonly int $chars = 0,
        public readonly int $estimatedTokens = 0,
        public readonly ?string $method = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly int $durationMs = 0,
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'url' => $this->url,
            'final_url' => $this->finalUrl,
            'title' => $this->title,
            'author' => $this->author,
            'published_at' => $this->publishedAt,
            'language' => $this->language,
            'summary' => $this->summary,
            'extracted_text' => $this->extractedText,
            'html' => $this->html,
            'word_count' => $this->wordCount,
            'chars' => $this->chars,
            'estimated_tokens' => $this->estimatedTokens,
            'method' => $this->method,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'duration_ms' => $this->durationMs,
            'metadata' => $this->metadata,
        ];
    }
}
