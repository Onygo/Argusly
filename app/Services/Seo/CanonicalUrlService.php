<?php

namespace App\Services\Seo;

use App\Models\Content;
use App\Support\LocalizedMarketingUrl;

class CanonicalUrlService
{
    public function publicBlogCanonical(string $slug, string $locale): string
    {
        return $this->normalizeOrFallback(
            LocalizedMarketingUrl::route('public.blog.show', ['slug' => $slug], $locale),
            LocalizedMarketingUrl::route('public.blog.show', ['slug' => $slug], $locale)
        );
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<string,string>
     */
    public function publicBlogAlternates(array $post, string $currentLocale): array
    {
        $variants = collect((array) ($post['localized_variants'] ?? []))
            ->filter(fn ($variant, $locale): bool => is_array($variant)
                && trim((string) $locale) !== ''
                && trim((string) ($variant['slug'] ?? '')) !== '')
            ->mapWithKeys(function (array $variant, string $locale): array {
                $slug = trim((string) ($variant['slug'] ?? ''));

                return [
                    $locale => $this->publicBlogCanonical($slug, $locale),
                ];
            });

        $selfSlug = trim((string) ($post['slug'] ?? ''));
        if ($selfSlug !== '' && ! $variants->has($currentLocale)) {
            $variants->put($currentLocale, $this->publicBlogCanonical($selfSlug, $currentLocale));
        }

        $xDefault = $variants->get(config('marketing_routing.default_locale', 'en'))
            ?? $variants->first();

        if (is_string($xDefault) && $xDefault !== '') {
            $variants->put('x-default', $xDefault);
        }

        return $variants->all();
    }

    public function expectedCanonicalForContent(Content $content): ?string
    {
        if ((string) $content->type !== 'article') {
            return $this->normalize($content->seo_canonical ?: $content->published_url);
        }

        $slug = trim((string) ($content->publish_url_key ?: $content->canonical_url_key));

        if ($slug === '') {
            $slug = trim((string) data_get($content->currentVersion?->meta, 'slug', ''));
        }

        if ($slug === '') {
            return null;
        }

        return $this->publicBlogCanonical($slug, $content->localeCode());
    }

    public function liveUrlForContent(Content $content, ?string $candidate = null, ?string $fallbackSlug = null): ?string
    {
        $candidate = trim((string) ($candidate ?? $content->published_url ?? ''));

        if ((string) $content->type !== 'article') {
            return $this->normalize($candidate) ?? ($candidate !== '' ? $candidate : null);
        }

        $expected = $this->expectedCanonicalForContent($content);

        $fallbackSlug = trim((string) $fallbackSlug);

        if (($expected === null || $expected === '') && $fallbackSlug === '') {
            $fallbackSlug = $this->slugFromPublicBlogCandidate($candidate) ?? '';
        }

        if (($expected === null || $expected === '') && $fallbackSlug !== '') {
            $expected = $this->publicBlogCanonical($fallbackSlug, $content->localeCode());
        }

        if ($candidate === '') {
            return $expected;
        }

        if ($expected !== null && $this->isSamePublicBlogUrlWithoutLocale($candidate, $expected)) {
            return $this->withOriginalQuery($this->withCandidateBase($expected, $candidate), $candidate);
        }

        return $candidate;
    }

    public function normalize(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '/')) {
            $url = url($url);
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = '/' . ltrim((string) ($parts['path'] ?? '/'), '/');
        $path = preg_replace('#/{2,}#', '/', $path) ?: '/';
        $path = $path !== '/' ? rtrim($path, '/') : '/';

        return $scheme . '://' . $host . $port . $path;
    }

    public function equivalent(?string $left, ?string $right): bool
    {
        $normalizedLeft = $this->normalize($left);
        $normalizedRight = $this->normalize($right);

        return $normalizedLeft !== null
            && $normalizedRight !== null
            && hash_equals($normalizedLeft, $normalizedRight);
    }

    public function normalizeOrFallback(?string $candidate, string $fallback): string
    {
        return $this->normalize($candidate) ?? $this->normalize($fallback) ?? $fallback;
    }

    private function isSamePublicBlogUrlWithoutLocale(string $candidate, string $expected): bool
    {
        $candidateParts = parse_url($candidate);
        $expectedParts = parse_url($expected);

        if (! is_array($candidateParts) || ! is_array($expectedParts)) {
            return false;
        }

        $candidateHost = strtolower((string) ($candidateParts['host'] ?? ''));
        $expectedHost = strtolower((string) ($expectedParts['host'] ?? ''));

        if ($candidateHost === '' || $expectedHost === '') {
            return false;
        }

        $candidatePath = '/' . ltrim((string) ($candidateParts['path'] ?? ''), '/');
        $expectedPath = '/' . ltrim((string) ($expectedParts['path'] ?? ''), '/');

        if ($candidateHost !== $expectedHost && ! $this->isKnownPublicMarketingHost($candidateHost)) {
            return false;
        }

        $candidateSlug = $this->slugFromPublicBlogCandidate($candidatePath);
        $expectedSlug = $this->slugFromPublicBlogCandidate($expectedPath);

        return $candidateSlug !== null
            && $expectedSlug !== null
            && hash_equals($candidateSlug, $expectedSlug);
    }

    private function slugFromPublicBlogCandidate(string $candidate): ?string
    {
        $parts = parse_url($candidate);

        if (! is_array($parts)) {
            return null;
        }

        $segments = array_values(array_filter(
            explode('/', trim((string) ($parts['path'] ?? ''), '/')),
            fn (string $segment): bool => $segment !== ''
        ));

        if ($segments === []) {
            return null;
        }

        $blogSegments = array_values(array_unique(array_filter(array_map(
            fn (mixed $segment): string => trim((string) $segment, '/'),
            (array) config('marketing_routing.segments.blog', ['en' => 'blog'])
        ))));

        if (count($segments) >= 2 && in_array($segments[0], $blogSegments, true)) {
            return $segments[1];
        }

        if (count($segments) >= 3 && in_array($segments[1], $blogSegments, true)) {
            return $segments[2];
        }

        return null;
    }

    private function withCandidateBase(string $url, string $candidate): string
    {
        $urlParts = parse_url($url);
        $candidateParts = parse_url($candidate);

        if (! is_array($urlParts) || ! is_array($candidateParts) || empty($candidateParts['host'])) {
            return $url;
        }

        $scheme = (string) ($candidateParts['scheme'] ?? $urlParts['scheme'] ?? 'https');
        $host = strtolower((string) $candidateParts['host']);
        $port = isset($candidateParts['port']) ? ':' . (int) $candidateParts['port'] : '';
        $path = '/' . ltrim((string) ($urlParts['path'] ?? '/'), '/');

        return $scheme . '://' . $host . $port . $path;
    }

    private function isKnownPublicMarketingHost(string $host): bool
    {
        $knownHosts = [
            'argusly.com',
            'www.argusly.com',
            strtolower((string) config('domains.base', '')),
            strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
        ];
        $knownHosts = array_merge(
            $knownHosts,
            array_map(
                fn (mixed $host): string => strtolower(trim((string) $host)),
                (array) config('argusly.analytics.internal_verified_domains', [])
            )
        );

        return in_array(strtolower($host), array_filter($knownHosts), true);
    }

    private function withOriginalQuery(string $url, string $original): string
    {
        $query = parse_url($original, PHP_URL_QUERY);

        return $query ? $url . '?' . $query : $url;
    }
}
