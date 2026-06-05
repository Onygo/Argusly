<?php

namespace App\Support\Connectors;

use App\Contracts\Connectors\ConnectorContract;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Services\Integrations\LaravelConnectorDestinationHealthService;
use App\Services\Integrations\LaravelConnectorPayloadFactory;
use App\Services\Integrations\LaravelConnectorPermanentSyncException;
use App\Support\Connectors\Results\HealthCheckResult;
use App\Support\Connectors\Results\PublicationResult;
use App\Support\Connectors\Results\VerificationResult;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Laravel connector for publishing content to Laravel applications.
 *
 * This connector publishes content to external Laravel applications via
 * HTTP POST to a sync endpoint. It is designed for "knowledge article"
 * style content delivery.
 *
 * ## Configuration
 *
 * Laravel destinations must be configured with:
 * - base_url: The Laravel application URL
 * - sync_endpoint: Path to sync endpoint (default: /publishlayer/sync)
 * - api_key: Authentication key
 * - site_id: Destination site identifier
 *
 * ## Differences from WordPress
 *
 * - No native remote_id return (uses content ID as reference)
 * - No verification endpoint (verification not supported)
 * - No native remote scheduling support (Argusly handles due dispatching)
 * - Safe to execute in the generic queued publication flow
 */
class LaravelConnector implements ConnectorContract
{
    public function __construct(
        private readonly LaravelConnectorPayloadFactory $payloadFactory,
        private readonly LaravelConnectorDestinationHealthService $healthService,
    ) {}

    public function type(): string
    {
        return ContentPublication::PROVIDER_LARAVEL;
    }

    public function capabilities(): ConnectorCapabilities
    {
        return ConnectorCapabilities::laravel();
    }

    public function publish(
        Content $content,
        ContentDestination $destination,
        ContentPublication $publication,
        ?Draft $draft = null,
        array $options = [],
    ): PublicationResult {
        return $this->executeSync($content, $destination, $publication, $draft, $this->statusFromOptions($options, 'published'), $options);
    }

    public function update(
        Content $content,
        ContentDestination $destination,
        ContentPublication $publication,
        ?Draft $draft = null,
        array $options = [],
    ): PublicationResult {
        // Laravel connector doesn't differentiate between create and update
        // The receiving application handles idempotency via content ID
        return $this->executeSync($content, $destination, $publication, $draft, $this->statusFromOptions($options, 'published'), $options);
    }

    public function unpublish(
        Content $content,
        ContentDestination $destination,
        ContentPublication $publication,
        array $options = [],
    ): PublicationResult {
        return $this->executeSync($content, $destination, $publication, null, 'deleted', $options);
    }

    /**
     * Verify content availability on the Laravel destination.
     *
     * Note: This is informational verification for debugging purposes.
     * Unlike WordPress destinations (truly remote), Laravel destinations
     * are native to Argusly's ecosystem and don't require strict
     * remote existence verification for the publish workflow.
     *
     * This method checks if the published URL returns a successful response,
     * which can help diagnose routing or middleware issues.
     */
    public function verify(
        ContentPublication $publication,
        ContentDestination $destination,
    ): VerificationResult {
        $url = trim((string) ($publication->remote_url ?: data_get($publication->meta, 'last_result.remote_url', '')));

        if ($url === '') {
            return VerificationResult::unknown(
                reason: 'Laravel route verification requires a published URL.',
                meta: [
                    'connector' => $this->type(),
                    'destination_id' => $destination->id,
                    'publication_id' => $publication->id,
                ],
            );
        }

        try {
            $response = Http::timeout(10)
                ->connectTimeout(3)
                ->withoutRedirecting()
                ->head($url);

            if ($response->status() === 405) {
                $response = Http::timeout(10)
                    ->connectTimeout(3)
                    ->withoutRedirecting()
                    ->get($url);
            }
        } catch (\Throwable $exception) {
            return VerificationResult::error(
                errorCode: 'TRANSPORT_ERROR',
                errorMessage: $exception->getMessage() !== '' ? $exception->getMessage() : 'Unable to verify Laravel route.',
                meta: [
                    'connector' => $this->type(),
                    'destination_id' => $destination->id,
                    'publication_id' => $publication->id,
                    'url' => $url,
                ],
            );
        }

        return $this->verificationResultFromResponse($response, $url, $publication, $destination);
    }

