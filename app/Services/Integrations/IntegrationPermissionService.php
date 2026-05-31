<?php

namespace App\Services\Integrations;

use App\Models\Account;
use App\Models\Brand;
use App\Models\IntegrationConnection;
use App\Models\IntegrationPermission;
use App\Models\User;
use InvalidArgumentException;

class IntegrationPermissionService
{
    public function canUse(User $user, IntegrationConnection $connection, ?Account $account = null, ?Brand $brand = null): bool
    {
        return $this->hasPermission($user, $connection, 'use', $account, $brand)
            || $this->hasPermission($user, $connection, 'manage', $account, $brand);
    }

    public function canManage(User $user, IntegrationConnection $connection, ?Account $account = null, ?Brand $brand = null): bool
    {
        return $this->hasPermission($user, $connection, 'manage', $account, $brand);
    }

    public function shareWithAccount(IntegrationConnection $connection, Account $account, User $grantedBy, string $permission = 'use'): IntegrationPermission
    {
        $this->assertPermission($permission);
        $this->assertConnectionCanBeSharedWithAccount($connection, $account);

        return $connection->permissions()->updateOrCreate(
            [
                'user_id' => null,
                'account_id' => $account->id,
                'brand_id' => null,
                'permission' => $permission,
            ],
            [
                'granted_by_user_id' => $grantedBy->id,
                'starts_at' => now(),
                'expires_at' => null,
            ],
        );
    }

    public function shareWithBrand(IntegrationConnection $connection, Brand $brand, User $grantedBy, string $permission = 'use'): IntegrationPermission
    {
        $this->assertPermission($permission);
        $this->assertConnectionCanBeSharedWithBrand($connection, $brand);

        return $connection->permissions()->updateOrCreate(
            [
                'user_id' => null,
                'account_id' => $brand->account_id,
                'brand_id' => $brand->id,
                'permission' => $permission,
            ],
            [
                'granted_by_user_id' => $grantedBy->id,
                'starts_at' => now(),
                'expires_at' => null,
            ],
        );
    }

    public function shareWithUser(IntegrationConnection $connection, User $user, User $grantedBy, string $permission = 'use'): IntegrationPermission
    {
        $this->assertPermission($permission);
        $this->assertUserCanReceiveConnection($connection, $user);

        return $connection->permissions()->updateOrCreate(
            [
                'user_id' => $user->id,
                'account_id' => null,
                'brand_id' => null,
                'permission' => $permission,
            ],
            [
                'granted_by_user_id' => $grantedBy->id,
                'starts_at' => now(),
                'expires_at' => null,
            ],
        );
    }

    private function hasPermission(User $user, IntegrationConnection $connection, string $permission, ?Account $account, ?Brand $brand): bool
    {
        if (! IntegrationConnection::query()->active()->whereKey($connection->id)->exists()) {
            return false;
        }

        if (! $this->contextMatchesConnection($connection, $account, $brand)) {
            return false;
        }

        if (! $this->userCanAccessContext($user, $account, $brand)) {
            return false;
        }

        if (! $this->userCanAccessConnectionScope($connection, $user)) {
            return false;
        }

        if ($connection->owner_user_id === $user->id && $permission === 'manage') {
            return true;
        }

        return $connection->permissions()
            ->active()
            ->where('permission', $permission)
            ->where(function ($query) use ($user, $account, $brand): void {
                $query->where('user_id', $user->id);

                if ($account) {
                    $query->orWhere(function ($accountQuery) use ($user, $account): void {
                        $accountQuery->whereNull('user_id')
                            ->whereNull('brand_id')
                            ->where('account_id', $account->id)
                            ->whereExists(function ($membershipQuery) use ($user, $account): void {
                                $membershipQuery->selectRaw('1')
                                    ->from('memberships')
                                    ->whereColumn('memberships.account_id', 'integration_permissions.account_id')
                                    ->where('memberships.user_id', $user->id)
                                    ->where('memberships.account_id', $account->id)
                                    ->where('memberships.status', 'active');
                            });
                    });
                }

                if ($brand) {
                    $query->orWhere(function ($brandQuery) use ($user, $brand): void {
                        $brandQuery->whereNull('user_id')
                            ->where('account_id', $brand->account_id)
                            ->where('brand_id', $brand->id)
                            ->whereExists(function ($membershipQuery) use ($user, $brand): void {
                                $membershipQuery->selectRaw('1')
                                    ->from('brand_memberships')
                                    ->whereColumn('brand_memberships.brand_id', 'integration_permissions.brand_id')
                                    ->where('brand_memberships.user_id', $user->id)
                                    ->where('brand_memberships.brand_id', $brand->id)
                                    ->where('brand_memberships.status', 'active');
                            });
                    });
                }
            })
            ->exists();
    }

