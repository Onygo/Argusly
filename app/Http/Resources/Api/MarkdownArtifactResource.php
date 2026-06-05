<?php

namespace App\Http\Resources\Api;

use App\Models\Content;
use App\Support\SeoMetadata;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class MarkdownArtifactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        /** @var \App\Models\ContentRenderArtifact $artifact */
        $artifact = $this->resource;
        /** @var Content|null $content */
        $content = $artifact->relationLoaded('content') ? $artifact->content : $artifact->content()->with('seo')->first();

        return [
            'content_id' => (string) ($content?->id ?? $artifact->content_id),
            'slug' => $this->resolveSlug($content),
            'locale' => (string) ($artifact->markdown_locale?->value ?? ''),
            'status' => (string) (($content?->publish_status ?: $content?->status) ?? $artifact->markdown_status),
            'rendered_markdown' => (string) ($artifact->rendered_markdown ?? ''),
            'rendered_html' => (string) ($artifact->rendered_html ?? ''),
            'markdown_checksum' => (string) ($artifact->markdown_checksum ?? ''),
            'markdown_generated_at' => optional($artifact->markdown_generated_at)->toIso8601String(),
            'canonical_url' => $this->resolveCanonicalUrl($content),
            'updated_at' => optional($content?->updated_at)->toIso8601String(),
            'aeo_score' => $content?->aeo_score,
        ];
    }

    private function resolveSlug(?Content $content): string
    {
        if (! $content) {
            return '';
        }

        $titleSlug = Str::slug((string) $content->title);
        if ($titleSlug !== '') {
            return $titleSlug;
        }

        $candidate = SeoMetadata::firstNonEmpty([
            (string) ($content->publish_url_key ?? ''),
            (string) ($content->canonical_url_key ?? ''),
            $this->slugFromUrl((string) ($content->published_url ?? '')),
            (string) ($content->external_key ?? ''),
        ]);

        return $candidate ? (string) Str::slug($candidate) : 'content';
    }

    private function resolveCanonicalUrl(?Content $content): ?string
    {
        if (! $content) {
            return null;
        }

        $resolved = SeoMetadata::resolveForContentContext($content);

        return SeoMetadata::firstNonEmpty([
            (string) ($resolved['seo_canonical'] ?? ''),
            (string) ($content->published_url ?? ''),
        ]);
    }

    private function slugFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return trim((string) basename($path), '/');
    }
}
