<?php

namespace App\Services\DataConnectors\GoogleSearchConsole;

use App\Models\ClientSite;
use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Services\DataConnectors\ConnectorDatasetDiscoveryAdapter;
use App\Services\DataConnectors\ConnectorProviderActionRequiredException;
use App\Services\DataConnectors\ConnectorProviderHttpClient;
use App\Support\MarketingMetadataRedactor;
use App\Support\SiteUrl;

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
            throw new ConnectorProviderActionRequiredException(
                'Google Search Console property discovery failed with HTTP '.$response->status().$this->providerErrorMessage($response->json()).'.'
            );
        }

        $siteEntries = collect((array) $response->json('siteEntry', []))
            ->filter(fn (mixed $site): bool => is_array($site))
            ->values();

        if ($siteEntries->isEmpty()) {
            throw new ConnectorProviderActionRequiredException(
                'Google Search Console returned 0 properties for this OAuth token. Reconnect and make sure you choose the Google account that owns the Search Console properties.'
            );
        }

        $sites = $siteEntries
            ->filter(fn (mixed $site): bool => is_array($site) && $this->isVerified($site))
            ->values()
            ->all();

        if ($sites === []) {
            throw new ConnectorProviderActionRequiredException(
                'Google Search Console returned '.$siteEntries->count().' properties, but none are verified for this OAuth token.'
            );
        }

        $multipleSites = count($sites) > 1;

        return collect($sites)
            ->map(fn (array $site): array => $this->mapSite($account, $site, $multipleSites))
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
    private function mapSite(ConnectorAccount $account, array $site, bool $multipleSites): array
    {
        $siteUrl = (string) $site['siteUrl'];
        $clientSite = $this->matchClientSite($account, $siteUrl);

        return array_filter([
            'external_dataset_id' => $siteUrl,
            'dataset_type' => 'site',
            'display_name' => $siteUrl,
            'status' => $multipleSites ? ConnectorDataset::STATUS_DISABLED : ConnectorDataset::STATUS_ACTIVE,
            'client_site_id' => $clientSite?->id,
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
                'matched_client_site_id' => $clientSite?->id,
            ]),
        ], fn (mixed $value): bool => $value !== null);
    }

    private function matchClientSite(ConnectorAccount $account, string $siteUrl): ?ClientSite
    {
        $propertyHost = $this->normalizedHost($siteUrl);

        if ($propertyHost === '') {
            return null;
        }

        return ClientSite::query()
            ->where('workspace_id', $account->workspace_id)
            ->where('is_active', true)
            ->get()
            ->first(fn (ClientSite $site): bool => $this->siteMatchesProperty($site, $propertyHost));
    }

    private function siteMatchesProperty(ClientSite $site, string $propertyHost): bool
    {
        foreach ($this->candidateHosts($site) as $candidate) {
            if ($candidate === $propertyHost || str_ends_with($candidate, '.'.$propertyHost) || str_ends_with($propertyHost, '.'.$candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function candidateHosts(ClientSite $site): array
    {
        return collect([
            $site->base_url,
            $site->site_url,
            ...(array) ($site->allowed_domains ?? []),
        ])
            ->map(fn (mixed $value): string => $this->normalizedHost((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizedHost(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, 'sc-domain:')) {
            $host = substr($value, strlen('sc-domain:'));
        } else {
            $host = SiteUrl::hostFromUrl($value) ?: $value;
        }

        return preg_replace('/^www\./', '', strtolower(trim($host, " \t\n\r\0\x0B/"))) ?: '';
    }

    private function apiBaseUrl(): string
    {
        return rtrim((string) config('data_connectors.providers.google_search_console.config_json.api.base_url', 'https://www.googleapis.com/webmasters/v3'), '/');
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('data_connectors.providers.google_search_console.config_json.api.timeout_seconds', 15));
    }

    /**
     * @param mixed $payload
     */
    private function providerErrorMessage(mixed $payload): string
    {
        $message = is_array($payload) ? trim((string) data_get($payload, 'error.message', '')) : '';

        return $message === '' ? '' : ': '.$message;
    }
}
