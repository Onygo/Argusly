<?php

namespace App\Services\Publication;

use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\ContentPublishTarget;
use App\Models\Draft;
use App\Services\Content\LocalizedContentSlugService;
use App\Services\Integrations\LaravelConnectorDestinationResolver;
use App\Support\Connectors\Results\PublicationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Bridge service connecting legacy Laravel publishing to the new connector architecture.
 *
 * This service provides backwards compatibility by:
 * - Creating/updating ContentPublication records from legacy flows
 * - Syncing ContentPublishTarget to ContentPublication
 * - Allowing gradual migration from legacy to connector-based publishing
 *
 * ## Migration Path
 *
 * 1. Phase 4B: Use this bridge to sync legacy publishes to ContentPublication
 * 2. Phase 5: Migrate callers to use ContentPublicationService directly
 * 3. Phase 6: Remove bridge and legacy code paths
 */
class LaravelPublicationBridge
{
    public function __construct(
        private readonly LaravelConnectorDestinationResolver $destinationResolver,
        private readonly ContentPublicationService $publicationService,
        private readonly LocalizedContentSlugService $slugs,
    ) {}

    /**
     * Ensure a ContentPublication record exists for a Laravel publication.
     *
     * Call this after a successful legacy publish to sync to the new model.
     */
    public function ensurePublicationRecord(
        Content $content,
        ?ContentDestination $destination = null,
        ?ContentPublishTarget $target = null,
    ): ContentPublication {
        $destination ??= $this->destinationResolver->resolveForContent($content);

        $publication = ContentPublication::resolveForDelivery(
            contentId: (string) $content->id,
            destinationId: $destination?->id,
            clientSiteId: $content->client_site_id,
            provider: ContentPublication::PROVIDER_LARAVEL,
            locale: $content->language,
        );

        // Sync state from target if provided
        if ($target !== null) {
            $this->syncFromTarget($publication, $target, $content);
        }

        return $publication;
    }

    /**
     * Mark a publication as delivered from legacy flow.
     *
     * Call this after SyncLaravelKnowledgeArticleJob succeeds.
     */
    public function markDelivered(
        Content $content,
        ContentDestination $destination,
        ?string $remoteUrl = null,
    ): ContentPublication {
        $publication = ContentPublication::resolveForDelivery(
            contentId: (string) $content->id,
            destinationId: (string) $destination->id,
            clientSiteId: $content->client_site_id,
            provider: ContentPublication::PROVIDER_LARAVEL,
            locale: $content->language,
        );

        // Laravel connector uses content ID as remote reference
        $publication->markDelivered(
            remoteId: (string) $content->id,
            remoteUrl: $remoteUrl ?? $content->published_url,
            remoteType: 'article',
        );

        return $publication;
    }

    /**
     * Mark a publication as failed from legacy flow.
     *
     * Call this after SyncLaravelKnowledgeArticleJob fails.
     */
    public function markFailed(
        Content $content,
        ContentDestination $destination,
        string $errorCode,
        string $errorMessage,
    ): ContentPublication {
        $publication = ContentPublication::resolveForDelivery(
            contentId: (string) $content->id,
            destinationId: (string) $destination->id,
            clientSiteId: $content->client_site_id,
            provider: ContentPublication::PROVIDER_LARAVEL,
            locale: $content->language,
        );

        $publication->markFailed($errorCode, $errorMessage);

        return $publication;
    }

