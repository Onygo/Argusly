<?php

namespace App\Services\SocialProfiles;

use App\Models\Account;
use App\Models\Brand;
use App\Models\IntegrationConnection;
use App\Models\SocialProfile;
use App\Models\SocialProfilePermission;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\DomainEventService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SocialProfileService
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly DomainEventService $events,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function createFromIntegrationConnection(
        IntegrationConnection $connection,
        User $owner,
        string $provider,
        string $displayName,
        string $type = 'person',
        ?string $providerProfileId = null,
        ?string $profileUrl = null,
        ?string $avatarUrl = null,
        ?Account $account = null,
        ?Brand $brand = null,
        array $metadata = [],
    ): SocialProfile {
        $this->assertOwnerCanUseConnection($connection, $owner);
        $this->assertContext($account, $brand);

        return DB::transaction(function () use ($connection, $owner, $provider, $displayName, $type, $providerProfileId, $profileUrl, $avatarUrl, $account, $brand, $metadata): SocialProfile {
            $profile = SocialProfile::query()->create([
                'account_id' => $account?->id ?? $brand?->account_id,
                'brand_id' => $brand?->id,
                'integration_connection_id' => $connection->id,
                'owner_user_id' => $owner->id,
                'provider' => $provider,
                'provider_profile_id' => $providerProfileId,
                'display_name' => $displayName,
                'profile_url' => $profileUrl,
                'avatar_url' => $avatarUrl,
                'type' => $type,
                'status' => 'connected',
                'metadata' => $metadata,
            ]);

            $this->activity->log(
                event: 'social_profile.connected',
                description: "Social profile {$profile->display_name} was connected.",
                account: $profile->account,
                brand: $profile->brand,
                user: $owner,
                subject: $profile,
                properties: [
                    'provider' => $provider,
                    'type' => $type,
                    'integration_connection_id' => $connection->id,
                ],
            );

            if ($profile->account_id !== null) {
                $this->events->recordForSubject('SocialProfileConnected', $profile, $owner, [
                    'provider' => $provider,
                    'type' => $type,
                    'integration_connection_id' => $connection->id,
                ]);
            }

            $this->grantOwnerDefaultPermissions($profile, $owner, $account, $brand);

            return $profile;
        });
    }

    public function grantOwnerDefaultPermissions(SocialProfile $profile, User $owner, ?Account $account = null, ?Brand $brand = null): SocialProfilePermission
    {
        $this->assertContext($account, $brand);

        return $profile->permissions()->updateOrCreate(
            [
                'user_id' => $owner->id,
                'account_id' => $account?->id,
                'brand_id' => $brand?->id,
            ],
            [
                'can_view' => true,
                'can_prepare' => true,
                'can_schedule' => true,
                'can_publish' => true,
                'can_manage' => true,
            ],
        );
    }

    /**
     * @param  array{view?: bool, prepare?: bool, schedule?: bool, publish?: bool, manage?: bool}  $abilities
     */
    public function shareWithAccount(SocialProfile $profile, Account $account, User $grantedBy, array $abilities): SocialProfilePermission
    {
        $this->assertCanManage($profile, $grantedBy);

        return $this->grant($profile, null, $account, null, $grantedBy, $abilities);
    }

    /**
     * @param  array{view?: bool, prepare?: bool, schedule?: bool, publish?: bool, manage?: bool}  $abilities
     */
    public function shareWithBrand(SocialProfile $profile, Brand $brand, User $grantedBy, array $abilities): SocialProfilePermission
    {
        $this->assertCanManage($profile, $grantedBy);

        return $this->grant($profile, null, $brand->account, $brand, $grantedBy, $abilities);
    }

    /**
     * @param  array{view?: bool, prepare?: bool, schedule?: bool, publish?: bool, manage?: bool}  $abilities
     */
    public function shareWithUser(SocialProfile $profile, User $user, User $grantedBy, array $abilities, ?Account $account = null, ?Brand $brand = null): SocialProfilePermission
    {
        $this->assertCanManage($profile, $grantedBy);
        $this->assertContext($account, $brand);

        if ($account === null && $brand === null) {
            throw new InvalidArgumentException('User-specific social profile permissions must be scoped to an account or brand.');
        }

        if ($account !== null && ! $this->userBelongsToAccount($user, $account->id)) {
            throw new InvalidArgumentException('A social profile cannot be shared with a user outside the target account.');
        }

        if ($brand !== null && ! $this->userBelongsToBrand($user, $brand->id, $brand->account_id)) {
            throw new InvalidArgumentException('A social profile cannot be shared with a user outside the target brand.');
        }

        return $this->grant($profile, $user, $account, $brand, $grantedBy, $abilities);
    }

    public function canView(User $user, SocialProfile $profile, ?Account $account = null, ?Brand $brand = null): bool
    {
        return $this->hasAbility($user, $profile, 'view', $account, $brand);
    }

    public function canPrepare(User $user, SocialProfile $profile, ?Account $account = null, ?Brand $brand = null): bool
    {
        return $this->hasAbility($user, $profile, 'prepare', $account, $brand);
    }

    public function canSchedule(User $user, SocialProfile $profile, ?Account $account = null, ?Brand $brand = null): bool
    {
        return $this->hasAbility($user, $profile, 'schedule', $account, $brand);
    }

    public function canPublish(User $user, SocialProfile $profile, ?Account $account = null, ?Brand $brand = null): bool
    {
        return $this->hasAbility($user, $profile, 'publish', $account, $brand);
    }

    public function canManage(User $user, SocialProfile $profile, ?Account $account = null, ?Brand $brand = null): bool
    {
        return $this->hasAbility($user, $profile, 'manage', $account, $brand);
    }

    /**
     * @return Collection<int, SocialProfile>
     */
    public function profilesFor(User $user, Account $account, ?Brand $brand = null): Collection
    {
        if (! $this->userCanAccessContext($user, $account, $brand)) {
            return collect();
        }

        return SocialProfile::query()
            ->connected()
            ->where(function (Builder $query) use ($user, $account, $brand): void {
                $query->where('owner_user_id', $user->id)
                    ->orWhereHas('permissions', function (Builder $permissions) use ($user, $account, $brand): void {
                        $this->permissionScope($permissions, $user, $account, $brand);
                    });
            })
            ->with(['owner', 'account', 'brand', 'permissions'])
            ->latest('created_at')
            ->get()
            ->filter(fn (SocialProfile $profile) => $this->canView($user, $profile, $account, $brand))
            ->values();
    }

    /**
     * @param  array{view?: bool, prepare?: bool, schedule?: bool, publish?: bool, manage?: bool}  $abilities
     */
    private function grant(SocialProfile $profile, ?User $user, ?Account $account, ?Brand $brand, User $grantedBy, array $abilities): SocialProfilePermission
    {
        $this->assertContext($account, $brand);
        $this->assertProfileCanBeSharedWithContext($profile, $account, $brand);
        $this->assertGranterCanAccessContext($grantedBy, $account, $brand);

        return DB::transaction(function () use ($profile, $user, $account, $brand, $grantedBy, $abilities): SocialProfilePermission {
            $permission = $profile->permissions()->updateOrCreate(
                [
                    'user_id' => $user?->id,
                    'account_id' => $account?->id,
                    'brand_id' => $brand?->id,
                ],
                [
                    'can_view' => $abilities['view'] ?? true,
                    'can_prepare' => $abilities['prepare'] ?? false,
                    'can_schedule' => $abilities['schedule'] ?? false,
                    'can_publish' => $abilities['publish'] ?? false,
                    'can_manage' => $abilities['manage'] ?? false,
                ],
            );

            $this->activity->log(
                event: 'social_profile.shared',
                description: "Social profile {$profile->display_name} sharing was updated.",
                account: $account,
                brand: $brand,
                user: $grantedBy,
                subject: $profile,
                properties: [
                    'social_profile_id' => $profile->id,
                    'shared_user_id' => $user?->id,
                    'abilities' => $this->abilityPayload($permission),
                ],
            );

            if ($account !== null) {
                $this->events->record('SocialProfileShared', $account, $brand, $profile->account_id === null ? null : $profile, $grantedBy, [
                    'social_profile_id' => $profile->id,
                    'shared_user_id' => $user?->id,
                    'abilities' => $this->abilityPayload($permission),
                ]);
            }

            return $permission;
        });
    }

    private function hasAbility(User $user, SocialProfile $profile, string $ability, ?Account $account, ?Brand $brand): bool
    {
        if ($profile->status !== 'connected') {
            return false;
        }

        if (! $this->contextMatchesProfile($profile, $account, $brand)) {
            return false;
        }

        if (! $this->userCanAccessContext($user, $account, $brand)) {
            return false;
        }

        if ($profile->owner_user_id === $user->id) {
            return true;
        }

        return $profile->permissions()
            ->where(function (Builder $query) use ($user, $account, $brand): void {
                $this->permissionScope($query, $user, $account, $brand);
            })
            ->get()
            ->contains(fn (SocialProfilePermission $permission) => $this->permissionAllows($permission, $ability));
    }

    private function permissionAllows(SocialProfilePermission $permission, string $ability): bool
    {
        return match ($ability) {
            'view' => $permission->can_view || $permission->can_prepare || $permission->can_schedule || $permission->can_publish || $permission->can_manage,
            'prepare' => $permission->can_prepare || $permission->can_schedule || $permission->can_publish,
            'schedule' => $permission->can_schedule,
            'publish' => $permission->can_publish,
            'manage' => $permission->can_manage,
            default => false,
        };
    }

    private function permissionScope(Builder $query, User $user, Account $account, ?Brand $brand): void
    {
        $query->where(function (Builder $scope) use ($user, $account, $brand): void {
            $scope->where(function (Builder $userScope) use ($user, $account, $brand): void {
                $userScope->where('user_id', $user->id)
                    ->where(function (Builder $context) use ($account, $brand): void {
                        $context->where('account_id', $account->id);

                        if ($brand !== null) {
                            $context->orWhere('brand_id', $brand->id);
                        }
                    });
            });

            $scope->orWhere(function (Builder $accountScope) use ($account): void {
                $accountScope->whereNull('user_id')
                    ->where('account_id', $account->id)
                    ->whereNull('brand_id');
            });

            if ($brand !== null) {
                $scope->orWhere(function (Builder $brandScope) use ($brand): void {
                    $brandScope->whereNull('user_id')
                        ->where('account_id', $brand->account_id)
                        ->where('brand_id', $brand->id);
                });
            }
        });
    }

    private function contextMatchesProfile(SocialProfile $profile, ?Account $account, ?Brand $brand): bool
    {
        if ($brand !== null && $account !== null && $brand->account_id !== $account->id) {
            return false;
        }

        if ($profile->account_id !== null && $account !== null && $profile->account_id !== $account->id) {
            return false;
        }

        if ($profile->brand_id !== null && $brand === null) {
            return false;
        }

        if ($profile->brand_id !== null && $brand !== null && $profile->brand_id !== $brand->id) {
            return false;
        }

        return true;
    }

    private function assertProfileCanBeSharedWithContext(SocialProfile $profile, ?Account $account, ?Brand $brand): void
    {
        if (! $this->contextMatchesProfile($profile, $account, $brand)) {
            throw new InvalidArgumentException('A social profile cannot be shared outside its tenant scope.');
        }
    }

    private function assertCanManage(SocialProfile $profile, User $user): void
    {
        if (! $this->canManage($user, $profile, $profile->account, $profile->brand)) {
            throw new InvalidArgumentException('Only a social profile manager can update sharing.');
        }
    }

    private function assertOwnerCanUseConnection(IntegrationConnection $connection, User $owner): void
    {
        if ($connection->owner_user_id !== $owner->id) {
            throw new InvalidArgumentException('Social profile owner must own the integration connection.');
        }
    }

    private function assertContext(?Account $account, ?Brand $brand): void
    {
        if ($account !== null && $brand !== null && $brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Social profile context brand must belong to the account.');
        }
    }

    private function assertGranterCanAccessContext(User $grantedBy, ?Account $account, ?Brand $brand): void
    {
        if ($account !== null && ! $this->userBelongsToAccount($grantedBy, $account->id)) {
            throw new InvalidArgumentException('The sharing user must belong to the target account.');
        }

        if ($brand !== null && ! $this->userBelongsToAccount($grantedBy, $brand->account_id)) {
            throw new InvalidArgumentException('The sharing user must belong to the target brand account.');
        }
    }

    private function userCanAccessContext(User $user, ?Account $account, ?Brand $brand): bool
    {
        if ($account === null) {
            return true;
        }

        if (! $this->userBelongsToAccount($user, $account->id)) {
            return false;
        }

        if ($brand !== null && ! $this->userBelongsToBrand($user, $brand->id, $brand->account_id)) {
            return false;
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

    /**
     * @return array<string, bool>
     */
    private function abilityPayload(SocialProfilePermission $permission): array
    {
        return [
            'view' => $permission->can_view,
            'prepare' => $permission->can_prepare,
            'schedule' => $permission->can_schedule,
            'publish' => $permission->can_publish,
            'manage' => $permission->can_manage,
        ];
    }
}
