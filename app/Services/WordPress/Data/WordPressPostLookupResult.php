<?php

namespace App\Services\WordPress\Data;

final class WordPressPostLookupResult
{
    public function __construct(
        public readonly bool $exists,
        public readonly ?WordPressPost $post = null,
        public readonly ?int $httpStatus = null,
    ) {}

    public function isMissing(): bool
    {
        return ! $this->exists && $this->httpStatus === 404;
    }

    /**
     * @return array{exists:bool,missing:bool,status:?int,wp_post_id:?string,published_url:?string,modified_ts:int,status_text:?string}
     */
    public function toArray(): array
    {
        return [
            'exists' => $this->exists,
            'missing' => $this->isMissing(),
            'status' => $this->httpStatus,
            'wp_post_id' => $this->post?->id,
            'published_url' => $this->post?->publishedUrl,
            'modified_ts' => $this->post?->modifiedTs ?? 0,
            'status_text' => $this->post?->status,
        ];
    }
}