    /**
     * Sync ContentPublishTarget state to ContentPublication.
     */
    public function syncFromTarget(
        ContentPublication $publication,
        ContentPublishTarget $target,
        Content $content,
    ): void {
        DB::transaction(function () use ($publication, $target, $content): void {
            $attributes = $this->buildSyncAttributes($publication, $target, $content);
            $publication->forceFill($attributes);

            $context = $this->syncLogContext($publication, $target, $content);
            Log::info('publication.laravel.sync_from_target.before_save', $context);

            try {
                $publication->save();
            } catch (Throwable $exception) {
                Log::error('publication.laravel.sync_from_target.save_failed', array_merge($context, [
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'failing_attributes' => $publication->getAttributes(),
                    'dirty_attributes' => $publication->getDirty(),
                    'validation_warnings' => $this->validationWarnings($publication, $target, $content),
                ]));

                throw new RuntimeException(sprintf(
                    'Laravel publication sync failed while saving publication %s for %s content %s: %s',
                    (string) ($publication->id ?? 'new'),
                    strtoupper($content->localeCode()),
                    (string) $content->id,
                    $exception->getMessage()
                ), previous: $exception);
            }
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function buildSyncAttributes(
        ContentPublication $publication,
        ContentPublishTarget $target,
        Content $content,
    ): array {
        $syncStatus = $target->sync_status;
        $meta = is_array($target->meta) ? $target->meta : [];
        $publishConfirmation = (string) ($meta['publish_confirmation'] ?? '');

        // For local_only publishes (Laravel sites without a connector destination),
        // content is "delivered" by being marked as published locally.
        $isLocalOnlyPublish = $publishConfirmation === 'local_only';

        $deliveryStatus = match ($syncStatus) {
            'synced' => ContentPublication::STATUS_DELIVERED,
            'failed' => ContentPublication::STATUS_FAILED,
            'deleted' => ContentPublication::STATUS_DELIVERED,
            'pending' => $isLocalOnlyPublish ? ContentPublication::STATUS_DELIVERED : ContentPublication::STATUS_PENDING,
            default => ContentPublication::STATUS_PENDING,
        };

        $remoteStatus = match ($syncStatus) {
            'synced' => 'published',
            'deleted' => 'deleted',
            'pending' => $isLocalOnlyPublish ? ContentPublication::REMOTE_PUBLISHED : null,
            default => null,
        };

        return [
            'remote_id' => $publication->remote_id ?? (string) $content->id,
            'remote_url' => $meta['published_url'] ?? $content->published_url,
            'remote_type' => 'article',
            'remote_status' => $remoteStatus,
            'delivery_status' => $deliveryStatus,
            'last_delivered_at' => $target->last_synced_at,
            'last_error_message' => $meta['last_sync_error'] ?? null,
            'meta' => array_merge(
                is_array($publication->meta) ? $publication->meta : [],
                [
                    'legacy_target_id' => (string) $target->id,
                    'legacy_sync_status' => $syncStatus,
                    'synced_from_target_at' => now()->toIso8601String(),
                ],
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function previewSyncFromTarget(
        ContentPublication $publication,
        ContentPublishTarget $target,
        Content $content,
    ): array {
        $publication = clone $publication;
        $publication->forceFill($this->buildSyncAttributes($publication, $target, $content));

        return [
            'context' => $this->syncLogContext($publication, $target, $content),
            'dirty_attributes' => $publication->getDirty(),
            'validation_warnings' => $this->validationWarnings($publication, $target, $content),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function syncLogContext(
        ContentPublication $publication,
        ContentPublishTarget $target,
        Content $content,
    ): array {
        $locale = ContentPublication::normalizeLocale(
            $publication->locale instanceof \BackedEnum
                ? $publication->locale->value
                : $publication->getRawOriginal('locale')
        ) ?? $content->localeCode();

        return [
            'content_id' => (string) $content->id,
            'publication_id' => (string) ($publication->id ?? ''),
            'target_id' => (string) $target->id,
            'locale' => $locale,
            'source_locale' => trim((string) ($content->translation_source_locale ?? '')),
            'site_id' => (string) ($publication->client_site_id ?? $content->client_site_id ?? $target->client_site_id ?? ''),
            'destination_id' => (string) ($publication->destination_id ?? $content->content_destination_id ?? $target->content_destination_id ?? ''),
            'slug' => $this->slugs->publicationSlug($content),
            'canonical_url' => trim((string) ($content->seo_canonical ?: data_get($target->meta, 'canonical_url', ''))),
            'canonical_path' => (string) parse_url((string) ($content->seo_canonical ?: data_get($target->meta, 'canonical_url', '')), PHP_URL_PATH),
            'external_key' => trim((string) ($content->external_key ?? $target->external_key ?? '')),
            'remote_id' => trim((string) ($publication->remote_id ?? '')),
            'remote_url' => trim((string) ($publication->remote_url ?? '')),
            'remote_status' => trim((string) ($publication->remote_status ?? '')),
            'delivery_status' => trim((string) ($publication->delivery_status ?? '')),
            'dirty_attributes' => $publication->getDirty(),
            'validation_warnings' => $this->validationWarnings($publication, $target, $content),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function validationWarnings(
        ContentPublication $publication,
        ContentPublishTarget $target,
        Content $content,
    ): array {
        $warnings = [];

        if (trim((string) ($publication->content_id ?? '')) === '') {
            $warnings[] = 'publication_content_id_missing';
        }

        if (trim((string) ($publication->provider ?? '')) === '') {
            $warnings[] = 'publication_provider_missing';
        }

        if (ContentPublication::normalizeLocale($publication->locale instanceof \BackedEnum ? $publication->locale->value : $publication->getRawOriginal('locale')) === null) {
            $warnings[] = 'publication_locale_missing';
        }

        if (trim((string) ($publication->destination_id ?? '')) === '' && trim((string) ($publication->client_site_id ?? $content->client_site_id ?? $target->client_site_id ?? '')) === '') {
            $warnings[] = 'publication_destination_and_site_missing';
        }

        if ($this->slugs->publicationSlug($content) === '') {
            $warnings[] = 'content_slug_missing';
        }

        if (trim((string) ($publication->remote_id ?? '')) === '') {
            $warnings[] = 'remote_id_missing';
        }

        return $warnings;
    }

    /**
     * Publish using the new connector system.
     *
     * This is the preferred method for new code. It uses the connector
     * abstraction and ContentPublication as canonical record.
     *
     * @param array<string, mixed> $options
     */
    public function publishViaConnector(
        Content $content,
        ContentDestination $destination,
        ?Draft $draft = null,
        array $options = [],
    ): PublicationResult {
        return $this->publicationService->publish($content, $destination, $draft, $options);
    }

    /**
     * Check destination health using the new connector system.
     */
    public function healthCheck(ContentDestination $destination): array
    {
        $result = $this->publicationService->healthCheck($destination);

        return $result->toArray();
    }
}
