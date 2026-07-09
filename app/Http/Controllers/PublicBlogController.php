<?php

namespace App\Http\Controllers;

use App\Exceptions\PublicBlogSourceUnavailableException;
use App\Models\ContentImage;
use App\Services\Content\ContentRenderNormalizer;
use App\Services\PublicBlog\MarketingBlogSourceScope;
use App\Services\PublicBlog\PublicBlogService;
use App\Services\Seo\RssFeedGenerator;
use App\Services\Seo\SeoMetadataService;
use App\Support\Database\RequestQueryProfiler;
use App\Support\LocaleHelper;
use App\Support\LocalizedMarketingUrl;
use DOMDocument;
use DOMElement;
use DOMText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\Paginator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Throwable;
use Illuminate\View\View;

class PublicBlogController extends Controller
{
    private const ARTICLE_RESPONSE_CACHE_VERSION = 'v2';

    public function __construct(
        private readonly MarketingBlogSourceScope $sourceScope,
        private readonly ContentRenderNormalizer $renderNormalizer,
    ) {
    }

    public function index(Request $request, PublicBlogService $blog): View|Response
    {
        return $this->renderIndex($request, $blog);
    }

    public function tag(Request $request, PublicBlogService $blog, string $tag): View|Response
    {
        return $this->renderIndex($request, $blog, ['tag' => $tag]);
    }

    public function category(Request $request, PublicBlogService $blog, string $category): View|Response
    {
        return $this->renderIndex($request, $blog, ['category' => $category]);
    }

