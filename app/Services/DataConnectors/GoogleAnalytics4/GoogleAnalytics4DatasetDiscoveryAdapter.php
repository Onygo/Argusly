<?php

namespace App\Services\DataConnectors\GoogleAnalytics4;

use App\Models\Connectors\ConnectorAccount;
use App\Services\DataConnectors\ConnectorDatasetDiscoveryAdapter;
use App\Services\DataConnectors\ConnectorProviderActionRequiredException;
use App\Services\DataConnectors\ConnectorProviderHttpClient;
use App\Support\MarketingMetadataRedactor;
use Illuminate\Http\Client\Response;

class GoogleAnalytics4DatasetDiscoveryAdapter implements ConnectorDatasetDiscoveryAdapter
{
    public function __construct(private readonly ConnectorProviderHttpClient $http)
    {
    }

    public function providerKey(): string
    {
        return 'google_analytics_4';
    }

    public function discoverDatasets(ConnectorAccount $account): array
    {
        $datasets = [];
        $accountSummaries = $this->accountSummaries($account);

        if ($accountSummaries === []) {
            throw new ConnectorProviderActionRequiredException(
                'Google Analytics 4 returned 0 account summaries for this OAuth token. Reconnect and make sure you choose a Google account that has access to GA4 properties.'
            );
        }

        foreach ($accountSummaries as $summary) {
            if ($this->supportsDataset('accounts')) {
                $datasets[] = $this->mapAccountSummary($summary);
            }

            foreach ($this->propertySummaries($summary) as $property) {
                if ($this->supportsDataset('properties')) {
                    $datasets[] = $this->mapPropertySummary($summary, $property);
                }

                if ($this->includeDataStreams()) {
                    foreach ($this->dataStreams($account, (string) $property['property']) as $stream) {
                        $datasets[] = $this->mapDataStream($summary, $property, $stream);
                    }
                }
            }
        }

        return $datasets;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function accountSummaries(ConnectorAccount $account): array
    {
        $summaries = [];
        $pageToken = null;

        do {
            $query = array_filter([
                'pageSize' => $this->adminPageSize(),
                'pageToken' => $pageToken,
            ], fn (mixed $value): bool => $value !== null && $value !== '');

            $response = $this->http->get($account, $this->adminBaseUrl().'/accountSummaries', $query, timeout: $this->timeoutSeconds());

            $this->throwIfDiscoveryFailed($response, 'account summary discovery');

            $summaries = array_merge($summaries, array_values(array_filter(
                (array) $response->json('accountSummaries', []),
                fn (mixed $summary): bool => is_array($summary) && trim((string) ($summary['account'] ?? '')) !== ''
            )));

            $pageToken = trim((string) $response->json('nextPageToken', ''));
        } while ($pageToken !== '');

        return $summaries;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<int, array<string, mixed>>
     */
    private function propertySummaries(array $summary): array
    {
        return array_values(array_filter(
            (array) ($summary['propertySummaries'] ?? []),
            fn (mixed $property): bool => is_array($property) && trim((string) ($property['property'] ?? '')) !== ''
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dataStreams(ConnectorAccount $account, string $propertyName): array
    {
        $streams = [];
        $pageToken = null;

        do {
            $query = array_filter([
                'pageSize' => $this->adminPageSize(),
                'pageToken' => $pageToken,
            ], fn (mixed $value): bool => $value !== null && $value !== '');

            $response = $this->http->get(
                $account,
                $this->adminBaseUrl().'/'.trim($propertyName, '/').'/dataStreams',
                $query,
                timeout: $this->timeoutSeconds(),
            );

            $this->throwIfDiscoveryFailed($response, 'data stream discovery');

            $streams = array_merge($streams, array_values(array_filter(
                (array) $response->json('dataStreams', []),
                fn (mixed $stream): bool => is_array($stream) && trim((string) ($stream['name'] ?? '')) !== ''
            )));

            $pageToken = trim((string) $response->json('nextPageToken', ''));
        } while ($pageToken !== '');

        return $streams;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function mapAccountSummary(array $summary): array
    {
        $account = (string) $summary['account'];
        $displayName = trim((string) ($summary['displayName'] ?? '')) ?: $account;

        return [
            'external_dataset_id' => $account,
            'dataset_type' => 'account',
            'display_name' => $displayName,
            'capabilities' => ['analytics.account'],
            'config' => [
                'account' => $account,
                'account_summary' => (string) ($summary['name'] ?? ''),
            ],
            'metadata' => MarketingMetadataRedactor::redact([
                'provider' => 'google_analytics_4',
                'resource_name' => (string) ($summary['name'] ?? ''),
                'account' => $account,
                'display_name' => $displayName,
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $property
     * @return array<string, mixed>
     */
    private function mapPropertySummary(array $summary, array $property): array
    {
        $propertyName = (string) $property['property'];
        $displayName = trim((string) ($property['displayName'] ?? '')) ?: $propertyName;

        return [
            'external_dataset_id' => $propertyName,
            'dataset_type' => 'property',
            'display_name' => $displayName,
            'capabilities' => ['analytics.property', 'analytics.reporting'],
            'sync_frequency' => 'daily',
            'sync_config' => [
                'metrics' => $this->defaultMetrics(),
                'dimensions' => $this->defaultDimensions(),
            ],
            'config' => [
                'property' => $propertyName,
                'property_id' => $this->resourceId($propertyName),
                'account' => (string) ($summary['account'] ?? ''),
                'parent' => (string) ($property['parent'] ?? ''),
            ],
            'metadata' => MarketingMetadataRedactor::redact([
                'provider' => 'google_analytics_4',
                'account' => (string) ($summary['account'] ?? ''),
                'account_display_name' => (string) ($summary['displayName'] ?? ''),
                'property' => $propertyName,
                'property_display_name' => $displayName,
                'property_type' => (string) ($property['propertyType'] ?? ''),
                'can_edit' => (bool) ($property['canEdit'] ?? false),
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $property
     * @param array<string, mixed> $stream
     * @return array<string, mixed>
     */
    private function mapDataStream(array $summary, array $property, array $stream): array
    {
        $streamName = (string) $stream['name'];
        $displayName = trim((string) ($stream['displayName'] ?? '')) ?: $streamName;
        $type = (string) ($stream['type'] ?? '');

        return [
            'external_dataset_id' => $streamName,
            'dataset_type' => 'data_stream',
            'display_name' => $displayName,
            'capabilities' => ['analytics.data_stream'],
            'config' => [
                'property' => (string) $property['property'],
                'property_id' => $this->resourceId((string) $property['property']),
                'data_stream' => $streamName,
                'data_stream_id' => $this->resourceId($streamName),
                'stream_type' => $type,
                'measurement_id' => data_get($stream, 'webStreamData.measurementId'),
            ],
            'metadata' => MarketingMetadataRedactor::redact([
                'provider' => 'google_analytics_4',
                'account' => (string) ($summary['account'] ?? ''),
                'account_display_name' => (string) ($summary['displayName'] ?? ''),
                'property' => (string) $property['property'],
                'property_display_name' => (string) ($property['displayName'] ?? ''),
                'data_stream' => $streamName,
                'data_stream_display_name' => $displayName,
                'stream_type' => $type,
                'default_uri' => data_get($stream, 'webStreamData.defaultUri'),
                'firebase_app_id' => data_get($stream, 'androidAppStreamData.firebaseAppId')
                    ?: data_get($stream, 'iosAppStreamData.firebaseAppId'),
                'raw_stream' => $stream,
            ]),
        ];
    }

    private function throwIfDiscoveryFailed(Response $response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }

        throw new ConnectorProviderActionRequiredException(
            'Google Analytics 4 '.$operation.' failed with HTTP '.$response->status().$this->providerErrorMessage($response->json()).'.'
        );
    }

    /**
     * @return list<string>
     */
    private function defaultMetrics(): array
    {
        return array_values(array_filter(
            (array) config('data_connectors.providers.google_analytics_4.config_json.sync.metrics', []),
            fn (mixed $metric): bool => is_string($metric) && trim($metric) !== ''
        ));
    }

    /**
     * @return list<string>
     */
    private function defaultDimensions(): array
    {
        return array_values(array_filter(
            (array) config('data_connectors.providers.google_analytics_4.config_json.sync.dimensions', []),
            fn (mixed $dimension): bool => is_string($dimension) && trim($dimension) !== ''
        ));
    }

    private function resourceId(string $resourceName): string
    {
        $parts = explode('/', trim($resourceName, '/'));

        return (string) end($parts);
    }

    private function includeDataStreams(): bool
    {
        return (bool) config('data_connectors.providers.google_analytics_4.config_json.discovery.include_data_streams', true)
            && $this->supportsDataset('data_streams');
    }

    private function supportsDataset(string $dataset): bool
    {
        return in_array($dataset, (array) config('data_connectors.providers.google_analytics_4.config_json.datasets', []), true);
    }

    private function adminBaseUrl(): string
    {
        return rtrim((string) config('data_connectors.providers.google_analytics_4.config_json.api.admin_base_url', 'https://analyticsadmin.googleapis.com/v1beta'), '/');
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('data_connectors.providers.google_analytics_4.config_json.api.timeout_seconds', 15));
    }

    private function adminPageSize(): int
    {
        return max(1, min(200, (int) config('data_connectors.providers.google_analytics_4.config_json.api.admin_page_size', 200)));
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
