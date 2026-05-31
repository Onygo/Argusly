<?php

namespace App\Services\Integrations\Google;

use App\Models\Account;
use App\Models\Brand;
use App\Models\IntegrationConnection;
use App\Models\SearchConsoleSite;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class SearchConsoleService
{
    private const SITES_URL = 'https://www.googleapis.com/webmasters/v3/sites';

    public function __construct(private readonly GoogleTokenService $tokens) {}

    /**
     * @return Collection<int, array{connection: IntegrationConnection, sites: Collection<int, array{site_url: string, permission_level: string, site_type: string, verification_state: string}>, error: string|null}>
     */
    public function discoverForConnections(Collection $connections): Collection
    {
        return $connections
            ->filter(fn (IntegrationConnection $connection) => $connection->integration?->key === 'google')
            ->map(function (IntegrationConnection $connection): array {
                try {
                    return [
                        'connection' => $connection,
                        'sites' => $this->accessibleSites($connection),
                        'error' => null,
                    ];
                } catch (RuntimeException $exception) {
                    return [
                        'connection' => $connection,
                        'sites' => collect(),
                        'error' => $exception->getMessage(),
                    ];
                }
            })
            ->values();
    }

    /**
     * @return Collection<int, array{site_url: string, permission_level: string, site_type: string, verification_state: string}>
     */
    public function accessibleSites(IntegrationConnection $connection): Collection
    {
        $connection = $this->tokens->refreshIfPossible($connection);

        if ($connection->status !== 'active' || $this->tokens->isExpired($connection) || blank($connection->access_token)) {
            throw new RuntimeException('Reconnect Google integration before discovering Search Console sites.');
        }

        try {
            $payload = Http::withToken($connection->access_token)
                ->acceptJson()
                ->get(self::SITES_URL)
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            throw new RuntimeException('Search Console site discovery failed. Please try again.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Search Console site discovery returned an invalid response.');
        }

        return collect($payload['siteEntry'] ?? [])
            ->map(fn (array $site) => $this->normalizeSite($site))
            ->filter(fn (array $site) => filled($site['site_url']) && $site['verification_state'] === 'verified')
            ->values();
    }

    /**
     * @param  array<int, string>  $siteUrls
     */
    public function storeSelectedSites(
        IntegrationConnection $connection,
        Account $account,
        Brand $brand,
        array $siteUrls,
    ): Collection {
        $this->assertConnectionBelongsToTenant($connection, $account, $brand);

        $available = $this->accessibleSites($connection)->keyBy('site_url');

        if ($available->isEmpty()) {
            throw new RuntimeException('No verified Search Console sites are available for this Google connection.');
        }

        return collect($siteUrls)
            ->map(function (string $siteUrl) use ($available, $connection, $account, $brand): SearchConsoleSite {
                $site = $available->get($siteUrl);

                if (! $site) {
                    throw new InvalidArgumentException('Selected Search Console site is not available to this Google connection.');
                }

                return SearchConsoleSite::query()->updateOrCreate(
                    [
                        'brand_id' => $brand->id,
                        'site_url' => $site['site_url'],
                    ],
                    [
                        'account_id' => $account->id,
                        'integration_connection_id' => $connection->id,
                        'status' => 'connected',
                        'metadata' => [
                            'permission_level' => $site['permission_level'],
                            'site_type' => $site['site_type'],
                            'verification_state' => $site['verification_state'],
                            'discovered_at' => now()->toIso8601String(),
                        ],
                    ],
                );
            })
            ->values();
    }

    /**
     * @return array{site_url: string, permission_level: string, site_type: string, verification_state: string}
     */
    private function normalizeSite(array $site): array
    {
        $siteUrl = (string) ($site['siteUrl'] ?? '');
        $permissionLevel = (string) ($site['permissionLevel'] ?? 'unknown');

        return [
            'site_url' => $siteUrl,
            'permission_level' => $permissionLevel,
            'site_type' => str_starts_with($siteUrl, 'sc-domain:') ? 'domain' : 'url-prefix',
            'verification_state' => $permissionLevel === 'siteUnverifiedUser' ? 'unverified' : 'verified',
        ];
    }

    private function assertConnectionBelongsToTenant(IntegrationConnection $connection, Account $account, Brand $brand): void
    {
        $connection->loadMissing('integration');

        if ($connection->integration?->key !== 'google') {
            throw new InvalidArgumentException('Search Console discovery requires a Google integration connection.');
        }

        if ($connection->account_id !== $account->id || ($connection->brand_id !== null && $connection->brand_id !== $brand->id)) {
            throw new InvalidArgumentException('Google integration connection must belong to the current account and brand.');
        }
    }
}
