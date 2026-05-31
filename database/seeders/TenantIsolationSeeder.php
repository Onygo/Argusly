<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Role;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TenantIsolationSeeder extends Seeder
{
    /**
     * Seed multiple tenants, brands, and users for isolation checks.
     */
    public function run(): void
    {
        $alpha = Account::query()->updateOrCreate(
            ['slug' => 'alpha-agency'],
            ['name' => 'Alpha Agency', 'status' => 'active'],
        );

        $beta = Account::query()->updateOrCreate(
            ['slug' => 'beta-group'],
            ['name' => 'Beta Group', 'status' => 'active'],
        );

        $alphaMain = Brand::query()->updateOrCreate(
            ['account_id' => $alpha->id, 'slug' => 'alpha-main'],
            ['name' => 'Alpha Main', 'domain' => 'alpha.example', 'status' => 'active'],
        );

        $alphaLabs = Brand::query()->updateOrCreate(
            ['account_id' => $alpha->id, 'slug' => 'alpha-labs'],
            ['name' => 'Alpha Labs', 'domain' => 'labs.alpha.example', 'status' => 'active'],
        );

        $betaMain = Brand::query()->updateOrCreate(
            ['account_id' => $beta->id, 'slug' => 'beta-main'],
            ['name' => 'Beta Main', 'domain' => 'beta.example', 'status' => 'active'],
        );

        $alphaOwner = $this->user('alpha.owner@example.com', 'Alpha Owner');
        $alphaEditor = $this->user('alpha.editor@example.com', 'Alpha Editor');
        $betaOwner = $this->user('beta.owner@example.com', 'Beta Owner');
        $sharedConsultant = $this->user('shared.consultant@example.com', 'Shared Consultant');

        $this->attachAccount($alphaOwner, $alpha);
        $this->attachAccount($alphaEditor, $alpha);
        $this->attachAccount($betaOwner, $beta);
        $this->attachAccount($sharedConsultant, $alpha);
        $this->attachAccount($sharedConsultant, $beta);

        $this->attachBrand($alphaOwner, $alphaMain);
        $this->attachBrand($alphaOwner, $alphaLabs);
        $this->attachBrand($alphaEditor, $alphaMain);
        $this->attachBrand($betaOwner, $betaMain);
        $this->attachBrand($sharedConsultant, $alphaLabs);
        $this->attachBrand($sharedConsultant, $betaMain);

        $this->attachRole($alphaOwner, 'owner', $alpha->id);
        $this->attachRole($alphaEditor, 'editor', $alpha->id, $alphaMain->id);
        $this->attachRole($betaOwner, 'owner', $beta->id);
        $this->attachRole($sharedConsultant, 'viewer', $alpha->id, $alphaLabs->id);
        $this->attachRole($sharedConsultant, 'viewer', $beta->id, $betaMain->id);

        app(SubscriptionService::class)->activatePlan($alpha, 'growth_monthly');
        app(SubscriptionService::class)->activatePlan($beta, 'starter_monthly');
    }

    private function user(string $email, string $name): User
    {
        return User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make('password')],
        );
    }

    private function attachAccount(User $user, Account $account): void
    {
        $user->accounts()->syncWithoutDetaching([
            $account->id => ['status' => 'active', 'joined_at' => now()],
        ]);
    }

    private function attachBrand(User $user, Brand $brand): void
    {
        $user->brands()->syncWithoutDetaching([
            $brand->id => ['account_id' => $brand->account_id, 'status' => 'active', 'joined_at' => now()],
        ]);
    }

    private function attachRole(User $user, string $roleName, ?int $accountId = null, ?int $brandId = null): void
    {
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->roleAssignments()->updateOrCreate(
            [
                'role_id' => $role->id,
                'account_id' => $accountId,
                'brand_id' => $brandId,
            ],
            [
                'starts_at' => null,
                'expires_at' => null,
            ],
        );
    }
}
