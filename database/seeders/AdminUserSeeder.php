<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@local.test');
        $password = env('ADMIN_PASSWORD', app()->environment('local') ? 'password' : null);

        if (! $password) {
            $password = 'password';
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Platform Admin',
                'password' => Hash::make($password),
                'is_admin' => true,
                'approved_at' => now(),
            ]
        );
    }
}
