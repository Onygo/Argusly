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
        return $this->request()->post('/api/v1/connectors/heartbeat', [
            'site' => $this->sitePayload(),
            'connector' => array_merge([
                'name' => 'argusly-laravel-connector',
                'version' => InstalledVersions::version(),
            ], $metadata),
        ]);
    }

    /**
     * Placeholder for future pull-based content sync.
     *
     * @param array<string, mixed> $filters
     */
    public function pullContent(array $filters = []): Response
    {
        // TODO(argusly): Review final content pull endpoint and pagination contract.
        return $this->request()->get('/api/v1/connectors/content', $filters);
    }

    /**
     * Placeholder for fetching a single content item.
     *
     * @param string|int $content
     */
    public function content(string|int $content): Response
    {
        // TODO(argusly): Review final content item response schema before release.
        return $this->request()->get('/api/v1/connectors/content/' . rawurlencode((string) $content));
    }

    /**
     * Placeholder for future push-based content sync acknowledgement.
     *
     * @param array<string, mixed> $payload
     */
    public function syncContent(string|int $content, array $payload, ?string $idempotencyKey = null): Response
    {
        // TODO(argusly): Review whether Laravel connector sync is push, pull, or hybrid before release.
        return $this->request([
            'X-Argusly-Idempotency-Key' => $idempotencyKey ?: (string) Str::uuid(),
        ])->post('/api/v1/connectors/content/' . rawurlencode((string) $content) . '/sync-results', $payload);
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
            'X-Argusly-API-Key' => data_get($this->config, 'api.send_api_key_alias', false) ? $token : null,
            'X-Argusly-Site' => data_get($this->config, 'site.id'),
            'X-Argusly-Destination-Id' => data_get($this->config, 'destination.id'),
        ], static fn ($value): bool => $value !== null && $value !== '');

        return Http::baseUrl($baseUrl)
            ->timeout((int) data_get($this->config, 'api.timeout', 15))
            ->acceptJson()
            ->asJson()
            ->withHeaders(array_merge($headers, $extraHeaders));
    }

    /**
     * @return array<string, mixed>
     */
    private function sitePayload(): array
    {
        return [
            'id' => data_get($this->config, 'site.id'),
            'name' => data_get($this->config, 'site.name'),
            'url' => data_get($this->config, 'site.url'),
        ];
    }
}
