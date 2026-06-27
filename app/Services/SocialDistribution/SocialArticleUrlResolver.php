<?php

namespace App\Services\SocialDistribution;

use App\Models\Content;
use App\Services\Seo\CanonicalUrlService;
use Illuminate\Support\Str;

class SocialArticleUrlResolver
{
    public function __construct(
        private readonly CanonicalUrlService $canonicals,
    ) {}

    public function forContent(Content $content, ?string $candidate = null): string
    {
        $candidate = trim((string) $candidate);

        $resolved = $this->canonicals->liveUrlForContent(
            $content,
            $candidate !== '' ? $candidate : null
        );

        $url = $resolved
            ?: ($candidate !== '' ? $candidate : trim((string) ($content->published_url ?: $content->seo_canonical)));

        return $this->arguslyPublicBlogUrl($content, $url) ?? $url;
    }

    private function arguslyPublicBlogUrl(Content $content, string $url): ?string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['host']) || ! $this->isArguslyPublicHost((string) $parts['host'])) {
            return null;
        }

        $slug = $this->blogSlugFromContent($content)
            ?: $this->slugFromPublicBlogPath((string) ($parts['path'] ?? ''));

        if ($slug === null || $slug === '') {
            return null;
        }

        $locale = $content->localeCode();
        $path = match ($locale) {
            'en' => '/en/blog/' . $slug,
            default => '/' . $locale . '/blog/' . $slug,
        };

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https')) ?: 'https';
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $query = isset($parts['query']) && trim((string) $parts['query']) !== '' ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) && trim((string) $parts['fragment']) !== '' ? '#' . $parts['fragment'] : '';

        return $scheme . '://' . $host . $port . $path . $query . $fragment;
    }

    private function blogSlugFromContent(Content $content): ?string
    {
        $slug = trim((string) ($content->publish_url_key ?: $content->canonical_url_key));

        if ($slug === '') {
            $slug = trim((string) data_get($content->currentVersion?->meta, 'slug', ''));
        }

        return $slug !== '' ? $slug : null;
    }

    private function slugFromPublicBlogPath(string $path): ?string
    {
        $segments = array_values(array_filter(
            explode('/', trim($path, '/')),
            fn (string $segment): bool => $segment !== ''
        ));

        if ($segments === []) {
            return null;
        }

        $blogIndex = null;
        foreach ($segments as $index => $segment) {
            if (Str::of($segment)->lower()->toString() === 'blog') {
                $blogIndex = $index;
                break;
            }
        }

        if ($blogIndex === null || ! isset($segments[$blogIndex + 1])) {
            return null;
        }

        return $segments[$blogIndex + 1];
    }

    private function isArguslyPublicHost(string $host): bool
    {
        $host = strtolower(trim($host));
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $baseDomain = strtolower((string) config('domains.base', ''));

        return in_array($host, array_filter([
            'argusly.com',
            'www.argusly.com',
            $appHost,
            $baseDomain,
        ]), true);
    }
}
