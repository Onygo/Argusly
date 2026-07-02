<?php

namespace App\Services\PublicBlog;

use App\Contracts\PublicBlogSource;
use App\Exceptions\PublicBlogSourceUnavailableException;
use App\Models\ContentImage;
use App\Services\Content\AnswerBlockInjectorService;
use App\Services\Content\AnswerBlockSchemaService;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\ContentVersion;
use App\Enums\SupportedLanguage;
use App\Support\SeoMetadata;
use App\Services\Content\LocalizedContentSlugService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Support\SafeMarkdownRenderer;
use Throwable;

class ConnectorSynchronizedBlogSource implements PublicBlogSource
{
    public function __construct(
        private readonly MarketingBlogSourceScope $sourceScope,
        private readonly LocalizedContentSlugService $slugs,
        private readonly AnswerBlockInjectorService $answerBlockInjector,
        private readonly AnswerBlockSchemaService $answerBlockSchema,
        private readonly SafeMarkdownRenderer $markdownRenderer,
    ) {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function fetchPublishedPosts(): array
    {
        try {
            if (! Schema::hasTable('contents') || ! Schema::hasTable('content_versions')) {
                return [];
            }

            $scope = $this->sourceScope->resolve();
            // Root cause: unscoped queries could leak posts from other clients.
            // Fix: require an explicit marketing blog source scope.
            if (! $scope) {
                return [];
            }

            $scopeColumn = $this->sourceScope->localColumnForMode($scope['mode']);
            if (! $scopeColumn || ! Schema::hasColumn('contents', $scopeColumn)) {
                return [];
            }

            $query = Content::query()
                ->with([
                    'currentVersion',
                    'featuredImage' => fn ($imageQuery) => $imageQuery->select([
                        'content_images.id',
                        'content_images.content_id',
                        'content_images.alt_text',
                        'content_images.width',
                        'content_images.height',
                    ]),
                    'publications',
                    'seo',
                    'answerBlocks',
                    'images' => fn ($imageQuery) => $imageQuery
                        ->select([
                            'content_images.id',
                            'content_images.content_id',
                            'content_images.type',
                            'content_images.status',
                            'content_images.is_active',
                            'content_images.image_path',
                            'content_images.image_url',
                            'content_images.medium_path',
                            'content_images.medium_webp_path',
                            'content_images.created_at',
                            'content_images.deleted_at',
                        ])
                        ->reorder()
                        ->where('content_images.type', 'featured')
                        ->where('content_images.status', 'ready')
                        ->orderByDesc('content_images.is_active')
                        ->orderByDesc('content_images.created_at'),
                ])
                ->where('type', 'article')
                ->where(function ($visibilityQuery): void {
                    $visibilityQuery
                        ->where(function ($legacyPublishedQuery): void {
                            $legacyPublishedQuery
                                ->where('status', 'published')
                                ->where(function ($statusQuery): void {
                                    $statusQuery->where('publish_status', 'published')
                                        ->orWhereNull('publish_status')
                                        ->orWhere('publish_status', '');
                                });
                        })
                        ->orWhereHas('publications', function ($query): void {
                            $query
                                ->where('provider', ContentPublication::PROVIDER_LARAVEL)
                                ->where('delivery_status', ContentPublication::STATUS_DELIVERED)
                                ->where(function ($statusQuery): void {
                                    $statusQuery->where('remote_status', ContentPublication::REMOTE_PUBLISHED)
                                        ->orWhereNull('remote_status');
                                });
                        });
                })
                ->whereNotNull('current_version_id')
                ->where(function ($publicationQuery): void {
                    $publicationQuery
                        ->whereDoesntHave('publications')
                        ->orWhereHas('publications', function ($query): void {
                            $query
                                ->where('provider', ContentPublication::PROVIDER_LARAVEL)
                                ->where('delivery_status', ContentPublication::STATUS_DELIVERED)
                                ->where(function ($statusQuery): void {
                                    $statusQuery->where('remote_status', ContentPublication::REMOTE_PUBLISHED)
                                        ->orWhereNull('remote_status');
                                });
                        });
                })
                ->where(function ($scheduleQuery): void {
                    $scheduleQuery->whereNull('scheduled_publish_at')
                        ->orWhere('scheduled_publish_at', '<=', now());
                })
                ->orderByDesc('updated_at')
                ->limit(max(1, (int) config('argusly_connector.public_blog.max_posts', config('argusly.public_blog.max_posts', 300))));
            $query->where($scopeColumn, $scope['id']);

            $rows = $query->get();

            $posts = $rows
                ->map(fn (Content $content): ?array => $this->mapContentToPost($content))
                ->filter()
                ->values();

            return $this->ensureUniqueSlugs($posts)->all();
        } catch (Throwable $e) {
            throw new PublicBlogSourceUnavailableException('The Argusly connector content source is currently unavailable.', previous: $e);
        }
    }

    /**
     * @param Collection<int,array<string,mixed>> $posts
     * @return Collection<int,array<string,mixed>>
     */
    private function ensureUniqueSlugs(Collection $posts): Collection
    {
        $seen = [];

        return $posts->map(function (array $post) use (&$seen): array {
            $slug = (string) ($post['slug'] ?? '');
            $locale = strtolower(trim((string) ($post['locale'] ?? '')));
            if ($slug === '') {
                $slug = (string) Str::slug((string) ($post['title'] ?? 'post'));
            }

            $key = ($locale !== '' ? $locale : 'default') . ':' . $slug;

            if (! isset($seen[$key])) {
                $seen[$key] = 1;
                $post['slug'] = $slug;

                return $post;
            }

            $seen[$key]++;
            $suffix = $seen[$key];
            $post['slug'] = $slug . '-' . $suffix;

            return $post;
        });
    }

    /**
     * @return array<string,mixed>|null
     */
    private function mapContentToPost(Content $content): ?array
    {
        $publication = $this->canonicalPublicPublication($content);
        if ($content->publications->isNotEmpty() && ! $publication instanceof ContentPublication) {
            return null;
        }

        $version = $this->resolveLiveVersion($content, $publication);

        if (! $version) {
            return null;
        }

        $meta = is_array($version->meta) ? $version->meta : [];
        $body = trim((string) ($version->body ?? ''));
        if ($body === '') {
            return null;
        }
        $seo = $this->resolveSeo($content);
        $html = $this->looksLikeHtml($body) ? $body : $this->markdownRenderer->render($body);
        $renderedBody = $this->injectAnswerBlocks($html, $content);

        $title = $this->firstNonEmpty([
            (string) data_get($meta, 'title', ''),
            (string) ($content->title ?? ''),
            (string) ($seo['seo_title'] ?? ''),
        ], 'Untitled');

        $slug = $this->resolveSlug($content, $meta, $title);
        $publishedAt = $this->resolvePublishedAt($content, $meta);

        return [
            'id' => (string) $content->id,
            'slug' => $slug,
            'title' => $title,
            'excerpt' => $this->resolveExcerpt($meta, $body),
            'featured_image' => $this->firstNonEmpty([
                // Canonical source: active featured image version selected in app history restore.
                $this->resolveGeneratedFeaturedImageUrl($content),
                (string) data_get($meta, 'featured_image', ''),
                (string) data_get($meta, 'featured_image_url', ''),
                (string) data_get($meta, 'images.featured', ''),
                (string) data_get($meta, 'hero_image', ''),
            ]),
            'featured_image_alt' => $this->firstNonEmpty([
                (string) ($content->featuredImage?->alt_text ?? ''),
                (string) data_get($meta, 'featured_image_alt', ''),
                (string) data_get($meta, 'image_alt', ''),
                $title,
            ]),
            'featured_image_width' => $content->featuredImage?->width,
            'featured_image_height' => $content->featuredImage?->height,
            'author' => $this->resolveAuthor($meta),
            'published_at' => $publishedAt?->toIso8601String(),
            'published_at_human' => $publishedAt?->toDateString(),
            'locale' => $this->resolveLocale($content, $meta),
            'categories' => $this->normalizeList(data_get($meta, 'categories', data_get($meta, 'taxonomy.categories', []))),
            'tags' => $this->normalizeList(data_get($meta, 'tags', data_get($meta, 'taxonomy.tags', []))),
            'content' => $renderedBody,
            'content_format' => 'html',
            'answer_blocks' => $this->safeAnswerBlocks($content),
            'faq_schema' => $this->safeFaqSchema($content),
            'translation_group' => $content->localizationRootId(),
            'translation_source_content_id' => $content->translation_source_content_id ? (string) $content->translation_source_content_id : null,
            'translation_source_locale' => trim((string) ($content->translation_source_locale ?? '')),
            'is_source_locale' => (bool) ($content->is_source_locale ?? false),
            'translation_generated_at' => $content->translation_generated_at?->toIso8601String(),
            'translation_source_updated_at' => $content->translation_source_updated_at?->toIso8601String(),
            'status' => (string) $content->status,
            'publish_status' => (string) ($content->publish_status ?? ''),
            'publication_id' => $publication?->id ? (string) $publication->id : null,
            'publication_delivered_at' => $publication?->last_delivered_at?->toIso8601String(),
            'seo_title' => trim((string) ($seo['seo_title'] ?? '')),
            'seo_meta_description' => trim((string) ($seo['seo_meta_description'] ?? '')),
            'seo_og_title' => trim((string) ($seo['seo_og_title'] ?? '')),
            'seo_og_description' => trim((string) ($seo['seo_og_description'] ?? '')),
            'seo_twitter_title' => trim((string) ($seo['seo_twitter_title'] ?? '')),
            'seo_twitter_description' => trim((string) ($seo['seo_twitter_description'] ?? '')),
            'robots_index' => $seo['robots_index'],
            'robots_follow' => $seo['robots_follow'],
            'meta_description' => $this->firstNonEmpty([
                (string) ($seo['seo_meta_description'] ?? ''),
                (string) data_get($meta, 'meta_description', ''),
                (string) data_get($meta, 'description', ''),
                (string) data_get($meta, 'excerpt', ''),
            ]),
            'canonical_url' => $this->firstNonEmpty([
                (string) ($content->seo_canonical ?? ''),
                (string) ($content->published_url ?? ''),
                (string) data_get($meta, 'canonical_url', ''),
            ]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveSeo(Content $content): array
    {
        try {
            return SeoMetadata::resolveForContentContext($content);
        } catch (Throwable $exception) {
            Log::warning('public_blog.seo_resolution_failed', [
                'content_id' => (string) ($content->id ?? ''),
                'slug' => (string) ($content->publish_url_key ?? $content->canonical_url_key ?? ''),
                'locale' => method_exists($content, 'localeCode') ? $content->localeCode() : (string) ($content->language ?? ''),
                'message' => $exception->getMessage(),
            ]);

            return [
                'seo_title' => '',
                'seo_meta_description' => '',
                'seo_og_title' => '',
                'seo_og_description' => '',
                'seo_twitter_title' => '',
                'seo_twitter_description' => '',
                'robots_index' => true,
                'robots_follow' => true,
            ];
        }
    }

    private function injectAnswerBlocks(string $html, Content $content): string
    {
        try {
            return $this->answerBlockInjector->inject($html, $content);
        } catch (Throwable $exception) {
            Log::warning('public_blog.answer_blocks.inject_failed', [
                'content_id' => (string) ($content->id ?? ''),
                'slug' => (string) ($content->publish_url_key ?? $content->canonical_url_key ?? ''),
                'locale' => method_exists($content, 'localeCode') ? $content->localeCode() : (string) ($content->language ?? ''),
                'message' => $exception->getMessage(),
            ]);

            return $html;
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function safeAnswerBlocks(Content $content): array
    {
        try {
            return $this->answerBlockSchema->exportableBlocks($content);
        } catch (Throwable $exception) {
            Log::warning('public_blog.answer_blocks.export_failed', [
                'content_id' => (string) ($content->id ?? ''),
                'slug' => (string) ($content->publish_url_key ?? $content->canonical_url_key ?? ''),
                'locale' => method_exists($content, 'localeCode') ? $content->localeCode() : (string) ($content->language ?? ''),
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function safeFaqSchema(Content $content): ?array
    {
        try {
            $schema = $this->answerBlockSchema->forContent($content);

            return is_array($schema) && $schema !== [] ? $schema : null;
        } catch (Throwable $exception) {
            Log::warning('public_blog.answer_blocks.schema_failed', [
                'content_id' => (string) ($content->id ?? ''),
                'slug' => (string) ($content->publish_url_key ?? $content->canonical_url_key ?? ''),
                'locale' => method_exists($content, 'localeCode') ? $content->localeCode() : (string) ($content->language ?? ''),
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function canonicalPublicPublication(Content $content): ?ContentPublication
    {
        $locale = $this->normalizeLocaleCandidate($content->localeCode());

        $candidates = $content->publications
            ->filter(function (ContentPublication $publication) use ($locale): bool {
                $publicationLocale = $this->normalizeLocaleCandidate(
                    $publication->locale instanceof SupportedLanguage
                        ? $publication->locale->value
                        : $publication->getRawOriginal('locale')
                );

                return (string) $publication->provider === ContentPublication::PROVIDER_LARAVEL
                    && (string) $publication->delivery_status === ContentPublication::STATUS_DELIVERED
                    && in_array((string) ($publication->remote_status ?? ContentPublication::REMOTE_PUBLISHED), [
                        '',
                        ContentPublication::REMOTE_PUBLISHED,
                    ], true)
                    && ($publicationLocale === null || $publicationLocale === $locale);
            })
            ->sortByDesc(fn (ContentPublication $publication): int => $publication->last_delivered_at?->getTimestamp()
                ?? $publication->updated_at?->getTimestamp()
                ?? 0)
            ->values();

        if ($candidates->count() > 1) {
            Log::warning('public_blog.publication.duplicate_active_detected', [
                'content_id' => (string) $content->id,
                'locale' => $locale,
                'publication_ids' => $candidates->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            ]);
        }

        return $candidates->first();
    }

    private function resolveLiveVersion(Content $content, ?ContentPublication $publication): ?ContentVersion
    {
        if ($publication instanceof ContentPublication) {
            $snapshot = ContentVersion::query()
                ->where('content_id', $content->id)
                ->where('type', ContentVersion::TYPE_PUBLISHED_SNAPSHOT)
                ->latest('created_at')
                ->first();

            if ($snapshot instanceof ContentVersion) {
                return $snapshot;
            }
        }

        return $content->currentVersion ?: ContentVersion::query()
            ->where('content_id', $content->id)
            ->whereIn('type', [
                ContentVersion::TYPE_PUBLISHED_SNAPSHOT,
                ContentVersion::TYPE_REVISION,
                ContentVersion::TYPE_DRAFT,
            ])
            ->orderByRaw("CASE type WHEN 'published_snapshot' THEN 1 WHEN 'revision' THEN 2 WHEN 'draft' THEN 3 ELSE 4 END")
            ->latest('created_at')
            ->first();
    }

    private function resolveSlug(Content $content, array $meta, string $title): string
    {
        $fallback = $this->firstNonEmpty([
            (string) ($content->publish_url_key ?? ''),
            $content->isTranslationVariant() ? $title : '',
            (string) data_get($meta, 'slug', ''),
            (string) data_get($meta, 'seo.slug', ''),
            (string) data_get($meta, 'client_refs.slug', ''),
            $this->slugFromUrl((string) ($content->published_url ?? '')),
            $title,
            'post',
        ], 'post');

        return $this->slugs->normalizeSlug($fallback) ?: 'post';
    }

    private function slugFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $slug = trim((string) basename($path), '/');

        return $slug;
    }

    private function normalizeSlugCandidate(string $candidate): string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return 'post';
        }

        if (filter_var($candidate, FILTER_VALIDATE_URL)) {
            $candidate = $this->slugFromUrl($candidate);
        } elseif (str_contains($candidate, '/')) {
            $candidate = trim((string) basename($candidate), '/');
        }

        return (string) Str::slug($candidate ?: 'post');
    }

    private function resolvePublishedAt(Content $content, array $meta): ?CarbonImmutable
    {
        $candidates = [
            data_get($meta, 'published_at'),
            data_get($meta, 'publish_at'),
            data_get($meta, 'date_published'),
            $content->scheduled_publish_at?->toIso8601String(),
            $content->updated_at?->toIso8601String(),
            $content->created_at?->toIso8601String(),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            try {
                return CarbonImmutable::parse($candidate);
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function resolveExcerpt(array $meta, string $body): string
    {
        $candidate = $this->firstNonEmpty([
            (string) data_get($meta, 'excerpt', ''),
            (string) data_get($meta, 'summary', ''),
            (string) data_get($meta, 'description', ''),
            (string) data_get($meta, 'meta_description', ''),
        ]);

        if ($candidate !== '') {
            return $candidate;
        }

        return (string) Str::limit(trim(strip_tags($body)), 220, '…');
    }

    private function resolveAuthor(array $meta): ?string
    {
        $author = $this->firstNonEmpty([
            (string) data_get($meta, 'author.name', ''),
            (string) data_get($meta, 'author', ''),
            (string) data_get($meta, 'generated_by', ''),
        ]);

        return $author === '' ? null : $author;
    }

    private function resolveGeneratedFeaturedImageUrl(Content $content): string
    {
        $image = $content->images->first();
        if (! $image) {
            return '';
        }

        $uiMedium = trim((string) ($image->medium_ui_url ?? ''));
        if ($uiMedium !== '') {
            return $uiMedium;
        }

        $directUrl = trim((string) ($image->image_url ?? ''));
        if ($directUrl !== '') {
            return $directUrl;
        }

        $path = trim((string) ($image->image_path ?? ''));
        if ($path === '') {
            return '';
        }

        $disk = (string) config('argusly.images.disk', config('argusly.ai.images.storage_disk', 'content_images'));

        return ContentImage::publicUrlForStorageValue((string) Storage::disk($disk)->url($path));
    }

    private function resolveLocale(Content $content, array $meta): ?string
    {
        $locale = strtolower(trim($this->firstNonEmpty([
            $content->language?->value ?? (string) $content->language,
            (string) ($content->translation_source_locale ?? ''),
            (string) data_get($meta, 'locale', ''),
            (string) data_get($meta, 'language', ''),
            (string) data_get($meta, 'lang', ''),
        ])));

        return SupportedLanguage::tryFromString($locale)?->value;
    }

    private function normalizeLocaleCandidate(mixed $locale): ?string
    {
        return SupportedLanguage::tryFromString((string) $locale)?->value;
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function normalizeList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,|]/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn ($entry) => is_scalar($entry) ? trim((string) $entry) : '')
            ->filter()
            ->values()
            ->all();
    }

    private function looksLikeHtml(string $body): bool
    {
        return preg_match('/<\s*(p|h1|h2|h3|h4|ul|ol|li|blockquote|img|a|div|br)\b/i', $body) === 1;
    }

    /**
     * @param array<int,string> $candidates
     */
    private function firstNonEmpty(array $candidates, string $default = ''): string
    {
        foreach ($candidates as $candidate) {
            $value = trim($candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return $default;
    }
}
