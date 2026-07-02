<?php

namespace App\Services\DraftDelivery;

use App\Models\Content;
use App\Models\ContentPublishTarget;
use App\Models\SiteToken;
use App\Support\ImageAttribution;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class PushContentFeaturedImageToWordPress
{
    public function __construct(
        private readonly DeliverDraftToWordPress $delivery
    ) {}

    /**
     * @return array{ok:bool,status:int|null,body:string|null,error:string|null,should_retry?:bool}
     */
    public function push(Content $content, bool $ensureWpPostId = true, bool $allowReschedule = false): array
    {
        $content->loadMissing(['clientSite', 'drafts', 'featuredImage', 'currentRevision', 'currentVersion']);

        if (! $content->clientSite) {
            return [
                'ok' => false,
                'status' => null,
                'body' => null,
                'error' => 'No connected site found for this content.',
            ];
        }

        $featured = $content->featuredImage;
        $featuredImageUrl = $featured?->getWordPressUploadUrl($content->clientSite);
        if (! $featured || $featured->status !== 'ready' || blank($featuredImageUrl)) {
            return [
                'ok' => false,
                'status' => null,
                'body' => null,
                'error' => 'No ready featured image available to push.',
            ];
        }

        $draft = $content->drafts()->latest('created_at')->first();
        $meta = is_array($draft?->meta) ? $draft->meta : [];
        $clientRefs = is_array(data_get($meta, 'client_refs')) ? data_get($meta, 'client_refs') : [];
        $remoteDraftId = $this->resolveRemoteDraftId($content, $draft, $clientRefs);
        $requestId = (string) Str::uuid();

        $url = trim((string) ($clientRefs['draft_webhook_url'] ?? $content->clientSite->draft_webhook_url ?? ''));
        $secret = trim((string) ($clientRefs['draft_webhook_secret'] ?? $content->clientSite->draft_webhook_secret ?? ''));
        if ($url !== '' && $secret === '') {
            $secret = trim((string) config('argusly.webhooks.secret', ''));
        }
        $baseUrl = rtrim((string) ($content->clientSite->base_url ?: $content->clientSite->site_url), '/');

        $targetPostId = trim((string) ($content->wp_post_id ?: data_get($clientRefs, 'wp_post_id', '')));
        if ($targetPostId === '' && $ensureWpPostId) {
            $ensureResult = $this->delivery->ensureWpPostIdForContent($content);
            $targetPostId = trim((string) ($ensureResult['wp_post_id'] ?? ''));

            if ($targetPostId !== '') {
                $content->refresh();
                $draft = $content->drafts()->latest('created_at')->first();
                $meta = is_array($draft?->meta) ? $draft->meta : [];
                $clientRefs = is_array(data_get($meta, 'client_refs')) ? data_get($meta, 'client_refs') : [];
                $url = trim((string) ($clientRefs['draft_webhook_url'] ?? $content->clientSite->draft_webhook_url ?? ''));
                $secret = trim((string) ($clientRefs['draft_webhook_secret'] ?? $content->clientSite->draft_webhook_secret ?? ''));
                if ($url !== '' && $secret === '') {
                    $secret = trim((string) config('argusly.webhooks.secret', ''));
                }

                $this->logWpPostIdState($content, 'set', [
                    'wp_post_id' => $targetPostId,
                    'source' => (string) ($ensureResult['source'] ?? 'ensure_wp_post_id'),
                ]);
            } else {
                $error = trim((string) ($ensureResult['error'] ?? 'Cannot push featured image because wp_post_id is missing.'));
                $shouldRetry = $allowReschedule && (bool) ($ensureResult['retryable'] ?? false);

                $this->logWpPostIdState($content, 'missing', [
                    'source' => (string) ($ensureResult['source'] ?? ''),
                    'error' => $error,
                    'should_retry' => $shouldRetry,
                ], $shouldRetry ? 'warning' : 'error');

                return [
                    'ok' => false,
                    'status' => isset($ensureResult['status']) ? (int) $ensureResult['status'] : null,
                    'body' => null,
                    'error' => $error,
                    'should_retry' => $shouldRetry,
                ];
            }
        }

        if ($targetPostId === '') {
            $this->logWpPostIdState($content, 'missing', [
                'error' => 'Cannot push featured image because wp_post_id is missing.',
                'should_retry' => false,
            ], 'error');

            return [
                'ok' => false,
                'status' => null,
                'body' => null,
                'error' => 'Cannot push featured image because wp_post_id is missing.',
                'should_retry' => false,
            ];
        }

        $endpoint = $this->resolveFeaturedImageEndpoint($url !== '' ? $url : $baseUrl, $targetPostId);

        $payload = [
            'request_id' => $requestId,
            'content_id' => (string) $content->id,
            'draft_id' => (string) ($draft?->id ?? ''),
            'remote_draft_id' => $remoteDraftId,
            'wp_post_id' => $targetPostId,
            'image_url' => (string) $featuredImageUrl,
            'filename' => $featured->getWordPressUploadFilename($content->clientSite),
            'mime' => $featured->getWordPressUploadMimeType($content->clientSite),
            'alt' => (string) ($content->title ?? ''),
            'title' => (string) (($draft?->title ?: $content->title) ?? ''),
            // Backward-compatible fields for older plugin fallback handlers.
            'event' => 'content.featured_image',
            'featured_image_url' => (string) $featuredImageUrl,
            'featured_image_path' => $featured->getPathForWordPressUpload($content->clientSite),
            'featured_image_filename' => $featured->getWordPressUploadFilename($content->clientSite),
            'featured_image_mime' => $featured->getWordPressUploadMimeType($content->clientSite),
        ];

        $attribution = ImageAttribution::fromContentImage($featured);
        if ($attribution !== []) {
            $payload['image_attribution'] = $attribution;
            $payload['featured_image_attribution'] = (string) ($attribution['text'] ?? '');
            $payload['featured_image_source'] = (string) ($attribution['provider_name'] ?? '');
            $payload['featured_image_source_url'] = (string) ($attribution['provider_url'] ?? '');
        }

        $imageB64 = $this->buildImageBase64Fallback($featured, $content);
        if ($imageB64 !== null) {
            $payload['image_b64'] = $imageB64;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (! is_string($body)) {
            throw new RuntimeException('Failed to encode WP featured image payload.');
        }

        $ts = (string) time();
        $signature = $secret !== '' ? hash_hmac('sha256', $ts.'.'.$body, $secret) : '';
        $siteToken = $this->resolveOutboundSiteToken($content);
        $useBearer = $secret === '' && $siteToken !== '';

        try {
            $headers = [
                'Content-Type' => 'application/json',
                'X-Argusly-Request-Id' => $requestId,
            ];

            if ($useBearer) {
                $headers['Authorization'] = 'Bearer '.$siteToken;
                $headers['Accept'] = 'application/json';
            } else {
                if ($secret === '') {
                    return [
                        'ok' => false,
                        'status' => null,
                        'body' => null,
                        'error' => 'WordPress connector is not configured for this content/site.',
                    ];
                }
                $headers['X-Argusly-Timestamp'] = $ts;
                $headers['X-Argusly-Signature'] = $signature;
                $headers['X-Argusly-Timestamp'] = $ts;
                $headers['X-Argusly-Signature'] = $signature;
            }

            $response = Http::timeout(30)
                ->withOptions([
                    'verify' => app()->environment('local') ? false : true,
                ])
                ->withHeaders($headers)
                ->send('POST', $endpoint, ['body' => $body]);

            if ($response->successful()) {
                $this->syncWordPressMetadata($featured, $content, $targetPostId, $response->json());
            } elseif ($response->status() === 404 || $response->status() === 405) {
                // Fallback for older plugin versions that only support the draft webhook route.
                $legacyResponse = null;
                if ($url !== '' && ! $useBearer) {
                    $legacyResponse = Http::timeout(30)
                        ->withOptions([
                            'verify' => app()->environment('local') ? false : true,
                        ])
                        ->withHeaders([
                            'Content-Type' => 'application/json',
                            'X-Argusly-Timestamp' => $ts,
                            'X-Argusly-Signature' => $signature,
                            'X-Argusly-Request-Id' => $requestId,
                            'X-Argusly-Timestamp' => $ts,
                            'X-Argusly-Signature' => $signature,
                        ])
                        ->send('POST', $url, ['body' => $body]);
                }

                if ($legacyResponse && $legacyResponse->successful()) {
                    $this->syncWordPressMetadata($featured, $content, $targetPostId, $legacyResponse->json());
                }

                if ($legacyResponse) {
                    $response = $legacyResponse;
                }
            }

            $this->logImagePush($content, [
                'request_id' => $requestId,
                'wp_post_id' => $targetPostId,
                'endpoint' => $endpoint,
                'channel' => $useBearer ? 'direct_api' : 'webhook',
                'payload_size' => strlen($body),
                'result' => $response->successful() ? 'ok' : 'http_error',
                'status' => $response->status(),
            ], $response->successful() ? 'info' : 'error');

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'body' => $response->body(),
                'error' => $response->successful()
                    ? null
                    : $this->extractRemoteError($response->status(), (string) $response->body()),
                'should_retry' => false,
            ];
        } catch (\Throwable $exception) {
            $this->logImagePush($content, [
                'request_id' => $requestId,
                'wp_post_id' => $targetPostId,
                'endpoint' => $endpoint,
                'result' => 'exception',
                'error' => $exception->getMessage(),
            ], 'error');

            return [
                'ok' => false,
                'status' => null,
                'body' => null,
                'error' => $exception->getMessage(),
                'should_retry' => false,
            ];
        }
    }

    /**
     * @param array<string,mixed> $clientRefs
     */
    private function resolveRemoteDraftId(Content $content, mixed $draft, array $clientRefs): string
    {
        $remoteDraftId = trim((string) ($clientRefs['remote_draft_id'] ?? ''));
        if ($remoteDraftId !== '') {
            return $remoteDraftId;
        }

        $wpDraftId = trim((string) ($clientRefs['wp_draft_id'] ?? ''));
        if ($wpDraftId !== '') {
            return $wpDraftId;
        }

        $wpPostId = trim((string) ($content->wp_post_id ?: ($clientRefs['wp_post_id'] ?? '')));
        if ($wpPostId !== '') {
            return $wpPostId;
        }

        $contentId = trim((string) $content->id);
        if ($contentId !== '') {
            return $contentId;
        }

        return (string) ($draft?->id ?? '');
    }

    private function resolveContentHtml(Content $content, mixed $draft): string
    {
        $versionHtml = (string) ($content->currentVersion?->body ?? '');
        if ($versionHtml !== '') {
            return $versionHtml;
        }

        $revisionHtml = (string) ($content->currentRevision?->content_html ?? '');
        if ($revisionHtml !== '') {
            return $revisionHtml;
        }

        return (string) ($draft?->content_html ?? '');
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveContentMeta(Content $content, mixed $draft): array
    {
        $versionMeta = $content->currentVersion?->meta;
        if (is_array($versionMeta)) {
            return $versionMeta;
        }

        $revisionMeta = $content->currentRevision?->meta;
        if (is_array($revisionMeta)) {
            return $revisionMeta;
        }

        $draftMeta = $draft?->meta;
        if (is_array($draftMeta)) {
            return $draftMeta;
        }

        return [];
    }

    /**
     * @return array<int,mixed>
     */
    private function resolveContentLinks(mixed $draft): array
    {
        return is_array($draft?->links) ? $draft->links : [];
    }

    private function resolveFeaturedImageEndpoint(string $webhookUrl, string $wpPostId): string
    {
        $trimmed = trim($webhookUrl);
        if ($trimmed === '') {
            return $trimmed;
        }

        if (str_contains($trimmed, '/wp-json/argusly/v1/webhook/draft')) {
            return str_replace(
                '/wp-json/argusly/v1/webhook/draft',
                '/wp-json/argusly/v1/posts/' . rawurlencode($wpPostId) . '/featured-image',
                $trimmed
            );
        }

        if (str_contains($trimmed, 'rest_route=/argusly/v1/webhook/draft')) {
            return preg_replace(
                '/rest_route=\\/argusly\\/v1\\/webhook\\/draft/i',
                'rest_route=/argusly/v1/posts/' . rawurlencode($wpPostId) . '/featured-image',
                $trimmed
            ) ?: $trimmed;
        }

        $parts = parse_url($trimmed);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        if ($host === '') {
            return $trimmed;
        }

        return $scheme . '://' . $host . $port . '/wp-json/argusly/v1/posts/' . rawurlencode($wpPostId) . '/featured-image';
    }

    /**
     * @param array<string,mixed>|null $responseData
     */
    private function syncWordPressMetadata(\App\Models\ContentImage $featured, Content $content, string $wpPostId, ?array $responseData): void
    {
        if (! is_array($responseData)) {
            return;
        }

        $attachmentId = trim((string) ($responseData['attachment_id'] ?? ''));
        $featuredImageId = trim((string) ($responseData['featured_image_id'] ?? ''));
        $featuredImageUrl = trim((string) ($responseData['featured_image_url'] ?? ''));

        if ($attachmentId === '' && $featuredImageId === '' && $featuredImageUrl === '') {
            return;
        }

        $metadata = is_array($featured->metadata) ? $featured->metadata : [];
        $wp = is_array($metadata['wp'] ?? null) ? $metadata['wp'] : [];

        if ($attachmentId !== '') {
            $wp['attachment_id'] = $attachmentId;
        }
        if ($featuredImageId !== '') {
            $wp['featured_image_id'] = $featuredImageId;
        }
        if ($featuredImageUrl !== '') {
            $wp['featured_image_url'] = $featuredImageUrl;
        }

        $metadata['wp'] = $wp;
        $featured->forceFill(['metadata' => $metadata])->save();

        $featuredMediaId = $featuredImageId !== '' ? $featuredImageId : $attachmentId;
        ContentPublishTarget::query()->updateOrCreate(
            [
                'content_id' => $content->id,
                'client_site_id' => $content->client_site_id,
                'target_type' => 'wp',
            ],
            [
                'target_identifier' => $wpPostId,
                'wp_post_id' => $wpPostId,
                'wp_featured_media_id' => $featuredMediaId !== '' ? $featuredMediaId : null,
                'sync_status' => 'synced',
                'last_synced_at' => now(),
                'meta' => array_filter([
                    'wp_post_id' => $wpPostId,
                    'featured_image_id' => $featuredImageId !== '' ? $featuredImageId : null,
                    'attachment_id' => $attachmentId !== '' ? $attachmentId : null,
                    'featured_image_url' => $featuredImageUrl !== '' ? $featuredImageUrl : null,
                ], static fn ($value) => $value !== null && $value !== ''),
            ]
        );
    }

    /**
     * @param array<string,mixed> $context
     */
    private function logImagePush(Content $content, array $context, string $level = 'info'): void
    {
        if (! (bool) config('argusly.wp_connector.sync_debug', false)) {
            return;
        }

        $siteFilter = trim((string) config('argusly.wp_connector.sync_debug_site_id', ''));
        $siteId = (string) ($content->client_site_id ?? '');
        if ($siteFilter !== '' && $siteFilter !== $siteId) {
            return;
        }

        Log::channel(config('logging.default'))->log($level, 'wp_featured_image_push', array_merge([
            'content_id' => (string) $content->id,
            'site_id' => $siteId,
        ], $context));
    }

    private function extractRemoteError(int $status, string $body): string
    {
        $decoded = json_decode($body, true);
        $error = trim((string) (is_array($decoded) ? ($decoded['error'] ?? '') : ''));

        if ($error !== '') {
            return 'HTTP ' . $status . ': ' . $error;
        }

        return 'HTTP ' . $status;
    }

    private function buildImageBase64Fallback(\App\Models\ContentImage $featured, Content $content): ?string
    {
        if (! (bool) config('argusly.wp_connector.featured_image_b64_fallback', true)) {
            return null;
        }

        $path = trim((string) $featured->getPathForWordPressUpload($content->clientSite));
        if ($path === '') {
            return null;
        }

        $disk = Storage::disk((string) config('argusly.images.disk', config('argusly.ai.images.storage_disk', 'content_images')));
        if (! $disk->exists($path)) {
            return null;
        }

        $maxBytes = max(1, (int) config('argusly.wp_connector.featured_image_b64_max_bytes', 8 * 1024 * 1024));
        $size = (int) $disk->size($path);
        if ($size > $maxBytes) {
            return null;
        }

        $binary = $disk->get($path);
        if ($binary === '') {
            return null;
        }

        return base64_encode($binary);
    }

    private function resolveOutboundSiteToken(Content $content): string
    {
        $tokens = SiteToken::query()
            ->where('client_site_id', (string) $content->client_site_id)
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

    /**
     * @param array<string,mixed> $context
     */
    private function logWpPostIdState(Content $content, string $event, array $context = [], string $level = 'info'): void
    {
        Log::channel(config('logging.default'))->log($level, 'wp_featured_image_wp_post_id', array_merge([
            'event' => $event,
            'content_id' => (string) $content->id,
            'site_id' => (string) ($content->client_site_id ?? ''),
        ], $context));
    }
}
