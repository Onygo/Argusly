<?php

namespace App\Services\Integrations\Google;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Ga4Property;
use App\Models\IntegrationConnection;
use App\Models\Property;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class GoogleAnalyticsAdminService
{
    private const ADMIN_BASE_URL = 'https://analyticsadmin.googleapis.com/v1beta';

    public function __construct(private readonly GoogleTokenService $tokens) {}

    /**
     * @return Collection<int, array{connection: IntegrationConnection, accounts: Collection<int, array{name: string, display_name: string, properties: Collection<int, array{name: string, property_id: string, display_name: string, website_url: string|null, parent: string|null}>}>, error: string|null}>
     */
    public function discoverForConnections(Collection $connections): Collection
    {
        return $connections
            ->filter(fn (IntegrationConnection $connection) => $connection->integration?->key === 'google')
            ->map(function (IntegrationConnection $connection): array {
                try {
                    return [
                        'connection' => $connection,
                        'accounts' => $this->accessibleProperties($connection),
                        'error' => null,
                    ];
                } catch (RuntimeException $exception) {
                    return [
                        'connection' => $connection,
                        'accounts' => collect(),
                        'error' => $exception->getMessage(),
                    ];
                }
            })
            ->values();
    }

    /**
     * @return Collection<int, array{name: string, display_name: string, properties: Collection<int, array{name: string, property_id: string, display_name: string, website_url: string|null, parent: string|null}>}>
     */
    public function accessibleProperties(IntegrationConnection $connection): Collection
    {
        $connection = $this->tokens->refreshIfPossible($connection);

        if ($connection->status !== 'active' || $this->tokens->isExpired($connection) || blank($connection->access_token)) {
            throw new RuntimeException('Reconnect Google integration before discovering GA4 properties.');
        }

        try {
            $accountsPayload = Http::withToken($connection->access_token)
                ->acceptJson()
                ->get(self::ADMIN_BASE_URL.'/accounts')
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            throw new RuntimeException('Google Analytics Admin account discovery failed. Please try again.', previous: $exception);
        }

        if (! is_array($accountsPayload)) {
            throw new RuntimeException('Google Analytics Admin account discovery returned an invalid response.');
        }

        return collect($accountsPayload['accounts'] ?? [])
            ->map(function (array $account) use ($connection): array {
                $accountName = (string) ($account['name'] ?? '');

                return [
                    'name' => $accountName,
                    'display_name' => (string) ($account['displayName'] ?? $accountName),
                    'properties' => $this->propertiesForAccount($connection, $accountName),
                ];
            })
            ->filter(fn (array $account) => filled($account['name']))
            ->values();
    }

    /**
     * @param  array<int, array{name: string, property_id?: int|string|null}>  $properties
     */
    public function storeSelectedProperties(
        IntegrationConnection $connection,
        Account $account,
        Brand $brand,
        array $properties,
    ): Collection {
        $this->assertConnectionBelongsToTenant($connection, $account, $brand);

        $available = $this->accessibleProperties($connection)
            ->flatMap(fn (array $analyticsAccount) => $analyticsAccount['properties'])
            ->keyBy('name');

        if ($available->isEmpty()) {
            throw new RuntimeException('No GA4 properties are available for this Google connection.');
        }

        return collect($properties)
            ->map(function (array $selection) use ($available, $connection, $account, $brand): Ga4Property {
                $propertyName = (string) ($selection['name'] ?? '');
                $property = $available->get($propertyName);

                if (! $property) {
                    throw new InvalidArgumentException('Selected GA4 property is not available to this Google connection.');
                }

                $brandProperty = $this->brandProperty($account, $brand, $selection['property_id'] ?? null);

                $ga4Property = Ga4Property::query()
                    ->where('account_id', $account->id)
                    ->where('brand_id', $brand->id)
                    ->where('integration_connection_id', $connection->id)
                    ->where('metadata->property_id', $property['name'])
                    ->first();

                $attributes = [
                    'account_id' => $account->id,
                    'brand_id' => $brand->id,
                    'integration_connection_id' => $connection->id,
                    'property_id' => $brandProperty?->id,
                    'display_name' => $property['display_name'],
                    'website_url' => $property['website_url'],
                    'status' => 'connected',
                    'metadata' => [
                        'property_id' => $property['name'],
                        'numeric_property_id' => $property['property_id'],
                        'parent' => $property['parent'],
                        'discovered_at' => now()->toIso8601String(),
                    ],
                ];

                if ($ga4Property) {
                    $ga4Property->update($attributes);

                    return $ga4Property->refresh();
                }

                return Ga4Property::query()->create($attributes);
            })
            ->values();
    }

    /**
     * @return Collection<int, array{name: string, property_id: string, display_name: string, website_url: string|null, parent: string|null}>
     */
    private function propertiesForAccount(IntegrationConnection $connection, string $accountName): Collection
    {
        if (blank($accountName)) {
            return collect();
        }

        try {
            $payload = Http::withToken($connection->access_token)
                ->acceptJson()
                ->get(self::ADMIN_BASE_URL.'/properties', [
                    'filter' => "parent:{$accountName}",
                ])
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            throw new RuntimeException('Google Analytics Admin property discovery failed. Please try again.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Google Analytics Admin property discovery returned an invalid response.');
        }

        return collect($payload['properties'] ?? [])
            ->map(fn (array $property) => [
                'name' => (string) ($property['name'] ?? ''),
                'property_id' => str((string) ($property['name'] ?? ''))->after('properties/')->toString(),
                'display_name' => (string) ($property['displayName'] ?? $property['name'] ?? 'GA4 property'),
                'website_url' => $property['websiteUrl'] ?? null,
                'parent' => $property['parent'] ?? $accountName,
            ])
            ->filter(fn (array $property) => filled($property['name']))
            ->values();
    }

    private function brandProperty(Account $account, Brand $brand, mixed $propertyId): ?Property
    {
        if (blank($propertyId)) {
            return null;
        }

        return Property::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->findOrFail((int) $propertyId);
    }

    private function assertConnectionBelongsToTenant(IntegrationConnection $connection, Account $account, Brand $brand): void
    {
        $connection->loadMissing('integration');

        if ($connection->integration?->key !== 'google') {
            throw new InvalidArgumentException('GA4 property discovery requires a Google integration connection.');
        }

        if ($connection->account_id !== $account->id || ($connection->brand_id !== null && $connection->brand_id !== $brand->id)) {
            throw new InvalidArgumentException('Google integration connection must belong to the current account and brand.');
        }
    }
}
