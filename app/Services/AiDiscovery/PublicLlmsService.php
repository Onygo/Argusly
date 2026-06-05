<?php

namespace App\Services\AiDiscovery;

use App\Services\PublicBlog\PublicBlogService;
use App\Support\LocalizedMarketingUrl;
use App\Support\PublicSiteContext;
use App\Support\EarlyAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

class PublicLlmsService
{
    public function __construct(
        private readonly PublicBlogService $blog
    ) {
    }

    public function render(bool $full = false, ?string $locale = null): string
    {
        $locale = strtolower(trim((string) ($locale ?: app()->getLocale())));
        $cacheKey = sprintf(
            'public_llms.%s.%s.%s',
            $full ? 'full' : 'summary',
            $locale,
            $this->cacheScope()
        );
        $cacheTtl = (int) config('llms.cache_ttl', 10);

        return Cache::remember($cacheKey, now()->addMinutes($cacheTtl), function () use ($full, $locale): string {
            return $this->generate($full, $locale);
        });
    }

    private function generate(bool $full, string $locale): string
    {
        $lines = $this->buildHeader();
        $lines = array_merge($lines, $this->buildPageSection($locale));
        $lines = array_merge($lines, $this->buildMarkdownResourceSection($locale));
        $lines = array_merge($lines, $this->buildBlogSection($full, $locale));

        return trim(implode("\n", $lines)) . "\n";
    }

    private function buildHeader(): array
    {
        $siteName = (string) config('llms.site_name', config('app.name', 'Argusly'));
        $descriptionKey = (string) config('llms.site_description', 'public.landing.meta_description');
        $description = trim((string) __($descriptionKey));

        return [
            '# ' . $siteName,
            $description,
            '',
        ];
    }

    private function buildPageSection(string $locale): array
    {
        $pages = (array) config('llms.pages', []);
        $inEarlyAccessMode = EarlyAccess::enabled();

        $lines = ['## Important pages'];

        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }

            $label = trim((string) ($page['label'] ?? ''));
            $routeName = trim((string) ($page['route'] ?? ''));
            $requiresFullMarketing = (bool) ($page['requires_full_marketing'] ?? false);

            if ($label === '' || $routeName === '') {
                continue;
            }

            // Skip pages that require full marketing mode when in early access
            if ($requiresFullMarketing && $inEarlyAccessMode) {
                continue;
            }

            if (! LocalizedMarketingUrl::supportsRoute($routeName) && ! Route::has($routeName)) {
                continue;
            }

            $url = $this->buildUrl($routeName, $locale);
            $lines[] = '- ' . $label . ' (' . $url . ')';
        }

        $lines[] = '';

        return $lines;
    }

    private function buildBlogSection(bool $full, string $locale): array
    {
        if (! config('llms.include_blog_articles', true)) {
            return [];
        }

        // Blog is not available in early access mode
        if (EarlyAccess::enabled()) {
            return [];
        }

        $limit = $full
            ? (int) config('llms.blog_limit_full', 200)
            : (int) config('llms.blog_limit_summary', 30);

        $posts = $this->blog->latestPosts($limit, $locale);

        if (empty($posts)) {
            return [];
        }

        $lines = ['## Articles'];

        foreach ($posts as $post) {
            $title = trim((string) ($post['title'] ?? 'Untitled'));
            $slug = trim((string) ($post['slug'] ?? ''));

            if ($slug === '') {
                continue;
            }

            $url = $this->buildUrl('public.blog.markdown', $locale, ['slug' => $slug]);
            $lines[] = '- ' . $title . ' (' . $url . ')';
        }

        $lines[] = '';

        return $lines;
    }

    private function buildMarkdownResourceSection(string $locale): array
    {
        $slug = $locale === 'nl' ? 'ai-zoekmachines' : 'ai-search';

        $resources = [
            'AI Search / AEO',
            LocalizedMarketingUrl::route('public.marketing-pages.markdown', ['slug' => $slug], $locale),
            LocalizedMarketingUrl::route('public.marketing-pages.show', ['slug' => $slug], $locale),
        ];

        return [
            '## Markdown resources',
            '- ' . $resources[0] . ' (' . $resources[1] . ')',
            '- ' . $resources[0] . ' page (' . $resources[2] . ')',
            '',
        ];
    }

    /**
     * Build a URL ensuring the correct base domain is used.
     *
     * @param  array<string, mixed>  $params
     */
    private function buildUrl(string $routeName, string $locale, array $params = []): string
    {
        $url = LocalizedMarketingUrl::supportsRoute($routeName)
            ? LocalizedMarketingUrl::route($routeName, $params, $locale)
            : route($routeName, $params);

        // If a base URL override is configured, replace the domain
        $baseUrl = config('llms.base_url');
        if ($baseUrl !== null && $baseUrl !== '') {
            $baseUrl = rtrim((string) $baseUrl, '/');
            $parsedUrl = parse_url($url);
            $path = $parsedUrl['path'] ?? '/';
            $query = isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '';
            $url = $baseUrl . $path . $query;
        } elseif (app()->bound(PublicSiteContext::class)) {
            $context = app(PublicSiteContext::class);
            $parsedUrl = parse_url($url);
            $path = $parsedUrl['path'] ?? '/';
            $query = isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '';
            $url = rtrim((string) $context->rootUrl, '/') . $path . $query;
        }

        return $url;
    }

    private function cacheScope(): string
    {
        if (app()->bound(PublicSiteContext::class)) {
            $context = app(PublicSiteContext::class);

            return trim((string) $context->scopeKey) !== '' ? (string) $context->scopeKey : 'default';
        }

        $host = trim((string) request()?->getHost());

        return $host !== '' ? strtolower($host) : 'default';
    }
}