    public function show(Request $request, PublicBlogService $blog, SeoMetadataService $metadata, string $slug): View|Response|RedirectResponse
    {
        $locale = (string) app()->getLocale();
        $slug = \Illuminate\Support\Str::slug($slug);

        try {
            $post = $blog->getPostBySlug($slug, $locale);
            if (! $post) {
                $redirectTarget = $blog->legacyRedirectUrlForSlug($slug, $locale);
                if ($redirectTarget !== null && $redirectTarget !== '') {
                    $query = trim((string) $request->getQueryString());
                    $target = $redirectTarget . ($query !== '' ? (str_contains($redirectTarget, '?') ? '&' : '?') . $query : '');

                    return redirect()->to($target, 301);
                }

                abort(404);
            }
        } catch (PublicBlogSourceUnavailableException) {
            return response()->view('public.blog.unavailable', [
                'metaTitle' => __('public.blog.meta_title'),
                'metaDescription' => __('public.blog.meta_description'),
                'canonicalUrl' => $this->localizedRoute('public.blog.show', ['slug' => $slug], $locale),
                'hreflangUrls' => [],
            ], 503);
        }

        if ($this->canCacheArticleResponse($request)) {
            $cacheKey = $this->articleResponseCacheKey($blog, $locale, $slug);

            try {
                $html = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($request, $blog, $metadata, $post, $slug, $locale): string {
                    return $this->renderArticleHtml($request, $blog, $metadata, $post, $slug, $locale);
                });

                return response($html);
            } catch (Throwable $exception) {
                Log::warning('public_blog.article_cache_failed', [
                    'content_id' => (string) ($post['id'] ?? ''),
                    'slug' => $slug,
                    'locale' => $locale,
                    'route' => (string) ($request->route()?->getName() ?? ''),
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        try {
            return response($this->renderArticleHtml($request, $blog, $metadata, $post, $slug, $locale));
        } catch (Throwable $exception) {
            Log::error('Public blog render failed', [
                'content_id' => $post['id'] ?? null,
                'slug' => $post['slug'] ?? $slug,
                'locale' => $post['locale'] ?? $locale,
                'exception' => $exception->getMessage(),
            ]);

            return response($this->renderDegradedArticleHtml($post, $slug, $locale), 200);
        }
    }

    /**
     * @param  array<string,mixed>  $post
     */
    private function renderArticleHtml(
        Request $request,
        PublicBlogService $blog,
        SeoMetadataService $metadata,
        array $post,
        string $slug,
        string $locale
    ): string {

        $post = $this->localizeRenderablePostLinks(
            $this->normalizeRenderablePost($post, $slug, $locale),
            $blog,
            $locale
        );
        $title = trim((string) ($post['title'] ?? __('public.blog.meta_title'))) ?: __('public.blog.meta_title');
        $canonical = $this->safeCanonical($blog, $post, $slug, $locale);
        $seo = $this->safeSeo($metadata, $post, $canonical, $title);
        $description = (string) ($seo['description'] ?? __('public.blog.meta_description'));
        $hreflangUrls = $this->safeHreflangUrls($blog, $post, $canonical, $locale);
        $visibleLocaleSwitchUrls = LocaleHelper::visibleLocaleUrls($hreflangUrls);

        $breadcrumbSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => __('public.nav.overview'),
                    'item' => $this->localizedRoute('landing', [], $locale),
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => __('public.blog.title'),
                    'item' => $this->localizedRoute('public.blog.index', [], $locale),
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 3,
                    'name' => $title,
                    'item' => $canonical,
                ],
            ],
        ];

        $blogPostingSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $title,
            'description' => $description,
            'inLanguage' => $locale,
            'datePublished' => (string) ($post['published_at'] ?? ''),
            'dateModified' => (string) (($post['updated_at'] ?? '') ?: ($post['published_at'] ?? '')),
            'author' => [
                '@type' => 'Person',
                'name' => (string) (($post['author'] ?? '') !== '' ? $post['author'] : 'Argusly'),
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => 'Argusly',
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => asset('images/argusly-logo.svg'),
                ],
            ],
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $canonical,
            ],
            'url' => $canonical,
            'image' => (string) $seo['og_image'],
        ];

        return view('public.blog.show', [
            'post' => $post,
            'metaTitle' => $seo['title'],
            'metaDescription' => $description,
            'canonicalUrl' => $canonical,
            'hreflangUrls' => $hreflangUrls,
            'ogType' => 'article',
            'ogTitle' => $seo['og_title'],
            'ogDescription' => $seo['og_description'],
            'twitterTitle' => $seo['twitter_title'],
            'twitterDescription' => $seo['twitter_description'],
            'ogImage' => $seo['og_image'],
            'robotsIndex' => (bool) ($post['robots_index'] ?? true),
            'robotsFollow' => (bool) ($post['robots_follow'] ?? true),
            'localeSwitchUrls' => $visibleLocaleSwitchUrls,
            'blogPostingSchema' => $blogPostingSchema,
            'breadcrumbSchema' => $breadcrumbSchema,
            'faqSchema' => $this->validSchema($post['faq_schema'] ?? null),
        ])->render();
    }

    /**
     * @param  array<string,mixed>  $post
     */
    private function renderDegradedArticleHtml(array $post, string $slug, string $locale): string
    {
        $post = $this->normalizeRenderablePost($post, $slug, $locale);
        $title = trim((string) ($post['title'] ?? '')) ?: __('public.blog.meta_title');
        $canonical = $this->localizedRoute('public.blog.show', ['slug' => $post['slug'] ?: $slug], $locale);
        $description = trim((string) ($post['meta_description'] ?? $post['seo_meta_description'] ?? $post['excerpt'] ?? ''))
            ?: __('public.blog.meta_description');

        return view('public.blog.show', [
            'post' => $post,
            'metaTitle' => $title,
            'metaDescription' => $description,
            'canonicalUrl' => $canonical,
            'hreflangUrls' => [$locale => $canonical, 'x-default' => $canonical],
            'ogType' => 'article',
            'ogTitle' => $title,
            'ogDescription' => $description,
            'twitterTitle' => $title,
            'twitterDescription' => $description,
            'ogImage' => null,
            'robotsIndex' => false,
            'robotsFollow' => true,
            'localeSwitchUrls' => [],
            'blogPostingSchema' => null,
            'breadcrumbSchema' => null,
            'faqSchema' => null,
        ])->render();
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<string,mixed>
     */
    private function normalizeRenderablePost(array $post, string $slug, string $locale): array
    {
        $post['id'] = (string) ($post['id'] ?? '');
        $post['slug'] = trim((string) ($post['slug'] ?? $slug));
        $post['locale'] = trim((string) ($post['locale'] ?? $locale)) ?: $locale;
        $post['title'] = trim((string) ($post['title'] ?? __('public.blog.meta_title'))) ?: __('public.blog.meta_title');
        $post['content_html'] = $this->renderNormalizer->normalize(
            is_string($post['content_html'] ?? null) ? $post['content_html'] : '',
            [
                'context' => 'public_blog.render',
                'content_id' => (string) ($post['id'] ?? ''),
            ],
        );
        $cleanExcerpt = trim((string) preg_replace('/\s+/u', ' ', strip_tags($post['content_html'])));
        if ($cleanExcerpt !== '') {
            $post['excerpt'] = \Illuminate\Support\Str::limit($cleanExcerpt, 220, '');
        }
        $post['published_date'] = trim((string) ($post['published_date'] ?? ''));
        $post['reading_time'] = max(0, (int) ($post['reading_time'] ?? 0));
        $post['author'] = trim((string) ($post['author'] ?? ''));
        $post['featured_image'] = $this->normalizePublicImageUrl((string) ($post['featured_image'] ?? ''));
        $post['featured_image_alt'] = trim((string) ($post['featured_image_alt'] ?? $post['title']));
        $post['localized_variants'] = is_array($post['localized_variants'] ?? null) ? $post['localized_variants'] : [];
        $post['answer_blocks'] = is_array($post['answer_blocks'] ?? null) ? $post['answer_blocks'] : [];

        return $post;
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<string,mixed>
     */
    private function localizeRenderablePostLinks(array $post, PublicBlogService $blog, string $locale): array
    {
        $html = trim((string) ($post['content_html'] ?? ''));
        if ($html === '') {
            return $post;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return $post;
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return $post;
        }

        $changed = false;
        $currentSlug = $this->normalizeBlogSlug((string) ($post['slug'] ?? ''));
        $selfAnchors = [];
        $anchors = [];
        foreach ($body->getElementsByTagName('a') as $anchor) {
            if ($anchor instanceof DOMElement) {
                $anchors[] = $anchor;
            }
        }

        foreach ($anchors as $anchor) {
            $href = trim((string) $anchor->getAttribute('href'));
            $slug = $this->blogSlugFromHref($href);
            if ($slug === null) {
                continue;
            }

            $localizedUrl = $blog->localizedUrlForLinkedSlug($slug, $locale);
            if ($localizedUrl === null || $localizedUrl === '') {
                continue;
            }

            $replacement = $this->withOriginalUrlSuffix($localizedUrl, $href);
            if ($replacement !== $href) {
                $anchor->setAttribute('href', $replacement);
                $changed = true;
            }

            $resolvedSlug = $this->blogSlugFromHref($replacement);
            if ($currentSlug !== '' && $resolvedSlug !== null && $this->sameBlogSlug($resolvedSlug, $currentSlug)) {
                $selfAnchors[] = $anchor;
            }
        }

        if ($selfAnchors !== []) {
            $changed = $this->removeSelfLinkedAnchors($selfAnchors, $currentSlug) || $changed;
        }

        if ($changed) {
            $post['content_html'] = trim($this->innerHtml($body));
        }

        return $post;
    }

    /**
     * @param array<int,DOMElement> $anchors
     */
    private function removeSelfLinkedAnchors(array $anchors, string $currentSlug): bool
    {
        $changed = false;
        $removedContainers = [];

        foreach ($anchors as $anchor) {
            if (! $anchor->parentNode) {
                continue;
            }

            $container = $this->generatedSelfLinkContainer($anchor, $currentSlug);
            if ($container instanceof DOMElement && $container->parentNode) {
                $containerId = spl_object_id($container);
                if (! isset($removedContainers[$containerId])) {
                    $container->parentNode->removeChild($container);
                    $removedContainers[$containerId] = true;
                    $changed = true;
                }

                continue;
            }

            $replacement = new DOMText((string) $anchor->textContent);
            $anchor->parentNode->replaceChild($replacement, $anchor);
            $changed = true;
        }

        return $changed;
    }

    private function generatedSelfLinkContainer(DOMElement $anchor, string $currentSlug): ?DOMElement
    {
        for ($node = $anchor->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            $tagName = strtolower($node->tagName);
            if ($tagName === 'body') {
                return null;
            }

            if (! in_array($tagName, ['p', 'li', 'div', 'section', 'aside'], true)) {
                continue;
            }

            if (! $this->looksLikeGeneratedRelatedContainer($node)) {
                continue;
            }

            foreach ($node->getElementsByTagName('a') as $candidate) {
                if (! $candidate instanceof DOMElement) {
                    continue;
                }

                $slug = $this->blogSlugFromHref((string) $candidate->getAttribute('href'));
                if ($slug === null || ! $this->sameBlogSlug($slug, $currentSlug)) {
                    return null;
                }
            }

            return $node;
        }

        return null;
    }

    private function looksLikeGeneratedRelatedContainer(DOMElement $element): bool
    {
        $text = strtolower(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($element->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));

        return str_contains($text, 'related reading')
            || str_contains($text, 'related article')
            || str_contains($text, 'gerelateerde lectuur')
            || str_contains($text, 'aanvullende resources');
    }

    private function sameBlogSlug(string $left, string $right): bool
    {
        return $this->normalizeBlogSlug($left) === $this->normalizeBlogSlug($right);
    }

    private function normalizeBlogSlug(string $slug): string
    {
        return strtolower(trim(rawurldecode($slug), " \t\n\r\0\x0B/"));
    }

    private function blogSlugFromHref(string $href): ?string
    {
        if ($href === '' || str_starts_with($href, '#') || str_starts_with(strtolower($href), 'mailto:')) {
            return null;
        }

        $path = (string) parse_url($href, PHP_URL_PATH);
        if ($path === '') {
            return null;
        }

        $host = strtolower(trim((string) parse_url($href, PHP_URL_HOST)));
        $baseHost = strtolower(trim((string) parse_url((string) config('app.url'), PHP_URL_HOST)));
        $domainHost = strtolower(trim((string) config('domains.base', 'argusly.local')));
        if ($host !== '' && ! in_array($host, array_filter([$baseHost, $domainHost]), true)) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), fn (string $segment): bool => $segment !== ''));
        if (count($segments) < 2) {
            return null;
        }

        $locales = (array) config('marketing_routing.locales', ['en', 'nl']);
        if (in_array(strtolower($segments[0]), $locales, true)) {
            return (($segments[1] ?? '') === 'blog' && isset($segments[2])) ? $segments[2] : null;
        }

        return $segments[0] === 'blog' && isset($segments[1]) ? $segments[1] : null;
    }

    private function withOriginalUrlSuffix(string $url, string $originalHref): string
    {
        $query = trim((string) parse_url($originalHref, PHP_URL_QUERY));
        $fragment = trim((string) parse_url($originalHref, PHP_URL_FRAGMENT));

        if ($query !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?') . $query;
        }

        if ($fragment !== '') {
            $url .= '#' . $fragment;
        }

        return $url;
    }

    private function innerHtml(DOMElement $element): string
    {
        $html = '';

        foreach ($element->childNodes as $child) {
            $html .= $element->ownerDocument?->saveHTML($child) ?? '';
        }

        return $html;
    }

    /**
     * @param  array<string,mixed>  $post
     */
    private function safeCanonical(PublicBlogService $blog, array $post, string $slug, string $locale): string
    {
        try {
            $canonical = trim((string) $blog->publicUrl($post, $locale));
        } catch (Throwable $exception) {
            Log::warning('public_blog.canonical_failed', [
                'content_id' => (string) ($post['id'] ?? ''),
                'slug' => (string) ($post['slug'] ?? $slug),
                'locale' => $locale,
                'message' => $exception->getMessage(),
            ]);
            $canonical = '';
        }

        return $canonical !== ''
            ? $canonical
            : $this->localizedRoute('public.blog.show', ['slug' => $post['slug'] ?: $slug], $locale);
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<string,mixed>
     */
    private function safeSeo(SeoMetadataService $metadata, array $post, string $canonical, string $title): array
    {
        try {
            return $metadata->forBlogPost($post, $canonical);
        } catch (Throwable $exception) {
            Log::warning('public_blog.seo_metadata_failed', [
                'content_id' => (string) ($post['id'] ?? ''),
                'slug' => (string) ($post['slug'] ?? ''),
                'locale' => (string) ($post['locale'] ?? ''),
                'message' => $exception->getMessage(),
            ]);

            $description = trim(strip_tags((string) ($post['meta_description'] ?? $post['excerpt'] ?? '')))
                ?: __('public.blog.meta_description');

            return [
                'title' => $title,
                'description' => $description,
                'canonical' => $canonical,
                'og_title' => $title,
                'og_description' => $description,
                'og_image' => null,
                'twitter_title' => $title,
                'twitter_description' => $description,
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<string,string>
     */
    private function safeHreflangUrls(PublicBlogService $blog, array $post, string $canonical, string $locale): array
    {
        try {
            $urls = $blog->alternateLocaleUrls($post);
        } catch (Throwable $exception) {
            Log::warning('public_blog.hreflang_failed', [
                'content_id' => (string) ($post['id'] ?? ''),
                'slug' => (string) ($post['slug'] ?? ''),
                'locale' => $locale,
                'message' => $exception->getMessage(),
            ]);
            $urls = [];
        }

        $urls = collect((array) $urls)
            ->filter(fn ($href, $hreflang): bool => is_string($hreflang) && trim($hreflang) !== '' && is_string($href) && trim($href) !== '')
            ->map(fn (string $href): string => $href)
            ->all();

        if (! isset($urls[$locale])) {
            $urls[$locale] = $canonical;
        }

        return $this->withDefaultHreflangUrls($urls);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function validSchema(mixed $schema): ?array
    {
        if (! is_array($schema) || $schema === []) {
            return null;
        }

        try {
            json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (Throwable) {
            return null;
        }

        return $schema;
    }

    public function rss(Request $request, PublicBlogService $blog, RssFeedGenerator $feed): Response
    {
        try {
            return response(
                $feed->generate((string) app()->getLocale()),
                200,
                ['Content-Type' => 'application/rss+xml; charset=UTF-8']
            );
        } catch (PublicBlogSourceUnavailableException) {
            return response('<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Argusly Blog</title></channel></rss>', 503)
                ->header('Content-Type', 'application/rss+xml; charset=UTF-8');
        } catch (Throwable $e) {
            Log::error('seo.feed_generation_failed', [
                'locale' => (string) app()->getLocale(),
                'message' => $e->getMessage(),
            ]);

            return response('Feed unavailable.', 503, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
    }

    private function renderIndex(Request $request, PublicBlogService $blog, array $filters = []): View|Response
    {
        $locale = (string) app()->getLocale();
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 9;
        $tagFilter = trim((string) ($filters['tag'] ?? ''));
        $categoryFilter = trim((string) ($filters['category'] ?? ''));
        $profiler = RequestQueryProfiler::startIfEnabled($request, 'public.blog.index');

        try {
            $posts = $blog->listPublishedPosts($page, $perPage, $filters, $locale);
            $tags = $blog->listTags($locale);
            $categories = $blog->listCategories($locale);
        } catch (PublicBlogSourceUnavailableException) {
            return response()->view('public.blog.index', [
                'posts' => new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage, $page, ['path' => Paginator::resolveCurrentPath(), 'query' => $request->query()]),
                'tags' => [],
                'categories' => [],
                'metaTitle' => __('public.blog.meta_title'),
                'metaDescription' => __('public.blog.meta_description'),
                'canonicalUrl' => $this->localizedRoute('public.blog.index', [], $locale),
                'hreflangUrls' => $this->withDefaultHreflangUrls(
                    LocalizedMarketingUrl::hreflangsForRoute('public.blog.index')
                ),
                'ogType' => 'website',
                'activeTag' => $tagFilter,
                'activeCategory' => $categoryFilter,
                'connectorUnavailable' => true,
                'blogSourceConfigured' => $this->sourceScope->isConfigured(),
            ], 503);
        }

        $profiler?->logSummary([
            'page' => $page,
            'locale' => $locale,
            'tag' => $tagFilter !== '' ? $tagFilter : null,
            'category' => $categoryFilter !== '' ? $categoryFilter : null,
            'post_count' => $posts->count(),
            'total_posts' => $posts->total(),
        ]);

        $title = __('public.blog.meta_title');
        $description = __('public.blog.meta_description');

        if ($tagFilter !== '') {
            $title = __('public.blog.meta_title_tag', ['tag' => $tagFilter]);
        } elseif ($categoryFilter !== '') {
            $title = __('public.blog.meta_title_category', ['category' => $categoryFilter]);
        }

        if ($page > 1) {
            $title .= ' - ' . __('public.blog.page_suffix', ['page' => $page]);
        }

        $canonicalParams = [];
        if ($tagFilter !== '') {
            $canonical = $this->localizedRoute('public.blog.tag', ['tag' => $tagFilter], $locale);
        } elseif ($categoryFilter !== '') {
            $canonical = $this->localizedRoute('public.blog.category', ['category' => $categoryFilter], $locale);
        } else {
            $canonical = $this->localizedRoute('public.blog.index', $canonicalParams, $locale);
        }

        if ($page > 1) {
            $canonical = $canonical . (str_contains($canonical, '?') ? '&' : '?') . 'page=' . $page;
        }

        $hreflangParams = $page > 1 ? ['page' => $page] : [];

        return view('public.blog.index', [
            'posts' => $posts,
            'tags' => $tags,
            'categories' => $categories,
            'metaTitle' => $title,
            'metaDescription' => $description,
            'canonicalUrl' => $canonical,
            'hreflangUrls' => $tagFilter !== '' || $categoryFilter !== ''
                ? []
                : $this->withDefaultHreflangUrls(LocalizedMarketingUrl::hreflangsForRoute('public.blog.index', $hreflangParams)),
            'ogType' => 'website',
            'robotsIndex' => $tagFilter === '' && $categoryFilter === '',
            'robotsFollow' => true,
            'activeTag' => $tagFilter,
            'activeCategory' => $categoryFilter,
            'connectorUnavailable' => false,
            'blogSourceConfigured' => $this->sourceScope->isConfigured(),
        ]);
    }

    private function localizedRoute(string $name, array $params, string $locale): string
    {
        return LocalizedMarketingUrl::route($name, $params, $locale);
    }

    private function normalizePublicImageUrl(string $url): string
    {
        $url = trim($url);

        return $url !== '' ? ContentImage::publicUrlForStorageValue($url) : '';
    }

    private function canCacheArticleResponse(Request $request): bool
    {
        return $request->isMethod('GET')
            && ! $request->ajax()
            && $request->getQueryString() === null
            && (bool) config('argusly.public_blog.cache_article_pages', true);
    }

    private function articleResponseCacheKey(PublicBlogService $blog, string $locale, string $slug): string
    {
        return sprintf(
            'public_blog.article_response.%s.%s.%s.%s',
            self::ARTICLE_RESPONSE_CACHE_VERSION,
            $locale,
            $blog->xmlLocaleVersion($locale),
            $slug
        );
    }

    /**
     * @param  array<string,string>  $urls
     * @return array<string,string>
     */
    private function withDefaultHreflangUrls(array $urls): array
    {
        $defaultLocale = (string) config('marketing_routing.default_locale', 'en');
        $xDefault = $urls[$defaultLocale] ?? reset($urls);

        if (is_string($xDefault) && $xDefault !== '') {
            $urls['x-default'] = $xDefault;
        }

        return $urls;
    }

}
