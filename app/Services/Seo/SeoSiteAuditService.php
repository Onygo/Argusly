<?php

namespace App\Services\Seo;

use App\Models\Content;
use App\Services\PublicBlog\PublicBlogService;
use App\Services\Sitemap\SitemapGenerator;
use App\Support\LocalizedMarketingUrl;
use App\Support\MarketingRouteSegments;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SeoSiteAuditService
{
    public function __construct(
        private readonly CanonicalUrlService $canonicals,
        private readonly MarketingRouteSegments $segments,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function indexability(): array
    {
        $candidates = $this->publicCandidates();
        $sitemapUrls = $this->sitemapUrls();
        $robotsBlocked = $this->robotsDisallowPatterns();

        $missingCanonicals = [];
        $canonicalMismatches = [];
        $noindex = [];

        foreach ($candidates as $candidate) {
            if (! (bool) ($candidate['indexable'] ?? true)) {
                $noindex[] = $candidate;
                continue;
            }

            $canonical = trim((string) ($candidate['canonical'] ?? ''));
            if ($canonical === '') {
                $missingCanonicals[] = $candidate;
            } elseif (! $this->canonicals->equivalent((string) $candidate['url'], $canonical)) {
                $canonicalMismatches[] = $candidate + ['expected' => $candidate['url'], 'actual' => $canonical];
            }
        }

        return [
            'indexed_candidates' => $candidates->where('indexable', true)->values()->all(),
            'noindex_pages' => $noindex,
            'missing_canonicals' => $missingCanonicals,
            'canonical_mismatches' => $canonicalMismatches,
            'sitemap_missing_urls' => $candidates
                ->where('indexable', true)
                ->reject(fn (array $candidate): bool => in_array((string) $candidate['url'], $sitemapUrls, true))
                ->values()
                ->all(),
            'sitemap_non_200_urls' => $this->nonOkSitemapUrls($sitemapUrls),
            'robots_blocked_sitemap_urls' => collect($sitemapUrls)
                ->filter(fn (string $url): bool => $this->isBlockedByRobots($url, $robotsBlocked))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function canonicals(): array
    {
        $contents = Content::query()
            ->with(['localizedVariants', 'translationSourceContent'])
            ->where('type', 'article')
            ->where('status', 'published')
            ->where('publish_status', 'published')
            ->whereNotNull('publish_url_key')
            ->limit(1000)
            ->get();

        $rows = $contents->map(function (Content $content): array {
            $expected = $this->canonicals->expectedCanonicalForContent($content);

            return [
                'id' => (string) $content->id,
                'title' => (string) $content->title,
                'locale' => $content->localeCode(),
                'canonical' => $expected,
                'stored_canonical' => $this->canonicals->normalize((string) ($content->seo_canonical ?? '')),
                'family_id' => (string) ($content->family_id ?? $content->id),
                'hreflangs' => $this->hreflangsForContent($content),
            ];
        })->values();

        $duplicateCanonicals = $rows
            ->filter(fn (array $row): bool => is_string($row['canonical']) && $row['canonical'] !== '')
            ->groupBy('canonical')
            ->filter(fn ($group): bool => $group->count() > 1)
            ->map(fn ($group): array => $group->values()->all())
            ->values()
            ->all();

        return [
            'duplicate_canonical_urls' => $duplicateCanonicals,
            'self_canonical_missing' => $rows->filter(fn (array $row): bool => empty($row['canonical']))->values()->all(),
            'canonical_wrong_locale' => $rows->filter(fn (array $row): bool => is_string($row['canonical'])
                && $row['canonical'] !== ''
                && ! str_contains($row['canonical'], '/' . $row['locale'] . '/'))->values()->all(),
            'hreflang_missing_sibling_locale' => $this->missingSiblingHreflangs($rows->all()),
            'x_default_mismatch' => $rows->filter(function (array $row): bool {
                $xDefault = (string) data_get($row, 'hreflangs.x-default', '');
                $defaultLocale = (string) config('marketing_routing.default_locale', 'en');
                $expected = (string) data_get($row, 'hreflangs.' . $defaultLocale, '');

                return $xDefault !== '' && $expected !== '' && $xDefault !== $expected;
            })->values()->all(),
            'canonical_redirect_or_404' => $this->nonOkSitemapUrls($rows->pluck('canonical')->filter()->values()->all(), false),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    public function publicCandidates()
    {
        $static = collect((array) config('sitemap.static_routes', []))
            ->flatMap(function (string $route): array {
                if (! LocalizedMarketingUrl::supportsRoute($route)) {
                    return [];
                }

                return collect(LocalizedMarketingUrl::hreflangsForRoute($route))
                    ->map(fn (string $url, string $locale): array => [
                        'type' => 'static',
                        'locale' => $locale,
                        'url' => $url,
                        'canonical' => $url,
                        'indexable' => true,
                    ])
                    ->values()
                    ->all();
            });

        $articles = Content::query()
            ->where('type', 'article')
            ->whereNotNull('publish_url_key')
            ->limit(1000)
            ->get()
            ->map(function (Content $content): array {
                $canonical = $this->canonicals->expectedCanonicalForContent($content);

                return [
                    'type' => 'article',
                    'id' => (string) $content->id,
                    'locale' => $content->localeCode(),
                    'url' => $canonical,
                    'canonical' => $canonical,
                    'indexable' => (string) $content->status === 'published'
                        && (string) ($content->publish_status ?? '') === 'published'
                        && (bool) ($content->robots_index ?? true),
                ];
            })
            ->filter(fn (array $row): bool => is_string($row['url']) && $row['url'] !== '');

        return $static->merge($articles)->unique('url')->values();
    }

    /**
     * @return list<string>
     */
    public function sitemapUrls(): array
    {
        $generator = app(SitemapGenerator::class);
        $payload = $generator->generate('seo-audit-' . Str::random(8), true);
        $urls = [];

        foreach ($payload['children'] as $xml) {
            $parsed = @simplexml_load_string($xml);
            if (! $parsed) {
                continue;
            }

            foreach ($parsed->xpath('//*[local-name()="loc"]') ?: [] as $loc) {
                $urls[] = (string) $loc;
            }
        }

        return collect($urls)->unique()->values()->all();
    }

    /**
     * @param  list<string>  $urls
     * @return list<array{url:string,status:int|string}>
     */
    private function nonOkSitemapUrls(array $urls, bool $includeSkipped = true): array
    {
        if (app()->environment('testing')) {
            return [];
        }

        return collect($urls)
            ->take(100)
            ->map(function (string $url) use ($includeSkipped): ?array {
                try {
                    $response = Http::timeout(8)->withoutRedirecting()->get($url);
                    $status = $response->status();

                    return $status === 200 ? null : ['url' => $url, 'status' => $status];
                } catch (\Throwable $exception) {
                    return $includeSkipped ? ['url' => $url, 'status' => 'error'] : ['url' => $url, 'status' => 'error'];
                }
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function robotsDisallowPatterns(): array
    {
        return ['/admin', '/app', '/billing', '/login', '/register', '/preview', '/drafts', '/api'];
    }

    /**
     * @param  list<string>  $patterns
     */
    private function isBlockedByRobots(string $url, array $patterns): bool
    {
        $path = '/' . ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        foreach ($patterns as $pattern) {
            if (str_starts_with($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,string>
     */
    private function hreflangsForContent(Content $content): array
    {
        return $content->normalizedLocalizationFamily()
            ->filter(fn (Content $variant): bool => trim((string) $variant->publish_url_key) !== '')
            ->mapWithKeys(fn (Content $variant): array => [
                $variant->localeCode() => $this->canonicals->expectedCanonicalForContent($variant),
            ])
            ->filter()
            ->pipe(function ($hreflangs) {
                $default = (string) config('marketing_routing.default_locale', 'en');
                $xDefault = $hreflangs->get($default) ?: $hreflangs->first();

                return $xDefault ? $hreflangs->put('x-default', $xDefault) : $hreflangs;
            })
            ->all();
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function missingSiblingHreflangs(array $rows): array
    {
        return collect($rows)
            ->groupBy('family_id')
            ->flatMap(function ($family) {
                $locales = $family->pluck('locale')->unique()->values();
                if ($locales->count() < 2) {
                    return [];
                }

                return $family->filter(function (array $row) use ($locales): bool {
                    $hreflangs = array_keys((array) ($row['hreflangs'] ?? []));

                    return $locales->diff($hreflangs)->isNotEmpty();
                });
            })
            ->values()
            ->all();
    }
}
