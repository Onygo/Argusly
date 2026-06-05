<?php

namespace App\Services\Article;

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Models\Workspace;
use App\Services\Content\ContentLifecycleService;
use App\Services\Integrations\LaravelConnectorPublishingService;
use App\Services\Publication\ContentPublicationService;
use App\Services\Publication\LaravelPublicationDestinationResolver;
use App\Services\Publication\WordPressPublicationDestinationResolver;
use App\Support\Connectors\Results\PublicationResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Unified service for article lifecycle management.
 *
 * This service is the single entry point for all article lifecycle operations:
 * - Status transitions (draft → scheduled → publishing → published)
 * - Publishing operations (publish now, schedule, cancel)
 * - Verification operations (verify remote, reconcile)
 *
 * ## Architecture
 *
 * ```
 * ArticleLifecycleService (this service - orchestration)
 *     ├── ContentLifecycleService (versioning, revisions)
 *     ├── ContentPublicationService (publication state, connectors)
 *     └── LaravelConnectorPublishingService (Laravel-specific flow)
 * ```
 *
 * ## Usage
 *
 * ```php
 * // Schedule article for publishing
 * $result = $lifecycleService->schedule($article, $publishAt);
 *
 * // Publish immediately
 * $result = $lifecycleService->publishNow($article);
 *
 * // Cancel scheduled publish
 * $lifecycleService->cancelSchedule($article);
 *
 * // Unpublish from remote
 * $result = $lifecycleService->unpublish($article);
 * ```
 */
class ArticleLifecycleService
{
    public function __construct(
        private readonly ContentLifecycleService $contentLifecycle,
        private readonly ContentPublicationService $publicationService,
        private readonly WordPressPublicationDestinationResolver $destinationResolver,
        private readonly LaravelConnectorPublishingService $laravelPublishingService,
    ) {}

    /**
     * Schedule an article for publishing at a specific time.
     *
     * @return array{scheduled: bool, publish_at: ?string, message: string}
     */
    public function schedule(Content $article, \DateTimeInterface $publishAt): array
    {
        return DB::transaction(function () use ($article, $publishAt): array {
            $article->forceFill([
                'scheduled_publish_at' => $publishAt,
                'publish_status' => 'scheduled',
                'publish_error' => null,
            ])->save();

            return [
                'scheduled' => true,
                'publish_at' => $publishAt->format('c'),
                'message' => 'Article scheduled for publishing.',
            ];
        });
    }

    /**
     * Cancel a scheduled publish.
     *
     * @return array{cancelled: bool, message: string}
     */
    public function cancelSchedule(Content $article): array
    {
        return DB::transaction(function () use ($article): array {
            if ($article->publish_status !== 'scheduled') {
                return [
                    'cancelled' => false,
                    'message' => 'Article is not scheduled for publishing.',
                ];
            }

            $article->forceFill([
                'scheduled_publish_at' => null,
                'publish_status' => 'draft',
            ])->save();

            return [
                'cancelled' => true,
                'message' => 'Scheduled publish cancelled.',
            ];
        });
    }

    /**
     * Publish an article immediately.
     *
     * Routes to the appropriate publishing flow based on site type.
     *
     * @param  array<string, mixed>  $options
     * @return array{published: bool, queued: bool, publication_id: ?string, message: string}
     */
    public function publishNow(Content $article, ?Draft $draft = null, array $options = []): array
    {
        $article->loadMissing('clientSite');
        $siteType = ClientSite::normalizeType((string) ($article->clientSite?->type ?? ''));

        if ($siteType === ClientSite::TYPE_WORDPRESS) {
            return $this->publishToWordPress($article, $draft, $options);
        }

        if ($siteType === ClientSite::TYPE_LARAVEL) {
            return $this->publishToLaravel($article, $draft, $options);
        }

        return [
            'published' => false,
            'queued' => false,
            'publication_id' => null,
            'message' => "Publishing is not supported for site type '{$siteType}'.",
        ];
    }

