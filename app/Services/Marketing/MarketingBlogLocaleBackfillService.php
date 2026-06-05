<?php

namespace App\Services\Marketing;

use App\Jobs\GenerateMarketingBlogTranslationJob;
use App\Models\Content;
use App\Services\Content\ContentCacheInvalidationService;
use App\Services\PublicBlog\MarketingBlogSourceScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MarketingBlogLocaleBackfillService
{
    public function __construct(
        private readonly MarketingBlogSourceScope $sourceScope,
        private readonly MarketingBlogLocaleDetector $detector,
        private readonly MarketingBlogRedirectService $redirects,
        private readonly MarketingBlogTranslationService $translations,
        private readonly ContentCacheInvalidationService $cacheInvalidation,
    ) {
    }

    /**
     * @param  array{
     *   dry_run:bool,
     *   only_misplaced_en:bool,
     *   generate_en:bool,
     *   publish_en:bool,
     *   limit:?int,
     *   article_id:?string,
     *   force:bool,
     *   queue:bool,
     *   skip_if_en_exists:bool,
     *   refresh_existing_en:bool
     * }  $options
     * @return array<string,mixed>
     */
    public function run(array $options): array
    {
        $scope = $this->sourceScope->resolve();
        if (! $scope) {
            throw new RuntimeException('Marketing blog source is not configured.');
        }

        $scopeColumn = $this->sourceScope->localColumnForMode($scope['mode']);
        if (! $scopeColumn) {
            throw new RuntimeException('Unsupported marketing blog source scope.');
        }

        $contents = $this->queryContents($scopeColumn, $scope['id'], $options);
        $report = [
            'mode' => $options['dry_run'] ? 'dry-run' : 'apply',
            'scope' => $scope,
            'found' => $contents->count(),
            'misplaced_en_detected' => 0,
            'processed' => 0,
            'normalized_to_nl' => 0,
            'redirects_created' => 0,
            'en_generated' => 0,
            'en_published' => 0,
            'skipped' => 0,
            'needs_review' => 0,
            'failed' => 0,
            'articles' => [],
            'review_items' => [],
        ];

        foreach ($contents as $content) {
            $detection = $this->detector->detect($content);
            if ($detection['is_candidate_misplaced_en']) {
                $report['misplaced_en_detected']++;
            }

            if ($options['only_misplaced_en'] && ! $detection['is_candidate_misplaced_en']) {
                $report['skipped']++;
                $report['articles'][] = $this->articleLine($content, $detection, 'skipped', 'Not a misplaced EN article.');
                continue;
            }

            $report['processed']++;

            if ($detection['needs_review'] && ! $options['force']) {
                $report['needs_review']++;
                $report['review_items'][] = $this->reviewLine($content, $detection);
                $report['articles'][] = $this->articleLine($content, $detection, 'review', $detection['reason']);
                continue;
            }

            try {
                $normalization = $this->normalizeSourceVariant($content, $detection, $options);
                if ($normalization['normalized']) {
                    $report['normalized_to_nl']++;
                }

                if ($normalization['redirect_changed']) {
                    $report['redirects_created']++;
                }

                $translationResult = null;
                if ($options['generate_en']) {
                    $translationResult = $this->handleEnglishTranslation(
                        source: $normalization['source_content'],
                        options: $options,
                        dryRunSourceSlug: $normalization['source_slug'],
                    );

                    if (($translationResult['generated'] ?? false) === true) {
                        $report['en_generated']++;
                    }

                    if (($translationResult['published'] ?? false) === true) {
                        $report['en_published']++;
                    }
                }

                if (! $normalization['normalized'] && ! $normalization['redirect_changed'] && ! ($translationResult['changed'] ?? false)) {
                    $report['skipped']++;
                    $report['articles'][] = $this->articleLine($content, $detection, 'skipped', $translationResult['message'] ?? 'No changes required.');
                    continue;
                }

                $report['articles'][] = $this->articleLine(
                    $content,
                    $detection,
                    'ok',
                    $this->buildSuccessMessage($normalization, $translationResult)
                );
            } catch (\Throwable $exception) {
                $report['failed']++;
                $report['articles'][] = $this->articleLine($content, $detection, 'failed', $exception->getMessage());
                Log::error('marketing_blog_backfill.failed', [
                    'content_id' => (string) $content->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (! $options['dry_run']) {
            $this->cacheInvalidation->invalidatePublicContent('marketing.blog_locale_backfill');
        }

        return $report;
    }

    /**
     * @return Collection<int,Content>
     */
    private function queryContents(string $scopeColumn, string $scopeId, array $options): Collection
    {
        $query = Content::query()
            ->with(['currentVersion', 'translationSourceContent', 'localizedVariants'])
            ->where($scopeColumn, $scopeId)
            ->where('type', 'article')
            ->whereNotNull('current_version_id')
            ->orderBy('created_at');

        if ($options['article_id']) {
            $query->whereKey($options['article_id']);
        }

        if ($options['limit']) {
            $query->limit((int) $options['limit']);
        }

        return $query->get();
    }

    /**
     * @return array{normalized:bool,redirect_changed:bool,source_content:Content,source_slug:string}
     */
    private function normalizeSourceVariant(Content $content, array $detection, array $options): array
    {
        $slug = $detection['slug'];
        $published = (string) $content->status === 'published' && (string) ($content->publish_status ?? '') === 'published';
        $alreadyNlSource = $content->localeCode() === 'nl'
            && ! $content->isTranslationVariant()
            && (bool) $content->is_source_locale
            && (($detection['route_locale'] ?? null) === null || ($detection['route_locale'] ?? null) === 'nl');

        $shouldNormalize = $detection['should_normalize_to_nl'] && ! $alreadyNlSource;
        $redirectChanged = false;

        if ($shouldNormalize) {
            $attributes = [
                'language' => 'nl',
                'translation_source_content_id' => null,
                'translation_source_version_id' => null,
                'translation_source_locale' => 'nl',
                'is_source_locale' => true,
                'translation_generated_at' => null,
                'translation_source_updated_at' => null,
                'source_content_updated_at_snapshot' => null,
                'publish_url_key' => $slug,
                'seo_canonical' => $this->redirects->blogUrl('nl', $slug),
                'locale_repair_meta' => $this->buildLocaleRepairMeta($content, $detection),
            ];

            if ($published) {
                $attributes['published_url'] = $this->redirects->blogUrl('nl', $slug);
            }

            if (! $options['dry_run']) {
                DB::transaction(function () use ($content, $attributes): void {
                    $content->forceFill($attributes)->save();
                });
                $content->refresh();
            }
        }

        $legacyLocale = $detection['route_locale'] ?? $detection['stored_locale'];
        if ($published && $legacyLocale === 'en') {
            $redirect = $this->redirects->ensureLegacyRedirect(
                sourceLocale: 'en',
                sourceSlug: $slug,
                targetLocale: 'nl',
                targetSlug: $slug,
                targetContentId: (string) $content->id,
                meta: [
                    'reason' => 'legacy_locale_repair',
                    'confidence' => $detection['confidence'],
                    'stored_locale' => $detection['stored_locale'],
                    'route_locale' => $detection['route_locale'],
                    'text_locale' => $detection['text_locale'],
                ],
                dryRun: $options['dry_run'],
            );

            $redirectChanged = $redirect['changed'];
        }

        return [
            'normalized' => $shouldNormalize,
            'redirect_changed' => $redirectChanged,
            'source_content' => $options['dry_run'] ? $content : ($content->fresh(['currentVersion', 'localizedVariants', 'translationSourceContent']) ?? $content),
            'source_slug' => $slug,
        ];
    }

    /**
     * @return array{generated:bool,published:bool,changed:bool,message:string}
     */
    private function handleEnglishTranslation(Content $source, array $options, string $dryRunSourceSlug): array
    {
        $source = $options['dry_run']
            ? $source
            : ($source->fresh(['currentVersion', 'translationSourceContent', 'localizedVariants']) ?? $source);

        $existingVariant = $source->localizedVariantFor('en');

        if ($existingVariant && ! $options['refresh_existing_en']) {
            if ($options['skip_if_en_exists']) {
                return [
                    'generated' => false,
                    'published' => false,
                    'changed' => false,
                    'message' => 'Skipped EN generation because an EN variant already exists.',
                ];
            }

            return [
                'generated' => false,
                'published' => false,
                'changed' => false,
                'message' => 'Existing EN variant detected; use --refresh-existing-en to regenerate it.',
            ];
        }

        if ($options['dry_run']) {
            return [
                'generated' => true,
                'published' => (bool) $options['publish_en'],
                'changed' => true,
                'message' => sprintf(
                    'Would %s EN variant for NL source slug [%s].',
                    $existingVariant ? 'refresh' : 'generate',
                    $dryRunSourceSlug,
                ),
            ];
        }

        if ($options['queue']) {
            GenerateMarketingBlogTranslationJob::dispatch(
                sourceContentId: (string) $source->id,
                publish: (bool) $options['publish_en'],
                refreshExisting: (bool) $options['refresh_existing_en'],
            )->afterCommit();

            return [
                'generated' => true,
                'published' => (bool) $options['publish_en'],
                'changed' => true,
                'message' => sprintf(
                    'Queued EN %s for source [%s].',
                    $existingVariant ? 'refresh' : 'generation',
                    (string) $source->id,
                ),
            ];
        }

        $result = $this->translations->generateEnglishVariant(
            source: $source,
            publish: (bool) $options['publish_en'],
            refreshExisting: (bool) $options['refresh_existing_en'],
            existingVariant: $existingVariant,
        );

        $generated = in_array($result['action'], ['created', 'refreshed'], true);

        return [
            'generated' => $generated,
            'published' => (bool) ($result['published'] ?? false),
            'changed' => (bool) ($result['changed'] ?? false),
            'message' => $generated
                ? sprintf('EN variant %s as [%s].', $result['action'], (string) ($result['slug'] ?? ''))
                : 'Existing EN variant left unchanged.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildLocaleRepairMeta(Content $content, array $detection): array
    {
        $existing = is_array($content->locale_repair_meta) ? $content->locale_repair_meta : [];

        return array_merge($existing, [
            'repair_type' => 'legacy_locale_repair',
            'normalized_from' => $detection['stored_locale'] ?? $detection['route_locale'] ?? 'unknown',
            'normalized_to' => 'nl',
            'legacy_route_locale' => $detection['route_locale'],
            'detected_text_locale' => $detection['text_locale'],
            'detector_confidence' => $detection['confidence'],
            'first_repaired_at' => data_get($existing, 'first_repaired_at') ?: Carbon::now()->toIso8601String(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function reviewLine(Content $content, array $detection): array
    {
        return [
            'content_id' => (string) $content->id,
            'title' => (string) $content->title,
            'slug' => $detection['slug'],
            'stored_locale' => $detection['stored_locale'] ?? '',
            'route_locale' => $detection['route_locale'] ?? '',
            'detected_locale' => $detection['text_locale'],
            'confidence' => $detection['confidence'],
            'reason' => $detection['reason'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function articleLine(Content $content, array $detection, string $status, string $message): array
    {
        return [
            'content_id' => (string) $content->id,
            'title' => (string) $content->title,
            'slug' => $detection['slug'],
            'status' => $status,
            'message' => $message,
        ];
    }

    private function buildSuccessMessage(array $normalization, ?array $translationResult): string
    {
        $parts = [];

        if ($normalization['normalized']) {
            $parts[] = 'normalized to NL source';
        }

        if ($normalization['redirect_changed']) {
            $parts[] = 'legacy EN redirect recorded';
        }

        if ($translationResult && ($translationResult['message'] ?? '') !== '') {
            $parts[] = $translationResult['message'];
        }

        return implode('; ', $parts);
    }

}
