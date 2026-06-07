<?php

declare(strict_types=1);

namespace Onygo\ArguslyConnector;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
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
        return $this->request()->post('connector/health', [
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
        return $this->request()->get('connector/content', $filters);
    }

    /**
     * Placeholder for future push-based content sync acknowledgement.
     *
     * @param array<string, mixed> $payload
     */
    public function syncContent(array $payload): Response
    {
        // TODO(argusly): Review whether Laravel connector sync is push, pull, or hybrid before release.
        return $this->request()->post('connector/content/sync', $payload);
    }

    private function request(): PendingRequest
    {
        $baseUrl = rtrim((string) data_get($this->config, 'api.base_url', 'https://api.argusly.com'), '/');
        $apiKey = (string) data_get($this->config, 'api.key', '');

        if ($apiKey === '') {
            throw new InvalidArgumentException('Missing Argusly connector API key.');
        }

        return Http::baseUrl($baseUrl)
            ->timeout((int) data_get($this->config, 'api.timeout', 15))
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'X-Argusly-API-Key' => $apiKey,
            ]);
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