    /**
     * Republish an article (force delivery even if already published).
     *
     * @param  array<string, mixed>  $options
     * @return array{published: bool, queued: bool, publication_id: ?string, message: string}
     */
    public function republish(Content $article, ?Draft $draft = null, array $options = []): array
    {
        $options['force'] = true;

        return $this->publishNow($article, $draft, $options);
    }

    /**
     * Unpublish an article from its remote destination.
     *
     * @return array{unpublished: bool, message: string}
     */
    public function unpublish(Content $article, ?ContentDestination $destination = null): array
    {
        $article->loadMissing('clientSite');
        $siteType = ClientSite::normalizeType((string) ($article->clientSite?->type ?? ''));

        if ($siteType === ClientSite::TYPE_WORDPRESS) {
            $destination ??= $this->destinationResolver->resolveForContent($article);

            if (! $destination) {
                return [
                    'unpublished' => false,
                    'message' => 'No destination found for unpublishing.',
                ];
            }

            $result = $this->publicationService->unpublish($article, $destination);

            return [
                'unpublished' => $result->isSuccess(),
                'message' => $result->isSuccess()
                    ? 'Article unpublished successfully.'
                    : ($result->errorMessage ?? 'Unpublish failed.'),
            ];
        }

        if ($siteType === ClientSite::TYPE_LARAVEL) {
            try {
                $this->laravelPublishingService->queueRemoteDeletion($article);

                return [
                    'unpublished' => true,
                    'message' => 'Remote deletion queued.',
                ];
            } catch (\Throwable $e) {
                return [
                    'unpublished' => false,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'unpublished' => false,
            'message' => "Unpublishing is not supported for site type '{$siteType}'.",
        ];
    }

    /**
     * Mark an article as published locally (without remote delivery).
     *
     * Use this for content that doesn't need to be pushed to an external system.
     *
     * @return array{published: bool, message: string}
     */
    public function markPublished(Content $article, ?string $publishedUrl = null): array
    {
        return DB::transaction(function () use ($article, $publishedUrl): array {
            $article->forceFill([
                'status' => 'published',
                'publish_status' => 'published',
                'delivery_status' => 'delivered',
                'scheduled_publish_at' => null,
                'publish_error' => null,
                'published_url' => $publishedUrl ?? $article->published_url,
            ])->save();

            return [
                'published' => true,
                'message' => 'Article marked as published.',
            ];
        });
    }

    /**
     * Revert a published article back to draft status.
     *
     * Note: This only updates local status. Use unpublish() to remove from remote.
     *
     * @return array{reverted: bool, message: string}
     */
    public function revertToDraft(Content $article): array
    {
        return DB::transaction(function () use ($article): array {
            $article->forceFill([
                'publish_status' => 'draft',
                'scheduled_publish_at' => null,
            ])->save();

            return [
                'reverted' => true,
                'message' => 'Article reverted to draft status.',
            ];
        });
    }

    /**
     * Get the canonical publication for an article.
     */
    public function getCanonicalPublication(Content $article): ?ContentPublication
    {
        return ContentPublication::query()
            ->where('content_id', $article->id)
            ->orderByRaw("CASE delivery_status WHEN 'delivered' THEN 0 ELSE 1 END")
            ->latest('last_delivered_at')
            ->latest('created_at')
            ->first();
    }

    /**
     * Check if an article can be published.
     *
     * @return array{can_publish: bool, reason: ?string}
     */
    public function canPublish(Content $article): array
    {
        $article->loadMissing('clientSite');

        if (! $article->clientSite) {
            return ['can_publish' => false, 'reason' => 'No site associated with article.'];
        }

        $siteType = ClientSite::normalizeType((string) $article->clientSite->type);
        if (! in_array($siteType, [ClientSite::TYPE_WORDPRESS, ClientSite::TYPE_LARAVEL], true)) {
            return ['can_publish' => false, 'reason' => "Site type '{$siteType}' does not support publishing."];
        }

        $draft = Draft::query()
            ->where('content_id', $article->id)
            ->latest('created_at')
            ->first();

        if (! $draft) {
            return ['can_publish' => false, 'reason' => 'No draft found for article.'];
        }

        if ($article->publish_status === 'publishing') {
            return ['can_publish' => false, 'reason' => 'Article is already being published.'];
        }

        return ['can_publish' => true, 'reason' => null];
    }

    /**
     * @return array{published: bool, queued: bool, publication_id: ?string, message: string}
     */
    private function publishToWordPress(Content $article, ?Draft $draft, array $options): array
    {
        $draft ??= Draft::query()
            ->where('content_id', $article->id)
            ->latest('created_at')
            ->first();

        if (! $draft) {
            return [
                'published' => false,
                'queued' => false,
                'publication_id' => null,
                'message' => 'No draft found for WordPress publishing.',
            ];
        }

        $destination = $this->destinationResolver->resolveForContent($article, $draft);

        // Create/resolve publication record
        $publication = ContentPublication::resolveForDelivery(
            contentId: (string) $article->id,
            destinationId: $destination?->id,
            clientSiteId: $article->client_site_id,
            provider: ContentPublication::PROVIDER_WORDPRESS,
            locale: $article->language,
        );

        // Mark as scheduled and queue job
        DB::transaction(function () use ($article) {
            $article->forceFill([
                'scheduled_publish_at' => now(),
                'publish_status' => 'scheduled',
                'publish_error' => null,
            ])->save();
        });

        $dispatch = $this->publicationService->dispatchWordPressPublication($article, $draft, [
            'source' => 'article.lifecycle.publish',
            'force' => (bool) ($options['force'] ?? false),
        ]);

        return [
            'published' => false,
            'queued' => (bool) ($dispatch['queued'] ?? false),
            'publication_id' => (string) $publication->id,
            'message' => (bool) ($dispatch['queued'] ?? false)
                ? 'WordPress publish job queued.'
                : 'WordPress publication already queued or processed.',
        ];
    }

    /**
     * @return array{published: bool, queued: bool, publication_id: ?string, message: string}
     */
    private function publishToLaravel(Content $article, ?Draft $draft, array $options): array
    {
        $draft ??= Draft::query()
            ->where('content_id', $article->id)
            ->latest('created_at')
            ->first();

        if (! $draft) {
            return [
                'published' => false,
                'queued' => false,
                'publication_id' => null,
                'message' => 'No draft found for Laravel publishing.',
            ];
        }

        try {
            $mode = (string) ($options['mode'] ?? 'publish_now');
            $source = (string) ($options['source'] ?? 'article.lifecycle.publish');
            $destination = app(LaravelPublicationDestinationResolver::class)->resolveForContent($article);

            if ($destination instanceof ContentDestination && $destination->isLaravelConnector()) {
                $dispatch = $this->publicationService->dispatchLaravelPublication($article, $draft, [
                    'source' => $source,
                    'mode' => $mode,
                    'force' => (bool) ($options['force'] ?? false),
                ]);

                $publication = $dispatch['publication'];

                return [
                    'published' => false,
                    'queued' => (bool) ($dispatch['queued'] ?? false),
                    'publication_id' => $publication?->id ? (string) $publication->id : null,
                    'message' => (bool) ($dispatch['queued'] ?? false)
                        ? 'Laravel publication job queued.'
                        : 'Laravel publication was already queued or processed.',
                ];
            }

            $this->laravelPublishingService->publish($article, $draft, $mode, $source);

            // Get the publication that was created
            $publication = ContentPublication::query()
                ->where('content_id', $article->id)
                ->where('provider', ContentPublication::PROVIDER_LARAVEL)
                ->latest('created_at')
                ->first();

            return [
                'published' => true,
                'queued' => false,
                'publication_id' => $publication?->id ? (string) $publication->id : null,
                'message' => 'Article published to Laravel connector.',
            ];
        } catch (\Throwable $e) {
            return [
                'published' => false,
                'queued' => false,
                'publication_id' => null,
                'message' => $e->getMessage(),
            ];
        }
    }
}
