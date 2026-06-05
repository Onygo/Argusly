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
}
