<?php

namespace App\Services\Seo;

use App\Services\PublicBlog\PublicBlogService;
use App\Support\LocalizedMarketingUrl;
use App\Enums\SupportedLanguage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use XMLWriter;

class RssFeedGenerator
{
    public function __construct(
        private readonly PublicBlogService $blog,
    ) {}

    public function generate(?string $locale = null): string
    {
        $locale = SupportedLanguage::fromStringOrDefault($locale ?: app()->getLocale())->value;
        $key = $this->cacheKey($locale);
        $ttl = (int) config('sitemap.cache_ttl', 3600);

        if ($ttl <= 0) {
            $cached = $this->store()->get($key);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            $xml = $this->buildXml($locale);
            $this->store()->forever($key, $xml);

            return $xml;
        }

        /** @var string */
        return $this->store()->remember($key, $ttl, fn (): string => $this->buildXml($locale));
    }

    private function buildXml(string $locale): string
    {
        $posts = $this->blog->latestPosts(50, $locale);
        $feedLink = LocalizedMarketingUrl::route('public.blog.index', [], $locale);
        $feedTitle = 'PublishLayer Blog';
        $feedDescription = trim((string) __('public.blog.meta_description'));
        $lastBuildDate = collect($posts)
            ->map(fn (array $post): ?string => $this->rssDate($post['published_at'] ?? null))
            ->filter()
            ->first() ?? now()->toRssString();

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('rss');
        $xml->writeAttribute('version', '2.0');
        $xml->startElement('channel');

        $xml->writeElement('title', $feedTitle);
        $xml->writeElement('link', $feedLink);
        $xml->writeElement('description', $feedDescription);
        $xml->writeElement('language', $locale);
        $xml->writeElement('lastBuildDate', $lastBuildDate);

        foreach ($posts as $post) {
            $url = $this->blog->publicUrl($post, $locale);
            $xml->startElement('item');
            $xml->writeElement('title', trim((string) ($post['title'] ?? 'Untitled')));
            $xml->writeElement('link', $url);
            $xml->startElement('guid');
            $xml->writeAttribute('isPermaLink', 'true');
            $xml->text($url);
            $xml->endElement();

            if ($pubDate = $this->rssDate($post['published_at'] ?? null)) {
                $xml->writeElement('pubDate', $pubDate);
            }

            $description = $this->plainText((string) ($post['excerpt'] ?? ''));
            if ($description !== '') {
                $xml->writeElement('description', $description);
            }

            $xml->endElement();
        }

        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private function cacheKey(string $locale): string
    {
        return sprintf(
            'seo_xml.feed.rss.%s.lv_%d.%s',
            $this->blog->xmlCacheScopeSegment(),
            $this->blog->xmlLocaleVersion($locale),
            $locale
        );
    }

    private function store(): Repository
    {
        $store = config('sitemap.cache_store');

        return is_string($store) && $store !== ''
            ? Cache::store($store)
            : Cache::store();
    }

    private function rssDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toRssString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function plainText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