    private function assertPermission(string $permission): void
    {
        if (! in_array($permission, config('integrations.permission_levels', []), true)) {
            throw new InvalidArgumentException("Invalid integration permission [{$permission}].");
        }
    }

    private function assertConnectionCanBeSharedWithAccount(IntegrationConnection $connection, Account $account): void
    {
        if ($connection->account_id !== null && $connection->account_id !== $account->id) {
            throw new InvalidArgumentException('A connection cannot be shared with an account outside its scope.');
        }

        if ($connection->brand_id !== null && $connection->account_id !== $account->id) {
            throw new InvalidArgumentException('A brand-scoped connection cannot be shared outside its account.');
        }
    }

    private function assertConnectionCanBeSharedWithBrand(IntegrationConnection $connection, Brand $brand): void
    {
        if ($connection->account_id !== null && $connection->account_id !== $brand->account_id) {
            throw new InvalidArgumentException('A connection cannot be shared with a brand outside its account.');
        }

        if ($connection->brand_id !== null && $connection->brand_id !== $brand->id) {
            throw new InvalidArgumentException('A brand-scoped connection cannot be shared with another brand.');
        }
    }

    private function assertUserCanReceiveConnection(IntegrationConnection $connection, User $user): void
    {
        if (! $this->userCanAccessConnectionScope($connection, $user)) {
            throw new InvalidArgumentException('A connection cannot be shared with a user outside its account or brand scope.');
        }
    }

    private function contextMatchesConnection(IntegrationConnection $connection, ?Account $account, ?Brand $brand): bool
    {
        if ($brand && $account && $brand->account_id !== $account->id) {
            return false;
        }

        if ($connection->account_id !== null && $account !== null && $connection->account_id !== $account->id) {
            return false;
        }

        if ($connection->brand_id !== null && $brand !== null && $connection->brand_id !== $brand->id) {
            return false;
        }

        if ($connection->brand_id !== null && $brand === null) {
            return false;
        }

        return true;
    }

    private function userCanAccessContext(User $user, ?Account $account, ?Brand $brand): bool
    {
        if ($account && ! $this->userBelongsToAccount($user, $account->id)) {
            return false;
        }

        if ($brand && ! $this->userBelongsToBrand($user, $brand->id, $brand->account_id)) {
            return false;
        }

        return true;
    }

    private function userCanAccessConnectionScope(IntegrationConnection $connection, User $user): bool
    {
        if ($connection->brand_id !== null) {
            return $this->userBelongsToBrand($user, $connection->brand_id, $connection->account_id);
        }

        if ($connection->account_id !== null) {
            return $this->userBelongsToAccount($user, $connection->account_id);
        }

        return true;
    }

    private function userBelongsToAccount(User $user, int $accountId): bool
    {
        return $user->memberships()
            ->where('account_id', $accountId)
            ->where('status', 'active')
            ->whereHas('account', fn ($query) => $query->where('status', 'active'))
            ->exists();
    }

    private function userBelongsToBrand(User $user, int $brandId, ?int $accountId): bool
    {
        return $user->brandMemberships()
            ->where('brand_id', $brandId)
            ->when($accountId !== null, fn ($query) => $query->where('account_id', $accountId))
            ->where('status', 'active')
            ->whereHas('brand', fn ($query) => $query->where('status', 'active'))
            ->exists();
    }
}
