<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Subscriptions\ModuleAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class PermissionService
{
    public function __construct(private readonly ModuleAccessService $moduleAccess) {}

    /**
     * Determine whether an ability is managed by the permission system.
     */
    public function isKnownPermission(string $permission): bool
    {
        if (in_array($permission, $this->configuredPermissionNames(), true)) {
            return true;
        }

        return Schema::hasTable('permissions')
            && Permission::query()->where('name', $permission)->exists();
    }

    /**
     * Determine whether the user has a role in the given scope.
     *
     * @param  string|array<int, string>  $roles
     * @param  array{account_id?: int|null, brand_id?: int|null}  $context
     */
    public function userHasRole(User $user, string|array $roles, array $context = []): bool
    {
        $roleNames = (array) $roles;

        return $user->roleAssignments()
            ->whereHas('role', fn (Builder $query) => $query->whereIn('name', $roleNames))
            ->tap(fn (Builder $query) => $this->applyActiveWindow($query))
            ->tap(fn (Builder $query) => $this->applyScope($query, $context))
            ->exists();
    }

    /**
     * Determine whether the user can perform a permission in the given scope.
     *
     * @param  array{account_id?: int|null, brand_id?: int|null}  $context
     */
    public function userCan(User $user, string $permission, array $context = []): bool
    {
        if (! $this->isKnownPermission($permission)) {
            return false;
        }

        if (! $this->tenantContextIsAccessible($user, $context)) {
            return false;
        }

        if (! $this->accountHasRequiredModule($permission, $context)) {
            return false;
        }

        return $user->roleAssignments()
            ->whereHas('role', function (Builder $query) use ($permission): void {
                $query->where('all_permissions', true)
                    ->orWhereHas('permissions', fn (Builder $permissionQuery) => $permissionQuery->where('name', $permission));
            })
            ->tap(fn (Builder $query) => $this->applyActiveWindow($query))
            ->tap(fn (Builder $query) => $this->applyScope($query, $context))
            ->exists();
    }

    /**
     * Return all configured permission slugs.
     *
     * @return array<int, string>
     */
    public function configuredPermissionNames(): array
    {
        return collect(config('permissions.permissions', []))
            ->flatten()
            ->values()
            ->all();
    }

    /**
     * @param  Builder<UserRole>  $query
     */
    private function applyActiveWindow(Builder $query): void
    {
        $query->where(function (Builder $window): void {
            $window->whereNull('starts_at')
                ->orWhere('starts_at', '<=', now());
        })->where(function (Builder $window): void {
            $window->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * @param  Builder<UserRole>  $query
     * @param  array{account_id?: int|null, brand_id?: int|null}  $context
     */
    private function applyScope(Builder $query, array $context): void
    {
        $accountId = $context['account_id'] ?? null;
        $brandId = $context['brand_id'] ?? null;

        $query->where(function (Builder $scope) use ($accountId): void {
            $scope->whereNull('account_id');

            if ($accountId !== null) {
                $scope->orWhere('account_id', $accountId);
            }
        })->where(function (Builder $scope) use ($brandId): void {
            $scope->whereNull('brand_id');

            if ($brandId !== null) {
                $scope->orWhere('brand_id', $brandId);
            }
        });
    }

    /**
     * @param  array{account_id?: int|null, brand_id?: int|null}  $context
     */
    private function tenantContextIsAccessible(User $user, array $context): bool
    {
        $accountId = $context['account_id'] ?? null;
        $brandId = $context['brand_id'] ?? null;

        if ($accountId !== null) {
            $hasAccount = $user->memberships()
                ->where('account_id', $accountId)
                ->where('status', 'active')
                ->whereHas('account', fn (Builder $query) => $query->where('status', 'active'))
                ->exists();

            if (! $hasAccount) {
                return false;
            }
        }

        if ($brandId !== null) {
            return $user->brandMemberships()
                ->where('brand_id', $brandId)
                ->when($accountId !== null, fn (Builder $query) => $query->where('account_id', $accountId))
                ->where('status', 'active')
                ->whereHas('brand', fn (Builder $query) => $query->where('status', 'active'))
                ->exists();
        }

        return true;
    }

    /**
     * @param  array{account_id?: int|null, brand_id?: int|null}  $context
     */
    private function accountHasRequiredModule(string $permission, array $context): bool
    {
        $accountId = $context['account_id'] ?? null;
        $moduleKeys = $this->modulesForPermission($permission);

        if ($accountId === null || $moduleKeys === []) {
            return true;
        }

        return $this->moduleAccess->accountHasAnyModuleId($accountId, $moduleKeys);
    }

    /**
     * @return array<int, string>
     */
    private function modulesForPermission(string $permission): array
    {
        return collect(config('permissions.module_requirements', []))
            ->filter(fn (array $permissions) => in_array($permission, $permissions, true))
            ->keys()
            ->values()
            ->all();
    }
}
