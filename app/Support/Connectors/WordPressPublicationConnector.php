<?php

namespace App\Support\Connectors;

use App\Contracts\Connectors\ConnectorContract;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Models\SiteToken;
use App\Services\DraftDelivery\DeliverDraftToWordPress;
use App\Services\WordPress\WordPressConnector as WordPressApiConnector;
use App\Support\Connectors\Results\HealthCheckResult;
use App\Support\Connectors\Results\PublicationResult;
use App\Support\Connectors\Results\VerificationResult;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class WordPressPublicationConnector implements ConnectorContract
{
    public function __construct(
        private readonly DeliverDraftToWordPress $deliveryService,
        private readonly WordPressApiConnector $wordPressConnector,
    ) {}

    public function type(): string
    {
        return ContentPublication::PROVIDER_WORDPRESS;
    }

    public function capabilities(): ConnectorCapabilities
    {
        return new ConnectorCapabilities(
            supportsCreate: true,
            supportsUpdate: true,
            supportsDelete: false,
            supportsScheduling: true,
            supportsVerification: true,
            supportsFeaturedImage: true,
            supportsCategories: true,
            supportsTags: true,
            supportsSeoFields: true,
            supportsMultipleLanguages: true,
            supportsCustomFields: true,
            supportsExcerpt: true,
            supportsSlug: true,
            isAsyncOnly: false,
            requiresAuthentication: true,
            supportedContentTypes: ['post', 'page'],
        );
    }

    public function publish(Content $content, ContentDestination $destination, ContentPublication $publication, ?Draft $draft = null, array $options = []): PublicationResult
    {
        return $this->deliver($content, $publication, $draft, $options);
    }

    public function update(Content $content, ContentDestination $destination, ContentPublication $publication, ?Draft $draft = null, array $options = []): PublicationResult
    {
        return $this->deliver($content, $publication, $draft, $options);
    }

    public function unpublish(Content $content, ContentDestination $destination, ContentPublication $publication, array $options = []): PublicationResult
    {
        return PublicationResult::failure(
            errorCode: 'CAPABILITY_NOT_SUPPORTED',
            errorMessage: 'WordPress unpublish is not routed through the connector yet.',
            retryable: false,
        );
    }

    public function verify(ContentPublication $publication, ContentDestination $destination): VerificationResult
    {
        $publication->loadMissing('content.clientSite');
        $content = $publication->content;
        $site = $content?->clientSite;
        $remoteId = trim((string) $publication->remote_id);

        if (! $content || ! $site || $remoteId === '') {
            return VerificationResult::error('VERIFY_CONTEXT_MISSING', 'WordPress verification context is incomplete.');
        }

        $baseUrl = rtrim((string) ($site->base_url ?: $site->site_url), '/');
        $token = $this->resolveOutboundSiteToken((string) $site->id);

        if ($baseUrl === '' || $token === '') {
            return VerificationResult::error('VERIFY_AUTH_MISSING', 'WordPress verification credentials are missing.');
        }

        try {
            $lookup = $this->wordPressConnector->forSite($baseUrl, $token)->postExists($remoteId);

            if (! $lookup->exists) {
                return VerificationResult::missing($lookup->httpStatus ?? 404);
            }

            $remoteStatus = $lookup->post?->status ?? 'publish';
            if ($remoteStatus === 'trash') {
                return VerificationResult::trashed($lookup->post?->publishedUrl, $lookup->httpStatus);
            }

            return VerificationResult::exists(
                remoteStatus: $remoteStatus,
                remoteUrl: $lookup->post?->publishedUrl,
                httpStatus: $lookup->httpStatus,
                meta: $lookup->post?->raw ?? [],
            );
        } catch (\Throwable $exception) {
            return VerificationResult::error('VERIFY_FAILED', $exception->getMessage());
        }
    }

    public function healthCheck(ContentDestination $destination): HealthCheckResult
    {
        return HealthCheckResult::unknown(message: 'WordPress health checks remain delegated to the legacy delivery connection.');
    }

    public function mapFields(Content $content, ?Draft $draft = null, array $options = []): array
    {
        return [
            'content_id' => (string) $content->id,
            'draft_id' => (string) ($draft?->id ?? ''),
            'title' => (string) ($draft?->title ?: $content->title),
            'status' => $options['status'] ?? 'publish',
        ];
    }

    private function deliver(Content $content, ContentPublication $publication, ?Draft $draft = null, array $options = []): PublicationResult
    {
        $draft ??= Draft::query()->where('content_id', $content->id)->latest('created_at')->first();

        if (! $draft) {
            return PublicationResult::failure('DRAFT_NOT_FOUND', 'No draft found for WordPress publishing.', retryable: false);
        }

        $deliveryDraft = $this->deliveryService->primeConnectorDraftForDelivery((string) $draft->id) ?? $draft->fresh() ?? $draft;
        $requestType = $publication->hasRemoteId() ? 'update' : 'create';
        $payloadChecksum = null;
        $syncAction = $requestType;
        $syncReason = null;

        Log::info('publication.wordpress.connector_delivery_started', [
            'publication_id' => (string) $publication->id,
            'content_id' => (string) $content->id,
            'draft_id' => (string) $deliveryDraft->id,
            'target_id' => (string) ($publication->destination_id ?? ''),
            'request_type' => $requestType,
            'remote_id' => (string) ($publication->remote_id ?? ''),
            'draft_status' => (string) ($deliveryDraft->status ?? ''),
            'draft_delivery_status' => (string) ($deliveryDraft->delivery_status ?? ''),
        ]);

        $this->deliveryService->beginConnectorPublicationSession($publication);

        try {
            try {
                $result = $this->deliveryService->deliver($deliveryDraft, (bool) ($options['force_delivery'] ?? false));
                $payloadChecksum = is_string($result['payload_checksum'] ?? null) ? (string) $result['payload_checksum'] : null;
                $syncAction = (string) ($result['sync_action'] ?? $syncAction);
                $syncReason = (string) ($result['recovery_reason'] ?? $result['reason'] ?? '');
            } catch (\Throwable $exception) {
                $message = trim($exception->getMessage()) !== ''
                    ? trim($exception->getMessage())
                    : 'WordPress delivery failed unexpectedly.';

                $this->deliveryService->markFailed($deliveryDraft, $message);

                return PublicationResult::failure(
                    errorCode: 'WORDPRESS_DELIVERY_EXCEPTION',
                    errorMessage: $message,
                    retryable: true,
                    meta: ['exception' => $exception::class],
                );
            }

            if (($result['skipped'] ?? false) === true) {
                Log::warning('publication.wordpress.connector_delivery_skipped', [
                    'publication_id' => (string) $publication->id,
                    'content_id' => (string) $content->id,
                    'draft_id' => (string) $deliveryDraft->id,
                    'target_id' => (string) ($publication->destination_id ?? ''),
                    'request_type' => $requestType,
                    'result' => $result,
                ]);

                return PublicationResult::skipped(
                    reason: (string) ($result['warning'] ?? $result['error'] ?? 'WordPress delivery skipped.'),
                    remoteId: $this->extractRemoteId($result, $publication),
                    remoteUrl: $this->extractPublishedUrl($result) ?? $publication->remote_url,
                    meta: [
                        'delivery_status' => ContentPublication::STATUS_DELIVERED,
                        'delivery_result' => $result,
                        'payload_checksum' => $payloadChecksum,
                        'sync_action' => $syncAction,
                        'sync_reason' => $syncReason,
                        'skip_reason' => (string) ($result['reason'] ?? 'connector_skipped'),
                    ],
                );
            }

            if (($result['ok'] ?? false) !== true) {
                $message = $this->formatFailureMessage($result);
                $this->deliveryService->markFailed($deliveryDraft, $message);

                return PublicationResult::failure(
                    errorCode: 'WORDPRESS_DELIVERY_FAILED',
                    errorMessage: $message,
                    retryable: true,
                    httpStatus: isset($result['status']) ? (int) $result['status'] : null,
                    meta: ['delivery_result' => $result],
                );
            }

            if (($result['partial_success'] ?? false) === true) {
                $wpPostId = $this->extractRemoteId($result, $publication);
                $warning = (string) ($result['warning'] ?? 'Post published with warnings');
                $errors = is_array($result['post_processing_errors'] ?? null) ? $result['post_processing_errors'] : [];
                $this->deliveryService->markPartialSuccess($deliveryDraft, $wpPostId, $warning, $errors);

                return PublicationResult::success(
                    remoteId: $wpPostId,
                    remoteUrl: $this->extractPublishedUrl($result),
                    remoteType: $content->wordPressPostType()->value,
                    remoteStatus: 'publish',
                    httpStatus: isset($result['status']) ? (int) $result['status'] : 200,
                    meta: [
                        'delivery_status' => 'partial_success',
                        'delivery_result' => $result,
                        'payload_checksum' => $payloadChecksum,
                        'sync_action' => $syncAction,
                        'sync_reason' => $syncReason,
                    ],
                );
            }

            if (($result['needs_verification'] ?? false) === true) {
                $wpPostId = $this->extractRemoteId($result, $publication);
                $reason = (string) ($result['warning'] ?? $result['original_error'] ?? 'Delivery result uncertain');
                $this->deliveryService->markNeedsVerification($deliveryDraft, $wpPostId, $reason);

                return PublicationResult::success(
                    remoteId: $wpPostId,
                    remoteUrl: $this->extractPublishedUrl($result),
                    remoteType: $content->wordPressPostType()->value,
                    remoteStatus: 'publish',
                    httpStatus: isset($result['status']) ? (int) $result['status'] : 200,
                    meta: [
                        'delivery_status' => 'needs_verification',
                        'delivery_result' => $result,
                        'payload_checksum' => $payloadChecksum,
                        'sync_action' => $syncAction,
                        'sync_reason' => $syncReason,
                    ],
                );
            }

            $this->deliveryService->markDelivered($deliveryDraft, true);
            $remoteId = $this->extractRemoteId($result, $publication);

            Log::info('publication.wordpress.connector_delivery_completed', [
                'publication_id' => (string) $publication->id,
                'content_id' => (string) $content->id,
                'draft_id' => (string) $deliveryDraft->id,
                'target_id' => (string) ($publication->destination_id ?? ''),
                'request_type' => $requestType,
                'remote_id' => $remoteId,
                'result' => $result,
            ]);

            return PublicationResult::success(
                remoteId: $remoteId,
                remoteUrl: $this->extractPublishedUrl($result),
                remoteType: $content->wordPressPostType()->value,
                remoteStatus: 'publish',
                httpStatus: isset($result['status']) ? (int) $result['status'] : 200,
                meta: [
                    'delivery_result' => $result,
                    'payload_checksum' => $payloadChecksum,
                    'sync_action' => $syncAction,
                    'sync_reason' => $syncReason,
                ],
            );
        } finally {
            $this->deliveryService->endConnectorPublicationSession();
        }
    }

    private function formatFailureMessage(array $result): string
    {
        $error = trim((string) ($result['error'] ?? 'Unknown publish error'));
        $status = isset($result['status']) && $result['status'] !== null ? (int) $result['status'] : null;

        return $status ? "HTTP {$status}: {$error}" : $error;
    }

    private function extractRemoteId(array $result, ContentPublication $publication): ?string
    {
        // Check top-level wp_post_id first
        $remoteId = trim((string) ($result['wp_post_id'] ?? ''));
        if ($remoteId !== '') {
            return $remoteId;
        }

        // Check inside body JSON (delivery service returns body as JSON string)
        $body = (string) ($result['body'] ?? '');
        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $remoteId = trim((string) ($decoded['wp_post_id'] ?? $decoded['post_id'] ?? ''));
                if ($remoteId !== '') {
                    return $remoteId;
                }
            }
        }

        return trim((string) ($publication->remote_id ?? '')) ?: null;
    }

    private function extractPublishedUrl(array $result): ?string
    {
        $body = (string) ($result['body'] ?? '');
        if (trim($body) === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return null;
        }

        foreach (['published_url', 'url', 'permalink', 'link', 'data.url'] as $key) {
            $value = data_get($decoded, $key);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function resolveOutboundSiteToken(string $clientSiteId): string
    {
        $tokens = SiteToken::query()
            ->where('client_site_id', $clientSiteId)
            ->where('revoked', false)
            ->whereNull('revoked_at')
            ->whereNotNull('token_encrypted')
            ->latest('created_at')
            ->get(['token_encrypted']);

        foreach ($tokens as $token) {
            try {
                $plain = trim((string) Crypt::decryptString((string) $token->token_encrypted));
                if ($plain !== '') {
                    return $plain;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return '';
    }
}
