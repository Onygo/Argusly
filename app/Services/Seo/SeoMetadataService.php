<?php

namespace App\Services\Seo;

use App\Models\Content;
use App\Models\ContentImage;
use App\Services\ContentImages\ContentImageAssetResolver;
use App\Support\SeoTitle;
use Illuminate\Support\Str;

class SeoMetadataService
{
    public function __construct(private ?ContentImageAssetResolver $assets = null)
    {
        $this->assets ??= app(ContentImageAssetResolver::class);
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<string,mixed>
     */
    public function forBlogPost(array $post, string $canonicalUrl): array
    {
        $title = $this->title(
            (string) ($post['seo_title'] ?? ''),
            (string) ($post['title'] ?? ''),
            'Argusly Blog'
        );
        $description = $this->description([
            (string) ($post['seo_meta_description'] ?? ''),
            (string) ($post['meta_description'] ?? ''),
            (string) ($post['excerpt'] ?? ''),
            (string) ($post['content_html'] ?? ''),
        ]);
        $image = $this->absoluteUrl($this->normalizePublicImageUrl($this->firstNonEmpty([
            $post['seo_og_image'] ?? null,
            $post['og_image'] ?? null,
            $post['featured_image'] ?? null,
        ])));
        $ogTitle = trim((string) ($post['seo_og_title'] ?? '')) ?: $title;
        $ogDescription = trim((string) ($post['seo_og_description'] ?? '')) ?: $description;

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonicalUrl,
            'og_title' => $ogTitle,
            'og_description' => $ogDescription,
            'og_image' => $image,
            'twitter_title' => trim((string) ($post['seo_twitter_title'] ?? '')) ?: $ogTitle,
            'twitter_description' => trim((string) ($post['seo_twitter_description'] ?? '')) ?: $ogDescription,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function forContent(Content $content, ?string $canonicalUrl = null): array
    {
        $content->loadMissing(['currentVersion', 'featuredImage']);

        $description = $this->description([
            (string) ($content->seo_meta_description ?? ''),
            (string) ($content->public_blog_excerpt ?? ''),
            (string) ($content->currentVersion?->body ?? ''),
        ]);
        $title = $this->title((string) ($content->seo_title ?? ''), (string) $content->title, 'Argusly');

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonicalUrl ?: (string) ($content->seo_canonical ?? $content->published_url ?? ''),
            'og_title' => trim((string) ($content->seo_og_title ?? '')) ?: $title,
            'og_description' => trim((string) ($content->seo_og_description ?? '')) ?: $description,
            'og_image' => $this->absoluteUrl($this->resolveContentOgImage($content)),
            'twitter_title' => trim((string) ($content->seo_twitter_title ?? '')) ?: $title,
            'twitter_description' => trim((string) ($content->seo_twitter_description ?? '')) ?: $description,
        ];
    }

    private function resolveContentOgImage(Content $content): string
    {
        $assetUrl = $this->assets->urlForContent($content, ContentImage::USAGE_META);
        if ($assetUrl !== '') {
            return $this->normalizePublicImageUrl($assetUrl);
        }

        $legacyUrl = trim((string) ($content->seo_og_image ?? ''));
        if ($legacyUrl !== '') {
            return $this->normalizePublicImageUrl($legacyUrl);
        }

        $featured = $content->featuredImage;
        if ($featured instanceof ContentImage) {
            if ((string) ($featured->source ?? '') === ContentImage::SOURCE_UPLOAD && ! $featured->allowsUsage(ContentImage::USAGE_META)) {
                return '';
            }

            return $this->normalizePublicImageUrl($featured->bestUrlForUsage(ContentImage::USAGE_META));
        }

        return '';
    }

    /**
     * @return list<array{type:string,message:string}>
     */
    public function warningsForContent(Content $content): array
    {
        $content->loadMissing(['featuredImage']);
        $warnings = [];

        if (trim((string) ($content->seo_title ?? '')) === '') {
            $warnings[] = ['type' => 'missing_seo_title', 'message' => 'Missing SEO title. A fallback will render, but set a specific title before publishing.'];
        } elseif (mb_strlen(trim((string) $content->seo_title)) > SeoTitle::MAX_LENGTH) {
            $warnings[] = ['type' => 'long_seo_title', 'message' => 'SEO title is longer than 70 characters and may be shortened in public metadata.'];
        }

        if ($this->hasDuplicateSeoTitle($content)) {
            $warnings[] = ['type' => 'duplicate_seo_title', 'message' => 'SEO title is also used by another public article.'];
        }

        $description = trim((string) ($content->seo_meta_description ?? ''));
        if ($description === '') {
            $warnings[] = ['type' => 'missing_meta_description', 'message' => 'Missing meta description.'];
        } elseif ($this->isGenericDescription($description)) {
            $warnings[] = ['type' => 'vague_meta_description', 'message' => 'Meta description is too vague to help users decide to click.'];
        }

        if (trim((string) ($content->public_blog_excerpt ?? '')) === '') {
            $warnings[] = ['type' => 'missing_excerpt', 'message' => 'Missing article excerpt.'];
        }

        if ($content->featuredImage && trim((string) ($content->featuredImage->alt_text ?? '')) === '') {
            $warnings[] = ['type' => 'missing_featured_image_alt', 'message' => 'Featured image is missing alt text.'];
        }

        return $warnings;
    }

    public function isGenericDescription(string $description): bool
    {
        $normalized = Str::lower(trim($description));

        if (mb_strlen($normalized) < 80) {
            return true;
        }

        foreach (['learn more', 'click here', 'read more', 'welcome to', 'this article is about'] as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function title(string $seoTitle, string $fallbackTitle, string $suffix): string
    {
        $title = trim($seoTitle) !== '' ? trim($seoTitle) : trim($fallbackTitle);
        $title = $title !== '' ? $title : 'Argusly';

        return SeoTitle::withSuffix($title, $suffix);
    }

    /**
     * @param  list<string>  $candidates
     */
    private function description(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $text = trim(strip_tags($candidate));
            $text = preg_replace('/\s+/', ' ', $text) ?: '';

            if ($text !== '') {
                return (string) Str::limit($text, 160, '');
            }
        }

        return 'Argusly helps teams plan, create and publish useful content with stronger SEO and AI search workflows.';
    }

    /**
     * @param  array<int,mixed>  $candidates
     */
    private function firstNonEmpty(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $value = trim((string) ($candidate ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function absoluteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        return str_starts_with($url, '/') ? url($url) : $url;
    }

    private function normalizePublicImageUrl(string $url): string
    {
        $url = trim($url);

        return $url !== '' ? ContentImage::publicUrlForStorageValue($url) : '';
    }

    private function hasDuplicateSeoTitle(Content $content): bool
    {
        $title = trim((string) ($content->seo_title ?? ''));
        if ($title === '') {
            return false;
        }

        return Content::query()
            ->whereKeyNot((string) $content->id)
            ->where('type', 'article')
            ->where('status', 'published')
            ->where('publish_status', 'published')
            ->where('seo_title', $title)
            ->exists();
    }
}
