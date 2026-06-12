<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ProductionSeeder extends Seeder
{
    public const ADMIN_EMAIL = 'hello@argusly.com';

    public const ADMIN_PASSWORD = 'Argusly@2026!';

    public function run(): void
    {
        $this->call(PlansSeeder::class);

        User::query()->updateOrCreate(
            ['email' => self::ADMIN_EMAIL],
            [
                'name' => 'Argusly Admin',
                'password' => Hash::make(self::ADMIN_PASSWORD),
                'organization_id' => null,
                'role' => 'owner',
                'active' => true,
                'approved_at' => now(),
                'email_verified_at' => now(),
                'is_admin' => true,
                'admin_role' => 'superadmin',
            ]
        );
    }
}
