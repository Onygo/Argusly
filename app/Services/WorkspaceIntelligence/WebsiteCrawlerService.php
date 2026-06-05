<?php

namespace App\Services\WorkspaceIntelligence;

use App\Services\OnboardingScan\ContentExtractionService;
use App\Services\OnboardingScan\WebsiteCrawlerService as OnboardingWebsiteCrawlerService;

class WebsiteCrawlerService
{
    public function __construct(
        private readonly OnboardingWebsiteCrawlerService $crawler,
        private readonly ContentExtractionService $extractor,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchPageContent(string $url): array
    {
        $page = $this->crawler->fetchPage($url);

        if (! ($page['success'] ?? false) || empty($page['html'])) {
            return [
                'page' => $page,
                'text' => '',
                'metadata' => [],
                'extracted' => [],
            ];
        }

        $extracted = $this->extractor->extractFromHtml((string) $page['html'], $url);

        return [
            'page' => $page,
            'text' => $this->extractRelevantText([$extracted]),
            'metadata' => $this->extractMetadata([$extracted]),
            'extracted' => $extracted,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function crawlWebsite(string $url, int $maxPages = 5): array
    {
        $crawl = $this->crawler->crawl($url, $maxPages);

        $pagesToExtract = [];
        if (($crawl['homepage']['success'] ?? false) === true) {
            $pagesToExtract['homepage'] = $crawl['homepage'];
        }

        foreach (($crawl['internal_pages'] ?? []) as $pageUrl => $page) {
            $pagesToExtract[$pageUrl] = $page;
        }

        $extractedPages = $this->extractor->extract($pagesToExtract);

        return [
            'crawl' => $crawl,
            'pages' => $extractedPages,
            'combined_text' => $this->extractRelevantText($extractedPages),
            'metadata' => $this->extractMetadata($extractedPages),
        ];
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $pages
     */
    public function extractRelevantText(array $pages): string
    {
        return trim(collect($pages)
            ->map(function (array $page): string {
                $parts = array_filter([
                    trim((string) ($page['title'] ?? '')),
                    trim((string) ($page['meta_description'] ?? '')),
                    trim((string) ($page['main_content'] ?? '')),
                ]);

                return implode("\n\n", $parts);
            })
            ->filter()
            ->implode("\n\n---\n\n"));
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $pages
     * @return array<string, mixed>
     */
    public function extractMetadata(array $pages): array
    {
        return [
            'titles' => collect($pages)->pluck('title')->filter()->values()->all(),
            'descriptions' => collect($pages)->pluck('meta_description')->filter()->values()->all(),
            'headings' => collect($pages)->flatMap(fn (array $page) => $page['headings'] ?? [])->values()->all(),
            'urls' => collect($pages)->pluck('url')->filter()->values()->all(),
        ];
    }
}
