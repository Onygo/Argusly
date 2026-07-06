<?php

declare(strict_types=1);

namespace Onygo\ArguslyConnector;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ArguslyClient
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config)
    {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function health(array $metadata = []): Response
    {
        $response = $this->request()->post('/api/v1/connectors/heartbeat', [
            'platform' => 'laravel',
            'connector_version' => InstalledVersions::version(),
            'framework_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'site_url' => data_get($this->config, 'site.url'),
            'app_url' => data_get($this->config, 'site.url'),
            ...$metadata,
        ]);

        $this->recordSuccessfulActivity($response, 'heartbeat');

        return $response;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function contentIndex(array $filters = []): Response
    {
        $response = $this->request()->get('/api/v1/connectors/content', $filters);

        $this->recordSuccessfulActivity($response, 'processed');

        return $response;
    }

    /**
     * @param string|int $content
     */
    public function content(string|int $content): Response
    {
        $response = $this->request()->get('/api/v1/connectors/content/' . rawurlencode((string) $content));

        $this->recordSuccessfulActivity($response, 'processed');

        return $response;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function acknowledgeContentSync(string|int $content, array $payload, ?string $idempotencyKey = null): Response
    {
        $payload['idempotency_key'] ??= $idempotencyKey;

        $response = $this->request([
            'X-Argusly-Idempotency-Key' => $idempotencyKey ?: (string) Str::uuid(),
        ])->post('/api/v1/connectors/content/' . rawurlencode((string) $content) . '/sync-results', $payload);

        $this->recordSuccessfulActivity($response, 'processed');

        return $response;
    }

    /**
     * @param array<string, string> $extraHeaders
     */
    private function request(array $extraHeaders = []): PendingRequest
    {
        $baseUrl = rtrim((string) data_get($this->config, 'api.base_url', 'https://api.argusly.com'), '/');
        $token = (string) data_get($this->config, 'api.token', '');

        if ($token === '') {
            throw new InvalidArgumentException('Missing Argusly connector token.');
        }

        $headers = array_filter([
            'Authorization' => 'Bearer ' . $token,
            'X-Argusly-Site' => data_get($this->config, 'site.url') ?: data_get($this->config, 'site.id'),
            'X-Argusly-Destination-Id' => data_get($this->config, 'destination.id'),
        ], static fn ($value): bool => $value !== null && $value !== '');

        return Http::baseUrl($baseUrl)
            ->timeout((int) data_get($this->config, 'api.timeout', 15))
            ->acceptJson()
            ->asJson()
            ->withHeaders(array_merge($headers, $extraHeaders));
    }

    private function recordSuccessfulActivity(Response $response, string $type): void
    {
        if (! $response->successful()) {
            return;
        }

        try {
            app(ActivityState::class)->record($type, [
                'last_status_code' => $response->status(),
            ]);
        } catch (\Throwable) {
            // Activity status must never break connector calls.
        }
    }

}
