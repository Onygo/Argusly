<?php

namespace App\Services\Integrations;

use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\Draft;
use BackedEnum;
use App\Services\Content\AnswerBlockInjectorService;
use App\Services\Content\AnswerBlockSchemaService;
use App\Services\Content\LocalizedContentSlugService;
use App\Support\ImageAttribution;
use App\Support\SeoMetadata;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class LaravelConnectorPayloadFactory
{
    public function __construct(
        private readonly LocalizedContentSlugService $slugs,
        private readonly AnswerBlockInjectorService $answerBlockInjector,
        private readonly AnswerBlockSchemaService $answerBlockSchema,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function make(Content $content, ContentDestination $destination, ?string $articleStatus = null, array $policy = []): array
    {
        $content->loadMissing([
            'drafts' => fn ($query) => $query->latest('created_at')->limit(1),
            'featuredImage',
            'currentVersion',
            'draftVersion',
            'answerBlocks',
            'series:id,name',
            'seriesArticle:id,series_id,content_id,article_number,is_pillar',
        ]);

        $draft = $content->drafts->first();
        $category = $this->resolveCategory($content, $draft);
        $sourceUpdatedAt = $draft?->updated_at ?: $content->updated_at;
        $status = $articleStatus ?: 'published';

        return [
            'type' => 'knowledge_article',
            'site_id' => $destination->laravelConnectorSiteId() ?: (string) $destination->id,
            'article' => [
                'id' => (string) $content->id,
                'title' => (string) ($draft?->title ?: $content->title),
                'language' => (string) ($draft?->language?->value ?? $content->language?->value ?? 'en'),
                'locale' => $this->resolveLocale($content, $draft),
                'source_locale' => $this->nullableString($content->translation_source_locale),
                'is_translation' => (bool) ($draft?->isTranslation() ?? false),
                'source_draft_id' => $draft?->source_draft_id ? (string) $draft->source_draft_id : null,
                'slug' => $this->resolveSlug($content, $draft),
                'summary' => $this->resolveSummary($content, $draft),
                'content_html' => $this->resolveContentHtml($content, $draft),
                'structured_output' => $this->resolveStructuredOutput($content),
                'answer_blocks' => $this->answerBlockSchema->exportableBlocks($content),
                'faq_schema' => $this->answerBlockSchema->forContent($content),
                'schema' => $this->resolveSchemaPayload($content),
                'seo_title' => $this->resolveSeoField('seo_title', $content, $draft),
                'seo_description' => $this->resolveSeoField('seo_meta_description', $content, $draft),
                'canonical_url' => $this->resolveCanonicalUrl($content, $draft),
                'canonical_content_id' => $this->nullableString($content->translation_source_content_id ?: $content->canonical_content_id ?? null),
                'hreflang_alternates' => $this->resolveHreflangAlternates($content, $draft),
                'x_default_url' => $this->nullableString(data_get($draft?->meta, 'x_default_url') ?: data_get($content->draftVersion?->meta, 'x_default_url')),
                'translation_group_id' => $this->nullableString($content->family_id ?: data_get($draft?->meta, 'translation_group_id')),
                'family_id' => $this->nullableString($content->family_id),
                'ai_visibility' => $this->resolveAiVisibilityMetadata($content, $draft),
                'featured_image_url' => $this->resolveFeaturedImageUrl($content, $draft),
                'featured_image_attribution' => $this->resolveFeaturedImageAttributionText($content),
                'image_attribution' => ImageAttribution::fromContentImage($content->featuredImage),
                'status' => $status,
                'published_at' => optional($content->updated_at)->toIso8601String(),
                'source_updated_at' => optional($sourceUpdatedAt)->toIso8601String(),
                'argusly' => $this->resolveArguslyMetadata($content),
                'category' => $category,
                'chain' => $content->series_id ? [
                    'series_id' => (string) $content->series_id,
                    'series_name' => (string) ($content->series?->name ?? ''),
                    'article_number' => $content->seriesArticle?->article_number ? (int) $content->seriesArticle->article_number : null,
                    'is_pillar' => (bool) ($content->seriesArticle?->is_pillar ?? false),
                    'role' => $content->seriesArticle
                        ? ($content->seriesArticle->is_pillar ? 'pillar' : 'supporting')
                        : null,
                ] : null,
                // Internal links are finalized in content_html before export.
                // Do not export a second related-articles layer that downstream
                // renderers could duplicate.
                'related_articles' => [],
            ],
            'policy' => $this->normalizePolicyPayload($policy),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveCategory(Content $content, ?Draft $draft): ?array
    {
        $candidates = [
            data_get($draft?->meta, 'category'),
            Arr::first((array) data_get($draft?->meta, 'categories', [])),
            Arr::first((array) data_get($draft?->meta, 'taxonomy.categories', [])),
            data_get($content->draftVersion?->meta, 'category'),
            Arr::first((array) data_get($content->draftVersion?->meta, 'categories', [])),
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeCategoryCandidate($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function resolveRelatedArticles(Content $content, ?Draft $draft): array
    {
        $rows = data_get($draft?->meta, 'related_articles');
        if (! is_array($rows)) {
            $rows = data_get($content->draftVersion?->meta, 'related_articles', []);
        }

        return collect(is_array($rows) ? $rows : [])
            ->map(fn ($row) => $this->normalizeRelatedArticle($row))
            ->filter(fn ($row) => is_array($row) && $row['source_argusly_id'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveArguslyMetadata(Content $content): array
    {
        return [
            'external_key' => $this->nullableString($content->external_key),
            'origin_type' => $this->enumValue($content->origin_type),
            'source' => $this->enumValue($content->source),
            'generation_mode' => $this->nullableString($content->generation_mode),
            'automation_id' => $this->nullableString($content->automation_id),
            'automation_run_id' => $this->nullableString($content->automation_run_id),
            'family_id' => $this->nullableString($content->family_id),
            'translation_source_content_id' => $this->nullableString($content->translation_source_content_id),
            'translation_source_locale' => $this->nullableString($content->translation_source_locale),
            'is_source_locale' => (bool) ($content->is_source_locale ?? false),
            'publish_url_key' => $this->nullableString($content->publish_url_key),
            'canonical_url_key' => $this->nullableString($content->canonical_url_key),
            'published_url' => $this->nullableString($content->published_url),
        ];
    }

    private function resolveLocale(Content $content, ?Draft $draft): string
    {
        return (string) ($draft?->language?->value ?? $content->language?->value ?? 'en');
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveStructuredOutput(Content $content): array
    {
        $blocks = $this->answerBlockSchema->exportableBlocks($content);

        return [
            'direct_answer' => data_get($blocks, 'direct_answer'),
            'summary_block' => data_get($blocks, 'summary'),
            'faq_block' => data_get($blocks, 'faq'),
            'comparison_block' => data_get($blocks, 'comparison'),
            'how_to_block' => data_get($blocks, 'how_to'),
            'key_takeaways' => data_get($blocks, 'key_takeaways', []),
            'entity_mentions' => data_get($blocks, 'entity_mentions', []),
            'blocks' => $blocks,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveSchemaPayload(Content $content): array
    {
        return array_filter([
            'Article' => [
                '@type' => 'Article',
                'headline' => $content->title,
            ],
            'FAQPage' => $this->answerBlockSchema->forContent($content),
            'HowTo' => data_get($this->answerBlockSchema->exportableBlocks($content), 'how_to_schema'),
        ], static fn ($value): bool => $value !== null && $value !== []);
    }

    private function resolveCanonicalUrl(Content $content, ?Draft $draft): ?string
    {
        $candidates = [
            $draft?->seo_canonical,
            data_get($draft?->meta, 'canonical_url'),
            $content->seo_canonical ?? null,
            $content->published_url,
            data_get($content->draftVersion?->meta, 'canonical_url'),
        ];

        foreach ($candidates as $candidate) {
            $value = $this->nullableString($candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function resolveHreflangAlternates(Content $content, ?Draft $draft): array
    {
        $rows = data_get($draft?->meta, 'hreflang_alternates', data_get($content->draftVersion?->meta, 'hreflang_alternates', []));

        return collect(is_array($rows) ? $rows : [])
            ->map(function (mixed $row): ?array {
                if (! is_array($row)) {
                    return null;
                }

                $locale = $this->nullableString($row['locale'] ?? $row['hreflang'] ?? null);
                $url = $this->nullableString($row['url'] ?? null);

                return $locale && $url ? ['locale' => $locale, 'url' => $url] : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveAiVisibilityMetadata(Content $content, ?Draft $draft): array
    {
        $meta = data_get($draft?->meta, 'ai_visibility', data_get($content->draftVersion?->meta, 'ai_visibility', []));

        return is_array($meta) ? $meta : [];
    }

    /**
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    private function normalizePolicyPayload(array $policy): array
    {
        return array_replace([
            'execution_mode' => 'guided',
            'action_run_id' => null,
            'approval_status' => 'approved',
            'approved_by' => null,
            'approved_at' => null,
            'autonomous_policy_snapshot' => [],
            'safety_check_status' => 'pass',
            'safety_check_issues' => [],
            'max_allowed_operation' => 'draft',
            'dry_run' => false,
            'idempotency_key' => null,
        ], $policy);
    }

    private function resolveSlug(Content $content, ?Draft $draft): string
    {
        return $this->slugs->publicationSlug($content, $draft);
    }

    private function resolveSummary(Content $content, ?Draft $draft): ?string
    {
        $candidates = [
            data_get($draft?->meta, 'summary'),
            data_get($draft?->meta, 'excerpt'),
            $draft?->seo_meta_description,
            $content->seo_meta_description,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function resolveContentHtml(Content $content, ?Draft $draft): string
    {
        $value = trim((string) ($draft?->content_html ?? ''));
        if ($value !== '') {
            return $this->answerBlockInjector->inject($value, $content);
        }

        $fallback = trim((string) ($content->draftVersion?->body ?? ''));

        return $fallback !== '' ? $this->answerBlockInjector->inject($fallback, $content) : '';
    }

    private function resolveSeoField(string $field, Content $content, ?Draft $draft): ?string
    {
        $resolved = SeoMetadata::resolveForDraftContext($draft, [
            'seo_title' => $content->seo_title,
            'seo_meta_description' => $content->seo_meta_description,
            'seo_og_image' => $content->seo_og_image,
        ]);

        $value = trim((string) ($resolved[$field] ?? $content->{$field} ?? ''));

        return $value !== '' ? $value : null;
    }

    private function resolveFeaturedImageUrl(Content $content, ?Draft $draft): ?string
    {
        $imageUrl = trim((string) ($content->featuredImage?->medium_ui_url ?: $content->featuredImage?->original_ui_url ?: ''));
        if ($imageUrl !== '') {
            return $imageUrl;
        }

        $fallback = trim((string) ($draft?->seo_og_image ?: $content->seo_og_image ?: ''));

        return $fallback !== '' ? $fallback : null;
    }

    private function resolveFeaturedImageAttributionText(Content $content): ?string
    {
        $attribution = ImageAttribution::fromContentImage($content->featuredImage);
        $text = trim((string) ($attribution['text'] ?? ''));

        return $text !== '' ? $text : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return $this->nullableString($value->value);
        }

        return $this->nullableString($value);
    }

    /**
     * @return array<string, string>|null
     */
    private function normalizeCategoryCandidate(mixed $candidate): ?array
    {
        if (is_string($candidate)) {
            $value = trim($candidate);

            return $value !== ''
                ? [
                    'id' => Str::slug($value),
                    'name' => $value,
                    'slug' => Str::slug($value),
                    'description' => '',
                ]
                : null;
        }

        if (! is_array($candidate)) {
            return null;
        }

        $name = trim((string) ($candidate['name'] ?? $candidate['label'] ?? ''));
        $slug = trim((string) ($candidate['slug'] ?? ''));
        $id = trim((string) ($candidate['id'] ?? $slug ?? ''));

        if ($name === '' && $slug === '' && $id === '') {
            return null;
        }

        $resolvedName = $name !== '' ? $name : Str::headline(str_replace('-', ' ', $slug !== '' ? $slug : $id));
        $resolvedSlug = Str::slug($slug !== '' ? $slug : $resolvedName);

        return [
            'id' => $id !== '' ? $id : $resolvedSlug,
            'name' => $resolvedName,
            'slug' => $resolvedSlug,
            'description' => trim((string) ($candidate['description'] ?? '')),
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function normalizeRelatedArticle(mixed $candidate): ?array
    {
        if (! is_array($candidate)) {
            return null;
        }

        $id = trim((string) ($candidate['source_argusly_id'] ?? $candidate['id'] ?? ''));
        $slug = trim((string) ($candidate['slug'] ?? ''));
        $title = trim((string) ($candidate['title'] ?? ''));

        if ($id === '') {
            return null;
        }

        return [
            'source_argusly_id' => $id,
            'slug' => $slug !== '' ? Str::slug($slug) : Str::slug($title !== '' ? $title : $id),
            'title' => $title !== '' ? $title : Str::headline(str_replace('-', ' ', $slug !== '' ? $slug : $id)),
        ];
    }
}
