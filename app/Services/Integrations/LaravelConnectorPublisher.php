<?php

namespace App\Services\Integrations;

use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentDestinationSyncAttempt;
use App\Models\ContentPublishTarget;
use App\Models\Event;
use App\Services\Publication\ContentPublicationService;
use App\Support\Connectors\Results\PublicationResult;
use Illuminate\Support\Str;
use RuntimeException;

class LaravelConnectorPublisher
{
    public function __construct(
        private readonly LaravelConnectorPayloadFactory $payloadFactory,
        private readonly ContentPublicationService $publicationService,
    ) {}

    public function publishKnowledgeArticle(
        ContentDestination $destination,
        Content $content,
        ContentPublishTarget $publishTarget,
        string $triggerSource,
        int $attempt = 1,
        ?string $articleStatus = null,
    ): ContentDestinationSyncAttempt {
        $syncUrl = $destination->laravelConnectorSyncUrl();
        $apiKey = $destination->laravelConnectorApiKey();
        $siteId = $destination->laravelConnectorSiteId();

        if (! $destination->laravelConnectorEnabled()) {
            throw new LaravelConnectorPermanentSyncException('Laravel connector destination is disabled.');
        }

        if ($syncUrl === null || $syncUrl === '') {
            throw new LaravelConnectorPermanentSyncException('Laravel connector sync URL is missing.');
        }

        if ($apiKey === null || $apiKey === '') {
            throw new LaravelConnectorPermanentSyncException('Laravel connector API key is missing.');
        }

        if ($siteId === null || $siteId === '') {
            throw new LaravelConnectorPermanentSyncException('Laravel connector site ID is missing.');
        }

        $payload = $this->payloadFactory->make($content, $destination, $articleStatus);
        $idempotencyKey = $this->makeIdempotencyKey($content, $payload);

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Argusly-API-Key' => $apiKey,
            'X-Argusly-Site' => $siteId,
            'X-Argusly-Content' => (string) $content->id,
            'X-Argusly-Idempotency-Key' => $idempotencyKey,
            'User-Agent' => 'Argusly/LaravelConnectorSync',
        ];

        $attemptModel = ContentDestinationSyncAttempt::query()->create([
            'workspace_id' => $destination->workspace_id,
            'content_destination_id' => $destination->id,
            'content_id' => $content->id,
            'content_publish_target_id' => $publishTarget->id,
            'sync_type' => 'knowledge_article',
            'trigger_source' => $triggerSource,
            'status' => 'pending',
            'attempt' => $attempt,
            'request_url' => $syncUrl,
            'idempotency_key' => $idempotencyKey,
            'request_headers' => $headers,
            'request_body' => $payload,
            'started_at' => now(),
        ]);

