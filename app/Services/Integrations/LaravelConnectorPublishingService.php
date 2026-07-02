<?php

namespace App\Services\Integrations;

use App\Events\Agents\ContentPublished;
use App\Jobs\Integrations\SyncLaravelKnowledgeArticleJob;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublishTarget;
use App\Models\Draft;
use App\Models\Event;
use App\Services\Content\ContentLifecycleService;
use App\Services\HumanContent\HumanContentGate;
use App\Services\Publication\LaravelPublicationBridge;
use App\Services\Publication\PublicationLegacyCompatibilityService;
use App\Services\Seo\CanonicalUrlService;
use App\Support\SeoMetadata;
use Illuminate\Support\Str;
use RuntimeException;

class LaravelConnectorPublishingService
{
    public function __construct(
        private readonly LaravelConnectorDestinationResolver $destinationResolver,
        private readonly LaravelPublicationBridge $publicationBridge,
        private readonly PublicationLegacyCompatibilityService $legacyCompatibility,
        private readonly CanonicalUrlService $canonicals,
        private readonly HumanContentGate $humanContentGate,
    ) {}

    public function publish(Content $content, ?Draft $draft = null, string $mode = 'publish_now', string $source = 'app.content.publish-now', array $context = []): ContentPublishTarget
    {
        $content->loadMissing('clientSite', 'contentDestination');
        $draft ??= Draft::query()
            ->where('content_id', $content->id)
            ->latest('created_at')
            ->first();

        if (! $draft) {
            throw new RuntimeException('No draft found for Laravel publishing.');
        }

        $gate = $this->humanContentGate->applyManualPublicationOverride(
            $draft,
            $content,
            $this->humanContentGate->evaluate($draft, $content),
            array_merge([
                'source' => $source,
                'mode' => $mode,
            ], $context)
        );

        if (! $gate['passed']) {
            $this->humanContentGate->markDraft($draft, $content);

            throw new RuntimeException($this->humanContentGate->message($gate));
        }

        $destination = $this->destinationResolver->resolveForContent($content);

        $draft->forceFill([
            'status' => 'delivered',
            'delivery_status' => 'delivered',
            'delivery_last_error' => null,
            'delivered_at' => $draft->delivered_at ?: now(),
            'acked_at' => $draft->acked_at ?: now(),
        ])->save();

        $publishedUrlResolution = $this->resolvePublishedUrl($content, $draft);
        $publishedUrl = $publishedUrlResolution['url'];
        $seoSnapshot = $this->buildSeoSnapshot($draft, $content, $publishedUrl);
        $seoFieldsAvailable = $this->resolveNonEmptySeoFields($seoSnapshot);

        $content->forceFill([
            'content_destination_id' => $destination?->id ?: $content->content_destination_id,
            'publish_status' => 'published',
            'scheduled_publish_at' => null,
            'publish_error' => null,
            'status' => 'published',
            'delivery_status' => 'delivered',
            'published_url' => $publishedUrl,
        ])->save();

        app(ContentLifecycleService::class)->synchronizePublishedSnapshotFromDraft($draft);

        $target = ContentPublishTarget::query()->updateOrCreate(
            [
                'content_id' => (string) $content->id,
                'content_destination_id' => $destination?->id,
                'client_site_id' => $content->client_site_id,
                'target_type' => $destination ? 'laravel_connector' : 'laravel',
            ],
            [
                'target_identifier' => (string) ($content->external_key ?: $content->id),
                'sync_status' => $destination ? 'queued' : 'pending',
                'last_synced_at' => null,
                'seo_sync_status' => 'pending',
                'seo_synced_at' => null,
                'seo_sync_mode' => $destination ? 'push' : 'pull',
                'seo_sync_error' => null,
                'seo_synced_fields' => null,
                'meta' => [
                    'mode' => $mode,
                    'source' => $source,
                    'destination_id' => $destination?->id,
                    'delivery_model' => $destination ? 'push' : 'pull',
                    'publish_confirmation' => $destination ? 'queued' : 'local_only',
                    'remote_sync_status' => $destination ? 'queued' : 'pending',
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

        $publication = $this->publicationBridge->ensurePublicationRecord($content, $destination, $target);
        $this->legacyCompatibility->sync($publication);

        Event::query()->create([
            'id' => (string) Str::uuid(),
            'client_site_id' => $content->client_site_id,
            'type' => 'publish.local_marked',
            'occurred_at' => now(),
            'data' => [
                'content_id' => $content->id,
                'draft_id' => $draft->id,
                'target' => $destination ? 'laravel_connector' : 'laravel',
                'destination_id' => $destination?->id,
                'mode' => $mode,
                'publish_confirmation' => $destination ? 'queued' : 'local_only',
                'remote_sync_status' => $destination ? 'queued' : 'pending',
                'published_url_source' => $publishedUrlResolution['source'],
            ],
        ]);

        if ($destination instanceof ContentDestination) {
            SyncLaravelKnowledgeArticleJob::dispatch((string) $content->id, $source)->onQueue('deliveries');
        } else {
            // Only trigger published-content workflows when the content is actually live.
            // Connector-backed publishes emit the event later from the confirmed remote publish path.
            ContentPublished::dispatch(
                contentId: (string) $content->id,
                draftId: (string) $draft->id,
                source: $source,
            );
        }

        return $target->fresh();
    }

    public function queueRemoteDeletion(Content $content, string $source = 'app.content.unpublish-remote'): ContentPublishTarget
    {
        $content->loadMissing('clientSite', 'contentDestination');

        $destination = $this->destinationResolver->resolveForContent($content);
        if (! $destination instanceof ContentDestination) {
            throw new RuntimeException('No Laravel connector destination is configured for this content.');
        }

        $existingTarget = ContentPublishTarget::query()
            ->where('content_id', (string) $content->id)
            ->where('content_destination_id', $destination->id)
            ->where('target_type', 'laravel_connector')
            ->first();

        $target = ContentPublishTarget::query()->updateOrCreate(
            [
                'content_id' => (string) $content->id,
                'content_destination_id' => $destination->id,
                'client_site_id' => $content->client_site_id,
                'target_type' => 'laravel_connector',
            ],
            [
                'target_identifier' => (string) ($content->external_key ?: $content->id),
                'sync_status' => 'queued',
                'last_synced_at' => null,
                'seo_sync_status' => 'pending',
                'seo_synced_at' => null,
                'seo_sync_mode' => 'push',
                'seo_sync_error' => null,
                'seo_synced_fields' => null,
                'meta' => array_merge(
                    is_array($existingTarget?->meta) ? $existingTarget->meta : [],
                    [
                        'mode' => 'remote_delete',
                        'source' => $source,
                        'destination_id' => $destination->id,
                        'delivery_model' => 'push',
                        'publish_confirmation' => 'queued',
                        'remote_sync_status' => 'queued',
                        'pending_operation' => 'deleted',
                    ]
                ),
            ]
        );

        $publication = $this->publicationBridge->ensurePublicationRecord($content, $destination, $target);
        $this->legacyCompatibility->sync($publication);

        SyncLaravelKnowledgeArticleJob::dispatch((string) $content->id, $source, 'deleted')->onQueue('deliveries');

        return $target->fresh();
    }

    /**
     * @return array{url:?string,source:string}
     */
    private function resolvePublishedUrl(Content $content, Draft $draft): array
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

        $base = rtrim((string) ($content->clientSite?->site_url ?? ''), '/');
        if ($base !== '') {
            $slug = Str::slug((string) $content->title);

            return [
                'url' => $this->canonicals->liveUrlForContent($content, $base.'/blog/'.$slug, $slug),
                'source' => 'site.slug_guess',
            ];
        }

        return ['url' => null, 'source' => 'none'];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSeoSnapshot(Draft $draft, Content $content, ?string $publishedUrl): array
    {
        $resolved = SeoMetadata::resolveForDraftContext($draft, [
            'canonical_url' => $publishedUrl,
        ]);

        $metaTitle = $resolved['seo_title'] ?: trim((string) $content->title);
        $metaDescription = $resolved['seo_meta_description'] ?: trim((string) ($content->seo_meta_description ?? ''));
        $canonicalUrl = $resolved['seo_canonical'] ?: $publishedUrl;
        $ogImage = $resolved['seo_og_image'] ?: trim((string) ($content->seo_og_image ?? ''));
        $primaryKeyword = trim((string) ($content->primary_keyword ?? ''));

        return [
            'meta_title' => $metaTitle !== '' ? $metaTitle : null,
            'meta_description' => $metaDescription !== '' ? $metaDescription : null,
            'canonical_url' => $canonicalUrl !== '' ? $canonicalUrl : null,
            'og_image' => $ogImage !== '' ? $ogImage : null,
            'primary_keyword' => $primaryKeyword !== '' ? $primaryKeyword : null,
            'focus_keyword' => $primaryKeyword !== '' ? $primaryKeyword : null,
            'robots_index' => $resolved['robots_index'],
            'robots_follow' => $resolved['robots_follow'],
            'schema_type' => $resolved['schema_type'] ?: null,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, string>
     */
    private function resolveNonEmptySeoFields(array $snapshot): array
    {
        return collect($snapshot)
            ->filter(fn ($value) => is_bool($value) || trim((string) $value) !== '')
            ->keys()
            ->values()
            ->all();
    }
}
