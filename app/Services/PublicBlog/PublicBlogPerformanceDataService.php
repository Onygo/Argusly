<?php

namespace App\Services\PublicBlog;

use App\Models\Content;
use App\Models\ContentImage;
use App\Models\ContentVersion;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class PublicBlogPerformanceDataService
{
    /**
     * @return array<string,mixed>
     */
    public function syncContent(Content $content, bool $persist = true): array
    {
        $content->loadMissing([
            'currentVersion:id,content_id,body,meta',
            'featuredImage',
        ]);

        $version = $content->currentVersion;
        $meta = is_array($version?->meta) ? $version->meta : [];
        $categories = $this->normalizeTerms((array) data_get($meta, 'categories', data_get($meta, 'taxonomy.categories', [])));
        $tags = $this->normalizeTerms((array) data_get($meta, 'tags', data_get($meta, 'taxonomy.tags', [])));
        $featuredImage = $content->featuredImage;

        $payload = [
            'public_blog_excerpt' => $this->resolveExcerpt($content, $version, $meta),
            'public_blog_reading_time_minutes' => $this->resolveReadingTime($version, $meta),
            'public_blog_author' => $this->resolveAuthor($meta),
            'public_blog_category' => $categories[0] ?? null,
            'public_blog_tags' => $tags !== [] ? $tags : null,
            'public_blog_featured_image_url' => $this->resolveFeaturedImageUrl($featuredImage, $meta),
            'public_blog_featured_image_width' => $featuredImage?->width ?: null,
            'public_blog_featured_image_height' => $featuredImage?->height ?: null,
        ];

        $normalized = [];
        foreach ($payload as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
                $value = $value !== '' ? $value : null;
            }

            $normalized[$key] = $value;
        }

        if ($this->isAlreadySynced($content, $normalized)) {
            return $normalized;
        }

        if ($persist) {
            $content->forceFill($normalized);

            $timestamps = $content->timestamps;
            $content->timestamps = false;

            try {
                $content->saveQuietly();
            } finally {
                $content->timestamps = $timestamps;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function isAlreadySynced(Content $content, array $payload): bool
    {
        foreach ($payload as $key => $value) {
            $current = $content->getAttribute($key);

            if (is_array($value) || is_array($current)) {
                if (json_encode($current) !== json_encode($value)) {
                    return false;
                }

                continue;
            }

            if ($current !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function resolveExcerpt(Content $content, ?ContentVersion $version, array $meta): ?string
    {
        $candidates = [
            data_get($meta, 'excerpt'),
            data_get($meta, 'summary'),
            $content->seo_meta_description,
            data_get($meta, 'meta_description'),
            data_get($meta, 'description'),
        ];

        foreach ($candidates as $candidate) {
            $resolved = $this->normalizeExcerptCandidate($candidate);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        $plainText = $this->plainText((string) ($version?->body ?? ''));
        if ($plainText === '') {
            return null;
        }

        return Str::limit($plainText, 220, '…');
    }

    /**
     * @param  mixed  $candidate
     */
    private function normalizeExcerptCandidate($candidate): ?string
    {
        $text = $this->plainText((string) $candidate);

        if ($text === '') {
            return null;
        }

        return Str::limit($text, 220, '…');
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function resolveReadingTime(?ContentVersion $version, array $meta): int
    {
        $metaValue = data_get($meta, 'reading_time_minutes', data_get($meta, 'reading_time'));
        if (is_numeric($metaValue)) {
            return max(1, (int) $metaValue);
        }

        $plainText = $this->plainText((string) ($version?->body ?? ''));
        $wordCount = max(1, str_word_count($plainText));

        return max(1, (int) ceil($wordCount / 220));
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function resolveAuthor(array $meta): ?string
    {
        $author = trim((string) Arr::first([
            data_get($meta, 'author'),
            data_get($meta, 'author_name'),
            data_get($meta, 'byline'),
            data_get($meta, 'meta.author'),
        ], fn ($value): bool => trim((string) $value) !== ''));

        return $author !== '' ? Str::limit($author, 191, '') : null;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function resolveFeaturedImageUrl(?ContentImage $featuredImage, array $meta): ?string
    {
        $candidates = [
            $featuredImage?->thumbnail_ui_url,
            $featuredImage?->medium_ui_url,
            $featuredImage?->original_ui_url,
            (string) data_get($meta, 'featured_image', ''),
            (string) data_get($meta, 'featured_image_url', ''),
            (string) data_get($meta, 'images.featured', ''),
            (string) data_get($meta, 'hero_image', ''),
        ];

        foreach ($candidates as $candidate) {
            $resolved = trim((string) $candidate);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * @param  array<int,mixed>  $terms
     * @return array<int,string>
     */
    private function normalizeTerms(array $terms): array
    {
        return collect($terms)
            ->map(fn ($term): string => trim($this->plainText((string) $term)))
            ->filter()
            ->unique(fn (string $term): string => Str::lower($term))
            ->values()
            ->all();
    }

    private function plainText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if ($this->looksLikeHtml($value)) {
            $value = strip_tags($value);
        }

        $value = preg_replace('/[#>*_`~\-\[\]\(\)]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function looksLikeHtml(string $value): bool
    {
        return preg_match('/<\s*[a-z][^>]*>/i', $value) === 1;
    }
}
