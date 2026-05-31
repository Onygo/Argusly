<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\IntegrationConnection;
use App\Models\Source;
use App\Models\SourceConnection;
use App\Models\SourceSync;
use App\Services\Integrations\Google\GoogleTokenService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class SourceRegistryService
{
    /**
     * @param  array{type?: string|null, provider?: string|null, status?: string|null, scope?: string|null}  $filters
     * @return LengthAwarePaginator<int, Source>
     */
    public function paginatedForTenant(Account $account, ?Brand $brand, array $filters = [], int $perPage = 12): LengthAwarePaginator
    {
        return $this->tenantQuery($account, $brand)
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['provider'] ?? null, fn (Builder $query, string $provider) => $query->where('provider', $provider))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when(($filters['scope'] ?? null) === 'global', fn (Builder $query) => $query->whereNull('account_id')->whereNull('brand_id'))
            ->when(($filters['scope'] ?? null) === 'account', fn (Builder $query) => $query->where('account_id', $account->id)->whereNull('brand_id'))
            ->when(($filters['scope'] ?? null) === 'brand', fn (Builder $query) => $brand ? $query->where('brand_id', $brand->id) : $query->whereRaw('1 = 0'))
            ->withCount(['connections', 'syncs'])
            ->with('brand')
            ->orderBy('provider')
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array{name: string, type: string, provider: string, status?: string|null, scope?: string|null, integration_connection_id?: int|string|null, settings?: array<string, mixed>|null, metadata?: array<string, mixed>|null}  $attributes
     */
    public function create(Account $account, ?Brand $brand, array $attributes): Source
    {
        $this->validateEnums($attributes['type'], $attributes['provider'], $attributes['status'] ?? 'active');
        $scope = $attributes['scope'] ?? 'brand';
        $sourceBrand = $this->brandForScope($account, $brand, $scope);

        $source = Source::query()->create([
            'account_id' => $scope === 'global' ? null : $account->id,
            'brand_id' => $sourceBrand?->id,
            'name' => $attributes['name'],
            'type' => $attributes['type'],
            'provider' => $attributes['provider'],
            'status' => $attributes['status'] ?? 'active',
            'metadata' => $attributes['metadata'] ?? [
                'architecture_only' => true,
                'sync_implementation' => 'not_configured',
            ],
        ]);

        if (($attributes['integration_connection_id'] ?? null) !== null) {
            $this->connect($source, (int) $attributes['integration_connection_id'], $attributes['settings'] ?? null);
        }

        return $source;
    }

    public function connect(Source $source, int $integrationConnectionId, ?array $settings = null): SourceConnection
    {
        $integration = IntegrationConnection::query()->with('integration')->findOrFail($integrationConnectionId);

        if ($source->account_id !== null && $integration->account_id !== null && $source->account_id !== $integration->account_id) {
            throw new InvalidArgumentException('Integration connection must belong to the same account as the source.');
        }

        if ($source->brand_id !== null && $integration->brand_id !== null && $source->brand_id !== $integration->brand_id) {
            throw new InvalidArgumentException('Integration connection must belong to the same brand as the source.');
        }

        if ($integration->integration?->key !== $source->provider) {
            throw new InvalidArgumentException('Integration connection provider must match the source provider.');
        }

        return SourceConnection::query()->updateOrCreate(
            [
                'source_id' => $source->id,
                'integration_connection_id' => $integration->id,
            ],
            [
                'status' => 'configured',
                'settings' => $settings ?? ['sync_enabled' => false],
            ],
        );
    }

    public function createPlannedSync(Source $source): SourceSync
    {
        $this->assertSourceCanSync($source);

        return $source->syncs()->create([
            'status' => 'planned',
            'started_at' => null,
            'completed_at' => null,
            'records_found' => null,
            'error' => null,
        ]);
    }

    private function assertSourceCanSync(Source $source): void
    {
        if ($source->provider !== 'google') {
            return;
        }

        $source->loadMissing('connections.integrationConnection.integration');

        $connection = $source->connections
            ->map(fn (SourceConnection $sourceConnection) => $sourceConnection->integrationConnection)
            ->filter(fn (?IntegrationConnection $connection) => $connection?->integration?->key === 'google')
            ->first();

        if (! $connection) {
            throw new InvalidArgumentException('Reconnect Google integration before syncing GA4 or Search Console.');
        }

        $connection = app(GoogleTokenService::class)->refreshIfPossible($connection);

        if ($connection->status !== 'active' || app(GoogleTokenService::class)->isExpired($connection)) {
            throw new InvalidArgumentException('Reconnect Google integration before syncing GA4 or Search Console.');
        }
    }

    public function completeSync(SourceSync $sync, int $recordsFound = 0): SourceSync
    {
        $sync->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
            'records_found' => $recordsFound,
            'error' => null,
        ])->save();

        app(DomainEventService::class)->recordForSubject('SourceSyncCompleted', $sync, null, [
            'source_id' => $sync->source_id,
            'records_found' => $sync->records_found,
            'started_at' => $sync->started_at?->toDateTimeString(),
            'completed_at' => $sync->completed_at?->toDateTimeString(),
        ], $sync->completed_at);

        return $sync->refresh();
    }

    public function findForTenant(Account $account, ?Brand $brand, int $id): Source
    {
        return $this->tenantQuery($account, $brand)
            ->with(['brand', 'connections.integrationConnection.integration', 'syncs' => fn ($query) => $query->latest()])
            ->withCount(['connections', 'syncs'])
            ->findOrFail($id);
    }

    /**
     * @return LengthAwarePaginator<int, SourceSync>
     */
    public function syncHistory(Account $account, ?Brand $brand, ?Source $source = null, int $perPage = 20): LengthAwarePaginator
    {
        $sourceIds = $source
            ? collect([$source->id])
            : $this->tenantQuery($account, $brand)->pluck('id');

        return SourceSync::query()
            ->whereIn('source_id', $sourceIds)
            ->with('source')
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return Collection<int, IntegrationConnection>
     */
    public function integrationConnections(Account $account, ?Brand $brand): Collection
    {
        return IntegrationConnection::query()
            ->where(function (Builder $scope) use ($account): void {
                $scope->whereNull('account_id')
                    ->orWhere('account_id', $account->id);
            })
            ->when(
                $brand !== null,
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->whereNull('brand_id')
                    ->orWhere('brand_id', $brand->id)),
                fn (Builder $query) => $query->whereNull('brand_id'),
            )
            ->with('integration')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Builder<Source>
     */
    private function tenantQuery(Account $account, ?Brand $brand): Builder
    {
        return Source::query()
            ->where(function (Builder $scope) use ($account, $brand): void {
                $scope->whereNull('account_id')
                    ->orWhere(function (Builder $accountScope) use ($account, $brand): void {
                        $accountScope->where('account_id', $account->id)
                            ->where(function (Builder $brandScope) use ($brand): void {
                                $brandScope->whereNull('brand_id')
                                    ->when($brand !== null, fn (Builder $query) => $query->orWhere('brand_id', $brand->id));
                            });
                    });
            });
    }

    private function brandForScope(Account $account, ?Brand $brand, string $scope): ?Brand
    {
        if ($scope === 'global' || $scope === 'account') {
            return null;
        }

        if ($brand === null) {
            throw new InvalidArgumentException('A brand context is required for brand-scoped sources.');
        }

        if ($brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Source brand must belong to the same account.');
        }

        return $brand;
    }

    private function validateEnums(string $type, string $provider, string $status): void
    {
        if (! in_array($type, Source::TYPES, true)) {
            throw new InvalidArgumentException("Invalid source type [{$type}].");
        }

        if (! in_array($provider, Source::PROVIDERS, true)) {
            throw new InvalidArgumentException("Invalid source provider [{$provider}].");
        }

        if (! in_array($status, Source::STATUSES, true)) {
            throw new InvalidArgumentException("Invalid source status [{$status}].");
        }
    }
}
