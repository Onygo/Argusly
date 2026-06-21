<?php

namespace App\Data\Faq;

class FaqPageInput
{
    /**
     * @param  array<int,string>  $h2s
     * @param  array<int,array<string,string>|string>  $internalLinks
     */
    public function __construct(
        public readonly string $pageTitle,
        public readonly string $metaTitle,
        public readonly string $metaDescription,
        public readonly string $h1,
        public readonly array $h2s,
        public readonly string $content,
        public readonly array $internalLinks,
        public readonly string $sector,
        public readonly string $solutionType,
        public readonly string $pageType = 'resource',
        public readonly string $pageSlug = 'unknown',
        public readonly string $locale = 'en',
        public readonly ?string $workspaceId = null,
        public readonly ?string $siteId = null,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            pageTitle: trim((string) ($payload['page_title'] ?? $payload['pageTitle'] ?? '')),
            metaTitle: trim((string) ($payload['meta_title'] ?? $payload['metaTitle'] ?? '')),
            metaDescription: trim((string) ($payload['meta_description'] ?? $payload['metaDescription'] ?? '')),
            h1: trim((string) ($payload['h1'] ?? '')),
            h2s: array_values(array_filter(array_map('strval', (array) ($payload['h2s'] ?? [])))),
            content: trim((string) ($payload['content'] ?? '')),
            internalLinks: array_values((array) ($payload['internal_links'] ?? $payload['internalLinks'] ?? [])),
            sector: trim((string) ($payload['sector'] ?? '')),
            solutionType: trim((string) ($payload['solution_type'] ?? $payload['solutionType'] ?? '')),
            pageType: trim((string) ($payload['page_type'] ?? $payload['pageType'] ?? 'resource')) ?: 'resource',
            pageSlug: trim((string) ($payload['page_slug'] ?? $payload['pageSlug'] ?? 'unknown')) ?: 'unknown',
            locale: strtolower(trim((string) ($payload['locale'] ?? 'en'))) ?: 'en',
            workspaceId: trim((string) ($payload['workspace_id'] ?? $payload['workspaceId'] ?? '')) ?: null,
            siteId: trim((string) ($payload['site_id'] ?? $payload['siteId'] ?? $payload['client_site_id'] ?? $payload['clientSiteId'] ?? '')) ?: null,
        );
    }

    public function searchableText(): string
    {
        return mb_strtolower(implode(' ', [
            $this->pageTitle,
            $this->metaTitle,
            $this->metaDescription,
            $this->h1,
            implode(' ', $this->h2s),
            $this->content,
            $this->sector,
            $this->solutionType,
        ]));
    }
}