    public function healthCheck(ContentDestination $destination): HealthCheckResult
    {
        $startTime = microtime(true);

        try {
            $result = $this->healthService->test($destination);
            $latencyMs = (microtime(true) - $startTime) * 1000;

            if ($result['ok']) {
                return HealthCheckResult::healthy(
                    message: $result['message'] ?? 'Laravel connector health check succeeded',
                    httpStatus: $result['status_code'],
                    latencyMs: $latencyMs,
                    diagnostics: $result['body'] ?? [],
                );
            }

            return HealthCheckResult::unhealthy(
                message: $result['message'] ?? 'Laravel connector health check failed',
                httpStatus: $result['status_code'],
                latencyMs: $latencyMs,
                diagnostics: $result['body'] ?? [],
            );
        } catch (\Throwable $exception) {
            $latencyMs = (microtime(true) - $startTime) * 1000;

            return HealthCheckResult::fromException($exception, [
                'latency_ms' => $latencyMs,
                'connector' => $this->type(),
            ]);
        }
    }

    public function mapFields(Content $content, ?Draft $draft = null, array $options = []): array
    {
        // This requires a destination for site_id, so we build a minimal payload
        // For full payload, use executeSync which uses the PayloadFactory
        $content->loadMissing([
            'drafts' => fn ($query) => $query->latest('created_at')->limit(1),
            'featuredImage',
        ]);

        $draft ??= $content->drafts->first();

        return [
            'id' => (string) $content->id,
            'title' => (string) ($draft?->title ?: $content->title),
            'language' => (string) ($draft?->language?->value ?? $content->language?->value ?? 'en'),
            'slug' => Str::slug((string) ($content->title ?: $content->id)),
            'content_html' => trim((string) ($draft?->content_html ?? '')),
            'seo_title' => $content->seo_title,
            'seo_description' => $content->seo_meta_description,
            'status' => $options['status'] ?? 'published',
        ];
    }

    /**
     * Execute the sync operation to Laravel destination.
     */
    private function executeSync(
        Content $content,
        ContentDestination $destination,
        ContentPublication $publication,
        ?Draft $draft,
        string $articleStatus,
        array $options = [],
    ): PublicationResult {
        // Validate destination configuration
        $validationResult = $this->validateDestination($destination);
        if ($validationResult !== null) {
            return $validationResult;
        }

        $syncUrl = $destination->laravelConnectorSyncUrl();
        $apiKey = $destination->laravelConnectorApiKey();
        $siteId = $destination->laravelConnectorSiteId();

        // Load draft if not provided
        if ($draft === null && $articleStatus !== 'deleted') {
            $content->loadMissing([
                'drafts' => fn ($query) => $query->latest('created_at')->limit(1),
            ]);
            $draft = $content->drafts->first();
        }

        // Build payload
        $policy = $this->resolvePolicyPayload($content, $publication, $destination, $articleStatus, $options);
        $payload = $this->payloadFactory->make($content, $destination, $articleStatus, $policy);
        $idempotencyKey = (string) ($policy['idempotency_key'] ?: $this->makeIdempotencyKey($content, $payload));
        $payload['policy']['idempotency_key'] = $idempotencyKey;

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-PublishLayer-Key' => $apiKey,
            'X-PublishLayer-Site' => $siteId,
            'X-PublishLayer-Content' => (string) $content->id,
            'X-PublishLayer-Idempotency-Key' => $idempotencyKey,
            'User-Agent' => 'Argusly/LaravelConnector',
        ];

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->acceptJson()
                ->withHeaders($headers)
                ->post($syncUrl, $payload);

