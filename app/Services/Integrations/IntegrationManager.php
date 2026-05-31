<?php

namespace App\Services\Integrations;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\IntegrationConnection;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class IntegrationManager
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly IntegrationPermissionService $permissions,
    ) {}

    /**
     * @return Collection<int, Integration>
     */
    public function registerProviders(): Collection
    {
        return $this->providers->definitions()
            ->map(function (array $definition, string $key): Integration {
                return Integration::query()->updateOrCreate(
                    ['key' => $key],
                    [
                        'name' => $definition['name'],
                        'auth_type' => $definition['auth_type'],
                        'default_scopes' => $definition['scopes'],
                        'supports_refresh_tokens' => $definition['auth_type'] === 'oauth2',
                        'is_active' => true,
                        'is_system' => true,
                    ],
                );
            })
            ->values();
    }

    public function enableProvider(string $provider): Integration
    {
        $integration = $this->providers->integration($provider);
        $integration->forceFill(['is_active' => true])->save();

        return $integration->refresh();
    }

    public function disableProvider(string $provider): Integration
    {
        $integration = $this->providers->integration($provider);
        $integration->forceFill(['is_active' => false])->save();

        return $integration->refresh();
    }

    public function providerEnabled(string $provider): bool
    {
        return $this->providers->isSupported($provider)
            && Integration::query()->where('key', $provider)->where('is_active', true)->exists();
    }

    public function validatePermissions(User $user, IntegrationConnection $connection, string $permission, ?Account $account = null, ?Brand $brand = null): bool
    {
        if (! in_array($permission, config('integrations.permission_levels', []), true)) {
            throw new InvalidArgumentException("Invalid integration permission [{$permission}].");
        }

        return match ($permission) {
            'use' => $this->permissions->canUse($user, $connection, $account, $brand),
            'manage' => $this->permissions->canManage($user, $connection, $account, $brand),
            default => false,
        };
    }

    public function checkAccountAccess(User $user, Account $account): bool
    {
        return $user->memberships()
            ->where('account_id', $account->id)
            ->where('status', 'active')
            ->whereHas('account', fn ($query) => $query->where('status', 'active'))
            ->exists();
    }

    public function checkBrandAccess(User $user, Brand $brand): bool
    {
        return $user->brandMemberships()
            ->where('brand_id', $brand->id)
            ->where('account_id', $brand->account_id)
            ->where('status', 'active')
            ->whereHas('brand', fn ($query) => $query->where('status', 'active'))
            ->exists();
    }

    /**
     * @return Collection<int, IntegrationConnection>
     */
    public function connectionsFor(User $user, Account $account, ?Brand $brand = null): Collection
    {
        return IntegrationConnection::query()
            ->active()
            ->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn ($query) => $query->where(fn ($scope) => $scope->whereNull('brand_id')->orWhere('brand_id', $brand->id)),
                fn ($query) => $query->whereNull('brand_id'),
            )
            ->with(['integration', 'brand'])
            ->get()
            ->filter(fn (IntegrationConnection $connection) => $this->validatePermissions($user, $connection, 'use', $account, $brand))
            ->values();
    }
}
