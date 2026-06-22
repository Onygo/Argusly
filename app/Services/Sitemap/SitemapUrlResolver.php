<?php

namespace App\Services\Sitemap;

use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\ContentVersion;
use App\Services\Seo\CanonicalUrlService;
use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

class SitemapUrlResolver
{
    public function __construct(
        private readonly CanonicalUrlService $canonicals,
    ) {}

    /**
     * @return array{loc:string,lastmod:?string}|null
     */
    public function resolveArticle(Content $content, ?ContentVersion $version = null, ?ContentPublication $publication = null): ?array
    {
        $meta = is_array($version?->meta) ? $version->meta : [];

        $loc = $this->normalizeAbsoluteUrl($this->canonicals->liveUrlForContent($content, $this->firstNonEmpty([
            (string) ($content->seo_canonical ?? ''),
            (string) ($content->published_url ?? ''),
            (string) ($publication?->remote_url ?? ''),
            (string) data_get($meta, 'canonical_url', ''),
        ])));

        if ($loc === null) {
            return null;
        }

        return [
            'loc' => $loc,
            'lastmod' => $this->resolveLastModified($content, $version, $publication),
        ];
    }

    /**
     * @return array{loc:string,lastmod:?string}|null
     */
    public function resolveStaticRoute(string $routeName, array $parameters = [], ?CarbonInterface $lastmod = null): ?array
    {
        if (! Route::has($routeName)) {
            return null;
        }

        return [
            'loc' => route($routeName, $parameters),
            'lastmod' => $lastmod?->toAtomString(),
        ];
    }

    public function childSitemapUrl(string $name, ?string $locale = null): string
    {
        if (is_string($locale) && trim($locale) !== '') {
            return route('sitemaps.localized.show', [
                'locale' => $locale,
                'name' => $name,
            ]);
        }

        return route('sitemaps.show', ['name' => $name]);
    }

    private function resolveLastModified(
        Content $content,
        ?ContentVersion $version = null,
        ?ContentPublication $publication = null,
    ): ?string {
        $meta = is_array($version?->meta) ? $version->meta : [];

        $candidates = [
            $publication?->last_delivered_at,
            $content->updated_at,
            $version?->updated_at,
            $version?->created_at,
            data_get($meta, 'published_at'),
            data_get($meta, 'publish_at'),
            data_get($meta, 'date_published'),
            $content->created_at,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate instanceof CarbonInterface) {
                return $candidate->toAtomString();
            }

            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            try {
                return CarbonImmutable::parse($candidate)->toAtomString();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizeAbsoluteUrl(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '/')) {
            return url($value);
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);
        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($scheme) || ! is_string($host) || $scheme === '' || $host === '') {
            return null;
        }

        return $value;
    }
}