            return $this->processResponse($response, $content, $payload, $articleStatus, $idempotencyKey);
        } catch (\Throwable $exception) {
            return PublicationResult::failure(
                errorCode: 'TRANSPORT_ERROR',
                errorMessage: $exception->getMessage(),
                retryable: true,
                meta: [
                    'exception_class' => get_class($exception),
                    'connector' => $this->type(),
                ],
            );
        }
    }

    /**
     * Validate destination configuration.
     */
    private function validateDestination(ContentDestination $destination): ?PublicationResult
    {
        if (! $destination->laravelConnectorEnabled()) {
            return PublicationResult::failure(
                errorCode: 'DESTINATION_DISABLED',
                errorMessage: 'Laravel connector destination is disabled.',
                retryable: false,
            );
        }

        $syncUrl = $destination->laravelConnectorSyncUrl();
        $apiKey = $destination->laravelConnectorApiKey();
        $siteId = $destination->laravelConnectorSiteId();

        if ($syncUrl === null || $syncUrl === '') {
            return PublicationResult::failure(
                errorCode: 'CONFIG_MISSING',
                errorMessage: 'Laravel connector sync URL is missing.',
                retryable: false,
            );
        }

        if ($apiKey === null || $apiKey === '') {
            return PublicationResult::failure(
                errorCode: 'CONFIG_MISSING',
                errorMessage: 'Laravel connector API key is missing.',
                retryable: false,
            );
        }

        if ($siteId === null || $siteId === '') {
            return PublicationResult::failure(
                errorCode: 'CONFIG_MISSING',
                errorMessage: 'Laravel connector site ID is missing.',
                retryable: false,
            );
        }

        return null;
    }

    /**
     * Process HTTP response into PublicationResult.
     *
     * @param array<string, mixed> $payload
     */
    private function processResponse(
        Response $response,
        Content $content,
        array $payload,
        string $articleStatus,
        string $idempotencyKey,
    ): PublicationResult {
        $httpStatus = $response->status();

        if (! $this->responseIsSuccessful($response)) {
            $message = $this->extractResponseMessage($response);
            $retryable = $this->responseIsRetryable($response);

            return PublicationResult::failure(
                errorCode: 'SYNC_FAILED',
                errorMessage: $message,
                retryable: $retryable,
                httpStatus: $httpStatus,
                meta: [
                    'response_body' => $response->json() ?? [],
                    'connector_feedback' => $response->json() ?? [],
                ],
            );
        }

        // Laravel connector uses content ID as the remote reference
        // The receiving application manages its own IDs internally
        $responseBody = $response->json() ?? [];
        $remoteId = trim((string) data_get($responseBody, 'remote_content_id', data_get($responseBody, 'article.id', $content->id)));
        $remoteUrl = trim((string) (
            data_get($responseBody, 'remote_url')
            ?? data_get($responseBody, 'preview_url')
            ?? data_get($responseBody, 'article.url')
            ?? $response->json('published_url')
            ?? $response->json('url')
            ?? $content->published_url
            ?? ''
        ));
        $remoteStatus = trim((string) data_get($responseBody, 'status', data_get($responseBody, 'article.status', '')));
        $remoteStatus = $remoteStatus !== '' ? $remoteStatus : ($articleStatus === 'deleted' ? 'deleted' : $articleStatus);

        return PublicationResult::success(
            remoteId: $remoteId,
            remoteUrl: $remoteUrl !== '' ? $remoteUrl : null,
            remoteType: 'knowledge_article',
            remoteStatus: $remoteStatus,
            httpStatus: $httpStatus,
            meta: [
                'response_body' => $responseBody,
                'connector_feedback' => $responseBody,
                'idempotency_key' => $idempotencyKey,
                'policy' => $payload['policy'] ?? [],
            ],
        );
    }

    private function statusFromOptions(array $options, string $fallback): string
    {
        $status = strtolower(trim((string) ($options['status'] ?? data_get($options, 'policy.status') ?? $fallback)));

        return in_array($status, ['published', 'draft', 'archived', 'unpublished', 'deleted', 'reference'], true)
            ? $status
            : $fallback;
    }

    /**
     * @return array<string,mixed>
     */
    private function resolvePolicyPayload(Content $content, ContentPublication $publication, ContentDestination $destination, string $articleStatus, array $options): array
    {
        $meta = is_array($publication->meta) ? $publication->meta : [];
        $policy = array_replace(
            is_array($meta['agentic_policy'] ?? null) ? $meta['agentic_policy'] : [],
            is_array($options['policy'] ?? null) ? $options['policy'] : [],
        );
        $executionMode = strtolower(trim((string) ($policy['execution_mode'] ?? 'guided')));

        if (! in_array($executionMode, ['guided', 'autonomous'], true)) {
            $executionMode = 'guided';
        }

        return array_replace([
            'execution_mode' => $executionMode,
            'action_run_id' => $policy['action_run_id'] ?? data_get($meta, 'agentic.action_run_id'),
            'approval_status' => $policy['approval_status'] ?? 'approved',
            'approved_by' => $policy['approved_by'] ?? null,
            'approved_at' => $policy['approved_at'] ?? null,
            'autonomous_policy_snapshot' => $policy['autonomous_policy_snapshot'] ?? [],
            'safety_check_status' => $policy['safety_check_status'] ?? 'pass',
            'safety_check_issues' => $policy['safety_check_issues'] ?? [],
            'max_allowed_operation' => $policy['max_allowed_operation'] ?? ($articleStatus === 'published' ? 'publish' : 'draft'),
            'dry_run' => (bool) ($policy['dry_run'] ?? false),
            'idempotency_key' => $policy['idempotency_key'] ?? null,
            'site_id' => $destination->laravelConnectorSiteId(),
            'publishing_site_id' => (string) ($content->client_site_id ?? ''),
        ], $policy);
    }

    /**
     * Check if response indicates success.
     */
    private function responseIsSuccessful(Response $response): bool
    {
        if (! $response->successful()) {
            return false;
        }

        $ok = $response->json('ok');
        $success = $response->json('success');

        if ($ok === false || $success === false) {
            return false;
        }

        return true;
    }

    /**
     * Check if response error is retryable.
     */
    private function responseIsRetryable(Response $response): bool
    {
        // Non-retryable status codes (client errors that won't change on retry)
        return ! in_array($response->status(), [400, 401, 403, 404, 409, 410, 422], true);
    }

    /**
     * Extract error message from response.
     */
    private function extractResponseMessage(Response $response): string
    {
        $message = trim((string) ($response->json('message') ?? ''));

        if ($message !== '') {
            $errors = $response->json('errors');
            if (is_array($errors) && $errors !== []) {
                $firstError = collect($errors)
                    ->flatten()
                    ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                    ->first();

                if (is_string($firstError) && $firstError !== '') {
                    return Str::limit($message . ' ' . $firstError, 1000, '');
                }
            }

            return Str::limit($message, 1000, '');
        }

        return Str::limit('Laravel connector returned HTTP ' . $response->status(), 1000, '');
    }

    /**
     * Generate idempotency key for the payload.
     *
     * @param array<string, mixed> $payload
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

    private function verificationResultFromResponse(
        Response $response,
        string $url,
        ContentPublication $publication,
        ContentDestination $destination,
    ): VerificationResult {
        $status = $response->status();

        if (($status >= 200 && $status < 400) || in_array($status, [401, 403], true)) {
            return VerificationResult::exists(
                remoteStatus: 'published',
                remoteUrl: $url,
                httpStatus: $status,
                meta: [
                    'connector' => $this->type(),
                    'destination_id' => $destination->id,
                    'publication_id' => $publication->id,
                ],
            );
        }

        if (in_array($status, [404, 410], true)) {
            return VerificationResult::missing(
                httpStatus: $status,
                meta: [
                    'connector' => $this->type(),
                    'destination_id' => $destination->id,
                    'publication_id' => $publication->id,
                    'url' => $url,
                ],
            );
        }

        return VerificationResult::error(
            errorCode: 'HTTP_'.$status,
            errorMessage: 'Laravel route verification returned an unexpected response.',
            httpStatus: $status,
            meta: [
                'connector' => $this->type(),
                'destination_id' => $destination->id,
                'publication_id' => $publication->id,
                'url' => $url,
            ],
        );
    }
}