        try {
            $draft = $articleStatus === 'deleted'
                ? null
                : $content->drafts()->latest('created_at')->first();

            $result = $articleStatus === 'deleted'
                ? $this->publicationService->unpublish($content, $destination)
                : $this->publicationService->publish($content, $destination, $draft, [
                    'status' => $articleStatus ?: 'published',
                ]);

            $this->recordResult($attemptModel, $result);

            if (! $result->isSuccess()) {
                $message = $result->errorMessage ?? 'Laravel connector sync failed';
                $retryable = $result->retryable;

                $this->markFailed($destination, $content, $publishTarget, $attemptModel, $message, $attempt, $retryable);

                if ($retryable) {
                    throw new RuntimeException($message);
                }

                throw new LaravelConnectorPermanentSyncException($message);
            }

            $this->markDelivered($destination, $content, $publishTarget, $attemptModel, (string) data_get($payload, 'article.status', 'published'));

            return $attemptModel->fresh();
        } catch (\Throwable $exception) {
            if (! $attemptModel->failed_at) {
                $retryable = ! $exception instanceof LaravelConnectorPermanentSyncException;
                $this->markFailed($destination, $content, $publishTarget, $attemptModel, $exception->getMessage(), $attempt, $retryable);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function makeIdempotencyKey(Content $content, array $payload): string
    {
        $articleUpdatedAt = (string) data_get($payload, 'article.source_updated_at', '');

        return hash('sha256', implode('|', [
            (string) $content->id,
            $articleUpdatedAt,
            (string) data_get($payload, 'article.slug', ''),
            (string) data_get($payload, 'article.status', ''),
        ]));
    }

    private function recordResult(ContentDestinationSyncAttempt $attemptModel, PublicationResult $result): void
    {
        $attemptModel->forceFill([
            'response_status' => $result->httpStatus,
            'response_headers' => [],
            'response_body' => Str::limit((string) json_encode($result->meta['response_body'] ?? $result->toArray()), 20000, ''),
        ])->save();
    }

    private function markDelivered(
        ContentDestination $destination,
        Content $content,
        ContentPublishTarget $publishTarget,
        ContentDestinationSyncAttempt $attemptModel,
        string $articleStatus,
    ): void {
        $remoteSyncStatus = $articleStatus === 'deleted' ? 'deleted' : 'synced';
        $resolvedPublishedUrl = (string) ($content->fresh()->published_url ?? $content->published_url ?? '');

        $attemptModel->forceFill([
            'status' => 'delivered',
            'delivered_at' => now(),
            'failed_at' => null,
            'next_retry_at' => null,
            'error_message' => null,
        ])->save();

        $publishTarget->forceFill([
            'sync_status' => $remoteSyncStatus === 'deleted' ? 'deleted' : 'synced',
            'last_synced_at' => now(),
            'meta' => array_merge(is_array($publishTarget->meta) ? $publishTarget->meta : [], [
                'remote_sync_status' => $remoteSyncStatus,
                'last_sync_attempt_id' => (string) $attemptModel->id,
                'last_sync_response_status' => $attemptModel->response_status,
                'last_sync_error' => null,
                'last_synced_operation' => $articleStatus,
                'published_url' => $resolvedPublishedUrl !== '' ? $resolvedPublishedUrl : null,
                'published_url_confirmed' => $remoteSyncStatus === 'synced' && $resolvedPublishedUrl !== '',
            ]),
        ])->save();

        $destination->forceFill(['last_used_at' => now()])->save();

        $content->forceFill([
            'publish_error' => null,
        ])->save();

        Event::query()->create([
            'id' => (string) Str::uuid(),
            'client_site_id' => $content->client_site_id,
            'type' => $remoteSyncStatus === 'deleted' ? 'publish.remote_deleted' : 'publish.remote_synced',
            'occurred_at' => now(),
            'data' => [
                'content_id' => (string) $content->id,
                'destination_id' => (string) $destination->id,
                'publish_target_id' => (string) $publishTarget->id,
                'sync_attempt_id' => (string) $attemptModel->id,
                'target' => 'laravel_connector',
                'operation' => $articleStatus,
            ],
        ]);
    }

    private function markFailed(
        ContentDestination $destination,
        Content $content,
        ContentPublishTarget $publishTarget,
        ContentDestinationSyncAttempt $attemptModel,
        string $message,
        int $attempt,
        bool $retryable,
    ): void {
        $nextRetryAt = $retryable && $attempt < 4
            ? now()->addSeconds($this->backoff()[$attempt - 1] ?? 900)
            : null;

        $attemptModel->forceFill([
            'status' => 'failed',
            'failed_at' => now(),
            'next_retry_at' => $nextRetryAt,
            'error_message' => Str::limit($message, 5000, ''),
        ])->save();

        $publishTarget->forceFill([
            'sync_status' => 'failed',
            'meta' => array_merge(is_array($publishTarget->meta) ? $publishTarget->meta : [], [
                'remote_sync_status' => 'failed',
                'last_sync_attempt_id' => (string) $attemptModel->id,
                'last_sync_response_status' => $attemptModel->response_status,
                'last_sync_error' => Str::limit($message, 2000, ''),
                'last_sync_retryable' => $retryable,
            ]),
        ])->save();

        $content->forceFill([
            'publish_error' => Str::limit('Laravel connector sync failed: '.$message, 2000, ''),
        ])->save();

        Event::query()->create([
            'id' => (string) Str::uuid(),
            'client_site_id' => $content->client_site_id,
            'type' => 'publish.remote_sync_failed',
            'occurred_at' => now(),
            'data' => [
                'content_id' => (string) $content->id,
                'destination_id' => (string) $destination->id,
                'publish_target_id' => (string) $publishTarget->id,
                'sync_attempt_id' => (string) $attemptModel->id,
                'target' => 'laravel_connector',
                'error' => Str::limit($message, 2000, ''),
                'retryable' => $retryable,
            ],
        ]);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }
}
