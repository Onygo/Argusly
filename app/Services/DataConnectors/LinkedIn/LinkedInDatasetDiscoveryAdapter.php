<?php

namespace App\Services\DataConnectors\LinkedIn;

use App\Models\Connectors\ConnectorAccount;
use App\Services\DataConnectors\ConnectorDatasetDiscoveryAdapter;
use App\Services\DataConnectors\ConnectorProviderHttpClient;
use App\Support\MarketingMetadataRedactor;
use Illuminate\Http\Client\Response;
use RuntimeException;

class LinkedInDatasetDiscoveryAdapter implements ConnectorDatasetDiscoveryAdapter
{
    public function __construct(private readonly ConnectorProviderHttpClient $http)
    {
    }

    public function providerKey(): string
    {
        return 'linkedin';
    }

    public function discoverDatasets(ConnectorAccount $account): array
    {
        if (! $this->shouldDiscoverOrganizationPages()) {
            return [];
        }

        $datasets = [];
        $start = 0;
        $count = $this->pageSize();

        do {
            $response = $this->http->get($account, $this->apiBaseUrl().'/organizationalEntityAcls', array_filter([
                    'q' => 'roleAssignee',
                    'role' => $this->discoveryRole(),
                    'state' => $this->discoveryState(),
                    'start' => $start,
                    'count' => $count,
                    'projection' => '(elements*(role,state,organizationalTarget,organizationalTarget~(id,localizedName,vanityName)))',
                ], fn (mixed $value): bool => $value !== null && $value !== ''), $this->headers(), $this->timeoutSeconds());

            $this->throwIfDiscoveryFailed($response);

            $elements = array_values(array_filter(
                (array) $response->json('elements', []),
                fn (mixed $element): bool => is_array($element) && $this->organizationUrn($element) !== ''
            ));

            foreach ($elements as $element) {
                $datasets[] = $this->mapOrganization($element);
            }

            $hasMore = $this->hasMore($response, $start, $count, count($elements));
            $start += $count;
        } while ($hasMore);

        return $datasets;
    }

    /**
     * @param array<string, mixed> $element
     * @return array<string, mixed>
     */
    private function mapOrganization(array $element): array
    {
        $organization = $this->expandedOrganization($element);
        $organizationUrn = $this->organizationUrn($element);
        $organizationId = $this->organizationId($organizationUrn, $organization);
        $displayName = trim((string) ($organization['localizedName'] ?? $organization['name'] ?? '')) ?: $organizationUrn;

        return [
            'external_dataset_id' => $organizationUrn,
            'dataset_type' => 'organization_page',
            'display_name' => $displayName,
            'capabilities' => ['social.organization', 'social.analytics', 'social.organic_analytics'],
            'sync_frequency' => 'daily',
            'sync_config' => [
                'resources' => $this->defaultResources(),
                'metrics' => $this->defaultMetrics(),
                'dimensions' => $this->defaultDimensions(),
            ],
            'config' => [
                'organization_urn' => $organizationUrn,
                'organization_id' => $organizationId,
            ],
            'metadata' => MarketingMetadataRedactor::redact([
                'provider' => 'linkedin',
                'role' => (string) ($element['role'] ?? ''),
                'state' => (string) ($element['state'] ?? ''),
                'organization_urn' => $organizationUrn,
                'organization_id' => $organizationId,
                'localized_name' => $displayName,
                'vanity_name' => (string) ($organization['vanityName'] ?? ''),
                'raw_organization' => $organization,
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $element
     * @return array<string, mixed>
     */
    private function expandedOrganization(array $element): array
    {
        $organization = $element['organizationalTarget~']
            ?? $element['organization~']
            ?? $element['target~']
            ?? [];

        return is_array($organization) ? $organization : [];
    }

    /**
     * @param array<string, mixed> $element
     */
    private function organizationUrn(array $element): string
    {
        foreach (['organizationalTarget', 'organization', 'target', 'organizationUrn'] as $key) {
            $value = trim((string) ($element[$key] ?? ''));

            if ($value !== '') {
                return str_starts_with($value, 'urn:li:organization:')
                    ? $value
                    : 'urn:li:organization:'.$value;
            }
        }

        $organization = $this->expandedOrganization($element);
        $id = trim((string) ($organization['id'] ?? ''));

        return $id === '' ? '' : 'urn:li:organization:'.$id;
    }

    /**
     * @param array<string, mixed> $organization
     */
    private function organizationId(string $organizationUrn, array $organization): string
    {
        $id = trim((string) ($organization['id'] ?? ''));

        if ($id !== '') {
            return $id;
        }

        $parts = explode(':', $organizationUrn);

        return (string) end($parts);
    }

    private function throwIfDiscoveryFailed(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException('LinkedIn organization discovery failed with status '.$response->status().'.');
    }

    private function hasMore(Response $response, int $start, int $count, int $rowCount): bool
    {
        $total = $response->json('paging.total');

        if (is_numeric($total)) {
            return ($start + $count) < (int) $total;
        }

        return $rowCount >= $count;
    }

    /**
     * @return list<string>
     */
    private function defaultResources(): array
    {
        return array_values(array_filter(
            (array) config('data_connectors.providers.linkedin.config_json.sync.resources', []),
            fn (mixed $resource): bool => is_string($resource) && trim($resource) !== ''
        ));
    }

    /**
     * @return list<string>
     */
    private function defaultMetrics(): array
    {
        return array_values(array_filter(
            (array) config('data_connectors.providers.linkedin.config_json.sync.metrics', []),
            fn (mixed $metric): bool => is_string($metric) && trim($metric) !== ''
        ));
    }

    /**
     * @return list<string>
     */
    private function defaultDimensions(): array
    {
        return array_values(array_filter(
            (array) config('data_connectors.providers.linkedin.config_json.sync.dimensions', []),
            fn (mixed $dimension): bool => is_string($dimension) && trim($dimension) !== ''
        ));
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = [
            'X-Restli-Protocol-Version' => '2.0.0',
        ];

        $version = trim((string) config('data_connectors.providers.linkedin.config_json.api.linkedin_version', ''));

        if ($version !== '') {
            $headers['LinkedIn-Version'] = $version;
        }

        return $headers;
    }

    private function shouldDiscoverOrganizationPages(): bool
    {
        $datasets = (array) config('data_connectors.providers.linkedin.config_json.datasets', []);

        return (bool) config('data_connectors.providers.linkedin.config_json.discovery.include_organization_pages', true)
            && (in_array('organizations', $datasets, true) || in_array('organization_pages', $datasets, true));
    }

    private function discoveryRole(): string
    {
        return trim((string) config('data_connectors.providers.linkedin.config_json.discovery.role', 'ADMINISTRATOR'));
    }

    private function discoveryState(): string
    {
        return trim((string) config('data_connectors.providers.linkedin.config_json.discovery.state', 'APPROVED'));
    }

    private function apiBaseUrl(): string
    {
        return rtrim((string) config('data_connectors.providers.linkedin.config_json.api.base_url', 'https://api.linkedin.com/v2'), '/');
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('data_connectors.providers.linkedin.config_json.api.timeout_seconds', 15));
    }

    private function pageSize(): int
    {
        return max(1, min(1000, (int) config('data_connectors.providers.linkedin.config_json.api.page_size', 100)));
    }
}
