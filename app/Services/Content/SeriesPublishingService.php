<?php

namespace App\Services\Content;

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublishTarget;
use App\Models\ContentSeries;
use App\Models\Draft;
use App\Models\Event;
use App\Services\Integrations\LaravelConnectorPublishingService;
use App\Services\Publication\ContentPublicationService;
use App\Services\Seo\CanonicalUrlService;
use App\Support\SeoMetadata;
use Illuminate\Support\Str;
use RuntimeException;

class SeriesPublishingService
{
    public function __construct(
        private readonly LaravelConnectorPublishingService $laravelPublishingService,
        private readonly ContentPublicationService $publicationService,
        private readonly CanonicalUrlService $canonicals,
    ) {}

    /**
     * @return array{queued:int,published:int,failed:int}
     */
    public function publish(ContentSeries $series): array
    {
        $series->loadMissing('site', 'contents.seriesArticle', 'contents.localizedVariants.seriesArticle');

        if ($series->isArchived()) {
            throw new RuntimeException('Archived series are read-only.');
        }

        $site = $series->site;
        if (! $site) {
            throw new RuntimeException('Series site is missing.');
        }

        $seriesContents = $series->contents()
            ->with('seriesArticle:id,series_id,content_id,article_number,is_pillar')
            ->orderByRaw("CASE WHEN EXISTS (
                SELECT 1 FROM content_series_articles
                WHERE content_series_articles.content_id = contents.id
                  AND content_series_articles.is_pillar = 1
            ) THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->get();
        if ($seriesContents->isEmpty()) {
            throw new RuntimeException('Generate series articles before publishing.');
        }

        $contents = $this->publishableContentsForSeries($seriesContents)
            ->reject(fn (Content $content): bool => (string) ($content->publish_status ?? '') === 'published')
            ->values();

        if ($contents->isEmpty()) {
            throw new RuntimeException('All generated articles and translations are already published.');
        }

        $siteType = ClientSite::normalizeType((string) $site->type);
        if (! in_array($siteType, [ClientSite::TYPE_WORDPRESS, ClientSite::TYPE_LARAVEL], true)) {
            throw new RuntimeException('Publishing is not supported for this site type.');
        }

        $queued = 0;
        $published = 0;
        $failed = 0;

        foreach ($contents as $content) {
            $draft = Draft::query()
                ->where('content_id', $content->id)
                ->latest('created_at')
                ->first();

            if (! $draft) {
                $content->update([
                    'publish_status' => 'failed',
                    'publish_error' => 'No draft found for series publishing.',
                ]);
                $failed++;
                continue;
            }

            if ($siteType === ClientSite::TYPE_WORDPRESS) {
                $content->update([
                    'client_site_id' => $site->id,
                    'scheduled_publish_at' => null,
                    'publish_error' => null,
                ]);

                $dispatch = $this->publicationService->dispatchWordPressPublication($content, $draft, [
                    'source' => 'series.publish',
                    'series_id' => (string) $series->id,
                ]);

                if ((bool) ($dispatch['queued'] ?? false)) {
                    $queued++;
                }
                continue;
            }

            try {
                $this->laravelPublishingService->publish($content, $draft, 'series_publish', 'content.series.publish');
                $published++;
            } catch (\Throwable $exception) {
                $content->update([
                    'publish_status' => 'failed',
                    'publish_error' => mb_substr($exception->getMessage(), 0, 1500),
                ]);
                $failed++;
            }
        }

        $nextStatus = ContentSeries::STATUS_READY;
        if ($siteType === ClientSite::TYPE_WORDPRESS && $queued > 0) {
            $nextStatus = $series->isPublished()
                ? ContentSeries::STATUS_PUBLISHED
                : ContentSeries::STATUS_SCHEDULED;
        }
        $allPublishableContent = $this->publishableContentsForSeries($seriesContents);
        $allPublished = $allPublishableContent->isNotEmpty()
            && $allPublishableContent->every(fn (Content $content): bool => (string) ($content->publish_status ?? '') === 'published');

        if ($siteType === ClientSite::TYPE_LARAVEL && $allPublished && $failed === 0) {
            $nextStatus = ContentSeries::STATUS_PUBLISHED;
        }

        $history = collect((array) data_get($series->publish_plan_json, 'publish_history', []))
            ->filter(fn ($row) => is_array($row))
            ->values()
            ->all();

        $history[] = [
            'run_at' => now()->toIso8601String(),
            'site_type' => $siteType,
            'queued' => $queued,
            'published' => $published,
            'failed' => $failed,
            'result_status' => $nextStatus,
        ];

        $series->update([
            'status' => $nextStatus,
            'is_locked' => $nextStatus === ContentSeries::STATUS_PUBLISHED,
            'publish_plan_json' => array_merge(
                is_array($series->publish_plan_json) ? $series->publish_plan_json : [],
                [
                    'publish' => [
                        'last_run_at' => now()->toIso8601String(),
                        'site_type' => $siteType,
                        'queued' => $queued,
                        'published' => $published,
                        'failed' => $failed,
                    ],
                    'publish_history' => $history,
                ]
            ),
        ]);

        return [
            'queued' => $queued,
            'published' => $published,
            'failed' => $failed,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int,Content>  $seriesContents
     * @return \Illuminate\Support\Collection<int,Content>
     */
    private function publishableContentsForSeries(\Illuminate\Support\Collection $seriesContents): \Illuminate\Support\Collection
    {
        return $seriesContents
            ->flatMap(function (Content $content): array {
                $content->loadMissing('familyRoot', 'translationSourceContent', 'localizedVariants');

                return $content->normalizedLocalizationFamily()->all();
            })
            ->unique(fn (Content $content): string => (string) $content->id)
            ->sortBy(function (Content $content): array {
                return [
                    (int) ($content->seriesArticle?->article_number ?? 999999),
                    (bool) $content->is_source_locale ? 0 : 1,
                    $content->localeCode(),
                    (string) $content->id,
                ];
            })
            ->values();
    }

    private function publishNowToLaravel(Content $content, Draft $draft, ClientSite $site): void
    {
        $draft->status = 'delivered';
        $draft->delivery_status = 'delivered';
        $draft->delivery_last_error = null;
        $draft->delivered_at = $draft->delivered_at ?: now();
        $draft->acked_at = $draft->acked_at ?: now();
        $draft->save();

        $publishedUrlResolution = $this->resolveLaravelPublishedUrl($content, $draft, $site);
        $publishedUrl = $publishedUrlResolution['url'];
        $seoSnapshot = $this->buildLaravelConnectorSeoSnapshot($draft, $content, $publishedUrl);
        $seoFieldsAvailable = $this->resolveNonEmptySeoFields($seoSnapshot);

        $content->update([
            'publish_status' => 'published',
            'scheduled_publish_at' => null,
            'publish_error' => null,
            'status' => 'published',
            'delivery_status' => 'delivered',
            'published_url' => $publishedUrl,
        ]);

        app(ContentLifecycleService::class)->synchronizePublishedSnapshotFromDraft($draft);

        ContentPublishTarget::query()->updateOrCreate(
            [
                'content_id' => $content->id,
                'client_site_id' => $site->id,
                'target_type' => 'laravel',
            ],
            [
                'target_identifier' => (string) ($content->external_key ?: $content->id),
                'sync_status' => 'pending',
                'last_synced_at' => null,
                'seo_sync_status' => 'pending',
                'seo_synced_at' => null,
                'seo_sync_mode' => 'pull',
                'seo_sync_error' => null,
                'seo_synced_fields' => null,
                'meta' => [
                    'mode' => 'series_publish',
                    'source' => 'content.series.publish',
                    'delivery_model' => 'pull',
                    'publish_confirmation' => 'local_only',
                    'remote_sync_status' => 'pending',
                    'published_url' => $publishedUrl,
                    'published_url_source' => $publishedUrlResolution['source'],
                    'published_url_confirmed' => false,
                    'meta_title' => $seoSnapshot['meta_title'],
                    'meta_description' => $seoSnapshot['meta_description'],
                    'canonical_url' => $seoSnapshot['canonical_url'],
                    'og_image' => $seoSnapshot['og_image'],
                    'primary_keyword' => $seoSnapshot['primary_keyword'],
                    'focus_keyword' => $seoSnapshot['focus_keyword'],
                    'robots_index' => $seoSnapshot['robots_index'],
                    'robots_follow' => $seoSnapshot['robots_follow'],
                    'schema_type' => $seoSnapshot['schema_type'],
                    'seo_fields_available' => $seoFieldsAvailable,
                    'seo' => $seoSnapshot,
                ],
            ]
        );

        Event::query()->create([
            'id' => (string) Str::uuid(),
            'client_site_id' => $site->id,
            'type' => 'publish.local_marked',
            'occurred_at' => now(),
            'data' => [
                'content_id' => $content->id,
                'draft_id' => $draft->id,
                'target' => 'laravel',
                'mode' => 'series_publish',
                'series_id' => $content->series_id,
                'publish_confirmation' => 'local_only',
                'remote_sync_status' => 'pending',
                'published_url_source' => $publishedUrlResolution['source'],
            ],
        ]);
    }

    /**
     * @return array{url:?string,source:string}
     */
    private function resolveLaravelPublishedUrl(Content $content, Draft $draft, ClientSite $site): array
    {
        $contentPublishedUrl = trim((string) ($content->published_url ?? ''));
        if ($contentPublishedUrl !== '') {
            return [
                'url' => $this->canonicals->liveUrlForContent($content, $contentPublishedUrl),
                'source' => 'content.published_url',
            ];
        }

        $draftCanonical = trim((string) ($draft->seo_canonical ?? ''));
        if ($draftCanonical !== '') {
            return [
                'url' => $this->canonicals->liveUrlForContent($content, $draftCanonical),
                'source' => 'draft.seo_canonical',
            ];
        }

        $metaCanonical = trim((string) data_get($draft->meta, 'canonical_url', ''));
        if ($metaCanonical !== '') {
            return [
                'url' => $this->canonicals->liveUrlForContent($content, $metaCanonical),
                'source' => 'draft.meta.canonical_url',
            ];
        }

        $metaPublishedUrl = trim((string) data_get($draft->meta, 'published_url', ''));
        if ($metaPublishedUrl !== '') {
            return [
                'url' => $this->canonicals->liveUrlForContent($content, $metaPublishedUrl),
                'source' => 'draft.meta.published_url',
            ];
        }

        $base = rtrim((string) ($site->site_url ?? ''), '/');
        if ($base !== '') {
            $slug = Str::slug((string) $content->title);

            return [
                'url' => $this->canonicals->liveUrlForContent($content, $base . '/blog/' . $slug, $slug),
                'source' => 'site.slug_guess',
            ];
        }

        return ['url' => null, 'source' => 'none'];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildLaravelConnectorSeoSnapshot(Draft $draft, Content $content, ?string $publishedUrl): array
    {
        $resolved = SeoMetadata::resolveForDraftContext($draft, [
            'canonical_url' => $publishedUrl,
        ]);

        $metaTitle = $resolved['seo_title'] ?: trim((string) $content->title);

        return [
            'primary_keyword' => $resolved['primary_keyword'],
            'focus_keyword' => $resolved['primary_keyword'],
            'meta_title' => $metaTitle !== '' ? $metaTitle : null,
            'meta_description' => $resolved['seo_meta_description'],
            'canonical_url' => $resolved['seo_canonical'] ?: $publishedUrl,
            'og_image' => $resolved['seo_og_image'],
            'seo_title' => $resolved['seo_title'],
            'seo_meta_description' => $resolved['seo_meta_description'],
            'seo_h1' => $resolved['seo_h1'],
            'seo_canonical' => $resolved['seo_canonical'] ?: $publishedUrl,
            'seo_og_title' => $resolved['seo_og_title'],
            'seo_og_description' => $resolved['seo_og_description'],
            'seo_og_image' => $resolved['seo_og_image'],
            'seo_twitter_title' => $resolved['seo_twitter_title'],
            'seo_twitter_description' => $resolved['seo_twitter_description'],
            'robots_index' => $resolved['robots_index'],
            'robots_follow' => $resolved['robots_follow'],
            'schema_type' => $resolved['schema_type'],
        ];
    }

    /**
     * @param array<string,mixed> $seoSnapshot
     * @return array<int,string>
     */
    private function resolveNonEmptySeoFields(array $seoSnapshot): array
    {
        return collect($seoSnapshot)
            ->filter(fn ($value) => is_bool($value) || trim((string) $value) !== '')
            ->keys()
            ->values()
            ->all();
    }
}
