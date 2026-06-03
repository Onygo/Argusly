<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleDemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoleDemoUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_role_demo_users_can_open_the_dashboard(): void
    {
        $this->seed(RoleDemoUsersSeeder::class);

        $emails = [
            'test@example.com',
            'platform.admin@example.com',
            'owner@example.com',
            'admin@example.com',
            'manager@example.com',
            'editor@example.com',
            'publisher@example.com',
            'viewer@example.com',
            'billing@example.com',
            'external@example.com',
        ];

        foreach ($emails as $email) {
            $user = User::query()->where('email', $email)->firstOrFail();

            $this->assertTrue(Hash::check('password', $user->password), "{$email} should use the shared demo password.");

            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk();
        }
    }
}
