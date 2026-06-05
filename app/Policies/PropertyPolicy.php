<?php

namespace App\Policies;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Property;
use App\Models\User;
use App\Services\PermissionService;

class PropertyPolicy
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly CurrentAccountContract $currentAccount,
        private readonly CurrentBrandContract $currentBrand,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->userCan($user, 'manage_account', $this->currentContext($user));
    }

    public function view(User $user, Property $property): bool
    {
        return $this->tenant($user, $property);
    }

    public function create(User $user): bool
    {
        return $this->permissions->userCan($user, 'manage_account', $this->currentContext($user));
    }

    public function update(User $user, Property $property): bool
    {
        return $this->tenant($user, $property);
    }

    private function tenant(User $user, Property $property): bool
    {
        return $this->permissions->userCan($user, 'manage_account', [
            'account_id' => $property->account_id,
            'brand_id' => $property->brand_id,
        ]);
    }

    /**
     * @return array{account_id?: int|null, brand_id?: int|null}
     */
    private function currentContext(User $user): array
    {
        return [
            'account_id' => $this->currentAccount->id($user),
            'brand_id' => $this->currentBrand->id($user),
        ];
    }
}
