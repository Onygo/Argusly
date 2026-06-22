<?php

namespace App\Services\Marketing;

use App\Models\MarketingBlogRedirect;
use App\Support\LocalizedMarketingUrl;
use App\Support\MarketingRouteSegments;

class MarketingBlogRedirectService
{
    public function __construct(
        private readonly MarketingRouteSegments $segments,
    ) {}

    public function blogPath(string $locale, string $slug): string
    {
        return LocalizedMarketingUrl::route('public.blog.show', ['slug' => $slug], $locale, false);
    }

    public function blogUrl(string $locale, string $slug): string
    {
        return url($this->blogPath($locale, $slug));
    }

    public function resolveBlogRouteLocale(string $url, string $slug): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            return null;
        }

        foreach ($this->segments->locales() as $locale) {
            if ($path === $this->blogPath($locale, $slug)) {
                return $locale;
            }
        }

        if ($path === '/' . trim($this->segments->segment('blog', $this->segments->defaultLocale()), '/') . '/' . $slug) {
            return $this->segments->defaultLocale();
        }

        return null;
    }

    public function retargetAbsoluteBlogUrl(string $url, string $targetLocale, string $slug): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $currentLocale = $this->resolveBlogRouteLocale($url, $slug);
        if ($currentLocale === null || $currentLocale === $targetLocale) {
            return null;
        }

        $parts = parse_url($url);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $authority = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        return $authority . $this->blogPath($targetLocale, $slug);
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array{changed:bool,source_path:string,target_path:string}
     */
    public function ensureLegacyRedirect(
        string $sourceLocale,
        string $sourceSlug,
        string $targetLocale,
        string $targetSlug,
        ?string $targetContentId = null,
        array $meta = [],
        bool $dryRun = false,
    ): array {
        $payload = [
            'source_path' => $this->blogPath($sourceLocale, $sourceSlug),
            'source_locale' => $sourceLocale,
            'source_slug' => $sourceSlug,
            'target_path' => $this->blogPath($targetLocale, $targetSlug),
            'target_locale' => $targetLocale,
            'target_slug' => $targetSlug,
            'target_content_id' => $targetContentId,
            'redirect_kind' => 'legacy_locale_mismatch',
            'is_active' => true,
            'meta' => $meta,
        ];

        $existing = MarketingBlogRedirect::query()
            ->where('source_path', $payload['source_path'])
            ->first();

        $changed = ! $existing
            || $existing->target_path !== $payload['target_path']
            || $existing->target_locale !== $payload['target_locale']
            || $existing->target_slug !== $payload['target_slug']
            || $existing->target_content_id !== $payload['target_content_id']
            || ! $existing->is_active
            || (array) ($existing->meta ?? []) !== $payload['meta'];

        if ($changed && ! $dryRun) {
            MarketingBlogRedirect::query()->updateOrCreate(
                ['source_path' => $payload['source_path']],
                $payload
            );
        }

        return [
            'changed' => $changed,
            'source_path' => $payload['source_path'],
            'target_path' => $payload['target_path'],
        ];
    }

    public function appendQueryString(string $targetPath, ?string $queryString): string
    {
        $queryString = trim((string) $queryString);
        if ($queryString === '') {
            return $targetPath;
        }

        return $targetPath . (str_contains($targetPath, '?') ? '&' : '?') . $queryString;
    }
}
