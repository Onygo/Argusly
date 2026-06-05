<?php

namespace App\Services\Content;

use App\Models\Content;
use App\Models\Draft;
use Illuminate\Support\Str;

class LocalizedContentSlugService
{
    public function slugFromTitle(string $title, string $fallback = 'post'): string
    {
        $slug = Str::slug(trim($title));

        return $slug !== '' ? $slug : Str::slug($fallback);
    }

    public function expectedSlug(Content $content): string
    {
        return $this->slugFromTitle((string) $content->title, (string) ($content->id ?? 'post'));
    }

    public function persistedSlug(Content $content): string
    {
        return $this->normalizeSlug((string) ($content->publish_url_key ?? ''));
    }

    public function publicationSlug(Content $content, ?Draft $draft = null): string
    {
        $persisted = $this->persistedSlug($content);
        if ($persisted !== '') {
            return $persisted;
        }

        if ($content->isTranslationVariant() && trim((string) $content->title) !== '') {
            return $this->expectedSlug($content);
        }

        foreach ([
            data_get($draft?->meta, 'slug'),
            data_get($content->draftVersion?->meta, 'slug'),
            data_get($content->currentVersion?->meta, 'slug'),
            $content->external_key,
            $draft?->title,
            $content->title,
        ] as $candidate) {
            $slug = $this->normalizeSlug((string) $candidate);
            if ($slug !== '') {
                return $slug;
            }
        }

        return $this->slugFromTitle((string) $content->id, 'post');
    }

    public function needsLocaleRepair(Content $content, bool $forceRegenerate = false): bool
    {
        $current = $this->persistedSlug($content);
        $expected = $this->expectedSlug($content);

        if ($expected === '' || $current === '') {
            return $forceRegenerate && $expected !== '';
        }

        if ($forceRegenerate) {
            return $current !== $expected;
        }

        if ($current === $expected) {
            return false;
        }

        return $this->matchesSourceSlug($content, $current);
    }

    public function matchesSourceSlug(Content $content, string $slug): bool
    {
        $slug = $this->normalizeSlug($slug);
        if ($slug === '' || ! $content->isTranslationVariant()) {
            return false;
        }

        $source = $content->translationSourceContent ?: $content->localizationSource();
        if (! $source || (string) $source->id === (string) $content->id) {
            return false;
        }

        $source->loadMissing('currentVersion');

        $sourceSlugs = collect([
            $source->publish_url_key,
            $source->title,
            data_get($source->currentVersion?->meta, 'slug'),
            $this->slugFromUrl((string) ($source->published_url ?? '')),
        ])
            ->map(fn (mixed $candidate): string => $this->normalizeSlug((string) $candidate))
            ->filter()
            ->unique()
            ->values();

        return $sourceSlugs->contains($slug);
    }

    public function normalizeSlug(string $candidate): string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return '';
        }

        if (filter_var($candidate, FILTER_VALIDATE_URL)) {
            $candidate = $this->slugFromUrl($candidate);
        } elseif (str_contains($candidate, '/')) {
            $candidate = trim((string) basename($candidate), '/');
        }

        return Str::slug($candidate);
    }

    private function slugFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return trim((string) basename($path), '/');
    }
}
