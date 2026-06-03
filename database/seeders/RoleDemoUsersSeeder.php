<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Role;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RoleDemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(SubscriptionCatalogSeeder::class);

        $account = Account::query()->updateOrCreate(
            ['slug' => 'alpha-agency'],
            ['name' => 'Alpha Agency', 'status' => 'active'],
        );

        $brand = Brand::query()->updateOrCreate(
            ['account_id' => $account->id, 'slug' => 'alpha-main'],
            ['name' => 'Alpha Main', 'domain' => 'alpha.example', 'status' => 'active'],
        );

        app(SubscriptionService::class)->activatePlan($account, 'growth_monthly');

        foreach (config('permissions.roles', []) as $roleName => $config) {
            $this->seedRoleUser(
                email: str_replace('_', '.', $roleName).'@example.com',
                name: $config['display_name'] ?? Str::headline($roleName),
                roleName: $roleName,
                account: $account,
                brand: $brand,
            );
        }

        $this->seedRoleUser(
            email: 'test@example.com',
            name: 'Test Owner',
            roleName: 'owner',
            account: $account,
            brand: $brand,
        );
    }

    private function seedRoleUser(string $email, string $name, string $roleName, Account $account, Brand $brand): void
    {
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password'),
            ],
        );

        $user->accounts()->syncWithoutDetaching([
            $account->id => ['status' => 'active', 'joined_at' => now()],
        ]);

        $user->brands()->syncWithoutDetaching([
            $brand->id => ['account_id' => $account->id, 'status' => 'active', 'joined_at' => now()],
        ]);

        $this->assignRole($user, $role, null, null);
        $this->assignRole($user, $role, $account->id, null);
        $this->assignRole($user, $role, $account->id, $brand->id);
    }

    private function assignRole(User $user, Role $role, ?int $accountId, ?int $brandId): void
    {
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
