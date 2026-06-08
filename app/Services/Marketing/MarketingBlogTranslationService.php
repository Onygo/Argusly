<?php

namespace App\Services\Marketing;

use App\Enums\ContentSource;
use App\Enums\SupportedLanguage;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\ContentSeo;
use App\Models\ContentVersion;
use App\Services\Content\ContentLocalizationService;
use App\Support\ContentPersistencePayloadNormalizer;
use App\Support\TitleSanitizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class MarketingBlogTranslationService
{
    public function __construct(
        private readonly MarketingBlogTranslationGenerator $generator,
        private readonly MarketingBlogRedirectService $redirects,
        private readonly ContentLocalizationService $localizations,
    ) {
    }

    /**
     * @return array{action:string,content_id:?string,slug:?string,published:bool,changed:bool}
     */
    public function generateEnglishVariant(
        Content $source,
        bool $publish,
        bool $refreshExisting = false,
        ?Content $existingVariant = null,
    ): array {
        $source = $this->localizations->source($source)->fresh(['currentVersion']) ?? $source;
        $source->loadMissing('currentVersion');

        if ($source->localeCode() !== SupportedLanguage::NL->value || ! (bool) $source->is_source_locale) {
            throw new RuntimeException('Marketing blog translations must be generated from a Dutch source variant.');
        }

        $existingVariant = $existingVariant ?: $this->localizations->variantForLocale($source, SupportedLanguage::EN->value);

        if ($existingVariant && ! $refreshExisting) {
            return [
                'action' => 'existing',
                'content_id' => (string) $existingVariant->id,
                'slug' => (string) ($existingVariant->publish_url_key ?? ''),
                'published' => (string) $existingVariant->publish_status === 'published' && (string) $existingVariant->status === 'published',
                'changed' => false,
            ];
        }

        $payload = $this->generator->generate($source);
        $titleResult = TitleSanitizer::normalizeWithMetadata(
            $payload['title'] ?? $source->title ?? 'English translation',
            fallback: 'English translation'
        );
        $payload['title'] = $titleResult['title'];

        if ($titleResult['was_shortened']) {
            Log::notice('marketing_blog_translation.title_shortened', [
                'source_content_id' => (string) $source->id,
                'existing_variant_id' => $existingVariant?->id ? (string) $existingVariant->id : null,
                'original_length' => $titleResult['original_length'],
                'persisted_length' => $titleResult['persisted_length'],
                'max_length' => $titleResult['max_length'],
            ]);
        }

        return DB::transaction(function () use ($source, $publish, $existingVariant, $payload, $titleResult): array {
            $snapshot = $this->sourceSnapshotTimestamp($source);
            $publishedAt = $this->resolvePublishedAt($publish, $existingVariant);
            $slug = $this->ensureUniqueSlug(
                candidate: (string) ($payload['slug'] ?? ''),
                locale: SupportedLanguage::EN->value,
                workspaceId: (string) $source->workspace_id,
                ignoreContentId: $existingVariant?->id,
                fallbackTitle: (string) ($payload['title'] ?? $source->title ?? 'english-translation'),
            );

            $canonicalUrl = $this->redirects->blogUrl(SupportedLanguage::EN->value, $slug);
            $currentMeta = is_array($source->currentVersion?->meta) ? $source->currentVersion->meta : [];
            $versionMeta = array_merge($this->passthroughMeta($currentMeta), [
                'excerpt' => (string) ($payload['excerpt'] ?? ''),
                'slug' => $slug,
                'locale' => SupportedLanguage::EN->value,
                'language' => SupportedLanguage::EN->value,
                'published_at' => $publishedAt?->toIso8601String(),
                'translation' => [
                    'source_content_id' => (string) $source->id,
                    'source_locale' => SupportedLanguage::NL->value,
                    'generated_by' => 'marketing_blog_translation',
                    'original_title' => $titleResult['was_shortened'] ? $titleResult['original_title'] : null,
                    'title_shortened' => $titleResult['was_shortened'],
                ],
            ]);

            $contentAttributes = ContentPersistencePayloadNormalizer::normalize([
                'workspace_id' => (string) $source->workspace_id,
                'client_site_id' => $source->client_site_id,
                'content_destination_id' => $source->content_destination_id,
                'series_id' => $source->series_id,
                'title' => (string) ($payload['title'] ?? $source->title),
                'language' => SupportedLanguage::EN->value,
                'translation_source_content_id' => (string) $source->id,
                'translation_source_version_id' => $source->current_version_id,
                'translation_source_locale' => SupportedLanguage::NL->value,
                'is_source_locale' => false,
                'translation_generated_at' => now(),
                'translation_source_updated_at' => $snapshot,
                'source_content_updated_at_snapshot' => $snapshot,
                'seo_title' => (string) ($payload['seo_title'] ?? $payload['title'] ?? $source->title),
                'seo_meta_description' => (string) ($payload['meta_description'] ?? ''),
                'seo_h1' => (string) ($payload['title'] ?? $source->title),
                'seo_canonical' => $canonicalUrl,
                'seo_og_title' => $payload['seo_og_title'] ?? null,
                'seo_og_description' => $payload['seo_og_description'] ?? null,
                'seo_og_image' => $source->seo_og_image,
                'seo_twitter_title' => $payload['seo_og_title'] ?? ($payload['seo_title'] ?? null),
                'seo_twitter_description' => $payload['seo_og_description'] ?? ($payload['meta_description'] ?? null),
                'robots_index' => $source->robots_index,
                'robots_follow' => $source->robots_follow,
                'schema_type' => $source->schema_type,
                'primary_keyword' => $payload['primary_keyword'] ?? null,
                'type' => (string) $source->type,
                'status' => $publish ? 'published' : 'draft',
                'source' => $source->source instanceof ContentSource
                    ? $source->source->value
                    : (string) $source->source,
                'delivery_status' => 'pending',
                'publish_status' => $publish ? 'published' : 'draft',
                'publish_error' => null,
                'published_url' => $publish ? $canonicalUrl : null,
                'publish_url_key' => $slug,
                'generation_mode' => (string) $source->generation_mode,
                'brand_voice_id' => $source->brand_voice_id,
                'team_member_id' => $source->team_member_id,
                'preferred_length' => $source->preferred_length,
            ]);

            $body = trim((string) ($payload['body_html'] ?? ''));
            if ($body === '') {
                throw new RuntimeException('Generated English translation body is empty.');
            }

            if ($existingVariant) {
                $changed = $this->updateExistingVariant($existingVariant, $contentAttributes, $body, $versionMeta, $payload);

                return [
                    'action' => 'refreshed',
                    'content_id' => (string) $existingVariant->id,
                    'slug' => $slug,
                    'published' => $publish,
                    'changed' => $changed,
                ];
            }

            $content = Content::query()->create(array_merge($contentAttributes, [
                'id' => (string) Str::uuid(),
            ]));

            $this->upsertSeoMirror($content, $payload);
            $this->createContentVersionAndRevision($content, $body, $versionMeta);

            return [
                'action' => 'created',
                'content_id' => (string) $content->id,
                'slug' => $slug,
                'published' => $publish,
                'changed' => true,
            ];
        });
    }

    /**
     * @param  array<string,mixed>  $contentAttributes
     * @param  array<string,mixed>  $versionMeta
     * @param  array<string,mixed>  $payload
     */
    private function updateExistingVariant(
        Content $existingVariant,
        array $contentAttributes,
        string $body,
        array $versionMeta,
        array $payload,
    ): bool {
        $existingVariant->loadMissing('currentVersion');

        $currentBody = trim((string) ($existingVariant->currentVersion?->body ?? ''));
        $currentMeta = is_array($existingVariant->currentVersion?->meta) ? $existingVariant->currentVersion->meta : [];
        $contentChanged = $this->hasContentAttributeChanges($existingVariant, $contentAttributes);
        $versionChanged = $currentBody !== $body || $this->normalizeVersionMeta($currentMeta) !== $this->normalizeVersionMeta($versionMeta);

        if (! $contentChanged && ! $versionChanged) {
            return false;
        }

        $existingVariant->forceFill($contentAttributes)->save();
        $this->upsertSeoMirror($existingVariant, $payload);

        if ($versionChanged) {
            $this->createContentVersionAndRevision($existingVariant, $body, $versionMeta);
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function upsertSeoMirror(Content $content, array $payload): void
    {
        ContentSeo::query()->updateOrCreate(
            ['content_id' => $content->id],
            [
                'meta_title' => (string) ($payload['seo_title'] ?? $payload['title'] ?? $content->title),
                'meta_description' => (string) ($payload['meta_description'] ?? ''),
                'primary_keyword' => $payload['primary_keyword'] ?? null,
                'secondary_keywords' => $payload['secondary_keywords'] ?? [],
                'robots_index' => $content->robots_index,
                'robots_follow' => $content->robots_follow,
                'schema_type' => $content->schema_type,
            ]
        );
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function createContentVersionAndRevision(Content $content, string $body, array $meta): void
    {
        $nextRevisionNumber = ((int) ContentRevision::query()
            ->where('content_id', $content->id)
            ->max('revision_number')) + 1;

        ContentRevision::query()
            ->where('content_id', $content->id)
            ->update(['is_active' => false]);

        $revision = ContentRevision::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => (string) $content->id,
            'draft_id' => null,
            'revision_number' => $nextRevisionNumber,
            'label' => 'R' . $nextRevisionNumber,
            'content_html' => $body,
            'meta' => $meta,
            'is_active' => true,
        ]);

        $version = ContentVersion::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => (string) $content->id,
            'type' => $content->current_version_id ? ContentVersion::TYPE_REVISION : ContentVersion::TYPE_DRAFT,
            'parent_version_id' => $content->current_version_id,
            'body' => $body,
            'meta' => $meta,
            'source' => ContentVersion::SOURCE_ARGUSLY,
        ]);

        $content->forceFill([
            'current_revision_id' => (string) $revision->id,
            'current_version_id' => (string) $version->id,
        ])->save();
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function passthroughMeta(array $meta): array
    {
        return collect($meta)
            ->only(['author', 'categories', 'tags', 'featured_image', 'featured_image_url', 'hero_image'])
            ->all();
    }

    private function sourceSnapshotTimestamp(Content $source): Carbon
    {
        return $source->currentVersion?->updated_at
            ?: $source->currentVersion?->created_at
            ?: $source->updated_at
            ?: now();
    }

    private function resolvePublishedAt(bool $publish, ?Content $existingVariant): ?Carbon
    {
        if (! $publish) {
            return null;
        }

        $existingMeta = is_array($existingVariant?->currentVersion?->meta) ? $existingVariant->currentVersion->meta : [];
        $existingPublishedAt = trim((string) data_get($existingMeta, 'published_at', ''));

        if ($existingPublishedAt !== '') {
            try {
                return Carbon::parse($existingPublishedAt);
            } catch (\Throwable) {
                // Fall through to now().
            }
        }

        return now();
    }

    private function ensureUniqueSlug(
        string $candidate,
        string $locale,
        string $workspaceId,
        ?string $ignoreContentId = null,
        string $fallbackTitle = 'english-translation',
    ): string {
        $base = Str::slug($candidate) ?: Str::slug($fallbackTitle) ?: 'english-translation';
        $slug = $base;
        $suffix = 2;

        while (
            Content::query()
                ->where('workspace_id', $workspaceId)
                ->where('language', $locale)
                ->where('publish_url_key', $slug)
                ->when($ignoreContentId, fn ($query) => $query->where('id', '!=', $ignoreContentId))
                ->exists()
        ) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function hasContentAttributeChanges(Content $content, array $attributes): bool
    {
        foreach ($attributes as $key => $value) {
            if ($key === 'translation_generated_at') {
                continue;
            }

            $current = $content->getAttribute($key);

            if ($current instanceof \BackedEnum) {
                $current = $current->value;
            }

            if ($current instanceof Carbon) {
                $current = $current->toIso8601String();
            }

            if ($value instanceof Carbon) {
                $value = $value->toIso8601String();
            }

            if ((string) $current !== (string) $value && $current !== $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function normalizeVersionMeta(array $meta): array
    {
        ksort($meta);

        return $meta;
    }
}
