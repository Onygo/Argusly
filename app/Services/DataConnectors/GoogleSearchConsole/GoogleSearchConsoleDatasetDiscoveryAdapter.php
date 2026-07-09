<?php

namespace App\Services\DataConnectors\GoogleSearchConsole;

use App\Models\Connectors\ConnectorAccount;
use App\Services\DataConnectors\ConnectorDatasetDiscoveryAdapter;
use App\Services\DataConnectors\ConnectorProviderHttpClient;
use App\Support\MarketingMetadataRedactor;
use RuntimeException;

class GoogleSearchConsoleDatasetDiscoveryAdapter implements ConnectorDatasetDiscoveryAdapter
{
    public function __construct(private readonly ConnectorProviderHttpClient $http)
    {
    }

    public function providerKey(): string
    {
        return 'google_search_console';
    }

    public function discoverDatasets(ConnectorAccount $account): array
    {
        $response = $this->http->get($account, $this->apiBaseUrl().'/sites', timeout: $this->timeoutSeconds());

        if (! $response->successful()) {
            throw new RuntimeException('Google Search Console site discovery failed with status '.$response->status().'.');
        }

        return collect((array) $response->json('siteEntry', []))
            ->filter(fn (mixed $site): bool => is_array($site) && $this->isVerified($site))
            ->map(fn (array $site): array => $this->mapSite($site))
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $site
     */
    private function isVerified(array $site): bool
    {
        $siteUrl = trim((string) ($site['siteUrl'] ?? ''));
        $permissionLevel = strtolower(trim((string) ($site['permissionLevel'] ?? '')));

        return $siteUrl !== '' && ! str_contains($permissionLevel, 'unverified');
    }

    /**
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    private function mapSite(array $site): array
    {
        $siteUrl = (string) $site['siteUrl'];

        return [
            'external_dataset_id' => $siteUrl,
            'dataset_type' => 'site',
            'display_name' => $siteUrl,
            'capabilities' => ['search.analytics'],
            'sync_frequency' => 'daily',
            'sync_config' => [
                'metrics' => ['clicks', 'impressions', 'ctr', 'position'],
                'dimensions' => ['date', 'query', 'page', 'country', 'device', 'searchAppearance'],
            ],
            'config' => [
                'site_url' => $siteUrl,
            ],
            'metadata' => MarketingMetadataRedactor::redact([
                'site_url' => $siteUrl,
                'permission_level' => (string) ($site['permissionLevel'] ?? ''),
                'property_type' => str_starts_with($siteUrl, 'sc-domain:') ? 'domain' : 'url_prefix',
            ]),
        ];
    }

    private function apiBaseUrl(): string
    {
        return rtrim((string) config('data_connectors.providers.google_search_console.config_json.api.base_url', 'https://www.googleapis.com/webmasters/v3'), '/');
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('data_connectors.providers.google_search_console.config_json.api.timeout_seconds', 15));
    }
}
