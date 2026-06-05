<?php

namespace App\Services\Seo;

use App\Models\Content;
use App\Models\ContentIndexationHealth;
use App\Models\MarketingBlogRedirect;
use App\Services\Content\LocaleIntegrityValidationService;
use App\Services\Publication\ContentPublicationStateService;

class ContentIndexationHealthService
{
    public function __construct(
        private readonly CanonicalUrlService $canonicals,
        private readonly SitemapValidationService $sitemaps,
        private readonly LocaleIntegrityValidationService $localeIntegrity,
        private readonly ContentPublicationStateService $publicationState,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function evaluate(Content $content): array
    {
        $content->loadMissing(['indexationHealth', 'localizedVariants.currentVersion', 'localizedVariants.publications', 'publications', 'currentVersion']);

        $localeIntegrity = $this->localeIntegrity->validate($content);
        $sitemap = $this->sitemaps->validateContent($content);
        $health = $content->indexationHealth;

        $canonicalUrl = (string) ($sitemap['canonical_url'] ?? '');
        $redirectChain = $this->redirectChainForCanonical($canonicalUrl);
        $duplicateDetected = collect($localeIntegrity['issues'])->contains(fn (array $issue): bool => (string) $issue['code'] === 'duplicate_locale_content');
        $noindex = ! (bool) ($content->robots_index ?? true);
        $published = $this->publicationState->isPublished($content);
        $indexed = $health?->indexed;
        $canonicalAccepted = $health?->canonical_accepted;

        $issues = collect(array_merge(
            (array) ($sitemap['issues'] ?? []),
            $this->searchConsoleIssues($health)
        ))->values()->all();

        $score = 100;
        $score -= $published && ! $sitemap['included'] ? 20 : 0;
        $score -= $duplicateDetected ? 30 : 0;
        $score -= ($sitemap['redirect_issue'] ?? false) ? 20 : 0;
        $score -= $noindex ? 20 : 0;
        $score -= ($indexed === false) ? 25 : 0;
        $score -= ($canonicalAccepted === false) ? 20 : 0;
        $score = max(0, min(100, $score));

        return [
            'canonical_url' => $canonicalUrl !== '' ? $canonicalUrl : null,
            'google_selected_canonical' => $health?->google_selected_canonical,
            'indexed' => $indexed,
            'canonical_accepted' => $canonicalAccepted,
            'duplicate_detected' => $duplicateDetected || (bool) $health?->duplicate_detected,
            'redirect_issue' => (bool) ($sitemap['redirect_issue'] ?? false) || (bool) $health?->redirect_issue,
            'crawled_not_indexed' => (bool) $health?->crawled_not_indexed,
            'noindex_detected' => $noindex || (bool) $health?->noindex_detected,
            'sitemap_status' => $published
                ? (($sitemap['included'] ?? false) ? 'included' : 'excluded')
                : 'not_publishable',
            'last_checked_at' => $health?->last_checked_at,
            'health_score' => $score,
            'issues_json' => $issues,
            'discovered_urls_json' => array_values(array_unique(array_filter(array_merge(
                [$canonicalUrl],
                (array) ($health?->discovered_urls_json ?? [])
            )))),
            'hreflang_urls' => (array) ($localeIntegrity['alternates'] ?? []),
            'family_locales' => (array) ($localeIntegrity['family_locales'] ?? []),
            'published_locales' => (array) ($localeIntegrity['published_locales'] ?? []),
            'redirect_chain' => $redirectChain,
        ];
    }

    public function persist(Content $content, array $overrides = []): ContentIndexationHealth
    {
        $payload = array_merge($this->evaluate($content), $overrides);

        return ContentIndexationHealth::query()->updateOrCreate(
            ['content_id' => (string) $content->id],
            [
                'indexed' => $payload['indexed'],
                'canonical_accepted' => $payload['canonical_accepted'],
                'duplicate_detected' => (bool) $payload['duplicate_detected'],
                'redirect_issue' => (bool) $payload['redirect_issue'],
                'crawled_not_indexed' => (bool) $payload['crawled_not_indexed'],
                'noindex_detected' => (bool) $payload['noindex_detected'],
                'sitemap_status' => $payload['sitemap_status'],
                'last_checked_at' => now(),
                'health_score' => (int) $payload['health_score'],
                'canonical_url' => $payload['canonical_url'],
                'google_selected_canonical' => $payload['google_selected_canonical'],
                'issues_json' => $payload['issues_json'],
                'discovered_urls_json' => $payload['discovered_urls_json'],
            ]
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function syncSearchConsole(Content $content, array $payload): ContentIndexationHealth
    {
        return $this->persist($content, [
            'indexed' => data_get($payload, 'indexed'),
            'canonical_accepted' => data_get($payload, 'canonical_accepted'),
            'duplicate_detected' => (bool) data_get($payload, 'duplicate_detected', false),
            'redirect_issue' => (bool) data_get($payload, 'redirect_issue', false),
            'crawled_not_indexed' => (bool) data_get($payload, 'crawled_not_indexed', false),
            'noindex_detected' => (bool) data_get($payload, 'noindex_detected', false),
            'google_selected_canonical' => data_get($payload, 'google_selected_canonical'),
            'discovered_urls_json' => (array) data_get($payload, 'discovered_urls', []),
        ]);
    }

    /**
     * @return array<int,array{source_path:string,target_path:string,redirect_kind:string}>
     */
    private function redirectChainForCanonical(string $canonicalUrl): array
    {
        $path = trim((string) parse_url($canonicalUrl, PHP_URL_PATH));

        if ($path === '') {
            return [];
        }

        $current = $path;
        $visited = [];
        $chain = [];

        while ($current !== '' && ! in_array($current, $visited, true)) {
            $visited[] = $current;

            $redirect = MarketingBlogRedirect::query()
                ->active()
                ->where('source_path', $current)
                ->first();

            if (! $redirect) {
                break;
            }

            $chain[] = [
                'source_path' => (string) $redirect->source_path,
                'target_path' => (string) $redirect->target_path,
                'redirect_kind' => (string) $redirect->redirect_kind,
            ];

            $current = (string) $redirect->target_path;
        }

        return $chain;
    }

    /**
     * @return array<int,array{code:string,severity:string,message:string}>
     */
    private function searchConsoleIssues(?ContentIndexationHealth $health): array
    {
        if (! $health) {
            return [];
        }

        $issues = [];

        if ($health->indexed === false) {
            $issues[] = [
                'code' => 'not_indexed',
                'severity' => 'high',
                'message' => 'Google Search Console reports this page as not indexed.',
            ];
        }

        if ($health->canonical_accepted === false) {
            $issues[] = [
                'code' => 'google_ignored_canonical',
                'severity' => 'critical',
                'message' => 'Google selected a different canonical than the platform expected.',
            ];
        }

        return $issues;
    }
}
