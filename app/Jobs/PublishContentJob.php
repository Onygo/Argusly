<?php

namespace App\Jobs;

use App\Contracts\Publication\PublicationDestinationDriverInterface;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\ContentSeries;
use App\Models\Draft;
use App\Services\Publication\ContentPublicationService;
use App\Services\Publication\PublicationDestinationDriverResolver;
use App\Services\Publication\WordPressPublicationDestinationResolver;
use App\Support\Connectors\ConnectorContract;
use App\Support\Connectors\ConnectorRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PublishContentJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 90;

    public int $uniqueFor = 1800;

    public string $contentId = '';

    public ?string $publicationId = null;

    public ?string $draftId = null;

    public ?string $targetId = null;

    public function __construct(string $contentId, ?string $publicationId = null)
    {
        $this->contentId = $contentId;
        $this->publicationId = $publicationId;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function uniqueId(): string
    {
        return 'publish_content:' . ($this->publicationId ?: $this->contentId);
    }

    public function handle(
        ContentPublicationService $publicationService,
        ?WordPressPublicationDestinationResolver $wordPressDestinationResolver = null,
    ): void
    {
        $publicationId = $this->resolveQueuedPublicationId($publicationService);

        Log::info('publication.delivery.job_started', [
            'content_id' => $this->contentId,
            'publication_id' => $publicationId,
            'draft_id' => $this->draftId,
            'target_id' => $this->targetId,
        ]);

        $publication = $this->resolvePublication($publicationId);
        if (! $publication) {
            Log::warning('publication.delivery.job_skipped', [
                'content_id' => $this->contentId,
                'publication_id' => $publicationId,
                'draft_id' => $this->draftId,
                'target_id' => $this->targetId,
                'reason' => 'publication_unresolved',
            ]);

            return;
        }

        $claim = $this->claimPublication($publicationService, $publication, $publicationId, [
            'job_content_id' => $this->contentId,
            'job_publication_id' => $publicationId,
            'destination_type' => (string) $publication->provider,
        ]);

        if (! ($claim['claimed'] ?? false)) {
            if ((bool) ($claim['invalid'] ?? false)) {
                throw new \RuntimeException('Publication claim failed: ' . (string) ($claim['reason'] ?? 'invalid_state'));
            }

            $driverClass = $this->resolveDriverClass($publication);

            Log::warning('publication.delivery.job_claim_skipped', [
                'content_id' => $this->contentId,
                'publication_id' => (string) $publication->id,
                'reason' => (string) ($claim['reason'] ?? 'claim_not_granted'),
                'destination_type' => (string) $publication->provider,
                'driver_class' => $driverClass,
            ]);

            return;
        }

        /** @var Content $content */
        $content = $claim['content'];
        /** @var ContentPublication $publication */
        $publication = $claim['publication'];

        $draft = $this->resolveDraftForExecution($content);
        if (! $draft) {
            $publicationService->markFailed(
                $content,
                'DRAFT_NOT_FOUND',
                'No draft found for content publishing.',
            );

            throw new \RuntimeException('No draft found for content publishing.');
        }

        $destination = $publication->destination;
        if (! $destination && (string) $publication->provider === ContentPublication::PROVIDER_WORDPRESS) {
            $wordPressDestinationResolver ??= app(WordPressPublicationDestinationResolver::class);
            $destination = $wordPressDestinationResolver->resolveForContent($content, $draft);

            if ($destination && (string) ($publication->destination_id ?? '') !== (string) $destination->id) {
                $publication->forceFill([
                    'destination_id' => (string) $destination->id,
                ])->save();
                $publication = $publication->fresh(['destination']) ?? $publication;
                $destination = $publication->destination ?? $destination;
            }
        }

        if (! $destination) {
            throw new \RuntimeException('Publication destination not found.');
        }

        ['driver' => $driver, 'connector' => $connector, 'driver_class' => $driverClass] = $this->resolveExecutionRuntime(
            $publication,
            $destination,
        );

        if ($connector?->capabilities()->isAsyncOnly) {
            Log::warning('publication.delivery.job_skipped', [
                'content_id' => (string) $publication->content_id,
                'publication_id' => (string) $publication->id,
                'destination_id' => (string) $destination->id,
                'destination_type' => (string) $publication->provider,
                'driver_class' => $driverClass,
                'reason' => 'driver_requires_specialized_job',
            ]);

            return;
        }

        Log::info('publication.delivery.publish_started', [
            'publication_id' => (string) $publication->id,
            'content_id' => (string) $content->id,
            'draft_id' => (string) $draft->id,
            'target_id' => (string) ($destination->id ?? ''),
            'destination_type' => (string) $publication->provider,
            'driver_class' => $driverClass,
            'publication_status' => (string) $publication->delivery_status,
            'content_status' => (string) $content->publish_status,
        ]);

        $result = $publicationService->publish($content, $destination, $draft, publication: $publication);

        if ($result->isSuccess()) {
            Log::info('publication.delivery.publish_completed', [
                'publication_id' => (string) $publication->id,
                'content_id' => (string) $content->id,
                'draft_id' => (string) $draft->id,
                'target_id' => (string) ($destination->id ?? ''),
                'destination_type' => (string) $publication->provider,
                'driver_class' => $driverClass,
                'result' => $result->toArray(),
            ]);

            $content->refresh();
            $this->syncSeriesLifecycle($content);

            if ($publication->provider === ContentPublication::PROVIDER_WORDPRESS) {
                $this->dispatchFeaturedImagePushIfAvailable($content);
            }

            return;
        }

        if ($result->isSkipped()) {
            Log::warning('publication.delivery.publish_skipped', [
                'publication_id' => (string) $publication->id,
                'content_id' => (string) $content->id,
                'draft_id' => (string) $draft->id,
                'target_id' => (string) ($destination->id ?? ''),
                'destination_type' => (string) $publication->provider,
                'driver_class' => $driverClass,
                'result' => $result->toArray(),
            ]);

            return;
        }

        Log::warning('publication.delivery.publish_failed', [
            'publication_id' => (string) $publication->id,
            'content_id' => (string) $content->id,
            'draft_id' => (string) $draft->id,
            'target_id' => (string) ($destination->id ?? ''),
            'destination_type' => (string) $publication->provider,
            'driver_class' => $driverClass,
            'result' => $result->toArray(),
        ]);

        throw new \RuntimeException($result->errorDetails() ?? 'Publication failed');
    }

    public function failed(\Throwable $exception): void
    {
        $content = Content::query()->find($this->contentId);
        if ($content) {
            $content->forceFill([
                'publish_status' => 'failed',
                'publish_error' => $exception->getMessage(),
            ])->save();
        }

        $publication = $this->publicationId
            ? ContentPublication::query()->find($this->publicationId)
            : null;

        if ($publication && (string) $publication->delivery_status === 'processing') {
            $publication->markFailed('JOB_FAILED', $exception->getMessage());
        }
    }

    protected function resolveQueuedPublicationId(ContentPublicationService $publicationService): string
    {
        $publicationId = trim((string) ($this->publicationId ?? ''));

        if ($publicationId !== '') {
            $this->publicationId = $publicationId;

            return $publicationId;
        }

        throw new RuntimeException(
            'PublishContentJob requires a publicationId. Dispatch the job with the canonical publication record id.'
        );
    }

    protected function resolvePublication(string $publicationId): ?ContentPublication
    {
        return ContentPublication::query()
            ->with(['content.clientSite', 'destination'])
            ->find($publicationId);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{
     *   claimed:bool,
     *   invalid:bool,
     *   reason:string,
     *   publication:?ContentPublication,
     *   content:?Content
     * }
     */
    protected function claimPublication(
        ContentPublicationService $publicationService,
        ContentPublication $publication,
        string $publicationId,
        array $context = [],
    ): array {
        return match ((string) $publication->provider) {
            ContentPublication::PROVIDER_WORDPRESS => $publicationService->claimWordPressPublicationForDelivery($publicationId, $context),
            default => $publicationService->claimPublicationForDelivery($publicationId, $context),
        };
    }

    protected function resolveDraftForExecution(Content $content): ?Draft
    {
        if ($this->draftId) {
            $draft = Draft::query()->find($this->draftId);

            if ($draft && (string) $draft->content_id === (string) $content->id) {
                return $draft;
            }
        }

        return Draft::query()
            ->where('content_id', $content->id)
            ->latest('created_at')
            ->first();
    }

    protected function syncSeriesLifecycle(Content $content): void
    {
        $seriesId = (string) ($content->series_id ?? '');
        if ($seriesId === '') {
            return;
        }

        $series = ContentSeries::query()->find($seriesId);
        if (! $series) {
            return;
        }

        $total = (int) Content::query()->where('series_id', $seriesId)->count();
        if ($total < 1) {
            return;
        }

        $published = (int) Content::query()
            ->where('series_id', $seriesId)
            ->where('publish_status', 'published')
            ->count();

        if ($published >= $total) {
            $series->update([
                'status' => ContentSeries::STATUS_PUBLISHED,
                'is_locked' => true,
            ]);

            return;
        }

        $scheduledOrPublishing = (int) Content::query()
            ->where('series_id', $seriesId)
            ->whereIn('publish_status', ['scheduled', 'publishing'])
            ->count();

        if ($scheduledOrPublishing > 0 && ! $series->isPublished()) {
            $series->update([
                'status' => ContentSeries::STATUS_SCHEDULED,
                'is_locked' => false,
            ]);
        }
    }

    protected function dispatchFeaturedImagePushIfAvailable(Content $content): void
    {
        $content->loadMissing('featuredImage');

        if (! $content->featuredImage || (string) $content->featuredImage->status !== 'ready') {
            return;
        }

        PushFeaturedImageToWordPressJob::dispatch((string) $content->id)->onQueue('deliveries');
    }

    /**
     * @return array{
     *   driver:?PublicationDestinationDriverInterface,
     *   connector:?ConnectorContract,
     *   driver_class:?string
     * }
     */
    protected function resolveExecutionRuntime(
        ContentPublication $publication,
        ?ContentDestination $destination = null,
    ): array {
        $driver = null;
        $connector = null;

        try {
            $driver = app(PublicationDestinationDriverResolver::class)->resolveForPublication($publication);
        } catch (\Throwable) {
            $driver = null;
        }

        if ($destination) {
            try {
                $connector = app(ConnectorRegistry::class)->resolveForDestination($destination);
            } catch (\Throwable) {
                $connector = null;
            }
        }

        return [
            'driver' => $driver,
            'connector' => $connector,
            'driver_class' => $driver ? $driver::class : null,
        ];
    }

    protected function resolveDriverClass(ContentPublication $publication): ?string
    {
        return $this->resolveExecutionRuntime($publication)['driver_class'];
    }
}
