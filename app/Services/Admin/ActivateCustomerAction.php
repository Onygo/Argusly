<?php

namespace App\Services\Admin;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ActivateCustomerAction
{
    public function execute(Organization $organization, ?User $adminUser = null): Organization
    {
        return DB::transaction(function () use ($organization, $adminUser): Organization {
            $organization->loadMissing('users');

            $primaryUser = $this->resolvePrimaryUser($organization);

            $organization->fill([
                'status' => 'active',
                'approved_at' => $organization->approved_at ?? now(),
                'approved_by' => $organization->approved_by ?? $adminUser?->id,
            ]);

            if ($primaryUser) {
                $organization->primary_user_id = $primaryUser->id;
            }

            $organization->save();

            if ($primaryUser) {
                $primaryUser->fill([
                    'active' => true,
                    'approved_at' => $primaryUser->approved_at ?? now(),
                ]);
                $primaryUser->save();
            }

            return $organization->fresh(['users', 'primaryUser']);
        });
    }

    private function resolvePrimaryUser(Organization $organization): ?User
    {
        if ($organization->primary_user_id) {
            $primary = $organization->users->firstWhere('id', $organization->primary_user_id);
            if ($primary) {
                return $primary;
            }
        }

        $owner = $organization->users
            ->where('is_admin', false)
            ->sortBy('created_at')
            ->firstWhere('role', 'owner');

        if ($owner) {
            return $owner;
        }

        return $organization->users
            ->where('is_admin', false)
            ->sortBy('created_at')
            ->first();
    }
}
