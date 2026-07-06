<?php

namespace App\Services\PageIntelligence\Discovery;

use DateTimeInterface;

final class DiscoveredUrl
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $canonicalUrl = null,
        public readonly ?string $title = null,
        public readonly ?DateTimeInterface $publishedAt = null,
        public readonly int $priority = 50,
        public readonly ?string $pageType = null,
        public readonly array $metadata = [],
    ) {
    }

    public static function fromArray(array|string $value): self
    {
        if (is_string($value)) {
            return new self(url: $value);
        }

        return new self(
            url: (string) ($value['url'] ?? $value['loc'] ?? ''),
            canonicalUrl: isset($value['canonical_url']) ? (string) $value['canonical_url'] : null,
            title: isset($value['title']) ? (string) $value['title'] : null,
            publishedAt: isset($value['published_at']) && $value['published_at'] instanceof DateTimeInterface ? $value['published_at'] : null,
            priority: max(0, min(100, (int) ($value['priority'] ?? 50))),
            pageType: isset($value['page_type']) ? (string) $value['page_type'] : null,
            metadata: (array) ($value['metadata'] ?? []),
        );
    }
}
